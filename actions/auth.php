<?php
require_once '../config/db.php';
require_once '../config/functions.php';

 $error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID for security
        session_regenerate_id();
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        
        // Audit Log
        $log = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, 'User Logged In')");
        $log->execute([$user['id']]);

        // Redirect based on role
        if ($user['role'] == 'admin') {
            header("Location: ../admin/dashboard.php");
        } else {
            header("Location: ../staff/dashboard.php");
        }
        exit;
    } else {
        // Redirect back with error or handle error here
        // For simplicity, we redirect back to index with a query param
        header("Location: ../index.php?error=invalid");
        exit;
    }
}
?>