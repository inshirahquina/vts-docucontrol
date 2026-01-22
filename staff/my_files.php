<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';
// REMOVED DUPLICATE SIDEBAR INCLUDE HERE

if(isAdmin()) redirect('../admin/dashboard.php');
?>

<div class="layout-wrapper">
    <!-- SIDEBAR INCLUDED ONLY HERE -->
    <?php require_once '../includes/sidebar_staff.php'; ?>

    <div class="main-content">
        <header>
            <div class="page-title">My Borrowing History</div>
            <div class="user-profile">
                <div class="user-info">
                    <strong><?= sanitize($_SESSION['full_name']) ?></strong>
                    <span><?= ucfirst($_SESSION['role']) ?></span>
                </div>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>
        </header>

        <div class="content-area">
            <div class="card">
                <h3>Full Transaction History</h3>
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Barcode</th>
                            <th>Borrowed Date</th>
                            <th>Due Date</th>
                            <th>Returned Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $uid = $_SESSION['user_id'];
                        $sql = "SELECT t.*, f.file_name, f.barcode 
                                FROM transactions t 
                                JOIN files f ON t.file_id = f.id 
                                WHERE t.user_id = ? 
                                ORDER BY t.borrow_date DESC";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$uid]);
                        if($stmt->rowCount() > 0):
                            while($row = $stmt->fetch()):
                        ?>
                        <tr>
                            <td><?= sanitize($row['file_name']) ?></td>
                            <td><?= sanitize($row['barcode']) ?></td>
                            <td><?= format_date($row['borrow_date']) ?></td>
                            <td><?= format_date($row['due_date']) ?></td>
                            <td><?= $row['return_date'] ? format_date($row['return_date']) : '-' ?></td>
                            <td>
                                <span class="badge 
                                    <?php 
                                        if($row['status'] == 'overdue') echo 'overdue';
                                        elseif($row['status'] == 'active') echo 'borrowed';
                                        elseif($row['status'] == 'returned') echo 'available'; // Returned = Available back
                                        else echo 'available'; // Pending
                                    ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="6" style="text-align:center; padding: 20px;">No borrowing history found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>