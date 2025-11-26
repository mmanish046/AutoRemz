<?php
// Show errors during development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ----------------------
// DATABASE SETTINGS
// ----------------------
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: your DB username
$password   = "HostingerDBpinetree90601@";   // TODO: your DB password
$dbname     = "u138912455_autoremz_db";        // TODO: your DB name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error   = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $shop_name = trim($_POST["shop_name"] ?? "");
    $email     = trim($_POST["email"] ?? "");
    $password  = trim($_POST["password"] ?? "");
    $confirm   = trim($_POST["confirm_password"] ?? "");
    $phone     = trim($_POST["phone"] ?? "");
    $city      = trim($_POST["city"] ?? "");
    $services  = trim($_POST["services"] ?? "");

    if ($shop_name === "" || $email === "" || $password === "" || $confirm === "") {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password should be at least 6 characters.";
    } else {
        // Check if email already exists
        $check = $conn->prepare("SELECT id FROM shops WHERE email = ? LIMIT 1");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "A shop with that email already exists.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $insert = $conn->prepare("
                INSERT INTO shops (shop_name, email, password_hash, phone, city, services)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->bind_param("ssssss", $shop_name, $email, $hashedPassword, $phone, $city, $services);

            if ($insert->execute()) {
                $success = "Shop account created! You can now sign in.";
            } else {
                $error = "Error creating shop. Please try again.";
            }

            $insert->close();
        }

        $check->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>List your shop – Autoremz</title>
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
      </div>

      <div class="header-actions">
        <a href="shop-signin.php" class="btn btn-ghost">
          Shop sign in
        </a>
      </div>
    </header>

    <main class="auth-layout">
      <section class="auth-card">
        <?php if (!empty($error)): ?>
          <p style="color: #ef4444; font-size: 14px; margin-top: 0; margin-bottom: 12px;">
            <?php echo htmlspecialchars($error); ?>
          </p>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
          <p style="color: #16a34a; font-size: 14px; margin-top: 0; margin-bottom: 12px;">
            <?php echo htmlspecialchars($success); ?>
          </p>
        <?php endif; ?>

        <h1 class="auth-title">List your auto repair shop</h1>
        <p class="auth-subtitle">
          Get matched with drivers who need exactly the services you offer.
        </p>

        <form class="auth-form" action="shop-signup.php" method="POST">
          <label>
            <span>Shop name</span>
            <input type="text" name="shop_name" required placeholder="Autoremz Garage"
                   value="<?php echo isset($shop_name) ? htmlspecialchars($shop_name) : ''; ?>">
          </label>

          <label>
            <span>Shop email (for login)</span>
            <input type="email" name="email" required placeholder="shop@example.com"
                   value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
          </label>

          <label>
            <span>Phone</span>
            <input type="text" name="phone" placeholder="(555) 123-4567"
                   value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>">
          </label>

          <label>
            <span>City / Area</span>
            <input type="text" name="city" placeholder="Boston, MA"
                   value="<?php echo isset($city) ? htmlspecialchars($city) : ''; ?>">
          </label>

          <label>
            <span>Services you offer</span>
            <textarea name="services" placeholder="Brakes, oil change, tires, diagnostics..." rows="3"><?php
              echo isset($services) ? htmlspecialchars($services) : '';
            ?></textarea>
          </label>

          <label>
            <span>Password</span>
            <input type="password" name="password" required placeholder="Choose a password">
          </label>

          <label>
            <span>Confirm password</span>
            <input type="password" name="confirm_password" required placeholder="Repeat your password">
          </label>

          <button type="submit" class="btn btn-primary auth-submit">
            Create shop account
          </button>
        </form>

        <p class="auth-footer-text">
          Already listed?
          <a href="shop-signin.php" class="auth-link">Sign in as a shop</a>
        </p>
      </section>
    </main>
  </div>
</body>
</html>
