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

// --- Pagination ---
$perPage = 15;
$page    = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// --- Filters & Search ---
$searchTerm = $_GET['search'] ?? '';
// Updated search SQL to use allocation instead of barcode
$searchSQL = $searchTerm ? " AND (f.file_name LIKE :s OR f.allocation LIKE :s OR f.department LIKE :s)" : "";

$statusFilter = $_GET['statuses'] ?? [];
$statusSQL = "";
if (!empty($statusFilter) && is_array($statusFilter)) {
    $placeholders = [];
    foreach ($statusFilter as $k => $s) {
        $placeholders[] = ":status_" . $k;
    }
    $statusSQL = " AND r.current_status IN (" . implode(',', $placeholders) . ")";
}

// --- Status Configuration ---
$statusConfig = [
    'Requested'            => ['📝', 'Requested', 'bg-blue-100 text-blue-800', 'Requested'],
    'Pending HOD Approval' => ['⏳', 'Pending HOD', 'bg-amber-100 text-amber-800', 'Requested'],
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
    'Returning'  => ['Return Requested', 'Restoration Assigned', 'File Restored']
];

// --- Count total rows ---
$countSQL = "SELECT COUNT(*) FROM requests r JOIN files f ON r.file_id=f.id WHERE r.user_id=:uid $searchSQL $statusSQL";
$stmtCount = $pdo->prepare($countSQL);
$stmtCount->bindValue(':uid', $uid);
if ($searchTerm) $stmtCount->bindValue(':s', "%$searchTerm%");
if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) $stmtCount->bindValue(":status_" . $k, $s);
}
$stmtCount->execute();
$totalRows = $stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// --- MAIN QUERY ---

$sql = "SELECT r.*, f.file_name, f.allocation, f.box_no, f.department,
        GREATEST(
            COALESCE(r.updated_at, '1970-01-01'), 
            COALESCE(r.released_at, '1970-01-01'), 
            COALESCE(r.retrieved_at, '1970-01-01'), 
            COALESCE(r.borrow_date, '1970-01-01')
        ) as last_activity
        FROM requests r 
        JOIN files f ON r.file_id=f.id 
        WHERE r.user_id=:uid $searchSQL $statusSQL 
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
        LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql); 
$stmt->bindValue(':uid', $uid); 
if ($searchTerm) $stmt->bindValue(':s', "%$searchTerm%");
if (!empty($statusFilter)) { 
    foreach ($statusFilter as $k => $s) $stmt->bindValue(":status_" . $k, $s); 
} 
$stmt->execute(); 
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="content-area">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h1>My Files</h1>
                    <p>Track and manage your requested files</p>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filter-bar-card">
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
            </div>

            <!-- Active Filters Tags -->
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

            <!-- List View -->
            <div class="files-list-container">
                <?php if($rows): ?>
                    <?php foreach($rows as $row):
                        $statusKey = $row['current_status'] ?: 'Cancelled';
                        if(!isset($statusConfig[$statusKey])) $statusKey='Cancelled';
                        [$icon, $label, $classes, $group] = $statusConfig[$statusKey];

                        $releasedAt = $row['released_at'];
                        $dueDate = $row['due_date'] ?? null;
                        $overDue = ($dueDate && strtotime(date('Y-m-d')) > strtotime($dueDate));

                        $actionBtn = false;
                        $actionType = '';
                        $showExtend = false;

                        if($statusKey==='Released') {
                            $actionBtn = true;
                            $actionType = 'return';
                            $showExtend = (!$overDue && $row['extension_count'] < 2);
                        }
                        if(in_array($statusKey, ['Requested', 'Pending HOD Approval'])) {
                            $actionBtn = true;
                            $actionType = 'cancel';
                        }
                    ?>
                    
                    <div class="file-card <?= $overDue ? 'is-overdue' : '' ?>">
                        <div class="card-main">
                            <div class="file-identity">
                                <h4 class="file-title"><?= sanitize($row['file_name']) ?></h4>
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
                                
                                <?php if($releasedAt): ?>
                                <div class="time-row">
                                    <span class="label">Released</span>
                                    <span class="value"><?= date('M j, Y', strtotime($releasedAt)) ?></span>
                                </div>
                                <div class="time-row <?= $overDue ? 'danger' : '' ?>">
                                    <span class="label">Due Date</span>
                                    <span class="value bold">
                                        <?= date('M j, Y', strtotime($dueDate)) ?>
                                        <?php if($row['extension_count'] > 0): ?>
                                            <small>(Ext. x<?= $row['extension_count'] ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                    <?= $overDue ? '<span class="overdue-alert">⚠ Overdue</span>' : '' ?>
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
                                <?php if($actionBtn): ?>
                                    <?php if($actionType==='return'): ?>
                                        <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Confirm return request?');" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="action" value="request_return">
                                            <button class="btn-sm primary">Return File</button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <?php if($actionType==='cancel'): ?>
                                        <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Cancel this request?');" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="action" value="cancel_request">
                                            <button class="btn-sm danger-outline">Cancel Request</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if($showExtend): ?>
                                        <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Request 3-day extension?');" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <input type="hidden" name="action" value="request_extension">
                                            <button class="btn-sm warning-outline">Extend</button>
                                        </form>
                                    <?php endif; ?>
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

            <!-- Pagination -->
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

<style>
/* --- Base & Layout --- */
.content-area { padding: 24px; background: #f3f4f6; min-height: calc(100vh - 60px); }
.page-header { margin-bottom: 24px; }
.page-title h1 { font-size: 1.5rem; margin: 0 0 4px 0; color: #111827; font-weight: 700; }
.page-title p { margin: 0; color: #6b7280; font-size: 0.9rem; }

/* --- Filter Bar --- */
.filter-bar-card { background: #fff; padding: 16px 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 16px; }
.filter-form-inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

.search-box-modern { position: relative; flex: 1; min-width: 250px; }
.search-box-modern .icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
.search-box-modern input { width: 100%; padding: 10px 32px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; background: #f9fafb; transition: all 0.2s; }
.search-box-modern input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); outline: none; }
.clear-search { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; cursor: pointer; font-size: 1.2rem; text-decoration: none; }

/* Dropdown */
.dropdown-modern { position: relative; }
.dropdown-trigger { display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; font-weight: 500; color: #374151; cursor: pointer; }
.filter-pill { background: #dbeafe; color: #1e40af; font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; font-weight: 600; }

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

/* Active Filters Tags */
.active-filters { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; font-size: 0.85rem; flex-wrap: wrap; }
.filter-tag { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #e5e7eb; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
.remove-tag { text-decoration: none; color: #9ca3af; font-weight: bold; }
.remove-tag:hover { color: #ef4444; }

/* --- Card List Design --- */
.files-list-container { display: flex; flex-direction: column; gap: 12px; }
.file-card { background: #fff; border-radius: 10px; border: 1px solid #f3f4f6; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
.file-card.is-overdue { border-left: 4px solid #ef4444; background: #fffafa; }

.card-main { flex: 3; display: flex; gap: 24px; align-items: center; }
.file-identity { min-width: 200px; }
.file-title { margin: 0 0 6px 0; font-size: 1rem; font-weight: 600; color: #111827; }
.file-meta-badges { display: flex; gap: 6px; }
.meta-badge { font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; font-weight: 500; }
.meta-badge.dark { background: #f3f4f6; color: #374151; }
.meta-badge.gray { background: #fff; border: 1px solid #e5e7eb; color: #6b7280; }

.file-timeline { display: flex; gap: 20px; }
.time-row { display: flex; flex-direction: column; font-size: 0.85rem; }
.time-row .label { color: #9ca3af; font-size: 0.75rem; }
.time-row .value { color: #1f2937; font-weight: 500; }
.time-row.danger .value { color: #b91c1c; font-weight: 700; }
.overdue-alert { font-size: 0.7rem; background: #fee2e2; color: #b91c1c; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 600; }

/* Status & Actions */
.card-status-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
.modern-badge { padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 6px; }

/* Colors */
.bg-blue-100 { background-color: #dbeafe; } .text-blue-800 { color: #1e40af; }
.bg-amber-100 { background-color: #fef3c7; } .text-amber-800 { color: #92400e; }
.bg-green-100 { background-color: #d1fae5; } .text-green-800 { color: #065f46; }
.bg-orange-100 { background-color: #ffedd5; } .text-orange-800 { color: #9a3412; }
.bg-emerald-100 { background-color: #d1fae5; } .text-emerald-800 { color: #047857; }
.bg-rose-100 { background-color: #ffe4e6; } .text-rose-800 { color: #9f1239; }
.bg-gray-100 { background-color: #f3f4f6; } .text-gray-600 { color: #4b5563; }
.bg-red-100 { background-color: #fee2e2; } .text-red-800 { color: #991b1b; }

.action-buttons { display: flex; gap: 8px; }
.btn-sm { padding: 6px 14px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer; }
.btn-sm.primary { background: #0d47a1; color: #fff; }
.btn-sm.danger-outline { background: transparent; border: 1px solid #fca5a5; color: #b91c1c; }
.btn-sm.warning-outline { background: #fff; border: 1px solid #fcd34d; color: #b45309; }

/* Empty & Pagination */
.empty-state-container { text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; border: 1px dashed #d1d5db; }
.empty-icon { font-size: 3rem; margin-bottom: 12px; opacity: 0.5; }
.pagination-wrapper { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; margin-top: 10px; }
.pagination-info { font-size: 0.85rem; color: #6b7280; }
.pagination-controls { display: flex; gap: 6px; }
.page-btn { padding: 8px 14px; border-radius: 6px; background: #fff; border: 1px solid #e5e7eb; color: #374151; text-decoration: none; font-size: 0.85rem; }
.page-btn.active { background: #0d47a1; color: #fff; border-color: #0d47a1; }

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
});
</script>

<?php require_once '../includes/footer.php'; ?>