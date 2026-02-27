<?php
require_once '../config/functions.php';

// 2. Load Database
require_once '../config/db.php';

if(!isLoggedIn()) {
    redirect(BASE_URL . 'index.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VTS DocuControl</title>
    
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">

    <style>
        .role-switcher { position: relative; display: inline-block; }
        .role-btn {
            background: #f1f5f9; padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;
            font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;
            border: 1px solid #e2e8f0;
        }
        .role-btn:hover { background: #e2e8f0; }
        .role-dropdown {
            display: none; position: absolute; right: 0; top: 100%; margin-top: 8px;
            background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0; width: 200px; z-index: 1000; overflow: hidden;
        }
        .role-dropdown.show { display: block; }
        .role-option {
            padding: 10px 15px; display: block; width: 100%; text-align: left;
            background: none; border: none; cursor: pointer; font-size: 0.9rem;
        }
        .role-option:hover { background: #f8fafc; color: var(--accent); }
        .role-option.active { background: #f0f9ff; color: var(--accent); font-weight: bold; }
    </style>
</head>
<body>

<div class="main-content">
    <header>
        <div class="page-title">Dashboard</div>
        
        <div class="header-right-group" style="display: flex; align-items: center; gap: 20px;">
            
            <!-- ROLE SWITCHER -->
            <?php if(isset($_SESSION['role'])): ?>
            <div class="role-switcher">
                <div class="role-btn" onclick="this.nextElementSibling.classList.toggle('show')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    Role: <span style="text-transform:capitalize; color:var(--accent);"><?= $_SESSION['active_role'] ?? 'User' ?></span>
                </div>
                <div class="role-dropdown">
                    <form method="POST" action="<?= BASE_URL ?>actions/switch_role.php">
                        
                        <!-- If Base Role is Admin -->
                        <?php if($_SESSION['role'] == 'admin'): ?>
                            <button type="submit" name="new_role" value="admin" class="role-option <?= ($_SESSION['active_role'] ?? '') == 'admin' ? 'active' : '' ?>">Admin Mode</button>
                            <button type="submit" name="new_role" value="operations" class="role-option <?= ($_SESSION['active_role'] ?? '') == 'operations' ? 'active' : '' ?>">Operations Mode</button>
                        
                        <!-- If Base Role is Operations -->
                        <?php elseif($_SESSION['role'] == 'operations'): ?>
                            <button type="submit" name="new_role" value="operations" class="role-option <?= ($_SESSION['active_role'] ?? '') == 'operations' ? 'active' : '' ?>">Operations Mode</button>
                            <button type="submit" name="new_role" value="admin" class="role-option <?= ($_SESSION['active_role'] ?? '') == 'admin' ? 'active' : '' ?>">Admin Mode</button>
                        
                        <!-- If Base Role is HOD -->
                        <?php elseif($_SESSION['role'] == 'hod'): ?>
                            <button type="submit" name="new_role" value="hod" class="role-option <?= ($_SESSION['active_role'] ?? '') == 'hod' ? 'active' : '' ?>">HOD Mode</button>
                            <button type="submit" name="new_role" value="requestor" class="role-option <?= ($_SESSION['active_role'] ?? '') == 'requestor' ? 'active' : '' ?>">Requestor Mode</button>
                        
                        <!-- Default Requestor -->
                        <?php else: ?>
                            <button type="submit" name="new_role" value="requestor" class="role-option active">Requestor Mode</button>
                        <?php endif; ?>

                    </form>
                </div>
            </div>
            <?php endif; ?>
            <!-- END ROLE SWITCHER -->

            <!-- User Profile -->
            <div class="user-profile">
                <div class="user-info">
                    <strong><?= sanitize($_SESSION['full_name'] ?? 'User') ?></strong>
                    <span><?= ucfirst($_SESSION['active_role'] ?? 'Guest') ?></span>
                </div>
                <a href="<?= BASE_URL ?>logout.php" class="logout-btn">Logout</a>
            </div>

        </div>
    </header>