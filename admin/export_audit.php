<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if(!isAdmin()) exit;

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="audit_log_'.date('Y-m-d').'.csv"');

 $output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Timestamp', 'User', 'Action', 'Details (JSON)']);

 $sql = "SELECT al.*, u.full_name FROM audit_logs al JOIN users u ON al.user_id = u.id ORDER BY al.id DESC";
 $stmt = $pdo->query($sql);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [$row['id'], $row['timestamp'], $row['full_name'], $row['action'], $row['details']]);
}

fclose($output);
exit;
?>