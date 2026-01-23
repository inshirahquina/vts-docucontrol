<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../includes/header.php'; 

// --- DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. DEFINE $current_page IMMEDIATELY
 $current_page = basename($_SERVER['PHP_SELF']);

// 2. Get User ID
 $uid = $_SESSION['user_id'];

// 3. Debug Info
echo "<div style='background:#fff3cd; color:#856404; padding:10px; margin:10px; border:1px solid #ffeeba;'>";
echo "<strong>Debug Info:</strong><br>";
echo "Session User ID: " . $uid . "<br>";
echo "Current Page: " . $current_page . "<br>";
echo "</div>";

// Include Universal Sidebar
require_once '../includes/sidebar.php'; 

echo "<div style='background:#d1e7dd; color:#0f5132; padding:5px; margin:10px;'>Sidebar Loaded Successfully.</div>";
?>

<!-- 
   IMPORTANT: We do NOT open <div class="layout-wrapper"> or <div class="main-content"> here. 
   They are already opened in header.php.
-->

<header>
    <div class="page-title">My Files</div>
</header>

<div class="content-area">
    <div class="card" style="padding: 0; overflow: hidden; border-radius: 8px; border: 1px solid #eee;">
        <div style="padding: 20px 25px; border-bottom: 1px solid #eee; background: #f9fafb;">
            <h3 style="margin:0; font-size: 1.1rem; color: #333;">Request History</h3>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; min-width: 800px;">
            <thead>
                <tr style="background: #f8f9fa; text-align: left;">
                    <th style="padding: 15px 25px; font-weight: 600; color: #6b7280; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">File Details</th>
                    <th style="padding: 15px 25px; font-weight: 600; color: #6b7280; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">Dates</th>
                    <th style="padding: 15px 25px; font-weight: 600; color: #6b7280; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">Status</th>
                    <th style="padding: 15px 25px; font-weight: 600; color: #6b7280; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                echo "<div style='background:#cfe2ff; color:#084298; padding:5px; margin:10px;'>Running SQL Query...</div>";

                try {
                    $sql = "SELECT r.*, f.file_name, f.barcode, f.department 
                            FROM requests r 
                            JOIN files f ON r.file_id = f.id 
                            WHERE r.user_id = ? 
                            ORDER BY r.borrow_date DESC";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$uid]); 
                    
                    echo "<div style='background:#d1e7dd; color:#0f5132; padding:5px; margin:10px;'>SQL Success. Rows found: " . $stmt->rowCount() . "</div>";

                } catch (PDOException $e) {
                    echo "<div style='background:#f8d7da; color:#721c24; padding:10px; margin:10px; border:1px solid #f5c6cb;'>";
                    echo "<strong>SQL Error:</strong> " . $e->getMessage();
                    echo "</div>";
                    $stmt = null; 
                }

                if($stmt && $stmt->rowCount() > 0):
                    while($row = $stmt->fetch()):
                        
                        // --- STATUS MAPPING ---
                        if ($row['current_status'] == 'Requested') {
                            $displayStatus = 'Pending Approval';
                            $badgeColor = '#fef3c7'; 
                            $textColor = '#d97706';
                            $icon = '&#9203;';
                            $actionBtn = false;
                        } elseif(in_array($row['current_status'], ['Retrieval Assigned', 'File Retrieved'])) {
                            $displayStatus = 'Processing';
                            $badgeColor = '#e0f2fe'; 
                            $textColor = '#0369a1';
                            $icon = '&#128259;';
                            $actionBtn = false;
                        } elseif($row['current_status'] == 'Released') {
                            $displayStatus = 'With You';
                            $badgeColor = '#dcfce7'; 
                            $textColor = '#15803d';
                            $icon = '&#128190;';
                            $actionBtn = true;
                        } elseif(in_array($row['current_status'], ['Return Requested', 'Restoration Assigned', 'File Restored', 'Completed'])) {
                            $displayStatus = 'Returning';
                            $badgeColor = '#f3e8ff'; 
                            $textColor = '#7e22ce';
                            $icon = '&#10162;';
                            $actionBtn = false;
                        } else {
                            $displayStatus = 'Unknown';
                            $badgeColor = '#f3f4f6';
                            $textColor = '#6b7280';
                            $icon = '&#10067;';
                            $actionBtn = false;
                        }
                ?>
                <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.2s;">
                    <td style="padding: 20px 25px; vertical-align: top;">
                        <div style="font-weight: 700; font-size: 1rem; color: #111827; margin-bottom: 4px;">
                            <?= sanitize($row['file_name']) ?>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                            <span style="font-family: monospace; font-size: 0.75rem; background: #f3f4f6; color: #4b5563; padding: 2px 6px; border-radius: 4px;">
                                <?= sanitize($row['barcode']) ?>
                            </span>
                            <span style="font-size: 0.8rem; color: #9ca3af;">•</span>
                            <span style="font-size: 0.8rem; color: #6b7280;">
                                <?= sanitize($row['department']) ?>
                            </span>
                        </div>
                    </td>
                    <td style="padding: 20px 25px; vertical-align: top;">
                        <div style="font-size: 0.9rem; color: #374151; margin-bottom: 4px;">
                            <span style="color: #9ca3af; font-size: 0.8rem;">Requested:</span> <?= format_date($row['borrow_date']) ?>
                        </div>
                        <?php if($row['due_date']): ?>
                        <div style="font-size: 0.85rem; color: #6b7280;">
                            <span style="color: #9ca3af;">Due:</span> <?= format_date($row['due_date']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 20px 25px; vertical-align: top;">
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; background: <?= $badgeColor ?>; color: <?= $textColor ?>;">
                            <?= $icon ?> <?= $displayStatus ?>
                        </span>
                    </td>
                    <td style="padding: 20px 25px; vertical-align: top; text-align: right;">
                        <?php if($actionBtn): ?>
                            <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Are you sure you want to return this file?');">
                                <input type="hidden" name="action" value="request_return">
                                <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                <button type="submit" style="background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; cursor: pointer; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2); transition: background 0.2s; font-weight: 500;">
                                    Return File
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="color: #d1d5db; font-size: 0.85rem; font-style: italic; font-weight: 500;">No Action</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="padding: 60px; text-align: center; color: #9ca3af; background: #f9fafb;">
                            <div style="font-size: 3rem; margin-bottom: 10px;">&#128193;</div>
                            <p style="margin:0; font-size: 1rem;">No files requested yet.</p>
                            <a href="browse.php" style="display:inline-block; margin-top:15px; color:var(--primary); text-decoration:none; font-weight:600;">Start Browsing</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>