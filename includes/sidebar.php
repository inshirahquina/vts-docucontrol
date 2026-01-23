<?php
// Ensure session is active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine current filename.
// We use isset() to prevent "Variable already defined" errors if this file is included 
// after $current_page was defined in the main page.
if (!isset($current_page)) {
    $current_page = basename($_SERVER['PHP_SELF']);
}
?>

<?php 
// --- 1. ADMIN SIDEBAR ---
if (isset($_SESSION['active_role']) && $_SESSION['active_role'] == 'admin'): 
?>
<div class="sidebar">
    <a href="../index.php" class="sidebar-brand">VTS Admin</a>
    <div class="sidebar-menu">
        <a href="../admin/dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
        <a href="../admin/requests.php" class="<?= ($current_page == 'requests.php') ? 'active' : ''; ?>">Manage Requests</a>
        <a href="../admin/reports.php" class="<?= ($current_page == 'reports.php') ? 'active' : ''; ?>">Audit Logs</a>
    </div>
</div>

<?php 
// --- 2. OPERATIONS / STAFF SIDEBAR ---
elseif (isset($_SESSION['active_role']) && $_SESSION['active_role'] == 'operations'): 
?>
<div class="sidebar">
    <a href="../index.php" class="sidebar-brand">VTS Operations</a>
    <div class="sidebar-menu">
        <a href="../staff/dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
        <a href="../staff/tasks.php" class="<?= ($current_page == 'tasks.php') ? 'active' : ''; ?>">My Tasks</a>
        <a href="../admin/reports.php" class="<?= ($current_page == 'reports.php') ? 'active' : ''; ?>">History</a>
    </div>
</div>

<?php 
// --- 3. REQUESTOR SIDEBAR ---
else: 
// Covers 'requestor' role or any fallback
?>
<div class="sidebar">
    <a href="../index.php" class="sidebar-brand">VTS Requestor</a>
    
    <div class="sidebar-menu">
        <a href="../requestor/dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            Dashboard
        </a>
        <a href="../requestor/browse.php" class="<?= ($current_page == 'browse.php') ? 'active' : ''; ?>">
            Browse Files
        </a>
        <a href="../requestor/my_files.php" class="<?= ($current_page == 'my_files.php') ? 'active' : ''; ?>">
            My History
        </a>
    </div>
</div>

<?php endif; ?>