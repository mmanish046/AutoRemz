<?php
session_start();

// Only clear shop session data (optional: clear all)
unset($_SESSION["shop_id"], $_SESSION["shop_email"], $_SESSION["shop_name"]);

// If you want to clear everything, you can also:
// session_unset();
// session_destroy();

// Redirect to homepage or shop sign-in
header("Location: index.php");
exit;
