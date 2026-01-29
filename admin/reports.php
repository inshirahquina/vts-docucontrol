<?php  
require_once '../config/db.php';
require_once '../config/functions.php';

if (!isAdmin()) {
    redirect('../index.php');
}

 $perPage = 20;
 $page    = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
 $offset  = ($page - 1) * $perPage;

// --- SEARCH LOGIC (Applied on top of Statuses) ---
 $searchSQL = '';
 $searchTerm = '';
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
    // Search in file name, username, or department
    $searchSQL = "AND (f.file_name LIKE :s OR u.username LIKE :s OR f.department LIKE :s)";
}

// --- TIME FILTER LOGIC ---
 $filter = $_GET['filter'] ?? 'all';
 $dateSQL = '';

if ($filter === 'biweekly') {
    $dateSQL = "AND fh.created_at >= DATE_SUB(NOW(), INTERVAL 2 WEEK)";
} elseif ($filter === 'monthly') {
    $dateSQL = "AND fh.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
} elseif ($filter === 'quarterly') { 
    $dateSQL = "AND fh.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
} elseif ($filter === 'yearly') {
    $dateSQL = "AND fh.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
}

// --- STATUS FILTER LOGIC ---
 $statusFilter = $_GET['statuses'] ?? [];
 $statusSQL = '';

if (!empty($statusFilter) && is_array($statusFilter)) {
    $placeholders = [];
    foreach ($statusFilter as $k => $status) {
        $placeholders[] = ':st' . $k;
    }
    $statusSQL = "AND fh.action IN (" . implode(',', $placeholders) . ")";
}

// Combine where clauses
// Order of application: Date -> Status -> Search
 $whereClause = "WHERE 1=1 $dateSQL $statusSQL $searchSQL";

// --- EXPORT CSV ---
if (isset($_GET['export']) && $_GET['export'] === 'audit') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Timestamp', 'User', 'Status', 'Department', 'File Name', 'Duration']);

    $sql = "
        SELECT
            fh.created_at AS timestamp,
            CONCAT(COALESCE(u.username, 'Unknown'), ' (', COALESCE(fh.performed_role, 'Unknown'), ')') AS user_with_role,
            fh.action AS status,
            f.department,
            f.file_name,
            (SELECT MAX(created_at) FROM file_history fh2 WHERE fh2.file_id = fh.file_id AND fh2.action = 'File Retrieved') AS time_retrieved,
            (SELECT MAX(created_at) FROM file_history fh3 WHERE fh3.file_id = fh.file_id AND fh3.action = 'Released') AS time_released
        FROM file_history fh
        JOIN files f ON fh.file_id = f.id
        LEFT JOIN users u ON fh.performed_by = u.id
        $whereClause
        ORDER BY fh.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    if (!empty($searchTerm)) $stmt->bindValue(':s', "%$searchTerm%");
    if (!empty($statusFilter)) {
        foreach ($statusFilter as $k => $status) {
            $stmt->bindValue(':st'.$k, $status);
        }
    }
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $retrieved = $row['time_retrieved'];
        $released = $row['time_released'];
        $duration = 0;
        
        if ($retrieved && $released) {
            $start = new DateTime($retrieved);
            $end = new DateTime($released);
            $diff = $start->diff($end);
            $duration = ($diff->h * 60) + $diff->i;
        }
        
        $slaStatus = ($duration > 20) ? ' (SLA BREACH)' : '';
        $durationText = ($duration > 0) ? $duration . ' mins' : 'Pending';

        fputcsv($output, [
            $row['timestamp'],
            $row['user_with_role'],
            $row['status'] . $slaStatus,
            $row['department'],
            $row['file_name'],
            $durationText
        ]);
    }

    fclose($output);
    exit;
}

// --- COUNT TOTAL ---
 $countSQL = "SELECT COUNT(*) FROM file_history fh JOIN files f ON fh.file_id = f.id LEFT JOIN users u ON fh.performed_by = u.id $whereClause";
 $stmtCount = $pdo->prepare($countSQL);

if (!empty($searchTerm)) $stmtCount->bindValue(':s', "%$searchTerm%");
if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $status) {
        $stmtCount->bindValue(':st'.$k, $status);
    }
}
 $stmtCount->execute();
 $totalRows  = $stmtCount->fetchColumn();
 $totalPages = ceil($totalRows / $perPage);

// --- FETCH DATA ---
 $sql = "
    SELECT
        fh.created_at AS timestamp,
        fh.action AS status,
        f.department,
        f.file_name,
        CONCAT(COALESCE(u.username, 'Unknown'), ' (', COALESCE(fh.performed_role, 'Unknown'), ')') AS user_with_role,
        (SELECT MAX(created_at) FROM file_history fh2 WHERE fh2.file_id = fh.file_id AND fh2.action = 'File Retrieved') AS time_retrieved,
        (SELECT MAX(created_at) FROM file_history fh3 WHERE fh3.file_id = fh.file_id AND fh3.action = 'Released') AS time_released
    FROM file_history fh
    JOIN files f ON fh.file_id = f.id
    LEFT JOIN users u ON fh.performed_by = u.id
    $whereClause
    ORDER BY fh.created_at DESC
    LIMIT $perPage OFFSET $offset
";

 $stmt = $pdo->prepare($sql);

if (!empty($searchTerm)) $stmt->bindValue(':s', "%$searchTerm%");
if (!empty($statusFilter)) {
    foreach ($statusFilter as $k => $status) {
        $stmt->bindValue(':st'.$k, $status);
    }
}
 $stmt->execute();
 $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to preserve query params
function buildQueryString($params = []) {
    $query = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return http_build_query($query);
}

require_once '../includes/header.php';
?>

<style>
/* Custom UX Styles */
.status-dropdown-container {
    position: relative;
    display: inline-block;
}

.filter-btn {
    background-color: white;
    border: 1px solid #ccc;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 140px;
    justify-content: space-between;
    transition: all 0.2s;
}
.filter-btn:hover {
    border-color: #999;
    background-color: #f9f9f9;
}
.filter-btn .count-badge {
    background: #0d47a1;
    color: white;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 10px;
    display: none; /* Hidden unless selected */
}

.dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background: white;
    border: 1px solid #ddd;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-radius: 4px;
    padding: 10px;
    width: 200px;
    z-index: 1000;
    margin-top: 4px;
}

.dropdown-menu.show {
    display: block;
}

.dropdown-item {
    display: flex;
    align-items: center;
    padding: 6px 8px;
    cursor: pointer;
    border-radius: 3px;
    color: #333;
    font-size: 14px;
}

.dropdown-item:hover {
    background-color: #f0f4f8;
}

.dropdown-item input[type="checkbox"] {
    margin-right: 10px;
    width: 16px;
    height: 16px;
    accent-color: #0d47a1;
}

.sla-badge {
    display: inline-block;
    background: #dc2626;
    color: white;
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 6px;
    font-weight: bold;
}
</style>

<div class="layout-wrapper">
<?php require_once '../includes/sidebar.php'; ?>

<div class="main-content">
<div class="content-area">

<div class="card">
    
    <!-- Controls Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
        
        <h3 style="margin:0;">Audit Logs</h3>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            
            <!-- 1. The Filter Button with Dropdown -->
            <div class="status-dropdown-container">
                <button class="filter-btn" onclick="toggleDropdown()">
                    <span>Filter Status</span>
                    <span class="count-badge" id="statusCount"><?= !empty($statusFilter) ? count($statusFilter) : '' ?></span>
                    <span style="font-size:10px;">▼</span>
                </button>
                
                <div class="dropdown-menu" id="statusDropdown">
                    <form method="GET" action="" id="filterForm" style="margin:0;">
                        <!-- Preserve other params if needed -->
                        <?php if(isset($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($_GET['filter']) ?>"><?php endif; ?>
                        <?php if(isset($_GET['search'])): ?><input type="hidden" name="search" value="<?= htmlspecialchars($_GET['search']) ?>"><?php endif; ?>
                        <?php if(isset($_GET['page'])): ?><input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page']) ?>"><?php endif; ?>

                        <div class="dropdown-item">
                            <input type="checkbox" name="statuses[]" value="File Requested" <?= in_array('File Requested', $statusFilter) ? 'checked' : '' ?> onchange="this.form.submit()"> 
                            <span>File Requested</span>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="statuses[]" value="Completed" <?= in_array('Completed', $statusFilter) ? 'checked' : '' ?> onchange="this.form.submit()"> 
                            <span>Completed</span>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="statuses[]" value="Released" <?= in_array('Released', $statusFilter) ? 'checked' : '' ?> onchange="this.form.submit()"> 
                            <span>Released</span>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="statuses[]" value="File Retrieved" <?= in_array('File Retrieved', $statusFilter) ? 'checked' : '' ?> onchange="this.form.submit()"> 
                            <span>File Retrieved</span>
                        </div>
                        <div class="dropdown-item">
                            <input type="checkbox" name="statuses[]" value="Cancelled" <?= in_array('Cancelled', $statusFilter) ? 'checked' : '' ?> onchange="this.form.submit()"> 
                            <span>Cancelled</span>
                        </div>
                    </form>
                </div>
            </div>


            <form method="GET" action="" style="display:flex; gap:0;">
                <?php if(isset($_GET['statuses'])): ?><?php foreach($_GET['statuses'] as $st): ?><input type="hidden" name="statuses[]" value="<?= htmlspecialchars($st) ?>"><?php endforeach; ?><?php endif; ?>
                <?php if(isset($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($_GET['filter']) ?>"><?php endif; ?>
                
                <input type="text" id="searchInput" name="search"
                       placeholder="Search file, user, or dept..."
                       value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>"
                       style="padding:8px; border:1px solid #ccc; border-right:none; border-radius:4px 0 0 4px; width:250px;">
                <button type="submit" class="btn" style="padding:8px 12px; border-radius:0 4px 4px 0;">Search</button>
                <?php if(isset($_GET['search'])): ?>
                    <a href="?<?= buildQueryString(['search' => null]) ?>" style="padding:8px 12px; background:#eee; text-decoration:none; border-radius:4px; font-size:0.9em;">✕</a>
                <?php endif; ?>
            </form>

            <!-- Export -->
            <a href="?<?= buildQueryString(['export' => 'audit', 'page' => null]) ?>" 
               class="btn" style="background:#2e7d32; color:white; padding:8px 16px; text-decoration:none; border-radius:4px;">
               Export
            </a>

        </div>
    </div>

    <!-- Table -->
    <table id="auditTable" style="width:100%; border-collapse: collapse; font-size: 0.9rem;">
        <thead>
            <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6; text-align: left;">
                <th style="padding:12px;">Timestamp</th>
                <th style="padding:12px;">User</th>
                <th style="padding:12px;">Status</th>
                <th style="padding:12px;">Department</th>
                <th style="padding:12px;">File Name</th>
                <th style="padding:12px;">Duration</th>
            </tr>
        </thead>
        <tbody>

<?php if ($logs): ?>
    <?php foreach ($logs as $row): 
        $duration = 0;
        $retrieved = $row['time_retrieved'] ?? null;
        $released = $row['time_released'] ?? null;

        if ($retrieved && $released) {
            $start = new DateTime($retrieved);
            $end = new DateTime($released);
            $diff = $start->diff($end);
            $duration = ($diff->h * 60) + $diff->i;
        }

        $isOverSLA = ($duration > 20);
        $durationText = ($duration > 0) ? $duration . " mins" : "Pending";
        
        $rowStyle = ($isOverSLA) ? "background-color: #fef2f2; color: #991b1b;" : "";
    ?>
    <tr style="<?= $rowStyle ?> border-bottom: 1px solid #eee;">
        <td style="padding:12px; vertical-align:middle;"><?= $row['timestamp'] ?></td>
        <td style="padding:12px; vertical-align:middle;"><?= sanitize($row['user_with_role']) ?></td>
        <td style="padding:12px; vertical-align:middle; font-weight:600;">
            <?= sanitize($row['status']) ?>
            <?php if($isOverSLA): ?>
                <span class="sla-badge">SLA BREACH</span>
            <?php endif; ?>
        </td>
        <td style="padding:12px; vertical-align:middle;"><?= sanitize($row['department']) ?></td>
        <td style="padding:12px; vertical-align:middle;"><?= sanitize($row['file_name']) ?></td>
        <td style="padding:12px; vertical-align:middle; font-weight:bold;">
            <?= $durationText ?>
        </td>
    </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="6" style="text-align:center; padding:30px; color:#666;">
            No records found.
        </td>
    </tr>
<?php endif; ?>

        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="margin-top:25px; display:flex; justify-content:center; gap:5px;">
        <?php for($i=1;$i<=$totalPages;$i++): ?>
            <a href="?<?= buildQueryString(['page' => $i]) ?>"
               style="padding:6px 12px; border:1px solid #ccc; border-radius:4px; text-decoration:none; color:#333; <?= $i==$page?'background:#0d47a1;color:white;':'background:white;' ?>">
               <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

</div>
</div>
</div>
</div>

<script>
function toggleDropdown() {
    var dropdown = document.getElementById("statusDropdown");
    dropdown.classList.toggle("show");
}

// Close the dropdown if the user clicks outside of it
window.onclick = function(event) {
    if (!event.target.matches('.filter-btn') && !event.target.matches('.filter-btn *')) {
        var dropdowns = document.getElementsByClassName("dropdown-menu");
        for (var i = 0; i < dropdowns.length; i++) {
            var openDropdown = dropdowns[i];
            if (openDropdown.classList.contains('show')) {
                openDropdown.classList.remove('show');
            }
        }
    }
}

// Show/Hide the count badge on the button
var countBadge = document.getElementById('statusCount');
if(countBadge.innerText.trim() !== "") {
    countBadge.style.display = "inline-block";
}
</script>

<?php require_once '../includes/footer.php'; ?>