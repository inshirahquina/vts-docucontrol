<?php
// 1. Initialize Logic & Configuration (Before any HTML)
require_once '../config/db.php';
require_once '../config/functions.php';

// Check Role and Redirect HERE (before headers are sent)
if(!isAdmin()) {
    redirect('../index.php');
}

// Set Active Role
if (!isset($_SESSION['active_role'])) {
    $_SESSION['active_role'] = 'admin';
}

// 2. Fetch Data
 $activeRole = $_SESSION['active_role'];
 $uid = $_SESSION['user_id'];

// FIX 1: Update the Staff Query.
// We select both 'staff' AND 'admin' so that Admins (like Nurul) can be assigned tasks.
 $sqlStaff = "SELECT id, full_name FROM users WHERE role = 'staff' OR role = 'admin' ORDER BY full_name ASC";
 $staffList = $pdo->query($sqlStaff)->fetchAll();

// Get all requests with details
 $sql = "SELECT r.*, f.file_name, f.barcode, f.department, f.status as file_status, 
               u_req.full_name as requester_name, 
               u_op.full_name as operator_name
        FROM requests r 
        JOIN files f ON r.file_id = f.id 
        JOIN users u_req ON r.user_id = u_req.id
        LEFT JOIN users u_op ON r.assigned_to = u_op.id
        ORDER BY 
            CASE r.current_status 
                WHEN 'Requested' THEN 1 
                WHEN 'Retrieval Assigned' THEN 2 
                WHEN 'Return Requested' THEN 3 
                ELSE 4 
            END, 
            r.borrow_date DESC";
        
 $stmt = $pdo->prepare($sql);
 $stmt->execute();
 $requests = $stmt;

// 3. Include HTML Header (AFTER all logic and redirects)
require_once '../includes/header.php'; 
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="content-area">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div>
                    <h1>Document Workflow Management</h1>
                    <p style="color:var(--text-light);">Control Request Approval, Task Assignment, and File Restoration.</p>
                </div>
                
                <div style="display:flex; gap:10px;">
                    <a href="export_audit.php" class="btn btn-secondary">Export Audit Log</a>
                </div>
            </div>

            <div class="card">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align:left;">
                            <th style="padding:12px;">File Details</th>
                            <th style="padding:12px;">Workflow Status</th>
                            <th style="padding:12px;">Assigned / SLA</th>
                            <th style="padding:12px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $requests->fetch()): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:12px; vertical-align:top;">
                                <strong style="display:block; margin-bottom:4px;"><?= sanitize($row['file_name']) ?></strong>
                                <span style="font-size:0.85rem; color:#666;">
                                    Req: <?= sanitize($row['requester_name']) ?><br>
                                    Dept: <?= sanitize($row['department']) ?><br>
                                    <small style="color:#999;">Barcode: <?= sanitize($row['barcode']) ?></small>
                                </span>
                            </td>
                            <td style="padding:12px; vertical-align:top;">
                                <span class="badge" style="background:#e3f2fd; color:#0d47a1; padding:4px 8px; border-radius:4px; font-weight:bold;">
                                    <?= $row['current_status'] ?>
                                </span>
                                
                                <?php 
                                // FIX 2: Improved Logic for "File Unavailable"
                                // Only show warning if file is physically with user (Released) or permanently gone.
                                // Do NOT show it during 'Requested' or 'Retrieval Assigned' phases.
                                if(in_array($row['current_status'], ['Released', 'File Restored', 'Completed'])): 
                                ?>
                                    <span style="font-size:0.75rem; color:#d63384; font-weight:bold; display:block; margin-top:4px;">(File Unavailable)</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px; font-size:0.85rem; vertical-align:top;">
                                <?php if($row['operator_name']): ?>
                                    <strong>Staff:</strong> <?= sanitize($row['operator_name']) ?><br>
                                <?php endif; ?>
                                
                                <?php if($row['retrieval_assigned_at']): ?>
                                    <strong>Start:</strong> <?= date('H:i', strtotime($row['retrieval_assigned_at'])) ?><br>
                                <?php endif; ?>
                                
                                <?php if($row['sla_breach']): ?>
                                    <span style="color:red; font-weight:bold;">&#9888; SLA BREACH</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px; vertical-align:top;">
                                <!-- ACTION BUTTONS -->
                                
                                <?php if($row['current_status'] == 'Requested' && $activeRole == 'admin'): ?>
                                    <!-- Admin: Assign Retrieval -->
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="assign_retrieval">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <select name="assigned_staff_id" required style="padding:5px; margin-right:5px;">
                                            <option value="">Assign To...</option>
                                            <?php foreach($staffList as $s): ?>
                                                <option value="<?= $s['id'] ?>" <?= $s['id'] == $uid ? 'selected' : '' ?>>
                                                    <?= sanitize($s['full_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn" style="padding:5px 10px; font-size:0.8rem;">Assign</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'Retrieval Assigned' && $activeRole == 'operations'): ?>
                                    <!-- Operations: Confirm Retrieval -->
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="confirm_retrieval">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:orange; color:white; padding:6px 12px; border-radius:4px; border:none;">Confirm Retrieved</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'File Retrieved' && $activeRole == 'admin'): ?>
                                    <!-- Admin: Release -->
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="release_file">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:green; color:white; padding:6px 12px; border-radius:4px; border:none;">Release to Requestor</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'Return Requested' && $activeRole == 'admin'): ?>
                                    <!-- Admin: Assign Restoration -->
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="assign_restoration">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <select name="assigned_staff_id" required style="padding:5px; margin-right:5px;">
                                            <option value="">Assign To...</option>
                                            <?php foreach($staffList as $s): ?>
                                                <option value="<?= $s['id'] ?>" <?= $s['id'] == $uid ? 'selected' : '' ?>>
                                                    <?= sanitize($s['full_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn" style="padding:5px 10px; font-size:0.8rem;">Assign Restore</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'Restoration Assigned' && $activeRole == 'operations'): ?>
                                    <!-- Operations: Confirm Restoration -->
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="confirm_restoration">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:green; color:white; padding:6px 12px; border-radius:4px; border:none;">Confirm Restored</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'File Restored' && $activeRole == 'admin'): ?>
                                    <!-- Admin: Complete -->
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="complete_transaction">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <input type="text" name="remarks" placeholder="Remarks (Optional)" style="padding:5px; margin-right:5px; width:120px;">
                                        <button type="submit" class="btn" style="padding:5px 10px; font-size:0.8rem;">Complete</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#999; font-style:italic;">Waiting...</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>