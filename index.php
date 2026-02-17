<?php
declare(strict_types=1);
error_reporting(0);

/* ================= CONFIG ================= */
$BOT_TOKEN    = getenv("BOT_TOKEN");
$DATABASE_URL = getenv("DATABASE_URL");
$BASE_URL     = rtrim(getenv("BASE_URL") ?: "", "/");
$BOT_USERNAME = getenv("BOT_USERNAME");
$ADMIN_IDS    = array_values(array_filter(array_map('trim', explode(",", getenv("ADMIN_IDS") ?: ""))));

if (!$BOT_TOKEN || !$DATABASE_URL || !$BASE_URL || !$BOT_USERNAME || count($ADMIN_IDS) < 1) {
  http_response_code(500);
  echo "Missing ENV vars";
  exit;
}

/* ================= DB ================= */
$db = parse_url($DATABASE_URL);
$dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'] ?? '', '/');
$pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/* ================= TELEGRAM HELPERS ================= */
function tgRequest(string $method, array $data = []): array {
  global $BOT_TOKEN;
  $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/{$method}");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
  curl_setopt($ch, CURLOPT_TIMEOUT, 8);
  $resp = curl_exec($ch);
  curl_close($ch);
  $json = json_decode($resp ?: "{}", true);
  return is_array($json) ? $json : [];
}
function tgSendMessage(int|string $chat_id, string $text, array $reply_markup = null): void {
  $payload = ["chat_id" => $chat_id, "text" => $text, "disable_web_page_preview" => true];
  if ($reply_markup !== null) $payload["reply_markup"] = json_encode($reply_markup);
  tgRequest("sendMessage", $payload);
}
function tgAnswerCallback(string $cb_id, string $text): void {
  tgRequest("answerCallbackQuery", ["callback_query_id" => $cb_id, "text" => $text, "show_alert" => false]);
}
function isAdmin(int $user_id): bool {
  global $ADMIN_IDS;
  return in_array((string)$user_id, $ADMIN_IDS, true);
}

/* ================= STATE HELPERS ================= */
function setState(int $user_id, string $state, string $data = ""): void {
  global $pdo;
  $q = $pdo->prepare("
    INSERT INTO user_states(user_id, state, data, updated_at)
    VALUES(?, ?, ?, NOW())
    ON CONFLICT (user_id)
    DO UPDATE SET state=EXCLUDED.state, data=EXCLUDED.data, updated_at=NOW()
  ");
  $q->execute([$user_id, $state, $data]);
}
function getState(int $user_id): array {
  global $pdo;
  $q = $pdo->prepare("SELECT state, data FROM user_states WHERE user_id=?");
  $q->execute([$user_id]);
  $r = $q->fetch(PDO::FETCH_ASSOC);
  return $r ? $r : ["state" => null, "data" => null];
}
function clearState(int $user_id): void {
  global $pdo;
  $q = $pdo->prepare("DELETE FROM user_states WHERE user_id=?");
  $q->execute([$user_id]);
}

/* ================= USER LOCK CHECK ================= */
function isUnlocked(int $user_id): bool {
  global $pdo;
  $q = $pdo->prepare("SELECT joined, web_verified FROM users WHERE user_id=?");
  $q->execute([$user_id]);
  $r = $q->fetch(PDO::FETCH_ASSOC);
  if (!$r) return false;
  return ($r["joined"] === true || $r["joined"] === 't' || $r["joined"] === 1 || $r["joined"] === '1')
      && ($r["web_verified"] === true || $r["web_verified"] === 't' || $r["web_verified"] === 1 || $r["web_verified"] === '1');
}

/* ================= MENUS ================= */
function showUserMenu(int|string $chat_id, bool $admin): void {
  $keyboard = [
    [["Stats"], ["Referral Link"]],
    [["Withdraw"]],
  ];
  if ($admin) $keyboard[] = [["Admin Panel"]];
  tgSendMessage($chat_id, "✅ Main Menu", [
    "keyboard" => $keyboard,
    "resize_keyboard" => true
  ]);
}
function showAdminMenu(int|string $chat_id): void {
  tgSendMessage($chat_id, "👑 Admin Panel", [
    "keyboard" => [
      [["Add Coupon"], ["Stock"]],
      [["Change Points"], ["Force Join"]],
      [["Redeems Log"]],
      [["Back"]],
    ],
    "resize_keyboard" => true
  ]);
}
function showForceJoinMenu(int|string $chat_id): void {
  tgSendMessage($chat_id, "📢 Force Join Management", [
    "keyboard" => [
      [["Add Group"], ["Remove Group"]],
      [["View Groups"]],
      [["Back"]],
    ],
    "resize_keyboard" => true
  ]);
}
function showAddCouponTypeMenu(int|string $chat_id): void {
  tgSendMessage($chat_id, "➕ Add Coupons: Choose type", [
    "keyboard" => [
      [["Add ₹5"], ["Add ₹10"]],
      [["Back"]],
    ],
    "resize_keyboard" => true
  ]);
}

/* ================= WEB VERIFY (GET) ================= */
if (isset($_GET["verify"])) {
  $uid = intval($_GET["verify"]);
  $ip  = $_SERVER["REMOTE_ADDR"] ?? "";

  if ($uid <= 0 || $ip === "") {
    http_response_code(400);
    echo "Invalid verify";
    exit;
  }

  // Premium verify page UI: GET without action => show button
  if (!isset($_GET["do"])) {
    echo "<!doctype html><html><head><meta charset='utf-8'>
      <meta name='viewport' content='width=device-width, initial-scale=1'>
      <title>Verify</title>
      <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1220;color:#fff;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .card{width:min(520px,92vw);background:linear-gradient(180deg,#121a2d,#0f172a);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:22px;box-shadow:0 12px 40px rgba(0,0,0,.35)}
        h1{font-size:22px;margin:0 0 10px}
        p{opacity:.85;line-height:1.5;margin:0 0 16px}
        .btn{width:100%;padding:14px 16px;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;background:#2f7cff;color:#fff}
        .btn:hover{filter:brightness(1.05)}
        .sub{margin-top:12px;opacity:.7;font-size:13px}
      </style></head><body>
      <div class='card'>
        <h1>✅ Web Verification</h1>
        <p>Click the button below to verify your account. After verifying, return to the bot and press <b>Completed Verification</b>.</p>
        <form method='GET'>
          <input type='hidden' name='verify' value='{$uid}'>
          <input type='hidden' name='do' value='1'>
          <button class='btn' type='submit'>Verify Now</button>
        </form>
        <div class='sub'>Security: 1 IP = 1 account</div>
      </div>
    </body></html>";
    exit;
  }

  // ACTION: complete verification with IP lock, and referral credit ONCE
  try {
    $pdo->beginTransaction();

    // If IP already used by ANYONE else -> block
    $q = $pdo->prepare("SELECT user_id FROM users WHERE ip_address = ? AND user_id <> ?");
    $q->execute([$ip, $uid]);
    if ($q->fetchColumn()) {
      $pdo->rollBack();
      echo "<h2 style='font-family:sans-serif;text-align:center;margin-top:100px;'>IP already used ❌</h2>";
      exit;
    }

    // Only verify if not already verified (prevents double referral credit)
    $q = $pdo->prepare("SELECT web_verified, referred_by FROM users WHERE user_id=? FOR UPDATE");
    $q->execute([$uid]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $pdo->rollBack();
      echo "<h2 style='font-family:sans-serif;text-align:center;margin-top:100px;'>User not found ❌</h2>";
      exit;
    }

    $already = ($row["web_verified"] === true || $row["web_verified"] === 't' || $row["web_verified"] === 1 || $row["web_verified"] === '1');
    if (!$already) {
      $q = $pdo->prepare("UPDATE users SET web_verified=true, ip_address=? WHERE user_id=?");
      $q->execute([$ip, $uid]);

      $ref_by = $row["referred_by"];
      if ($ref_by && (string)$ref_by !== (string)$uid) {
        // credit referrer 1 point and +1 referrals
        $q = $pdo->prepare("UPDATE users SET points = points + 1, referrals = referrals + 1 WHERE user_id=?");
        $q->execute([$ref_by]);
      }
    }

    $pdo->commit();

    echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>
      <title>Verified</title>
      <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1220;color:#fff;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .card{width:min(520px,92vw);background:linear-gradient(180deg,#121a2d,#0f172a);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:22px;text-align:center}
        .btn{display:inline-block;margin-top:14px;padding:12px 18px;border-radius:12px;background:#2f7cff;color:#fff;text-decoration:none;font-weight:800}
      </style></head><body>
      <div class='card'>
        <h2>🎉 Verification Successful</h2>
        <p>Return to the bot and click <b>Completed Verification</b>.</p>
        <a class='btn' href='https://t.me/{$BOT_USERNAME}'>Return to Bot</a>
      </div>
    </body></html>";
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Verify error";
    exit;
  }
}

/* ================= TELEGRAM WEBHOOK (POST) ================= */
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "OK"; exit; }

$message  = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

$chat_id  = $message["chat"]["id"] ?? ($callback["message"]["chat"]["id"] ?? null);
$user_id  = $message["from"]["id"] ?? ($callback["from"]["id"] ?? null);
$text     = trim($message["text"] ?? "");
$username = $message["from"]["username"] ?? "";

if (!$chat_id || !$user_id) { echo "OK"; exit; }

$admin = isAdmin((int)$user_id);

/* ===== Upsert user on any interaction (and capture /start ref once) ===== */
$refParam = null;
if (strpos($text, "/start") === 0) {
  $parts = explode(" ", $text);
  if (isset($parts[1]) && ctype_digit($parts[1]) && $parts[1] !== (string)$user_id) {
    $refParam = $parts[1];
  }
}

$q = $pdo->prepare("
  INSERT INTO users (user_id, username, referred_by)
  VALUES (?, ?, ?)
  ON CONFLICT (user_id) DO UPDATE
  SET username = EXCLUDED.username,
      referred_by = COALESCE(users.referred_by, EXCLUDED.referred_by)
");
$q->execute([$user_id, $username, $refParam]);

/* ================= FORCE JOIN FLOW ================= */
function sendForceJoin(int|string $chat_id, int $user_id): void {
  global $pdo;
  $channels = $pdo->query("SELECT username FROM force_channels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
  if (!$channels || count($channels) === 0) {
    // No force channels configured -> allow next step (web verify)
    tgSendMessage($chat_id, "No force-join channels set by admin. Continue verification.", [
      "inline_keyboard" => [
        [["text" => "Verify Now", "url" => (getenv("BASE_URL") ?: "") . "?verify={$user_id}"]],
        [["text" => "Completed Verification", "callback_data" => "verify_done"]],
      ]
    ]);
    return;
  }

  $ik = [];
  foreach ($channels as $c) {
    $u = $c["username"];
    $ik[] = [["text" => "Join @{$u}", "url" => "https://t.me/{$u}"]];
  }
  $ik[] = [["text" => "✅ Joined All Channels", "callback_data" => "check_join"]];

  tgSendMessage($chat_id, "📢 Join all channels first, then click ✅ Joined All Channels:", [
    "inline_keyboard" => $ik
  ]);
}

if ($text === "/start") {
  sendForceJoin($chat_id, (int)$user_id);
  echo "OK"; exit;
}

/* ================= CALLBACKS ================= */
if ($callback) {
  $cb_id = $callback["id"];
  $data  = $callback["data"] ?? "";

  if ($data === "check_join") {
    // verify membership in all channels
    $channels = $pdo->query("SELECT username FROM force_channels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($channels as $c) {
      $u = $c["username"];
      $res = tgRequest("getChatMember", ["chat_id" => "@{$u}", "user_id" => $user_id]);
      $status = $res["result"]["status"] ?? "left";
      if (!in_array($status, ["member", "administrator", "creator"], true)) {
        tgAnswerCallback($cb_id, "Join all channels first ❌");
        echo "OK"; exit;
      }
    }

    $q = $pdo->prepare("UPDATE users SET joined=true WHERE user_id=?");
    $q->execute([$user_id]);

    tgSendMessage($chat_id, "✅ Channels Verified! Now do web verification:", [
      "inline_keyboard" => [
        [["text" => "🌐 Verify Now", "url" => "{$GLOBALS['BASE_URL']}?verify={$user_id}"]],
        [["text" => "✅ Completed Verification", "callback_data" => "verify_done"]],
      ]
    ]);
    echo "OK"; exit;
  }

  if ($data === "verify_done") {
    if (isUnlocked((int)$user_id)) {
      showUserMenu($chat_id, $admin);
    } else {
      tgAnswerCallback($cb_id, "Complete web verification first ❌");
    }
    echo "OK"; exit;
  }

  // Withdraw callbacks (wd_5 / wd_10) only if unlocked
  if (strpos($data, "wd_") === 0) {
    if (!isUnlocked((int)$user_id)) {
      tgAnswerCallback($cb_id, "Complete verification first ❌");
      echo "OK"; exit;
    }

    $type = substr($data, 3); // "5" or "10"
    if (!in_array($type, ["5","10"], true)) {
      tgAnswerCallback($cb_id, "Invalid type");
      echo "OK"; exit;
    }

    // required points
    $q = $pdo->prepare("SELECT required_points FROM withdraw_settings WHERE type=?");
    $q->execute([$type]);
    $required = (int)($q->fetchColumn() ?: 0);

    $q = $pdo->prepare("SELECT points FROM users WHERE user_id=?");
    $q->execute([$user_id]);
    $points = (int)($q->fetchColumn() ?: 0);

    if ($points < $required) {
      tgAnswerCallback($cb_id, "Not enough points ❌");
      echo "OK"; exit;
    }

    // get coupon
    $q = $pdo->prepare("SELECT id, code FROM coupons WHERE type=? AND used=false ORDER BY id ASC LIMIT 1");
    $q->execute([$type]);
    $coupon = $q->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
      tgAnswerCallback($cb_id, "Out of stock ❌");
      echo "OK"; exit;
    }

    try {
      $pdo->beginTransaction();

      // lock coupon row
      $q = $pdo->prepare("SELECT used FROM coupons WHERE id=? FOR UPDATE");
      $q->execute([$coupon["id"]]);
      $used = $q->fetchColumn();
      if ($used === true || $used === 't' || $used === 1 || $used === '1') {
        $pdo->rollBack();
        tgAnswerCallback($cb_id, "Try again (stock changed)");
        echo "OK"; exit;
      }

      // deduct points safely
      $q = $pdo->prepare("SELECT points FROM users WHERE user_id=? FOR UPDATE");
      $q->execute([$user_id]);
      $curPoints = (int)($q->fetchColumn() ?: 0);
      if ($curPoints < $required) {
        $pdo->rollBack();
        tgAnswerCallback($cb_id, "Not enough points ❌");
        echo "OK"; exit;
      }

      $q = $pdo->prepare("UPDATE users SET points = points - ? WHERE user_id=?");
      $q->execute([$required, $user_id]);

      $q = $pdo->prepare("UPDATE coupons SET used=true, used_by=?, used_at=NOW() WHERE id=?");
      $q->execute([$user_id, $coupon["id"]]);

      $q = $pdo->prepare("INSERT INTO redeems(user_id,type,code) VALUES(?,?,?)");
      $q->execute([$user_id, $type, $coupon["code"]]);

      $pdo->commit();

      tgSendMessage($chat_id, "🎁 Your ₹{$type} Gift Card Coupon:\n\n{$coupon['code']}");
      // notify admins
      foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
        tgSendMessage((int)$aid, "📢 Redeem Alert:\nUser: {$user_id}\nType: ₹{$type}\nCode: {$coupon['code']}");
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      tgAnswerCallback($cb_id, "Error, try again");
    }

    echo "OK"; exit;
  }
}

/* ================= Gate: If not unlocked, block normal buttons ================= */
if (!isUnlocked((int)$user_id)) {
  // allow admins to manage without unlocking? (NO — you wanted force join + verify first for all users)
  // so just guide them to /start flow:
  tgSendMessage($chat_id, "🔒 Complete verification first.\nSend /start and follow steps.");
  echo "OK"; exit;
}

/* ================= Handle stateful admin steps ================= */
$st = getState((int)$user_id);
$state = $st["state"];

/* ================= USER PANEL ================= */
if ($text === "Stats") {
  $q = $pdo->prepare("SELECT points, referrals FROM users WHERE user_id=?");
  $q->execute([$user_id]);
  $u = $q->fetch(PDO::FETCH_ASSOC);
  $p = (int)($u["points"] ?? 0);
  $r = (int)($u["referrals"] ?? 0);
  tgSendMessage($chat_id, "📊 Stats\n\nPoints: {$p}\nReferrals: {$r}");
  echo "OK"; exit;
}

if ($text === "Referral Link") {
  tgSendMessage($chat_id, "🔗 Your Referral Link:\nhttps://t.me/{$BOT_USERNAME}?start={$user_id}");
  echo "OK"; exit;
}

if ($text === "Withdraw") {
  tgSendMessage($chat_id, "💳 Choose Withdraw Option:", [
    "inline_keyboard" => [
      [["text" => "₹5 Gift Card",  "callback_data" => "wd_5"]],
      [["text" => "₹10 Gift Card", "callback_data" => "wd_10"]],
    ]
  ]);
  echo "OK"; exit;
}

/* ================= ADMIN PANEL (ENTERPRISE) ================= */
if ($text === "Admin Panel") {
  if (!$admin) { tgSendMessage($chat_id, "❌ Not authorized"); echo "OK"; exit; }
  clearState((int)$user_id);
  showAdminMenu($chat_id);
  echo "OK"; exit;
}

if ($text === "Back") {
  clearState((int)$user_id);
  showUserMenu($chat_id, $admin);
  echo "OK"; exit;
}

if ($admin) {

  /* ---- Admin: Open Add Coupon menu ---- */
  if ($text === "Add Coupon") {
    clearState((int)$user_id);
    showAddCouponTypeMenu($chat_id);
    echo "OK"; exit;
  }

  if ($text === "Add ₹5") {
    setState((int)$user_id, "ADD_COUPON", "5");
    tgSendMessage($chat_id, "Send ₹5 coupon codes line-by-line (paste many lines).");
    echo "OK"; exit;
  }

  if ($text === "Add ₹10") {
    setState((int)$user_id, "ADD_COUPON", "10");
    tgSendMessage($chat_id, "Send ₹10 coupon codes line-by-line (paste many lines).");
    echo "OK"; exit;
  }

  if ($state === "ADD_COUPON") {
    $type = $st["data"] ?: "5";
    $lines = preg_split("/\r\n|\n|\r/", $text);
    $added = 0;

    $pdo->beginTransaction();
    try {
      $ins = $pdo->prepare("INSERT INTO coupons(type, code) VALUES(?, ?) ON CONFLICT (code) DO NOTHING");
      foreach ($lines as $line) {
        $code = trim($line);
        if ($code === "") continue;
        $ins->execute([$type, $code]);
        // rowCount returns 1 if inserted, 0 if conflict
        $added += (int)$ins->rowCount();
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      tgSendMessage($chat_id, "❌ Failed to add coupons. Try again.");
      clearState((int)$user_id);
      echo "OK"; exit;
    }

    clearState((int)$user_id);
    tgSendMessage($chat_id, "✅ Added {$added} coupon(s) to ₹{$type} stock.");
    showAdminMenu($chat_id);
    echo "OK"; exit;
  }

  /* ---- Admin: Stock ---- */
  if ($text === "Stock") {
    $s5  = (int)$pdo->query("SELECT COUNT(*) FROM coupons WHERE type='5' AND used=false")->fetchColumn();
    $s10 = (int)$pdo->query("SELECT COUNT(*) FROM coupons WHERE type='10' AND used=false")->fetchColumn();
    tgSendMessage($chat_id, "📦 Stock\n\n₹5: {$s5}\n₹10: {$s10}");
    echo "OK"; exit;
  }

  /* ---- Admin: Change Points ---- */
  if ($text === "Change Points") {
    setState((int)$user_id, "CHANGE_POINTS", "");
    tgSendMessage($chat_id, "Send like this (one per line):\n\n5=10\n10=20\n\nMeaning: required points for ₹5 and ₹10.");
    echo "OK"; exit;
  }

  if ($state === "CHANGE_POINTS") {
    $lines = preg_split("/\r\n|\n|\r/", $text);
    $ok = 0;

    $pdo->beginTransaction();
    try {
      $up = $pdo->prepare("UPDATE withdraw_settings SET required_points=? WHERE type=?");
      foreach ($lines as $l) {
        $l = trim($l);
        if ($l === "") continue;
        if (!str_contains($l, "=")) continue;
        [$t,$v] = array_map('trim', explode("=", $l, 2));
        if (!in_array($t, ["5","10"], true)) continue;
        if (!ctype_digit($v)) continue;
        $up->execute([(int)$v, $t]);
        $ok++;
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      tgSendMessage($chat_id, "❌ Failed. Send again in format: 5=10");
      echo "OK"; exit;
    }

    clearState((int)$user_id);
    tgSendMessage($chat_id, "✅ Updated withdraw points ({$ok} line(s) applied).");
    showAdminMenu($chat_id);
    echo "OK"; exit;
  }

  /* ---- Admin: Force Join menu ---- */
  if ($text === "Force Join") {
    clearState((int)$user_id);
    showForceJoinMenu($chat_id);
    echo "OK"; exit;
  }

  if ($text === "Add Group") {
    setState((int)$user_id, "ADD_GROUP", "");
    tgSendMessage($chat_id, "Send channel username WITHOUT @\nExample: mychannel");
    echo "OK"; exit;
  }

  if ($state === "ADD_GROUP") {
    $u = trim(ltrim($text, "@"));
    if ($u === "" || preg_match("/[^a-zA-Z0-9_]/", $u)) {
      tgSendMessage($chat_id, "❌ Invalid username. Send without @ (letters/numbers/_ only).");
      echo "OK"; exit;
    }
    $q = $pdo->prepare("INSERT INTO force_channels(username) VALUES(?) ON CONFLICT (username) DO NOTHING");
    $q->execute([$u]);
    clearState((int)$user_id);
    tgSendMessage($chat_id, "✅ Added force-join channel: @{$u}");
    showForceJoinMenu($chat_id);
    echo "OK"; exit;
  }

  if ($text === "Remove Group") {
    setState((int)$user_id, "REMOVE_GROUP", "");
    tgSendMessage($chat_id, "Send channel username to remove (WITHOUT @)");
    echo "OK"; exit;
  }

  if ($state === "REMOVE_GROUP") {
    $u = trim(ltrim($text, "@"));
    $q = $pdo->prepare("DELETE FROM force_channels WHERE username=?");
    $q->execute([$u]);
    clearState((int)$user_id);
    tgSendMessage($chat_id, "✅ Removed (if existed): @{$u}");
    showForceJoinMenu($chat_id);
    echo "OK"; exit;
  }

  if ($text === "View Groups") {
    $rows = $pdo->query("SELECT username FROM force_channels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows || count($rows) === 0) {
      tgSendMessage($chat_id, "No force-join channels set.");
      echo "OK"; exit;
    }
    $msg = "📋 Force-Join Channels:\n\n";
    foreach ($rows as $r) $msg .= "@{$r['username']}\n";
    tgSendMessage($chat_id, $msg);
    echo "OK"; exit;
  }

  /* ---- Admin: Redeems log ---- */
  if ($text === "Redeems Log") {
    $rows = $pdo->query("SELECT user_id, type, code, created_at FROM redeems ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows || count($rows) === 0) {
      tgSendMessage($chat_id, "No redeems yet.");
      echo "OK"; exit;
    }
    $msg = "🧾 Last 10 Redeems:\n\n";
    foreach ($rows as $r) {
      $msg .= "User: {$r['user_id']} | ₹{$r['type']} | {$r['code']}\n";
    }
    tgSendMessage($chat_id, $msg);
    echo "OK"; exit;
  }
}

/* ================= DEFAULT FALLBACK ================= */
showUserMenu($chat_id, $admin);
echo "OK";
