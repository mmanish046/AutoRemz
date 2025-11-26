<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Shop must be logged in
if (!isset($_SESSION['shop_id'])) {
    header('Location: shop-signin.html');
    exit;
}

$shopId = (int) $_SESSION['shop_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: shop-dashboard.php');
    exit;
}

// Collect POST data
$reservationId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
$action        = $_POST['action'] ?? '';
$finalTimeRaw  = trim($_POST['final_time'] ?? '');

if ($reservationId <= 0 || !in_array($action, ['accept', 'decline'], true)) {
    header('Location: shop-dashboard.php?resmsg=error');
    exit;
}

// Convert datetime-local to MySQL DATETIME
function to_mysql_datetime($input) {
    if ($input === '') return null;
    $input = str_replace('T', ' ', $input) . ':00'; // add seconds
    return $input;
}

$finalTime = $finalTimeRaw !== '' ? to_mysql_datetime($finalTimeRaw) : null;

// DB
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: your DB user
$password   = "HostingerDBpinetree90601@";   // TODO: your DB pass
$dbname     = "u138912455_autoremz_db";        // TODO: your DB name

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    header('Location: shop-dashboard.php?resmsg=error');
    exit;
}

// Make sure reservation belongs to this shop
$stmt = $conn->prepare("SELECT id FROM reservations WHERE id = ? AND shop_id = ? LIMIT 1");
$stmt->bind_param("ii", $reservationId, $shopId);
$stmt->execute();
$result = $stmt->get_result();
$exists = $result->fetch_assoc();
$stmt->close();

if (!$exists) {
    $conn->close();
    header('Location: shop-dashboard.php?resmsg=notfound');
    exit;
}

if ($action === 'accept') {
    // If no final time chosen, fall back to preferred_time1
    if ($finalTime === null) {
        $stmt = $conn->prepare("SELECT preferred_time1 FROM reservations WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $reservationId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res && !empty($res['preferred_time1'])) {
            $finalTime = $res['preferred_time1'];
        }
    }

    $status = 'accepted';
    $stmt = $conn->prepare("
        UPDATE reservations
        SET status = ?, final_time = ?
        WHERE id = ? AND shop_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ssii", $status, $finalTime, $reservationId, $shopId);
} else {
    // decline
    $status = 'declined';
    $stmt = $conn->prepare("
        UPDATE reservations
        SET status = ?, final_time = NULL
        WHERE id = ? AND shop_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("sii", $status, $reservationId, $shopId);
}

$ok = $stmt->execute();
$stmt->close();
$conn->close();

if ($ok) {
    header('Location: shop-dashboard.php?resmsg=ok');
} else {
    header('Location: shop-dashboard.php?resmsg=error');
}
exit;
