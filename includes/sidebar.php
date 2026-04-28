<?php
require_once '../config/functions.php'; // IMPORTANT for BASE_URL

$current_page = basename($_SERVER['SCRIPT_NAME']);
$role = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'requestor';
?>

<div class="sidebar">

<?php if ($role === 'admin'): ?>

<a href="<?= BASE_URL ?>admin/dashboard.php" class="sidebar-brand">VTS Admin</a>

<div class="sidebar-menu">
<a href="<?= BASE_URL ?>admin/dashboard.php" class="<?= ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
<a href="<?= BASE_URL ?>admin/requests.php" class="<?= ($current_page === 'requests.php') ? 'active' : ''; ?>">Manage Requests</a>
<a href="<?= BASE_URL ?>admin/files.php" class="<?= ($current_page === 'files.php') ? 'active' : ''; ?>">Manage Files</a>
<a href="<?= BASE_URL ?>admin/reports.php" class="<?= ($current_page === 'reports.php') ? 'active' : ''; ?>">Audit Logs</a>
</div>

<?php elseif ($role === 'hod'): ?>

<a href="<?= BASE_URL ?>hod/dashboard.php" class="sidebar-brand">VTS Head Of Department</a>

<div class="sidebar-menu">
<a href="<?= BASE_URL ?>hod/dashboard.php" class="<?= ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
<a href="<?= BASE_URL ?>hod/approvals.php" class="<?= ($current_page === 'approvals.php') ? 'active' : ''; ?>">Pending Approvals</a>
<a href="<?= BASE_URL ?>hod/history.php" class="<?= ($current_page === 'history.php') ? 'active' : ''; ?>">Approval History</a>
</div>

<?php elseif ($role === 'operations'): ?>

<a href="<?= BASE_URL ?>operations/dashboard.php" class="sidebar-brand">VTS Operations</a>

<div class="sidebar-menu">
<a href="<?= BASE_URL ?>operations/dashboard.php" class="<?= ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
<a href="<?= BASE_URL ?>operations/history.php" class="<?= ($current_page === 'history.php') ? 'active' : ''; ?>">History</a>
</div>

<?php else: ?>

<a href="<?= BASE_URL ?>requestor/dashboard.php" class="sidebar-brand">VTS Requestor</a>

<div class="sidebar-menu">
<a href="<?= BASE_URL ?>requestor/dashboard.php" class="<?= ($current_page === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
<a href="<?= BASE_URL ?>requestor/browse.php" class="<?= ($current_page === 'browse.php') ? 'active' : ''; ?>">Browse Files</a>
<a href="<?= BASE_URL ?>requestor/my_files.php" class="<?= ($current_page === 'my_files.php') ? 'active' : ''; ?>">My Files</a>
<a href="<?= BASE_URL ?>requestor/department_files.php" class="<?= ($current_page === 'department_files.php') ? 'active' : ''; ?>">Department Files</a>
</div>

<?php endif; ?>

</div>
