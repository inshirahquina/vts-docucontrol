<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(isAdmin()) redirect('../admin/dashboard.php');

// --- STATS LOGIC ---
 $uid = $_SESSION['user_id'];

// Total files requested by this user
 $total_requests = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ?");
 $total_requests->execute([$uid]);
 $total_count = $total_requests->fetchColumn();

// Currently borrowed (Active)
 $active_borrows = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND status = 'active'");
 $active_borrows->execute([$uid]);
 $active_count = $active_borrows->fetchColumn();

// Overdue files
 $overdue_borrows = $pdo->prepare("SELECT COUNT(*) FROM transactions t JOIN files f ON t.file_id = f.id WHERE t.user_id = ? AND t.status = 'active' AND t.due_date < CURDATE()");
 $overdue_borrows->execute([$uid]);
 $overdue_count = $overdue_borrows->fetchColumn();
?>

<!-- WRAPPER START -->
<div class="layout-wrapper">
    
    <!-- SIDEBAR INCLUDED HERE (Removed the one from top) -->
    <?php require_once '../includes/sidebar_staff.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <!-- STATS GRID -->
            <div class="stats-grid">
                <div class="stat-card blue">
                    <h3>Total Requests</h3>
                    <div class="value"><?= number_format($total_count) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">All time history</div>
                </div>
                <div class="stat-card green">
                    <h3>Current Borrowing</h3>
                    <div class="value"><?= number_format($active_count) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">In your possession</div>
                </div>
                <div class="stat-card red">
                    <h3>Overdue</h3>
                    <div class="value"><?= number_format($overdue_count) ?></div>
                    <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-light);">Return immediately</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
                
                <div class="card">
                    <h3>Quick Borrow</h3>
                    <p style="color:var(--text-light); margin-bottom:20px;">Scan a file barcode or enter File Number below.</p>
                    
                    <form method="POST" action="../actions/borrow_actions.php">
                        <!-- Input Wrapper -->
                        <div class="input-wrapper" style="margin-bottom: 15px;">
                            <!-- SVG with NO width/height attributes so CSS controls the size (20px) -->
                        
                            <input type="text" name="barcode_input" placeholder="Scan or Type Barcode..." required autofocus>
                        </div>
                        <button type="submit" name="action" value="request_borrow" class="btn" style="width:100%;">Request Borrow</button>
                    </form>
                    <div style="margin-top:15px; font-size:0.8rem; color:var(--text-light); text-align:center;">
                        * Request requires Admin approval.
                    </div>
                </div>
                
                <!-- MY FILES TABLE -->
                <div class="card">
                    <h3>My Active Files</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT t.*, f.file_name FROM transactions t JOIN files f ON t.file_id = f.id WHERE t.user_id = ? AND t.status IN ('pending','active') ORDER BY t.id DESC";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$uid]);
                            if($stmt->rowCount() > 0):
                                while($row = $stmt->fetch()):
                            ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($row['file_name']) ?></strong>
                                </td>
                                <td>
                                    <?php 
                                        $due = $row['due_date'];
                                        $isOverdue = (strtotime($due) < time()) && ($row['status'] == 'active');
                                    ?>
                                    <span style="color: <?= $isOverdue ? 'var(--danger)' : 'inherit' ?>; font-weight: <?= $isOverdue ? 'bold' : 'normal' ?>;">
                                        <?= format_date($due) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?php 
                                            if($row['status'] == 'overdue' || ($row['status']=='active' && $isOverdue)) echo 'overdue';
                                            elseif($row['status'] == 'active') echo 'borrowed';
                                            else echo 'available'; // pending
                                        ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; 
                            else: ?>
                            <tr><td colspan="3" style="text-align:center; padding:20px;">No active files borrowed.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="text-align:right; margin-top:15px;">
                        <a href="my_files.php" style="color:var(--accent); text-decoration:none; font-size:0.9rem;">View Full History &rarr;</a>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>