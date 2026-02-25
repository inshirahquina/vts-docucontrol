<?php
require_once '../config/db.php';
require_once '../config/functions.php';

$allowed_roles = ['admin', 'staff', 'operations'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    redirect('../index.php');
}

$activeRole = $_SESSION['active_role'] ?? $_SESSION['role'];
$uid = $_SESSION['user_id'];

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
    $whereClauses[] = "(f.file_name LIKE :search 
                        OR u_req.full_name LIKE :search 
                        OR f.allocation LIKE :search)";
    $params[':search'] = "%$searchTerm%";
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

    CASE 
        WHEN r.released_at IS NOT NULL 
            THEN DATE_ADD(r.released_at, INTERVAL (3 + (r.extension_count * 3)) DAY)
        ELSE NULL
    END AS calculated_due_date

FROM requests r
JOIN files f ON r.file_id = f.id
JOIN users u_req ON r.user_id = u_req.id
LEFT JOIN users u_op ON r.assigned_to = u_op.id
$whereSQL
ORDER BY r.updated_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

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

function formatDuration($minutes) {
    if ($minutes === null) return "--";
    if ($minutes < 1) return "0m";
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return ($h > 0 ? $h . "h " : "") . $m . "m";
}

require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="content-area">
            
            <div class="page-header">
                <div>
                    <h1>Document Workflow</h1>
                    <p>Manage file requests, retrieval, and restoration tasks.</p>
                </div>
            </div>

            <!-- Filters Card -->
            <div class="card filter-card">
                <form method="GET" class="filter-form">
                    <div class="search-box">
                        <span class="search-icon">🔍</span>
                        <input type="text" name="search" placeholder="Search file, name, or allocation..." value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    
                    <div class="dropdown-filter">
                        <button type="button" class="dropdown-btn" onclick="toggleDropdown()">
                            <span>Filter Status</span>
                            <?php if(count($filterStatuses) > 0): ?>
                                <span class="badge-count"><?= count($filterStatuses) ?></span>
                            <?php endif; ?>
                            <span class="arrow">▼</span>
                        </button>
                        <div id="statusDropdown" class="dropdown-content">
                            <?php foreach($statusList as $s): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="status_filter[]" value="<?= $s ?>" <?= in_array($s, $filterStatuses) ? 'checked' : '' ?> onchange="this.form.submit()"> 
                                <span><?= $s ?></span>
                            </label>
                            <?php endforeach; ?>
                            <div class="dropdown-actions">
                                <button type="submit" class="btn-apply">Apply Filters</button>
                                <a href="?" class="btn-reset">Reset</a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Data Table Card -->
            <div class="card table-card">
                <div class="table-responsive">
                    <table class="workflow-table">
                        <thead>
                            <tr>
                                <th>File Details</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th>Timeline & SLA</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $stmt->fetch()): 
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
                                $isOverdue = ($dueDate && strtotime(date('Y-m-d')) > strtotime($dueDate) && $displayStatus == 'Released');
                                
                                $slaColor = '#10b981'; 
                                if ($mins > 20) $slaColor = '#ef4444'; 
                                elseif ($mins > 15) $slaColor = '#f59e0b'; 

                                $lastUpdate = $row['last_updated_time'];
                            ?>
                            <tr>
                                <td>
                                    <div class="file-info">
                                        <span class="file-name"><?= sanitize($row['file_name']) ?></span>
                                        <span class="file-meta">
                                            Alloc: <?= sanitize($row['allocation']) ?> • 
                                            Box: <?= sanitize($row['box_no']) ?> • 
                                            Req by: <?= sanitize($row['requester_name']) ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="status-badge" style="background:<?= $style['bg'] ?>; color:<?= $style['color'] ?>;">
                                        <span class="icon"><?= $style['icon'] ?></span>
                                        <?= $displayStatus ?>
                                    </div>
                                    <?php if($row['extension_count'] > 0): ?>
                                        <div class="ext-badge">Extension x<?= $row['extension_count'] ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="timestamp-col">
                                    <?php if($row['hod_timestamp']): ?>
                                        <!-- Show HOD timestamp if available -->
                                        <div class="time-display"><?= date('d M Y', strtotime($row['hod_timestamp'])) ?></div>
                                        <div class="time-small"><?= date('H:i', strtotime($row['hod_timestamp'])) ?></div>
                                    <?php elseif($row['last_updated_time']): ?>
                                        <!-- Fallback -->
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
                                            <div class="sla-limit">(Limit: 20m)</div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">--</span>
                                    <?php endif; ?>
                                </td>

                                <td class="action-col">
                                    <?php 
                                    // ACTION LOGIC (Same as before, unchanged logic just cleaner look)
                                    if($displayStatus == 'Pending HOD Approval'): ?>
                                        <span class="info-text waiting">Waiting for HOD</span>

                                    <?php elseif($displayStatus == 'Approved by HOD' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form">
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
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form">
                                            <input type="hidden" name="action" value="confirm_retrieval">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm">✅ Retrieved</button>
                                        </form>

                                    <?php elseif($displayStatus == 'File Retrieved' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form">
                                            <input type="hidden" name="action" value="release_file">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Release File</button>
                                        </form>

                                    <?php elseif($displayStatus == 'Return Requested' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form">
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
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form">
                                            <input type="hidden" name="action" value="confirm_restoration">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm">✅ Restored</button>
                                        </form>

                                    <?php elseif($displayStatus == 'File Restored' && $activeRole == 'admin'): ?>
                                        <form method="POST" action="../actions/request_actions.php" class="inline-form">
                                            <input type="hidden" name="action" value="complete_transaction">
                                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-dark btn-sm">Complete</button>
                                        </form>
                                    
                                    <?php elseif(in_array($displayStatus, ['Completed', 'Cancelled'])): ?>
                                        <span class="text-muted">No Action</span>
                                    
                                    <?php else: ?>
                                        <span class="text-muted">Processing...</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
/* --- Modern UI Styles --- */

/* Layout */
.content-area { padding: 25px; background: #f4f6f9; min-height: 100vh; }
.page-header { margin-bottom: 25px; }
.page-header h1 { font-size: 1.5rem; color: #111827; margin: 0 0 5px 0; }
.page-header p { font-size: 0.9rem; color: #6b7280; margin: 0; }

/* Cards */
.card { background: #fff; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
.filter-card { padding: 15px 20px; }

/* Filters */
.filter-form { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
.search-box { position: relative; flex: 1; min-width: 250px; }
.search-box input { width: 100%; padding: 10px 15px 10px 40px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.9rem; }
.search-box .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); opacity: 0.5; }

.dropdown-filter { position: relative; }
.dropdown-btn { display: flex; align-items: center; gap: 8px; padding: 10px 15px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; font-weight: 500; color: #374151; }
.dropdown-btn .badge-count { background: #3b82f6; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; }
.dropdown-content { display: none; position: absolute; top: 110%; right: 0; width: 250px; background: white; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 100; padding: 15px; border: 1px solid #e5e7eb; }
.show-dropdown { display: block !important; }
.checkbox-label { display: flex; align-items: center; gap: 10px; padding: 8px 0; cursor: pointer; font-size: 0.9rem; color: #374151; }
.checkbox-label input { width: 16px; height: 16px; }
.dropdown-actions { margin-top: 10px; border-top: 1px solid #eee; padding-top: 10px; display: flex; gap: 10px; }
.btn-apply { flex: 1; padding: 8px; background: #111827; color: white; border: none; border-radius: 5px; cursor: pointer; }
.btn-reset { flex: 1; text-align: center; padding: 8px; background: #f3f4f6; color: #374151; border-radius: 5px; text-decoration: none; font-size: 0.85rem; }

/* Table */
.table-responsive { overflow-x: auto; }
.workflow-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.workflow-table th { text-align: left; padding: 15px 20px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; border-bottom: 1px solid #e5e7eb; background: #f9fafb; }
.workflow-table td { padding: 15px 20px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }

.file-info { display: flex; flex-direction: column; }
.file-name { font-weight: 600; color: #111827; margin-bottom: 2px; }
.file-meta { font-size: 0.8rem; color: #6b7280; }

/* Status Badges */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; }
.status-badge .icon { font-size: 0.9rem; }
.ext-badge { font-size: 0.7rem; color: #6b7280; margin-top: 4px; }

/* Timestamps */
.timestamp-col .time-display { font-weight: 500; color: #374151; font-size: 0.9rem; }
.timestamp-col .time-small { font-size: 0.8rem; color: #9ca3af; }
.text-muted { color: #9ca3af; font-size: 0.85rem; font-style: italic; }

/* Timeline & SLA */
.timeline-box { background: #f9fafb; padding: 8px 10px; border-radius: 6px; border: 1px solid #e5e7eb; }
.due-row { display: flex; justify-content: space-between; font-size: 0.85rem; color: #374151; }
.overdue-alert { color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 4px; }

.sla-box { text-align: center; }
.sla-label { font-size: 0.7rem; color: #6b7280; text-transform: uppercase; }
.sla-time { font-size: 1.1rem; }
.sla-limit { font-size: 0.7rem; color: #9ca3af; }

/* Actions */
.action-col { min-width: 200px; }
.inline-form { display: flex; gap: 8px; align-items: center; }
.mini-select { padding: 6px 10px; border-radius: 6px; border: 1px solid #d1d5db; font-size: 0.85rem; background: #fff; cursor: pointer; }

/* Buttons */
.btn { border: none; cursor: pointer; font-weight: 500; border-radius: 6px; transition: all 0.2s; }
.btn-sm { padding: 6px 12px; font-size: 0.8rem; }
.btn-primary { background: #2563eb; color: white; }
.btn-success { background: #16a34a; color: white; }
.btn-warning { background: #d97706; color: white; }
.btn-dark { background: #111827; color: white; }
.btn:hover { opacity: 0.9; transform: translateY(-1px); }

.info-text { font-style: italic; font-size: 0.85rem; padding: 5px 10px; background: #f3f4f6; border-radius: 4px; display: inline-block; }
.info-text.waiting { color: #d97706; background: #fff7ed; }

</style>
<script>
    function toggleDropdown() { document.getElementById("statusDropdown").classList.toggle("show-dropdown"); }
    window.onclick = function(event) {
        if (!event.target.matches('.dropdown-btn') && !event.target.closest('.dropdown-btn')) {
            var dropdowns = document.getElementsByClassName("dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show-dropdown')) { openDropdown.classList.remove('show-dropdown'); }
            }
        }
    }
</script>
<?php require_once '../includes/footer.php'; ?>