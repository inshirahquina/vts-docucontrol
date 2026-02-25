<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';

if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'admin') {
    header("Location: ../admin/dashboard.php");
    exit();
}

require_once '../includes/header.php'; 

// Get User ID
$uid = $_SESSION['user_id'];

// --- STATS FOR OPERATIONS ---

// 1. Tasks assigned to ME for Retrieval (Pending)
$retrieve_pending = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND current_status = 'Retrieval Assigned'");
$retrieve_pending->execute([$uid]);
$retrieve_count = $retrieve_pending->fetchColumn();

// 2. Tasks assigned to ME for Restoration (Pending)
$restore_pending = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND current_status = 'Restoration Assigned'");
$restore_pending->execute([$uid]);
$restore_count = $restore_pending->fetchColumn();

// 3. Total tasks I completed today
$completed_today = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND (current_status = 'File Retrieved' OR current_status = 'File Restored') AND DATE(retrieved_at) = CURDATE()");
$completed_today->execute([$uid]);
$completed_count = $completed_today->fetchColumn();

// --- PAGINATION FOR TASK LIST ---
$records_per_page = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Count total tasks for pagination
$countTasks = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE assigned_to = ? AND current_status IN ('Retrieval Assigned', 'Restoration Assigned')");
$countTasks->execute([$uid]);
$total_records = $countTasks->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get paginated tasks assigned to this user
$sql = "SELECT r.*, f.file_name, u_req.full_name as requester_name, f.department
        FROM requests r 
        JOIN files f ON r.file_id = f.id 
        JOIN users u_req ON r.user_id = u_req.id
        WHERE r.assigned_to = ? 
        AND r.current_status IN ('Retrieval Assigned', 'Restoration Assigned')
        ORDER BY r.retrieval_assigned_at DESC
        LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(1, $uid, PDO::PARAM_INT);
$stmt->bindValue(2, $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$tasks = $stmt->fetchAll();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Operations Dashboard</h1>
                    <p>Manage your assigned file retrieval and restoration tasks.</p>
                </div>
            </div>

            <!-- STATS GRID -->
            <div class="stats-grid-modern">
                <!-- Pending Retrievals -->
                <div class="stat-card-modern orange">
                    <div class="stat-icon">📤</div>
                    <div class="stat-details">
                        <h3>Pending Retrievals</h3>
                        <div class="value"><?= number_format($retrieve_count) ?></div>
                    </div>
                </div>

                <!-- Pending Restorations -->
                <div class="stat-card-modern green">
                    <div class="stat-icon">📥</div>
                    <div class="stat-details">
                        <h3>Pending Restorations</h3>
                        <div class="value"><?= number_format($restore_count) ?></div>
                    </div>
                </div>

                <!-- Completed Today -->
                <div class="stat-card-modern blue">
                    <div class="stat-icon">✅</div>
                    <div class="stat-details">
                        <h3>Completed Today</h3>
                        <div class="value"><?= number_format($completed_count) ?></div>
                    </div>
                </div>
            </div>

            <!-- TASK LIST -->
            <div class="card table-card">
                <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
                    <h3 style="margin:0; font-size: 1.1rem; color: #111827;">My Assigned Tasks</h3>
                    <p style="color:#6b7280; margin:5px 0 0 0; font-size: 0.85rem;">
                        These tasks are assigned to you by Admin. Complete them to update file status.
                    </p>
                </div>

                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>File Details</th>
                                <th>Requester</th>
                                <th>Task Type</th>
                                <th>Assigned Time</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($tasks) > 0): ?>
                                <?php foreach($tasks as $row): ?>
                                <tr>
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-title"><?= sanitize($row['file_name']) ?></span>
                                            <span class="cell-sub"><?= sanitize($row['department']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="cell-text"><?= sanitize($row['requester_name']) ?></span>
                                    </td>
                                    <td>
                                        <?php if($row['current_status'] == 'Retrieval Assigned'): ?>
                                            <span class="status-badge status-orange">📤 Retrieve</span>
                                        <?php else: ?>
                                            <span class="status-badge status-blue">📥 Restore</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="cell-text"><?= date('M j, g:i A', strtotime($row['retrieval_assigned_at'])) ?></span>
                                    </td>
                                    <td class="action-cell">
                                        <?php if($row['current_status'] == 'Retrieval Assigned'): ?>
                                            <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                                <input type="hidden" name="action" value="confirm_retrieval">
                                                <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm warning">Confirm Retrieved</button>
                                            </form>
                                        <?php elseif($row['current_status'] == 'Restoration Assigned'): ?>
                                            <form method="POST" action="../actions/request_actions.php" style="display:inline;">
                                                <input type="hidden" name="action" value="confirm_restoration">
                                                <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm success">Confirm Restored</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-cell">
                                        <div class="empty-state">
                                            <h3>No Pending Tasks</h3>
                                            <p>You have completed all assigned tasks.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Showing <?= min($total_records, $offset + 1) ?> - <?= min($total_records, $offset + $records_per_page) ?> of <?= $total_records ?> tasks
                    </div>
                    <div class="pagination-links">
                        <?php if($page > 1): ?>
                            <a href="?page=1" class="page-btn">«</a>
                            <a href="?page=<?= $page-1 ?>" class="page-btn">‹</a>
                        <?php endif; ?>

                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if($i == $page): ?>
                                <span class="page-btn active"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $i ?>" class="page-btn"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if($page < $total_pages): ?>
                            <a href="?page=<?= $page+1 ?>" class="page-btn">›</a>
                            <a href="?page=<?= $total_pages ?>" class="page-btn">»</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<style>
/* Layout */
.content-area { padding: 24px; background: #f3f4f6; min-height: calc(100vh - 60px); }

/* Page Header */
.page-header { margin-bottom: 24px; }
.page-title h1 { font-size: 1.5rem; color: #111827; margin: 0 0 4px 0; font-weight: 700; }
.page-title p { margin: 0; color: #6b7280; font-size: 0.9rem; }

/* Stats Grid Modern */
.stats-grid-modern { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 24px; }
.stat-card-modern { background: #fff; border-radius: 10px; padding: 20px; display: flex; align-items: center; gap: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-left: 4px solid #e5e7eb; }
.stat-card-modern.orange { border-left-color: #f59e0b; }
.stat-card-modern.green { border-left-color: #10b981; }
.stat-card-modern.blue { border-left-color: #3b82f6; }
.stat-icon { width: 48px; height: 48px; border-radius: 8px; background: #f3f4f6; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.stat-card-modern.orange .stat-icon { background: #fff7ed; }
.stat-card-modern.green .stat-icon { background: #ecfdf5; }
.stat-card-modern.blue .stat-icon { background: #eff6ff; }
.stat-details h3 { margin: 0; font-size: 0.85rem; color: #6b7280; font-weight: 500; }
.stat-details .value { font-size: 1.75rem; font-weight: 700; color: #111827; margin: 4px 0; }
.stat-sub { font-size: 0.8rem; color: #9ca3af; }

/* Cards & Table */
.card { background: #fff; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.table-card { overflow: hidden; }
.table-responsive { overflow-x: auto; }
.modern-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.modern-table th { text-align: left; padding: 12px 20px; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; background: #f9fafb; border-bottom: 1px solid #e5e7eb; letter-spacing: 0.05em; }
.modern-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.cell-main { display: flex; flex-direction: column; gap: 2px; }
.cell-title { font-weight: 600; color: #111827; font-size: 0.95rem; }
.cell-text { font-weight: 500; color: #374151; font-size: 0.9rem; }
.cell-sub { font-size: 0.8rem; color: #9ca3af; }

/* Status Badges */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.status-orange { background: #ffedd5; color: #c2410c; }
.status-blue { background: #dbeafe; color: #1d4ed8; }

/* Buttons */
.btn { padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn-sm { padding: 6px 14px; font-size: 0.8rem; }
.btn.warning { background: #f59e0b; color: white; }
.btn.warning:hover { background: #d97706; }
.btn.success { background: #10b981; color: white; }
.btn.success:hover { background: #059669; }

/* Empty State */
.empty-cell { padding: 40px; text-align: center; }
.empty-state h3 { margin: 0 0 5px 0; color: #374151; }
.empty-state p { margin: 0; color: #6b7280; font-size: 0.9rem; }

/* Pagination */
.pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-top: 1px solid #e5e7eb; background: #f9fafb; }
.pagination-info { font-size: 0.85rem; color: #6b7280; }
.pagination-links { display: flex; gap: 4px; }
.page-btn { padding: 6px 12px; border: 1px solid #d1d5db; background: #fff; color: #374151; text-decoration: none; border-radius: 6px; font-size: 0.85rem; transition: all 0.2s; cursor: pointer; }
.page-btn:hover { background: #f3f4f6; }
.page-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
</style>

<?php require_once '../includes/footer.php'; ?>