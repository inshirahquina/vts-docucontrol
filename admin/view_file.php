<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');

$file_id = $_GET['id'] ?? redirect('files.php');

$records_per_page = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if(!$file) redirect('files.php');

$borrowInfo = null;

if($file['status'] === 'borrowed') {
    $borrowStmt = $pdo->prepare("
        SELECT r.released_at, u.full_name
        FROM requests r
        JOIN users u ON r.user_id = u.id
        WHERE r.file_id = ?
        AND r.current_status = 'Released'
        ORDER BY r.released_at DESC
        LIMIT 1
    ");
    $borrowStmt->execute([$file_id]);
    $borrowInfo = $borrowStmt->fetch(PDO::FETCH_ASSOC);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM file_history WHERE file_id = ?");
$countStmt->execute([$file_id]);
$total_records = $countStmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

$hist = $pdo->prepare("
    SELECT 
        fh.*,
        u.full_name AS performer_name,
        u.username AS performer_username,
        r.current_status,
        req_user.full_name AS requester_name
    FROM file_history fh
    LEFT JOIN users u ON fh.performed_by = u.id
    LEFT JOIN requests r ON fh.request_id = r.id
    LEFT JOIN users req_user ON r.user_id = req_user.id
    WHERE fh.file_id = ?
    ORDER BY fh.created_at DESC
    LIMIT ? OFFSET ?
");
$hist->execute([$file_id, $records_per_page, $offset]);
$history = $hist->fetchAll();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <a href="files.php" class="btn btn-secondary" style="margin-bottom: 10px; display: inline-flex; align-items: center; gap: 5px;">
                        &larr; Back to Files
                    </a>
                    <h1>File Details</h1>
                </div>
                <div class="page-actions">
                    <span class="status-badge <?= sanitize($file['status']) ?>" style="font-size: 1rem; padding: 8px 16px;">
                        <?= ucfirst(sanitize($file['status'])) ?>
                    </span>
                </div>
            </div>

            <!-- File Info Card -->
            <div class="card table-card" style="margin-bottom: 20px;">
                <div style="padding: 25px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 30px;">
                        
                        <!-- Column 1: Identity -->
                        <div>
                            <h3 style="margin: 0 0 15px 0; color: #111827;"><?= sanitize($file['file_name']) ?></h3>
                            
                            <div style="margin-bottom: 12px;">
                                <small style="color: #888; display:block; margin-bottom: 4px;">Department</small>
                                <strong style="color: #374151;"><?= sanitize($file['department']) ?></strong>
                            </div>
                            <div>
                                <small style="color: #888; display:block; margin-bottom: 4px;">Month/Year</small>
                                <strong style="color: #374151;"><?= sanitize($file['month_year']) ?></strong>
                            </div>
                        </div>

                        <!-- Column 2: Location / Allocation -->
                        <div>
                            <h4 style="margin: 0 0 15px 0; color: #6b7280; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em;">Allocation Info</h4>
                            
                            <div style="margin-bottom: 12px;">
                                <small style="color: #888; display:block; margin-bottom: 4px;">Allocation Code</small>
                                <strong style="color: #374151;"><?= sanitize($file['allocation']) ?: 'N/A' ?></strong>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <small style="color: #888; display:block; margin-bottom: 4px;">Box Number</small>
                                <strong style="color: #374151;"><?= sanitize($file['box_no']) ?: 'N/A' ?></strong>
                            </div>
                            <div>
                                <small style="color: #888; display:block; margin-bottom: 4px;">Retention Period</small>
                                <strong style="color: #374151;"><?= sanitize($file['retention_period']) ?></strong>
                            </div>
                        </div>

                        <!-- Column 3: Status / Current Holder -->
                        <div>
                            <h4 style="margin: 0 0 15px 0; color: #6b7280; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em;">Current Status</h4>
                            <?php if($file['status'] == 'borrowed'): ?>
                            <div style="background: #fff3e0; padding: 15px; border-radius: 8px; border-left: 4px solid #ef6c00;">
                                <div style="font-weight: 700; color: #e65100; margin-bottom: 5px;">Currently Borrowed</div>
                                <div style="font-size: 0.9rem; color: #333;">
                                    By: <strong><?= sanitize($borrowInfo['full_name'] ?? 'Unknown') ?></strong><br>
                                    Since: <strong>
                                        <?= !empty($borrowInfo['released_at']) 
                                            ? date('M j, Y', strtotime($borrowInfo['released_at'])) 
                                            : 'N/A' ?>
                                    </strong>
                                </div>
                            </div>

                        <?php elseif($file['status'] == 'requested'): ?>

                            <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; border-left: 4px solid #1e88e5;">
                                <div style="font-weight: 700; color: #1565c0; margin-bottom: 5px;">Request Pending</div>
                                <div style="font-size: 0.9rem; color: #333;">
                                    File has been requested and awaiting approval.
                                </div>
                            </div>

                        <?php elseif($file['status'] == 'archived'): ?>

                            <div style="background: #f3f4f6; padding: 15px; border-radius: 8px; border-left: 4px solid #6b7280;">
                                <div style="font-weight: 700; color: #374151; margin-bottom: 5px;">Archived</div>
                                <div style="font-size: 0.9rem; color: #333;">
                                    This file is no longer active.
                                </div>
                            </div>

                        <?php elseif($file['status'] == 'lost'): ?>

                            <div style="background: #fee2e2; padding: 15px; border-radius: 8px; border-left: 4px solid #dc2626;">
                                <div style="font-weight: 700; color: #b91c1c; margin-bottom: 5px;">File Marked as Lost</div>
                                <div style="font-size: 0.9rem; color: #333;">
                                    This file is currently missing from storage.
                                </div>
                            </div>

                        <?php else: ?>

                            <div style="background: #e8f5e9; padding: 15px; border-radius: 8px; border-left: 4px solid #2e7d32;">
                                <div style="font-weight: 700; color: #1b5e20; margin-bottom: 5px;">Available</div>
                                <div style="font-size: 0.9rem; color: #333;">
                                    Ready for request or archiving.
                                </div>
                            </div>

                        <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>

            <!-- HISTORY TIMELINE -->
            <div class="card table-card">
                <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin:0; font-size: 1.1rem; color: #111827;">Activity Log</h3>
                    <span style="font-size: 0.85rem; color: #6b7280;">
                        <?= $total_records ?> total entries
                    </span>
                </div>
                
                <?php if(count($history) > 0): ?>
                    <div class="table-responsive">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th style="width: 180px;">Timestamp</th>
                                    <th>Action Details</th>
                                    <th style="width: 200px;">Performed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($history as $row): 
                                    // --- SMART USER LOGIC ---
                                    $displayName = 'Unknown User';
                                    if (!empty($row['performer_name'])) {
                                        $displayName = sanitize($row['performer_name']);
                                    } elseif (!empty($row['performer_username'])) {
                                        $displayName = sanitize($row['performer_username']);
                                    } elseif (!empty($row['requester_name'])) {
                                        $displayName = sanitize($row['requester_name']);
                                    }
                                    
                                    $role = !empty($row['performed_role']) ? sanitize($row['performed_role']) : 'System';
                                ?>
                                <tr>
                                    <td style="white-space: nowrap;">
                                        <div style="font-weight: 600; color: #333;"><?= date('M j, Y', strtotime($row['created_at'])) ?></div>
                                        <div style="font-size: 0.85rem; color: #888;"><?= date('g:i A', strtotime($row['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: #111827;"><?= sanitize($row['action']) ?></span>
                                        <?php if(!empty($row['current_status'])): ?>
                                            <span style="display:inline-block; margin-left:8px; font-size:0.75rem; background:#f3f4f6; padding:2px 6px; border-radius:4px; color:#555;">
                                                Status: <?= sanitize($row['current_status']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; color: #111827;"><?= $displayName ?></div>
                                        <div style="font-size: 0.8rem; color: #6b7280; text-transform: capitalize;"><?= $role ?></div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-bar">
                        <div class="pagination-info">
                            Showing <?= min($total_records, $offset + 1) ?> - <?= min($total_records, $offset + $records_per_page) ?> of <?= $total_records ?>
                        </div>
                        <div class="pagination-links">
                            <?php if($page > 1): ?>
                                <a href="?id=<?= $file_id ?>&page=1" class="page-btn">«</a>
                                <a href="?id=<?= $file_id ?>&page=<?= $page-1 ?>" class="page-btn">‹</a>
                            <?php endif; ?>

                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if($i == $page): ?>
                                    <span class="page-btn active"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="?id=<?= $file_id ?>&page=<?= $i ?>" class="page-btn"><?= $i ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if($page < $total_pages): ?>
                                <a href="?id=<?= $file_id ?>&page=<?= $page+1 ?>" class="page-btn">›</a>
                                <a href="?id=<?= $file_id ?>&page=<?= $total_pages ?>" class="page-btn">»</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-cell" style="padding: 40px; text-align:center;">
                        <div style="font-size: 2rem; margin-bottom: 10px;">📂</div>
                        <h3 style="margin: 0 0 5px 0; color: #374151;">No History</h3>
                        <p style="margin: 0; color: #6b7280;">No activity recorded for this file yet.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<style>
    /* Layout */
    .content-area { padding: 24px; background: #f3f4f6; min-height: calc(100vh - 60px); }
    .page-header { margin-bottom: 20px; }
    .page-title h1 { font-size: 1.5rem; color: #111827; margin: 10px 0 0 0; font-weight: 700; }

    /* Cards */
    .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    
    /* Status Badges */
    .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .status-badge.available { background: #dcfce7; color: #166534; }
    .status-badge.borrowed  { background: #ffedd5; color: #c2410c; }
    .status-badge.requested { background: #dbeafe; color: #1d4ed8; }
    .status-badge.archived  { background: #f3f4f6; color: #4b5563; }
    .status-badge.lost      { background: #fee2e2; color: #b91c1c; }
    
    /* Table Styling */
    .table-responsive { overflow-x: auto; }
    .modern-table { width: 100%; border-collapse: collapse; }
    .modern-table th { text-align: left; padding: 12px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; }
    .modern-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
    .modern-table tbody tr:hover { background: #f9fafb; }

    /* Pagination Styles */
    .pagination-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-top: 1px solid #e5e7eb;
        background: #f9fafb;
        border-bottom-left-radius: 10px;
        border-bottom-right-radius: 10px;
    }
    .pagination-info {
        font-size: 0.85rem;
        color: #6b7280;
    }
    .pagination-links {
        display: flex;
        gap: 4px;
    }
    .page-btn {
        padding: 6px 12px;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #374151;
        text-decoration: none;
        border-radius: 6px;
        font-size: 0.85rem;
        transition: all 0.2s;
        cursor: pointer;
    }
    .page-btn:hover {
        background: #f3f4f6;
        border-color: #9ca3af;
    }
    .page-btn.active {
        background: #2563eb;
        color: white;
        border-color: #2563eb;
    }
</style>

<?php require_once '../includes/footer.php'; ?>