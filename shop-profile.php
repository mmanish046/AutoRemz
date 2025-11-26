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

$shopId = (int) $_SESSION["shop_id"];

// DATABASE SETTINGS
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$profileMessage = "";
$profileError   = "";

// Handle POST: update basic profile (shop_name, phone, city)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $shopName = trim($_POST["shop_name"] ?? "");
    $phone    = trim($_POST["phone"]     ?? "");
    $city     = trim($_POST["city"]      ?? "");

    if ($shopName === "") {
        $profileError = "Shop name is required.";
    } else {
        $stmt = $conn->prepare("
            UPDATE shops
            SET shop_name = ?, phone = ?, city = ?
            WHERE id = ?
        ");
        if (!$stmt) {
            $profileError = "Error preparing update. Please try again.";
        } else {
            $stmt->bind_param("sssi", $shopName, $phone, $city, $shopId);
            if ($stmt->execute()) {
                $profileMessage = "Profile updated successfully.";
            } else {
                $profileError = "Error updating profile. Please try again.";
            }
            $stmt->close();
        }
    }
}

// Fetch latest shop data (to fill the form)
$stmt = $conn->prepare("
    SELECT shop_name, email, phone, city
    FROM shops
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $shopId);
$stmt->execute();
$result = $stmt->get_result();
$shop   = $result->fetch_assoc();
$stmt->close();

$shopName = $shop["shop_name"] ?? "";
$email    = $shop["email"]     ?? "";
$phone    = $shop["phone"]     ?? "";
$city     = $shop["city"]      ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit shop profile – AutoRemz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="page">
    <header>
      <div class="brand">
        <img src="brand_logo" alt="Autoremz logo" class="logo" />
        <div class="brand-text">
          <strong>Shop profile</strong>
          <small>Update your basic shop information</small>
        </div>
      </div>

      <div class="header-actions">
        <a href="shop-dashboard.php" class="btn btn-ghost">← Back to dashboard</a>
        <a href="shop-logout.php" class="btn btn-ghost">Log out</a>
      </div>
    </header>

    <main class="dashboard-layout">
      <section class="dashboard-card">
        <h2 class="dashboard-section-title">
          Edit profile for <?php echo htmlspecialchars($shopName); ?>
        </h2>
        <p class="dashboard-subtitle">
          This information is visible to users when they view your shop.
        </p>

        <?php if ($profileMessage): ?>
          <p class="dashboard-message" style="color:#16a34a;">
            <?php echo htmlspecialchars($profileMessage); ?>
          </p>
        <?php endif; ?>

        <?php if ($profileError): ?>
          <p class="dashboard-message" style="color:#b91c1c;">
            <?php echo htmlspecialchars($profileError); ?>
          </p>
        <?php endif; ?>

        <form class="dashboard-form" action="shop-profile.php" method="POST">
          <label>
            <span>Shop name</span>
            <input
              type="text"
              name="shop_name"
              required
              value="<?php echo htmlspecialchars($shopName); ?>"
              placeholder="Example: Worcester Auto Repair"
            >
          </label>

          <label>
            <span>Public contact email (read-only)</span>
            <input
              type="email"
              value="<?php echo htmlspecialchars($email); ?>"
              disabled
            >
            <span style="font-size:11px; color:#9ca3af;">
              This is your login email. If you need to change it, contact support or update in a future settings page.
            </span>
          </label>

          <label>
            <span>Phone</span>
            <input
              type="tel"
              name="phone"
              value="<?php echo htmlspecialchars($phone); ?>"
              placeholder="(123) 456-7890"
            >
          </label>

          <label>
            <span>City / Area</span>
            <input
              type="text"
              name="city"
              value="<?php echo htmlspecialchars($city); ?>"
              placeholder="Worcester, MA"
            >
          </label>

          <button type="submit" class="btn btn-primary dashboard-submit">
            Save profile
          </button>
        </form>
      </section>
    </main>
  </div>
</body>
</html>
