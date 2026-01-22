<?php
// Determine current page safely
 $current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar">
    <a href="../index.php" class="sidebar-brand">VTS Admin</a>
    <div class="sidebar-menu">
        <a href="dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            Dashboard
        </a>
        <a href="files.php" class="<?= ($current_page == 'files.php') ? 'active' : ''; ?>">
            Manage Files
        </a>
        <a href="approvals.php" class="<?= ($current_page == 'approvals.php') ? 'active' : ''; ?>">
            Borrow Requests
        </a>
        <a href="reports.php" class="<?= ($current_page == 'reports.php') ? 'active' : ''; ?>">
            Audit Logs
        </a>
    </div>
</div>