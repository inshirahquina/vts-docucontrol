<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VTS DocuControl</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<?php if(isLoggedIn()): ?>
<div class="main-content">
    <header>
        <div class="page-title">Dashboard</div>
        
        <div style="display: flex; align-items: center; gap: 25px;">
            
            <!-- NOTIFICATION BELL -->
            <?php 
            $notif_count = 0;
            $uid = $_SESSION['user_id'];
            
            // Count unread notifications
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$uid]);
            $notif_count = $stmt->fetchColumn();
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
                        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                        $stmt->execute([$uid]);
                        if($stmt->rowCount() > 0):
                            while($row = $stmt->fetch()):
                        ?>
                        <a href="<?= $row['link'] ?>" class="notif-item <?= $row['is_read'] ? '' : 'unread' ?>">
                            <div class="notif-text"><?= sanitize($row['message']) ?></div>
                            <div class="notif-time" style="font-size:0.75rem; color:#94a3b8;"><?= $row['created_at'] ?></div>
                        </a>
                        <?php endwhile; 
                        else: ?>
                            <div class="notif-item" style="justify-content:center; color:#94a3b8;">No new notifications</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- User Profile -->
            <div class="user-profile">
                <div class="user-info">
                    <strong><?= sanitize($_SESSION['full_name']) ?></strong>
                    <span><?= ucfirst($_SESSION['role']) ?></span>
                </div>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>
<?php endif; ?>