<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if(!isAdmin()) {
    header("Location: ../index.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $file_id = $_POST['file_id'];
    
    // Helper function to log history
    function logHistory($pdo, $fileId, $actionType, $performedBy) {
        $sql = "INSERT INTO file_history (file_id, action, performed_by) VALUES (?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fileId, $actionType, $performedBy]);
    }

    if($action == 'delete_file') {
        // Deleting the file will automatically cascade delete history due to Foreign Key
        $stmt = $pdo->prepare("DELETE FROM files WHERE id = ?");
        $stmt->execute([$file_id]);
        header("Location: ../files.php?msg=deleted");
    }

    if($action == 'borrow_file') {
        $borrowed_by = $_POST['borrowed_by']; // The person borrowing
        
        // 1. Update File Status
        $sql = "UPDATE files 
                SET status = 'borrowed', 
                    borrowed_by = ?, 
                    borrowed_at = NOW(), 
                    returned_at = NULL 
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$borrowed_by, $file_id]);

        // 2. Log to History
        logHistory($pdo, $file_id, 'Borrowed by ' . $borrowed_by, 'Admin');

        header("Location: ../files.php?msg=borrowed");
    }

    if($action == 'return_file') {
        // 1. Update File Status
        $sql = "UPDATE files 
                SET status = 'available', 
                    borrowed_by = NULL, 
                    returned_at = NOW() 
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$file_id]);

        // 2. Log to History
        logHistory($pdo, $file_id, 'Returned to Shelf', 'Admin');

        header("Location: ../files.php?msg=returned");
    }
}
?>