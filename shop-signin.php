<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// DATABASE SETTINGS
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: your DB username
$password   = "HostingerDBpinetree90601@";   // TODO: your DB password
$dbname     = "u138912455_autoremz_db";        // TODO: your DB name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error    = "";
$loggedIn = false;
$shopRow  = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim($_POST["email"] ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($email === "" || $password === "") {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, shop_name, email, password_hash FROM shops WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($id, $shop_name, $dbEmail, $passwordHash);
            $stmt->fetch();

            if (password_verify($password, $passwordHash)) {
                $_SESSION["shop_id"]    = $id;
                $_SESSION["shop_email"] = $dbEmail;
                $_SESSION["shop_name"]  = $shop_name;
                $loggedIn = true;
                $shopRow  = [
                    "id"        => $id,
                    "shop_name" => $shop_name,
                    "email"     => $dbEmail,
                ];
            } else {
                $error = "Incorrect password.";
            }
        } else {
            $error = "Shop not found.";
        }

        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Shop sign in – Autoremz</title>
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
        <a href="shop-signup.php" class="btn btn-ghost">
          List your shop
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

        <?php if ($loggedIn && $shopRow): ?>
          <h1 class="auth-title">Welcome, <?php echo htmlspecialchars($shopRow["shop_name"]); ?> 👋</h1>
          <p class="auth-subtitle">
            You are now signed in as <strong><?php echo htmlspecialchars($shopRow["email"]); ?></strong>.
          </p>
          <a href="shop-dashboard.php" class="btn btn-primary auth-submit" style="margin-top:16px; display:inline-flex; justify-content:center; width:100%;">
            Go to dashboard
          </a>
        <?php else: ?>
          <h1 class="auth-title">Shop sign in</h1>
          <p class="auth-subtitle">
            Sign in to manage your Autoremz shop profile.
          </p>

          <form class="auth-form" action="shop-signin.php" method="POST">
            <label>
              <span>Shop email</span>
              <input type="email" name="email" required placeholder="shop@example.com">
            </label>

            <label>
              <span>Password</span>
              <input type="password" name="password" required placeholder="••••••••">
            </label>

            <button type="submit" class="btn btn-primary auth-submit">
              Sign in
            </button>
          </form>

          <p class="auth-footer-text">
            New to Autoremz?
            <a href="shop-signup.php" class="auth-link">List your shop</a>
          </p>
        <?php endif; ?>
      </section>
    </main>
  </div>
</body>
</html>
