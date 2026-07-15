<?php
require_once '../config/db.php';
require_once '../config/functions.php';

$allowed_roles = ['admin', 'staff', 'operations'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    redirect('../index.php');
}

$activeRole = $_SESSION['active_role'] ?? $_SESSION['role'];
$uid = $_SESSION['user_id'];
$isAdmin = ($activeRole === 'admin');

$staffList = $pdo->query("
    SELECT id, full_name 
    FROM users 
    WHERE role IN ('operations','admin') 
    ORDER BY full_name ASC
")->fetchAll();

$searchTerm = $_GET['search'] ?? '';
$filterStatuses = $_GET['status_filter'] ?? [];

$whereClauses = [];
$params = [];

if ($searchTerm) {
    $whereClauses[] = "(f.file_name LIKE :search1 
                        OR u_req.full_name LIKE :search2 
                        OR f.allocation LIKE :search3)";
    
    $params[':search1'] = "%$searchTerm%";
    $params[':search2'] = "%$searchTerm%";
    $params[':search3'] = "%$searchTerm%";
}

if (!empty($filterStatuses) && is_array($filterStatuses)) {
    $placeholders = [];
    foreach ($filterStatuses as $k => $s) {
        $key = ":sts_$k";
        $placeholders[] = $key;
        $params[$key] = $s;
    }
    $whereClauses[] = "r.current_status IN (" . implode(',', $placeholders) . ")";
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";
$perPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$offset = ($page - 1) * $perPage;
$countSQL = "
SELECT COUNT(*)
FROM requests r
JOIN files f ON r.file_id = f.id
JOIN users u_req ON r.user_id = u_req.id
LEFT JOIN users u_op ON r.assigned_to = u_op.id
$whereSQL
";

$countStmt = $pdo->prepare($countSQL);
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);
$startResult = $totalRows > 0 ? $offset + 1 : 0;
$endResult = min($offset + $perPage, $totalRows);
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$sql = "
SELECT 
    r.*,
    f.file_name,
    f.allocation,
    f.box_no,
    f.department,
    u_req.full_name AS requester_name,
    u_op.full_name AS operator_name,
    r.updated_at AS last_updated_time,

    CASE 
        WHEN r.retrieved_at IS NOT NULL AND r.released_at IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, r.retrieved_at, r.released_at)
        WHEN r.retrieved_at IS NOT NULL AND r.released_at IS NULL 
            THEN TIMESTAMPDIFF(MINUTE, r.retrieved_at, NOW())
        ELSE NULL
    END AS retrieval_duration,

    r.due_date AS calculated_due_date

FROM requests r
JOIN files f ON r.file_id = f.id
JOIN users u_req ON r.user_id = u_req.id
LEFT JOIN users u_op ON r.assigned_to = u_op.id
$whereSQL
ORDER BY r.updated_at DESC
";
$sql .= " LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusList = [
    'Pending HOD Approval',
    'Approved by HOD',
    'Retrieval Assigned',
    'File Retrieved',
    'Released',
    'Return Requested',
    'Restoration Assigned',
    'File Restored',
    'Completed',
    'Cancelled'
];

$adminSelectable = ['Approved by HOD', 'File Retrieved', 'Return Requested', 'File Restored'];
$selectableOnPage = 0;
if ($isAdmin) {
    foreach ($rows as $r) {
        if (in_array($r['current_status'], $adminSelectable, true)) {
            $selectableOnPage++;
        }
    }
}

function formatDuration($minutes) {
    if ($minutes === null) return "--";
    if ($minutes < 1) return "0m";
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return ($h > 0 ? $h . "h " : "") . $m . "m";
}

require_once '../includes/header.php';
?>

<div class="layout-wrapper" id="adminRequestsRoot">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="content-area admin-workflow">
            
            <div class="page-header-row">
                <div class="page-title">
                    <h1>Document Workflow</h1>
                    <p>Manage file requests, retrieval, and restoration tasks.</p>
                </div>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="flash-banner success"><?= sanitize($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="card filter-card list-controls-card">
                <form method="GET" class="filter-form" id="adminFilterForm">
                    <div class="search-box">
                        <span class="search-icon">🔍</span>
                        <input type="text" name="search" placeholder="Search file, name, or allocation..." value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    
                    <div class="dropdown-filter" id="statusFilterRoot">
                        <button type="button" class="dropdown-btn" id="statusFilterBtn" aria-haspopup="listbox" aria-expanded="false">
                            <span>Filter Status</span>
                            <?php if(count($filterStatuses) > 0): ?>
                                <span class="badge-count"><?= count($filterStatuses) ?></span>
                            <?php endif; ?>
                            <span class="arrow">▼</span>
                        </button>
                        <div id="statusDropdown" class="dropdown-content" role="listbox" hidden>
                            <div class="dropdown-options-scroll">
                                <?php foreach($statusList as $s): ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="status_filter[]" value="<?= $s ?>" form="adminFilterForm" <?= in_array($s, $filterStatuses) ? 'checked' : '' ?>>
                                    <span><?= $s ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="dropdown-actions">
                                <button type="submit" class="btn-apply" form="adminFilterForm">Apply Filters</button>
                                <a href="?" class="btn-reset">Reset</a>
                            </div>
                        </div>
                    </div>
                </form>

                <?php if ($isAdmin && $selectableOnPage > 0): ?>
                <div class="bulk-list-toolbar" id="bulkListToolbar">
                    <label class="bulk-mode-control">
                        <input type="checkbox" id="selectAllBulk">
                        <span class="bulk-mode-label">Select Requests</span>
                    </label>
                    <span class="bulk-select-hint" id="bulkStripCount">None selected</span>
                    <button type="button" class="bulk-cancel-btn" id="bulkExitBtn" hidden>Cancel</button>
                </div>
                <?php endif; ?>
            </div>

            <div class="card table-card">
                <div class="table-responsive">
                    <table class="workflow-table">
                        <thead>
                            <tr>
                                <?php if ($isAdmin): ?><th class="col-check"></th><?php endif; ?>
                                <th>File Details</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th>Timeline</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row):
                                $displayStatus = $row['current_status'];
                                
                                $statusStyles = [
                                    'Pending HOD Approval' => ['bg'=>'#fff7ed', 'color'=>'#c2410c', 'icon'=>'⏳'],
                                    'Approved by HOD'      => ['bg'=>'#ecfccb', 'color'=>'#3f6212', 'icon'=>'✅'],
                                    'Retrieval Assigned'   => ['bg'=>'#fef3c7', 'color'=>'#92400e', 'icon'=>'📅'],
                                    'File Retrieved'       => ['bg'=>'#e0f2fe', 'color'=>'#0369a1', 'icon'=>'📂'],
                                    'Released'             => ['bg'=>'#dcfce7', 'color'=>'#15803d', 'icon'=>'📤'],
                                    'Return Requested'     => ['bg'=>'#ffedd5', 'color'=>'#9a3412', 'icon'=>'↩️'],
                                    'Restoration Assigned' => ['bg'=>'#f3e8ff', 'color'=>'#7e22ce', 'icon'=>'🔧'],
                                    'File Restored'        => ['bg'=>'#e0e7ff', 'color'=>'#3730a3', 'icon'=>'✔️'],
                                    'Completed'            => ['bg'=>'#f1f5f9', 'color'=>'#475569', 'icon'=>'🏁'],
                                    'Cancelled'            => ['bg'=>'#fee2e2', 'color'=>'#b91c1c', 'icon'=>'🚫'],
                                ];
                                $style = $statusStyles[$displayStatus] ?? ['bg'=>'#f3f4f6', 'color'=>'#374151', 'icon'=>'❓'];

                                $mins = $row['retrieval_duration'];
                                $dueDate = $row['calculated_due_date'];
                                $isOverdue = (
                                    $dueDate &&
                                    date('Y-m-d') > date('Y-m-d', strtotime($dueDate)) &&
                                    $displayStatus == 'Released'
                                );
                                
                                $slaColor = '#10b981'; 
                                if ($mins > 10) $slaColor = '#ef4444'; 
                                elseif ($mins > 8) $slaColor = '#f59e0b'; 

                                $canSelect = $isAdmin && in_array($displayStatus, $adminSelectable, true);
                            ?>
                            <tr class="bulk-item <?= $canSelect ? '' : 'bulk-disabled' ?>"
                                data-selectable="<?= $canSelect ? '1' : '0' ?>"
                                data-status="<?= htmlspecialchars($displayStatus) ?>"
                                data-request-id="<?= (int)$row['id'] ?>">
                                <?php if ($isAdmin): ?>
                                <td class="col-check">
                                    <?php if ($canSelect): ?>
                                    <div class="bulk-checkbox-wrap">
                                        <input type="checkbox"
                                               class="bulk-checkbox"
                                               value="<?= (int)$row['id'] ?>"
                                               data-status="<?= htmlspecialchars($displayStatus) ?>"
                                               aria-label="Select <?= sanitize($row['file_name']) ?>">
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div class="file-info">
                                        <span class="file-name"><?= sanitize($row['file_name']) ?></span>
                                        <span class="file-meta">
                                            Alloc: <?= sanitize($row['allocation']) ?> • 
                                            Box: <?= sanitize($row['box_no']) ?> • 
                                            Req by: <?= sanitize($row['requester_name']) ?>
                                            <?php if(!empty($row['remarks'])): ?>
                                            <div class="remark-text">
                                            Remark: <?= sanitize($row['remarks']) ?>
                                            </div>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div>
                                        <div class="status-badge" style="background:<?= $style['bg'] ?>; color:<?= $style['color'] ?>;">
                                            <span class="icon"><?= $style['icon'] ?></span>
                                            <?= $displayStatus ?>
                                        </div>

                                        <?php if(!empty($row['operator_name']) && 
                                            in_array($displayStatus, ['Retrieval Assigned','Restoration Assigned'])): ?>
                                            <div class="assigned-label">
                                                Assigned to: <?= sanitize($row['operator_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if($row['extension_count'] > 0): ?>
                                        <div class="ext-badge">Extension x<?= $row['extension_count'] ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="timestamp-col">
                                    <?php if($row['hod_timestamp']): ?>
                                        <div class="time-display"><?= date('d M Y', strtotime($row['hod_timestamp'])) ?></div>
                                        <div class="time-small"><?= date('H:i', strtotime($row['hod_timestamp'])) ?></div>
                                    <?php elseif($row['last_updated_time']): ?>
                                        <div class="time-display"><?= date('d M Y', strtotime($row['last_updated_time'])) ?></div>
                                        <div class="time-small"><?= date('H:i', strtotime($row['last_updated_time'])) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">--</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if($displayStatus == 'Released' && $dueDate): ?>
                                        <div class="timeline-box">
                                            <div class="due-row">
                                                <span>Due Date:</span>
                                                <strong style="color: <?= $isOverdue ? '#dc2626' : '#059669' ?>">
                                                    <?= date('d M', strtotime($dueDate)) ?>
                                                </strong>
                                            </div>
                                            <?php if($isOverdue): ?>
                                                <div class="overdue-alert">⚠ Overdue</div>
                                            <?php endif; ?>
                                        </div>
                                    
                                    <?php elseif(in_array($displayStatus, ['Retrieval Assigned', 'File Retrieved']) && $mins !== null): ?>
                                        <div class="timeline-box sla-box">
                                            <div class="sla-label">Process Time</div>
                                            <div class="sla-time" style="color:<?= $slaColor ?>">
                                                <strong><?= formatDuration($mins) ?></strong>
                                            </div>
                                            <div class="sla-limit">(Limit: 10m)</div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">--</span>
                                    <?php endif; ?>
                                </td>

                                <td class="action-col">
                                    <div class="per-row-actions">
                                    <?php 
                                    if($displayStatus == 'Pending HOD Approval'): ?>
                                        <span class="info-text waiting">Waiting for HOD</span>

                                    <?php elseif($displayStatus == 'Approved by HOD' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form no-bulk-toggle">
                                            <input type="hidden" name="action" value="assign_retrieval">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <select name="assigned_staff_id" required class="mini-select">
                                                <?php foreach($staffList as $s): ?>
                                                    <option value="<?= $s['id'] ?>"><?= sanitize($s['full_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Assign</button>
                                        </form>

                                    <?php elseif($displayStatus == 'Retrieval Assigned' && in_array($activeRole, ['staff', 'operations'])): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form no-bulk-toggle">
                                            <input type="hidden" name="action" value="confirm_retrieval">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm">✅ Retrieved</button>
                                        </form>

                                    <?php elseif($displayStatus == 'File Retrieved' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form no-bulk-toggle">
                                            <input type="hidden" name="action" value="release_file">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Release File</button>
                                        </form>

                                    <?php elseif($displayStatus == 'Return Requested' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form no-bulk-toggle">
                                            <input type="hidden" name="action" value="assign_restoration">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <select name="assigned_staff_id" required class="mini-select">
                                                <?php foreach($staffList as $s): ?>
                                                    <option value="<?= $s['id'] ?>"><?= sanitize($s['full_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Assign</button>
                                        </form>

                                    <?php elseif($displayStatus == 'Restoration Assigned' && in_array($activeRole, ['staff', 'operations'])): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form no-bulk-toggle">
                                            <input type="hidden" name="action" value="confirm_restoration">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm">✅ Restored</button>
                                        </form>

                                    <?php elseif($displayStatus == 'File Restored' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form no-bulk-toggle">
                                            <input type="hidden" name="action" value="complete_transaction">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-dark btn-sm">Complete</button>
                                        </form>
                                    
                                    <?php elseif(in_array($displayStatus, ['Completed', 'Cancelled'])): ?>
                                        <span class="text-muted">No Action</span>
                                    
                                    <?php else: ?>
                                        <span class="text-muted">Processing...</span>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pagination-info">
                        Showing <?= $startResult ?> - <?= $endResult ?> of <?= $totalRows ?> results
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrapper">
                            <?php if ($page > 1): ?>
                                <a class="page-btn" 
                                href="?<?= http_build_query(array_merge($_GET, ['page'=>$page-1])) ?>">
                                ← Prev
                                </a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 4);
                            $end = min($totalPages, $start + 9);

                            for ($i = $start; $i <= $end; $i++): ?>
                                <a class="page-btn <?= $i == $page ? 'active' : '' ?>"
                                href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>">
                                <?= $i ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a class="page-btn"
                                href="?<?= http_build_query(array_merge($_GET, ['page'=>$page+1])) ?>">
                                Next →
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="bulk-toolbar bulk-toolbar-admin" id="bulkToolbar" data-state="hidden" aria-hidden="true">
    <div class="bulk-admin-valid" id="bulkAdminValid" hidden>
        <div class="bulk-toolbar-count" id="bulkSelectedCount">0 requests selected</div>
        <form id="bulkStageForm"
              method="POST"
              action="../actions/request_actions.php"
              class="bulk-stage-form"
              data-bulk-ids>
            <input type="hidden" name="action" id="bulkStageAction" value="">
            <select name="assigned_staff_id"
                    id="bulkStaffSelect"
                    class="bulk-staff-select"
                    hidden
                    disabled>
                <option value="" disabled selected>Staff</option>
                <?php foreach ($staffList as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= sanitize($s['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" id="bulkStageSubmit" class="btn-bulk btn-bulk-primary" disabled>
                Continue
            </button>
        </form>
        <button type="button" class="btn-bulk btn-bulk-ghost" id="cancelSelectionBtn">Cancel</button>
    </div>

    <div class="bulk-admin-mixed" id="bulkAdminMixed" hidden>
        <div class="bulk-stage-msg" role="alert">
            <strong>Mixed workflow stages selected.</strong>
            Please select requests from the same workflow stage.
        </div>
        <button type="button" class="btn-bulk btn-bulk-ghost" id="bulkClearMixedBtn">Clear Selection</button>
    </div>
</div>
<?php endif; ?>

<style>
.admin-workflow { padding: 28px 32px 32px; background: #f8fafc; font-family: var(--bs-font, 'IBM Plex Sans', sans-serif); }
.card { background: #fff; border-radius: 12px; box-shadow: var(--bs-shadow); margin-bottom: 20px; border: 1px solid #e8eef5; }
.card.filter-card.list-controls-card { padding: 0; margin-bottom: 16px; }
.filter-card:not(.list-controls-card) { padding: 15px 20px; }

.filter-form { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
.search-box { position: relative; flex: 1; min-width: 250px; }
.search-box input { width: 100%; padding: 10px 15px 10px 40px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; }
.search-box .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); opacity: 0.5; }

.dropdown-filter { position: relative; z-index: 5; }
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
.dropdown-btn .badge-count {
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
.dropdown-btn[aria-expanded="true"] {
    border-color: #93c5fd;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

/*
 * Fixed positioning escapes .main-content { overflow:hidden }
 * and .content-area { overflow-y:auto } clipping.
 */
.dropdown-content {
    display: none;
    position: fixed;
    width: min(280px, calc(100vw - 24px));
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.14), 0 2px 8px rgba(15, 23, 42, 0.06);
    border: 1px solid #e2e8f0;
    z-index: 4000;
    padding: 0;
    overflow: hidden;
}

.dropdown-content.is-open {
    display: flex;
    flex-direction: column;
    max-height: min(380px, calc(100vh - 24px));
}

.dropdown-options-scroll {
    flex: 1 1 auto;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 8px 12px;
    max-height: 320px;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}

.dropdown-options-scroll::-webkit-scrollbar {
    width: 8px;
}
.dropdown-options-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 8px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 6px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    color: #374151;
}
.checkbox-label:hover { background: #f8fafc; }
.checkbox-label input { width: 16px; height: 16px; flex-shrink: 0; }

.dropdown-actions {
    flex-shrink: 0;
    border-top: 1px solid #eef2f7;
    padding: 10px 12px;
    display: flex;
    gap: 10px;
    background: #f8fafc;
}
.btn-apply { flex: 1; padding: 8px; background: #111827; color: white; border: none; border-radius: 5px; cursor: pointer; }
.btn-reset { flex: 1; text-align: center; padding: 8px; background: #f3f4f6; color: #374151; border-radius: 5px; text-decoration: none; font-size: 0.85rem; }

.table-responsive { overflow-x: auto; }
.workflow-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.workflow-table th { text-align: left; padding: 15px 20px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; border-bottom: 1px solid #e5e7eb; background: #f9fafb; }
.workflow-table td { padding: 15px 20px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }

.file-info { display: flex; flex-direction: column; }
.file-name { font-weight: 600; color: #111827; margin-bottom: 2px; }
.file-meta { font-size: 0.8rem; color: #6b7280; }

.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; }
.ext-badge { font-size: 0.7rem; color: #6b7280; margin-top: 4px; }

.timestamp-col .time-display { font-weight: 500; color: #374151; font-size: 0.9rem; }
.timestamp-col .time-small { font-size: 0.8rem; color: #9ca3af; }
.text-muted { color: #9ca3af; font-size: 0.85rem; font-style: italic; }

.timeline-box { background: #f9fafb; padding: 8px 10px; border-radius: 6px; border: 1px solid #e5e7eb; }
.due-row { display: flex; justify-content: space-between; font-size: 0.85rem; color: #374151; }
.overdue-alert { color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 4px; }

.sla-box { text-align: center; }
.sla-label { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; }
.sla-time { font-size: 1.1rem; }
.sla-limit { font-size: 0.7rem; color: #9ca3af; }

.action-col { min-width: 200px; }
.inline-form { display: flex; gap: 8px; align-items: center; }
.mini-select { padding: 6px 10px; border-radius: 6px; border: 1px solid #d1d5db; font-size: 0.85rem; background: #fff; cursor: pointer; }
.assigned-label{ font-size:0.75rem; color:#6b7280; margin-top:4px;}

.btn { border: none; cursor: pointer; font-weight: 500; border-radius: 6px; transition: all 0.2s; }
.btn-sm { padding: 6px 12px; font-size: 0.8rem; }
.btn-primary { background: #2563eb; color: white; }
.btn-success { background: #16a34a; color: white; }
.btn-warning { background: #d97706; color: white; }
.btn-dark { background: #111827; color: white; }
.btn:hover { opacity: 0.9; transform: translateY(-1px); }

.info-text { font-style: italic; font-size: 0.85rem; padding: 5px 10px; background: #f3f4f6; border-radius: 4px; display: inline-block; }
.info-text.waiting { color: #d97706; background: #fff7ed; }
.remark-text{ font-size:0.75rem; color:#6b7280; margin-top:3px; font-style:italic; }

.pagination-wrapper { display: flex; gap: 6px; justify-content: center; padding: 20px 0;}
.pagination-info{ text-align:center; font-size:0.85rem; color:#6b7280; margin-top:15px; }

.page-btn {
    padding: 6px 12px;
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.85rem;
    color: #374151;
}

.page-btn.active {
    background: #111827;
    color: white;
    border-color: #111827;
}

.page-btn:hover { background: #f3f4f6; }

/* Admin workflow toolbar — strict state machine */
.bulk-toolbar-admin[hidden],
.bulk-toolbar-admin [hidden] {
    display: none !important;
}

.bulk-admin-valid,
.bulk-admin-mixed {
    display: inline-flex;
    align-items: center;
    gap: 12px;
}

.bulk-stage-form {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.bulk-stage-msg {
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1.4;
    color: #92400e;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 8px 12px;
    max-width: 320px;
}

.bulk-stage-msg strong {
    display: block;
    font-weight: 650;
    margin-bottom: 2px;
}

.bulk-toolbar-admin .bulk-staff-select {
    min-width: 132px;
    max-width: 180px;
}

.bulk-toolbar-admin[data-state="mixed"] {
    border-color: #fde68a;
}

.bulk-toolbar-admin #bulkStageSubmit:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    pointer-events: none;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ---------- Adaptive Filter Status dropdown ---------- */
    (function initStatusFilterDropdown() {
        var root = document.getElementById('statusFilterRoot');
        var btn = document.getElementById('statusFilterBtn');
        var menu = document.getElementById('statusDropdown');
        var scroll = menu ? menu.querySelector('.dropdown-options-scroll') : null;
        var actions = menu ? menu.querySelector('.dropdown-actions') : null;
        if (!root || !btn || !menu || !scroll) return;

        var open = false;
        var GAP = 8;
        var EDGE = 12;
        var MAX_SCROLL = 320;
        var MIN_SCROLL = 120;

        function closeMenu() {
            open = false;
            menu.classList.remove('is-open');
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            menu.style.top = '';
            menu.style.bottom = '';
            menu.style.left = '';
            menu.style.right = '';
            menu.style.maxHeight = '';
            scroll.style.maxHeight = '';
            // Return portal to form so checkbox name binding stays intact
            if (menu.parentNode !== root) {
                root.appendChild(menu);
            }
        }

        function positionMenu() {
            menu.hidden = false;
            menu.classList.add('is-open');

            // Portal to body so .main-content / .content-area overflow cannot clip
            if (menu.parentNode !== document.body) {
                document.body.appendChild(menu);
            }

            var rect = btn.getBoundingClientRect();
            var actionsH = actions ? actions.offsetHeight : 0;
            var spaceBelow = window.innerHeight - rect.bottom - GAP - EDGE;
            var spaceAbove = rect.top - GAP - EDGE;

            // Prefer downward; flip up when below cannot fit a usable menu and above has more room
            var minUsable = MIN_SCROLL + actionsH;
            var openUp = spaceBelow < minUsable && spaceAbove > spaceBelow;

            var available = openUp ? spaceAbove : spaceBelow;
            var scrollMax = Math.min(MAX_SCROLL, Math.max(40, available - actionsH));
            scroll.style.maxHeight = scrollMax + 'px';
            menu.style.maxHeight = (scrollMax + actionsH) + 'px';

            var menuWidth = menu.offsetWidth || 280;
            var left = rect.right - menuWidth;
            left = Math.max(EDGE, Math.min(left, window.innerWidth - menuWidth - EDGE));
            menu.style.left = left + 'px';
            menu.style.right = 'auto';

            if (openUp) {
                menu.style.top = 'auto';
                menu.style.bottom = (window.innerHeight - rect.top + GAP) + 'px';
            } else {
                menu.style.bottom = 'auto';
                menu.style.top = (rect.bottom + GAP) + 'px';
            }
        }

        function openMenu() {
            open = true;
            btn.setAttribute('aria-expanded', 'true');
            positionMenu();
        }

        function toggleMenu(e) {
            e.preventDefault();
            e.stopPropagation();
            if (open) closeMenu();
            else openMenu();
        }

        btn.addEventListener('click', toggleMenu);

        document.addEventListener('click', function (e) {
            if (!open) return;
            if (menu.contains(e.target) || btn.contains(e.target)) return;
            closeMenu();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && open) closeMenu();
        });

        window.addEventListener('resize', function () {
            if (open) positionMenu();
        });

        // Reposition when any ancestor scrolls (content-area, window, etc.)
        document.addEventListener('scroll', function () {
            if (open) positionMenu();
        }, true);
    })();

    /* ---------- Admin bulk selection ---------- */
    if (typeof BulkSelect === 'undefined' || !document.getElementById('selectAllBulk')) return;

    var STAGE_ACTIONS = {
        'Approved by HOD': {
            action: 'bulk_assign_retrieval',
            label: 'Assign Retrieval',
            needsStaff: true
        },
        'Return Requested': {
            action: 'bulk_assign_restoration',
            label: 'Assign Restoration',
            needsStaff: true
        },
        'File Retrieved': {
            action: 'bulk_release_file',
            label: 'Release Selected',
            needsStaff: false
        },
        'File Restored': {
            action: 'bulk_complete_transaction',
            label: 'Complete Selected',
            needsStaff: false
        }
    };

    var STATES = {
        HIDDEN: 'hidden',
        VALID: 'valid',
        MIXED: 'mixed'
    };

    var toolbar = document.getElementById('bulkToolbar');
    var validPanel = document.getElementById('bulkAdminValid');
    var mixedPanel = document.getElementById('bulkAdminMixed');
    var stageForm = document.getElementById('bulkStageForm');
    var stageAction = document.getElementById('bulkStageAction');
    var staffSelect = document.getElementById('bulkStaffSelect');
    var stageSubmit = document.getElementById('bulkStageSubmit');
    var clearMixedBtn = document.getElementById('bulkClearMixedBtn');

    var currentState = STATES.HIDDEN;
    var activeStage = null;

    function resetStaffSelect() {
        if (!staffSelect) return;
        staffSelect.selectedIndex = 0;
        staffSelect.required = false;
        staffSelect.disabled = true;
        staffSelect.hidden = true;
    }

    function disableActions() {
        activeStage = null;
        if (stageAction) stageAction.value = '';
        if (stageSubmit) {
            stageSubmit.disabled = true;
            stageSubmit.setAttribute('aria-disabled', 'true');
        }
        resetStaffSelect();
    }

    function configureValidAction(status) {
        var config = STAGE_ACTIONS[status];
        if (!config) {
            disableActions();
            return false;
        }

        activeStage = status;
        if (stageAction) stageAction.value = config.action;
        if (stageSubmit) {
            stageSubmit.textContent = config.label;
            stageSubmit.className = 'btn-bulk ' + (config.action === 'bulk_complete_transaction' ? 'btn-bulk-dark' : 'btn-bulk-primary');
            stageSubmit.disabled = false;
            stageSubmit.removeAttribute('aria-disabled');
        }

        if (staffSelect) {
            if (config.needsStaff) {
                staffSelect.hidden = false;
                staffSelect.disabled = false;
                staffSelect.required = true;
            } else {
                resetStaffSelect();
            }
        }

        return true;
    }

    function applyToolbarState(nextState, status) {
        currentState = nextState;
        if (toolbar) toolbar.setAttribute('data-state', nextState);

        if (nextState === STATES.HIDDEN) {
            if (validPanel) validPanel.hidden = true;
            if (mixedPanel) mixedPanel.hidden = true;
            disableActions();
            return;
        }

        if (nextState === STATES.MIXED) {
            // Mixed: warning + Clear Selection only. No actions ever.
            if (validPanel) validPanel.hidden = true;
            if (mixedPanel) mixedPanel.hidden = false;
            disableActions();
            return;
        }

        // VALID: show only the matching stage action
        if (mixedPanel) mixedPanel.hidden = true;
        if (validPanel) validPanel.hidden = false;
        if (!configureValidAction(status)) {
            applyToolbarState(STATES.MIXED, null);
        }
    }

    function resolveSelectionState(count, statuses) {
        if (count === 0) {
            return { state: STATES.HIDDEN, status: null };
        }

        var unique = [];
        statuses.forEach(function (s) {
            if (s && unique.indexOf(s) === -1) unique.push(s);
        });

        if (unique.length !== 1 || !STAGE_ACTIONS[unique[0]]) {
            return { state: STATES.MIXED, status: null };
        }

        return { state: STATES.VALID, status: unique[0] };
    }

    var bulk = BulkSelect.init({
        root: document.getElementById('adminRequestsRoot'),
        modeContainer: document.getElementById('adminRequestsRoot'),
        noun: 'request',
        onChange: function (count) {
            var statuses = [];
            document.querySelectorAll('.bulk-checkbox:checked').forEach(function (cb) {
                statuses.push(cb.getAttribute('data-status'));
            });

            var resolved = resolveSelectionState(count, statuses);
            applyToolbarState(resolved.state, resolved.status);
        }
    });

    applyToolbarState(STATES.HIDDEN, null);

    if (clearMixedBtn) {
        clearMixedBtn.addEventListener('click', function () {
            bulk.clearSelection();
            bulk._update();
        });
    }

    if (stageForm) {
        stageForm.addEventListener('submit', function (e) {
            if (currentState !== STATES.VALID || !activeStage || !STAGE_ACTIONS[activeStage]) {
                e.preventDefault();
                return;
            }

            var config = STAGE_ACTIONS[activeStage];

            if (config.needsStaff && (!staffSelect || staffSelect.hidden || staffSelect.disabled || !staffSelect.value)) {
                e.preventDefault();
                if (staffSelect && !staffSelect.hidden) staffSelect.focus();
                return;
            }

            if (!bulk.fillForm(stageForm)) {
                e.preventDefault();
                return;
            }

            var n = bulk.getSelectedIds().length;
            if (!confirm(config.label + ' for ' + n + ' request(s)?')) {
                e.preventDefault();
            }
        });
    }
});
</script>
<?php require_once '../includes/footer.php'; ?>
