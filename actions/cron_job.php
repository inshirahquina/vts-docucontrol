<?php
require_once '../config/db.php';

// 1. Check for Overdue (Day 3+)
 $overdue = $pdo->query("SELECT t.id, t.user_id, u.email, u.full_name, f.file_name 
                        FROM transactions t 
                        JOIN users u ON t.user_id = u.id 
                        JOIN files f ON t.file_id = f.id 
                        WHERE t.status = 'active' AND t.due_date < CURDATE()");

while($row = $overdue->fetch()) {
    // Send email logic here (e.g., using PHP mailer)
    $subject = "OVERDUE NOTICE: File {$row['file_name']}";
    $msg = "Dear {$row['full_name']}, The file {$row['file_name']} is overdue.";
    // mail($row['email'], $subject, $msg);
    
    // Log internal notification
    $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")
        ->execute([$row['user_id'], "OVERDUE: File {$row['file_name']} must be returned immediately."]);
        
    // Update status in DB
    $pdo->prepare("UPDATE transactions SET status = 'overdue' WHERE id = ?")->execute([$row['id']]);
}

// 2. Check for Day 2 Reminder
 $reminder = $pdo->query("SELECT t.id, t.user_id, u.email, f.file_name 
                         FROM transactions t 
                         JOIN users u ON t.user_id = u.id 
                         JOIN files f ON t.file_id = f.id 
                         WHERE t.status = 'active' AND t.due_date = CURDATE() + INTERVAL 2 DAY");

while($row = $reminder->fetch()) {
    $subject = "Reminder: Return File {$row['file_name']}";
    $msg = "Due in 2 days.";
    // mail($row['email'], $subject, $msg);
}
?>