<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');

// CSV Export Logic
if(isset($_GET['export']) && $_GET['export'] == 'audit') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'User', 'Action', 'Timestamp']);
    $rows = $pdo->query("SELECT a.*, u.username FROM audit_logs a JOIN users u ON a.user_id = u.id ORDER BY a.timestamp DESC");
    while($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
?>

<!-- WRAPPER START -->
<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3>Audit Trail</h3>
                    <a href="?export=audit" class="btn">Export CSV</a>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql = "SELECT a.*, u.username FROM audit_logs a JOIN users u ON a.user_id = u.id ORDER BY a.timestamp DESC LIMIT 50";
                        $logs = $pdo->query($sql);
                        while($row = $logs->fetch()):
                        ?>
                        <tr>
                            <td style="font-size:0.85rem; color:var(--text-light);"><?= $row['timestamp'] ?></td>
                            <td><?= sanitize($row['username']) ?></td>
                            <td><?= sanitize($row['action']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>Most Borrowed Files</h3>
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Times Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stats = $pdo->query("SELECT f.file_name, COUNT(t.id) as borrow_count 
                                              FROM files f 
                                              JOIN transactions t ON f.id = t.file_id 
                                              GROUP BY f.id 
                                              ORDER BY borrow_count DESC 
                                              LIMIT 10");
                        while($row = $stats->fetch()):
                        ?>
                        <tr>
                            <td><?= sanitize($row['file_name']) ?></td>
                            <td><?= $row['borrow_count'] ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>