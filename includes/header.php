<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VTS DocuControl</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Role Switcher & Notifications */
        .role-switcher {
            position: relative;
            display: inline-block;
        }
        
        .role-btn {
            background: #f1f5f9;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e2e8f0;
        }

        .role-btn:hover {
            background: #e2e8f0;
        }

        .role-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 8px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            width: 180px;
            z-index: 100;
            overflow: hidden;
        }

        .role-dropdown.show {
            display: block;
        }

        .role-option {
            padding: 10px 15px;
            display: block;
            text-decoration: none;
            color: #334155;
            font-size: 0.9rem;
            cursor: pointer;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
        }

        .role-option:hover {
            background: #f8fafc;
            color: var(--accent);
        }

        .role-option.active {
            background: #f0f9ff;
            color: var(--accent);
            font-weight: bold;
        }

        .header-right-group {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        /* Toast Notification Styles */
        #toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast-msg {
            background: #333;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            min-width: 250px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease-out forwards, fadeOut 0.5s ease-in 4.5s forwards;
            font-size: 0.9rem;
            border-left: 5px solid var(--accent);
        }

        .toast-content {
            display: flex;
            flex-direction: column;
        }
        .toast-title {
            font-weight: bold;
            margin-bottom: 2px;
        }
        .toast-body {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    </style>
</head>
<body>
<?php 
// FIX 1: Ensure Database Connection is available
require_once '../config/db.php'; 

// FIX 2: Wrap session_start to prevent "Headers already sent" error
// This check ensures that if header.php is included by other files (or called twice), it won't crash.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(isLoggedIn()): ?>
    
    <!-- Note: Sidebar is included in individual pages -->
    
    <div class="main-content">
        <header>
            <div class="page-title">Dashboard</div>
            
            <div class="header-right-group">
                
                <!-- ROLE SWITCHER (Shows if Base Role is Admin or Staff) -->
                <?php if(isset($_SESSION['role']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'staff')): ?>
                <div class="role-switcher">
                    <div class="role-btn" onclick="this.nextElementSibling.classList.toggle('show')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        Acting as: <span style="text-transform:capitalize; color:var(--accent);"><?= $_SESSION['active_role'] ?></span>
                    </div>
                    <div class="role-dropdown">
                        <form method="POST" action="../actions/switch_role.php">
                            <button type="submit" name="new_role" value="admin" class="role-option <?= $_SESSION['active_role'] == 'admin' ? 'active' : '' ?>">
                                Admin Mode
                            </button>
                            <button type="submit" name="new_role" value="operations" class="role-option <?= $_SESSION['active_role'] == 'operations' ? 'active' : '' ?>">
                                Operations Mode
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- NOTIFICATION BELL -->
                <?php 
                $notif_count = 0;
                $uid = $_SESSION['user_id'];
                $activeRole = $_SESSION['active_role'];

                // Helper function for "Time Ago" (Fixed for PHP 8.2+)
                function time_ago($datetime, $full = false) {
                    $now = new DateTime();
                    $ago = new DateTime($datetime);
                    $diff = $now->diff($ago);

                    // FIX: Calculate weeks manually to avoid PHP 8.2 Deprecation Error
                    $weeks = floor($diff->d / 7);
                    $diff->d = $diff->d % 7; 

                    $string = array(
                        'y' => 'year', 'm' => 'month',
                        'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second',
                    );
                    
                    if ($weeks > 0) {
                        $string['w'] = 'week';
                        $diff->d = $weeks; // Update object for display if needed
                    } else {
                        unset($string['w']);
                    }

                    foreach ($string as $k => &$v) {
                        if ($diff->$k) {
                            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
                        } else {
                            unset($string[$k]);
                        }
                    }

                    if (!$full) $string = array_slice($string, 0, 1);
                    return $string ? implode(', ', $string) . ' ago' : 'just now';
                }
                
                // Only run query if we have a valid ID
                if($uid):
                    try {
                        // Fetch count of unread notifications for the current active role
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                        $stmt->execute([$uid]);
                        $notif_count = $stmt->fetchColumn();
                    } catch (Exception $e) {
                        $notif_count = 0; // Fail silently
                    }
                endif;
                ?>
                
                <div class="notification-icon" onclick="document.getElementById('notifDropdown').classList.toggle('show');">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    <?php if($notif_count > 0): ?>
                    <span class="badge-count"><?= $notif_count ?></span>
                    <?php endif; ?>
                    
                    <!-- Dropdown Content -->
                    <div id="notifDropdown" class="notif-dropdown">
                        <div class="notif-header">
                            <span>Notifications</span>
                            <a href="../actions/mark_read.php" style="font-size:0.8rem; color:var(--accent); text-decoration:none;">Mark all read</a>
                        </div>
                        <div class="notif-list">
                            <?php
                            if($uid):
                                try {
                                    // Fetch recent notifications
                                    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                                    $stmt->execute([$uid]);
                                    if($stmt->rowCount() > 0):
                                        while($row = $stmt->fetch()): ?>
                                        <a href="<?= $row['link'] ?>" class="notif-item <?= $row['is_read'] ? '' : 'unread' ?>">
                                            <div class="notif-text"><?= sanitize($row['message']) ?></div>
                                            <div class="notif-time" style="font-size:0.75rem; color:#94a3b8;"><?= time_ago($row['created_at']) ?></div>
                                        </a>
                                    <?php endwhile; 
                                    else: ?>
                                        <div class="notif-item" style="justify-content:center; color:#94a3b8;">No new notifications</div>
                                    <?php endif; 
                                } catch (Exception $e) {
                                    echo "<div class='notif-item' style='justify-content:center; color:#94a3b8;'>Notifications unavailable</div>";
                                }
                            endif; ?>
                        </div>
                    </div>
                </div>

                <!-- User Profile -->
                <div class="user-profile">
                    <div class="user-info">
                        <strong><?= sanitize($_SESSION['full_name']) ?></strong>
                        <span><?= ucfirst($activeRole) ?></span>
                    </div>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>

        <div id="toast-container"></div>

        <!-- <script>
            // Function to show popup notification
            function showToast(title, message, link) {
                const container = document.getElementById('toast-container');
                const toast = document.createElement('div');
                toast.className = 'toast-msg';
                
                toast.innerHTML = `
                    <div class="toast-content">
                        <div class="toast-title">${title}</div>
                        <div class="toast-body">${message}</div>
                    </div>
                    <a href="${link}" style="color: white; font-size: 1.2rem; text-decoration:none;">&rarr;</a>
                `;

                container.appendChild(toast);

                // Remove from DOM after animation finishes (5 seconds total)
                setTimeout(function() {
                    toast.remove();
                }, 5000);
            }

            let lastNotificationId = 0;

            setInterval(function() {
                // Send last ID we have seen to the server
                // FIX: Removed cache headers to ensure clean fetch
                // Also added ?last_id= at the start to ensure consistency
                fetch('../actions/check_new_notif.php?last_id=' + lastNotificationId) 
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        // DEBUG: Check console (F12) to see what's server is returning
                        console.log('Debug Data:', data); 

                        if (data.has_new) {
                            // Update badge count
                            const badge = document.querySelector('.badge-count');
                            if (badge) {
                                const currentCount = parseInt(badge.innerText);
                                badge.innerText = currentCount + 1;
                                badge.style.display = 'inline-block';
                            }
                            // Show popup
                            showToast("New Notification", data.message, data.link);
                            
                            // Update local variable so we don't show it again
                            lastNotificationId = data.id;
                        }
                    })
                    .catch(function(err) { console.log(err); });
            }, 5000); 
        </script> -->

<?php else: ?>
    <script>window.location.href='../index.php';</script>
<?php endif; ?>