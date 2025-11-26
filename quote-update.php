<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Must be logged in as a shop
if (!isset($_SESSION['shop_id'])) {
    header('Location: shop-signin.php');
    exit;
}

$shopId = (int) $_SESSION['shop_id'];

// DB settings
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// ----------------------
// Validate input
// ----------------------
$relId  = isset($_POST['rel_id']) ? (int)$_POST['rel_id'] : 0;
$action = $_POST['action'] ?? '';

if ($relId <= 0 || !in_array($action, ['accept', 'decline'], true)) {
    $conn->close();
    header('Location: shop-dashboard.php?qmsg=error');
    exit;
}

// ----------------------
// Load relation + quote
// ----------------------
$sql = "
  SELECT 
    qrs.id,
    qrs.quote_id,
    qrs.status AS rel_status,
    q.status   AS quote_status
  FROM quote_requests_shops qrs
  JOIN quote_requests q ON qrs.quote_id = q.id
  WHERE qrs.id = ? AND qrs.shop_id = ?
  LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("ii", $relId, $shopId);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    $conn->close();
    header('Location: shop-dashboard.php?qmsg=notfound');
    exit;
}

$quoteId     = (int)$res['quote_id'];
$relStatus   = $res['rel_status']   ?? 'pending';
$quoteStatus = $res['quote_status'] ?? 'open';

// If the quote is already booked (by someone else or earlier),
// do not allow further updates from this shop
if ($quoteStatus === 'booked' && $relStatus !== 'booked') {
    $conn->close();
    header('Location: shop-dashboard.php?qmsg=alreadybooked');
    exit;
}

// ----------------------
// Update relation status
// ----------------------
if ($action === 'accept') {
    $newStatus = 'accepted';
} elseif ($action === 'decline') {
    $newStatus = 'declined';
} else {
    // Should never hit here due to earlier in_array check
    $conn->close();
    header('Location: shop-dashboard.php?qmsg=error');
    exit;
}

$upd = $conn->prepare("
  UPDATE quote_requests_shops
  SET status = ?
  WHERE id = ? AND shop_id = ?
  LIMIT 1
");
if (!$upd) {
    die("Prepare failed (update): " . $conn->error);
}
$upd->bind_param("sii", $newStatus, $relId, $shopId);
$ok = $upd->execute();
$upd->close();

$conn->close();

if (!$ok) {
    header('Location: shop-dashboard.php?qmsg=error');
    exit;
}

// Success
header('Location: shop-dashboard.php?qmsg=ok#custom-quotes');
exit;
