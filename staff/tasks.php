<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php'; // Ensure this points to your Dynamic Header

// --- SECURITY CHECK ---
// Only allow access if Active Role is 'operations' (allows Staff acting as Ops, OR Admin acting as Ops)
if (!isLoggedIn()) {
    redirect('../index.php');
}

if (isset($_SESSION['role']) && $_SESSION['role'] !== 'requestor') {
    // If Active Role is NOT operations (or not set), block access
    // This ensures 'requestor' users are blocked
    if (!isset($_SESSION['active_role']) || $_SESSION['active_role'] !== 'operations') {
        redirect('../index.php');
    }
}

 $uid = $_SESSION['user_id'];

// --- STATS FOR OPERATIONS ---

// Tasks assigned to ME for Retrieval (Pending)
 $retrieve_pending = $pdo->prepare(
    "SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND current_status = 'Retrieval Assigned'"
);
 $retrieve_pending->execute([$uid]);
 $retrieve_count = $retrieve_pending->fetchColumn();

// Tasks assigned to ME for Restoration (Pending)
 $restore_pending = $pdo->prepare(
    "SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND current_status = 'Restoration Assigned'"
);
 $restore_pending->execute([$uid]);
 $restore_count = $restore_pending->fetchColumn();

// Total tasks I completed today (SLA Performance check)
 $completed_today = $pdo->prepare(
    "SELECT COUNT(*) FROM requests 
     WHERE assigned_to = ? 
     AND (current_status = 'File Retrieved' OR current_status = 'File Restored') 
     AND DATE(retrieved_at) = CURDATE()"
);
 $completed_today->execute([$uid]);
 $completed_count = $completed_today->fetchColumn();

// My Personal SLA Breaches
 $my_breaches = $pdo->prepare(
    "SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND sla_breach = 1"
);
 $my_breaches->execute([$uid]);
 $breach_count = $my_breaches->fetchColumn();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <header>
            <div class="page-title">Operations Workstation</div>
        </header>

        <div class="content-area">
            
            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card orange">
                    <h3>Pending Retrievals</h3>
                    <div class="value"><?= number_format($retrieve_count) ?></div>
                    <div style="margin-top:10px;font-size:0.85rem;color:var(--text-light);">Assigned to me</div>
                </div>
                <div class="stat-card green">
                    <h3>Pending Restorations</h3>
                    <div class="value"><?= number_format($restore_count) ?></div>
                    <div style="margin-top:10px;font-size:0.85rem;color:var(--text-light);">Files to return</div>
                </div>
                <div class="stat-card blue">
                    <h3>Completed Today</h3>
                    <div class="value"><?= number_format($completed_count) ?></div>
                    <div style="margin-top:10px;font-size:0.85rem;color:var(--text-light);">My performance</div>
                </div>
                <?php if($breach_count > 0): ?>
                <div class="stat-card red">
                    <h3>SLA Breaches</h3>
                    <div class="value"><?= number_format($breach_count) ?></div>
                    <div style="margin-top:10px;font-size:0.85rem;color:var(--text-light);">Exceeded 20 mins</div>
                </div>
                <?php endif; ?>
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
                            <th>SLA</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Get tasks assigned to this user
                        $sql = "SELECT r.*, f.file_name, f.barcode, f.department, 
                                       u_req.full_name as requester_name, 
                                       u_op.full_name as operator_name
                                FROM requests r 
                                JOIN files f ON r.file_id = f.id 
                                JOIN users u_req ON r.user_id = u_req.id
                                LEFT JOIN users u_op ON r.assigned_to = u_op.id
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
                                <strong><?= htmlspecialchars($row['file_name']) ?></strong><br>
                                <small><?= htmlspecialchars($row['department']) ?> &bull; Req: <?= htmlspecialchars($row['requester_name']) ?></small>
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
                                <?php if($row['sla_breach']): ?>
                                    <span style="color:red;font-weight:bold;">&#9888; Breach</span>
                                <?php else: ?>
                                    <span style="color:green;">OK</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($row['current_status'] == 'Retrieval Assigned'): ?>
                                    <form method="POST" action="../actions/request_actions.php">
                                        <input type="hidden" name="action" value="confirm_retrieval">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:orange;color:white;padding:5px 10px;">Confirm Retrieved</button>
                                    </form>
                                <?php elseif($row['current_status'] == 'Restoration Assigned'): ?>
                                    <form method="POST" action="../actions/request_actions.php">
                                        <input type="hidden" name="action" value="confirm_restoration">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:green;color:white;padding:5px 10px;">Confirm Restored</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="5" style="text-align:center;padding:20px;">No pending tasks assigned to you.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>