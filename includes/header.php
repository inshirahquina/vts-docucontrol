<?php
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';

if(!isLoggedIn()) {
    redirect(BASE_URL . 'index.php');
}

// Helper variable for display
 $currentUserName = sanitize($_SESSION['full_name'] ?? 'User');
 $activeRole = $_SESSION['active_role'] ?? 'Guest';
 $baseRole = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VTS DocuControl</title>
    
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">

    <style>
        /* --- HEADER LAYOUT --- */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 40px;
            height: 70px;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .page-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            text-decoration: none;
            cursor: pointer;
        }

        .page-title:hover {
            color: var(--accent);
        }

        .profile-menu {
            position: relative;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 6px 12px 6px 6px;
            border-radius: 50px;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .profile-btn:hover {
            background: #f8fafc;
            border-color: #e2e8f0;
        }

        /* Avatar Circle */
        .profile-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        /* User Text Info */
        .profile-info {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.2;
        }

        .profile-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #334155;
        }

        .profile-role-badge {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
            margin-top: 2px;
        }

        /* Dropdown Arrow */
        .profile-arrow {
            margin-left: 4px;
            color: #94a3b8;
            transition: transform 0.2s ease;
        }

        .profile-menu.active .profile-arrow {
            transform: rotate(180deg);
        }

        /* --- DROPDOWN MENU --- */
        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 240px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            overflow: hidden;
        }

        .profile-menu.active .profile-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-header {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f5f9;
            background: #f8fafc;
        }
        .dropdown-header strong { display: block; color: #1e293b; }
        .dropdown-header small { color: #64748b; font-size: 0.8rem; }

        /* Menu Items */
        .dropdown-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: #475569;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.15s;
            width: 100%;
            border: none;
            background: none;
            cursor: pointer;
            text-align: left;
        }

        .dropdown-menu-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .dropdown-menu-item svg {
            width: 18px;
            height: 18px;
            color: #94a3b8;
        }

        /* Section Dividers */
        .dropdown-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 5px 0;
        }

        /* Special Logout Style */
        .dropdown-menu-item.logout:hover {
            background: #fef2f2;
            color: #dc2626;
        }
        .dropdown-menu-item.logout:hover svg {
            color: #dc2626;
        }

        /* Active Role Switch Label */
        .role-active-label {
            color: var(--accent) !important;
            font-weight: 600;
        }
        .role-active-label svg {
            color: var(--accent) !important;
        }
    </style>
</head>
<body>

<div class="main-content">
    <header>
        <a href="<?= BASE_URL . $activeRole ?>/dashboard.php" class="page-title">
            Dashboard
        </a>
        
        <div class="profile-menu" id="profileMenu">
            <div class="profile-btn" onclick="toggleMenu()">
                <div class="profile-avatar">
                    <?= substr($currentUserName, 0, 1) ?>
                </div>
                <div class="profile-info">
                    <span class="profile-name"><?= $currentUserName ?></span>
                    <span class="profile-role-badge"><?= ucfirst($activeRole) ?></span>
                </div>
                <div class="profile-arrow">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                </div>
            </div>

            <div class="profile-dropdown">
                <!-- User Info Summary -->
                <div class="dropdown-header">
                    <small>Active Role: <?= ucfirst($activeRole) ?></small>
                </div>

                <?php 
                $showSwitcher = false;
                $allowedSwitches = [];

                if ($baseRole == 'admin') {
                    $allowedSwitches = ['admin' => 'Admin Mode', 'operations' => 'Operations Mode'];
                    $showSwitcher = true;
                } elseif ($baseRole == 'operations') {
                    $allowedSwitches = ['operations' => 'Operations Mode', 'admin' => 'Admin Mode'];
                    $showSwitcher = true;
                } elseif ($baseRole == 'hod') {
                    $allowedSwitches = ['hod' => 'HOD Mode', 'requestor' => 'Requestor Mode'];
                    $showSwitcher = true;
                }
                ?>

                <?php if ($showSwitcher): ?>
                    <div style="padding: 5px 0;">
                        <form method="POST" action="<?= BASE_URL ?>actions/switch_role.php">
                            <?php foreach ($allowedSwitches as $roleVal => $roleLabel): ?>
                                <button type="submit" name="new_role" value="<?= $roleVal ?>" 
                                    class="dropdown-menu-item <?= ($activeRole == $roleVal) ? 'role-active-label' : '' ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <?php if ($activeRole == $roleVal): ?>
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>
                                        <?php else: ?>
                                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                        <?php endif; ?>
                                    </svg>
                                    <?= $roleLabel ?>
                                </button>
                            <?php endforeach; ?>
                        </form>
                    </div>
                    <div class="dropdown-divider"></div>
                <?php endif; ?>

                <div style="padding: 5px 0;">
                    <a href="../change-credentials.php" class="dropdown-menu-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                        </svg>
                        Change Credentials
                    </a>
                </div>

                <div class="dropdown-divider"></div>

                <div style="padding: 5px 0;">
                    <a href="<?= BASE_URL ?>logout.php" class="dropdown-menu-item logout">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <script>
        // Toggle Dropdown
        function toggleMenu() {
            const menu = document.getElementById('profileMenu');
            menu.classList.toggle('active');
        }

        // Close dropdown when clicking outside
        window.addEventListener('click', function(e) {
            const menu = document.getElementById('profileMenu');
            if (!menu.contains(e.target)) {
                menu.classList.remove('active');
            }
        });
    </script>