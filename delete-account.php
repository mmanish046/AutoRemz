<?php
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

session_start();

//Make sure user is logged in
if (!isset($_SESSION['user_id'])) {
	echo json_encode(["success" => false, "nessage" => "Not authenticated"]);
	exit;
}

$input = json_decode(file_get_contents("user-dashboard.php"), true);

if (!isset($input['delete'])) {
	echo json_encode(["success" => false, "message" => "Invalid request"]);
	exit;
}

$userId - $_SESSION['user_id'];

try {
	//Database connection
	$pdo = new PDO("mysql:host=localhost;dbname=u138912455_autoremz_db", "u138912455_autoremz_user", "HostingerDBpinetree90601@");
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	//Delete user from DB
	$stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
	$stmt->bindParam(":id", $userId, PDO::PARAM_INT);
	$stmt->execute();

	//Destroy session
	session_destroy();

	echo json_encode(["success" => true]);

} catch (Exception $e) {
	echo json_encode(["success" => false, "message" => $e->getMessage()]);

}
?>

