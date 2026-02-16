<?php
/*
====================================================
 REFER & EARN BOT - FULL SYSTEM
 Webhook + Website Verify in SAME file
 Render Ready
====================================================
*/

/* ================== ENV ================== */

$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_IDS = explode(",", getenv("ADMIN_IDS"));
$DB_URL = getenv("DATABASE_URL");
$WEB_URL = getenv("WEB_URL");

if (!$BOT_TOKEN || !$DB_URL) {
    die("Missing environment variables");
}

/* ================== DB ================== */

$db = new PDO($DB_URL);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ================== TELEGRAM API ================== */

function bot($method, $data = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot".$BOT_TOKEN."/".$method;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

/* ================== WEBSITE VERIFY ================== */

if (isset($_GET['verify'])) {

    $user_id = intval($_GET['verify']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = $db->prepare("UPDATE users SET is_verified=TRUE WHERE id=?");
        $stmt->execute([$user_id]);

        echo "<script>
        window.location.href='https://t.me/".bot_username()."';
        </script>";
        exit;
    }

    echo '
    <html>
    <head>
    <title>Verification</title>
    <style>
    body{
        background:linear-gradient(135deg,#4e73df,#1cc88a);
        display:flex;
        justify-content:center;
        align-items:center;
        height:100vh;
        font-family:sans-serif;
    }
    .card{
        background:white;
        padding:40px;
        border-radius:20px;
        box-shadow:0 10px 30px rgba(0,0,0,0.2);
        text-align:center;
    }
    button{
        padding:12px 25px;
        border:none;
        border-radius:10px;
        background:#4e73df;
        color:white;
        font-size:16px;
        cursor:pointer;
    }
    </style>
    </head>
    <body>
    <div class="card">
    <h2>Verify Account</h2>
    <form method="POST">
    <button type="submit">Verify Now</button>
    </form>
    </div>
    </body>
    </html>';
    exit;
}

/* ================== UPDATE ================== */

$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

$message = $update["message"] ?? null;
if (!$message) exit;

$chat_id = $message["chat"]["id"];
$user_id = $message["from"]["id"];
$text = $message["text"] ?? "";
$username = $message["from"]["username"] ?? "NoUsername";

/* ================== USER CHECK ================== */

$stmt = $db->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $db->prepare("INSERT INTO users(id,username) VALUES(?,?)")
        ->execute([$user_id,$username]);
}

/* ================== START ================== */

if (strpos($text, "/start") === 0) {

    $parts = explode(" ", $text);
    $ref = $parts[1] ?? null;

    if ($ref && $ref != $user_id) {
        $check = $db->prepare("SELECT referred_by FROM users WHERE id=?");
        $check->execute([$user_id]);
        $existing = $check->fetchColumn();

        if (!$existing) {
            $db->prepare("UPDATE users SET referred_by=? WHERE id=?")
                ->execute([$ref,$user_id]);

            $db->prepare("UPDATE users SET points=points+1,total_refers=total_refers+1 WHERE id=?")
                ->execute([$ref]);
        }
    }

    show_force_join($chat_id);
    exit;
}

/* ================== FORCE JOIN CHECK ================== */

if ($text == "Joined All Channels") {
    if (check_all_joined($user_id)) {
        show_web_verify($chat_id);
    } else {
        bot("sendMessage",[
            "chat_id"=>$chat_id,
            "text"=>"You must join all groups!"
        ]);
    }
    exit;
}

/* ================== COMPLETE VERIFY ================== */

if ($text == "Completed Verification") {

    $check = $db->prepare("SELECT is_verified FROM users WHERE id=?");
    $check->execute([$user_id]);
    if ($check->fetchColumn()) {
        show_main_menu($chat_id,$user_id);
    } else {
        bot("sendMessage",[
            "chat_id"=>$chat_id,
            "text"=>"Please verify on website first."
        ]);
    }
    exit;
}

/* ================== MAIN MENU ================== */

if ($text == "📊 Stats") {
    $stmt = $db->prepare("SELECT points,total_refers FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    bot("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Points: ".$u['points']."\nRefers: ".$u['total_refers']
    ]);
}

if ($text == "🔗 Referral Link") {
    bot("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Your Link:\nhttps://t.me/".bot_username()."?start=".$user_id
    ]);
}

/* ================== WITHDRAW ================== */

if ($text == "💰 Withdraw") {

    $keyboard = [
        "keyboard"=>[
            [["₹5 Gift Card"],["₹10 Gift Card"]],
            [["⬅ Back"]]
        ],
        "resize_keyboard"=>true
    ];

    bot("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Choose Withdraw Option:",
        "reply_markup"=>json_encode($keyboard)
    ]);
}

/* ================== FUNCTIONS ================== */

function show_force_join($chat_id){
    global $db;

    $groups = $db->query("SELECT * FROM force_groups")->fetchAll(PDO::FETCH_ASSOC);

    $text = "Join these groups:\n\n";
    $buttons = [];

    foreach($groups as $g){
        $text .= "• ".$g['title']."\n";
        $buttons[] = [["text"=>$g['title'],"url"=>"https://t.me/".$g['title']]];
    }

    $keyboard = [
        "keyboard"=>[
            [["Joined All Channels"]]
        ],
        "resize_keyboard"=>true
    ];

    bot("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>$text,
        "reply_markup"=>json_encode($keyboard)
    ]);
}

function check_all_joined($user_id){
    global $db;

    $groups = $db->query("SELECT chat_id FROM force_groups")->fetchAll(PDO::FETCH_COLUMN);

    foreach($groups as $chat){
        $res = json_decode(bot("getChatMember",[
            "chat_id"=>$chat,
            "user_id"=>$user_id
        ]),true);

        if ($res["result"]["status"] == "left") {
            return false;
        }
    }
    return true;
}

function show_web_verify($chat_id){
    global $WEB_URL,$user_id;

    $keyboard = [
        "keyboard"=>[
            [["🌐 Verify Now"]],
            [["Completed Verification"]]
        ],
        "resize_keyboard"=>true
    ];

    bot("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Click Verify Now",
        "reply_markup"=>json_encode($keyboard)
    ]);
}

function show_main_menu($chat_id,$user_id){
    global $ADMIN_IDS;

    $keyboard = [
        "keyboard"=>[
            [["📊 Stats"],["🔗 Referral Link"]],
            [["💰 Withdraw"]]
        ],
        "resize_keyboard"=>true
    ];

    if (in_array($user_id,$ADMIN_IDS)) {
        $keyboard["keyboard"][] = [["🛠 Admin Panel"]];
    }

    bot("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Welcome!",
        "reply_markup"=>json_encode($keyboard)
    ]);
}

function bot_username(){
    return "YOUR_BOT_USERNAME";
}
?>
