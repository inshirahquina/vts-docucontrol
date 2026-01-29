<?php
// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Safely get current page
$current_page = $current_page ?? basename($_SERVER['PHP_SELF']);

// Safely get active role
$role = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'requestor';
?>

<div class="sidebar">
    <?php if ($role === 'admin'): ?>
        <a href="../index.php" class="sidebar-brand">VTS Admin</a>
        <div class="sidebar-menu">
            <a href="../admin/dashboard.php" class="<?= ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            <a href="../admin/requests.php" class="<?= ($current_page === 'requests.php') ? 'active' : ''; ?>">Manage Requests</a>
            <a href="../admin/reports.php" class="<?= ($current_page === 'reports.php') ? 'active' : ''; ?>">Audit Logs</a>
        </div>

    <?php elseif ($role === 'operations'): ?>
        <a href="../index.php" class="sidebar-brand">VTS Operations</a>
        <div class="sidebar-menu">
            <a href="../staff/dashboard.php" class="<?= ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            <!-- <a href="../staff/tasks.php" class="<?= ($current_page === 'tasks.php') ? 'active' : ''; ?>">My Tasks</a> -->
            <a href="../admin/reports.php" class="<?= ($current_page === 'reports.php') ? 'active' : ''; ?>">History</a>
        </div>

    <?php else: // requestor ?>
        <a href="../index.php" class="sidebar-brand">VTS Requestor</a>
        <div class="sidebar-menu">
            <a href="../requestor/dashboard.php" class="<?= ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            <a href="../requestor/browse.php" class="<?= ($current_page === 'browse.php') ? 'active' : ''; ?>">Browse Files</a>
            <a href="../requestor/my_files.php" class="<?= ($current_page === 'my_files.php') ? 'active' : ''; ?>">My History</a>
        </div>
    <?php endif; ?>
</div>
