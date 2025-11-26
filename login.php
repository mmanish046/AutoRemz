<?php
// Show errors so we don't get a blank page while debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ----------------------
// 1. DATABASE SETTINGS
// ----------------------
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: change to YOUR DB username
$password   = "HostingerDBpinetree90601@";   // TODO: change to YOUR DB password
$dbname     = "u138912455_autoremz_db";        // TODO: change to YOUR DB name

// ----------------------
// 2. CONNECT TO MYSQL
// ----------------------
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error    = "";
$loggedIn = false;

// ----------------------
// 3. HANDLE FORM SUBMIT
// ----------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = isset($_POST["email"]) ? trim($_POST["email"]) : "";
    $password = isset($_POST["password"]) ? trim($_POST["password"]) : "";

    if ($email === "" || $password === "") {
        $error = "Please enter both email and password.";
    } else {
        // Use prepared statement to prevent SQL injection
        $stmt = $conn->prepare(
            "SELECT id, email, password_hash, name FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            // Bind result columns
            $stmt->bind_result($id, $dbEmail, $passwordHash, $name);
            $stmt->fetch();

            if (password_verify($password, $passwordHash)) {
                // Success: store session & mark logged in
                $_SESSION["user_id"]    = $id;
                $_SESSION["user_email"] = $dbEmail;
                $_SESSION["user_name"]  = $name;
                $loggedIn = true;
            } else {
                $error = "Incorrect password.";
            }
        } else {
            $error = "User not found.";
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
  <title>Sign in – Autoremz</title>
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
        <a href="index.php" class="btn btn-ghost">
          ← Back to home
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

        <?php if ($loggedIn): ?>
          <h1 class="auth-title">Welcome back 👋</h1>
          <p class="auth-subtitle">
            You are now signed in as
            <strong><?php echo htmlspecialchars($_SESSION["user_email"]); ?></strong>.
          </p>
          <a href="index.php" class="btn btn-primary auth-submit" style="margin-top:16px; display:inline-flex; justify-content:center; width:100%;">
            Go to homepage
          </a>
        <?php else: ?>
          <h1 class="auth-title">Sign in to your account</h1>
          <p class="auth-subtitle">
            Use the email and password you created.
          </p>

          <form class="auth-form" action="login.php" method="POST">
            <label>
              <span>Email</span>
              <input type="email" name="email" required placeholder="you@example.com">
            </label>

            <label>
              <span>Password</span>
              <input type="password" name="password" required placeholder="••••••••">
            </label>

            <div class="auth-extra-row">
              <label class="auth-checkbox">
                <input type="checkbox" name="remember">
                <span>Remember me</span>
              </label>
              <a href="#" class="auth-link">Forgot password?</a>
            </div>

            <button type="submit" class="btn btn-primary auth-submit">
              Sign in
            </button>
          </form>

            <p class="auth-footer-text">
              Don’t have an account?
              <a href="signup.php" class="auth-link">Create one</a>
            </p>
        <?php endif; ?>
      </section>
    </main>
  </div>
</body>
</html>
