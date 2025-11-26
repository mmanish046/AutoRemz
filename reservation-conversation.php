<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check who is logged in
$isUser = isset($_SESSION['user_id']);
$isShop = isset($_SESSION['shop_id']);

if (!$isUser && !$isShop) {
    header("Location: signin.html");
    exit;
}

$userId = $isUser ? (int)$_SESSION['user_id'] : null;
$shopId = $isShop ? (int)$_SESSION['shop_id'] : null;

// Reservation ID from query
if (!isset($_GET['id'])) {
    die("Missing reservation ID.");
}
$reservationId = (int)$_GET['id'];

// DB connection
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

// -------------------------------------------------
// Fetch reservation + user + shop + optional custom quote details
// -------------------------------------------------
$sql = "
  SELECT r.*,
         u.name  AS user_name,
         u.email AS user_email,
         s.shop_name,
         s.city   AS shop_city,
         qr.details AS quote_details
  FROM reservations r
  JOIN users u ON r.user_id = u.id
  JOIN shops s ON r.shop_id = s.id
  LEFT JOIN quote_requests_shops qrs ON r.quote_rel_id = qrs.id
  LEFT JOIN quote_requests      qr   ON qrs.quote_id   = qr.id
  WHERE r.id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reservationId);
$stmt->execute();
$resData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resData) {
    $conn->close();
    die("Reservation not found.");
}

// Permission checks
if ($isUser && (int)$resData['user_id'] !== $userId) {
    $conn->close();
    die("Unauthorized (user).");
}
if ($isShop && (int)$resData['shop_id'] !== $shopId) {
    $conn->close();
    die("Unauthorized (shop).");
}

// -------------------------------------------------
// Handle sending a new message
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim($_POST['message'] ?? '');
    if ($msg !== '') {
        $senderType = $isUser ? 'user' : 'shop';
        $senderId   = $isUser ? $userId : $shopId;

        $insSql = "
          INSERT INTO reservation_messages
          (reservation_id, sender_type, sender_id, message, created_at)
          VALUES (?, ?, ?, ?, NOW())
        ";
        $stmt = $conn->prepare($insSql);
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        if (!$stmt->bind_param("isis", $reservationId, $senderType, $senderId, $msg)) {
            die("bind_param failed: " . $stmt->error);
        }
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        $stmt->close();

        // Update user's last seen message (for unread indicators)
        if ($isUser) {
            $lastSql = "
              SELECT id
              FROM reservation_messages
              WHERE reservation_id = ?
              ORDER BY id DESC
              LIMIT 1
            ";
            if ($stmt2 = $conn->prepare($lastSql)) {
                $stmt2->bind_param("i", $reservationId);
                $stmt2->execute();
                $stmt2->bind_result($lastId);
                if ($stmt2->fetch()) {
                    $stmt2->close();
                    $updSql = "UPDATE reservations SET user_last_seen_msg_id = ? WHERE id = ?";
                    if ($stmt3 = $conn->prepare($updSql)) {
                        $stmt3->bind_param("ii", $lastId, $reservationId);
                        $stmt3->execute();
                        $stmt3->close();
                    }
                } else {
                    $stmt2->close();
                }
            }
        }
    }

    header("Location: reservation-conversation.php?id=" . $reservationId);
    exit;
}

// -------------------------------------------------
// Fetch messages for initial render
// -------------------------------------------------
$msgSql = "
  SELECT sender_type, message, created_at
  FROM reservation_messages
  WHERE reservation_id = ?
  ORDER BY created_at ASC
";
$stmt = $conn->prepare($msgSql);
$stmt->bind_param("i", $reservationId);
$stmt->execute();
$msgResult = $stmt->get_result();

$messages = [];
while ($row = $msgResult->fetch_assoc()) {
    $messages[] = $row;
}
$stmt->close();
$conn->close();

function nice_dt($dt) {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if (!$ts) return '—';
    return date("M j, Y g:ia", $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reservation conversation – Autoremz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="styles.css">
  <style>
    .chat-box {
      background:#fff;
      border-radius:16px;
      border:1px solid #e5e7eb;
      padding:16px;
      display:flex;
      flex-direction:column;
      gap:12px;
      height:450px;
      overflow-y:auto;
    }
    .msg-user {
      align-self:flex-end;
      background:#ef4444;
      color:#ffffff;
      padding:10px 14px;
      border-radius:14px;
      max-width:60%;
    }
    .msg-shop {
      align-self:flex-start;
      background:#f3f4f6;
      color:#111827;
      padding:10px 14px;
      border-radius:14px;
      max-width:60%;
    }
    .msg-time {
      font-size:11px;
      color:#9ca3af;
      margin-top:2px;
    }
    .chat-send {
      margin-top:12px;
      display:flex;
      gap:8px;
    }
    .chat-send textarea {
      flex:1;
      padding:8px;
      border-radius:8px;
      border:1px solid #d1d5db;
      font-size:13px;
      resize:vertical;
      min-height:60px;
    }
    .btn-primary {
      background:#ef4444;
      color:#ffffff;
      border:none;
      padding:8px 14px;
      border-radius:8px;
      cursor:pointer;
      font-size:13px;
      font-weight:500;
    }
    .btn-primary:hover {
      background:#dc2626;
    }
    .res-summary {
      font-size:13px;
      color:#4b5563;
      margin-bottom:10px;
      padding:10px 12px;
      border-radius:12px;
      background:#f9fafb;
      border:1px solid #e5e7eb;
    }
    .res-summary strong {
      color:#111827;
    }
  </style>
</head>
<body>
<div class="page">
  <header>
    <div class="brand">
      <a href="index.php">
        <img src="brand_logo" alt="Autoremz logo" class="logo" />
      </a>
      <div class="brand-text">
        <strong>Autoremz – Reservation conversation</strong>
        <small>Discuss booking details</small>
      </div>
    </div>

    <div class="header-actions">
      <a href="<?php echo $isUser ? 'my-reservations.php' : 'shop-dashboard.php#reservations'; ?>" class="btn btn-ghost">
        Back
      </a>
      <a href="<?php echo $isUser ? 'user-logout.php' : 'shop-logout.php'; ?>" class="btn btn-ghost">
        Log out
      </a>
    </div>
  </header>

  <main class="dashboard-layout">
    <h1 class="dashboard-title">Conversation</h1>
    <p class="dashboard-subtitle">
      Customer:
      <strong><?php echo htmlspecialchars($resData['user_name']); ?></strong>
      (<?php echo htmlspecialchars($resData['user_email']); ?>)
      &nbsp;·&nbsp;
      Shop:
      <strong><?php echo htmlspecialchars($resData['shop_name']); ?></strong>
      <?php if (!empty($resData['shop_city'])): ?>
        &nbsp;·&nbsp; Location: <?php echo htmlspecialchars($resData['shop_city']); ?>
      <?php endif; ?>
    </p>

    <div class="dashboard-card">
      <div class="res-summary">
        <?php
          $statusLabel = ucfirst($resData['status'] ?? 'pending');
          $prefSlots = [];
          foreach (['preferred_time1', 'preferred_time2', 'preferred_time3'] as $field) {
              if (!empty($resData[$field])) {
                  $prefSlots[] = nice_dt($resData[$field]);
              }
          }
          // Consider this "from custom request" if either quote_details or quote_rel_id exists
          $fromCustom = !empty($resData['quote_details']) || !empty($resData['quote_rel_id']);
        ?>
        <strong>Reservation details:</strong><br>
        <?php if (!empty($resData['service_name'])): ?>
          Service: <?php echo htmlspecialchars($resData['service_name']); ?><br>
        <?php endif; ?>
        Status: <?php echo htmlspecialchars($statusLabel); ?><br>

        <?php if (!empty($resData['final_time'])): ?>
          Confirmed time: <?php echo nice_dt($resData['final_time']); ?><br>
        <?php elseif (!empty($prefSlots)): ?>
          Preferred time windows:<br>
          <?php foreach ($prefSlots as $slot): ?>
            • <?php echo htmlspecialchars($slot); ?><br>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($fromCustom): ?>
          <br>
          <strong>Notes from request:</strong><br>

          <?php if (!empty($resData['quote_details'])): ?>
            <?php echo nl2br(htmlspecialchars($resData['quote_details'])); ?><br>
          <?php endif; ?>

          <?php if (!empty($resData['message'])): ?>
            <?php if (!empty($resData['quote_details'])): ?>
              <br>
              <span style="font-size:12px; color:#4b5563;">
                Additional note when booking:
              </span><br>
            <?php endif; ?>
            <?php echo nl2br(htmlspecialchars($resData['message'])); ?><br>
          <?php endif; ?>

          <br>
          <span style="font-size:12px; color:#6b7280;">
            Created from custom quote request.
          </span><br>

        <?php elseif (!empty($resData['message'])): ?>
          <br>
          <strong>Notes from request:</strong><br>
          <?php echo nl2br(htmlspecialchars($resData['message'])); ?><br>
        <?php endif; ?>

        <br>
        <span style="font-size:11px; color:#6b7280;">
          Created: <?php echo nice_dt($resData['created_at']); ?>
        </span>
      </div>

      <div class="chat-box" id="chat-messages">
        <?php if (empty($messages)): ?>
          <p style="font-size:13px; color:#9ca3af;">
            No messages yet. Start the conversation below.
          </p>
        <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <?php if ($m['sender_type'] === 'user'): ?>
              <div class="msg-user">
                <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                <div class="msg-time"><?php echo nice_dt($m['created_at']); ?></div>
              </div>
            <?php else: ?>
              <div class="msg-shop">
                <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                <div class="msg-time"><?php echo nice_dt($m['created_at']); ?></div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <form class="chat-send" method="POST">
        <textarea name="message" placeholder="Write a message…"></textarea>
        <button type="submit" class="btn-primary">Send</button>
      </form>
    </div>
  </main>
</div>

<script>
  function refreshMessages() {
    const container = document.getElementById('chat-messages');
    const reservationId = <?php echo (int)$reservationId; ?>;
    fetch("fetch-messages.php?id=" + reservationId)
      .then(res => res.text())
      .then(html => {
        container.innerHTML = html;
      });
  }

  setInterval(refreshMessages, 3000);
</script>
</body>
</html>
