<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Require user to be logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: signin.html");
    exit;
}

$userId   = (int) $_SESSION["user_id"];
$userName = $_SESSION["user_name"] ?? "";

// DATABASE SETTINGS
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// ------------------------------------------------------
// Handle archive / complete actions (POST)
// ------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action        = $_POST['action'] ?? '';
    $reservationId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;

    if ($reservationId > 0 && in_array($action, ['archive', 'complete'], true)) {

        if ($action === 'archive') {
            $sql = "UPDATE reservations SET is_archived = 1 WHERE id = ? AND user_id = ?";
        } else { // complete
            $sql = "UPDATE reservations SET status = 'completed' WHERE id = ? AND user_id = ?";
        }

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ii", $reservationId, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Avoid form resubmission on refresh
    header("Location: my-reservations.php");
    exit;
}

// ------------------------------------------------------
// Header notification counts
// ------------------------------------------------------
$resAlertCount   = 0;
$quoteAlertCount = 0;

// Reservations needing attention: pending or accepted (not archived, not completed)
$cntSql = "
  SELECT COUNT(*) AS c
  FROM reservations
  WHERE user_id = ?
    AND is_archived = 0
    AND status IN ('pending','accepted')
";
if ($stmt = $conn->prepare($cntSql)) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($c);
    if ($stmt->fetch()) {
        $resAlertCount = (int)$c;
    }
    $stmt->close();
}

// Custom requests that are still open or booked
$cntSql = "
  SELECT COUNT(*) AS c
  FROM quote_requests
  WHERE user_id = ?
    AND status IN ('open','booked')
";
if ($stmt = $conn->prepare($cntSql)) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($c);
    if ($stmt->fetch()) {
        $quoteAlertCount = (int)$c;
    }
    $stmt->close();
}

// ------------------------------------------------------
// Fetch reservations for this user (with shop + quote rel)
// ------------------------------------------------------
$reservations = [];

$sql = "
  SELECT r.*,
         s.shop_name,
         s.city,
         s.phone,
         qrs.user_last_seen_msg_id AS quote_user_last_seen_msg_id
  FROM reservations r
  JOIN shops s ON r.shop_id = s.id
  LEFT JOIN quote_requests_shops qrs
    ON r.quote_rel_id = qrs.id
  WHERE r.user_id = ?
  ORDER BY r.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reservations[] = $row;
}
$stmt->close();

// ------------------------------------------------------
// Split into Active / Completed / Archived
// + compute unread map per reservation
// ------------------------------------------------------
$activeReservations    = [];
$completedReservations = [];
$archivedReservations  = [];

$unreadByReservationId = []; // reservation_id => true

foreach ($reservations as $row) {
    $reservationId = (int)$row['id'];
    $status        = $row['status'] ?? 'pending';
    $isArchived    = !empty($row['is_archived']);

    // bucket classification
    if ($isArchived) {
        $archivedReservations[] = $row;
    } elseif ($status === 'completed') {
        $completedReservations[] = $row;
    } else {
        $activeReservations[] = $row;
    }

    // Only compute unread state for Active reservations
    if ($isArchived || $status === 'completed') {
        continue;
    }

    // From a custom quote? (quote_rel_id)
    if (!empty($row['quote_rel_id'])) {
        $relId  = (int)$row['quote_rel_id'];
        $seenId = $row['quote_user_last_seen_msg_id'] ?? null;

        $sqlLast = "
          SELECT id, sender_type
          FROM quote_messages
          WHERE quote_rel_id = ?
          ORDER BY id DESC
          LIMIT 1
        ";
        if ($stmt2 = $conn->prepare($sqlLast)) {
            $stmt2->bind_param("i", $relId);
            $stmt2->execute();
            $stmt2->bind_result($lastId, $senderType);
            if ($stmt2->fetch()) {
                if ($senderType === 'shop' &&
                    ($seenId === null || (int)$lastId > (int)$seenId)) {
                    $unreadByReservationId[$reservationId] = true;
                }
            }
            $stmt2->close();
        }
    } else {
        // Normal reservation: use reservation_messages + reservations.user_last_seen_msg_id
        $seenId = $row['user_last_seen_msg_id'] ?? null;

        $sqlLast = "
          SELECT id, sender_type
          FROM reservation_messages
          WHERE reservation_id = ?
          ORDER BY id DESC
          LIMIT 1
        ";
        if ($stmt2 = $conn->prepare($sqlLast)) {
            $stmt2->bind_param("i", $reservationId);
            $stmt2->execute();
            $stmt2->bind_result($lastId, $senderType);
            if ($stmt2->fetch()) {
                if ($senderType === 'shop' &&
                    ($seenId === null || (int)$lastId > (int)$seenId)) {
                    $unreadByReservationId[$reservationId] = true;
                }
            }
            $stmt2->close();
        }
    }
}

$conn->close();

// Helper to format date/time nicely
function nice_datetime($dt) {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if (!$ts) return '—';
    return date("M j, Y g:ia", $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>My reservations – Autoremz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="styles.css">
  <style>
    body {
      background:#F3F4F6;
    }

    .dashboard-shell {
      max-width: 1100px;
      margin: 0 auto;
      padding: 16px 12px 32px;
    }

    .dashboard-header-text {
      font-size: 24px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 4px;
    }

    .dashboard-header-subtitle {
      font-size: 14px;
      color: #6B7280;
      margin-bottom: 16px;
    }

    .dashboard-card-light {
      background: #FFFFFF;
      border-radius: 12px;
      padding: 16px 18px 14px;
      box-shadow: 0 1px 4px rgba(15,23,42,0.06);
      border: 1px solid #E5E7EB;
      margin-bottom: 14px;
    }

    .section-header {
      display:flex;
      justify-content:space-between;
      align-items:center;
      margin-bottom:8px;
    }

    .section-title {
      font-size:16px;
      font-weight:600;
      color:#111827;
    }

    .section-pill {
      font-size:11px;
      color:#6B7280;
      padding:2px 8px;
      border-radius:999px;
      background:#F3F4F6;
      border:1px solid #E5E7EB;
    }

    .reservation-list {
      display:flex;
      flex-direction:column;
      gap:8px;
    }

    .reservation-card {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      padding:10px 10px;
      border-radius:10px;
      border:1px solid #E5E7EB;
      background:#F9FAFB;
    }

    .reservation-main {
      flex:1;
      display:flex;
      flex-direction:column;
      gap:4px;
    }

    .reservation-title {
      font-size:13px;
      font-weight:600;
      color:#111827;
    }

    .reservation-meta {
      font-size:11px;
      color:#6B7280;
      display:flex;
      flex-wrap:wrap;
      gap:6px;
    }

    .status-badge {
      display:inline-flex;
      align-items:center;
      padding:2px 8px;
      border-radius:999px;
      font-size:11px;
      font-weight:500;
    }

    .reservation-actions {
      display:flex;
      flex-direction:column;
      gap:6px;
      align-items:flex-end;
      justify-content:center;
      min-width:190px;
    }

    .btn-primary {
      background:#EF4444;
      color:#FFFFFF !important;
      padding:6px 12px;
      border-radius:8px;
      font-size:12px;
      font-weight:500;
      text-decoration:none;
      border:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      transition:background 0.15s ease;
    }

    .btn-primary:hover {
      background:#DC2626;
    }

    .btn-ghost {
      color:#374151 !important;
      padding:5px 10px;
      border-radius:6px;
      text-decoration:none;
      border:none;
      background:transparent;
      cursor:pointer;
      font-size:11px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      transition:background 0.15s ease;
    }

    .btn-ghost:hover {
      background:#F3F4F6;
    }

    .btn-ghost-danger {
      color:#B91C1C !important;
    }

    .badge-tag {
      font-size:11px;
      color:#6B7280;
      padding:2px 6px;
      border-radius:999px;
      background:#E5E7EB;
    }

    .list-empty-text {
      font-size:13px;
      color:#6B7280;
      margin-top:4px;
    }

    .nav-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-left: 6px;
      min-width: 18px;
      padding: 0 6px;
      border-radius: 999px;
      background: #EF4444;
      color: #ffffff;
      font-size: 11px;
      font-weight: 600;
      line-height: 1.4;
    }

    /* Unread chat indicator for Open conversation button */
    .reservation-unread-wrapper {
      position: relative;
      display: inline-block;
    }


/* Tiny chat icon unread indicator */
.reservation-unread-icon {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 16px;
  height: 16px;
  background: #f97316;         /* orange background */
  border-radius: 999px;
  border: 2px solid #ffffff;   /* white halo to pop against any color */
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  box-shadow: 0 2px 4px rgba(0,0,0,0.12);
}

    /* Simple collapsible for Completed & Archived */
    .collapsible-toggle {
      font-size:18px;
      line-height:1;
      color:#9CA3AF;
      cursor:pointer;
      user-select:none;
      padding-left:6px;
    }

    .collapsible-body {
      margin-top:6px;
    }

    .collapsed .collapsible-body {
      display:none;
    }

    @media (max-width: 800px) {
      .reservation-card {
        flex-direction:column;
      }
      .reservation-actions {
        align-items:flex-start;
        min-width:0;
      }
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
          <strong>Autoremz – My reservations</strong>
          <small>View and manage your bookings</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="index.php" class="btn-ghost">Home</a>

        <a href="my-quotes.php" class="btn-ghost">
          My custom requests
          <?php if (!empty($quoteAlertCount)): ?>
            <span class="nav-badge"><?php echo $quoteAlertCount; ?></span>
          <?php endif; ?>
        </a>

        <a href="user-dashboard.php" class="btn-ghost">
          Dashboard
        </a>

        <a href="user-logout.php" class="btn-ghost">Log out</a>
      </div>
    </header>

    <main class="dashboard-shell">
      <h1 class="dashboard-header-text">
        Your reservations<?php echo $userName ? ', ' . htmlspecialchars($userName) : ''; ?>
      </h1>
      <p class="dashboard-header-subtitle">
        Track your active bookings, completed work, and archived history.
      </p>

      <!-- ACTIVE RESERVATIONS -->
      <section class="dashboard-card-light">
        <div class="section-header">
          <div class="section-title">Active reservations</div>
          <span class="section-pill">
            <?php echo count($activeReservations); ?> active
          </span>
        </div>

        <?php if (empty($activeReservations)): ?>
          <p class="list-empty-text">
            No active reservations right now. Once you schedule or accept a repair, it will appear here.
          </p>
        <?php else: ?>
          <div class="reservation-list">
            <?php foreach ($activeReservations as $res): ?>
              <?php
                $status = $res['status'] ?? 'pending';
                $badgeText = ucfirst($status);
                $badgeBg   = '#FEF3C7';
                $badgeCol  = '#92400E';

                if ($status === 'accepted') {
                  $badgeText = 'Accepted';
                  $badgeBg   = '#ECFDF3';
                  $badgeCol  = '#166534';
                } elseif ($status === 'declined') {
                  $badgeText = 'Declined';
                  $badgeBg   = '#FEE2E2';
                  $badgeCol  = '#B91C1C';
                } elseif ($status === 'cancelled') {
                  $badgeText = 'Cancelled';
                  $badgeBg   = '#F9FAFB';
                  $badgeCol  = '#6B7280';
                } elseif ($status === 'pending') {
                  $badgeText = 'Pending';
                }

                $hasUnread = !empty($unreadByReservationId[(int)$res['id']]);
              ?>
              <div class="reservation-card">
                <div class="reservation-main">
                  <div class="reservation-title">
                    <?php echo htmlspecialchars($res['shop_name']); ?>
                  </div>
                  <div class="reservation-meta">
                    <?php if (!empty($res['city'])): ?>
                      <span><?php echo htmlspecialchars($res['city']); ?></span>
                    <?php endif; ?>
                    <span>Created: <?php echo nice_datetime($res['created_at']); ?></span>
                    <?php if (!empty($res['final_time'])): ?>
                      <span>Confirmed: <?php echo nice_datetime($res['final_time']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($res['quote_rel_id'])): ?>
                      <span class="badge-tag">From custom request</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="reservation-actions">
                  <span class="status-badge" style="background:<?php echo $badgeBg; ?>; color:<?php echo $badgeCol; ?>;">
                    <?php echo $badgeText; ?>
                  </span>

                  <!-- Conversation button with unread dot -->
                  <?php if (!empty($res['quote_rel_id'])): ?>
                    <div class="reservation-unread-wrapper">
                      <a href="quote-conversation.php?rel_id=<?php echo (int)$res['quote_rel_id']; ?>"
                         class="btn-primary">
                        Open conversation
                      </a>
<?php if ($hasUnread): ?>
    <span class="reservation-unread-icon">
      <svg viewBox="0 0 20 20" fill="currentColor" width="10" height="10">
        <path d="M18 10c0 3.866-3.582 7-8 7-1.042 0-2.044-.154-2.98-.44L3 17l.51-3.06C2.565 12.83 2 11.464 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7z"/>
      </svg>
    </span>
<?php endif; ?>

                    </div>
                  <?php else: ?>
                    <div class="reservation-unread-wrapper">
                      <a href="reservation-conversation.php?id=<?php echo (int)$res['id']; ?>"
                         class="btn-primary">
                        Open conversation
                      </a>
                      <?php if ($hasUnread): ?>
                        <span class="reservation-unread-dot" title="New messages from this shop"></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <!-- Actions: archive / complete -->
                  <div style="display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                    <form method="POST" action="my-reservations.php" style="margin:0;">
                      <input type="hidden" name="action" value="complete" />
                      <input type="hidden" name="reservation_id" value="<?php echo (int)$res['id']; ?>" />
                      <button type="submit" class="btn-ghost">
                        Mark as completed
                      </button>
                    </form>

                    <form method="POST" action="my-reservations.php" style="margin:0;"
                          onsubmit="return confirm('Archive this reservation? You will no longer access the conversation from here.');">
                      <input type="hidden" name="action" value="archive" />
                      <input type="hidden" name="reservation_id" value="<?php echo (int)$res['id']; ?>" />
                      <button type="submit" class="btn-ghost btn-ghost-danger">
                        Archive this reservation
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- COMPLETED RESERVATIONS (collapsible) -->
      <section class="dashboard-card-light collapsed" id="completed-section">
        <div class="section-header">
          <div class="section-title">Completed reservations</div>
          <div style="display:flex; align-items:center; gap:6px;">
            <span class="section-pill">
              <?php echo count($completedReservations); ?> completed
            </span>
            <span class="collapsible-toggle" onclick="toggleSection('completed-section')">+</span>
          </div>
        </div>

        <div class="collapsible-body">
          <?php if (empty($completedReservations)): ?>
            <p class="list-empty-text">
              Once you mark a reservation as completed, it will appear here.
            </p>
          <?php else: ?>
            <div class="reservation-list">
              <?php foreach ($completedReservations as $res): ?>
                <div class="reservation-card">
                  <div class="reservation-main">
                    <div class="reservation-title">
                      <?php echo htmlspecialchars($res['shop_name']); ?>
                    </div>
                    <div class="reservation-meta">
                      <?php if (!empty($res['city'])): ?>
                        <span><?php echo htmlspecialchars($res['city']); ?></span>
                      <?php endif; ?>
                      <span>Created: <?php echo nice_datetime($res['created_at']); ?></span>
                      <?php if (!empty($res['final_time'])): ?>
                        <span>Completed around: <?php echo nice_datetime($res['final_time']); ?></span>
                      <?php endif; ?>
                      <?php if (!empty($res['quote_rel_id'])): ?>
                        <span class="badge-tag">From custom request</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="reservation-actions">
                    <span class="status-badge" style="background:#EEF2FF; color:#4F46E5;">
                      Completed
                    </span>
                    <span style="font-size:11px; color:#9CA3AF;">
                      Conversation closed
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- ARCHIVED RESERVATIONS (collapsible) -->
      <section class="dashboard-card-light collapsed" id="archived-section">
        <div class="section-header">
          <div class="section-title">Archived reservations</div>
          <div style="display:flex; align-items:center; gap:6px;">
            <span class="section-pill">
              <?php echo count($archivedReservations); ?> archived
            </span>
            <span class="collapsible-toggle" onclick="toggleSection('archived-section')">+</span>
          </div>
        </div>

        <div class="collapsible-body">
          <?php if (empty($archivedReservations)): ?>
            <p class="list-empty-text">
              Archive older reservations here to keep your active list clean.
            </p>
          <?php else: ?>
            <div class="reservation-list">
              <?php foreach ($archivedReservations as $res): ?>
                <div class="reservation-card">
                  <div class="reservation-main">
                    <div class="reservation-title">
                      <?php echo htmlspecialchars($res['shop_name']); ?>
                    </div>
                    <div class="reservation-meta">
                      <?php if (!empty($res['city'])): ?>
                        <span><?php echo htmlspecialchars($res['city']); ?></span>
                      <?php endif; ?>
                      <span>Created: <?php echo nice_datetime($res['created_at']); ?></span>
                      <?php if (!empty($res['final_time'])): ?>
                        <span>Last confirmed: <?php echo nice_datetime($res['final_time']); ?></span>
                      <?php endif; ?>
                      <?php if (!empty($res['quote_rel_id'])): ?>
                        <span class="badge-tag">From custom request</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="reservation-actions">
                    <span class="status-badge" style="background:#F9FAFB; color:#6B7280;">
                      Archived
                    </span>
                    <span style="font-size:11px; color:#9CA3AF;">
                      Conversation closed
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <script>
    function toggleSection(id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.classList.toggle('collapsed');
      var toggle = el.querySelector('.collapsible-toggle');
      if (toggle) {
        toggle.textContent = el.classList.contains('collapsed') ? '+' : '−';
      }
    }
  </script>
</body>
</html>
