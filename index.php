<?php

// ====================== CONFIG ======================
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_IDS    = explode(",", getenv("ADMIN_IDS"));
$DATABASE_URL = getenv("DATABASE_URL");
$BASE_URL     = getenv("BASE_URL");
$BOT_USERNAME = getenv("BOT_USERNAME");

if (!$BOT_TOKEN || !$DATABASE_URL) {
    exit("Missing ENV variables");
}

// ================= DATABASE CONNECTION FIX =================
$db = parse_url(getenv("DATABASE_URL"));

$host = $db["host"];
$port = $db["port"];
$user = $db["user"];
$pass = $db["pass"];
$dbname = ltrim($db["path"], "/");

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// ====================== TELEGRAM FUNCTION ======================
function bot($method, $data = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $res = curl_exec($ch);
    curl_close($ch);

    return json_decode($res, true);
}

// ====================== WEB VERIFICATION ======================
if (isset($_GET['verify'])) {

    $token = $_GET['verify'];

    echo "
    <html>
    <head>
    <title>Verification</title>
    <style>
    body {
        background: linear-gradient(135deg,#1f1c2c,#928dab);
        font-family: sans-serif;
        text-align: center;
        padding-top: 100px;
        color: white;
    }
    .card {
        background: #111;
        padding: 40px;
        width: 350px;
        margin: auto;
        border-radius: 15px;
        box-shadow: 0 0 20px rgba(0,255,200,0.5);
    }
    button {
        padding: 15px 30px;
        font-size: 18px;
        background: #00ffcc;
        border: none;
        border-radius: 10px;
        cursor: pointer;
    }
    </style>
    </head>
    <body>
        <div class='card'>
            <h2>Complete Verification</h2>
            <form method='post'>
                <input type='hidden' name='token' value='$token'>
                <button name='verify'>Verify Now</button>
            </form>
        </div>
    </body>
    </html>
    ";
    exit;
}

if (isset($_POST['verify'])) {
    $pdo->prepare("UPDATE users SET verified = true WHERE verify_token = ?")
        ->execute([$_POST['token']]);

    echo "<h2 style='text-align:center;margin-top:100px;'>Verification Completed ✅<br>Return to Telegram</h2>";
    exit;
}

// ====================== HANDLE TELEGRAM UPDATE ======================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

$message = $update["message"] ?? null;
if (!$message) exit;

$chat_id  = $message["chat"]["id"];
$text     = $message["text"] ?? "";
$username = $message["from"]["username"] ?? "";

// ====================== CREATE USER ======================
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$chat_id]);
$user = $stmt->fetch();

if (!$user) {
    $token = bin2hex(random_bytes(8));

    $pdo->prepare("INSERT INTO users (id, username, verify_token) VALUES (?, ?, ?)")
        ->execute([$chat_id, $username, $token]);

    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();
}

// ====================== FORCE JOIN CHECK ======================
$groups = $pdo->query("SELECT * FROM force_groups")->fetchAll(PDO::FETCH_ASSOC);

foreach ($groups as $group) {

    $check = bot("getChatMember", [
        "chat_id" => $group['chat_id'],
        "user_id" => $chat_id
    ]);

    if (isset($check['result']['status']) && $check['result']['status'] == "left") {

        $buttons = [];

        foreach ($groups as $g) {
            $buttons[] = [
                ["text" => "Join Channel", "url" => $g['invite_link']]
            ];
        }

        $buttons[] = [
            ["text" => "Joined All Channels", "callback_data" => "check_join"]
        ];

        bot("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🚨 Please join all required channels:",
            "reply_markup" => json_encode(["inline_keyboard" => $buttons])
        ]);

        exit;
    }
}

// ====================== REFERRAL SYSTEM ======================
if (strpos($text, "/start") === 0) {

    $parts = explode(" ", $text);

    if (isset($parts[1])) {
        $ref_id = $parts[1];

        if ($ref_id != $chat_id) {

            if (!$user['ref_by']) {

                $pdo->prepare("UPDATE users SET ref_by = ? WHERE id = ?")
                    ->execute([$ref_id, $chat_id]);

                $pdo->prepare("UPDATE users SET points = points + 1, total_ref = total_ref + 1 WHERE id = ?")
                    ->execute([$ref_id]);
            }
        }
    }

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "🎉 Welcome to Refer & Earn Bot!",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["📊 Stats", "🔗 Referral Link"],
                ["💰 Withdraw"]
            ],
            "resize_keyboard" => true
        ])
    ]);
}

// ====================== USER MENU ======================
if ($text == "📊 Stats") {

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Points: {$user['points']}\nReferrals: {$user['total_ref']}"
    ]);
}

if ($text == "🔗 Referral Link") {

    $link = "https://t.me/$BOT_USERNAME?start=$chat_id";

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Your Referral Link:\n$link"
    ]);
}

if ($text == "💰 Withdraw") {

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Choose withdraw option:",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["₹5 Gift Card", "₹10 Gift Card"]
            ],
            "resize_keyboard" => true
        ])
    ]);
}

// ====================== WITHDRAW SYSTEM ======================

// When user selects ₹5 or ₹10
if ($text == "₹5 Gift Card" || $text == "₹10 Gift Card") {

    $type = ($text == "₹5 Gift Card") ? "5" : "10";

    // Get required points dynamically
    $points_required = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
    $points_required->execute(["withdraw_{$type}_points"]);
    $points_required = $points_required->fetchColumn();

    // Refresh user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();

    if ($user['points'] < $points_required) {

        bot("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "❌ Not enough points.\nRequired: $points_required"
        ]);
        exit;
    }

    // Get available coupon
    $coupon_stmt = $pdo->prepare("SELECT * FROM coupons WHERE type = ? AND used = false LIMIT 1");
    $coupon_stmt->execute([$type]);
    $coupon = $coupon_stmt->fetch();

    if (!$coupon) {

        bot("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "❌ Out of stock. Try later."
        ]);
        exit;
    }

    // Mark coupon used
    $pdo->prepare("UPDATE coupons SET used = true WHERE id = ?")
        ->execute([$coupon['id']]);

    // Deduct points
    $pdo->prepare("UPDATE users SET points = points - ? WHERE id = ?")
        ->execute([$points_required, $chat_id]);

    // Save withdraw log
    $pdo->prepare("INSERT INTO withdraws (user_id, code, type) VALUES (?, ?, ?)")
        ->execute([$chat_id, $coupon['code'], $type]);

    // Send coupon to user
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "🎉 Withdrawal Successful!\n\nHere is your ₹$type Gift Card Code:\n\n`{$coupon['code']}`",
        "parse_mode" => "Markdown"
    ]);

    // Notify all admins
    foreach ($ADMIN_IDS as $admin) {

        bot("sendMessage", [
            "chat_id" => $admin,
            "text" => "🚨 New Withdrawal\n\nUser: $chat_id\nType: ₹$type\nCode: {$coupon['code']}"
        ]);
    }
}


// ====================== ADMIN STOCK CHECK ======================
if (in_array($chat_id, $ADMIN_IDS) && $text == "📦 Stock") {

    $stock5 = $pdo->query("SELECT COUNT(*) FROM coupons WHERE type='5' AND used=false")->fetchColumn();
    $stock10 = $pdo->query("SELECT COUNT(*) FROM coupons WHERE type='10' AND used=false")->fetchColumn();

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "📦 Current Stock:\n\n₹5 Gift Cards: $stock5\n₹10 Gift Cards: $stock10"
    ]);
}


// ====================== ADMIN REDEEMS LOG ======================
if (in_array($chat_id, $ADMIN_IDS) && $text == "📜 Redeems Log") {

    $logs = $pdo->query("SELECT * FROM withdraws ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

    if (!$logs) {
        bot("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "No withdrawals yet."
        ]);
        exit;
    }

    $msg = "📜 Last 10 Withdrawals:\n\n";

    foreach ($logs as $log) {
        $msg .= "User: {$log['user_id']} | ₹{$log['type']}\n";
    }

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => $msg
    ]);
}

// ====================== CALLBACK HANDLER (Joined All Channels / Completed Verification) ======================
$callback = $update["callback_query"] ?? null;

if ($callback) {

    $cb_chat_id = $callback["from"]["id"];
    $cb_data    = $callback["data"] ?? "";
    $cb_id      = $callback["id"];

    // Always answer callback (prevents loading spinner)
    bot("answerCallbackQuery", ["callback_query_id" => $cb_id]);

    // Refresh user
    $st = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$cb_chat_id]);
    $cb_user = $st->fetch();

    // 1) User clicked "Joined All Channels"
    if ($cb_data === "check_join") {

        $groups = $pdo->query("SELECT * FROM force_groups")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($groups as $g) {
            $member = bot("getChatMember", [
                "chat_id" => $g["chat_id"],
                "user_id" => $cb_chat_id
            ]);

            if (isset($member["result"]["status"]) && $member["result"]["status"] === "left") {
                bot("sendMessage", [
                    "chat_id" => $cb_chat_id,
                    "text" => "❌ You have not joined all required groups yet."
                ]);
                exit;
            }
        }

        // Joined OK -> send web verify message
        $verifyUrl = rtrim($BASE_URL, "/") . "/index.php?verify=" . urlencode($cb_user["verify_token"]);

        bot("sendMessage", [
            "chat_id" => $cb_chat_id,
            "text" => "✅ Channels joined!\n\nNow complete web verification:",
            "reply_markup" => json_encode([
                "inline_keyboard" => [
                    [
                        ["text" => "✅ Verify Now", "url" => $verifyUrl]
                    ],
                    [
                        ["text" => "✅ Completed Verification", "callback_data" => "check_verified"]
                    ]
                ]
            ])
        ]);

        exit;
    }

    // 2) User clicked "Completed Verification"
    if ($cb_data === "check_verified") {

        // Refresh user again to get latest verified status
        $st = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $st->execute([$cb_chat_id]);
        $cb_user = $st->fetch();

        if (!$cb_user || !$cb_user["verified"]) {
            bot("sendMessage", [
                "chat_id" => $cb_chat_id,
                "text" => "❌ Verification not completed yet.\nPlease click Verify Now first and finish on website."
            ]);
            exit;
        }

        // Verified -> show main menu
        bot("sendMessage", [
            "chat_id" => $cb_chat_id,
            "text" => "🎉 Verification completed! All options unlocked.",
            "reply_markup" => json_encode([
                "keyboard" => [
                    ["📊 Stats", "🔗 Referral Link"],
                    ["💰 Withdraw"]
                ],
                "resize_keyboard" => true
            ])
        ]);

        exit;
    }

    exit;
}


// ====================== ADMIN PANEL MENU ======================
if (in_array($chat_id, $ADMIN_IDS) && $text == "/admin") {

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
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && $text == "⬅ Back To User Menu") {
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "User menu:",
        "reply_markup" => json_encode([
            "keyboard" => [
                ["📊 Stats", "🔗 Referral Link"],
                ["💰 Withdraw"]
            ],
            "resize_keyboard" => true
        ])
    ]);
    exit;
}


// ====================== ADMIN STATE STORAGE HELPERS ======================
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


// ====================== ADMIN: ADD COUPONS FLOW ======================
if (in_array($chat_id, $ADMIN_IDS) && $text == "➕ Add Coupons") {

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

if (in_array($chat_id, $ADMIN_IDS) && $text == "⬅ Back To Admin Panel") {
    // reopen admin menu quickly
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
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && $text == "➕ Add ₹5 Coupons") {
    setState($chat_id, "add_coupons_5");
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Send ₹5 coupon codes (bulk).\n\n✅ Send line-by-line in message OR upload a .txt file."
    ]);
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && $text == "➕ Add ₹10 Coupons") {
    setState($chat_id, "add_coupons_10");
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Send ₹10 coupon codes (bulk).\n\n✅ Send line-by-line in message OR upload a .txt file."
    ]);
    exit;
}


// ====================== ADMIN: HANDLE COUPON INPUT (TEXT OR FILE) ======================
$state = getState($chat_id);

if (in_array($chat_id, $ADMIN_IDS) && ($state === "add_coupons_5" || $state === "add_coupons_10")) {

    $type = ($state === "add_coupons_5") ? "5" : "10";

    $codesText = "";

    // Text codes
    if (!empty($text) && $text !== "➕ Add ₹5 Coupons" && $text !== "➕ Add ₹10 Coupons") {
        $codesText = $text;
    }

    // File upload
    if (isset($message["document"])) {
        $file_id = $message["document"]["file_id"];
        $file = bot("getFile", ["file_id" => $file_id]);
        if (!isset($file["result"]["file_path"])) {
            bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Failed to read file."]);
            exit;
        }
        $file_path = $file["result"]["file_path"];
        $file_url = "https://api.telegram.org/file/bot$BOT_TOKEN/$file_path";
        $codesText = @file_get_contents($file_url);
    }

    $codesText = trim((string)$codesText);

    if ($codesText === "") {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ No codes found. Send again."]);
        exit;
    }

    // Parse codes line by line, remove duplicates empty lines
    $lines = preg_split("/\R+/", $codesText);
    $codes = [];
    foreach ($lines as $ln) {
        $c = trim($ln);
        if ($c !== "") $codes[] = $c;
    }
    $codes = array_values(array_unique($codes));

    if (count($codes) === 0) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ No valid lines found."]);
        exit;
    }

    // Insert
    $ins = $pdo->prepare("INSERT INTO coupons (code,type,used) VALUES (?,?,false)");

    $added = 0;
    foreach ($codes as $c) {
        try {
            $ins->execute([$c, $type]);
            $added++;
        } catch (Exception $e) {
            // ignore duplicates if DB has none-unique; otherwise skip errors
        }
    }

    setState($chat_id, "none");

    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "✅ Added $added coupons for ₹$type."
    ]);
    exit;
}


// ====================== ADMIN: CHANGE WITHDRAW POINTS ======================
if (in_array($chat_id, $ADMIN_IDS) && $text == "⚙ Change Withdraw Points") {

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

if (in_array($chat_id, $ADMIN_IDS) && $text == "⚙ Set ₹5 Points") {
    setState($chat_id, "set_points_5");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "Send new required points for ₹5 withdraw (number only)."]);
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && $text == "⚙ Set ₹10 Points") {
    setState($chat_id, "set_points_10");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "Send new required points for ₹10 withdraw (number only)."]);
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && ($state === "set_points_5" || $state === "set_points_10")) {

    $val = trim($text);
    if (!preg_match("/^\d+$/", $val)) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Please send a valid number only."]);
        exit;
    }

    $key = ($state === "set_points_5") ? "withdraw_5_points" : "withdraw_10_points";
    $pdo->prepare("UPDATE settings SET value=? WHERE key=?")->execute([$val, $key]);

    setState($chat_id, "none");

    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Updated $key = $val"]);
    exit;
}


// ====================== ADMIN: FORCE GROUP ADD/REMOVE ======================
if (in_array($chat_id, $ADMIN_IDS) && $text == "📌 Add Force Group") {
    setState($chat_id, "add_force_group");
    bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "Send in this format:\n\nCHAT_ID | INVITE_LINK\n\nExample:\n-1001234567890 | https://t.me/yourlink"
    ]);
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && $state === "add_force_group") {

    $parts = explode("|", $text);
    if (count($parts) != 2) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Wrong format. Use:\nCHAT_ID | INVITE_LINK"]);
        exit;
    }

    $chatId = trim($parts[0]);
    $link   = trim($parts[1]);

    $pdo->prepare("INSERT INTO force_groups (chat_id, invite_link) VALUES (?,?)")
        ->execute([$chatId, $link]);

    setState($chat_id, "none");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Force group added."]);
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && $text == "🗑 Remove Force Group") {

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

if (in_array($chat_id, $ADMIN_IDS) && $state === "remove_force_group") {

    $id = trim($text);
    if (!preg_match("/^\d+$/", $id)) {
        bot("sendMessage", ["chat_id" => $chat_id, "text" => "❌ Send a valid group ID number."]);
        exit;
    }

    $pdo->prepare("DELETE FROM force_groups WHERE id=?")->execute([$id]);

    setState($chat_id, "none");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Removed force group ID $id"]);
    exit;
}


// ====================== ADMIN: BROADCAST ======================
if (in_array($chat_id, $ADMIN_IDS) && $text == "📣 Broadcast") {
    setState($chat_id, "broadcast");
    bot("sendMessage", ["chat_id" => $chat_id, "text" => "Send the broadcast message now."]);
    exit;
}

if (in_array($chat_id, $ADMIN_IDS) && $state === "broadcast") {

    $msg = $text;

    $users = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;

    foreach ($users as $u) {
        $r = bot("sendMessage", ["chat_id" => $u['id'], "text" => $msg]);
        if (isset($r["ok"]) && $r["ok"]) $sent++;
        usleep(25000); // small delay to avoid flood
    }

    setState($chat_id, "none");

    bot("sendMessage", ["chat_id" => $chat_id, "text" => "✅ Broadcast done. Sent to $sent users."]);
    exit;
}

// ====================== PERFORMANCE OPTIMIZATION ======================

// Only enforce force join + verification if user NOT verified
$stmt = $pdo->prepare("SELECT verified FROM users WHERE id=?");
$stmt->execute([$chat_id]);
$current_verified = $stmt->fetchColumn();

if (!$current_verified) {

    $groups = $pdo->query("SELECT * FROM force_groups")->fetchAll(PDO::FETCH_ASSOC);

    if ($groups) {

        $notJoined = false;

        foreach ($groups as $group) {

            $member = bot("getChatMember", [
                "chat_id" => $group['chat_id'],
                "user_id" => $chat_id
            ]);

            if (!isset($member['result']['status']) || $member['result']['status'] == "left") {
                $notJoined = true;
                break;
            }
        }

        if ($notJoined) {

            $buttons = [];

            foreach ($groups as $g) {
                $buttons[] = [
                    ["text" => "Join Channel", "url" => $g['invite_link']]
                ];
            }

            $buttons[] = [
                ["text" => "Joined All Channels", "callback_data" => "check_join"]
            ];

            bot("sendMessage", [
                "chat_id" => $chat_id,
                "text" => "🚨 You must join all required channels first.",
                "reply_markup" => json_encode(["inline_keyboard" => $buttons])
            ]);

            exit;
        }
    }

    // If joined but not verified → ask for verification
    $stmt = $pdo->prepare("SELECT verified, verify_token FROM users WHERE id=?");
    $stmt->execute([$chat_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u['verified']) {

        $verifyUrl = rtrim($BASE_URL, "/") . "/index.php?verify=" . urlencode($u["verify_token"]);

        bot("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "⚠ Please complete web verification to continue.",
            "reply_markup" => json_encode([
                "inline_keyboard" => [
                    [["text" => "✅ Verify Now", "url" => $verifyUrl]],
                    [["text" => "✅ Completed Verification", "callback_data" => "check_verified"]]
                ]
            ])
        ]);

        exit;
    }
}


// ====================== ANTI REFERRAL SPAM FIX ======================
// Prevent self referral and double referral reward

if (strpos($text, "/start") === 0) {

    $parts = explode(" ", $text);

    if (isset($parts[1])) {

        $ref_id = intval($parts[1]);

        if ($ref_id != $chat_id) {

            $check = $pdo->prepare("SELECT ref_by FROM users WHERE id=?");
            $check->execute([$chat_id]);
            $already = $check->fetchColumn();

            if (!$already) {

                $exists = $pdo->prepare("SELECT id FROM users WHERE id=?");
                $exists->execute([$ref_id]);

                if ($exists->fetch()) {

                    $pdo->prepare("UPDATE users SET ref_by=? WHERE id=?")
                        ->execute([$ref_id, $chat_id]);

                    $pdo->prepare("UPDATE users SET points=points+1,total_ref=total_ref+1 WHERE id=?")
                        ->execute([$ref_id]);
                }
            }
        }
    }
}


// ====================== SAFE SEND FUNCTION (ANTI FLOOD) ======================
function safeSend($chat_id, $text, $keyboard = null) {

    $data = [
        "chat_id" => $chat_id,
        "text" => $text
    ];

    if ($keyboard) {
        $data["reply_markup"] = json_encode($keyboard);
    }

    $res = bot("sendMessage", $data);

    usleep(25000); // 25ms delay to prevent flood
    return $res;
}
