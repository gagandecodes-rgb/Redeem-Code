<?php
// ============================================================
// ✅ Refer & Earn Bot (Single index.php) — Render + Supabase
// ✅ Force Join (Unlimited add/remove from Admin Panel)
// ✅ Web Verification (Only after verified menu unlocks)
// ✅ Referral: 1 refer = 1 point (anti-duplicate)
// ✅ Withdraw: ₹5 / ₹10 Gift Card (dynamic points via settings)
// ✅ Admin: bulk coupon add (text or .txt file), stock, logs,
//          change withdraw points, broadcast, add/remove force groups
// ✅ Multiple admins via ADMIN_IDS env (comma separated)
// ============================================================


// ====================== CONFIG ======================
$BOT_TOKEN    = getenv("BOT_TOKEN");
$DATABASE_URL = getenv("DATABASE_URL");
$BASE_URL     = getenv("BASE_URL");
$BOT_USERNAME = getenv("BOT_USERNAME");

// Multiple admins: "123,456"
$ADMIN_IDS = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));

if (!$BOT_TOKEN || !$DATABASE_URL || !$BASE_URL || !$BOT_USERNAME) {
    exit("Missing ENV variables (BOT_TOKEN, DATABASE_URL, BASE_URL, BOT_USERNAME)");
}


// ====================== DB CONNECTION (Supabase URL -> PDO DSN) ======================
$db = parse_url($DATABASE_URL);
$host   = $db["host"] ?? "";
$port   = $db["port"] ?? 5432;
$userDB = $db["user"] ?? "";
$passDB = $db["pass"] ?? "";
$dbname = ltrim($db["path"] ?? "", "/");

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    $pdo = new PDO($dsn, $userDB, $passDB, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    exit("DB connect error: " . $e->getMessage());
}


// ====================== TELEGRAM API ======================
function bot($method, $data = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    return json_decode($res, true);
}

function isAdmin($id) {
    global $ADMIN_IDS;
    return in_array((string)$id, array_map('strval', $ADMIN_IDS), true);
}


// ====================== UI HELPERS ======================
function sendUserMenu($chat_id) {
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "✅ Verified! Choose an option:",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["📊 Stats", "🔗 Referral Link"],
                ["💰 Withdraw"]
            ],
            "resize_keyboard" => true
        ])
    ]);
}

function sendAdminMenu($chat_id) {
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "👑 Admin Panel",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["➕ Add Coupons", "📦 Stock"],
                ["⚙ Change Withdraw Points", "📜 Redeems Log"],
                ["📌 Add Force Group", "🗑 Remove Force Group"],
                ["📣 Broadcast", "⬅ Back To User Menu"]
            ],
            "resize_keyboard" => true
        ])
    ]);
}

function sendJoinPrompt($chat_id, $groups) {
    $buttons = [];
    foreach ($groups as $g) {
        $buttons[] = [
            ["text" => "Join Channel", "url" => $g["invite_link"]]
        ];
    }
    $buttons[] = [
        ["text" => "✅ Joined All Channels", "callback_data" => "check_join"]
    ];

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "🚨 You must join all required channels first:",
        "reply_markup" => json_encode(["inline_keyboard" => $buttons])
    ]);
}

function sendVerifyPrompt($chat_id, $verify_token) {
    global $BASE_URL;
    $verifyUrl = rtrim($BASE_URL, "/") . "/index.php?verify=" . urlencode($verify_token);

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "⚠️ Now complete web verification to unlock the bot:",
        "reply_markup" => json_encode([
            "inline_keyboard" => [
                [["text" => "✅ Verify Now", "url" => $verifyUrl]],
                [["text" => "✅ Completed Verification", "callback_data" => "check_verified"]]
            ]
        ])
    ]);
}


// ====================== STATE HELPERS ======================
function setState($userId, $state) {
    global $pdo;
    $pdo->prepare("UPDATE users SET state=? WHERE id=?")->execute([$state, $userId]);
}
function getState($userId) {
    global $pdo;
    $st = $pdo->prepare("SELECT state FROM users WHERE id=?");
    $st->execute([$userId]);
    return $st->fetchColumn() ?: "none";
}


// ====================== WEB VERIFICATION PAGE ======================
if (isset($_GET["verify"])) {
    $token = $_GET["verify"];

    echo "
    <html>
    <head>
      <title>Verification</title>
      <meta name='viewport' content='width=device-width, initial-scale=1' />
      <style>
        body{
          margin:0; padding:0;
          min-height:100vh;
          display:flex; align-items:center; justify-content:center;
          font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
          background:linear-gradient(135deg,#1f1c2c,#928dab);
          color:#fff;
        }
        .card{
          width:min(420px,92vw);
          background:rgba(0,0,0,.55);
          border:1px solid rgba(255,255,255,.12);
          border-radius:18px;
          padding:28px;
          box-shadow:0 0 28px rgba(0,255,200,.35);
          text-align:center;
        }
        h2{ margin:0 0 12px; }
        p{ opacity:.9; margin:0 0 18px; }
        button{
          width:100%;
          padding:14px 18px;
          font-size:18px;
          font-weight:700;
          background:#00ffcc;
          border:none;
          border-radius:12px;
          cursor:pointer;
        }
        .small{ font-size:12px; opacity:.75; margin-top:14px; }
      </style>
    </head>
    <body>
      <div class='card'>
        <h2>Complete Verification</h2>
        <p>Click the button below to verify your account.</p>
        <form method='post'>
          <input type='hidden' name='token' value='".htmlspecialchars($token, ENT_QUOTES)."'>
          <button name='verify' value='1'>✅ Verify Now</button>
        </form>
        <div class='small'>After verifying, return to Telegram and press <b>Completed Verification</b>.</div>
      </div>
    </body>
    </html>";
    exit;
}

if (isset($_POST["verify"]) && isset($_POST["token"])) {
    $token = $_POST["token"];
    $pdo->prepare("UPDATE users SET verified=true WHERE verify_token=?")->execute([$token]);

    echo "
    <html><head><meta name='viewport' content='width=device-width, initial-scale=1' /></head>
    <body style='font-family:sans-serif;text-align:center;margin-top:80px;'>
      <h2>✅ Verification Completed</h2>
      <p>Go back to Telegram and press <b>Completed Verification</b>.</p>
    </body></html>";
    exit;
}


// ====================== TELEGRAM UPDATE ======================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;


// ====================== CALLBACK HANDLING ======================
if (isset($update["callback_query"])) {
    $cb = $update["callback_query"];
    $cb_id = $cb["id"];
    $cb_user_id = $cb["from"]["id"];
    $data = $cb["data"] ?? "";

    bot("answerCallbackQuery", ["callback_query_id" => $cb_id]);

    // Ensure user exists
    $st = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$cb_user_id]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $token = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO users (id, username, verify_token) VALUES (?,?,?)")
            ->execute([$cb_user_id, "", $token]);

        $st->execute([$cb_user_id]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
    }

    // Fetch force groups
    $groups = $pdo->query("SELECT * FROM force_groups ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    if ($data === "check_join") {
        // Check membership for all
        foreach ($groups as $g) {
            $member = bot("getChatMember", [
                "chat_id" => $g["chat_id"],
                "user_id" => $cb_user_id
            ]);

            if (!isset($member["result"]["status"]) || $member["result"]["status"] === "left") {
                bot("sendMessage", [
                    "chat_id" => $cb_user_id,
                    "text" => "❌ You have not joined all required channels yet."
                ]);
                exit;
            }
        }

        // Joined all -> ask web verify
        if (!$user["verified"]) {
            sendVerifyPrompt($cb_user_id, $user["verify_token"]);
            exit;
        }

        // If already verified, show menu
        sendUserMenu($cb_user_id);
        exit;
    }

    if ($data === "check_verified") {
        // Re-fetch latest verification
        $st->execute([$cb_user_id]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        if (!$user["verified"]) {
            bot("sendMessage", [
                "chat_id" => $cb_user_id,
                "text" => "❌ Verification not completed yet.\nPlease click Verify Now and finish on website."
            ]);
            exit;
        }

        sendUserMenu($cb_user_id);
        exit;
    }

    exit;
}


// ====================== MESSAGE HANDLING ======================
$message = $update["message"] ?? null;
if (!$message) exit;

$chat_id  = $message["chat"]["id"];
$text     = $message["text"] ?? "";
$username = $message["from"]["username"] ?? "";

// Ensure user exists
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$chat_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $token = bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO users (id, username, verify_token) VALUES (?,?,?)")
        ->execute([$chat_id, $username, $token]);

    $stmt->execute([$chat_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Always keep username updated
if ($username && $username !== ($user["username"] ?? "")) {
    $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $chat_id]);
    $user["username"] = $username;
}


// ====================== ADMIN QUICK ACCESS ======================
if (isAdmin($chat_id) && $text === "/admin") {
    sendAdminMenu($chat_id);
    exit;
}
if (isAdmin($chat_id) && $text === "⬅ Back To User Menu") {
    sendUserMenu($chat_id);
    exit;
}
if (isAdmin($chat_id) && $text === "⬅ Back To Admin Panel") {
    sendAdminMenu($chat_id);
    exit;
}


// ====================== GATE: FORCE JOIN + WEB VERIFY (Only for non-verified users) ======================
if (!$user["verified"] && !isAdmin($chat_id)) {

    $groups = $pdo->query("SELECT * FROM force_groups ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // If there are required groups, ensure joined
    if ($groups) {
        $notJoined = false;
        foreach ($groups as $g) {
            $member = bot("getChatMember", [
                "chat_id" => $g["chat_id"],
                "user_id" => $chat_id
            ]);
            if (!isset($member["result"]["status"]) || $member["result"]["status"] === "left") {
                $notJoined = true;
                break;
            }
        }
        if ($notJoined) {
            sendJoinPrompt($chat_id, $groups);
            exit;
        }
    }

    // Joined -> require web verification
    sendVerifyPrompt($chat_id, $user["verify_token"]);
    exit;
}


// ====================== /START (referral + only show menu if verified) ======================
if (strpos($text, "/start") === 0) {

    // referral handling
    $parts = explode(" ", $text);
    if (isset($parts[1])) {
        $ref_id = (string)$parts[1];

        if ($ref_id !== (string)$chat_id) {
            // only reward once
            $check = $pdo->prepare("SELECT ref_by FROM users WHERE id=?");
            $check->execute([$chat_id]);
            $already = $check->fetchColumn();

            if (!$already) {
                // ref must exist
                $exists = $pdo->prepare("SELECT id FROM users WHERE id=?");
                $exists->execute([$ref_id]);

                if ($exists->fetchColumn()) {
                    $pdo->prepare("UPDATE users SET ref_by=? WHERE id=?")->execute([$ref_id, $chat_id]);
                    $pdo->prepare("UPDATE users SET points=points+1, total_ref=total_ref+1 WHERE id=?")->execute([$ref_id]);
                }
            }
        }
    }

    // refresh user
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // If verified -> show menu, else gate section above already exits (non-admin)
    if ($user["verified"]) {
        sendUserMenu($chat_id);
    }
    exit;
}


// ====================== USER FEATURES ======================
if ($text === "📊 Stats") {
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "📊 Your Stats\n\n⭐ Points: {$user['points']}\n👥 Referrals: {$user['total_ref']}"
    ]);
    exit;
}

if ($text === "🔗 Referral Link") {
    $link = "https://t.me/{$GLOBALS['BOT_USERNAME']}?start={$chat_id}";
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "🔗 Your Referral Link:\n{$link}\n\n✅ 1 referral = 1 point"
    ]);
    exit;
}

if ($text === "💰 Withdraw") {
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Select withdraw option:",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["₹5 Gift Card", "₹10 Gift Card"],
                ["⬅ Back"]
            ],
            "resize_keyboard" => true
        ])
    ]);
    exit;
}

if ($text === "⬅ Back") {
    sendUserMenu($chat_id);
    exit;
}


// ====================== WITHDRAW (₹5 / ₹10) ======================
if ($text === "₹5 Gift Card" || $text === "₹10 Gift Card") {

    $type = ($text === "₹5 Gift Card") ? "5" : "10";

    // dynamic required points
    $p = $pdo->prepare("SELECT value FROM settings WHERE key=?");
    $p->execute(["withdraw_{$type}_points"]);
    $points_required = (int)$p->fetchColumn();

    // refresh user points
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int)$user["points"] < $points_required) {
        bot("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "❌ Not enough points.\nRequired: {$points_required}\nYour points: {$user['points']}"
        ]);
        exit;
    }

    // pick a coupon
    $cst = $pdo->prepare("SELECT * FROM coupons WHERE type=? AND used=false ORDER BY id ASC LIMIT 1");
    $cst->execute([$type]);
    $coupon = $cst->fetch(PDO::FETCH_ASSOC);

    if (!$coupon) {
        bot("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "❌ Out of stock. Try later."
        ]);
        exit;
    }

    // apply transaction
    $pdo->prepare("UPDATE coupons SET used=true WHERE id=?")->execute([$coupon["id"]]);
    $pdo->prepare("UPDATE users SET points=points-? WHERE id=?")->execute([$points_required, $chat_id]);
    $pdo->prepare("INSERT INTO withdraws (user_id, code, type) VALUES (?,?,?)")
        ->execute([$chat_id, $coupon["code"], $type]);

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "✅ Withdrawal Successful!\n\nHere is your ₹{$type} Gift Card Code:\n\n`{$coupon['code']}`",
        "parse_mode" => "Markdown"
    ]);

    // notify admins
    foreach ($GLOBALS["ADMIN_IDS"] as $admin) {
        $admin = trim($admin);
        if ($admin === "") continue;
        bot("sendMessage", [
            "chat_id" => $admin,
            "text" => "🚨 New Withdrawal\nUser: {$chat_id}\nType: ₹{$type}\nCode: {$coupon['code']}"
        ]);
    }

    exit;
}


// ====================== ADMIN FEATURES ======================
$state = getState($chat_id);

// Admin menu entry via button
if (isAdmin($chat_id) && $text === "Admin Panel") {
    sendAdminMenu($chat_id);
    exit;
}

// ---- Stock
if (isAdmin($chat_id) && $text === "📦 Stock") {
    $stock5  = (int)$pdo->query("SELECT COUNT(*) FROM coupons WHERE type='5' AND used=false")->fetchColumn();
    $stock10 = (int)$pdo->query("SELECT COUNT(*) FROM coupons WHERE type='10' AND used=false")->fetchColumn();
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "📦 Current Stock\n\n₹5: {$stock5}\n₹10: {$stock10}"
    ]);
    exit;
}

// ---- Redeems log
if (isAdmin($chat_id) && $text === "📜 Redeems Log") {
    $logs = $pdo->query("SELECT * FROM withdraws ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (!$logs) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "No withdrawals yet."]);
        exit;
    }
    $msg = "📜 Last 10 Withdrawals:\n\n";
    foreach ($logs as $l) {
        $msg .= "User: {$l['user_id']} | ₹{$l['type']} | {$l['created_at']}\n";
    }
    bot("sendMessage", ["chat_id" => $chat_id, "text" => $msg]);
    exit;
}

// ---- Add coupons menu
if (isAdmin($chat_id) && $text === "➕ Add Coupons") {
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Select coupon type to add:",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["➕ Add ₹5 Coupons", "➕ Add ₹10 Coupons"],
                ["⬅ Back To Admin Panel"]
            ],
            "resize_keyboard" => true
        ])
    ]);
    exit;
}

if (isAdmin($chat_id) && $text === "➕ Add ₹5 Coupons") {
    setState($chat_id, "add_coupons_5");
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Send ₹5 coupon codes in bulk.\n\n✅ Send line-by-line text OR upload a .txt file."
    ]);
    exit;
}

if (isAdmin($chat_id) && $text === "➕ Add ₹10 Coupons") {
    setState($chat_id, "add_coupons_10");
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Send ₹10 coupon codes in bulk.\n\n✅ Send line-by-line text OR upload a .txt file."
    ]);
    exit;
}

// ---- Handle coupon input (text or document)
if (isAdmin($chat_id) && ($state === "add_coupons_5" || $state === "add_coupons_10")) {

    $type = ($state === "add_coupons_5") ? "5" : "10";
    $codesText = "";

    // 1) Document upload
    if (isset($message["document"])) {
        $file_id = $message["document"]["file_id"];
        $file = bot("getFile", ["file_id" => $file_id]);

        if (!isset($file["result"]["file_path"])) {
            bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Failed to read file. Try again."]);
            exit;
        }

        $file_path = $file["result"]["file_path"];
        $file_url  = "https://api.telegram.org/file/bot{$GLOBALS['BOT_TOKEN']}/{$file_path}";
        $codesText = @file_get_contents($file_url) ?: "";
    } else {
        // 2) Text message
        $codesText = trim($text);
    }

    $codesText = trim((string)$codesText);
    if ($codesText === "") {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ No codes found. Send again."]);
        exit;
    }

    $lines = preg_split("/\R+/", $codesText);
    $codes = [];
    foreach ($lines as $ln) {
        $c = trim($ln);
        if ($c !== "") $codes[] = $c;
    }
    $codes = array_values(array_unique($codes));

    $ins = $pdo->prepare("INSERT INTO coupons (code,type,used) VALUES (?,?,false)");
    $added = 0;

    foreach ($codes as $c) {
        try {
            $ins->execute([$c, $type]);
            $added++;
        } catch (Exception $e) {
            // ignore insert failures (duplicates etc.)
        }
    }

    setState($chat_id, "none");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Added {$added} coupons for ₹{$type}."]);
    exit;
}


// ---- Change withdraw points menu
if (isAdmin($chat_id) && $text === "⚙ Change Withdraw Points") {
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Select which points you want to change:",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["⚙ Set ₹5 Points", "⚙ Set ₹10 Points"],
                ["⬅ Back To Admin Panel"]
            ],
            "resize_keyboard" => true
        ])
    ]);
    exit;
}

if (isAdmin($chat_id) && $text === "⚙ Set ₹5 Points") {
    setState($chat_id, "set_points_5");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "Send new required points for ₹5 withdraw (number only)."]);
    exit;
}

if (isAdmin($chat_id) && $text === "⚙ Set ₹10 Points") {
    setState($chat_id, "set_points_10");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "Send new required points for ₹10 withdraw (number only)."]);
    exit;
}

if (isAdmin($chat_id) && ($state === "set_points_5" || $state === "set_points_10")) {
    $val = trim($text);
    if (!preg_match("/^\d+$/", $val)) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Please send a valid number only."]);
        exit;
    }

    $key = ($state === "set_points_5") ? "withdraw_5_points" : "withdraw_10_points";
    $pdo->prepare("UPDATE settings SET value=? WHERE key=?")->execute([$val, $key]);

    setState($chat_id, "none");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Updated {$key} = {$val}"]);
    exit;
}


// ---- Add force group
if (isAdmin($chat_id) && $text === "📌 Add Force Group") {
    setState($chat_id, "add_force_group");
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Send in this format:\n\nCHAT_ID | INVITE_LINK\n\nExample:\n-1001234567890 | https://t.me/yourlink"
    ]);
    exit;
}

if (isAdmin($chat_id) && $state === "add_force_group") {
    $parts = explode("|", $text);
    if (count($parts) != 2) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Wrong format.\nUse:\nCHAT_ID | INVITE_LINK"]);
        exit;
    }

    $chatId = trim($parts[0]);
    $link   = trim($parts[1]);

    $pdo->prepare("INSERT INTO force_groups (chat_id, invite_link) VALUES (?,?)")->execute([$chatId, $link]);

    setState($chat_id, "none");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Force group added."]);
    exit;
}


// ---- Remove force group
if (isAdmin($chat_id) && $text === "🗑 Remove Force Group") {
    $all = $pdo->query("SELECT * FROM force_groups ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$all) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "No force groups found."]);
        exit;
    }

    $msg = "Send the ID number to remove:\n\n";
    foreach ($all as $g) {
        $msg .= "ID: {$g['id']} | {$g['chat_id']}\n";
    }

    setState($chat_id, "remove_force_group");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => $msg]);
    exit;
}

if (isAdmin($chat_id) && $state === "remove_force_group") {
    $id = trim($text);
    if (!preg_match("/^\d+$/", $id)) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Send a valid group ID number."]);
        exit;
    }

    $pdo->prepare("DELETE FROM force_groups WHERE id=?")->execute([$id]);

    setState($chat_id, "none");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Removed force group ID {$id}"]);
    exit;
}


// ---- Broadcast
if (isAdmin($chat_id) && $text === "📣 Broadcast") {
    setState($chat_id, "broadcast");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "Send the broadcast message now."]);
    exit;
}

if (isAdmin($chat_id) && $state === "broadcast") {
    $msg = (string)$text;

    $users = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;

    foreach ($users as $u) {
        $r = bot("sendMessage", ["chat_id" => $u["id"], "text" => $msg]);
        if (isset($r["ok"]) && $r["ok"]) $sent++;
        usleep(25000); // small delay to avoid flood
    }

    setState($chat_id, "none");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Broadcast done. Sent to {$sent} users."]);
    exit;
}


// ====================== FALLBACK ======================
if (isAdmin($chat_id)) {
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "Use /admin to open Admin Panel."]);
} else {
    // verified users only reach here
    sendUserMenu($chat_id);
}
