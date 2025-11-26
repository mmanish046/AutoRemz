<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Who is logged in?
$isUser = isset($_SESSION['user_id']);
$isShop = isset($_SESSION['shop_id']);

if (!$isUser && !$isShop) {
    header("Location: signin.html");
    exit;
}

$userId = $isUser ? (int)$_SESSION['user_id'] : null;
$shopId = $isShop ? (int)$_SESSION['shop_id'] : null;

// DB settings
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}

// ---------------------
// Load quote + relation
// ---------------------
$relId = isset($_GET['rel_id']) ? (int)$_GET['rel_id'] : 0;

$sql = "
  SELECT
    qrs.id            AS rel_id,
    qrs.status        AS rel_status,
    qrs.created_at    AS rel_created_at,
    q.id              AS quote_id,
    q.title,
    q.details,
    q.keywords,
    q.city,
    q.status          AS quote_status,
    q.created_at      AS quote_created_at,
    u.id              AS user_id,
    u.name            AS user_name,
    u.email           AS user_email,
    s.id              AS shop_id,
    s.shop_name
  FROM quote_requests_shops qrs
  JOIN quote_requests q ON qrs.quote_id = q.id
  JOIN users u          ON q.user_id   = u.id
  JOIN shops s          ON qrs.shop_id = s.id
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

// Auth: ensure this user/shop belongs to this quote relation
if ($isUser && (int)$relData['user_id'] !== $userId) {
    $conn->close();
    die("Unauthorized (user).");
}
if ($isShop && (int)$relData['shop_id'] !== $shopId) {
    $conn->close();
    die("Unauthorized (shop).");
}

// ---------------------
// Handle sending message
// ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim($_POST['message'] ?? '');

    if ($msg !== '') {
        $senderType = $isUser ? 'user' : 'shop';
        $senderId   = $isUser ? $userId : $shopId;

        $insSql = "
          INSERT INTO quote_messages
          (quote_rel_id, sender_type, sender_id, message, created_at)
          VALUES (?, ?, ?, ?, NOW())
        ";
        $stmt = $conn->prepare($insSql);
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        if (!$stmt->bind_param("isis", $relId, $senderType, $senderId, $msg)) {
            die("bind_param failed: " . $stmt->error);
        }
        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }
        $stmt->close();
    }

    header("Location: quote-conversation.php?rel_id=" . $relId);
    $conn->close();
    exit;
}

// ---------------------
// Fetch existing messages
// ---------------------
$msgSql = "
  SELECT id, sender_type, sender_id, message, created_at
  FROM quote_messages
  WHERE quote_rel_id = ?
  ORDER BY created_at ASC
";
$stmt = $conn->prepare($msgSql);
$stmt->bind_param("i", $relId);
$stmt->execute();
$msgResult = $stmt->get_result();

$messages = [];
while ($row = $msgResult->fetch_assoc()) {
    $messages[] = $row;
}
$stmt->close();

// Mark all messages as seen for this user (only when user is viewing)
if ($isUser && !empty($messages)) {
    $lastMsgId = (int) end($messages)['id'];

    $seenSql = "
      UPDATE quote_requests_shops qrs
      JOIN quote_requests q ON qrs.quote_id = q.id
      SET qrs.user_last_seen_msg_id = ?
      WHERE qrs.id = ? AND q.user_id = ?
    ";
    if ($seenStmt = $conn->prepare($seenSql)) {
        $seenStmt->bind_param("iii", $lastMsgId, $relId, $userId);
        $seenStmt->execute();
        $seenStmt->close();
    }
}
// ---------------------
// Optional: linked reservation (if this quote was booked)
// ---------------------
$reservationData = null;

$resSql = "
  SELECT *
  FROM reservations
  WHERE quote_rel_id = ?
  LIMIT 1
";
if ($stmtR = $conn->prepare($resSql)) {
    $stmtR->bind_param("i", $relId);
    $stmtR->execute();
    $res = $stmtR->get_result();
    if ($row = $res->fetch_assoc()) {
        $reservationData = $row;
    }
    $stmtR->close();
}

$conn->close();


function nice_dt($dt) {
    $ts = strtotime($dt);
    if (!$ts) return "—";
    return date("M j, Y g:ia", $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Custom quote conversation – Autoremz</title>
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
      color:white;
      padding:10px 14px;
      border-radius:14px;
      max-width:60%;
    }
    .msg-shop {
      align-self:flex-start;
      background:#f3f4f6;
      color:#374151;
      padding:10px 14px;
      border-radius:14px;
      max-width:60%;
      border:1px solid #e5e7eb;
    }
    .msg-time {
      font-size:11px;
      color:#6b7280;
      margin-top:2px;
    }
    .chat-send {
      margin-top:12px;
      display:flex;
      gap:8px;
    }
    .chat-send textarea {
      flex:1;
      border-radius:10px;
      border:1px solid #d1d5db;
      padding:10px;
      font-size:14px;
      resize:vertical;
    }
    .quote-summary {
      font-size:13px;
      color:#4b5563;
      margin-bottom:10px;
      padding:10px 12px;
      border-radius:12px;
      background:#f9fafb;
      border:1px solid #e5e7eb;
    }
    .quote-summary strong {
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
          <strong>Autoremz – Custom quote conversation</strong>
          <small>Discuss details before booking</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="<?php echo $isShop ? 'shop-dashboard.php#custom-quotes' : 'my-quotes.php'; ?>" class="btn btn-ghost">
          Back
        </a>
        <a href="<?php echo $isShop ? 'shop-logout.php' : 'index.php'; ?>" class="btn btn-ghost">
          Log out
        </a>
      </div>
    </header>

    <main class="dashboard-layout">
      <h1 class="dashboard-title">
        <?php echo htmlspecialchars($relData['title']); ?>
      </h1>
      <p class="dashboard-subtitle">
        Customer: <strong><?php echo htmlspecialchars($relData['user_name']); ?></strong>
        (<?php echo htmlspecialchars($relData['user_email']); ?>)
        &nbsp;·&nbsp;
        Shop: <strong><?php echo htmlspecialchars($relData['shop_name']); ?></strong>
        <?php if (!empty($relData['city'])): ?>
          &nbsp;·&nbsp; Location: <?php echo htmlspecialchars($relData['city']); ?>
        <?php endif; ?>
      </p>

 <?php if (!empty($reservationData)): ?>
  <div class="quote-summary" style="margin-top:8px;">
    <?php
      $statusLabel = ucfirst($reservationData['status'] ?? 'pending');
      $prefSlots = [];
      foreach (['preferred_time1', 'preferred_time2', 'preferred_time3'] as $field) {
          if (!empty($reservationData[$field])) {
              $prefSlots[] = nice_dt($reservationData[$field]);
          }
      }
      // This reservation is by definition from this custom quote
      $fromCustom = true;
    ?>
    <strong>Reservation details:</strong><br>
    <?php if (!empty($reservationData['service_name'])): ?>
      Service: <?php echo htmlspecialchars($reservationData['service_name']); ?><br>
    <?php endif; ?>
    Status: <?php echo htmlspecialchars($statusLabel); ?><br>

    <?php if (!empty($reservationData['final_time'])): ?>
      Confirmed time: <?php echo nice_dt($reservationData['final_time']); ?><br>
    <?php elseif (!empty($prefSlots)): ?>
      Preferred time windows:<br>
      <?php foreach ($prefSlots as $slot): ?>
        • <?php echo htmlspecialchars($slot); ?><br>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($fromCustom): ?>
      <br>
      <strong>Notes from request:</strong><br>

      <?php if (!empty($relData['details'])): ?>
        <?php echo nl2br(htmlspecialchars($relData['details'])); ?><br>
      <?php endif; ?>

      <?php if (!empty($reservationData['message'])): ?>
        <?php if (!empty($relData['details'])): ?>
          <br>
          <span style="font-size:12px; color:#4b5563;">
            Additional note when booking:
          </span><br>
        <?php endif; ?>
        <?php echo nl2br(htmlspecialchars($reservationData['message'])); ?><br>
      <?php endif; ?>

      <br>
  
    <?php endif; ?>

    <br>
    <span style="font-size:11px; color:#6b7280;">
      Created: <?php echo nice_dt($reservationData['created_at']); ?>
    </span>
  </div>
<?php endif; ?>



<div class="chat-box" id="chat-messages">
  <!-- Messages are loaded here via AJAX -->
</div>

        <form class="chat-send" method="POST">
          <textarea name="message" placeholder="Write a message…"></textarea>
          <button type="submit" class="btn btn-primary">Send</button>
        </form>
        
<?php if ($isUser && $relData['quote_status'] !== 'booked'): ?>
  <div style="margin-top:16px; border-top:1px dashed #e5e7eb; padding-top:10px;">
    <h3 style="font-size:14px; margin:0 0 6px;">Schedule this repair with this shop</h3>
    <p style="font-size:12px; color:#6b7280; margin:0 0 8px;">
      Once you confirm a time, this request will be marked as booked with this shop
      and a reservation will be created. You’ll still be able to continue this conversation.
    </p>

    <form method="POST" action="quote-book.php" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
      <input type="hidden" name="rel_id" value="<?php echo (int)$relId; ?>">

      <label style="font-size:12px; color:#374151; display:flex; flex-direction:column; gap:4px;">
        Final date &amp; time
        <input
          type="datetime-local"
          name="final_time"
          required
          style="border-radius:10px; border:1px solid #d1d5db; padding:6px 8px; font-size:13px;"
        >
      </label>

      <button class="btn btn-primary" style="font-size:12px; padding:6px 14px;">
        Confirm &amp; create reservation
      </button>
    </form>
  </div>
<?php endif; ?>




      </div>
    </main>
  </div>
  
  
<script>
function refreshMessages() {
    const container = document.getElementById('chat-messages');
    const relId = <?php echo (int)$relId; ?>;

    fetch("fetch-quote-messages.php?rel_id=" + relId)
        .then(res => res.text())
        .then(html => {
            container.innerHTML = html;
        });
}

// load once right away
refreshMessages();

// then keep refreshing every 3 seconds
setInterval(refreshMessages, 3000);
</script>


</body>


</html>
