<?php

define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));

function getDB(): PDO {
    static $pdo = null;
    if($pdo) return $pdo;

    $dsn = getenv('DATABASE_URL');
    $parsed = parse_url($dsn);

    $host   = $parsed['host'];
    $port   = $parsed['port'] ?? 5432;
    $dbname = ltrim($parsed['path'], '/');
    $user   = $parsed['user'];
    $pass   = $parsed['pass'];

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    return $pdo;
}

function jsonOut(array $data){
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.html");
    exit;
}

if($_SESSION['email'] !== ADMIN_EMAIL){
    header("Location: index.php");
    exit;
}

$db = getDB();

$action = $_POST['action'] ?? '';

if($action === "add_tokens"){

    $uid = trim($_POST['user_uid'] ?? '');
    $amount = (int)($_POST['amount'] ?? 0);

    if(!$uid || $amount <= 0){
        jsonOut(['success'=>false,'message'=>'Noto‘g‘ri ma‘lumot']);
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE user_uid=:uid");
    $stmt->bindValue(":uid",$uid);
    $stmt->execute();

    if(!$stmt->fetch()){
        jsonOut(['success'=>false,'message'=>'User topilmadi']);
    }

    $upd = $db->prepare("UPDATE users SET tokens = tokens + :amount WHERE user_uid = :uid");
    $upd->bindValue(":amount",$amount,PDO::PARAM_INT);
    $upd->bindValue(":uid",$uid);
    $upd->execute();

    jsonOut(['success'=>true,'message'=>"Token qo‘shildi"]);
}

/* ------------------- STATS ------------------- */

$totalUsers = $db->query("
SELECT COUNT(*)
FROM users
WHERE verified IS TRUE
")->fetchColumn();

$totalImages = $db->query("
SELECT COUNT(*)
FROM image_history
")->fetchColumn();

$todayImages = $db->query("
SELECT COUNT(*)
FROM image_history
WHERE DATE(created_at) = CURRENT_DATE
")->fetchColumn();

/* ------------------- USERS ------------------- */

$userStmt = $db->query("
SELECT *
FROM users
ORDER BY id DESC
");

$users = $userStmt->fetchAll();

/* ------------------- HISTORY ------------------- */

$histStmt = $db->query("
SELECT ih.*,u.email
FROM image_history ih
LEFT JOIN users u ON u.id = ih.user_id
ORDER BY ih.created_at DESC
LIMIT 100
");

$history = $histStmt->fetchAll();

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Admin Panel</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<h2>Admin Panel</h2>

<h3>Statistika</h3>

<ul>
<li>Foydalanuvchilar: <?= $totalUsers ?></li>
<li>Bugungi rasmlar: <?= $todayImages ?></li>
<li>Jami rasmlar: <?= $totalImages ?></li>
</ul>

<h3>Token qo‘shish</h3>

<input type="text" id="uid" placeholder="User ID">
<input type="number" id="amount" placeholder="Token">

<button onclick="addToken()">Token qo‘shish</button>

<hr>

<h3>Foydalanuvchilar</h3>

<table border="1">
<tr>
<th>Email</th>
<th>User ID</th>
<th>Limit</th>
<th>Token</th>
</tr>

<?php foreach($users as $u): ?>

<tr>
<td><?= htmlspecialchars($u['email']) ?></td>
<td><?= $u['user_uid'] ?></td>
<td><?= $u['daily_limit'] ?></td>
<td><?= $u['tokens'] ?></td>
</tr>

<?php endforeach; ?>

</table>

<hr>

<h3>Rasm tarixi</h3>

<table border="1">

<tr>
<th>Email</th>
<th>User ID</th>
<th>Prompt</th>
<th>Sana</th>
</tr>

<?php foreach($history as $h): ?>

<tr>
<td><?= htmlspecialchars($h['email']) ?></td>
<td><?= $h['user_uid'] ?></td>
<td><?= htmlspecialchars($h['prompt']) ?></td>
<td><?= $h['created_at'] ?></td>
</tr>

<?php endforeach; ?>

</table>

<script>

async function addToken(){

const uid = document.getElementById("uid").value
const amount = document.getElementById("amount").value

const res = await fetch("admin.php",{
method:"POST",
headers:{
"Content-Type":"application/x-www-form-urlencoded"
},
body:`action=add_tokens&user_uid=${uid}&amount=${amount}`
})

const data = await res.json()

alert(data.message)

location.reload()

}

</script>

</body>
</html>
