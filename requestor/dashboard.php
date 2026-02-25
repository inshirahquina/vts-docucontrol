<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

 $base_role = $_SESSION['role'] ?? '';
if ($base_role !== 'requestor' && $base_role !== 'hod') redirect('../index.php');
 $uid = $_SESSION['user_id'];

// --- STATS ---
 $total_req = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE user_id = ?");
 $total_req->execute([$uid]);
 $total_count = $total_req->fetchColumn();

 $with_me = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE user_id = ? AND current_status = 'Released'");
 $with_me->execute([$uid]);
 $with_me_count = $with_me->fetchColumn();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card blue">
                    <h3>Total Requests</h3>
                    <div class="value"><?= number_format($total_count) ?></div>
                </div>
                <div class="stat-card green">
                    <h3>With Me</h3>
                    <div class="value"><?= number_format($with_me_count) ?></div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
                
                <!-- Quick Request -->
                <div class="card">
                    <h3>Request a File</h3>
                    <form method="POST" action="../actions/request_actions.php">
                        <input type="hidden" name="action" value="create_request">
                        
                        <div class="input-group" style="margin-bottom:15px;">
                            <label>Select File</label>
                            <select name="file_id" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                                <option value="">-- Choose File --</option>
                                <?php 
                                // Only show available files
                                $files = $pdo->query("SELECT id, file_name, allocation FROM files WHERE status = 'available' ORDER BY file_name ASC");
                                while($f = $files->fetch()):
                                ?>
                                    <option value="<?= $f['id'] ?>">
                                        <?= sanitize($f['file_name']) ?> 
                                        (<?= sanitize($f['allocation']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn" style="width:100%;">Submit Request</button>
                    </form>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <h3>Recent Status</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT r.current_status, f.file_name 
                                    FROM requests r JOIN files f ON r.file_id = f.id 
                                    WHERE r.user_id = ? 
                                    ORDER BY r.id DESC LIMIT 5";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$uid]);
                            while($row = $stmt->fetch()):
                                $simple = 'Requested';
                                if(in_array($row['current_status'], ['Retrieval Assigned', 'File Retrieved'])) $simple = 'Processing';
                                elseif($row['current_status'] == 'Released') $simple = 'With Me';
                            ?>
                            <tr>
                                <td><?= sanitize($row['file_name']) ?></td>
                                <td><?= $simple ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <div style="text-align:right; margin-top:10px;">
                        <a href="my_files.php">View All &rarr;</a>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>