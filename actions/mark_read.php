<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if(!isLoggedIn()) exit;

 $uid = $_SESSION['user_id'];

// Mark all notifications for this user as read
 $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
 $stmt->execute([$uid]);

// Redirect back to the page they came from
header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?>