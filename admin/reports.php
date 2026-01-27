<?php
// 1. Load Config & Functions
require_once '../config/db.php';
require_once '../config/functions.php';

// 2. Role Check (Admins Only)
if(!isAdmin()) {
    redirect('../index.php');
}

// 3. Determine Date Filter
$filter = $_GET['filter'] ?? 'all';
$startDateSQL = '';
if ($filter == 'monthly') {
    $startDateSQL = "AND DATE(a.timestamp) >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($filter == 'quarterly') {
    $startDateSQL = "AND DATE(a.timestamp) >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
} elseif ($filter == 'yearly') {
    $startDateSQL = "AND DATE(a.timestamp) >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}

// 4. Export CSV Logic (Before HTML Output)
if(isset($_GET['export']) && $_GET['export'] == 'audit') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_'.date('Y-m-d').'.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Request ID','Timestamp','User','Role','Status','File Name','Department']);

    $sql = "SELECT a.request_id, a.timestamp, u.full_name, a.role, a.status, a.file_name, a.department
            FROM audit_logs a
            JOIN users u ON a.user_id = u.id
            WHERE 1=1 $startDateSQL
            ORDER BY a.timestamp DESC";
    $stmt = $pdo->query($sql);

    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['request_id'],
            $row['timestamp'],
            $row['full_name'],
            $row['role'],
            $row['status'],
            $row['file_name'],
            $row['department']
        ]);
    }

    fclose($output);
    exit;
}

// 5. Include Header
require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3>Audit Logs</h3>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <!-- Live Search Input -->
                        <input type="text" id="searchInput" placeholder="Search File, Department, or User..." style="padding:8px; border:1px solid #ddd; border-radius:4px;">

                        <!-- Filters -->
                        <select id="filterSelect" onchange="applyFilters()" style="padding:8px; border:1px solid #ddd; border-radius:4px;">
                            <option value="all" <?= $filter=='all'?'selected':'' ?>>All Time</option>
                            <option value="monthly" <?= $filter=='monthly'?'selected':'' ?>>Last Month</option>
                            <option value="quarterly" <?= $filter=='quarterly'?'selected':'' ?>>Last 3 Months</option>
                            <option value="yearly" <?= $filter=='yearly'?'selected':'' ?>>Last Year</option>
                        </select>

                        <a href="?export=audit&filter=<?= $filter ?>" class="btn">Export CSV</a>
                    </div>
                </div>

                <table id="auditTable">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>File Name</th>
                            <th>Department</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch last 50 logs
                        $sql = "SELECT a.request_id, a.timestamp, u.full_name, a.role, a.status, a.file_name, a.department
                                FROM audit_logs a
                                JOIN users u ON a.user_id = u.id
                                WHERE 1=1 $startDateSQL
                                ORDER BY a.timestamp DESC
                                LIMIT 50";
                        $stmt = $pdo->query($sql);

                        if($stmt->rowCount() > 0):
                            while($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                        ?>
                        <tr>
                            <td><?= $row['timestamp'] ?></td>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= ucfirst($row['role']) ?></td>
                            <td><?= sanitize($row['status']) ?></td>
                            <td><?= sanitize($row['file_name']) ?></td>
                            <td><?= sanitize($row['department']) ?></td>
                        </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px;">No recent activity found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Filter and Search
function applyFilters() {
    const filter = document.getElementById('filterSelect').value;
    window.location.href = `audit_logs.php?filter=${filter}`;
}

// Live Search
document.getElementById('searchInput').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase();
    const rows = document.querySelectorAll('#auditTable tbody tr');
    rows.forEach(row => {
        row.style.display = Array.from(row.cells).some(td => td.textContent.toLowerCase().includes(searchTerm)) ? '' : 'none';
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
