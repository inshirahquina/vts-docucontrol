<?php
require_once '../config/db.php';
require_once '../config/functions.php';
session_start();

// Security Check
if (!isLoggedIn()) {
    redirect('../index.php');
}

if (isset($_POST['new_role'])) {
    $newRole = $_POST['new_role'];
    $userId = $_SESSION['user_id'];

    // 1. Update the SESSION (Immediate effect)
    $_SESSION['active_role'] = $newRole;

    // 2. Update the DATABASE (Persistent effect)
    $stmt = $pdo->prepare("UPDATE users SET active_role = ? WHERE id = ?");
    $stmt->execute([$newRole, $userId]);
}

// 3. Redirect back to the page the user is currently on
// Instead of forcing them to admin/dashboard.php, we send them back.
header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
?>