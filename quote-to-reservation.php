<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Must be logged in as user or shop
$isUser = isset($_SESSION['user_id']);
$isShop = isset($_SESSION['shop_id']);

if (!$isUser && !$isShop) {
    header("Location: signin.html");
    exit;
}

$userId = $isUser ? (int)$_SESSION['user_id'] : null;
$shopId = $isShop ? (int)$_SESSION['shop_id'] : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$relId          = isset($_POST['rel_id']) ? (int)$_POST['rel_id'] : 0;
$serviceName    = trim($_POST['service_name']    ?? '');
$preferredTime1 = trim($_POST['preferred_time1'] ?? '');
$note           = trim($_POST['note']            ?? '');

if ($relId <= 0 || $serviceName === '' || $preferredTime1 === '') {
    // Simple validation – you can improve error handling if you want
    header("Location: index.php");
    exit;
}

// DB settings
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}

// 1) Load quote relation + quote + user + shop
$sql = "
  SELECT
    qrs.id            AS rel_id,
    qrs.quote_id,
    qrs.shop_id,
    qrs.status        AS rel_status,
    q.user_id,
    q.title,
    q.details,
    q.status          AS quote_status
  FROM quote_requests_shops qrs
  JOIN quote_requests q ON qrs.quote_id = q.id
  WHERE qrs.id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $relId);
$stmt->execute();
$relData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$relData) {
    $conn->close();
    die("Quote relation not found.");
}

$quoteUserId = (int)$relData['user_id'];
$quoteShopId = (int)$relData['shop_id'];
$quoteId     = (int)$relData['quote_id'];

// 2) Permission check
if ($isUser && $quoteUserId !== $userId) {
    $conn->close();
    die("Unauthorized (user).");
}
if ($isShop && $quoteShopId !== $shopId) {
    $conn->close();
    die("Unauthorized (shop).");
}

// For the reservation record, make sure we know user_id and shop_id
// If shop is triggering, user_id still comes from quote
if ($isShop && !$isUser) {
    $userId = $quoteUserId;
}
if ($isUser && !$isShop) {
    $shopId = $quoteShopId;
}

// 3) Insert into reservations
// Adjust column names if your existing reservations table differs
$insSql = "
  INSERT INTO reservations
    (user_id, shop_id, service_name, preferred_time1, preferred_time2, preferred_time3, message, status, final_time, created_at)
  VALUES
    (?, ?, ?, ?, NULL, NULL, ?, 'pending', NULL, NOW())
";
$stmt = $conn->prepare($insSql);
if (!$stmt) {
    die("Prepare failed for reservation insert: " . $conn->error);
}
$stmt->bind_param(
    "iisss",
    $userId,
    $shopId,
    $serviceName,
    $preferredTime1,
    $note
);
if (!$stmt->execute()) {
    die("Execute failed for reservation insert: " . $stmt->error);
}
$reservationId = $stmt->insert_id;
$stmt->close();

// 4) Mark quote as booked + mark THIS shop relation as booked
$quoteStatus = 'booked';

// main quote
$stmt = $conn->prepare("
  UPDATE quote_requests
  SET status = ?
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("si", $quoteStatus, $quoteId);
$stmt->execute();
$stmt->close();

// this shop's relation
$stmt = $conn->prepare("
  UPDATE quote_requests_shops
  SET status = 'booked'
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $relId);
$stmt->execute();
$stmt->close();


$conn->close();

// Redirect depending on who triggered it
if ($isUser) {
    header("Location: my-reservations.php");
} else {
    header("Location: shop-dashboard.php#reservations");
}
exit;
