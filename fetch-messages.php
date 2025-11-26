<?php
session_start();

// DB connection
// DATABASE SETTINGS
$servername = "localhost";
$username   = "u138912455_autoremz_user";      // TODO: your DB username
$password   = "HostingerDBpinetree90601@";   // TODO: your DB password
$dbname     = "u138912455_autoremz_db";        // TODO: your DB name

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    exit("DB error");
}

// Reservation being viewed
$reservationId = (int)($_GET["id"] ?? 0);

$sql = "
    SELECT sender_type, message, created_at
    FROM reservation_messages
    WHERE reservation_id = ?
    ORDER BY created_at ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $reservationId);
$stmt->execute();
$result = $stmt->get_result();

function nice_dt($dt) {
    return date("M j, Y g:ia", strtotime($dt));
}

// OUTPUT ONLY the message HTML — NO <html>, NO <body>, NO CSS
while ($m = $result->fetch_assoc()):
    if ($m["sender_type"] === "user"): ?>

        <div class="msg-user">
            <?= nl2br(htmlspecialchars($m["message"])) ?>
            <div class="msg-time"><?= nice_dt($m["created_at"]) ?></div>
        </div>

    <?php else: ?>

        <div class="msg-shop">
            <?= nl2br(htmlspecialchars($m["message"])) ?>
            <div class="msg-time"><?= nice_dt($m["created_at"]) ?></div>
        </div>

    <?php endif;
endwhile;

$stmt->close();
$conn->close();
?>
