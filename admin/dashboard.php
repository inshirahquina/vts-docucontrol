<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');

 $total_files = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
 $borrowed = $pdo->query("SELECT COUNT(*) FROM files WHERE status='borrowed'")->fetchColumn();
 $pending = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='pending'")->fetchColumn();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <div class="stats-grid">
                <div class="stat-card blue">
                    <h3>Total Files</h3>
                    <div class="value"><?= number_format($total_files) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Documents in archive</div>
                </div>
                <div class="stat-card red">
                    <h3>Borrowed Out</h3>
                    <div class="value"><?= number_format($borrowed) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Currently with staff</div>
                </div>
                <div class="stat-card green">
                    <h3>Pending Approvals</h3>
                    <div class="value"><?= number_format($pending) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Requires action</div>
                </div>
            </div>

            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                    <h3>Recent Activity</h3>
                    <a href="reports.php" style="font-size: 0.85rem; color: var(--accent); text-decoration: none;">View All Logs &rarr;</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $logs = $pdo->query("SELECT a.*, u.full_name FROM audit_logs a JOIN users u ON a.user_id = u.id ORDER BY a.timestamp DESC LIMIT 7");
                        if($logs->rowCount() > 0):
                            while($row = $logs->fetch()):
                        ?>
                        <tr>
                            <td><strong><?= sanitize($row['full_name']) ?></strong></td>
                            <td><?= sanitize($row['action']) ?></td>
                            <td style="color: var(--text-light); font-size: 0.85rem;"><?= $row['timestamp'] ?></td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="3" style="text-align:center; padding: 20px;">No recent activity found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>