<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

// 1. Security: Ensure user is logged in as Requestor
if (!isset($_SESSION['active_role'])) {
    $_SESSION['active_role'] = 'requestor';
}

// 2. Handle New File Submission (Requestor Action)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] == 'create_request') {
    $fileId = $_POST['file_id'];
    $userId = $_SESSION['user_id'];

    $sql = "INSERT INTO requests (file_id, user_id, borrow_date, due_date, current_status) 
            VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Requested')";
    $stmt = $pdo->prepare($sql);

    if ($stmt->execute([$fileId, $userId])) {

        $requestId = $pdo->lastInsertId();

        $pdo->prepare("UPDATE files SET status = 'borrowed' WHERE id = ?")
            ->execute([$fileId]);

        $fileStmt = $pdo->prepare("SELECT file_name, department FROM files WHERE id = ?");
        $fileStmt->execute([$fileId]);
        $file = $fileStmt->fetch(PDO::FETCH_ASSOC);

        logAudit($pdo, [
            'user_id'     => $userId,
            'request_id'  => $requestId,
            'status'      => 'Requested',
            'file_name'   => $file['file_name'],
            'department'  => $file['department'],
            'role'        => $_SESSION['active_role'],
            'action'      => 'Create Request',
            'details'     => 'Request submitted by requestor'
        ]);

        header("Location: my_files.php?msg=requested");
        exit;
    } else {
        header("Location: my_files.php?msg=error");
        exit;
    }
}
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <header>
            <div class="page-title">My File Requests</div>
            <div class="user-profile">
                <div class="user-info">
                    <strong><?= sanitize($_SESSION['full_name']) ?></strong>
                    <span>Requestor View</span>
                </div>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="content-area">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3>My Requests & History</h3>
                    <button class="btn" onclick="document.getElementById('requestModal').style.display='flex'">+ Request New File</button>
                </div>
                
                <p style="color:#666; font-size:0.9rem; margin-bottom:15px;">
                    Statuses: <b>Requested</b> (Pending), <b>Processing</b> (Ops/Admin), <b>Released</b> (With You), <b>Returned</b> (Done).
                </p>

                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Requested Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $uid = $_SESSION['user_id'];
                        $sql = "
                        SELECT r.*, f.file_name, f.barcode, f.department,
                            fh.action AS last_action,
                            fh.performed_by AS last_by,
                            fh.created_at AS last_at
                        FROM requests r
                        JOIN files f ON r.file_id = f.id
                        LEFT JOIN file_history fh 
                            ON fh.request_id = r.id
                        WHERE r.user_id = ?
                        ORDER BY r.borrow_date DESC, fh.created_at DESC
                        ";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$uid]);
                        
                        if($stmt->rowCount() > 0):
                            while($row = $stmt->fetch()):
                                // Map Complex Internal Status to Simplified View
                                $displayStatus = 'Requested';
                                $badgeClass = 'pending'; // Assuming you have this CSS class
                                
                                if($row['current_status'] == 'Requested') {
                                    $displayStatus = 'Requested';
                                    $badgeClass = 'pending'; 
                                } elseif(in_array($row['current_status'], ['Retrieval Assigned', 'File Retrieved'])) {
                                    $displayStatus = 'Processing';
                                    $badgeClass = 'borrowed'; 
                                } elseif($row['current_status'] == 'Released') {
                                    $displayStatus = 'File Released';
                                    $badgeClass = 'available'; // Green
                                } elseif(in_array($row['current_status'], ['Return Requested', 'Restoration Assigned', 'File Restored', 'Completed'])) {
                                    $displayStatus = 'Returned';
                                    $badgeClass = 'available';
                                }
                        ?>
                        <tr>
                            <td><?= sanitize($row['file_name']) ?></td>
                            <td><?= format_date($row['borrow_date']) ?></td>
                            <td>
                                <span class="badge <?= $badgeClass ?>" style="padding:4px 8px; border-radius:4px; font-size:0.85rem;">
                                    <?= $displayStatus ?>
                                </span>
                                <?php if($row['sla_breach']): ?>
                                    <span style="color:red; font-size:0.75rem; display:block;">(SLA Breach)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($row['current_status'] == 'Released'): ?>
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="action" value="request_return">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn" style="padding:5px 10px; font-size:0.8rem; background:#28a745; color:white;">Return File</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#999; font-size:0.85rem;">No Action</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="4" style="text-align:center; padding: 20px;">No requests found. Click "Request New File" to start.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- REQUEST MODAL -->
<div id="requestModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h2>Request a File</h2>
            <button class="modal-close" onclick="document.getElementById('requestModal').style.display='none'">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_request">
            
            <div class="input-group" style="margin-bottom:15px;">
                <label>Select File to Request</label>
                <select name="file_id" required style="width:100%; padding:10px;">
                    <option value="">-- Choose a File --</option>
                    <?php 
                    // Only show files that are currently 'available'
                    $files = $pdo->query("SELECT id, file_name, barcode FROM files WHERE status = 'available' ORDER BY file_name ASC");
                    while($f = $files->fetch()):
                    ?>
                        <option value="<?= $f['id'] ?>">
                            <?= sanitize($f['file_name']) ?> (<?= sanitize($f['barcode']) ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="document.getElementById('requestModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>