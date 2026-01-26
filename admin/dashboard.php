<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';

// --- LOGIC TO HANDLE ROLE SWITCHING ---
// If the user switched to 'operations' role, redirect them to the staff dashboard.
if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'operations') {
    header("Location: ../staff/dashboard.php");
    exit();
}

require_once '../includes/header.php'; 

// 1. TOTAL FILES
 $total_files = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();

// 2. ACTIVE REQUESTS (Files inside the workflow)
 $active_requests = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status IN ('Requested', 'Retrieval Assigned', 'File Retrieved', 'Released', 'Return Requested', 'Restoration Assigned')")->fetchColumn();

// 3. PENDING ADMIN APPROVALS (Items stuck at 'Requested' or 'Return Requested' needing Admin Assignment)
 $pending_approvals = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status IN ('Requested', 'Return Requested')")->fetchColumn();

?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <div class="stats-grid">
                <div class="stat-card blue">
                    <h3>Total Archive</h3>
                    <div class="value"><?= number_format($total_files) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Total Documents in system</div>
                </div>
                
                <div class="stat-card orange">
                    <h3>Active Workflow</h3>
                    <div class="value"><?= number_format($active_requests) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Files in circulation/retrieval</div>
                </div>

                <div class="stat-card red">
                    <h3>Pending Approval</h3>
                    <div class="value"><?= number_format($pending_approvals) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Awaiting Admin Assignment</div>
                </div>
            </div>

            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                    <h3>Recent Audit Activity</h3>
                    <a href="reports.php" style="font-size: 0.85rem; color: var(--accent); text-decoration: none;">View Full Log &rarr;</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $logs = $pdo->query("SELECT a.*, u.full_name, u.role as user_role 
                                             FROM audit_logs a 
                                             JOIN users u ON a.user_id = u.id 
                                             ORDER BY a.timestamp DESC LIMIT 7");
                        if($logs->rowCount() > 0):
                            while($row = $logs->fetch()):
                                
                                // Decode JSON details for better readability
                                $details = json_decode($row['details'], true);
                                $detailText = '';
                                if ($details && isset($details['status'])) {
                                    $detailText = "Status: <strong>" . htmlspecialchars($details['status']) . "</strong>";
                                    if(isset($details['role'])) {
                                        $detailText .= " <span style='font-size:0.8em; color:#888;'>(" . htmlspecialchars($details['role']) . ")</span>";
                                    }
                                } else {
                                    $detailText = '<span style="color:#999;">System Action</span>';
                                }
                        ?>
                        <tr>
                            <td>
                                <strong><?= sanitize($row['full_name']) ?></strong><br>
                                <span style="font-size:0.75rem; color:#888; text-transform:uppercase;"><?= ucfirst($row['user_role']) ?></span>
                            </td>
                            <td><?= sanitize($row['action']) ?></td>
                            <td><?= $detailText ?></td>
                            <td style="color: var(--text-light); font-size: 0.85rem;"><?= date('M j, H:i', strtotime($row['timestamp'])) ?></td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="4" style="text-align:center; padding: 20px;">No recent activity found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>