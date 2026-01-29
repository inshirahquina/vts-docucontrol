<?php
// 1. Initialize Logic & Configuration
require_once '../config/db.php';
require_once '../config/functions.php';

// Check Role and Redirect
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

// Fetch Staff and Admins for assignment dropdown
 $sqlStaff = "SELECT id, full_name FROM users WHERE role = 'staff' OR role = 'admin' ORDER BY full_name ASC";
 $staffList = $pdo->query($sqlStaff)->fetchAll();

// CORRECTED SLA LOGIC:
// The 20 minute SLA is strictly between:
// START: 'retrieved_at' (Status: File Retrieved)
// END:   'released_at'   (Status: Released/Completed)
//
// If the file is Retrieved but not yet Released, we calculate duration against NOW().
 $sql = "SELECT r.*, 
               f.file_name, 
               f.barcode, 
               f.department, 
               f.status as file_status, 
               u_req.full_name as requester_name, 
               u_op.full_name as operator_name,
               
               -- Timestamps for the 'Retrieved -> Released' SLA Window
               r.retrieved_at as sla_start_time,
               r.released_at as sla_end_time,

               -- Calculate Duration Minutes for this specific window
               CASE 
                   -- If released, calculate difference
                   WHEN r.retrieved_at IS NOT NULL AND r.released_at IS NOT NULL THEN
                       TIMESTAMPDIFF(MINUTE, r.retrieved_at, r.released_at)
                   
                   -- If retrieved but not released (active SLA window), calculate against NOW()
                   WHEN r.retrieved_at IS NOT NULL AND r.released_at IS NULL THEN
                       TIMESTAMPDIFF(MINUTE, r.retrieved_at, NOW())
                   
                   ELSE 0
               END as sla_duration_minutes
        FROM requests r 
        JOIN files f ON r.file_id = f.id 
        JOIN users u_req ON r.user_id = u_req.id
        LEFT JOIN users u_op ON r.assigned_to = u_op.id
        ORDER BY 
            CASE r.current_status 
                WHEN 'Requested' THEN 1 
                WHEN 'Retrieval Assigned' THEN 2 
                WHEN 'File Retrieved' THEN 3
                WHEN 'Return Requested' THEN 4 
                WHEN 'Restoration Assigned' THEN 5
                WHEN 'Cancelled' THEN 99 
                WHEN 'Completed' THEN 99
                ELSE 100 
            END, 
            r.borrow_date DESC";
        
 $stmt = $pdo->prepare($sql);
 $stmt->execute();
 $requests = $stmt;

// Helper function for better time display
function formatDuration($minutes) {
    if ($minutes < 1) return "0m";
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    if ($h > 0) return $h . "h " . $m . "m";
    return $m . " mins";
}

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
                    <div style="display:flex; gap:10px;">
                        <input type="text" id="tableSearch" placeholder="Search..." 
                               style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <select id="statusFilter" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                            <option value="all">All Statuses</option>
                            <option value="Requested">Requested</option>
                            <option value="Retrieval Assigned">Retrieval Assigned</option>
                            <option value="File Retrieved">File Retrieved</option>
                            <option value="Return Requested">Return Requested</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align:left;">
                            <th style="padding:12px;">File Details</th>
                            <th style="padding:12px;">Workflow Status</th>
                            <th style="padding:12px;">Timeline (Retrieved -> Released)</th>
                            <th style="padding:12px;">Duration</th>
                            <th style="padding:12px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $requests->fetch()): 
                            // Determine Badge Color for Status
                            $displayStatus = $row['current_status'];
                            $badgeBg = '#e3f2fd';
                            $badgeColor = '#0d47a1';

                            if ($displayStatus == 'Cancelled' || empty($displayStatus)) {
                                $displayStatus = 'Cancelled';
                                $badgeBg = '#fee2e2';
                                $badgeColor = '#b91c1c';
                            } elseif ($displayStatus == 'Completed') {
                                $badgeBg = '#d1fae5';
                                $badgeColor = '#065f46';
                            } elseif (in_array($displayStatus, ['Released', 'File Restored'])) {
                                $badgeBg = '#e0e7ff';
                                $badgeColor = '#3730a3';
                            } elseif ($displayStatus == 'Return Requested') {
                                $badgeBg = '#ffedd5';
                                $badgeColor = '#9a3412';
                            } elseif ($displayStatus == 'Retrieval Assigned') {
                                $badgeBg = '#fef3c7'; 
                                $badgeColor = '#92400e';
                            } elseif ($displayStatus == 'File Retrieved') {
                                // Highlight this status as it starts the SLA timer
                                $badgeBg = '#e0f2fe'; 
                                $badgeColor = '#0369a1';
                            }
                        ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:12px; vertical-align:top;">
                                <strong style="display:block; margin-bottom:4px;"><?= sanitize($row['file_name']) ?></strong>
                                <span style="font-size:0.85rem; color:#666;">
                                    Req: <?= sanitize($row['requester_name']) ?><br>
                                    Dept: <?= sanitize($row['department']) ?><br>
                                    <!-- <small style="color:#999;">Barcode: <?= sanitize($row['barcode']) ?></small> -->
                                </span>
                            </td>
                            <td style="padding:12px; vertical-align:top;">
                                <span class="badge" style="background:<?= $badgeBg ?>; color:<?= $badgeColor ?>; padding:4px 8px; border-radius:4px; font-weight:bold; display:inline-block; margin-bottom:4px;">
                                    <?= $displayStatus ?>
                                </span>
                                <?php if($row['operator_name']): ?>
                                    <div style="font-size:0.8rem; color:#666; margin-top:4px;">Staff: <?= sanitize($row['operator_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- NEW: Specific SLA Timeline Display -->
                            <td style="padding:12px; font-size:0.85rem; vertical-align:top; min-width: 160px;">
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <!-- Start Time (Retrieved) -->
                                    <div>
                                        <span style="color:#666; font-size:0.75rem; text-transform:uppercase; font-weight:bold;">Retrieved (Start)</span><br>
                                        <?php if($row['sla_start_time']): ?>
                                            <span style="font-family:monospace; font-size:0.9rem; color:#333;">
                                                <?= date('M j, H:i:s', strtotime($row['sla_start_time'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#999;">--</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- End Time (Released) -->
                                    <div>
                                        <span style="color:#666; font-size:0.75rem; text-transform:uppercase; font-weight:bold;">Released (End)</span><br>
                                        <?php if($row['sla_end_time']): ?>
                                            <span style="font-family:monospace; font-size:0.9rem; color:#059669; font-weight:bold;">
                                                <?= date('M j, H:i:s', strtotime($row['sla_end_time'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#999; font-style:italic;">Pending...</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            
                            <td style="padding:12px; font-size:0.9rem; vertical-align:top; min-width: 100px;">
                                <?php 
                                    $mins = isset($row['sla_duration_minutes']) ? intval($row['sla_duration_minutes']) : 0;
                                    
                                    // SLA Logic: 20 Minutes
                                    $slaBg = '#d1fae5'; // Green
                                    $slaText = '#065f46';
                                    
                                    if ($mins > 20) {
                                        $slaBg = '#fee2e2'; // Red
                                        $slaText = '#b91c1c';
                                    } elseif ($mins > 15 && $mins <= 20) {
                                        $slaBg = '#ffedd5'; // Orange
                                        $slaText = '#9a3412';
                                    }

                                    // Only show duration if the SLA window has started (Retrieved)
                                    if ($row['sla_start_time']) {
                                ?>
                                   <div style="background:<?= $slaBg ?>; color:<?= $slaText ?>; 
                                            padding:4px 6px; 
                                            border-radius:4px; 
                                            text-align:center; 
                                            font-weight:bold; 
                                            font-size:0.85rem; 
                                            display:inline-block; 
                                            min-width:60px; 
                                            box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                                    <?= formatDuration($mins) ?>
                                </div>
                            
                                <?php } else { 
                                    // Not yet retrieved, so SLA clock hasn't started
                                    echo '<span style="color:#999; font-style:italic;">Not Started</span>'; 
                                } ?>
                            </td>
                            
                            <td style="padding:12px; vertical-align:top;">
                                <!-- ACTION BUTTONS -->
                                
                                <?php if($row['current_status'] == 'Requested' && $activeRole == 'admin'): ?>
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
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="confirm_retrieval">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:orange; color:white; padding:6px 12px; border-radius:4px; border:none;">Confirm Retrieved</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'File Retrieved' && $activeRole == 'admin'): ?>
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="release_file">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:green; color:white; padding:6px 12px; border-radius:4px; border:none;">Release to Requestor</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'Return Requested' && $activeRole == 'admin'): ?>
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
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="confirm_restoration">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="background:green; color:white; padding:6px 12px; border-radius:4px; border:none;">Confirm Restored</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'File Restored' && $activeRole == 'admin'): ?>
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="complete_transaction">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <input type="text" name="remarks" placeholder="Remarks (Optional)" style="padding:5px; margin-right:5px; width:120px;">
                                        <button type="submit" class="btn" style="padding:5px 10px; font-size:0.8rem;">Complete</button>
                                    </form>

                                <?php elseif($row['current_status'] == 'Cancelled' || empty($row['current_status'])): ?>
                                    <span style="color:#dc2626; font-weight:bold; display:block;">Request Cancelled</span>
                                    
                                <?php elseif($row['current_status'] == 'Completed'): ?>
                                    <span style="color:#059669; font-weight:bold;">Completed</span>
                                    <?php if(!empty($row['remarks'])): ?>
                                        <div style="font-size:0.75rem; color:#666; margin-top:4px;">Note: <?= sanitize($row['remarks']) ?></div>
                                    <?php endif; ?>

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

<script>
// Simple Filter Logic
document.getElementById('tableSearch').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let statusFilter = document.getElementById('statusFilter').value;
    let rows = document.querySelectorAll('tbody tr');
    rows.forEach(row => {
        let text = row.textContent.toLowerCase();
        // Check status column specifically (index 1)
        let statusCell = row.cells[1].innerText.trim();
        
        let matchesSearch = text.includes(filter);
        let matchesStatus = (statusFilter === 'all') || (statusCell === statusFilter);

        row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
    });
});
document.getElementById('statusFilter').addEventListener('change', function() {
    document.getElementById('tableSearch').dispatchEvent(new Event('keyup'));
});
</script>

<?php require_once '../includes/footer.php'; ?>