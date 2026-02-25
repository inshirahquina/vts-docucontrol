<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
}

$uid = $_SESSION['user_id'];

/* ===============================
   DATE FILTER
================================= */

$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$date_from   = $_GET['date_from'] ?? $default_from;
$date_to     = $_GET['date_to'] ?? $default_to;
$action_type = $_GET['type'] ?? '';

/* ===============================
   BASE WHERE CLAUSE
================================= */

$where = "r.assigned_to = :uid 
          AND DATE(fh.created_at) BETWEEN :from AND :to";

$params = [
    ':uid'  => $uid,
    ':from' => $date_from,
    ':to'   => $date_to
];

/* ===============================
   ACTION FILTER
================================= */

if (!empty($action_type)) {
    if ($action_type === 'retrieval') {
        $where .= " AND fh.action = 'File Retrieved'";
    } elseif ($action_type === 'restoration') {
        $where .= " AND fh.action = 'File Restored'";
    }
}

/* ===============================
   EXPORT SECTION
================================= */

if (isset($_GET['export'])) {

    $sql = "SELECT 
                fh.action,
                fh.created_at,
                f.file_name,
                f.department,
                f.allocation,
                u_req.full_name AS requester_name
            FROM file_history fh
            JOIN requests r ON fh.request_id = r.id
            JOIN files f ON fh.file_id = f.id
            JOIN users u_req ON r.user_id = u_req.id
            WHERE $where
            ORDER BY fh.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    $filename = "Operations_Report_" . date('Ymd_His') . ".xls";

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    echo '<tr style="background-color:#f3f4f6; font-weight:bold;">
            <th>File Name</th>
            <th>Department</th>
            <th>Allocation</th>
            <th>Requester</th>
            <th>Action</th>
            <th>Date</th>
          </tr>';

    foreach ($data as $row) {
        $actionText = ($row['action'] === 'File Restored') ? 'Restoration' : 'Retrieval';

        echo '<tr>';
        echo '<td>' . sanitize($row['file_name']) . '</td>';
        echo '<td>' . sanitize($row['department']) . '</td>';
        echo '<td>' . sanitize($row['allocation']) . '</td>';
        echo '<td>' . sanitize($row['requester_name']) . '</td>';
        echo '<td>' . $actionText . '</td>';
        echo '<td>' . date('M j, Y g:i A', strtotime($row['created_at'])) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
    exit;
}

/* ===============================
   PAGINATION
================================= */

$records_per_page = 10;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

/* ===============================
   COUNT TOTAL
================================= */

$countSql = "SELECT COUNT(*)
             FROM file_history fh
             JOIN requests r ON fh.request_id = r.id
             WHERE $where
             AND fh.action IN ('File Retrieved','File Restored')";

$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$total_records = $stmtCount->fetchColumn();
$total_pages   = ceil($total_records / $records_per_page);

/* ===============================
   FETCH DATA
================================= */
$sql = "SELECT 
            fh.action,
            fh.created_at,
            f.file_name,
            f.department,
            u_req.full_name AS requester_name
        FROM file_history fh
        JOIN requests r ON fh.request_id = r.id
        JOIN files f ON fh.file_id = f.id
        JOIN users u_req ON r.user_id = u_req.id
        WHERE $where
        AND fh.action IN ('File Retrieved','File Restored')
        ORDER BY fh.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}

$stmt->bindValue(':limit',  $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$history = $stmt->fetchAll();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Activity History & Reports</h1>
                    <p>Generate reports or browse your past activity.</p>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar-card">
                <form method="GET" action="history.php" class="filter-form-inline">
                    
                    <div class="filter-group">
                        <label>From:</label>
                        <input type="date" name="date_from" class="modern-input" value="<?= htmlspecialchars($date_from) ?>">
                    </div>

                    <div class="filter-group">
                        <label>To:</label>
                        <input type="date" name="date_to" class="modern-input" value="<?= htmlspecialchars($date_to) ?>">
                    </div>

                    <div class="filter-item">
                        <select name="type" class="modern-select">
                            <option value="">All Actions</option>
                            <option value="retrieval" <?= ($action_type == 'retrieval') ? 'selected' : '' ?>>Retrievals</option>
                            <option value="restoration" <?= ($action_type == 'restoration') ? 'selected' : '' ?>>Restorations</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-dark">Filter</button>
                    
                    <!-- Export Button (Keeps current filters) -->
                    <a href="?export=true&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&type=<?= $action_type ?>" class="btn btn-success">
                        Export to Excel
                    </a>
                </form>
            </div>

            <!-- Data Table Card -->
            <div class="card table-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>File Details</th>
                                <th>Requester</th>
                                <th>Action Type</th>
                                <th>Completed At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                           <?php if(count($history) > 0): ?>
                                <?php foreach($history as $row): 

                                    if ($row['action'] === 'File Retrieved') {
                                        $actionType = 'Retrieval';
                                        $actionIcon = '📤';
                                        $badgeClass = 'status-orange';
                                        $badgeText  = 'Retrieved';
                                    } elseif ($row['action'] === 'File Restored') {
                                        $actionType = 'Restoration';
                                        $actionIcon = '📥';
                                        $badgeClass = 'status-green';
                                        $badgeText  = 'Restored';
                                    } else {
                                        continue;
                                    }

                                ?>
                                <tr>
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-title"><?= sanitize($row['file_name']) ?></span>
                                            <span class="cell-sub"><?= sanitize($row['department']) ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <?= sanitize($row['requester_name']) ?>
                                    </td>

                                    <td>
                                        <?= $actionIcon ?> <?= $actionType ?>
                                    </td>

                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-text"><?= date('M j, Y', strtotime($row['created_at'])) ?></span>
                                            <span class="cell-sub"><?= date('g:i A', strtotime($row['created_at'])) ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="status-badge <?= $badgeClass ?>">
                                            <?= $badgeText ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <div class="pagination-info">
                        Showing <?= min($total_records, $offset + 1) ?> - <?= min($total_records, $offset + $records_per_page) ?> of <?= $total_records ?> records
                    </div>
                    <div class="pagination-links">
                        <?php 
                        // Preserve query parameters
                        $query_params = $_GET;
                        unset($query_params['page']); 
                        $query_string = http_build_query($query_params);
                        if(!empty($query_string)) $query_string = '&'.$query_string;
                        ?>

                        <?php if($page > 1): ?>
                            <a href="?page=1<?= $query_string ?>" class="page-btn">«</a>
                            <a href="?page=<?= $page-1 ?><?= $query_string ?>" class="page-btn">‹</a>
                        <?php endif; ?>

                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if($i == $page): ?>
                                <span class="page-btn active"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $i ?><?= $query_string ?>" class="page-btn"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if($page < $total_pages): ?>
                            <a href="?page=<?= $page+1 ?><?= $query_string ?>" class="page-btn">›</a>
                            <a href="?page=<?= $total_pages ?><?= $query_string ?>" class="page-btn">»</a>
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

/* Cards */
.card { background: #fff; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.table-card { overflow: hidden; }

/* Filter Bar */
.filter-bar-card { padding: 16px 20px; margin-bottom: 16px; }
.filter-form-inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.filter-group { display: flex; align-items: center; gap: 8px; }
.filter-group label { font-size: 0.85rem; color: #6b7280; font-weight: 500; }

.modern-input { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; background: #f9fafb; }
.modern-input:focus { border-color: #2563eb; background: #fff; outline: none; }

.modern-select { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; font-size: 0.9rem; min-width: 150px; }

/* Table */
.table-responsive { overflow-x: auto; }
.modern-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.modern-table th { text-align: left; padding: 12px 20px; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; background: #f9fafb; border-bottom: 1px solid #e5e7eb; letter-spacing: 0.05em; }
.modern-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }

.cell-main { display: flex; flex-direction: column; gap: 2px; }
.cell-title { font-weight: 600; color: #111827; font-size: 0.95rem; }
.cell-text { font-weight: 500; color: #374151; font-size: 0.9rem; }
.cell-sub { font-size: 0.8rem; color: #9ca3af; }

.empty-cell { padding: 40px; text-align: center; }
.empty-state h3 { margin: 0 0 5px 0; color: #374151; }
.empty-state p { margin: 0; color: #6b7280; font-size: 0.9rem; }

/* Status Badges */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.status-green { background: #dcfce7; color: #166534; }
.status-orange { background: #ffedd5; color: #c2410c; }
.status-blue { background: #dbeafe; color: #1d4ed8; }
.status-gray { background: #f3f4f6; color: #4b5563; }

/* Buttons */
.btn { padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-secondary { background: #f3f4f6; color: #374151; }
.btn-secondary:hover { background: #e5e7eb; }
.btn-dark { background: #1f2937; color: white; }
.btn-dark:hover { background: #111827; }
.btn-success { background: #059669; color: white; }
.btn-success:hover { background: #047857; }

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
.pagination-info { font-size: 0.85rem; color: #6b7280; }
.pagination-links { display: flex; gap: 4px; }
.page-btn {
    padding: 6px 12px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.page-btn:hover { background: #f3f4f6; border-color: #9ca3af; }
.page-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
</style>

<?php require_once '../includes/footer.php'; ?>