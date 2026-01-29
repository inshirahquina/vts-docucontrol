<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/functions.php';

if (!isset($_SESSION['user_id'])) redirect('../auth/login.php');
if ($_SESSION['role'] !== 'requestor') redirect('../index.php');

$uid = $_SESSION['user_id'];

// --- Pagination ---
$perPage = 20;
$page    = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

// --- Filters & Search ---
$searchTerm = $_GET['search'] ?? '';
$searchSQL = $searchTerm ? " AND (f.file_name LIKE :s OR f.barcode LIKE :s OR f.department LIKE :s)" : "";

$statusFilter = $_GET['statuses'] ?? [];
$statusSQL = "";
if (!empty($statusFilter) && is_array($statusFilter)) {
    $placeholders = [];
    foreach ($statusFilter as $k => $s) $placeholders[] = ":st$k";
    $statusSQL = " AND r.current_status IN (" . implode(',', $placeholders) . ")";
}

// --- Status map ---
$statusMap = [
    'Requested' => ['Pending Approval','#fef3c7','#d97706','⏳'],
    'File Retrieved' => ['Processing','#e0f2fe','#0369a1','🛠️'],
    'Released' => ['With You','#dcfce7','#15803d','📂'],
    'File Restored' => ['Returning','#f3e8ff','#7e22ce','↩️'],
    'Completed' => ['Completed','#d1fae5','#065f46','✅'],
    'Cancelled' => ['Cancelled','#fee2e2','#b91c1c','🚫']
];

// --- Count total rows (named placeholders only) ---
$countSQL = "SELECT COUNT(*) FROM requests r
             JOIN files f ON r.file_id=f.id
             WHERE r.user_id=:uid $searchSQL $statusSQL";
$stmtCount = $pdo->prepare($countSQL);
$stmtCount->bindValue(':uid', $uid);

if ($searchTerm) {
    $stmtCount->bindValue(':s', "%$searchTerm%");
}
if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) {
        $stmtCount->bindValue(":st$k", $s);
    }
}
$stmtCount->execute();
$totalRows = $stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// --- Fetch requests ---
$sql = "SELECT r.*, f.file_name, f.barcode, f.department
        FROM requests r
        JOIN files f ON r.file_id=f.id
        WHERE r.user_id=:uid $searchSQL $statusSQL
        ORDER BY r.borrow_date DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':uid', $uid);
if ($searchTerm) $stmt->bindValue(':s', "%$searchTerm%");
if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $s) {
        $stmt->bindValue(":st$k", $s);
    }
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="layout-wrapper">
<?php require_once '../includes/sidebar.php'; ?>

<div class="main-content">
<div class="content-area">

<div class="card card-container">
    <div class="header-row">
        <h3>My Files</h3>
        <div class="filters-row">

            <!-- Search Input -->
            <form method="GET" class="search-form">
                <?php foreach($statusFilter as $st): ?>
                    <input type="hidden" name="statuses[]" value="<?= htmlspecialchars($st) ?>">
                <?php endforeach; ?>
                <input type="text" name="search" placeholder="Search file name" value="<?= htmlspecialchars($searchTerm) ?>">
                <button type="submit">Search</button>
            </form>

            <!-- Filter Dropdown -->
            <div class="status-dropdown-container">
                <button class="filter-btn">
                    <span>Filter Status</span>
                    <span class="count-badge" id="statusCount"><?= count($statusFilter) ?: '' ?></span>
                    <span class="arrow">▼</span>
                </button>
                <div class="dropdown-menu" id="statusDropdown">
                    <form method="GET" id="filterForm">
                        <?php if(isset($_GET['search'])): ?>
                            <input type="hidden" name="search" value="<?= htmlspecialchars($_GET['search']) ?>">
                        <?php endif; ?>
                        <?php foreach($statusMap as $key=>$v): ?>
                        <label class="dropdown-item">
                            <input type="checkbox" name="statuses[]" value="<?= $key ?>" <?= in_array($key,$statusFilter)?'checked':'' ?> onchange="this.form.submit()">
                            <?= $key ?>
                        </label>
                        <?php endforeach; ?>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- Table -->
    <table class="modern-table">
        <thead>
            <tr>
                <th>File</th>
                <th>Dates & Due</th>
                <th>Status</th>
                <th style="text-align:right;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if($rows): ?>
            <?php foreach($rows as $row):
                $statusKey = $row['current_status'] ?: 'Cancelled';
                if(!isset($statusMap[$statusKey])) $statusKey='Cancelled';
                [$statusLabel,$bg,$color,$icon] = $statusMap[$statusKey];

                $released = $row['released_at'] ?? null;
                $dueDate = $released ? date('Y-m-d', strtotime("$released +3 days")) : null;
                $overDue = $dueDate && (strtotime(date('Y-m-d'))>strtotime($dueDate));

                $retrieved = $row['retrieved_at'] ?? null;
                $duration = 0;
                if($retrieved && $released){
                    $start = new DateTime($retrieved);
                    $end = new DateTime($released);
                    $diff = $start->diff($end);
                    $duration = ($diff->h*60)+$diff->i;
                }
                $overSLA = ($duration>20);
                $durationText = $duration>0 ? $duration.' mins' : 'Pending';

                $actionBtn=false;$actionType=null;
                if($statusKey==='Released'){$actionBtn=true;$actionType='return';}
                if($statusKey==='Requested'){$actionBtn=true;$actionType='cancel';}
            ?>
            <tr class="<?= $overSLA ? 'over-sla' : '' ?>">
                <td>
                    <strong><?= sanitize($row['file_name']) ?></strong><br>
                    <small><?= sanitize($row['barcode']) ?> • <?= sanitize($row['department']) ?></small>
                </td>
                <td>
                    <div>Requested: <?= format_date($row['borrow_date']) ?></div>
                    <?php if($dueDate): ?>
                        <div class="<?= $overDue ? 'overdue' : 'due' ?>">
                            Due: <?= format_date($dueDate) ?> <?= $overDue?'⚠ Overdue':'' ?>
                        </div>
                    <?php endif; ?>
                    <?php if($retrieved || $released): ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-badge" style="background:<?= $bg ?>;color:<?= $color ?>;">
                        <?= $icon ?> <?= $statusLabel ?>
                    </span>
                </td>
                <td style="text-align:right;">
                    <?php if($actionBtn): ?>
                        <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Are you sure?');">
                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                            <?php if($actionType==='return'): ?>
                                <input type="hidden" name="action" value="request_return">
                                <button class="btn-action return">Return</button>
                            <?php endif; ?>
                            <?php if($actionType==='cancel'): ?>
                                <input type="hidden" name="action" value="cancel_request">
                                <button class="btn-action cancel">Cancel Request</button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <em class="no-action">No Action</em>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="4" class="no-data">No files requested yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if($totalPages>1): ?>
        <div class="pagination">
        <?php for($i=1;$i<=$totalPages;$i++): ?>
            <a href="?<?= buildQueryString(['page'=>$i]) ?>" class="<?= $i==$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
</div>
</div>

<!-- --- Styles --- -->
<style>
/* Card & Layout */
.card-container { padding: 20px; border-radius: 12px; background: #fff; box-shadow: 0 3px 12px rgba(0,0,0,0.05); }
.header-row { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.filters-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }

/* Search Form */
.search-form { display:flex; gap:0; }
.search-form input[type=text] { padding:8px 12px; border:1px solid #ccc; border-radius:6px 0 0 6px; min-width:200px; }
.search-form button { padding:8px 14px; border:none; background:#0d47a1; color:#fff; border-radius:0 6px 6px 0; cursor:pointer; }
.search-form button:hover { background:#08306b; }

/* Filter Dropdown */
.status-dropdown-container { position: relative; display:inline-block; }
.filter-btn { display:flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; border:none; background:#0d47a1; color:#fff; font-weight:600; cursor:pointer; box-shadow:0 2px 6px rgba(0,0,0,0.15); }
.filter-btn:hover { background:#08306b; }
.filter-btn .count-badge { display:none; background:#ef4444; padding:2px 8px; border-radius:50%; font-size:0.75rem; }
.filter-btn .arrow { font-size:0.7rem; }

.dropdown-menu { display:none; position:absolute; top:120%; right:0; background:#fff; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,0.2); min-width:180px; padding:10px 0; z-index:10; }
.dropdown-menu.show { display:block; }
.dropdown-item { display:flex; align-items:center; gap:6px; padding:6px 14px; cursor:pointer; transition: background 0.15s; }
.dropdown-item:hover { background:#f3f4f6; }

/* Table */
.modern-table { width:100%; border-collapse:collapse; margin-top:20px; }
.modern-table th { padding:12px; text-align:left; background:#f8f9fa; border-bottom:2px solid #ddd; }
.modern-table td { padding:12px; vertical-align:top; border-bottom:1px solid #eee; }
.modern-table tbody tr:hover { background:#f9fafb; }
.status-badge { padding:6px 14px; border-radius:20px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.over-sla { background:#fef2f2; color:#991b1b; }
.overdue { color:#dc2626; font-weight:600; }
.due { color:#065f46; font-weight:600; }
.over-sla-text { color:#dc2626; font-size:0.85rem; }
.sla-text { color:#065f46; font-size:0.85rem; }

/* Action Buttons */
.btn-action { padding:8px 14px; border-radius:6px; border:none; cursor:pointer; font-weight:600; transition:all 0.2s; }
.btn-action.return { background:#ef4444; color:#fff; }
.btn-action.cancel { background:#fff; color:#6b7280; border:1px solid #d1d5db; }
.btn-action:hover { opacity:0.9; }
.no-action { color:#9ca3af; font-style:italic; }

/* Pagination */
.pagination { margin-top:20px; display:flex; justify-content:center; gap:5px; }
.pagination a { padding:6px 12px; border-radius:4px; border:1px solid #ccc; text-decoration:none; color:#333; }
.pagination a.active { background:#0d47a1; color:#fff; }
.no-data { padding:30px; text-align:center; color:#666; }
</style>

<!-- --- JS --- -->
<script>
const filterBtn = document.querySelector('.filter-btn');
const dropdown = document.getElementById('statusDropdown');
filterBtn.addEventListener('click', ()=>dropdown.classList.toggle('show'));
window.addEventListener('click', e=>{
    if(!filterBtn.contains(e.target) && !dropdown.contains(e.target)){
        dropdown.classList.remove('show');
    }
});
const countBadge = document.getElementById('statusCount');
if(countBadge.innerText.trim()!=="") countBadge.style.display="inline-block";
</script>

<?php require_once '../includes/footer.php'; ?>
