<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/functions.php';

if (!isset($_SESSION['user_id'])) redirect('../auth/login.php');

$base_role = $_SESSION['role'] ?? '';
if ($base_role !== 'requestor' && $base_role !== 'hod') {
    redirect('../index.php');
}

$uid = $_SESSION['user_id'];

// ===============================
// ✅ GET USER DEPARTMENT
// ===============================
$stmtUser = $pdo->prepare("SELECT department FROM users WHERE id = ?");
$stmtUser->execute([$uid]);
$currentUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

$userDept = $currentUser['department'] ?? '';

if (!$userDept) {
    die("Department not assigned to your account. Please contact admin.");
}

// ===============================
// ✅ PAGINATION
// ===============================
$perPage = 15;
$page    = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// ===============================
// ✅ STATUS CONFIG
// ===============================
$statusConfig = [
    'Requested'            => ['📝', 'Requested', 'bg-blue-100 text-blue-800'],
    'Pending HOD Approval' => ['⏳', 'Pending HOD', 'bg-amber-100 text-amber-800'],
    'Extension Requested'  => ['⏰', 'Extension Requested', 'bg-purple-100 text-purple-800'],
    'Approved by HOD'      => ['✅', 'Approved by HOD', 'bg-green-100 text-green-800'],
    'Retrieval Assigned'   => ['🛠️', 'Retrieval Assigned', 'bg-orange-100 text-orange-800'],
    'File Retrieved'       => ['📦', 'File Retrieved', 'bg-orange-100 text-orange-800'],
    'Released'             => ['📂', 'Released', 'bg-emerald-100 text-emerald-800'],
    'Return Requested'     => ['↩️', 'Return Requested', 'bg-rose-100 text-rose-800'],
    'Restoration Assigned' => ['📦', 'Restoration Assigned', 'bg-orange-100 text-orange-800'],
    'File Restored'        => ['✅', 'File Restored', 'bg-green-100 text-green-800'],
    'Completed'            => ['✔️', 'Completed', 'bg-gray-100 text-gray-600'],
    'Cancelled'            => ['🚫', 'Cancelled', 'bg-red-100 text-red-800']
];

// ===============================
// ✅ FILTERS
// ===============================
$searchTerm = $_GET['search'] ?? '';

$searchSQL = $searchTerm 
    ? " AND (f.file_name LIKE :s1 OR f.allocation LIKE :s2 OR u.full_name LIKE :s3)" 
    : "";

// FIX index
$statusFilter = array_values(array_filter($_GET['statuses'] ?? []));

$statusSQL = "";
$currentPlaceholders = [];
$extensionPlaceholders = [];

if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) {
        $currentPlaceholders[]   = ":status_current_" . $k;
        $extensionPlaceholders[] = ":status_ext_" . $k;
    }

    $statusSQL = " AND (
        r.current_status IN (" . implode(',', $currentPlaceholders) . ")
        OR (
            r.status = 'extension_requested'
            AND 'Extension Requested' IN (" . implode(',', $extensionPlaceholders) . ")
        )
    )";
}

// ===============================
// ✅ COUNT QUERY
// ===============================
$countSQL = "
    SELECT COUNT(*)
    FROM requests r
    JOIN files f ON r.file_id = f.id
    JOIN users u ON r.user_id = u.id
    WHERE u.department = :department
    $searchSQL
    $statusSQL
";

$stmtCount = $pdo->prepare($countSQL);
$stmtCount->bindValue(':department', $userDept);

// FIX search binding
if ($searchTerm) {
    $stmtCount->bindValue(':s1', "%$searchTerm%");
    $stmtCount->bindValue(':s2', "%$searchTerm%");
    $stmtCount->bindValue(':s3', "%$searchTerm%");
}

// FIX status binding
if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) {
        $stmtCount->bindValue(":status_current_" . $k, $s);
        $stmtCount->bindValue(":status_ext_" . $k, $s);
    }
}

$stmtCount->execute();
$totalRows = $stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// ===============================
// ✅ MAIN QUERY
// ===============================
$sql = "
    SELECT 
        r.*,
        f.file_name,
        f.allocation,
        f.box_no,
        f.department AS file_department,
        u.full_name AS requester_name,
        u.department AS requester_department,
        GREATEST(
            COALESCE(r.updated_at, '1970-01-01'),
            COALESCE(r.released_at, '1970-01-01'),
            COALESCE(r.retrieved_at, '1970-01-01'),
            COALESCE(r.borrow_date, '1970-01-01')
        ) AS last_activity
    FROM requests r
    JOIN files f ON r.file_id = f.id
    JOIN users u ON r.user_id = u.id
    WHERE u.department = :department
    $searchSQL
    $statusSQL
    ORDER BY
        CASE
            WHEN r.status = 'extension_requested' THEN 1
            WHEN r.current_status = 'Requested' THEN 2
            WHEN r.current_status = 'Pending HOD Approval' THEN 3
            WHEN r.current_status = 'Approved by HOD' THEN 4
            WHEN r.current_status = 'Retrieval Assigned' THEN 5
            WHEN r.current_status = 'File Retrieved' THEN 6
            WHEN r.current_status = 'Released' THEN 7
            WHEN r.current_status = 'Return Requested' THEN 8
            WHEN r.current_status = 'Restoration Assigned' THEN 9
            WHEN r.current_status = 'File Restored' THEN 10
            WHEN r.current_status = 'Completed' THEN 11
            WHEN r.current_status = 'Cancelled' THEN 12
            ELSE 13
        END,
        last_activity DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':department', $userDept);

// FIX search binding
if ($searchTerm) {
    $stmt->bindValue(':s1', "%$searchTerm%");
    $stmt->bindValue(':s2', "%$searchTerm%");
    $stmt->bindValue(':s3', "%$searchTerm%");
}

// FIX status binding
if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) {
        $stmt->bindValue(":status_current_" . $k, $s);
        $stmt->bindValue(":status_ext_" . $k, $s);
    }
}

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$releasedCount = 0;
foreach ($rows as $r) {
    if (($r['current_status'] ?? '') === 'Released') {
        $releasedCount++;
    }
}

require_once '../includes/header.php';
?>

<div class="layout-wrapper" id="deptFilesRoot">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">

            <div class="page-header-row">
                <div class="page-title">
                    <h1>Department Files</h1>
                    <p>View department file requests and help return or extend released files</p>
                </div>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="flash-banner success"><?= sanitize($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="filter-bar-card list-controls-card">
                <form method="GET" class="filter-form-inline">

                    <div class="search-box-modern">
                        <span class="icon">🔍</span>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by file name, allocation, requester..." 
                            value="<?= htmlspecialchars($searchTerm) ?>"
                        >

                        <?php if($searchTerm): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['search' => ''])) ?>" class="clear-search">×</a>
                        <?php endif; ?>
                    </div>

                    <select name="statuses[]" class="status-select" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <?php foreach($statusConfig as $key => $props): ?>
                            <option value="<?= $key ?>" <?= in_array($key, $statusFilter) ? 'selected' : '' ?>>
                                <?= $props[1] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn-filter">Filter</button>
                </form>

                <?php if ($releasedCount > 0): ?>
                <div class="bulk-list-toolbar" id="bulkListToolbar">
                    <label class="bulk-mode-control">
                        <input type="checkbox" id="selectAllBulk">
                        <span class="bulk-mode-label">Select Files</span>
                    </label>
                    <span class="bulk-select-hint" id="bulkStripCount">None selected</span>
                    <button type="button" class="bulk-cancel-btn" id="bulkExitBtn" hidden>Cancel</button>
                </div>
                <?php endif; ?>
            </div>

            <div class="dept-info-card">
                Department: <strong><?= sanitize($userDept) ?></strong>
            </div>

            <div class="files-list-container">
                <?php if($rows): ?>
                    <?php foreach($rows as $row):

                        $statusKey = $row['current_status'] ?: 'Completed';

                        if ($row['status'] === 'extension_requested') {
                            $statusKey = 'Extension Requested';
                        }

                        if (!isset($statusConfig[$statusKey])) {
                            $statusKey = 'Completed';
                        }

                        [$icon, $label, $classes] = $statusConfig[$statusKey];

                        $releasedAt = $row['released_at'];
                        $dueDate = $row['due_date'] ?? null;

                        $overDue = (
                            $statusKey === 'Released' &&
                            $dueDate &&
                            date('Y-m-d') > date('Y-m-d', strtotime($dueDate))
                        );

                        // Same gates as original Return File / Extend
                        $canReturn = ($statusKey === 'Released');
                        $canExtend = ($canReturn && !$overDue && $row['extension_count'] < 2);
                    ?>

                    <div class="file-card bulk-item <?= $overDue ? 'is-overdue' : '' ?>"
                         data-selectable="<?= $canReturn ? '1' : '0' ?>"
                         data-request-id="<?= (int)$row['id'] ?>">
                        <div class="card-main">
                            <?php if ($canReturn): ?>
                            <div class="bulk-checkbox-wrap">
                                <input type="checkbox"
                                       class="bulk-checkbox"
                                       value="<?= (int)$row['id'] ?>"
                                       aria-label="Select <?= sanitize($row['file_name']) ?>">
                            </div>
                            <?php endif; ?>
                            <div class="file-identity">
                                <h4 class="file-title"><?= sanitize($row['file_name']) ?></h4>

                                <div class="file-remark">
                                    👤 Requested by: <?= sanitize($row['requester_name']) ?>
                                    <?php if($row['user_id'] == $uid): ?>
                                        <span class="own-label">Your request</span>
                                    <?php endif; ?>
                                </div>

                                <?php if(!empty($row['remarks'])): ?>
                                    <div class="file-remark">
                                        📝 <?= sanitize($row['remarks']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="file-meta-badges">
                                    <span class="meta-badge dark"><?= sanitize($row['allocation']) ?></span>
                                    <span class="meta-badge gray"><?= sanitize($row['file_department']) ?></span>
                                </div>
                            </div>

                            <div class="file-timeline">
                                <div class="time-row">
                                    <span class="label">Requested</span>
                                    <span class="value"><?= date('M j, Y', strtotime($row['borrow_date'])) ?></span>
                                </div>

                                <?php if($releasedAt): ?>
                                    <div class="time-row">
                                        <span class="label">Released</span>
                                        <span class="value"><?= date('M j, Y', strtotime($releasedAt)) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if($dueDate): ?>
                                    <div class="time-row <?= $overDue ? 'danger' : '' ?>">
                                        <span class="label">Due Date</span>
                                        <span class="value bold">
                                            <?= date('M j, Y', strtotime($dueDate)) ?>

                                            <?php if($row['extension_count'] > 0): ?>
                                                <small>(Ext. x<?= $row['extension_count'] ?>)</small>
                                            <?php endif; ?>
                                        </span>

                                        <?php if($overDue): ?>
                                            <span class="overdue-alert">⚠ Overdue</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if($statusKey === 'Extension Requested'): ?>
                                    <div class="time-row">
                                        <span class="label">Extension</span>
                                        <span class="value">Request #<?= sanitize($row['extension_count']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if($row['current_status'] === 'Completed' && !empty($row['return_date'])): ?>
                                    <div class="time-row">
                                        <span class="label">Returned</span>
                                        <span class="value"><?= date('M j, Y', strtotime($row['return_date'])) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-status-actions">
                            <div class="status-wrapper">
                                <span class="modern-badge <?= $classes ?>">
                                    <?= $icon ?> <?= $label ?>
                                </span>
                            </div>

                            <div class="action-buttons">
                                <?php if($canReturn): ?>
                                    <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Confirm return request for this file?');" style="display:inline;" class="no-bulk-toggle">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="action" value="request_return">
                                        <input type="hidden" name="redirect_to" value="department_files">
                                        <button class="btn-sm primary">Return File</button>
                                    </form>
                                <?php endif; ?>

                                <?php if($canExtend): ?>
                                    <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Request 3-day extension for this file?');" style="display:inline;" class="no-bulk-toggle">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="action" value="request_extension">
                                        <input type="hidden" name="redirect_to" value="department_files">
                                        <button class="btn-sm warning-outline">Extend</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state-container">
                        <div class="empty-icon">📂</div>
                        <h3>No Department Files Found</h3>
                        <p>No file requests found under your department.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Showing <?= ($offset + 1) ?> - <?= min($page * $perPage, $totalRows) ?> of <?= $totalRows ?>
                    </div>

                    <div class="pagination-controls">
                        <?php if($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$page-1])) ?>" class="page-btn">← Prev</a>
                        <?php endif; ?>

                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>" class="page-btn num <?= $i == $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$page+1])) ?>" class="page-btn">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<div class="bulk-toolbar" id="bulkToolbar" aria-hidden="true">
    <div class="bulk-toolbar-count" id="bulkSelectedCount">0 files selected</div>
    <div class="bulk-toolbar-actions">
        <button type="button" class="btn-bulk btn-bulk-ghost" id="cancelSelectionBtn">Cancel Selection</button>
        <form id="bulkReturnForm" method="POST" action="../actions/request_actions.php" data-bulk-ids>
            <input type="hidden" name="action" value="bulk_request_return">
            <input type="hidden" name="redirect_to" value="department_files">
            <button type="submit" class="btn-bulk btn-bulk-primary">Return Selected Files</button>
        </form>
    </div>
</div>

<style>
.content-area { padding: 28px 32px 32px; background: #f8fafc; font-family: var(--bs-font, 'IBM Plex Sans', sans-serif); }
.page-title h1 { font-size: 1.5rem; margin: 0 0 4px 0; color: #111827; font-weight: 700; }
.page-title p { margin: 0; color: #6b7280; font-size: 0.9rem; }

.filter-bar-card:not(.list-controls-card) { background: #fff; padding: 16px 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 12px; }
.filter-form-inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

.search-box-modern { position: relative; flex: 1; min-width: 250px; }
.search-box-modern .icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
.search-box-modern input { width: 100%; padding: 10px 32px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; background: #f9fafb; }
.search-box-modern input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); outline: none; }
.clear-search { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 1.2rem; text-decoration: none; }

.status-select {
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    color: #374151;
    font-size: 0.9rem;
}

.btn-filter {
    padding: 10px 16px;
    background: #0d47a1;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.dept-info-card {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 0.9rem;
    margin-bottom: 16px;
}

.files-list-container { display: flex; flex-direction: column; gap: 12px; }

.file-card {
    background: #fff;
    border-radius: 10px;
    border: 1px solid #f3f4f6;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.file-card.is-overdue { border-left: 4px solid #ef4444; background: #fffafa; }

.card-main { flex: 3; display: flex; gap: 24px; align-items: center; }
.file-identity { min-width: 240px; }

.file-title { margin: 0 0 6px 0; font-size: 1rem; font-weight: 600; color: #111827; }

.file-remark {
    font-size: 0.8rem;
    color: #6b7280;
    margin-bottom: 6px;
}

.own-label {
    display: inline-block;
    margin-left: 6px;
    font-style: normal;
    background: #dbeafe;
    color: #1e40af;
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
}

.file-meta-badges { display: flex; gap: 6px; flex-wrap: wrap; }

.meta-badge {
    font-size: 0.75rem;
    padding: 3px 8px;
    border-radius: 4px;
    font-weight: 500;
}

.meta-badge.dark { background: #f3f4f6; color: #374151; }
.meta-badge.gray { background: #fff; border: 1px solid #e5e7eb; color: #6b7280; }

.file-timeline { display: flex; gap: 20px; flex-wrap: wrap; }

.time-row { display: flex; flex-direction: column; font-size: 0.85rem; }
.time-row .label { color: #9ca3af; font-size: 0.75rem; }
.time-row .value { color: #1f2937; font-weight: 500; }
.time-row.danger .value { color: #b91c1c; font-weight: 700; }

.overdue-alert {
    font-size: 0.7rem;
    background: #fee2e2;
    color: #b91c1c;
    padding: 2px 6px;
    border-radius: 4px;
    margin-top: 3px;
    font-weight: 600;
    width: fit-content;
}

.card-status-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
}

.modern-badge {
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Badge Colors */
.bg-blue-100 { background-color: #dbeafe; }
.text-blue-800 { color: #1e40af; }

.bg-amber-100 { background-color: #fef3c7; }
.text-amber-800 { color: #92400e; }

.bg-purple-100 { background-color: #ede9fe; }
.text-purple-800 { color: #5b21b6; }

.bg-emerald-100 { background-color: #d1fae5; }
.text-emerald-800 { color: #047857; }

.bg-rose-100 { background-color: #ffe4e6; }
.text-rose-800 { color: #9f1239; }

.bg-orange-100 { background-color: #ffedd5; }
.text-orange-800 { color: #9a3412; }

.bg-green-100 { background-color: #d1fae5; }
.text-green-800 { color: #065f46; }

.bg-gray-100 { background-color: #f3f4f6; }
.text-gray-600 { color: #4b5563; }

.bg-red-100 { background-color: #fee2e2; }
.text-red-800 { color: #991b1b; }

.action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-sm {
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
}

.btn-sm.primary { background: #0d47a1; color: #fff; }

.btn-sm.warning-outline {
    background: #fff;
    border: 1px solid #fcd34d;
    color: #b45309;
}

.empty-state-container {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border-radius: 12px;
    border: 1px dashed #d1d5db;
}

.empty-icon { font-size: 3rem; margin-bottom: 12px; opacity: 0.5; }

.pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0;
    margin-top: 10px;
}

.pagination-info { font-size: 0.85rem; color: #6b7280; }

.pagination-controls { display: flex; gap: 6px; flex-wrap: wrap; }

.page-btn {
    padding: 8px 14px;
    border-radius: 6px;
    background: #fff;
    border: 1px solid #e5e7eb;
    color: #374151;
    text-decoration: none;
    font-size: 0.85rem;
}

.page-btn.active {
    background: #0d47a1;
    color: #fff;
    border-color: #0d47a1;
}

@media (max-width: 768px) {
    .file-card { flex-direction: column; align-items: flex-start; gap: 16px; }
    .card-main { flex-direction: column; align-items: flex-start; gap: 12px; width: 100%; }
    .file-timeline { flex-direction: column; gap: 8px; }
    .card-status-actions { width: 100%; flex-direction: row; justify-content: space-between; align-items: center; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof BulkSelect === 'undefined' || !document.getElementById('selectAllBulk')) return;

    var bulk = BulkSelect.init({
        root: document.getElementById('deptFilesRoot'),
        modeContainer: document.getElementById('deptFilesRoot'),
        noun: 'file'
    });

    document.getElementById('bulkReturnForm')?.addEventListener('submit', function (e) {
        if (!bulk.fillForm(this)) {
            e.preventDefault();
            return;
        }
        if (!confirm('Return ' + bulk.getSelectedIds().length + ' selected file(s)?')) {
            e.preventDefault();
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>