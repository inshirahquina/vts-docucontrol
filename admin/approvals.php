<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');

if(isset($_POST['action'])) {
    $trans_id = $_POST['trans_id'];
    $status = $_POST['status']; // 'approved' or 'rejected'
    
    $stmt = $pdo->prepare("UPDATE transactions SET status = ? WHERE id = ?");
    $stmt->execute([$status, $trans_id]);
    
    if($status == 'approved') {
        $stmt = $pdo->prepare("UPDATE transactions t JOIN files f ON t.file_id = f.id SET f.status = 'borrowed', t.status = 'active' WHERE t.id = ?");
        $stmt->execute([$trans_id]);
    }
    echo "<script>alert('Request $status'); window.location.href='approvals.php';</script>";
}
?>

<!-- WRAPPER START -->
<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th>File Name</th>
                            <th>Borrow Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sql = "SELECT t.*, f.file_name, u.full_name 
                                FROM transactions t 
                                JOIN files f ON t.file_id = f.id 
                                JOIN users u ON t.user_id = u.id 
                                WHERE t.status != 'returned' ORDER BY t.id DESC";
                        $reqs = $pdo->query($sql);
                        while($row = $reqs->fetch()):
                        ?>
                        <tr>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['file_name']) ?></td>
                            <td><?= format_date($row['borrow_date']) ?></td>
                            <td><span class="badge"><?= $row['status'] ?></span></td>
                            <td>
                                <?php if($row['status'] == 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="trans_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="action" value="approve" class="btn btn-sm" style="background:var(--success)">Approve</button>
                                    <input type="hidden" name="status" value="approved">
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="trans_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="action" value="reject" class="btn btn-sm" style="background:var(--danger)">Reject</button>
                                    <input type="hidden" name="status" value="rejected">
                                </form>
                                <?php elseif($row['status'] == 'active' && empty($row['return_date'])): ?>
                                <form method="POST" action="../actions/file_actions.php">
                                    <input type="hidden" name="trans_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="action" value="return_file">
                                    <button type="submit" class="btn btn-sm" style="background:var(--primary)">Return File</button>
                                </form>
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