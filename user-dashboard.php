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
    die("DB connection failed: " . $conn->connect_error);
}

// Messages for profile / garage forms
$profileMsg = "";
$carMsg     = "";

// Handle POST actions for profile + garage
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $profileMsg = "Name cannot be empty.";
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $userId);
            if ($stmt->execute()) {
                $profileMsg = "Profile updated.";
                $_SESSION['user_name'] = $name;
                $userName = $name;
            } else {
                $profileMsg = "Error updating profile.";
            }
            $stmt->close();
        }
    }

    if ($action === 'add_car') {
        $make  = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year  = (int)($_POST['year'] ?? 0);
        $trim  = trim($_POST['trim'] ?? '');
        $vin   = trim($_POST['vin'] ?? '');
        $plate = trim($_POST['plate'] ?? '');

        if ($make === '' || $model === '' || !$year) {
            $carMsg = "Make, model, and year are required.";
        } else {
            $stmt = $conn->prepare("
              INSERT INTO cars (user_id, make, model, year, trim, vin, plate)
              VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ississs", $userId, $make, $model, $year, $trim, $vin, $plate);
            $carMsg = $stmt->execute() ? "Car added to your garage." : "Error adding car.";
            $stmt->close();
        }
    }

    if ($action === 'delete_car') {
        $carId = (int)($_POST['car_id'] ?? 0);
        if ($carId > 0) {
            $stmt = $conn->prepare("DELETE FROM cars WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $carId, $userId);
            $carMsg = $stmt->execute() ? "Car removed." : "Error removing car.";
            $stmt->close();
        }
    }
}

// Fetch user profile (email + name)
$stmt = $conn->prepare("SELECT email, name FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch cars for "My garage"
$stmt = $conn->prepare("
  SELECT id, make, model, year, trim, vin, plate, created_at
  FROM cars
  WHERE user_id = ?
  ORDER BY created_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$cars = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Notification counts for header badges ---
$resAlertCount   = 0;
$quoteAlertCount = 0;

// Reservations needing attention: pending or accepted
$cntSql = "
  SELECT COUNT(*) AS c
  FROM reservations
  WHERE user_id = ?
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

// --- High level stats for dashboard tiles ---

// Total & upcoming reservations
$totalReservations    = 0;
$upcomingReservations = 0;

$sql = "
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status IN ('pending','accepted') THEN 1 ELSE 0 END) AS upcoming
  FROM reservations
  WHERE user_id = ?
";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($totalReservations, $upcomingReservations);
    $stmt->fetch();
    $stmt->close();
}

// Total & open custom requests
$totalQuotes = 0;
$openQuotes  = 0;

$sql = "
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count
  FROM quote_requests
  WHERE user_id = ?
";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($totalQuotes, $openQuotes);
    $stmt->fetch();
    $stmt->close();
}

// --- Recent reservations (latest 3) ---
$recentReservations = [];

$sql = "
  SELECT r.*,
         s.shop_name,
         s.city
  FROM reservations r
  JOIN shops s ON r.shop_id = s.id
  WHERE r.user_id = ?
  ORDER BY r.created_at DESC
  LIMIT 3
";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recentReservations[] = $row;
    }
    $stmt->close();
}

// --- Recent custom requests (latest 3) ---
$recentQuotes = [];

$sql = "
  SELECT *
  FROM quote_requests
  WHERE user_id = ?
  ORDER BY created_at DESC
  LIMIT 3
";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recentQuotes[] = $row;
    }
    $stmt->close();
}

$conn->close();

// Helper to format date/time nicely
function nice_datetime($dt) {
    if (!$dt) return "—";
    $ts = strtotime($dt);
    if (!$ts) return "—";
    return date("M j, Y g:ia", $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>User dashboard – Autoremz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Light, neutral dashboard overrides */

    body {
      background: #F3F4F6; /* neutral light grey */
    }

    .dashboard-shell {
      max-width: 1100px;
      margin: 0 auto;
      padding: 16px 12px 32px;
    }

    .dashboard-header-text {
      font-size: 26px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 4px;
    }

    .dashboard-header-subtitle {
      font-size: 14px;
      color: #6B7280;
      margin-bottom: 20px;
    }

    .dashboard-cards-grid {
      display: grid;
      grid-template-columns: minmax(0, 2.1fr) minmax(0, 1.4fr);
      gap: 14px;
      align-items: flex-start;
    }

    .dashboard-card-light {
      background: #FFFFFF;
      border-radius: 12px;
      padding: 18px 18px 16px;
      box-shadow: 0 1px 4px rgba(15,23,42,0.06);
      border: 1px solid #E5E7EB;
    }

    .dashboard-card-title {
      font-size: 16px;
      font-weight: 600;
      color: #111827;
      margin: 0 0 4px;
    }

    .dashboard-card-subtitle {
      font-size: 12px;
      color: #6B7280;
      margin: 0 0 12px;
    }

    .stats-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 10px;
    }

    .stats-pill {
      padding: 6px 10px;
      border-radius: 999px;
      background: #F9FAFB;
      border: 1px solid #E5E7EB;
      font-size: 11px;
      color: #374151;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .stats-pill strong {
      font-weight: 600;
      color: #111827;
    }

    .link-inline {
      font-size: 12px;
      color: #EF4444;
      text-decoration: none;
      font-weight: 500;
    }

    .link-inline:hover {
      text-decoration: underline;
    }

    .list-empty-text {
      font-size: 13px;
      color: #6B7280;
      margin: 4px 0 0;
    }

    .item-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 8px 0;
      border-bottom: 1px dashed #E5E7EB;
      gap: 10px;
    }

    .item-row:last-child {
      border-bottom: none;
    }

    .item-main {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .item-title {
      font-size: 13px;
      font-weight: 500;
      color: #111827;
    }

    .item-meta {
      font-size: 11px;
      color: #6B7280;
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .item-badge {
      display: inline-flex;
      align-items: center;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
    }

    .item-actions {
      display: flex;
      flex-direction: column;
      gap: 6px;
      align-items: flex-end;
      justify-content: center;
    }

    .btn-primary {
      background:#EF4444;
      color:#FFFFFF !important;
      padding:8px 14px;
      border-radius:8px;
      font-size:13px;
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
      padding:6px 10px;
      border-radius:6px;
      text-decoration:none;
      border:none;
      background:transparent;
      cursor:pointer;
      font-size:12px;
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

    /* Profile & garage layout */
    .profile-garage-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.8fr);
      gap: 14px;
      margin-top: 16px;
    }

    .dashboard-form label {
      display:block;
      margin-bottom:8px;
      font-size:12px;
      color:#374151;
    }

    .dashboard-form label span {
      display:block;
      margin-bottom:2px;
    }

    .dashboard-form input[type="text"],
    .dashboard-form input[type="email"],
    .dashboard-form input[type="number"] {
      width:100%;
      border-radius:6px;
      border:1px solid #D1D5DB;
      padding:6px 8px;
      font-size:13px;
    }

    .dashboard-form input:focus {
      outline:none;
      border-color:#EF4444;
      box-shadow:0 0 0 1px rgba(239,68,68,0.2);
    }

    .dashboard-message {
      font-size:12px;
      margin-bottom:8px;
      color:#166534;
    }

    .garage-list {
      display:flex;
      flex-direction:column;
      gap:6px;
      margin-top:8px;
    }

    .garage-item {
      display:grid;
      grid-template-columns: repeat(6, minmax(0,1fr));
      gap:6px;
      padding:6px 8px;
      border-radius:8px;
      border:1px solid #E5E7EB;
      background:#F9FAFB;
      font-size:11px;
      align-items:center;
    }

    @media (max-width: 900px) {
      .dashboard-cards-grid {
        grid-template-columns: minmax(0, 1fr);
      }
      .profile-garage-grid {
        grid-template-columns: minmax(0, 1fr);
      }
      .garage-item {
        grid-template-columns: repeat(2, minmax(0,1fr));
      }
    }
    
    
    /* Collapsible cards (profile + garage) */
.dashboard-card-light.collapsible {
  cursor: default;
}

.collapsible-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  cursor: pointer;
}

.collapsible-header-main {
  flex: 1;
}

.collapsible-toggle {
  font-size: 20px;
  line-height: 1;
  color: #9CA3AF;
  padding-left: 8px;
  user-select: none;
}

.collapsible-body {
  margin-top: 10px;
}

.dashboard-card-light.collapsed .collapsible-body {
  display: none;
}
/* Profile / garage pill tabs */
.profile-tabs {
  display: inline-flex;
  gap: 8px;
  margin-top: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.profile-tab {
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid #E5E7EB;
  background: #F9FAFB;
  font-size: 12px;
  color: #374151;
  cursor: pointer;
  outline: none;
}

.profile-tab.active {
  background: #EF4444;
  color: #FFFFFF;
  border-color: #EF4444;
}

.profile-section {
  display: none;
}

.profile-section.active {
  display: block;
}

.danger-btn {
    background: red;
    color: white;
    padding: 10px 15px;
    border: none;
    border-radius: 5px;
    cursor: pointer
}

.danger-btn:hover {
    opacity: 0.8;
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
          <strong>Autoremz – User dashboard</strong>
          <small>All your repairs in one place</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="index.php" class="btn-ghost">Home</a>

        <a href="my-reservations.php" class="btn-ghost">
          My reservations
          <?php if (!empty($resAlertCount)): ?>
            <span class="nav-badge"><?php echo $resAlertCount; ?></span>
          <?php endif; ?>
        </a>

        <a href="my-quotes.php" class="btn-ghost">
          My custom requests
          <?php if (!empty($quoteAlertCount)): ?>
            <span class="nav-badge"><?php echo $quoteAlertCount; ?></span>
          <?php endif; ?>
        </a>

        <a href="user-logout.php" class="btn-ghost">Log out</a>
      </div>
    </header>

    <main class="dashboard-shell">
      <h1 class="dashboard-header-text">
        Welcome back<?php echo $userName ? ', ' . htmlspecialchars($userName) : ''; ?>
      </h1>
      <p class="dashboard-header-subtitle">
        Quickly check your upcoming reservations, track custom requests, manage your profile, and keep your garage up to date.
      </p>

      <!-- Top stats row -->
      <div class="dashboard-cards-grid">
        <!-- Left: activity snapshot -->
        <section class="dashboard-card-light">
          <h2 class="dashboard-card-title">Your current activity</h2>
          <p class="dashboard-card-subtitle">
            A snapshot of what’s happening with your repairs right now.
          </p>

          <div class="stats-row">
            <div class="stats-pill">
              Total reservations: <strong><?php echo (int)$totalReservations; ?></strong>
            </div>
            <div class="stats-pill">
              Upcoming / in-progress: <strong><?php echo (int)$upcomingReservations; ?></strong>
            </div>
          </div>

          <div class="stats-row">
            <div class="stats-pill">
              Custom requests sent: <strong><?php echo (int)$totalQuotes; ?></strong>
            </div>
            <div class="stats-pill">
              Open custom requests: <strong><?php echo (int)$openQuotes; ?></strong>
            </div>
          </div>

          <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:8px;">
            <a href="my-reservations.php" class="btn-primary">
              Go to my reservations
            </a>
            <a href="my-quotes.php" class="btn-ghost">
              View custom requests
            </a>
            <a href="custom-quote.php" class="btn-ghost">
              New custom request
            </a>
          </div>
        </section>

        <!-- Right: shortcuts -->
        <aside class="dashboard-card-light">
          <h2 class="dashboard-card-title">Quick shortcuts</h2>
          <p class="dashboard-card-subtitle">
            Jump directly to what you use most.
          </p>

          <div style="display:flex; flex-direction:column; gap:8px; font-size:13px;">
<a href="#account-card" class="link-inline" onclick="showProfileSection('personal')">
  • Edit my profile
</a>
<a href="#account-card" class="link-inline" onclick="showProfileSection('garage')">
  • Manage my garage
</a>

            <a href="my-reservations.php" class="link-inline">
              • View all reservations
            </a>
            <a href="my-quotes.php" class="link-inline">
              • View all custom repair requests
            </a>
            <a href="custom-quote.php" class="link-inline">
              • Send a new custom request
            </a>
          </div>
        </aside>
      </div>

      <!-- Middle row: recent items -->
      <div style="display:grid; grid-template-columns:minmax(0,1.6fr) minmax(0,1.4fr); gap:14px; margin-top:16px;">
        <!-- Recent reservations -->
        <section class="dashboard-card-light">
          <h2 class="dashboard-card-title">Recent reservations</h2>
          <p class="dashboard-card-subtitle">
            Last few shops you booked with.
          </p>

          <?php if (empty($recentReservations)): ?>
            <p class="list-empty-text">
              You haven’t booked any repairs yet. Once you schedule a repair with a shop, it will appear here.
            </p>
          <?php else: ?>
            <?php foreach ($recentReservations as $res): ?>
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
                } elseif ($status === 'completed') {
                  $badgeText = 'Completed';
                  $badgeBg   = '#EEF2FF';
                  $badgeCol  = '#4F46E5';
                } elseif ($status === 'pending') {
                  $badgeText = 'Pending';
                }
              ?>
              <div class="item-row">
                <div class="item-main">
                  <div class="item-title">
                    <?php echo htmlspecialchars($res['shop_name']); ?>
                  </div>
                  <div class="item-meta">
                    <?php if (!empty($res['city'])): ?>
                      <span><?php echo htmlspecialchars($res['city']); ?></span>
                    <?php endif; ?>
                    <span>Created: <?php echo nice_datetime($res['created_at']); ?></span>
                    <?php if (!empty($res['preferred_time'])): ?>
                      <span>Preferred: <?php echo nice_datetime($res['preferred_time']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="item-actions">
                  <span class="item-badge" style="background:<?php echo $badgeBg; ?>; color:<?php echo $badgeCol; ?>;">
                    <?php echo $badgeText; ?>
                  </span>
                  <a href="my-reservations.php" class="btn-ghost" style="font-size:11px;">
                    View details
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>

        <!-- Recent custom requests -->
        <section class="dashboard-card-light">
          <h2 class="dashboard-card-title">Recent custom requests</h2>
          <p class="dashboard-card-subtitle">
            Shops you’ve reached out to for custom repairs.
          </p>

          <?php if (empty($recentQuotes)): ?>
            <p class="list-empty-text">
              No custom requests yet. You can send one in seconds from the homepage or the button above.
            </p>
          <?php else: ?>
            <?php foreach ($recentQuotes as $q): ?>
              <?php
                $status = $q['status'] ?? 'open';
                $badgeText = ucfirst($status);
                $badgeBg   = '#ECFDF3';
                $badgeCol  = '#166534';

                if ($status === 'booked') {
                  $badgeText = 'Booked';
                  $badgeBg   = '#DBEAFE';
                  $badgeCol  = '#1D4ED8';
                } elseif ($status === 'closed') {
                  $badgeText = 'Closed';
                  $badgeBg   = '#F9FAFB';
                  $badgeCol  = '#6B7280';
                } elseif ($status === 'open') {
                  $badgeText = 'Open';
                  $badgeBg   = '#ECFDF3';
                  $badgeCol  = '#15803D';
                }
              ?>
              <div class="item-row">
                <div class="item-main">
                  <div class="item-title">
                    <?php echo htmlspecialchars($q['title']); ?>
                  </div>
                  <div class="item-meta">
                    <?php if (!empty($q['city'])): ?>
                      <span><?php echo htmlspecialchars($q['city']); ?></span>
                    <?php endif; ?>
                    <span>Created: <?php echo nice_datetime($q['created_at']); ?></span>
                  </div>
                </div>

                <div class="item-actions">
                  <span class="item-badge" style="background:<?php echo $badgeBg; ?>; color:<?php echo $badgeCol; ?>;">
                    <?php echo $badgeText; ?>
                  </span>
                  <a href="my-quotes.php" class="btn-ghost" style="font-size:11px;">
                    View details
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
      </div>

      <!-- Bottom: profile + garage in one card with tabs -->
      <section class="dashboard-card-light" id="account-card" style="margin-top:16px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
          <div>
            <h2 class="dashboard-card-title">Your account</h2>
            <p class="dashboard-card-subtitle">
              Update your personal details and manage your vehicles in one place.
            </p>
          </div>
          <div class="profile-tabs">
            <button type="button"
                    class="profile-tab active"
                    data-section="personal"
                    onclick="showProfileSection('personal')">
              Personal details
            </button>
            <button type="button"
                    class="profile-tab"
                    data-section="garage"
                    onclick="showProfileSection('garage')">
              My garage
            </button>
            <button id="deleteAccountbtn"
                    class="danger-btn">
              Delete My Account
            </button>
          </div>
        </div>

        <!-- Personal details section -->
        <div id="section-personal" class="profile-section active">
          <?php if ($profileMsg): ?>
            <p class="dashboard-message"><?php echo htmlspecialchars($profileMsg); ?></p>
          <?php endif; ?>

          <form class="dashboard-form" method="POST" action="user-dashboard.php">
            <input type="hidden" name="action" value="profile" />
            <label>
              <span>Full name</span>
              <input type="text" name="name" required
                     value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" />
            </label>
            <label>
              <span>Email (read-only)</span>
              <input type="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled />
            </label>
            <button type="submit" class="btn-primary" style="margin-top:6px;">
              Save profile
            </button>
          </form>
        </div>

        <!-- My garage section -->
        <div id="section-garage" class="profile-section">
          <?php if ($carMsg): ?>
            <p class="dashboard-message"><?php echo htmlspecialchars($carMsg); ?></p>
          <?php endif; ?>

          <!-- Add car -->
          <form class="dashboard-form" method="POST" action="user-dashboard.php">
            <input type="hidden" name="action" value="add_car" />
            <div style="display:grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap:6px; margin-bottom:6px;">
              <input type="text" name="make"  placeholder="Make (e.g., Toyota)" required />
              <input type="text" name="model" placeholder="Model (e.g., Camry)" required />
              <input type="number" name="year"  placeholder="Year" min="1950" max="2099" required />
              <input type="text" name="trim"  placeholder="Trim (e.g., SE)" />
              <input type="text" name="vin"   placeholder="VIN" />
              <input type="text" name="plate" placeholder="Plate" />
            </div>
            <button type="submit" class="btn-primary">
              Add car
            </button>
          </form>

          <!-- List cars -->
          <?php if (count($cars) === 0): ?>
            <p class="list-empty-text" style="margin-top:8px;">
              No vehicles saved yet. Add one above to make future requests faster.
            </p>
          <?php else: ?>
            <div class="garage-list">
              <?php foreach ($cars as $c): ?>
                <div class="garage-item">
                  <div><strong><?php echo htmlspecialchars($c['make']." ".$c['model']); ?></strong></div>
                  <div>Year: <?php echo (int)$c['year']; ?></div>
                  <div><?php echo htmlspecialchars($c['trim'] ?: '—'); ?></div>
                  <div>VIN: <?php echo htmlspecialchars($c['vin'] ?: '—'); ?></div>
                  <div>Plate: <?php echo htmlspecialchars($c['plate'] ?: '—'); ?></div>
                  <div>
                    <form method="POST" action="user-dashboard.php"
                          onsubmit="return confirm('Remove this car from your garage?');">
                      <input type="hidden" name="action" value="delete_car" />
                      <input type="hidden" name="car_id" value="<?php echo (int)$c['id']; ?>" />
                      <button type="submit" class="btn-ghost" style="font-size:11px;">
                        Remove
                      </button>
                    </form>
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
  function showProfileSection(which) {
    var card = document.getElementById('account-card');
    if (!card) return;

    // Sections
    var sections = card.querySelectorAll('.profile-section');
    sections.forEach(function(sec) {
      sec.classList.remove('active');
    });
    var target = document.getElementById('section-' + which);
    if (target) {
      target.classList.add('active');
    }

    // Tabs
    var tabs = card.querySelectorAll('.profile-tab');
    tabs.forEach(function(tab) {
      tab.classList.remove('active');
    });
    var activeTab = card.querySelector('.profile-tab[data-section="' + which + '"]');
    if (activeTab) {
      activeTab.classList.add('active');
    }
  }

  // Optional: ensure default tab state on load
  document.addEventListener('DOMContentLoaded', function() {
    showProfileSection('personal');
  });

  //Deleting the user Profile
  //document.getElementById
</script>


</body>
</html>
