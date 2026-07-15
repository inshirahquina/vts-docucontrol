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

$perPage = 15;
$page    = (isset($_GET['page']) && is_numeric($_GET['page'])) ? intval($_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$searchTerm = $_GET['search'] ?? '';
$searchSQL  = '';

if (!empty($searchTerm)) {
    $searchSQL = " 
        AND (
            f.file_name LIKE :s1 
            OR f.allocation LIKE :s2 
            OR f.department LIKE :s3
        )
    ";
}

$statusFilter = array_values(array_filter($_GET['statuses'] ?? []));
$statusSQL    = '';

if (!empty($statusFilter)) {
    $placeholders = [];
    foreach ($statusFilter as $k => $s) {
        $placeholders[] = ":status_" . $k;
    }
    $statusSQL = " AND r.current_status IN (" . implode(',', $placeholders) . ")";
}

$statusConfig = [
    'Requested'            => ['📝', 'Requested', 'bg-blue-100 text-blue-800', 'Requested'],
    'Pending HOD Approval' => ['⏳', 'Pending HOD', 'bg-amber-100 text-amber-800', 'Requested'],
    'Extension Requested'  => ['⏳', 'Extension Requested', 'bg-amber-100 text-amber-800', 'Requested'],
    'Approved by HOD'      => ['✅', 'Approved', 'bg-green-100 text-green-800', 'Approved'],
    'Retrieval Assigned'   => ['🛠️', 'Processing', 'bg-orange-100 text-orange-800', 'Processing'],
    'File Retrieved'       => ['🛠️', 'Processing', 'bg-orange-100 text-orange-800', 'Processing'],
    'Released'             => ['📂', 'With You', 'bg-emerald-100 text-emerald-800', 'Released'],
    'Return Requested'     => ['↩️', 'Returning', 'bg-rose-100 text-rose-800', 'Returning'],
    'Restoration Assigned' => ['↩️', 'Returning', 'bg-rose-100 text-rose-800', 'Returning'],
    'File Restored'        => ['↩️', 'Returning', 'bg-rose-100 text-rose-800', 'Returning'],
    'Completed'            => ['✔️', 'Completed', 'bg-gray-100 text-gray-600', 'Completed'],
    'Cancelled'            => ['🚫', 'Cancelled', 'bg-red-100 text-red-800', 'Cancelled']
];

$filterGroups = [
    'Processing' => ['Retrieval Assigned', 'File Retrieved'],
    'Returning'  => ['Return Requested', 'Restoration Assigned', 'File Restored'],
];

$countSQL = "
    SELECT COUNT(*) 
    FROM requests r
    JOIN files f ON r.file_id = f.id
    WHERE r.user_id = :uid
    $searchSQL
    $statusSQL
";

$stmtCount = $pdo->prepare($countSQL);
$stmtCount->bindValue(':uid', $uid);

if (!empty($searchTerm)) {
    $stmtCount->bindValue(':s1', "%$searchTerm%");
    $stmtCount->bindValue(':s2', "%$searchTerm%");
    $stmtCount->bindValue(':s3', "%$searchTerm%");
}

if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) {
        $stmtCount->bindValue(":status_" . $k, $s);
    }
}

$stmtCount->execute();
$totalRows  = $stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$sql = "
    SELECT 
        r.*,
        f.file_name,
        f.allocation,
        f.box_no,
        f.department,
        GREATEST(
            COALESCE(r.updated_at, '1970-01-01'),
            COALESCE(r.released_at, '1970-01-01'),
            COALESCE(r.retrieved_at, '1970-01-01'),
            COALESCE(r.borrow_date, '1970-01-01')
        ) AS last_activity
    FROM requests r
    JOIN files f ON r.file_id = f.id
    WHERE r.user_id = :uid
    $searchSQL
    $statusSQL
    ORDER BY 
        CASE 
            WHEN r.current_status = 'Requested' THEN 1
            WHEN r.current_status = 'Pending HOD Approval' THEN 2
            WHEN r.current_status IN ('Approved by HOD','Retrieval Assigned','File Retrieved') THEN 3
            WHEN r.current_status = 'Released' THEN 4
            WHEN r.current_status IN ('Return Requested','Restoration Assigned','File Restored') THEN 5
            WHEN r.current_status = 'Completed' THEN 6
            WHEN r.current_status = 'Cancelled' THEN 7
            ELSE 8
        END,
        last_activity DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':uid', $uid);

if (!empty($searchTerm)) {
    $stmt->bindValue(':s1', "%$searchTerm%");
    $stmt->bindValue(':s2', "%$searchTerm%");
    $stmt->bindValue(':s3', "%$searchTerm%");
}

if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) {
        $stmt->bindValue(":status_" . $k, $s);
    }
}

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count returnable rows with the same gate as original Return File (Released, incl. overdue).
$releasedCount = 0;
foreach ($rows as $r) {
    $key = $r['current_status'] ?? '';
    if (($r['status'] ?? '') === 'extension_requested') {
        $key = 'Extension Requested';
    }
    if ($key === 'Released') {
        $releasedCount++;
    }
}

require_once '../includes/header.php';
?>

<div class="layout-wrapper" id="myFilesRoot">
    <?php require_once '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="content-area">

            <div class="page-header-row">
                <div class="page-title">
                    <h1>My Files</h1>
                    <p>Track and manage your requested files</p>
                </div>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="flash-banner success"><?= sanitize($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="flash-banner error"><?= sanitize($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="filter-bar-card list-controls-card">
                <form method="GET" class="filter-form-inline" id="filterForm">
                    
                    <div class="search-box-modern">
                        <span class="icon">🔍</span>
                        <input type="text" name="search" placeholder="Search by name, allocation, department..." value="<?= htmlspecialchars($searchTerm) ?>">
                        <?php if($searchTerm): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['search' => ''])) ?>" class="clear-search">×</a>
                        <?php endif; ?>
                    </div>

                    <div class="dropdown-modern" id="statusDropdownContainer">
                        <button type="button" class="dropdown-trigger" id="statusBtn">
                            <span>Filter Status</span>
                            <?php if(count($statusFilter) > 0): ?>
                                <span class="filter-pill"><?= count($statusFilter) ?> selected</span>
                            <?php endif; ?>
                            <span class="arrow-down">▾</span>
                        </button>

                        <div class="dropdown-content" id="statusDropdown">
                            <div class="dropdown-header">
                                <span>Filter Options</span>
                                <a href="?search=<?= htmlspecialchars($searchTerm) ?>" class="text-link">Reset</a>
                            </div>
                            
                            <div class="dropdown-body">
                                <div class="filter-section-title">Quick Filters</div>
                                <div class="quick-filters-grid">
                                    <button type="button" class="quick-filter-btn" onclick="toggleGroup(<?= htmlspecialchars(json_encode($filterGroups['Processing'])) ?>, this)">
                                        🛠️ Processing
                                    </button>
                                    <button type="button" class="quick-filter-btn" onclick="toggleGroup(<?= htmlspecialchars(json_encode($filterGroups['Returning'])) ?>, this)">
                                        ↩️ Returning
                                    </button>
                                </div>

                                <div class="divider"></div>

                                <div class="filter-section-title">Detailed Status</div>
                                <div class="dropdown-options">
                                    <?php foreach($statusConfig as $key => $props): ?>
                                        <?php if ($key === 'Extension Requested') continue; ?>
                                        <label class="option-item">
                                            <input type="checkbox" name="statuses[]" value="<?= $key ?>" 
                                                <?= in_array($key, $statusFilter) ? 'checked' : '' ?> 
                                                class="status-checkbox" data-status="<?= $key ?>">
                                            <span class="custom-checkbox"></span>
                                            <span class="label-text"><?= $props[1] ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="dropdown-footer">
                                <button type="submit" class="btn-apply-filters">Apply Filters</button>
                            </div>
                        </div>
                    </div>
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

            <?php if(!empty($statusFilter)): ?>
            <div class="active-filters">
                <span>Active Filters:</span>
                <?php foreach($statusFilter as $st): ?>
                    <?php $displayName = isset($statusConfig[$st]) ? $statusConfig[$st][1] : $st; ?>
                    <span class="filter-tag">
                        <?= $displayName ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['statuses' => array_diff($statusFilter, [$st])])) ?>" class="remove-tag">×</a>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="files-list-container">
                <?php if($rows): ?>
                    <?php foreach($rows as $row):

                    $statusKey = $row['current_status'] ?: 'Cancelled';

                    if ($row['status'] === 'extension_requested') {
                        $statusKey = 'Extension Requested';
                    }
                    if (!isset($statusConfig[$statusKey])) {
                        $statusKey = 'Cancelled';
                    }

                    [$icon, $label, $classes, $group] = $statusConfig[$statusKey];

                    $releasedAt   = $row['released_at'];
                    $dueDate      = $row['due_date'] ?? null;
                    $hasExtension = !empty($row['extension_count']) && $row['extension_count'] > 0;

                    $extensionCancelled = (
                        $row['status'] === 'active' &&
                        $row['current_status'] === 'Released' &&
                        $row['extension_count'] > 0 &&
                        empty($row['extension_requested_at'])
                    );

                    /*
                     * Original gates — Return always for Released (incl. overdue); Extend only when not overdue.
                     */
                    $overDue = (
                        $statusKey === 'Released' &&
                        !empty($dueDate) &&
                        date('Y-m-d') > date('Y-m-d', strtotime($dueDate))
                    );

                    $canReturn  = ($statusKey === 'Released');
                    $showCancel = in_array($statusKey, ['Requested', 'Pending HOD Approval'], true);
                    $showExtend = ($canReturn && !$overDue && $row['extension_count'] < 2);
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

                                <?php if(!empty($row['remarks'])): ?>
                                    <div class="file-remark">
                                        📝 <?= sanitize($row['remarks']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="file-meta-badges">
                                    <span class="meta-badge dark"><?= sanitize($row['allocation']) ?></span>
                                    <span class="meta-badge gray"><?= sanitize($row['department']) ?></span>
                                </div>
                            </div>

                            <div class="file-timeline">
                                <div class="time-row">
                                    <span class="label">Requested</span>
                                    <span class="value"><?= date('M j, Y', strtotime($row['borrow_date'])) ?></span>
                                </div>

                                <?php if($releasedAt || $dueDate || $row['extension_count'] > 0): ?>
                                    <div class="time-row <?= $overDue ? 'danger' : '' ?>">
                                        <span class="label">Due Date</span>
                                        <span class="value bold">
                                            <?= !empty($dueDate)
                                            ? date('M j, Y', strtotime($dueDate))
                                            : '--' ?>

                                            <?php if($hasExtension): ?>
                                                <small>(Ext. x<?= $row['extension_count'] ?>)</small>
                                            <?php endif; ?>
                                        </span>

                                        <?= ($overDue && $canReturn)
                                            ? '<span class="overdue-alert">⚠ Overdue</span>'
                                            : '' ?>
                                    </div>
                                <?php endif; ?>

                                <?php if($statusKey === 'Completed' && !empty($row['return_date'])): ?>
                                    <div class="time-row">
                                        <span class="label">Returned</span>
                                        <span class="value"><?= date('M j, Y', strtotime($row['return_date'])) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-status-actions">
                            <div class="status-wrapper">
                                <?php if($row['extension_count'] > 0): ?>
                                    <div class="file-remark" style="font-size:0.75rem; color:#6b7280;">
                                        Extension x<?= $row['extension_count'] ?>
                                    </div>
                                <?php endif; ?>

                                <span class="modern-badge <?= $classes ?>">
                                    <?= $icon ?> <?= $label ?>
                                </span>

                                <?php if($extensionCancelled): ?>
                                    <div class="file-remark" style="color:#b45309; font-weight:600;">
                                        ⚠ Extension Cancelled
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="action-buttons">
                                <?php if($canReturn): ?>
                                    <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Confirm return request?');" class="no-bulk-toggle">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="action" value="request_return">
                                        <button class="btn-sm primary">Return File</button>
                                    </form>
                                <?php endif; ?>

                                <?php if($showCancel): ?>
                                    <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Cancel this request?');" class="no-bulk-toggle">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="action" value="cancel_request">
                                        <button class="btn-sm danger-outline">Cancel Request</button>
                                    </form>
                                <?php endif; ?>

                                <?php if($showExtend): ?>
                                    <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Request 3-day extension?');" class="no-bulk-toggle">
                                        <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="action" value="request_extension">
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
                        <h3>No Files Found</h3>
                        <p>You have no active requests matching these criteria.</p>
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
                    
                    <?php for($i=1; $i<=$totalPages; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>" class="page-btn num <?= $i==$page?'active':'' ?>"><?= $i ?></a>
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

<!-- Sticky bulk return toolbar -->
<div class="bulk-toolbar" id="bulkToolbar" aria-hidden="true">
    <div class="bulk-toolbar-count" id="bulkSelectedCount">0 files selected</div>
    <div class="bulk-toolbar-actions">
        <button type="button" class="btn-bulk btn-bulk-ghost" id="cancelSelectionBtn">Cancel Selection</button>
        <form id="bulkReturnForm" method="POST" action="../actions/request_actions.php" data-bulk-ids>
            <input type="hidden" name="action" value="bulk_request_return">
            <button type="submit" class="btn-bulk btn-bulk-primary">Return Selected Files</button>
        </form>
    </div>
</div>

<style>
.content-area { padding: 28px 32px 32px; background: #f8fafc; font-family: var(--bs-font, 'IBM Plex Sans', sans-serif); }
.filter-form-inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

.search-box-modern { position: relative; flex: 1; min-width: 250px; }
.search-box-modern .icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; }
.search-box-modern input { width: 100%; height: 44px; padding: 0 36px 0 36px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 0.875rem; background: #fff; transition: all 0.2s; font-family: inherit; }
.search-box-modern input:focus { border-color: #93c5fd; background: #fff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); outline: none; }
.clear-search { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; font-size: 1.1rem; text-decoration: none; }

.dropdown-modern { position: relative; }
.dropdown-trigger { display: flex; align-items: center; gap: 8px; height: 44px; padding: 0 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; font-weight: 500; font-size: 0.875rem; color: #334155; cursor: pointer; font-family: inherit; }
.filter-pill { background: #eff6ff; color: #1d4ed8; font-size: 0.7rem; padding: 2px 8px; border-radius: 999px; font-weight: 600; }

.dropdown-content { display: none; position: absolute; top: 115%; right: 0; width: 280px; background: #fff; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 20; border: 1px solid #e5e7eb; }
.show-drop { display: block; }

.dropdown-header { display: flex; justify-content: space-between; padding: 10px 12px; border-bottom: 1px solid #f3f4f6; font-size: 0.8rem; font-weight: 600; color: #6b7280; }
.dropdown-body { padding: 12px; }
.filter-section-title { font-size: 0.7rem; text-transform: uppercase; color: #9ca3af; font-weight: 700; margin-bottom: 8px; }
.quick-filters-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.quick-filter-btn { padding: 8px; border: 1px solid #e5e7eb; background: #f9fafb; border-radius: 6px; font-size: 0.8rem; font-weight: 500; cursor: pointer; transition: all 0.2s; }
.quick-filter-btn.active { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; }
.divider { height: 1px; background: #f3f4f6; margin: 12px 0; }
.dropdown-options { max-height: 180px; overflow-y: auto; }
.option-item { display: flex; align-items: center; padding: 6px 8px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
.option-item input { margin-right: 8px; }
.dropdown-footer { padding: 10px 12px; border-top: 1px solid #f3f4f6; background: #f9fafb; border-radius: 0 0 8px 8px; }
.btn-apply-filters { width: 100%; padding: 8px; background: #0d47a1; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }

.active-filters { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; font-size: 0.85rem; flex-wrap: wrap; }
.filter-tag { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #e5e7eb; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
.remove-tag { text-decoration: none; color: #9ca3af; font-weight: bold; }
.remove-tag:hover { color: #ef4444; }

.files-list-container { display: flex; flex-direction: column; gap: 10px; }
.file-card { background: #fff; border-radius: 12px; padding: 18px 20px; display: flex; justify-content: space-between; align-items: center; }
.file-card.is-overdue { border-left: 3px solid #ef4444; }

.card-main { flex: 3; display: flex; gap: 16px; align-items: center; }
.file-identity { min-width: 200px; }
.file-title { margin: 0 0 6px 0; font-size: 0.95rem; font-weight: 600; color: #0f172a; letter-spacing: -0.01em; }
.file-meta-badges { display: flex; gap: 6px; }
.meta-badge { font-size: 0.7rem; padding: 3px 8px; border-radius: 6px; font-weight: 500; }
.meta-badge.dark { background: #f1f5f9; color: #475569; }
.meta-badge.gray { background: #fff; border: 1px solid #e2e8f0; color: #64748b; }

.file-timeline { display: flex; gap: 24px; }
.time-row { display: flex; flex-direction: column; font-size: 0.8125rem; gap: 2px; }
.time-row .label { color: #94a3b8; font-size: 0.7rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.03em; }
.time-row .value { color: #334155; font-weight: 500; }
.time-row.danger .value { color: #b91c1c; font-weight: 600; }
.overdue-alert { font-size: 0.7rem; background: #fef2f2; color: #b91c1c; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 600; }

.card-status-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
.modern-badge { padding: 5px 10px; border-radius: 6px; font-weight: 550; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 5px; }

.bg-blue-100 { background-color: #dbeafe; } .text-blue-800 { color: #1e40af; }
.bg-amber-100 { background-color: #fef3c7; } .text-amber-800 { color: #92400e; }
.bg-green-100 { background-color: #d1fae5; } .text-green-800 { color: #065f46; }
.bg-orange-100 { background-color: #ffedd5; } .text-orange-800 { color: #9a3412; }
.bg-emerald-100 { background-color: #d1fae5; } .text-emerald-800 { color: #047857; }
.bg-rose-100 { background-color: #ffe4e6; } .text-rose-800 { color: #9f1239; }
.bg-gray-100 { background-color: #f3f4f6; } .text-gray-600 { color: #4b5563; }
.bg-red-100 { background-color: #fee2e2; } .text-red-800 { color: #991b1b; }

.action-buttons { display: flex; gap: 8px; }
.btn-sm { padding: 7px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 550; border: none; cursor: pointer; font-family: inherit; transition: background 0.15s, border-color 0.15s, color 0.15s; }
.btn-sm.danger-outline { background: transparent; border: 1px solid #fecaca; color: #dc2626; }
.btn-sm.danger-outline:hover { background: #fef2f2; }
.btn-sm.warning-outline { background: #fff; border: 1px solid #fde68a; color: #b45309; }
.btn-sm.warning-outline:hover { background: #fffbeb; }

.empty-state-container { text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; border: 1px dashed #d1d5db; }
.empty-icon { font-size: 3rem; margin-bottom: 12px; opacity: 0.5; }
.pagination-wrapper { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; margin-top: 10px; }
.pagination-info { font-size: 0.85rem; color: #6b7280; }
.pagination-controls { display: flex; gap: 6px; }
.page-btn { padding: 8px 14px; border-radius: 6px; background: #fff; border: 1px solid #e5e7eb; color: #374151; text-decoration: none; font-size: 0.85rem; }
.page-btn.active { background: #0d47a1; color: #fff; border-color: #0d47a1; }

.file-remark{ font-size:0.8rem; color:#6b7280; margin-bottom:6px; font-style:italic; }

@media (max-width: 768px) {
    .file-card { flex-direction: column; align-items: flex-start; gap: 16px; }
    .card-main { flex-direction: column; align-items: flex-start; gap: 12px; width: 100%; }
    .file-timeline { flex-direction: column; gap: 8px; }
    .card-status-actions { width: 100%; flex-direction: row; justify-content: space-between; }
}
</style>

<script>
const statusBtn = document.getElementById('statusBtn');
const statusDropdown = document.getElementById('statusDropdown');

if (statusBtn && statusDropdown) {
    statusBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        statusDropdown.classList.toggle('show-drop');
    });

    window.addEventListener('click', function(e) {
        if (statusDropdown.classList.contains('show-drop')) {
            if (!statusDropdown.contains(e.target) && !statusBtn.contains(e.target)) {
                statusDropdown.classList.remove('show-drop');
            }
        }
    });
}

function toggleGroup(statusArray, btn) {
    const checkboxes = document.querySelectorAll('.status-checkbox');
    const isActive = btn.classList.contains('active');
    
    checkboxes.forEach(cb => {
        if (statusArray.includes(cb.value)) {
            cb.checked = !isActive;
        }
    });
    btn.classList.toggle('active');
}

document.addEventListener('DOMContentLoaded', () => {
    const processingGroup = <?= json_encode($filterGroups['Processing']) ?>;
    const returningGroup = <?= json_encode($filterGroups['Returning']) ?>;

    function areAllChecked(items) {
        const checkboxes = document.querySelectorAll('.status-checkbox');
        let count = 0;
        items.forEach(item => {
            checkboxes.forEach(cb => {
                if(cb.value === item && cb.checked) count++;
            });
        });
        return count === items.length;
    }

    if (areAllChecked(processingGroup)) {
        document.querySelector("button[onclick*='Processing']")?.classList.add('active');
    }
    if (areAllChecked(returningGroup)) {
        document.querySelector("button[onclick*='Returning']")?.classList.add('active');
    }

    if (typeof BulkSelect === 'undefined' || !document.getElementById('selectAllBulk')) return;

    const bulk = BulkSelect.init({
        root: document.getElementById('myFilesRoot'),
        modeContainer: document.getElementById('myFilesRoot'),
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