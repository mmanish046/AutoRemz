<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Require shop to be logged in
if (!isset($_SESSION["shop_id"])) {
    header("Location: shop-signin.php");
    exit;
}

$shopId = (int) $_SESSION['shop_id'];

// DATABASE SETTINGS
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: your DB username
$password   = "HostingerDBpinetree90601@";   // TODO: your DB password
$dbname     = "u138912455_autoremz_db";        // TODO: your DB name

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$profileMessage  = "";
$servicesMessage = "";

// Preset services for dropdown/checkboxes
$PRESET_SERVICES = [
    "Oil Change",
    "Brakes",
    "Engine Diagnostics",
    "Tires & Alignment",
    "AC Service",
    "Battery & Electrical",
    "Transmission"
];

// ---------------------------
// Handle POST (updates)
// ---------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "profile") {
        // Update profile info
        $shop_name = trim($_POST["shop_name"] ?? "");
        $phone     = trim($_POST["phone"] ?? "");
        $city      = trim($_POST["city"] ?? "");

        if ($shop_name === "") {
            $profileMessage = "Shop name cannot be empty.";
        } else {
            $stmt = $conn->prepare("UPDATE shops SET shop_name = ?, phone = ?, city = ? WHERE id = ?");
            $stmt->bind_param("sssi", $shop_name, $phone, $city, $shopId);
            if ($stmt->execute()) {
                $profileMessage = "Profile updated successfully.";
                $_SESSION["shop_name"] = $shop_name;
            } else {
                $profileMessage = "Error updating profile. Please try again.";
            }
            $stmt->close();
        }
    } elseif ($action === "services") {
        // Update services list
        $servicesList = [];

        // 1) Preset services
        $presetActive = $_POST["preset_active"] ?? [];      // array of service names
        $presetPrice  = $_POST["preset_price"] ?? [];       // assoc: serviceName => price

        foreach ($presetActive as $serviceName) {
            $serviceName = trim($serviceName);
            if ($serviceName === "") continue;
            $price = isset($presetPrice[$serviceName]) ? floatval($presetPrice[$serviceName]) : 0;
            if ($price > 0) {
                $servicesList[] = [
                    "name"  => $serviceName,
                    "price" => $price
                ];
            }
        }

        // 2) Custom services
        $customNames  = $_POST["custom_name"] ?? [];
        $customPrices = $_POST["custom_price"] ?? [];

        foreach ($customNames as $idx => $name) {
            $name  = trim($name);
            $price = isset($customPrices[$idx]) ? floatval($customPrices[$idx]) : 0;
            if ($name !== "" && $price > 0) {
                $servicesList[] = [
                    "name"  => $name,
                    "price" => $price
                ];
            }
        }

        // Save as JSON into "services" column
        $servicesJson = json_encode($servicesList);

        $stmt = $conn->prepare("UPDATE shops SET services = ? WHERE id = ?");
        $stmt->bind_param("si", $servicesJson, $shopId);
        if ($stmt->execute()) {
            $servicesMessage = "Services updated successfully.";
        } else {
            $servicesMessage = "Error updating services. Please try again.";
        }
        $stmt->close();
    }
}

// ---------------------------
// Fetch latest shop data
// ---------------------------
$stmt = $conn->prepare("SELECT shop_name, email, phone, city, services FROM shops WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $shopId);
$stmt->execute();
$result = $stmt->get_result();
$shop   = $result->fetch_assoc();
$stmt->close();

// ---------------------------
// Fetch reservations for this shop
// ---------------------------
$reservations = [];

$sql = "
  SELECT r.*,
         u.name  AS user_name,
         u.email AS user_email
  FROM reservations r
  JOIN users u ON r.user_id = u.id
  WHERE r.shop_id = ?
  ORDER BY r.created_at DESC
  LIMIT 50
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $shopId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reservations[] = $row;
}

$stmt->close();

// Helper to format date/times for display
function nice_datetime($dt) {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if (!$ts) return '—';
    return date('M j, Y g:ia', $ts);
}


// --- Fetch custom quote requests for this shop ---
$quoteRows = [];

$qSql = "
  SELECT
    qrs.id              AS rel_id,
    qrs.status          AS rel_status,
    qrs.created_at      AS rel_created_at,
    q.title,
    q.details,
    q.keywords,
    q.city,
    q.status            AS quote_status,
    q.created_at        AS quote_created_at,
    u.name              AS user_name,
    u.email             AS user_email
  FROM quote_requests_shops qrs
  JOIN quote_requests q ON qrs.quote_id = q.id
  JOIN users u          ON q.user_id = u.id
  WHERE qrs.shop_id = ?
  ORDER BY qrs.created_at DESC
  LIMIT 50
";

$stmt = $conn->prepare($qSql);
$stmt->bind_param("i", $shopId);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $quoteRows[] = $row;
}

$stmt->close();

// We can safely close DB now; all data is in PHP arrays
$conn->close();

// ---------------------------
// Decode services JSON
// ---------------------------
$savedServices = [];
if (!empty($shop["services"])) {
    $decoded = json_decode($shop["services"], true);
    if (is_array($decoded)) {
        $savedServices = $decoded;
    }
}

// Map of serviceName => price for easy access
$savedMap = [];
foreach ($savedServices as $row) {
    if (!empty($row["name"])) {
        $savedMap[$row["name"]] = $row["price"] ?? 0;
    }
}

// Separate custom services (those not in preset list)
$customExisting = [];
foreach ($savedMap as $name => $price) {
    if (!in_array($name, $PRESET_SERVICES, true)) {
        $customExisting[] = ["name" => $name, "price" => $price];
    }
}


// Simple counts for UI badges
$pendingResCount   = 0;
$pendingQuoteCount = 0;

foreach ($reservations as $r) {
    $status = $r['status'] ?? 'pending';
    if ($status === 'pending') {
        $pendingResCount++;
    }
}

foreach ($quoteRows as $qr) {
    $relStatus   = $qr['rel_status']   ?? 'pending';
    $quoteStatus = $qr['quote_status'] ?? 'open';

    // Count only quote relations that are still pending and not already booked
    if ($relStatus === 'pending' && $quoteStatus !== 'booked') {
        $pendingQuoteCount++;
    }
}

// Ensure we have at least 3 custom rows to show
while (count($customExisting) < 3) {
    $customExisting[] = ["name" => "", "price" => ""];
}


// --- Bucket reservations for the shop dashboard ---
$activeReservations    = [];
$completedReservations = [];
$archivedReservations  = [];

foreach ($reservations as $r) {
    $status     = $r['status'] ?? 'pending';
    $isArchived = !empty($r['is_archived']) ? (int)$r['is_archived'] : 0;

    if ($isArchived === 1) {
        $archivedReservations[] = $r;
    } elseif ($status === 'completed') {
        $completedReservations[] = $r;
    } else {
        // pending, accepted, etc.
        $activeReservations[] = $r;
    }
}

// ---------------------------
// Unread message indicators (shop side)
// ---------------------------
$lastSenderByResId = [];
$lastSenderByRelId = [];

// Collect reservation IDs for this shop
$reservationIds = [];
foreach ($reservations as $r) {
    if (!empty($r['id'])) {
        $reservationIds[] = (int)$r['id'];
    }
}

// Collect custom-quote relation IDs for this shop
$relIds = [];
foreach ($quoteRows as $qr) {
    if (!empty($qr['rel_id'])) {
        $relIds[] = (int)$qr['rel_id'];
    }
}

if (!empty($reservationIds) || !empty($relIds)) {
    // New lightweight connection just for unread checks
    $conn2 = new mysqli($servername, $username, $password, $dbname);
    if (!$conn2->connect_error) {

        // Last sender per reservation (reservation_messages)
        if (!empty($reservationIds)) {
            $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
            $types = str_repeat('i', count($reservationIds));

            $sqlLastRes = "
              SELECT m.reservation_id, m.sender_type
              FROM reservation_messages m
              INNER JOIN (
                SELECT reservation_id, MAX(created_at) AS max_created
                FROM reservation_messages
                WHERE reservation_id IN ($placeholders)
                GROUP BY reservation_id
              ) latest
                ON m.reservation_id = latest.reservation_id
               AND m.created_at = latest.max_created
            ";
            if ($stmt = $conn2->prepare($sqlLastRes)) {
                $stmt->bind_param($types, ...$reservationIds);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($row = $rs->fetch_assoc()) {
                    $lastSenderByResId[(int)$row['reservation_id']] = $row['sender_type'];
                }
                $stmt->close();
            }
        }

        // Last sender per quote_rel_id (quote_messages)
        if (!empty($relIds)) {
            $placeholders = implode(',', array_fill(0, count($relIds), '?'));
            $types = str_repeat('i', count($relIds));

            $sqlLastQuote = "
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
            if ($stmt = $conn2->prepare($sqlLastQuote)) {
                $stmt->bind_param($types, ...$relIds);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($row = $rs->fetch_assoc()) {
                    $lastSenderByRelId[(int)$row['quote_rel_id']] = $row['sender_type'];
                }
                $stmt->close();
            }
        }

        $conn2->close();
    }
}

$resmsg = $_GET['resmsg'] ?? null;


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Shop dashboard – Autoremz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="styles.css">
  
  
  <style>
  body {
    background:#F3F4F6;
  }
</style>

</head>
<body>
  <div class="page">
    <header>
      <div class="brand">
          <img src="brand_logo" alt="Autoremz logo" class="logo" />
        <div class="brand-text">
          <strong>Your Shop Dashboard</strong>
          <small>Manage your profile & services</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="shop-logout.php" class="btn btn-ghost">
          Log out
        </a>
      </div>
    </header>

    <main class="dashboard-layout">
      <h1 class="dashboard-title">
        Welcome, <?php echo htmlspecialchars($shop["shop_name"]); ?>
      </h1>
      <p class="dashboard-subtitle">
        Logged in as <strong><?php echo htmlspecialchars($shop["email"]); ?></strong>
      </p>

      <div class="dashboard-row">
        <!-- Read-only shop overview -->
        <section class="dashboard-card" id="shop-overview">
          <h2 class="dashboard-section-title">Shop overview</h2>
          <p class="dashboard-subtitle" style="margin-bottom:10px;">
            Basic info about your shop. Editing can live on a separate screen later.
          </p>
<div style="margin-top:14px; display:flex; gap:8px; flex-wrap:wrap;">
  <a href="shop-profile.php" class="btn btn-ghost">
    Edit shop profile
  </a>
  <a href="shop-services.php" class="btn btn-primary">
    Edit services &amp; prices
  </a>
</div>
          <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; font-size:14px;">
            <div>
              <div style="font-size:11px; text-transform:uppercase; color:#6b7280;">Shop name</div>
              <div><?php echo htmlspecialchars($shop['shop_name']); ?></div>
            </div>

            <div>
              <div style="font-size:11px; text-transform:uppercase; color:#6b7280;">Email</div>
              <div><?php echo htmlspecialchars($shop['email']); ?></div>
            </div>

            <div>
              <div style="font-size:11px; text-transform:uppercase; color:#6b7280;">Phone</div>
              <div><?php echo htmlspecialchars($shop['phone'] ?: '—'); ?></div>
            </div>

            <div>
              <div style="font-size:11px; text-transform:uppercase; color:#6b7280;">City / Area</div>
              <div><?php echo htmlspecialchars($shop['city'] ?: '—'); ?></div>
            </div>

            <div>
              <div style="font-size:11px; text-transform:uppercase; color:#6b7280;">Services listed</div>
              <div><?php echo count($savedServices); ?></div>
            </div>
          </div>
        </section>
      </div>

<!-- ======================= -->
<!-- ACTIVE RESERVATIONS     -->
<!-- ======================= -->
<section class="dashboard-card" id="shop-active-reservations">
  <div class="section-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
    <div class="section-title" style="font-size:16px; font-weight:600; color:#111827;">
      Active reservations
    </div>
    <span style="font-size:11px; color:#6B7280; padding:2px 8px; border-radius:999px; background:#F3F4F6; border:1px solid #E5E7EB;">
      <?php echo count($activeReservations); ?> active
    </span>
  </div>

  <?php if (empty($activeReservations)): ?>
    <p style="font-size:13px; color:#6B7280; margin-top:4px;">
      You don’t have any active reservations at the moment.
      New bookings will show up here.
    </p>
  <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:10px;">
      <?php foreach ($activeReservations as $res): ?>
        <?php
          $resId  = (int)$res['id'];
          $status = $res['status'] ?? 'pending';
          $statusLabel = ucfirst($status);

          $badgeBg   = '#FEF3C7';
          $badgeText = '#92400E';

          if ($status === 'accepted') {
              $badgeBg   = '#ECFDF3';
              $badgeText = '#166534';
          } elseif ($status === 'pending') {
              $badgeBg   = '#EFF6FF';
              $badgeText = '#1D4ED8';
          }

          // Build human time info
          $finalTime = $res['final_time'] ?? null;
          $prefSlots = [];
          foreach (['preferred_time1','preferred_time2','preferred_time3'] as $field) {
              if (!empty($res[$field])) {
                  $prefSlots[] = date("M j, Y g:ia", strtotime($res[$field]));
              }
          }

          // Unread indicator for shop
          $relId = !empty($res['quote_rel_id']) ? (int)$res['quote_rel_id'] : null;
          $lastSenderForThis = null;

          if ($relId === null) {
              // Normal reservation chat
              if (isset($lastSenderByResId[$resId])) {
                  $lastSenderForThis = $lastSenderByResId[$resId];
              }
          } else {
              // From custom quote, use quote_messages
              if (isset($lastSenderByRelId[$relId])) {
                  $lastSenderForThis = $lastSenderByRelId[$relId];
              }
          }

          // For SHOP: unread = last sender was USER
          $hasUnread = ($lastSenderForThis === 'user');
        ?>
        <div class="shop-card" style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; padding:10px 10px; border-radius:10px; border:1px solid #E5E7EB; background:#F9FAFB;">
          <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
              <div>
                <div style="font-size:13px; font-weight:600; color:#111827;">
                  <?php echo htmlspecialchars($res['user_name'] ?? 'Customer'); ?>
                </div>
                <div style="font-size:11px; color:#6B7280; display:flex; flex-wrap:wrap; gap:6px;">
                  <?php if (!empty($res['service_name'])): ?>
                    <span><?php echo htmlspecialchars($res['service_name']); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($res['city'])): ?>
                    <span>Location: <?php echo htmlspecialchars($res['city']); ?></span>
                  <?php endif; ?>
                  <span>Created: <?php echo date("M j, Y g:ia", strtotime($res['created_at'])); ?></span>
                </div>
              </div>
              <span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; background:<?php echo $badgeBg; ?>; color:<?php echo $badgeText; ?>;">
                <?php echo $statusLabel; ?>
              </span>
            </div>

            <div style="margin-top:4px; font-size:12px; color:#374151;">
              <?php if (!empty($finalTime)): ?>
                Confirmed time: <?php echo date("M j, Y g:ia", strtotime($finalTime)); ?>
              <?php elseif (!empty($prefSlots)): ?>
                Preferred time windows:
                <ul style="margin:4px 0 0 16px; padding:0; font-size:12px; color:#4B5563;">
                  <?php foreach ($prefSlots as $slot): ?>
                    <li><?php echo htmlspecialchars($slot); ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                No time preference provided yet.
              <?php endif; ?>
            </div>
          </div>

          <div style="min-width:180px; display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
            <?php if ($status === 'pending'): ?>
              <a href="reservation-update.php?id=<?php echo $resId; ?>"
                 class="btn btn-primary"
                 style="font-size:11px; padding:6px 10px;">
                Review & respond
              </a>
            <?php elseif ($status === 'accepted'): ?>
              <a href="reservation-update.php?id=<?php echo $resId; ?>"
                 class="btn btn-ghost"
                 style="font-size:11px; padding:6px 10px;">
                Modify / complete
              </a>
            <?php endif; ?>

            <?php if ($status === 'accepted' || $status === 'pending'): ?>
              <?php if (!empty($res['quote_rel_id'])): ?>
                <a href="quote-conversation.php?rel_id=<?php echo (int)$res['quote_rel_id']; ?>"
                   class="btn btn-ghost"
                   style="font-size:11px; padding:6px 10px; display:inline-flex; align-items:center;">
                  Open conversation
                  <?php if ($hasUnread): ?>
                    <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; width:14px; height:14px; border-radius:999px; background:#fb923c;">
                      <span style="font-size:9px; color:white;">✉</span>
                    </span>
                  <?php endif; ?>
                </a>
              <?php else: ?>
                <a href="reservation-conversation.php?id=<?php echo $resId; ?>"
                   class="btn btn-ghost"
                   style="font-size:11px; padding:6px 10px; display:inline-flex; align-items:center;">
                  View messages
                  <?php if ($hasUnread): ?>
                    <span style="margin-left:6px; display:inline-flex; align-items:center; justify-content:center; width:14px; height:14px; border-radius:999px; background:#fb923c;">
                      <span style="font-size:9px; color:white;">✉</span>
                    </span>
                  <?php endif; ?>
                </a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- ======================= -->
<!-- COMPLETED RESERVATIONS  -->
<!-- ======================= -->
<section class="dashboard-card collapsed" id="shop-completed-reservations">
  <div class="section-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
    <div class="section-title" style="font-size:16px; font-weight:600; color:#111827;">
      Completed reservations
    </div>
    <div style="display:flex; align-items:center; gap:6px;">
      <span style="font-size:11px; color:#6B7280; padding:2px 8px; border-radius:999px; background:#F3F4F6; border:1px solid #E5E7EB;">
        <?php echo count($completedReservations); ?> completed
      </span>
      <span class="collapsible-toggle"
            onclick="toggleSection('shop-completed-reservations')"
            style="font-size:18px; line-height:1; color:#9CA3AF; cursor:pointer; user-select:none;">
        +
      </span>
    </div>
  </div>

  <div class="collapsible-body">
    <?php if (empty($completedReservations)): ?>
      <p style="font-size:13px; color:#6B7280; margin-top:4px;">
        Marked complete reservations will appear here.
      </p>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($completedReservations as $res): ?>
          <?php
            $resId = (int)$res['id'];
          ?>
          <div class="shop-card" style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; padding:10px 10px; border-radius:10px; border:1px solid #E5E7EB; background:#F9FAFB;">
            <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
              <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                <div>
                  <div style="font-size:13px; font-weight:600; color:#111827;">
                    <?php echo htmlspecialchars($res['user_name'] ?? 'Customer'); ?>
                  </div>
                  <div style="font-size:11px; color:#6B7280; display:flex; flex-wrap:wrap; gap:6px;">
                    <?php if (!empty($res['service_name'])): ?>
                      <span><?php echo htmlspecialchars($res['service_name']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($res['city'])): ?>
                      <span>Location: <?php echo htmlspecialchars($res['city']); ?></span>
                    <?php endif; ?>
                    <span>Created: <?php echo date("M j, Y g:ia", strtotime($res['created_at'])); ?></span>
                  </div>
                </div>
                <span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; background:#ECFDF3; color:#166534;">
                  Completed
                </span>
              </div>
              <div style="margin-top:4px; font-size:12px; color:#374151;">
                This booking has been completed. Conversation is read-only from your records or moved out of your active flow.
              </div>
            </div>
            <div style="min-width:180px; display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
              <span style="font-size:11px; color:#9CA3AF;">
                No further action required.
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ======================= -->
<!-- ARCHIVED RESERVATIONS   -->
<!-- ======================= -->
<section class="dashboard-card collapsed" id="shop-archived-reservations">
  <div class="section-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
    <div class="section-title" style="font-size:16px; font-weight:600; color:#111827;">
      Archived reservations
    </div>
    <div style="display:flex; align-items:center; gap:6px;">
      <span style="font-size:11px; color:#6B7280; padding:2px 8px; border-radius:999px; background:#F3F4F6; border:1px solid #E5E7EB;">
        <?php echo count($archivedReservations); ?> archived
      </span>
      <span class="collapsible-toggle"
            onclick="toggleSection('shop-archived-reservations')"
            style="font-size:18px; line-height:1; color:#9CA3AF; cursor:pointer; user-select:none;">
        +
      </span>
    </div>
  </div>

  <div class="collapsible-body">
    <?php if (empty($archivedReservations)): ?>
      <p style="font-size:13px; color:#6B7280; margin-top:4px;">
        Reservations you archive will appear here for your records.
      </p>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach ($archivedReservations as $res): ?>
          <div class="shop-card" style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; padding:10px 10px; border-radius:10px; border:1px solid #E5E7EB; background:#F9FAFB;">
            <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
              <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                <div>
                  <div style="font-size:13px; font-weight:600; color:#111827;">
                    <?php echo htmlspecialchars($res['user_name'] ?? 'Customer'); ?>
                  </div>
                  <div style="font-size:11px; color:#6B7280; display:flex; flex-wrap:wrap; gap:6px;">
                    <?php if (!empty($res['service_name'])): ?>
                      <span><?php echo htmlspecialchars($res['service_name']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($res['city'])): ?>
                      <span>Location: <?php echo htmlspecialchars($res['city']); ?></span>
                    <?php endif; ?>
                    <span>Created: <?php echo date("M j, Y g:ia", strtotime($res['created_at'])); ?></span>
                  </div>
                </div>
                <span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; background:#F9FAFB; color:#6B7280; border:1px solid #E5E7EB;">
                  Archived
                </span>
              </div>
              <div style="margin-top:4px; font-size:12px; color:#374151;">
                This reservation is archived for your records and no longer appears in your active workflow.
              </div>
            </div>
            <div style="min-width:180px; display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
              <span style="font-size:11px; color:#9CA3AF;">
                Conversation closed.
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

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

      <!-- Custom quote requests card -->
      <?php $qmsg = $_GET['qmsg'] ?? null; ?>
      <div class="dashboard-card" id="custom-quotes">
        <details open>
          <summary style="display:flex; align-items:center; justify-content:space-between; cursor:pointer;">
            <span class="dashboard-section-title">Custom repair requests</span>
            <span style="font-size:12px; color:#6b7280;">
              <?php echo $pendingQuoteCount; ?> pending
            </span>
          </summary>

          <div style="margin-top:10px;">
            <?php if ($qmsg === 'ok'): ?>
              <p class="dashboard-message" style="color:#16a34a;">
                Quote updated successfully.
              </p>
            <?php elseif ($qmsg === 'error'): ?>
              <p class="dashboard-message" style="color:#b91c1c;">
                There was a problem updating that quote.
              </p>
            <?php elseif ($qmsg === 'notfound'): ?>
              <p class="dashboard-message" style="color:#b91c1c;">
                That quote request was not found for your shop.
              </p>
            <?php endif; ?>

            <?php if (empty($quoteRows)): ?>
              <p style="font-size:13px; color:#6b7280;">
                You don’t have any custom repair requests yet.
              </p>
            <?php else: ?>
              <div style="display:flex; flex-direction:column; gap:10px; margin-top:8px;">
                <?php foreach ($quoteRows as $qr): ?>
                  <?php
                    $relStatus   = $qr['rel_status']   ?? 'pending';
                    $quoteStatus = $qr['quote_status'] ?? 'open';

                    $badgeText = ucfirst($relStatus);
                    $color     = '#f97316';
                    $bg        = '#fffbeb';

                    if ($relStatus === 'accepted') {
                      $color = '#166534';
                      $bg    = '#ecfdf3';
                      $badgeText = 'Interested';
                    } elseif ($relStatus === 'declined') {
                      $color = '#991b1b';
                      $bg    = '#fef2f2';
                      $badgeText = 'Declined';
                    } elseif ($relStatus === 'booked') {
                      $color = '#1d4ed8';
                      $bg    = '#eff6ff';
                      $badgeText = 'Booked with you';
                    } elseif ($quoteStatus === 'booked' && $relStatus !== 'booked') {
                      $color = '#6b7280';
                      $bg    = '#f9fafb';
                      $badgeText = 'Booked elsewhere';
                    } elseif ($relStatus === 'pending') {
                      $color = '#92400e';
                      $bg    = '#fffbeb';
                      $badgeText = 'Pending';
                    }
                  ?>
                  <div class="shop-card" style="align-items:flex-start;">
                    <div style="flex:1; display:flex; flex-direction:column; gap:4px;">
                      <div class="shop-name-row">
                        <div>
                          <div class="shop-name">
                            <?php echo htmlspecialchars($qr['title']); ?>
                          </div>
                          <div class="shop-meta">
                            <span>From: <?php echo htmlspecialchars($qr['user_name']); ?></span>
                            <?php if (!empty($qr['user_email'])): ?>
                              <span class="dot-divider"><?php echo htmlspecialchars($qr['user_email']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($qr['city'])): ?>
                              <span class="dot-divider"><?php echo htmlspecialchars($qr['city']); ?></span>
                            <?php endif; ?>
                          </div>
                        </div>
                        <span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; background:<?php echo $bg; ?>; color:<?php echo $color; ?>;">
                          <?php echo $badgeText; ?>
                        </span>
                      </div>

                      <?php if (!empty($qr['keywords'])): ?>
                        <div class="service-tags" style="margin-top:4px;">
                          <?php foreach (explode(',', $qr['keywords']) as $kw): ?>
                            <span class="service-tag">
                              <?php echo htmlspecialchars(trim($kw)); ?>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>

                      <?php if (!empty($qr['details'])): ?>
                        <div style="margin-top:6px; font-size:13px; color:#4b5563; max-height:80px; overflow:auto;">
                          <?php echo nl2br(htmlspecialchars($qr['details'])); ?>
                        </div>
                      <?php endif; ?>

                      <div style="margin-top:6px; font-size:11px; color:#9ca3af;">
                        Request created: <?php echo nice_datetime($qr['quote_created_at']); ?><br>
                        Sent to your shop: <?php echo nice_datetime($qr['rel_created_at']); ?>
                      </div>
                    </div>

                    <div class="shop-cta" style="min-width:190px;">
                      <div class="shop-cta-buttons" style="flex-wrap:wrap;">
                        <?php if ($quoteStatus === 'booked' && $relStatus === 'booked'): ?>
                          <span style="font-size:12px; color:#1d4ed8;">
                            This request has been booked with your shop.
                          </span>
                        <?php elseif ($quoteStatus === 'booked'): ?>
                          <span style="font-size:12px; color:#6b7280;">
                            This request has already been booked with another shop.
                          </span>
                        <?php else: ?>
                          <form action="quote-update.php" method="POST" style="display:flex; flex-direction:column; gap:4px; width:100%;">
                            <input type="hidden" name="rel_id" value="<?php echo (int)$qr['rel_id']; ?>">

                            <button type="submit" name="action" value="accept"
                                    class="btn btn-primary"
                                    style="font-size:11px; padding:5px 10px; width:100%;">
                              Mark as interested
                            </button>

                            <button type="submit" name="action" value="decline"
                                    class="btn btn-ghost"
                                    style="font-size:11px; padding:5px 10px; width:100%;">
                              Decline
                            </button>
                          </form>
                        <?php endif; ?>

<?php
  $relId = (int)$qr['rel_id'];
  $lastSender = $lastSenderByRelId[$relId] ?? null;
  // For the SHOP, "unread" means last message was from the USER
  $hasUnread = ($lastSender === 'user');
?>
<?php if ($relStatus === 'accepted' || $relStatus === 'booked'): ?>
  <a href="quote-conversation.php?rel_id=<?php echo $relId; ?>"
     class="btn btn-ghost"
     style="font-size:11px; padding:5px 10px; width:100%; margin-top:6px; text-align:center; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
    <span>View conversation</span>
    <?php if ($hasUnread): ?>
      <span style="display:inline-flex; align-items:center; justify-content:center; width:14px; height:14px; border-radius:999px; background:#fb923c;">
        <span style="font-size:9px; color:white;">✉</span>
      </span>
    <?php endif; ?>
  </a>
<?php endif; ?>

                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </details>
      </div>

    </main>
  </div>
</body>
</html>
