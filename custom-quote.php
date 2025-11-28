<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Require user login
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

$successMessage = "";
$errorMessage   = "";

// simple keyword options (we can expand later)
$KEYWORD_OPTIONS = [
    "Engine",
    "Brakes",
    "Bodywork / Collision",
    "Electrical",
    "AC / Heating",
    "Tires / Wheels",
    "Transmission",
    "Diagnostics"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title   = trim($_POST["title"] ?? "");
    $details = trim($_POST["details"] ?? "");
    $city    = trim($_POST["city"] ?? "");
    $kw      = $_POST["keywords"] ?? []; // array of selected keywords

    if ($title === "" || $details === "") {
        $errorMessage = "Please provide at least a title and some details.";
    } else {
        $keywordsStr = "";
        if (!empty($kw) && is_array($kw)) {
            // Join selected keywords into comma-separated string
            $cleanKw = array_map("trim", $kw);
            $keywordsStr = implode(", ", $cleanKw);
        }

        // Insert into quote_requests
        $stmt = $conn->prepare("
            INSERT INTO quote_requests (user_id, title, details, keywords, city, status)
            VALUES (?, ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param("issss", $userId, $title, $details, $keywordsStr, $city);
        if ($stmt->execute()) {
            $quoteId = $stmt->insert_id;
            $stmt->close();

            // For v1: send this request to all shops in the same city (if provided).
            // Later we can refine to match services/keywords more tightly.
            if ($city !== "") {
                $shopStmt = $conn->prepare("SELECT id FROM shops WHERE city = ?");
                $shopStmt->bind_param("s", $city);
            } else {
                $shopStmt = $conn->prepare("SELECT id FROM shops");
            }

            $shopStmt->execute();
            $shopResult = $shopStmt->get_result();

            $insertShopStmt = $conn->prepare("
                INSERT INTO quote_requests_shops (quote_id, shop_id, status)
                VALUES (?, ?, 'pending')
            ");

            while ($shopRow = $shopResult->fetch_assoc()) {
                $sid = (int)$shopRow["id"];
                $insertShopStmt->bind_param("ii", $quoteId, $sid);
                $insertShopStmt->execute();
            }

            $insertShopStmt->close();
            $shopStmt->close();

            $successMessage = "Your custom request has been sent to repair shops.";
        } else {
            $errorMessage = "Something went wrong saving your request. Please try again.";
        }
    }
}

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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Custom repair request – Autoremz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="page">
    <header>
      <div class="brand">
        <a href="index.php">
          <img src="brand_logo" alt="Autoremz logo" class="logo" />
        </a>
        <div class="brand-text">
          <strong>Autoremz – Custom repair request</strong>
          <small>Describe your repair and get offers from shops</small>
        </div>
      </div>

<div class="header-actions">
  <a href="index.php" class="btn btn-ghost">
    Back to home
  </a>

  <a href="my-reservations.php" class="btn btn-ghost">
    My reservations
    <?php if (!empty($resAlertCount)): ?>
      <span class="nav-badge"><?php echo $resAlertCount; ?></span>
    <?php endif; ?>
  </a>

  <a href="my-quotes.php" class="btn btn-ghost">
    My custom requests
    <?php if (!empty($quoteAlertCount)): ?>
      <span class="nav-badge"><?php echo $quoteAlertCount; ?></span>
    <?php endif; ?>
  </a>

  <a href="user-logout.php" class="btn btn-ghost">
    Log out
  </a>
</div>


    </header>

    <main class="dashboard-layout">
      <h1 class="dashboard-title">
        Request a custom repair quote
      </h1>
      <p class="dashboard-subtitle">
        Tell nearby auto shops what you need help with. They can reply with questions, estimates, and availability.
      </p>

      <section class="dashboard-card">
        <?php if ($successMessage): ?>
          <p class="dashboard-message" style="color:#16a34a;">
            <?php echo htmlspecialchars($successMessage); ?>
          </p>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
          <p class="dashboard-message" style="color:#b91c1c;">
            <?php echo htmlspecialchars($errorMessage); ?>
          </p>
        <?php endif; ?>

        <form class="dashboard-form" action="custom-quote.php" method="POST">
          <label>
            <span>Short title (what needs repair?)</span>
            <input type="text"
                   name="title"
                   required
                   placeholder="Example: Front bumper damage, 2018 Honda Civic">
          </label>

          <label>
            <span>City / area</span>
            <input type="text"
                   name="city"
                   placeholder="Example: Boston, MA">
          </label>

          <label>
            <span>Describe the issue in detail</span>
            <textarea name="details"
                      rows="5"
                      placeholder="Describe the symptoms, when it happens, any warning lights, photos you have, etc."
                      style="resize:vertical;"></textarea>
          </label>

          <label>
            <span>What type of work does this sound like? (optional)</span>
          </label>
          <div class="service-list">
            <?php foreach ($KEYWORD_OPTIONS as $kw): ?>
              <label class="service-row" style="grid-template-columns:auto 1fr;">
                <input type="checkbox" name="keywords[]"
                       value="<?php echo htmlspecialchars($kw); ?>">
                <span class="service-label"><?php echo htmlspecialchars($kw); ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <button type="submit" class="btn btn-primary dashboard-submit">
            Send request to shops
          </button>
        </form>
      </section>
    </main>
  </div>
</body>
</html>
