<?php
require_once '../config/db.php';

/**
 * Inserts a notification into the database.
 * 
 * @param int    $user_id  The ID of the user to notify.
 * @param string $message  The notification text.
 * @param string $link     The link to redirect to when clicked.
 */
function sendNotification($pdo, $user_id, $message, $link) {
    $sql = "INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $message, $link]);
}
?>