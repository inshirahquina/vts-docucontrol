<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if (!isLoggedIn()) {
    die("Access Denied");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $user_id = $_SESSION['user_id'];

    // 1. Staff Request Borrow
    if ($action == 'request_borrow') {
        $barcode = trim($_POST['barcode_input']);
        
        $stmt = $pdo->prepare("SELECT id FROM files WHERE (barcode = ? OR file_number = ?) AND status = 'available'");
        $stmt->execute([$barcode, $barcode]);
        $file = $stmt->fetch();

        if ($file) {
            // Check if already requested
            $check = $pdo->prepare("SELECT id FROM transactions WHERE file_id = ? AND user_id = ? AND status = 'pending'");
            $check->execute([$file['id'], $user_id]);
            if($check->rowCount() > 0){
                echo "<script>alert('Already requested this file.'); window.history.back();</script>";
                exit;
            }

            $borrow_date = date('Y-m-d');
            $due_date = date('Y-m-d', strtotime('+7 days')); 
            
            $ins = $pdo->prepare("INSERT INTO transactions (file_id, user_id, borrow_date, due_date, status) VALUES (?, ?, ?, ?, 'pending')");
            $ins->execute([$file['id'], $user_id, $borrow_date, $due_date]);
            
            // --- NEW: SEND NOTIFICATION TO ALL ADMINS ---
            $message = "New borrow request for File ID: {$file['id']}";
            $link = "admin/approvals.php";
            
            // Find all admin IDs
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin'");
            while($admin = $admins->fetch()){
                $notif = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                $notif->execute([$admin['id'], $message, $link]);
            }
            // ------------------------------------------------

            echo "<script>alert('Request sent to Admin.'); window.location.href='../staff/dashboard.php';</script>";

        } else {
            echo "<script>alert('File not found or not available.'); window.history.back();</script>";
        }
    }

    // 2. Admin Return File
    if ($action == 'return_file' && isAdmin()) {
        $trans_id = $_POST['trans_id'];
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE transactions SET return_date = CURDATE(), status = 'returned' WHERE id = ?");
            $stmt->execute([$trans_id]);
            
            $stmt = $pdo->prepare("UPDATE files SET status = 'available' WHERE id = (SELECT file_id FROM transactions WHERE id = ?)");
            $stmt->execute([$trans_id]);
            
            $pdo->commit();
            echo "<script>alert('File Returned Successfully'); window.location.href='../admin/approvals.php';</script>";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "Error: " . $e->getMessage();
        }
    }
}
?>