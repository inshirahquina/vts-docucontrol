<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');

$file_id = $_GET['id'] ?? redirect('files.php');

$hist = $pdo->prepare("
    SELECT fh.*, r.current_status, u.full_name AS user_name
    FROM file_history fh
    LEFT JOIN requests r ON fh.request_id = r.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE fh.file_id = ?
    ORDER BY fh.created_at DESC
");
$hist->execute([$file_id]);
$history = $hist->fetchAll();


if(!$file) redirect('files.php');

// 2. Get History Log
 $hist = $pdo->prepare("SELECT * FROM file_history WHERE file_id = ? ORDER BY created_at DESC");
 $hist->execute([$file_id]);
 $history = $hist->fetchAll();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <div style="margin-bottom: 20px;">
                <a href="files.php" class="btn btn-secondary">&larr; Back to Files</a>
            </div>

            <div class="card" style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <h2 style="margin:0 0 10px 0;"><?= sanitize($file['file_name']) ?></h2>
                        <p style="color: var(--text-light); margin:0;">Barcode: <?= sanitize($file['barcode']) ?></p>
                    </div>
                    <span class="badge <?= $file['status'] ?>" style="font-size: 1.2rem;"><?= strtoupper($file['status']) ?></span>
                </div>
                <hr style="border:0; border-top:1px solid #eee; margin: 20px 0;">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <div>
                        <small style="color: #888; display:block; margin-bottom: 5px;">Location</small>
                        <strong>
                            <?= sanitize($file['room']) ?> / 
                            <?= sanitize($file['rack']) ?> / 
                            <?= sanitize($file['box']) ?>
                        </strong>
                    </div>
                    <div>
                        <small style="color: #888; display:block; margin-bottom: 5px;">Department</small>
                        <strong><?= sanitize($file['department']) ?></strong>
                    </div>
                    <div>
                        <small style="color: #888; display:block; margin-bottom: 5px;">Category</small>
                        <strong><?= sanitize($file['category']) ?></strong>
                    </div>
                    <?php if($file['status'] == 'borrowed'): ?>
                    <div>
                        <small style="color: #888; display:block; margin-bottom: 5px;">Currently With</small>
                        <strong style="color: var(--danger);"><?= sanitize($file['borrowed_by']) ?></strong><br>
                        <small>Since: <?= date('M j, Y', strtotime($file['borrowed_at'])) ?></small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- HISTORY TIMELINE -->
            <div class="card">
                <h3>Activity Log</h3>
                <?php if(count($history) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th width="150">Date & Time</th>
                                <th>Action</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($history as $row): ?>
                            <tr>
                                <td><?= date('M j, Y g:i A', strtotime($row['created_at'])) ?></td>
                                <td><?= sanitize($row['action']) ?> (<?= sanitize($row['current_status']) ?>)</td>
                                <td><?= sanitize($row['performed_by']) ?> <?= $row['user_name'] ? '('.sanitize($row['user_name']).')' : '' ?></td>

                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #888;">No history recorded for this file.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>