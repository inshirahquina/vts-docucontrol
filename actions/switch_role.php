<?php
require_once '../config/db.php';
require_once '../config/functions.php';
session_start(); // pastikan session start

// 1. Check new role
if (isset($_POST['new_role']) && isset($_SESSION['user_id'])) {
    $new_role = $_POST['new_role'];
    $user_id = $_SESSION['user_id'];

    // 2. Update session
    $_SESSION['active_role'] = $new_role;

    // 3. Update database so it's persistent
    $stmt = $pdo->prepare("UPDATE users SET active_role = ? WHERE id = ?");
    $stmt->execute([$new_role, $user_id]);
}

// 4. Redirect back to previous page
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../staff/dashboard.php';
header("Location: $referer");
exit;
?>
