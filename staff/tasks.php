<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php'; 

// --- SECURITY CHECK ---
if (!isLoggedIn()) {
    redirect('../index.php');
}

if (isset($_SESSION['role']) && $_SESSION['role'] !== 'requestor') {
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

// Total tasks I completed today
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
        <div class="content-area">

            <!-- STATS GRID -->
            <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px;">
                
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #f59e0b;">
                    <div style="color: #6b7280; font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">Pending Retrievals</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: #111; margin: 5px 0;"><?= number_format($retrieve_count) ?></div>
                    <div style="font-size: 0.8rem; color: #9ca3af;">Assigned to you</div>
                </div>
                
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #10b981;">
                    <div style="color: #6b7280; font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">Pending Restorations</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: #111; margin: 5px 0;"><?= number_format($restore_count) ?></div>
                    <div style="font-size: 0.8rem; color: #9ca3af;">Files to return</div>
                </div>

                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #3b82f6;">
                    <div style="color: #6b7280; font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">Completed Today</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: #111; margin: 5px 0;"><?= number_format($completed_count) ?></div>
                    <div style="font-size: 0.8rem; color: #9ca3af;">My performance</div>
                </div>

                <?php if($breach_count > 0): ?>
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #ef4444;">
                    <div style="color: #6b7280; font-size: 0.85rem; font-weight: 600; text-transform: uppercase;">SLA Breaches</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: #ef4444; margin: 5px 0;"><?= number_format($breach_count) ?></div>
                    <div style="font-size: 0.8rem; color: #9ca3af;">Exceeded 20 mins</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- TASK LIST -->
            <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
                <div style="padding: 20px; border-bottom: 1px solid #f3f4f6;">
                    <h3 style="margin:0; font-size:1.1rem; color: #111;">My Assigned Tasks</h3>
                    <p style="margin:5px 0 0 0; color:#6b7280; font-size:0.9rem;">
                        These tasks are assigned to you by Admin. Only you can complete them.
                    </p>
                </div>
                
                <div style="overflow-x: auto;">
                    <table style="width:100%; border-collapse: collapse; min-width: 900px;">
                        <thead style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                            <tr style="text-align: left; font-size: 0.8rem; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em;">
                                <th style="padding: 16px;">File Information</th>
                                <th style="padding: 16px;">Task Type</th>
                                <th style="padding: 16px;">Assigned At</th>
                                <th style="padding: 16px;">SLA Status</th>
                                <th style="padding: 16px;">Action</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 0.9rem;">
                            <?php
                            // Get tasks assigned to this user
                            $sql = "
                                SELECT r.*, f.file_name, f.barcode, f.department, 
                                    u_req.full_name as requester_name, 
                                    u_op.full_name as operator_name
                                FROM requests r
                                JOIN files f ON r.file_id = f.id
                                JOIN users u_req ON r.user_id = u_req.id
                                LEFT JOIN users u_op ON r.assigned_to = u_op.id
                                WHERE r.assigned_to = ?
                                AND r.current_status IN ('Retrieval Assigned', 'Restoration Assigned')
                                ORDER BY r.retrieval_assigned_at ASC
                            ";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$uid]);
                            
                            if($stmt->rowCount() > 0):
                                while($row = $stmt->fetch()):
                                    $isRetrieval = ($row['current_status'] == 'Retrieval Assigned');
                            ?>
                            <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.2s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
                                <td style="padding: 16px; vertical-align:top;">
                                    <div style="font-weight: 600; color: #111; margin-bottom: 4px;"><?= sanitize($row['file_name']) ?></div>
                                    <div style="font-size: 0.8rem; color: #6b7280; line-height: 1.4;">
                                        <div>Req: <?= sanitize($row['requester_name']) ?> • Dept: <?= sanitize($row['department']) ?></div>
                                        <div style="color: #9ca3af; margin-top:2px;">ID: <?= sanitize($row['barcode']) ?></div>
                                    </div>
                                </td>
                                <td style="padding: 16px; vertical-align:middle;">
                                    <?php if($isRetrieval): ?>
                                        <span class="badge" style="background:#fff7ed; color:#c2410c; padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 0.8rem;">Retrieve File</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#ecfeff; color:#0e7490; padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 0.8rem;">Restore File</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px; vertical-align:middle; color: #4b5563;">
                                    <?= date('M j, H:i', strtotime($row['retrieval_assigned_at'])) ?>
                                </td>
                                <td style="padding: 16px; vertical-align:middle;">
                                    <?php if($row['sla_breach']): ?>
                                        <span style="color: #ef4444; font-weight: 600; background: #fee2e2; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">&#9888; SLA Breach</span>
                                    <?php else: ?>
                                        <span style="color: #059669; font-weight: 600; background: #d1fae5; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">Within SLA</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 16px; vertical-align:middle;">
                                    <?php if($isRetrieval): ?>
                                        <form method="POST" action="../actions/request_actions.php">
                                            <input type="hidden" name="action" value="confirm_retrieval">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn" style="background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 500; cursor: pointer; width: 100%;">Confirm Retrieved</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="../actions/request_actions.php">
                                            <input type="hidden" name="action" value="confirm_restoration">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 500; cursor: pointer; width: 100%;">Confirm Restored</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; 
                            else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding: 40px; color:#9ca3af;">No pending tasks assigned to you. Great job!</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.badge { font-weight: 600; display: inline-block; }
</style>

<?php require_once '../includes/footer.php'; ?>