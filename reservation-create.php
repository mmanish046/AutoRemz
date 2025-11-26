<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// User must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: signin.html');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$shopId       = isset($_POST['shop_id']) ? (int)$_POST['shop_id'] : 0;
$serviceName  = trim($_POST['service_name'] ?? '');
$pref1        = trim($_POST['preferred_time1'] ?? '');
$pref2        = trim($_POST['preferred_time2'] ?? '');
$pref3        = trim($_POST['preferred_time3'] ?? '');
$message      = trim($_POST['message'] ?? '');

if ($shopId <= 0 || $serviceName === '' || $pref1 === '') {
    // minimal validation
    header('Location: shop.php?id=' . $shopId . '&reservation=error');
    exit;
}

// Convert HTML5 datetime-local (YYYY-MM-DDTHH:MM) to MySQL DATETIME (space instead of T)
function to_mysql_datetime($input) {
    if ($input === '') return null;
    $input = str_replace('T', ' ', $input) . ':00'; // add seconds
    return $input;
}

$pref1_mysql = to_mysql_datetime($pref1);
$pref2_mysql = to_mysql_datetime($pref2);
$pref3_mysql = to_mysql_datetime($pref3);

// DB connection
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO
$password   = "HostingerDBpinetree90601@";   // TODO
$dbname     = "u138912455_autoremz_db";        // TODO

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    header('Location: shop.php?id=' . $shopId . '&reservation=error');
    exit;
}

$stmt = $conn->prepare("
  INSERT INTO reservations
    (user_id, shop_id, service_name, preferred_time1, preferred_time2, preferred_time3, message)
  VALUES
    (?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "iisssss",
    $userId,
    $shopId,
    $serviceName,
    $pref1_mysql,
    $pref2_mysql,
    $pref3_mysql,
    $message
);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    header('Location: shop.php?id=' . $shopId . '&reservation=ok');
    exit;
} else {
    $stmt->close();
    $conn->close();
    header('Location: shop.php?id=' . $shopId . '&reservation=error');
    exit;
}
