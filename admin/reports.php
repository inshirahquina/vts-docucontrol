<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php';

if(!isAdmin()) redirect('../index.php');

// --- FILTER LOGIC ---
 $filter = $_GET['filter'] ?? 'all';
 $startDate = '';

// Calculate Date Range based on filter
if ($filter == 'monthly') {
    $startDate = "AND DATE(a.timestamp) >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($filter == 'quarterly') {
    $startDate = "AND DATE(a.timestamp) >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
} elseif ($filter == 'yearly') {
    $startDate = "AND DATE(a.timestamp) >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}

// EXPORT LOGIC
if(isset($_GET['export']) && $_GET['export'] == 'audit') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_'.date('Y-m-d').'.csv"');
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, ['Request ID', 'Timestamp', 'User', 'Role', 'Status', 'File Name', 'Department']);
    
    // Query
    $sql = "SELECT a.*, u.full_name, u.role 
             FROM audit_logs a 
             JOIN users u ON a.user_id = u.id 
             WHERE 1=1 $startDate 
             ORDER BY a.timestamp DESC";
    $stmt = $pdo->query($sql);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Decode details
        $dets = json_decode($row['details'], true);
        $row['req_id'] = $dets['request_id'] ?? '-';
        $row['status'] = $dets['status'] ?? '-';
        $row['file'] = $dets['file_name'] ?? '-';
        $row['dept'] = $dets['department'] ?? '-';
        
        fputcsv($output, [$row['req_id'], $row['timestamp'], $row['full_name'], $dets['role'] ?? '-', $row['status'], $row['file'], $row['dept']]);
    }
    fclose($output);
    exit;
}
?>

<!-- WRAPPER START -->
<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                    <h3>Audit Trail</h3>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <!-- Search Input -->
                        <input type="text" id="searchInput" placeholder="Search File, Department, or User..." style="padding:8px; border:1px solid #ddd; border-radius:4px;">
                        
                        <!-- Filters -->
                        <select id="filterSelect" onchange="applyFilters()" style="padding:8px; border:1px solid #ddd; border-radius:4px;">
                            <option value="all">All Time</option>
                            <option value="monthly" <?= $filter == 'monthly' ? 'selected' : '' ?>>Last Month</option>
                            <option value="quarterly" <?= $filter == 'quarterly' ? 'selected' : '' ?>>Last 3 Months</option>
                            <option value="yearly" <?= $filter == 'yearly' ? 'selected' : '' ?>>Last Year</option>
                        </select>

                        <a href="?export=audit&filter=<?= $filter ?>" class="btn">Export CSV</a>
                    </div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Status</th>
                            <th>File Details</th>
                        </tr>
                    </thead>
                    <tbody id="auditTableBody">
                        <?php 
                        $sql = "SELECT a.*, u.full_name, u.role as user_role 
                                     FROM audit_logs a 
                                     JOIN users u ON a.user_id = u.id 
                                     WHERE 1=1 $startDate 
                                     ORDER BY a.timestamp DESC LIMIT 50";
                        $logs = $pdo->query($sql);
                        if($logs->rowCount() > 0):
                            while($row = $logs->fetch()):
                                // Decode details
                                $details = json_decode($row['details'], true);
                        ?>
                        <tr>
                            <td style="font-size:0.85rem; color:var(--text-light);"><?= $row['timestamp'] ?></td>
                            <td>
                                <strong><?= sanitize($row['full_name']) ?></strong><br>
                                <span style="font-size:0.75rem; color:#888; text-transform:uppercase;"><?= ucfirst($row['user_role']) ?></span>
                            </td>
                            <td>
                                <span style="font-weight:bold; color:var(--accent);"><?= $details['status'] ?? 'System Action' ?></span>
                            </td>
                            <td style="font-size:0.85rem;">
                                <?php if(isset($details['file_name'])): ?>
                                    <strong><?= sanitize($details['file_name']) ?></strong><br>
                                    <span style="color:#666;"><?= sanitize($details['department']) ?></span>
                                <?php else: ?>
                                    <span style="color:#999;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; 
                        else: ?>
                        <tr><td colspan="4" style="text-align:center; padding: 20px;">No recent activity found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const filter = document.getElementById('filterSelect').value;
    const search = document.getElementById('searchInput').value;
    // Simple reload for now
    window.location.href = `reports.php?filter=${filter}`;
}
</script>

<?php require_once '../includes/footer.php'; ?>