<?php
// Show errors while developing (can be turned off later)
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

// Connect to MySQL
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error   = "";
$success = "";

// ----------------------
// HANDLE FORM SUBMIT
// ----------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name            = isset($_POST["name"]) ? trim($_POST["name"]) : "";
    $email           = isset($_POST["email"]) ? trim($_POST["email"]) : "";
    $password        = isset($_POST["password"]) ? trim($_POST["password"]) : "";
    $confirmPassword = isset($_POST["confirm_password"]) ? trim($_POST["confirm_password"]) : "";

    // Basic validation
    if ($name === "" || $email === "" || $password === "" || $confirmPassword === "") {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password should be at least 6 characters.";
    } else {
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $error = "An account with that email already exists.";
        } else {
            // Insert new user
            // NOTE: For now we store plain text password to match your current login logic.
         $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

         $insertStmt = $conn->prepare("INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)");
         $insertStmt->bind_param("sss", $email, $hashedPassword, $name);


            if ($insertStmt->execute()) {
                $success = "Account created successfully! You can now sign in.";
            } else {
                $error = "Error creating account. Please try again.";
            }

            $insertStmt->close();
        }

        $checkStmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Sign up – Autoremz</title>
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
        <a href="signin.html" class="btn btn-ghost">
          ← Back to sign in
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

        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle">
          Sign up to save your quotes and repair history.
        </p>

        <form class="auth-form" action="signup.php" method="POST">
          <label>
            <span>Full name</span>
            <input type="text" name="name" required placeholder="Jane Doe"
                   value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
          </label>

          <label>
            <span>Email</span>
            <input type="email" name="email" required placeholder="you@example.com"
                   value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
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
            Create account
          </button>
        </form>

        <p class="auth-footer-text">
          Already have an account?
          <a href="signin.html" class="auth-link">Sign in</a>
        </p>
      </section>
    </main>
  </div>
</body>
</html>
