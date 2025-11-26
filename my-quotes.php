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

// DB settings
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// --- Notification counts for header badges ---
$resAlertCount   = 0;

// Reservations needing attention: pending or accepted and not archived
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

// -------------------
// Fetch all quotes
// -------------------
$quotes = [];

$qSql = "
  SELECT *
  FROM quote_requests
  WHERE user_id = ?
  ORDER BY created_at DESC
";
$stmt = $conn->prepare($qSql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $quotes[] = $row;
}
$stmt->close();

// -------------------
// Fetch shop relations
// -------------------
$shopsByQuoteId = [];

if (!empty($quotes)) {
    $relSql = "
      SELECT
        q.id              AS quote_id,
        qrs.id            AS rel_id,
        qrs.status        AS rel_status,
        qrs.created_at    AS rel_created_at,
        s.shop_name,
        s.city
      FROM quote_requests_shops qrs
      JOIN quote_requests q ON qrs.quote_id = q.id
      JOIN shops s          ON qrs.shop_id = s.id
      WHERE q.user_id = ?
      ORDER BY q.id ASC, qrs.created_at ASC
    ";
    $stmt = $conn->prepare($relSql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $relRes = $stmt->get_result();

    while ($row = $relRes->fetch_assoc()) {
        $qid = (int)$row['quote_id'];
        if (!isset($shopsByQuoteId[$qid])) {
            $shopsByQuoteId[$qid] = [];
        }
        $shopsByQuoteId[$qid][] = $row;
    }

    $stmt->close();
}

// -----------------------------
// Unread indicator: last sender
// -----------------------------
$lastSenderByRelId = [];

$relIds = [];
foreach ($shopsByQuoteId as $qid => $rels) {
    foreach ($rels as $sr) {
        $relIds[] = (int)$sr['rel_id'];
    }
}

if (!empty($relIds)) {
    // Build placeholders ?,?,?... for IN ()
    $placeholders = implode(',', array_fill(0, count($relIds), '?'));
    $types = str_repeat('i', count($relIds));

    // Get the last message per quote_rel_id from quote_messages
    $sqlLast = "
      SELECT m.quote_rel_id, m.sender_type
      FROM quote_messages m
      INNER JOIN (
        SELECT quote_rel_id, MAX(created_at) AS max_created
        FROM quote_messages
        WHERE quote_rel_id IN ($placeholders)
        GROUP BY quote_rel_id
      ) latest
        ON m.quote_rel_id = latest.quote_rel_id
       AND m.created_at = latest.max_created
    ";

    if ($stmt = $conn->prepare($sqlLast)) {
        $stmt->bind_param($types, ...$relIds);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {
            $lastSenderByRelId[(int)$row['quote_rel_id']] = $row['sender_type'];
        }
        $stmt->close();
    }
}

$conn->close();

// Helper
function nice_datetime($dt) {
    if (!$dt) return "—";
    $ts = strtotime($dt);
    if (!$ts) return "—";
    return date("M j, Y g:ia", $ts);
}

// Bucket quotes: active (open), booked, archived (closed)
$activeQuotes   = [];
$bookedQuotes   = [];
$archivedQuotes = [];

foreach ($quotes as $q) {
    $status = $q['status'] ?? 'open';
    if ($status === 'booked') {
        $bookedQuotes[] = $q;
    } elseif ($status === 'closed') {
        $archivedQuotes[] = $q;
    } else { // treat everything else as active
        $activeQuotes[] = $q;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>My custom repair requests – Autoremz</title>
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

    .quote-list {
      display:flex;
      flex-direction:column;
      gap:10px;
    }

    .quote-card {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      padding:10px 10px;
      border-radius:10px;
      border:1px solid #E5E7EB;
      background:#F9FAFB;
    }

    .quote-main {
      flex:1;
      display:flex;
      flex-direction:column;
      gap:4px;
    }

    .quote-title {
      font-size:13px;
      font-weight:600;
      color:#111827;
    }

    .quote-meta {
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

    .service-tags {
      display:flex;
      flex-wrap:wrap;
      gap:4px;
      margin-top:4px;
    }

    .service-tag {
      font-size:11px;
      padding:2px 6px;
      border-radius:999px;
      background:#EEF2FF;
      color:#4F46E5;
    }

    .shops-line {
      font-size:12px;
      color:#374151;
      margin-top:6px;
    }

    .shops-stats {
      margin-top:4px;
      font-size:11px;
      color:#6B7280;
      display:flex;
      flex-wrap:wrap;
      gap:8px;
    }

    .shop-row {
      display:grid;
      grid-template-columns: minmax(0,1.6fr) auto auto;
      align-items:center;
      gap:8px;
      font-size:12px;
    }

    .shop-name-small {
      font-size:13px;
      font-weight:500;
      color:#111827;
    }

    .shop-city-small {
      font-size:11px;
      color:#6B7280;
    }

    .quote-cta {
      min-width:190px;
      display:flex;
      flex-direction:column;
      justify-content:center;
      align-items:flex-end;
      gap:6px;
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

    .list-empty-text {
      font-size:13px;
      color:#6B7280;
      margin-top:4px;
    }

    /* collapsible sections */
    .collapsible-toggle {
      font-size:18px;
      line-height:1;
      color:#9CA3AF;
      cursor:pointer;
      user-select:none;
      padding-left:6px;
    }
/* Tiny chat icon unread indicator */
.quote-unread-icon {
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
    .collapsible-body {
      margin-top:6px;
    }

    .collapsed .collapsible-body {
      display:none;
    }

    @media (max-width: 800px) {
      .quote-card {
        flex-direction:column;
      }
      .quote-cta {
        align-items:flex-start;
        min-width:0;
      }
      .shop-row {
        grid-template-columns: minmax(0,1.8fr) auto;
        row-gap:4px;
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
          <strong>Autoremz – My custom requests</strong>
          <small>Track shops and conversations</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="index.php" class="btn-ghost">
          Home
        </a>

        <a href="my-reservations.php" class="btn-ghost">
          My reservations
          <?php if (!empty($resAlertCount)): ?>
            <span class="nav-badge"><?php echo $resAlertCount; ?></span>
          <?php endif; ?>
        </a>

        <a href="user-dashboard.php" class="btn-ghost">
          Dashboard
        </a>

        <a href="user-logout.php" class="btn-ghost">
          Log out
        </a>
      </div>
    </header>

    <main class="dashboard-shell">
      <h1 class="dashboard-header-text">
        Your custom repair requests<?php echo $userName ? ', ' . htmlspecialchars($userName) : ''; ?>
      </h1>
      <p class="dashboard-header-subtitle">
        See which shops received your request, who is interested, and open conversations to discuss details.
      </p>

      <!-- ACTIVE QUOTES -->
      <section class="dashboard-card-light">
        <div class="section-header">
          <div class="section-title">Active custom requests</div>
          <span class="section-pill"><?php echo count($activeQuotes); ?> active</span>
        </div>

        <?php if (empty($activeQuotes)): ?>
          <p class="list-empty-text">
            You don’t have any active custom repair requests right now.
            You can send one from the homepage or via the
            <a href="custom-quote.php" class="auth-link">custom request form</a>.
          </p>
        <?php else: ?>
          <div class="quote-list">
            <?php foreach ($activeQuotes as $q): ?>
              <?php
                $qid    = (int)$q['id'];
                $status = $q['status'] ?? 'open';

                $label  = 'Open';
                $color  = '#15803d';
                $bg     = '#ecfdf3';

                if ($status === 'booked') {
                  $label = 'Booked';
                  $color = '#1d4ed8';
                  $bg    = '#eff6ff';
                } elseif ($status === 'closed') {
                  $label = 'Closed';
                  $color = '#6b7280';
                  $bg    = '#f9fafb';
                }

                $allShops = $shopsByQuoteId[$qid] ?? [];
                $total   = count($allShops);
                $pending = $accepted = $declined = $booked = 0;

                foreach ($allShops as $sr) {
                    $s = $sr['rel_status'] ?? 'pending';
                    if ($s === 'pending')  $pending++;
                    if ($s === 'accepted') $accepted++;
                    if ($s === 'declined') $declined++;
                    if ($s === 'booked')   $booked++;
                }

                if ($status === 'booked') {
                    $pending = 0;
                }

                // shops actually shown
                $displayShops = [];
                if ($status === 'booked') {
                    foreach ($allShops as $sr) {
                        if (($sr['rel_status'] ?? 'pending') === 'booked') {
                            $displayShops[] = $sr;
                        }
                    }
                } else {
                    foreach ($allShops as $sr) {
                        if (($sr['rel_status'] ?? 'pending') === 'accepted') {
                            $displayShops[] = $sr;
                        }
                    }
                }
              ?>
              <div class="quote-card">
                <div class="quote-main">
                  <div class="quote-title">
                    <?php echo htmlspecialchars($q['title']); ?>
                  </div>
                  <div class="quote-meta">
                    <?php if (!empty($q['city'])): ?>
                      <span><?php echo htmlspecialchars($q['city']); ?></span>
                    <?php endif; ?>
                    <span>Created: <?php echo nice_datetime($q['created_at']); ?></span>
                  </div>

                  <?php if (!empty($q['keywords'])): ?>
                    <div class="service-tags">
                      <?php foreach (explode(',', $q['keywords']) as $kw): ?>
                        <span class="service-tag">
                          <?php echo htmlspecialchars(trim($kw)); ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($q['details'])): ?>
                    <div style="margin-top:6px; font-size:13px; color:#4b5563; max-height:80px; overflow:auto;">
                      <?php echo nl2br(htmlspecialchars($q['details'])); ?>
                    </div>
                  <?php endif; ?>

                  <div class="shops-line">
                    <strong>Shops contacted:</strong> <?php echo $total; ?>
                  </div>

                  <div class="shops-stats">
                    <span>Pending: <strong><?php echo $pending; ?></strong></span>
                    <span>Interested: <strong><?php echo $accepted; ?></strong></span>
                    <span>Declined: <strong><?php echo $declined; ?></strong></span>
                    <span>Booked: <strong><?php echo $booked; ?></strong></span>
                  </div>

                  <div style="margin-top:10px; border-top:1px dashed #e5e7eb; padding-top:8px;">
                    <?php if (empty($allShops)): ?>
                      <p style="font-size:12px; color:#9ca3af; margin:0;">
                        This request hasn’t been sent to any shops yet (no matching city).
                      </p>
                    <?php elseif (empty($displayShops)): ?>
                      <p style="font-size:12px; color:#9ca3af; margin:0;">
                        No shops have shown interest yet. Once a shop marks this request
                        as interested, they’ll appear here and you’ll be able to contact them.
                      </p>
                    <?php else: ?>
                      <div style="display:flex; flex-direction:column; gap:6px;">
                        <?php foreach ($displayShops as $sr): ?>
                          <?php
                            $sStatus = $sr['rel_status'] ?? 'pending';
                            $badgeText = ucfirst($sStatus);
                            $sColor = '#92400e';
                            $sBg    = '#fffbeb';

                            if ($sStatus === 'accepted') {
                              $badgeText = 'Interested';
                              $sColor = '#166534';
                              $sBg    = '#ecfdf3';
                            } elseif ($sStatus === 'declined') {
                              $badgeText = 'Declined';
                              $sColor = '#991b1b';
                              $sBg    = '#fef2f2';
                            } elseif ($sStatus === 'booked') {
                              $badgeText = 'Booked';
                              $sColor = '#1d4ed8';
                              $sBg    = '#eff6ff';
                            } elseif ($sStatus === 'pending') {
                              $badgeText = 'Pending';
                            }

                            $relId = (int)$sr['rel_id'];
                            $lastSender = $lastSenderByRelId[$relId] ?? null;
                            $hasUnread = ($lastSender === 'shop');
                          ?>
                          <div class="shop-row">
                            <div>
                              <div class="shop-name-small">
                                <?php echo htmlspecialchars($sr['shop_name']); ?>
                              </div>
                              <?php if (!empty($sr['city'])): ?>
                                <div class="shop-city-small">
                                  <?php echo htmlspecialchars($sr['city']); ?>
                                </div>
                              <?php endif; ?>
                            </div>
                            <span class="status-badge" style="background:<?php echo $sBg; ?>; color:<?php echo $sColor; ?>;">
                              <?php echo $badgeText; ?>
                            </span>

                            <?php if ($sStatus === 'accepted'): ?>
                              <a href="quote-conversation.php?rel_id=<?php echo $relId; ?>"
                                 class="btn-primary"
                                 style="font-size:11px; padding:5px 10px; justify-self:flex-end; position:relative;">
                                Open conversation
                                <?php if ($hasUnread): ?>
    <span class="quote-unread-icon">
      <svg viewBox="0 0 20 20" fill="currentColor" width="10" height="10">
        <path d="M18 10c0 3.866-3.582 7-8 7-1.042 0-2.044-.154-2.98-.44L3 17l.51-3.06C2.565 12.83 2 11.464 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7z"/>
      </svg>
    </span>
                                <?php endif; ?>
                              </a>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="quote-cta">
                  <span class="status-badge" style="background:<?php echo $bg; ?>; color:<?php echo $color; ?>;">
                    <?php echo $label; ?>
                  </span>
                  <a href="custom-quote.php" class="btn-ghost" style="font-size:11px; padding-inline:10px;">
                    New request
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- BOOKED QUOTES (collapsible) -->
      <section class="dashboard-card-light collapsed" id="booked-section">
        <div class="section-header">
          <div class="section-title">Booked custom requests</div>
          <div style="display:flex; align-items:center; gap:6px;">
            <span class="section-pill"><?php echo count($bookedQuotes); ?> booked</span>
            <span class="collapsible-toggle" onclick="toggleSection('booked-section')">+</span>
          </div>
        </div>

        <div class="collapsible-body">
          <?php if (empty($bookedQuotes)): ?>
            <p class="list-empty-text">
              Once a custom request is booked with a shop, it will appear here.
            </p>
          <?php else: ?>
            <div class="quote-list">
              <?php foreach ($bookedQuotes as $q): ?>
                <?php
                  $qid    = (int)$q['id'];
                  $status = $q['status'] ?? 'booked';

                  $label  = 'Booked';
                  $color  = '#1d4ed8';
                  $bg     = '#eff6ff';

                  $allShops = $shopsByQuoteId[$qid] ?? [];
                  $total   = count($allShops);
                  $pending = $accepted = $declined = $booked = 0;

                  foreach ($allShops as $sr) {
                      $s = $sr['rel_status'] ?? 'pending';
                      if ($s === 'pending')  $pending++;
                      if ($s === 'accepted') $accepted++;
                      if ($s === 'declined') $declined++;
                      if ($s === 'booked')   $booked++;
                  }

                  $pending = 0; // overall booked

                  $displayShops = [];
                  foreach ($allShops as $sr) {
                      if (($sr['rel_status'] ?? 'pending') === 'booked') {
                          $displayShops[] = $sr;
                      }
                  }
                ?>
                <div class="quote-card">
                  <div class="quote-main">
                    <div class="quote-title">
                      <?php echo htmlspecialchars($q['title']); ?>
                    </div>
                    <div class="quote-meta">
                      <?php if (!empty($q['city'])): ?>
                        <span><?php echo htmlspecialchars($q['city']); ?></span>
                      <?php endif; ?>
                      <span>Created: <?php echo nice_datetime($q['created_at']); ?></span>
                    </div>

                    <?php if (!empty($q['keywords'])): ?>
                      <div class="service-tags">
                        <?php foreach (explode(',', $q['keywords']) as $kw): ?>
                          <span class="service-tag">
                            <?php echo htmlspecialchars(trim($kw)); ?>
                          </span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>

                    <?php if (!empty($q['details'])): ?>
                      <div style="margin-top:6px; font-size:13px; color:#4b5563; max-height:80px; overflow:auto;">
                        <?php echo nl2br(htmlspecialchars($q['details'])); ?>
                      </div>
                    <?php endif; ?>

                    <div class="shops-line">
                      <strong>Shops contacted:</strong> <?php echo $total; ?>
                    </div>

                    <div class="shops-stats">
                      <span>Pending: <strong><?php echo $pending; ?></strong></span>
                      <span>Interested: <strong><?php echo $accepted; ?></strong></span>
                      <span>Declined: <strong><?php echo $declined; ?></strong></span>
                      <span>Booked: <strong><?php echo $booked; ?></strong></span>
                    </div>

                    <div style="margin-top:10px; border-top:1px dashed #e5e7eb; padding-top:8px;">
                      <?php if (empty($displayShops)): ?>
                        <p style="font-size:12px; color:#9ca3af; margin:0;">
                          This request has been booked, but the booked shop is not visible.
                        </p>
                      <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                          <?php foreach ($displayShops as $sr): ?>
                            <div class="shop-row">
                              <div>
                                <div class="shop-name-small">
                                  <?php echo htmlspecialchars($sr['shop_name']); ?>
                                </div>
                                <?php if (!empty($sr['city'])): ?>
                                  <div class="shop-city-small">
                                    <?php echo htmlspecialchars($sr['city']); ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                              <span class="status-badge" style="background:#eff6ff; color:#1d4ed8;">
                                Booked
                              </span>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="quote-cta">
                    <span class="status-badge" style="background:<?php echo $bg; ?>; color:<?php echo $color; ?>;">
                      <?php echo $label; ?>
                    </span>
                    <span style="font-size:11px; color:#9CA3AF; text-align:right;">
                      Conversation continues under “My reservations”.
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- ARCHIVED QUOTES -->
      <section class="dashboard-card-light collapsed" id="archived-quotes-section">
        <div class="section-header">
          <div class="section-title">Archived custom requests</div>
          <div style="display:flex; align-items:center; gap:6px;">
            <span class="section-pill"><?php echo count($archivedQuotes); ?> archived</span>
            <span class="collapsible-toggle" onclick="toggleSection('archived-quotes-section')">+</span>
          </div>
        </div>

        <div class="collapsible-body">
          <?php if (empty($archivedQuotes)): ?>
            <p class="list-empty-text">
              When you manually close custom requests (status = closed), they will appear here.
            </p>
          <?php else: ?>
            <div class="quote-list">
              <?php foreach ($archivedQuotes as $q): ?>
                <?php
                  $qid    = (int)$q['id'];
                  $label  = 'Closed';
                  $color  = '#6b7280';
                  $bg     = '#f9fafb';

                  $allShops = $shopsByQuoteId[$qid] ?? [];
                  $total   = count($allShops);
                  $pending = $accepted = $declined = $booked = 0;

                  foreach ($allShops as $sr) {
                      $s = $sr['rel_status'] ?? 'pending';
                      if ($s === 'pending')  $pending++;
                      if ($s === 'accepted') $accepted++;
                      if ($s === 'declined') $declined++;
                      if ($s === 'booked')   $booked++;
                  }
                ?>
                <div class="quote-card">
                  <div class="quote-main">
                    <div class="quote-title">
                      <?php echo htmlspecialchars($q['title']); ?>
                    </div>
                    <div class="quote-meta">
                      <?php if (!empty($q['city'])): ?>
                        <span><?php echo htmlspecialchars($q['city']); ?></span>
                      <?php endif; ?>
                      <span>Created: <?php echo nice_datetime($q['created_at']); ?></span>
                    </div>

                    <?php if (!empty($q['keywords'])): ?>
                      <div class="service-tags">
                        <?php foreach (explode(',', $q['keywords']) as $kw): ?>
                          <span class="service-tag">
                            <?php echo htmlspecialchars(trim($kw)); ?>
                          </span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>

                    <?php if (!empty($q['details'])): ?>
                      <div style="margin-top:6px; font-size:13px; color:#4b5563; max-height:80px; overflow:auto;">
                        <?php echo nl2br(htmlspecialchars($q['details'])); ?>
                      </div>
                    <?php endif; ?>

                    <div class="shops-line">
                      <strong>Shops contacted:</strong> <?php echo $total; ?>
                    </div>

                    <div class="shops-stats">
                      <span>Pending: <strong><?php echo $pending; ?></strong></span>
                      <span>Interested: <strong><?php echo $accepted; ?></strong></span>
                      <span>Declined: <strong><?php echo $declined; ?></strong></span>
                      <span>Booked: <strong><?php echo $booked; ?></strong></span>
                    </div>
                  </div>

                  <div class="quote-cta">
                    <span class="status-badge" style="background:<?php echo $bg; ?>; color:<?php echo $color; ?>;">
                      <?php echo $label; ?>
                    </span>
                    <span style="font-size:11px; color:#9CA3AF; text-align:right;">
                      Conversation closed.
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
