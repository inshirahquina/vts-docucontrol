<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if(!isLoggedIn() || ($_SESSION['active_role'] ?? '') !== 'hod'){
    redirect(BASE_URL . 'index.php');
}

// Handle Approval/Rejection
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Ideally, verify the HOD owns this request before processing action here too
    // But for now, we focus on displaying the correct list.
    $action = $_POST['action'] ?? '';
    $id = $_POST['request_id'] ?? '';
}

$current_user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT r.*, f.file_name, u.full_name as requester_name 
    FROM requests r
    JOIN files f ON r.file_id = f.id
    JOIN users u ON r.user_id = u.id
    WHERE r.current_status = 'Pending HOD Approval'
    AND u.hod_id = ?
    ORDER BY r.borrow_date DESC
");

$stmt->execute([$current_user_id]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="content-area">
            <h2>Pending Approvals</h2>
            
            <?php if(empty($pending)): ?>
                <div class="card">No pending approvals for your staff.</div>
            <?php else: ?>
                <div class="card">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>File</th>
                                <th>Requester</th>
                                <th>Type</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending as $p): 
                                $isExtension = $p['extension_count'] > 0;
                            ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($p['borrow_date'])) ?></td>
                                <td><strong><?= sanitize($p['file_name']) ?></strong></td>
                                <td><?= sanitize($p['requester_name']) ?></td>
                                <td>
                                    <?php if($isExtension): ?>
                                        <span style="color:#d97706; font-weight:bold;">Extension Request #<?= $p['extension_count'] ?></span>
                                    <?php else: ?>
                                        <span>New Request</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="request_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="approve_hod">
                                        <button class="btn" style="background:#16a34a; color:#fff;">Approve</button>
                                    </form>
                                    <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                        <input type="hidden" name="request_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="reject_hod">
                                        <button class="btn" style="background:#dc2626; color:#fff;">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once '../includes/footer.php'; ?>