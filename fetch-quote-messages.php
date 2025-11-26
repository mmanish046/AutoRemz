<?php
session_start();

$relId = isset($_GET['rel_id']) ? (int)$_GET['rel_id'] : 0;
if ($relId <= 0) {
    exit;
}

$servername = "localhost";
$username   = "u138912455_autoremz_user";
$password   = "HostingerDBpinetree90601@";
$dbname     = "u138912455_autoremz_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    exit("DB error");
}

function nice_dt($dt) {
    $ts = strtotime($dt);
    return date("M j, Y g:ia", $ts);
}

$sql = "SELECT * FROM quote_messages WHERE quote_rel_id = ? ORDER BY created_at ASC, id ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $relId);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo '<p style="font-size:13px; color:#9ca3af;">No messages yet. Start the conversation below.</p>';
} else {
    while ($m = $res->fetch_assoc()) {
        if ($m['sender_type'] === 'user') {
            ?>
            <div class="msg-user">
                <?= nl2br(htmlspecialchars($m['message'])) ?>
                <div class="msg-time"><?= nice_dt($m['created_at']) ?></div>
            </div>
            <?php
        } else {
            ?>
            <div class="msg-shop">
                <?= nl2br(htmlspecialchars($m['message'])) ?>
                <div class="msg-time"><?= nice_dt($m['created_at']) ?></div>
            </div>
            <?php
        }
    }
}

$stmt->close();
$conn->close();
