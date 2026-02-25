<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if(!isLoggedIn() || ($_SESSION['active_role'] ?? '') !== 'hod'){
    redirect(BASE_URL . 'index.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.current_status = 'Pending HOD Approval'
    AND u.hod_id = ?
");
$stmt->execute([$current_user_id]);
$pending_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.current_status = 'Approved by HOD'
    AND u.hod_id = ?
");
$stmt->execute([$current_user_id]);
$approved_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT r.*, u.full_name, f.file_name
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN files f ON r.file_id = f.id
    WHERE r.current_status = 'Pending HOD Approval'
    AND u.hod_id = ?
    ORDER BY r.id DESC
    LIMIT 5
");
$stmt->execute([$current_user_id]);
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="content-area">

            <div class="stats-grid">
                <div class="stat-card orange">
                    <h3>Pending Approval</h3>
                    <div class="value"><?= number_format($pending_count) ?></div>
                </div>
                <div class="stat-card green">
                    <h3>Approved</h3>
                    <div class="value"><?= number_format($approved_count) ?></div>
                </div>
            </div>

            <div class="card">
                <h3>Recent Approval Tasks</h3>
                <?php if(empty($recent)): ?>
                    <p>No pending approvals.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Staff</th>
                                <th>File</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($recent as $r): ?>
                            <tr>
                                <td>#<?= $r['id'] ?></td>
                                <td><?= sanitize($r['full_name']) ?></td>
                                <td><?= sanitize($r['file_name']) ?></td>
                                <td><span style="color:orange"><?= sanitize($r['current_status']) ?></span></td>
                                <td><a href="approvals.php" class="btn">Review</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<?php require_once '../includes/footer.php'; ?>
