<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';

if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'admin') {
    header("Location: ../admin/dashboard.php");
    exit();
}
require_once '../includes/header.php'; 

// Get User ID
 $uid = $_SESSION['user_id'];

// --- STATS FOR OPERATIONS ---

// Tasks assigned to ME for Retrieval (Pending)
 $retrieve_pending = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND current_status = 'Retrieval Assigned'");
 $retrieve_pending->execute([$uid]);
 $retrieve_count = $retrieve_pending->fetchColumn();

// Tasks assigned to ME for Restoration (Pending)
 $restore_pending = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND current_status = 'Restoration Assigned'");
 $restore_pending->execute([$uid]);
 $restore_count = $restore_pending->fetchColumn();

// Total tasks I completed today
 $completed_today = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND (current_status = 'File Retrieved' OR current_status = 'File Restored') AND DATE(retrieved_at) = CURDATE()");
 $completed_today->execute([$uid]);
 $completed_count = $completed_today->fetchColumn();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        
        <div class="content-area">
            
            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card orange">
                    <h3>Pending Retrievals</h3>
                    <div class="value"><?= number_format($retrieve_count) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Assigned to me</div>
                </div>
                <div class="stat-card green">
                    <h3>Pending Restorations</h3>
                    <div class="value"><?= number_format($restore_count) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Files to return</div>
                </div>
                <div class="stat-card blue">
                    <h3>Completed Today</h3>
                    <div class="value"><?= number_format($completed_count) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">My performance</div>
                </div>
            </div>

            <!-- TASK LIST -->
            <div class="card">
                <h3>My Assigned Tasks</h3>
                <p style="color:var(--text-light); margin-bottom:15px;">
                    These tasks are assigned to you by Admin. Only you can complete them.
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>File / Requester</th>
                            <th>Task Type</th>
                            <th>Assigned Time</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get tasks assigned to this user
                        $sql = "SELECT r.*, f.file_name, u_req.full_name as requester_name, f.department
                                FROM requests r 
                                JOIN files f ON r.file_id = f.id 
                                JOIN users u_req ON r.user_id = u_req.id
                                WHERE r.assigned_to = ? 
                                AND r.current_status IN ('Retrieval Assigned', 'Restoration Assigned')
                                ORDER BY r.retrieval_assigned_at DESC";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$uid]);
                        
                        if($stmt->rowCount() > 0):
                            while($row = $stmt->fetch()):
                        ?>
                        <tr>
                            <td>
                                <strong><?= sanitize($row['file_name']) ?></strong><br>
                                <small><?= sanitize($row['department']) ?> &bull; Req: <?= sanitize($row['requester_name']) ?></small>
                            </td>
                            <td>
                                <?php if($row['current_status'] == 'Retrieval Assigned'): ?>
                                    <span class="badge" style="background:#fff3cd; color:#856404;">Retrieve File</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#d1ecf1; color:#0c5460;">Restore File</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('H:i', strtotime($row['retrieval_assigned_at'])) ?></td>
                            <td>
                                <?php if($row['current_status'] == 'Retrieval Assigned'): ?>
                                    <form method="POST" action="../actions/request_actions.php">
                                        <input type="hidden" name="action" value="confirm_retrieval">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:orange; color:white; padding:5px 10px;">Confirm Retrieved</button>
                                    </form>
                                <?php elseif($row['current_status'] == 'Restoration Assigned'): ?>
                                    <form method="POST" action="../actions/request_actions.php">
                                        <input type="hidden" name="action" value="confirm_restoration">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:green; color:white; padding:5px 10px;">Confirm Restored</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="4" style="text-align:center; padding:20px;">No pending tasks assigned to you.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>