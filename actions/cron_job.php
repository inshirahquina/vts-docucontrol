<?php

require_once '../config/db.php';
require_once '../config/functions.php';

file_put_contents(
    __DIR__ . '/cron_test.txt',
    date('Y-m-d H:i:s') . PHP_EOL,
    FILE_APPEND
);
# =================================================
# 1. REMINDER - 1 DAY BEFORE DUE DATE
# =================================================

$stmt = $pdo->query("
SELECT r.id, u.email, u.full_name, f.file_name, r.due_date
FROM requests r
JOIN users u ON r.user_id = u.id
JOIN files f ON r.file_id = f.id
WHERE r.current_status = 'Released'
AND DATE(r.due_date) = DATE(DATE_ADD(CURDATE(), INTERVAL 1 DAY))
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

    error_log('[REMINDER] request=' . $row['id'] . ' email=[' . $row['email'] . ']');
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

error_log('entered overdue section');

$overdueSql = "
SELECT r.id, u.email, u.full_name, f.file_name, r.due_date, r.current_status, r.status
FROM requests r
JOIN users u ON r.user_id = u.id
JOIN files f ON r.file_id = f.id
WHERE r.current_status = 'Released'
AND DATE(r.due_date) < CURDATE()
AND COALESCE(r.status, '') <> 'overdue'
";

error_log('overdue SQL: ' . preg_replace('/\s+/', ' ', trim($overdueSql)));

try {
    $stmt = $pdo->query($overdueSql);
    $overdueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('PDO exception fetching overdue candidates: ' . $e->getMessage());
    $overdueRows = [];
}

error_log('exact SQL query result count: ' . count($overdueRows));

foreach ($overdueRows as $row) {

    error_log('processing request id: ' . $row['id']);
    error_log(
        'request values id=' . $row['id']
        . ' current_status=' . var_export($row['current_status'] ?? null, true)
        . ' status=' . var_export($row['status'] ?? null, true)
        . ' due_date=' . var_export($row['due_date'] ?? null, true)
    );

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

    error_log('[cron] var_export($row) = ' . var_export($row, true));
    error_log('[OVERDUE] request=' . $row['id'] . ' email=[' . $row['email'] . ']');
    error_log('[cron] calling sendEmail');
    $sent = sendEmail($row['email'], $subject, $message);
    error_log('[cron] sendEmail returned: ' . ($sent ? 'TRUE' : 'FALSE'));

    if ($sent) {
        $stmt = $pdo->prepare("
            UPDATE requests
            SET status = 'overdue'
            WHERE id = ?
        ");
        $ok = $stmt->execute([$row['id']]);
        error_log('[cron] UPDATE returned: ' . ($ok ? 'TRUE' : 'FALSE'));

        if (!$ok) {
            error_log(print_r($stmt->errorInfo(), true));
        }
    }
}

error_log('exited overdue section');
?>