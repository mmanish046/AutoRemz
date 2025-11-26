<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Must be logged in as a user
if (!isset($_SESSION['user_id'])) {
    header("Location: signin.html");
    exit;
}

$userId = (int)$_SESSION['user_id'];

// DB settings
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// --------------------
// Validate input
// --------------------
$relId     = isset($_POST['rel_id']) ? (int)$_POST['rel_id'] : 0;
$finalTime = trim($_POST['final_time'] ?? '');

if ($relId <= 0 || $finalTime === '') {
    $conn->close();
    header("Location: my-quotes.php?bookmsg=error");
    exit;
}

// --------------------
// Load relation + quote
// --------------------
$sql = "
  SELECT 
    qrs.id          AS rel_id,
    qrs.quote_id,
    qrs.shop_id,
    qrs.status      AS rel_status,
    q.title,
    q.details,
    q.city,
    q.status        AS quote_status,
    q.user_id
  FROM quote_requests_shops qrs
  JOIN quote_requests q ON qrs.quote_id = q.id
  WHERE qrs.id = ? AND q.user_id = ?
  LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("ii", $relId, $userId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    $conn->close();
    header("Location: my-quotes.php?bookmsg=notfound");
    exit;
}

// If already booked, don't double-book
if ($data['quote_status'] === 'booked') {
    $conn->close();
    header("Location: my-reservations.php?bookmsg=already");
    exit;
}

$quoteId = (int)$data['quote_id'];
$shopId  = (int)$data['shop_id'];
$title   = $data['title']   ?? 'Custom repair';
$details = $data['details'] ?? '';

// --------------------
// Create reservation
// --------------------
$serviceName = $title;
$status      = 'accepted';  // treat as confirmed reservation

// normalize datetime
$ts = strtotime($finalTime);
$finalTimeDb = $ts ? date('Y-m-d H:i:s', $ts) : null;

$ins = $conn->prepare("
  INSERT INTO reservations
    (user_id, shop_id, service_name,
     preferred_time1, preferred_time2, preferred_time3,
     message, status, final_time, created_at, quote_rel_id)
  VALUES
    (?, ?, ?, ?, NULL, NULL,
     ?, ?, ?, NOW(), ?)
");
if (!$ins) {
    die("Prepare failed (insert reservation): " . $conn->error);
}

$preferred1 = $finalTimeDb;         // we reuse final time as the chosen slot
$msg        = "Created from custom quote request.";
$ins->bind_param(
    "iisssssi",
    $userId,
    $shopId,
    $serviceName,
    $preferred1,
    $msg,
    $status,
    $finalTimeDb,
    $relId
);
$ok = $ins->execute();
$reservationId = $ins->insert_id;
$ins->close();

if (!$ok) {
    $conn->close();
    header("Location: my-quotes.php?bookmsg=error");
    exit;
}

// --------------------
// Mark quote + relation as booked
// --------------------
$up1 = $conn->prepare("UPDATE quote_requests SET status = 'booked' WHERE id = ? LIMIT 1");
$up1->bind_param("i", $quoteId);
$up1->execute();
$up1->close();

$up2 = $conn->prepare("
  UPDATE quote_requests_shops
  SET status = 'booked'
  WHERE id = ? AND shop_id = ?
  LIMIT 1
");
$up2->bind_param("ii", $relId, $shopId);
$up2->execute();
$up2->close();

$conn->close();

// Done – go to reservations
header("Location: my-reservations.php?bookmsg=ok");
exit;
