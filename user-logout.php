<?php
session_start();
unset($_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['user_name']);
header('Location: index.php');
exit;
