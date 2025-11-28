<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

header("Content-Type: application/json");

// Make sure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Not authenticated"]);
    exit;
}

// Database connection
$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Connection failed: " . $conn->connect_error]);
    exit;
}

// Fetch user ID from session
$userId = $_SESSION['user_id'];

// Delete user from the `users` table
$stmtUser = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmtUser->bind_param("i", $userId);

if ($stmtUser->execute()) {

    // Delete associated cars
    $stmtCars = $conn->prepare("DELETE FROM cars WHERE user_id = ?");
    $stmtCars->bind_param("i", $userId);
    $stmtCars->execute();

    session_unset();
    session_destroy();

    echo json_encode(["success" => true, "message" => "Account deleted successfully"]);

} else {
    echo json_encode(["success" => false, "message" => "Error deleting account"]);
}

$stmtUser->close();
$conn->close();
?>