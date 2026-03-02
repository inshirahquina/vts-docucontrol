<?php
require_once '../config/db.php';
require_once '../config/functions.php';

// Security Check
if (!isLoggedIn()) {
    redirect('../index.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_role'])) {
    
    $newRole = $_POST['new_role'];
    $userId = $_SESSION['user_id'];
    $baseRole = $_SESSION['role']; // e.g., 'hod', 'admin', 'staff'

    // Validate: Ensure the user is allowed to switch to this role
    $allowed = false;

    if ($baseRole == 'admin') {
        // Admin can switch to admin or operations
        if ($newRole == 'admin' || $newRole == 'operations') $allowed = true;
    
    } elseif ($baseRole == 'operations') {
        // Staff can switch to admin or operations
        if ($newRole == 'admin' || $newRole == 'operations') $allowed = true;
    
    } elseif ($baseRole == 'hod') {
        // HOD can switch to hod or requestor
        if ($newRole == 'hod' || $newRole == 'requestor') $allowed = true;
    }

    if ($allowed) {
        // 1. Update Database
        $stmt = $pdo->prepare("UPDATE users SET active_role = ? WHERE id = ?");
        $stmt->execute([$newRole, $userId]);

        // 2. Update Session
        $_SESSION['active_role'] = $newRole;

        // 3. Redirect Logic
        if ($newRole == 'hod') {
            redirect('../hod/dashboard.php');
        } elseif ($newRole == 'requestor') {
            redirect('../requestor/dashboard.php');
        } elseif ($newRole == 'admin') {
            redirect('../admin/dashboard.php');
        } elseif ($newRole == 'operations') {
            redirect('../operations/dashboard.php');
        }
    }
}

redirect('../index.php');
?>