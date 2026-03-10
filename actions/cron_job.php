<?php
require_once '../config/db.php';
require_once '../config/functions.php';

# =================================================
# 1. REMINDER - 1 DAY BEFORE DUE DATE
# =================================================

$stmt = $pdo->query("
SELECT r.id, u.email, u.full_name, f.file_name, r.due_date
FROM requests r
JOIN users u ON r.user_id = u.id
JOIN files f ON r.file_id = f.id
WHERE r.current_status = 'Released'
AND DATE(r.due_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
AND r.reminder_sent = 0
");

while($row = $stmt->fetch(PDO::FETCH_ASSOC)){

    $subject = "Reminder: File Due Tomorrow";

    $message = "
    Dear {$row['full_name']},<br><br>

    This is a friendly reminder that the following file must be returned tomorrow.<br><br>

    <b>File:</b> {$row['file_name']}<br>
    <b>Due Date:</b> {$row['due_date']}<br><br>

    Kindly return the file to the archive counter.<br><br>

    Regards,<br>
    VTS e-Library System
    ";

    sendEmail($row['email'],$subject,$message);

    # mark reminder as sent
    $pdo->prepare("
        UPDATE requests
        SET reminder_sent = 1
        WHERE id = ?
    ")->execute([$row['id']]);
}

# =================================================
# 2. OVERDUE FILE CHECK
# =================================================

$stmt = $pdo->query("
SELECT r.id, u.email, u.full_name, f.file_name, r.due_date
FROM requests r
JOIN users u ON r.user_id = u.id
JOIN files f ON r.file_id = f.id
WHERE r.current_status = 'Released'
AND r.due_date < CURDATE()
AND r.status != 'overdue'
");

while($row = $stmt->fetch(PDO::FETCH_ASSOC)){

    $subject = "OVERDUE: File Must Be Returned Immediately";

    $message = "
    Dear {$row['full_name']},<br><br>

    The following file is now <b>OVERDUE</b> and should be returned immediately.<br><br>

    <b>File:</b> {$row['file_name']}<br>
    <b>Due Date:</b> {$row['due_date']}<br><br>

    Please return the file to the archive as soon as possible.<br><br>

    Regards,<br>
    VTS e-Library System
    ";

    sendEmail($row['email'],$subject,$message);

    # update request status to overdue
    $pdo->prepare("
        UPDATE requests
        SET status = 'overdue'
        WHERE id = ?
    ")->execute([$row['id']]);
}
?>