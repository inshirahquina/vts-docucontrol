<?php
// Get the current filename to highlight the active menu item
 $current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar">
    <!-- Branding -->
    <a href="../index.php" class="sidebar-brand">VTS Staff</a>
    
    <!-- Navigation -->
    <div class="sidebar-menu">
        <a href="dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            Dashboard
        </a>
        <a href="browse.php" class="<?= ($current_page == 'browse.php') ? 'active' : ''; ?>">
            Browse Files
        </a>
        <a href="my_files.php" class="<?= ($current_page == 'my_files.php') ? 'active' : ''; ?>">
            My History
        </a>
    </div>
</div>