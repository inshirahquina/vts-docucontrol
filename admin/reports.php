<?php  
require_once '../config/db.php';
require_once '../config/functions.php';

if (!isAdmin()) {
    redirect('../index.php');
}

// --- DATE RANGE LOGIC ---
$default_from = date('Y-m-01'); 
$default_to = date('Y-m-d');    // Today

$date_from = $_GET['date_from'] ?? $default_from;
$date_to = $_GET['date_to'] ?? $default_to;

// --- FILTERS ---
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['statuses'] ?? [];

// --- EXPORT TO EXCEL LOGIC ---
if (isset($_GET['export'])) {
    $whereClause = "WHERE DATE(fh.created_at) BETWEEN :from AND :to";
    $params = [':from' => $date_from, ':to' => $date_to];

    if (!empty($searchTerm)) {

        $whereClause .= "
            AND (
                f.file_name LIKE :search_file
                OR u.full_name LIKE :search_user
                OR f.department LIKE :search_dept
            )
        ";

        $params[':search_file'] = "%$searchTerm%";
        $params[':search_user'] = "%$searchTerm%";
        $params[':search_dept'] = "%$searchTerm%";
    }
    if (!empty($statusFilter)) {
        $placeholders = [];
        foreach ($statusFilter as $k => $status) {
            $placeholders[] = ":st$k";
            $params[":st$k"] = $status;
        }
        $whereClause .= " AND fh.action IN (" . implode(',', $placeholders) . ")";
    }

    $sql = "SELECT fh.created_at, fh.action, fh.performed_role, f.department, f.file_name, 
                   u.full_name, u.username, r.released_at, r.retrieved_at, u_assign.full_name as assigned_name
            FROM file_history fh
            JOIN files f ON fh.file_id = f.id
            LEFT JOIN users u ON fh.performed_by = u.id
            LEFT JOIN requests r ON fh.request_id = r.id
            LEFT JOIN users u_assign ON r.assigned_to = u_assign.id
            $whereClause ORDER BY fh.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Generate Excel
    $filename = "Audit_Report_{$date_from}_to_{$date_to}.xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    echo '<tr style="background-color:#f3f4f6; font-weight:bold;">
            <th>Timestamp</th><th>User (Role)</th><th>Action</th><th>Department</th><th>File Name</th><th>Processing Time</th>
          </tr>';

    foreach ($data as $row) {
        // Smart User Logic
        $userDisplay = 'System';
        if (!empty($row['full_name'])) $userDisplay = $row['full_name'];
        elseif (!empty($row['assigned_name'])) $userDisplay = $row['assigned_name'] . ' (Op)';
        elseif (!empty($row['username'])) $userDisplay = $row['username'];
        
        $role = $row['performed_role'] ?? 'System';
        $userWithRole = "$userDisplay ($role)";

        // Duration Logic
        $durationText = '-';
        if ($row['action'] === 'Released' && $row['retrieved_at'] && $row['released_at']) {
            $diff = (new DateTime($row['retrieved_at']))->diff(new DateTime($row['released_at']));
            $mins = ($diff->h * 60) + $diff->i;
            $durationText = $mins . ' mins';
            if ($mins > 20) $durationText .= ' (Over SLA)';
        }

        echo '<tr>';
        echo '<td>' . $row['created_at'] . '</td>';
        echo '<td>' . $userWithRole . '</td>';
        echo '<td>' . $row['action'] . '</td>';
        echo '<td>' . $row['department'] . '</td>';
        echo '<td>' . $row['file_name'] . '</td>';
        echo '<td>' . $durationText . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
}

// --- PAGINATION SETUP ---
$perPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$offset = ($page - 1) * $perPage;

// --- BUILD WHERE CLAUSE FOR VIEW ---
$whereClause = "WHERE DATE(fh.created_at) BETWEEN :from AND :to";
$params = [':from' => $date_from, ':to' => $date_to];

if (!empty($searchTerm)) {

    $whereClause .= "
        AND (
            f.file_name LIKE :search_file
            OR u.full_name LIKE :search_user
            OR f.department LIKE :search_dept
        )
    ";

    $params[':search_file'] = "%$searchTerm%";
    $params[':search_user'] = "%$searchTerm%";
    $params[':search_dept'] = "%$searchTerm%";
}

if (!empty($statusFilter)) {
    $placeholders = [];
    foreach ($statusFilter as $k => $status) {
        $placeholders[] = ":st$k";
        $params[":st$k"] = $status;
    }
    $whereClause .= " AND fh.action IN (" . implode(',', $placeholders) . ")";
}

// Count Total
$countSQL = "SELECT COUNT(*) FROM file_history fh JOIN files f ON fh.file_id = f.id LEFT JOIN users u ON fh.performed_by = u.id $whereClause";
$stmtCount = $pdo->prepare($countSQL);
$stmtCount->execute($params);
$totalRows = $stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$startResult = $totalRows > 0 ? $offset + 1 : 0;
$endResult = min($offset + $perPage, $totalRows);

// Fetch Data
$sql = "SELECT fh.created_at, fh.action, fh.performed_by, fh.performed_role, f.department, f.file_name, 
               u.full_name, u.username, r.released_at, r.retrieved_at, u_assign.full_name as assigned_name
        FROM file_history fh
        JOIN files f ON fh.file_id = f.id
        LEFT JOIN users u ON fh.performed_by = u.id
        LEFT JOIN requests r ON fh.request_id = r.id
        LEFT JOIN users u_assign ON r.assigned_to = u_assign.id
        $whereClause
        ORDER BY fh.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) $stmt->bindValue($key, $val);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Available Statuses for Filter
$statusList = ['Requested (Pending HOD)', 'Approved by HOD', 'Retrieval Assigned', 'File Retrieved', 'Released', 'Return Requested', 'Restoration Assigned', 'File Restored', 'Completed', 'Cancelled'];

require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>Audit Logs & Reports</h1>
                    <p>System activity history and C-OTF monitoring.</p>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar-card">
                <form method="GET" action="reports.php" class="filter-form-inline">
                    
                    <div class="filter-group">
                        <label>From:</label>
                        <input type="date" name="date_from" class="modern-input" value="<?= htmlspecialchars($date_from) ?>">
                    </div>

                    <div class="filter-group">
                        <label>To:</label>
                        <input type="date" name="date_to" class="modern-input" value="<?= htmlspecialchars($date_to) ?>">
                    </div>

                    <div class="dropdown-filter">
                        <button type="button" class="dropdown-btn" onclick="toggleStatusDropdown()">
                            <span>Filter Status</span>
                            <?php if(!empty($statusFilter)): ?>
                                <span class="badge-count"><?= count($statusFilter) ?></span>
                            <?php endif; ?>
                            <span class="arrow">▼</span>
                        </button>

                        <div id="statusDropdown" class="dropdown-content">
                            <?php foreach($statusList as $st): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox"
                                        name="statuses[]"
                                        value="<?= $st ?>"
                                        <?= in_array($st, $statusFilter) ? 'checked' : '' ?>>
                                    <span><?= $st ?></span>
                                </label>
                            <?php endforeach; ?>

                            <div class="dropdown-actions">
                                <button type="submit" class="btn-apply">Apply</button>
                                <a href="reports.php" class="btn-reset">Reset</a>
                            </div>
                        </div>
                    </div>

                    <div class="search-box-modern" style="min-width: 200px; flex: 0;">
                        <input type="text" name="search" placeholder="Search File/User..." value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>

                    <button type="submit" class="btn btn-dark">Filter</button>
                    
                    <a href="?export=1&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&search=<?= urlencode($searchTerm) ?><?= !empty($statusFilter) ? '&statuses[]='.implode('&statuses[]=', $statusFilter) : '' ?>" class="btn btn-success">
                        Export Excel
                    </a>
                </form>
            </div>

            <!-- Data Table Card -->
            <div class="card table-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Department</th>
                                <th>File Name</th>
                                <th>Duration (SLA)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($logs): ?>
                                <?php foreach($logs as $row): 
                                    // Smart User Logic
                                    $userDisplay = 'Unknown';
                                    if (!empty($row['full_name'])) $userDisplay = $row['full_name'];
                                    elseif (!empty($row['assigned_name'])) $userDisplay = $row['assigned_name'] . ' (Op)';
                                    elseif (!empty($row['username'])) $userDisplay = $row['username'];
                                    
                                    $role = $row['performed_role'] ?? 'System';
                                    
                                    // Duration Logic
                                    $duration = 0;
                                    $durationText = '-';
                                    $isOverSLA = false;
                                    
                                    if ($row['action'] === 'Released' && $row['retrieved_at'] && $row['released_at']) {
                                        $diff = (new DateTime($row['retrieved_at']))->diff(new DateTime($row['released_at']));
                                        $duration = ($diff->h * 60) + $diff->i;
                                        $durationText = $duration . ' mins';
                                        if ($duration > 20) $isOverSLA = true;
                                    }
                                ?>
                                <tr class="<?= $isOverSLA ? 'row-danger' : '' ?>">
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-text"><?= date('M j, Y', strtotime($row['created_at'])) ?></span>
                                            <span class="cell-sub"><?= date('g:i A', strtotime($row['created_at'])) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cell-main">
                                            <span class="cell-text"><?= sanitize($userDisplay) ?></span>
                                            <span class="cell-sub"><?= sanitize($role) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $isOverSLA ? 'status-red' : 'status-gray' ?>">
                                            <?= sanitize($row['action']) ?>
                                            <?php if($isOverSLA): ?> (OTF) <?php endif; ?>
                                        </span>
                                    </td>
                                    <td><?= sanitize($row['department']) ?></td>
                                    <td>
                                         <span class="cell-text"><?= sanitize($row['file_name']) ?></span>
                                    </td>
                                    <td>
                                        <?php if($isOverSLA): ?>
                                            <span style="color:#b91c1c; font-weight:700;"><?= $durationText ?></span>
                                        <?php else: ?>
                                            <span class="cell-sub"><?= $durationText ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-cell">
                                        <div class="empty-state">
                                            <h3>No Logs Found</h3>
                                            <p>No activity matches your selected date range or filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-info">
                    Showing <?= $startResult ?> - <?= $endResult ?> of <?= $totalRows ?> results
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            ← Prev
                        </a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 4);
                    $end = min($totalPages, $start + 9);

                    for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-btn <?= $i == $page ? 'active' : '' ?>"
                        href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            Next →
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleStatusDropdown() {
    document.getElementById("statusDropdown").classList.toggle("show-dropdown");
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById("statusDropdown");
    const btn = document.querySelector(".dropdown-btn");

    if (!btn.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove("show-dropdown");
    }
});
</script>

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

.search-box-modern { position: relative; flex: 1; min-width: 250px; }
.search-box-modern input { width: 100%; padding: 10px 15px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; background: #f9fafb; }

/* Table */
.table-responsive { overflow-x: auto; }
.modern-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.modern-table th { text-align: left; padding: 12px 20px; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; background: #f9fafb; border-bottom: 1px solid #e5e7eb; letter-spacing: 0.05em; }
.modern-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }

.cell-main { display: flex; flex-direction: column; gap: 2px; }
.cell-title { font-weight: 600; color: #111827; font-size: 0.95rem; }
.cell-text { font-weight: 500; color: #374151; font-size: 0.9rem; }
.cell-sub { font-size: 0.8rem; color: #9ca3af; }

.row-danger { background-color: #fef2f2; }
.row-danger:hover { background-color: #fee2e2; }

/* Status Badges */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.status-green { background: #dcfce7; color: #166534; }
.status-orange { background: #ffedd5; color: #c2410c; }
.status-red { background: #fee2e2; color: #991b1b; }
.status-gray { background: #f3f4f6; color: #4b5563; }

/* Buttons */
.btn { padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.btn-secondary { background: #f3f4f6; color: #374151; }
.btn-dark { background: #1f2937; color: white; }
.btn-success { background: #059669; color: white; }
.btn-success:hover { background: #047857; }

/* Empty State */
.empty-cell { padding: 40px; text-align: center; }
.empty-state h3 { margin: 0 0 5px 0; color: #374151; }
.empty-state p { margin: 0; color: #6b7280; font-size: 0.9rem; }

/* Pagination */
/* Pagination */
.pagination-info{
    text-align:center;
    font-size:0.85rem;
    color:#6b7280;
    margin-top:15px;
}

.pagination-wrapper{
    display:flex;
    gap:6px;
    justify-content:center;
    padding:20px 0;
    flex-wrap:wrap;
}

.page-btn{
    padding: 6px 12px;
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.85rem;
    color: #374151;
    transition: all 0.2s;
}

.page-btn.active{
    background: #111827;
    color: white;
    border-color: #111827;
}

.page-btn:hover{
    background: #f3f4f6;
}

.dropdown-filter { position: relative; }

.dropdown-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 15px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    color: #374151;
}

.dropdown-content {
    display: none;
    position: absolute;
    top: 110%;
    right: 0;
    width: 260px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    z-index: 100;
    padding: 15px;
    border: 1px solid #e5e7eb;
    max-height: 300px;
    overflow-y: auto;
}

.show-dropdown { display: block !important; }

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
    cursor: pointer;
    font-size: 0.9rem;
    color: #374151;
}

.badge-count {
    background: #3b82f6;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.dropdown-actions {
    margin-top: 10px;
    border-top: 1px solid #eee;
    padding-top: 10px;
    display: flex;
    gap: 10px;
}

.btn-apply {
    flex: 1;
    padding: 8px;
    background: #111827;
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
}

.btn-reset {
    flex: 1;
    text-align: center;
    padding: 8px;
    background: #f3f4f6;
    color: #374151;
    border-radius: 5px;
    text-decoration: none;
    font-size: 0.85rem;
}
</style>

<?php require_once '../includes/footer.php'; ?>