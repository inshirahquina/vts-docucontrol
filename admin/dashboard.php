<?php
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';

// --- LOGIC TO HANDLE ROLE SWITCHING ---
if (isset($_SESSION['active_role']) && $_SESSION['active_role'] === 'operations') {
    header("Location: ../operations/dashboard.php");
    exit();
}

require_once '../includes/header.php'; 

// 1. TOTAL FILES
 $total_files = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();

// 2. OVERDUE FILES
 $overdue_files = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status = 'Released' AND due_date < CURDATE()")->fetchColumn();

// 3. PENDING ADMIN APPROVALS 
 $pending_approvals = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status IN ('Requested', 'Return Requested')")->fetchColumn();

// 4. PENDING RETURN 
 $pending_return = $pdo->query("SELECT COUNT(*) FROM requests WHERE current_status = 'Released'")->fetchColumn();

// 5. RECENT REQUESTS 
 $recent_req = $pdo->query("SELECT r.id, r.current_status, r.borrow_date, f.file_name, u.full_name 
                           FROM requests r 
                           JOIN files f ON r.file_id = f.id 
                           JOIN users u ON r.user_id = u.id 
                           WHERE r.current_status != 'Completed' AND r.current_status != 'Cancelled'
                           ORDER BY r.borrow_date DESC LIMIT 5");

$selected_month = $_GET['month'] ?? date('m');
$selected_year  = $_GET['year'] ?? date('Y');
$dept_summary = $pdo->prepare("
    SELECT
        u.department,
        COUNT(r.id) AS total_requests
    FROM requests r
    JOIN users u ON r.user_id = u.id
    WHERE MONTH(r.borrow_date) = ?
    AND YEAR(r.borrow_date) = ?
    GROUP BY u.department
    ORDER BY total_requests DESC
");
$dept_summary->execute([$selected_month, $selected_year]);
$total_request = $pdo->prepare("
    SELECT COUNT(*) 
    FROM requests
    WHERE MONTH(borrow_date) = ?
    AND YEAR(borrow_date) = ?
");
$total_request->execute([$selected_month, $selected_year]);
$total_requests = $total_request->fetchColumn();
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            
            <!-- Header -->
            <div style="margin-bottom: 25px;">
                <h2 style="margin:0;">Admin Dashboard</h2>
                <p style="color:var(--text-light); margin:5px 0 0 0;">Overview of repository status and activities.</p>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">
                
                <!-- Total Archive -->
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid var(--accent);">
                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Total Archive</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($total_files) ?></div>
                    <div style="font-size: 0.8rem; color: #888;">Documents in system</div>
                </div>
                
                <!-- Overdue Files -->
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #dc2626;">
                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Overdue Files</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($overdue_files) ?></div>
                    <div style="font-size: 0.8rem; color: #888;">Past due date</div>
                </div>

                <!-- Pending Approval -->
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #ef4444;">
                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Needs Action</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($pending_approvals) ?></div>
                    <div style="font-size: 0.8rem; color: #888;">Awaiting assignment</div>
                </div>

                <!-- Pending Return -->
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-left: 4px solid #8b5cf6;">
                    <div style="color: var(--text-light); font-size: 0.9rem; font-weight: 600; text-transform: uppercase;">Pending Return</div>
                    <div class="value" style="font-size: 2rem; font-weight: bold; color: var(--text-dark); margin: 5px 0;"><?= number_format($pending_return) ?></div>
                    <div style="font-size: 0.8rem; color: #888;">Files with requestors</div>
                </div>
            </div>
            <div class="card" style="margin-top:20px; background:white; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,.05); padding:20px;">

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;">Department Request Summary</h3>

                    <form method="GET" style="display:flex; align-items:center; gap:10px;">

                        <select
                            name="month"
                            onchange="this.form.submit()"
                            style="
                                padding:8px 14px;
                                border:1px solid #d1d5db;
                                border-radius:8px;
                                background:#fff;
                                color:#374151;
                                font-size:14px;
                                font-weight:500;
                                cursor:pointer;
                                outline:none;
                                min-width:140px;
                                box-shadow:0 1px 2px rgba(0,0,0,0.05);
                            ">
                            <?php
                            for($m=1;$m<=12;$m++){
                                $val = str_pad($m,2,"0",STR_PAD_LEFT);
                                $selected = ($selected_month==$val) ? "selected" : "";
                                echo "<option value='$val' $selected>".date('F', mktime(0,0,0,$m,1))."</option>";
                            }
                            ?>
                        </select>

                        <select
                            name="year"
                            onchange="this.form.submit()"
                            style="
                                padding:8px 14px;
                                border:1px solid #d1d5db;
                                border-radius:8px;
                                background:#fff;
                                color:#374151;
                                font-size:14px;
                                font-weight:500;
                                cursor:pointer;
                                outline:none;
                                min-width:90px;
                                box-shadow:0 1px 2px rgba(0,0,0,0.05);
                            ">
                            <?php
                            for($y=date('Y'); $y>=2024; $y--){
                                $selected = ($selected_year==$y) ? "selected" : "";
                                echo "<option value='$y' $selected>$y</option>";
                            }
                            ?>
                        </select>

                    </form>
                </div>

                <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
                <colgroup>
                    <col style="width:80%;">
                    <col style="width:20%;">
                </colgroup>
                    <thead>
                        <tr style="border-bottom:2px solid #eee;">
                            <th style="padding:10px; text-align:left;">Department</th>
                            <th style="padding:10px; text-align:center;">Total Request</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if($dept_summary->rowCount()>0): ?>

                        <?php while($row=$dept_summary->fetch()): ?>

                        <tr style="border-bottom:1px solid #f5f5f5;">
                        <td style="padding:12px;">
                                <?= sanitize($row['department']) ?>
                            </td>

                            <td style="padding:12px; text-align:center;">
                                <span style="
                                    display:inline-block;
                                    min-width:32px;
                                    background:#eef4ff;
                                    color:#2563eb;
                                    padding:5px 12px;
                                    border-radius:999px;
                                    font-weight:600;
                                ">
                                    <?= $row['total_requests'] ?>
                                </span>
                            </td>
                        </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="2" style="padding:25px; text-align:center; color:#999;">
                                No request found.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #ddd; background:#f9fafb;">
                            <td style="padding:14px; font-weight:700;">
                                Total Requests
                            </td>
                            <td style="padding:14px; text-align:center;">
                                <span style="
                                    display:inline-block;
                                    min-width:32px;
                                    background:#eef4ff;
                                    color:#2563eb;
                                    padding:5px 12px;
                                    border-radius:999px;
                                    font-weight:800;
                                ">
                                    <?= $total_requests ?>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Two Column Layout -->
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                
                <!-- Recent Requests (Left, Larger) -->
                <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <h3 style="margin:0; font-size: 1.1rem;">Recent Requests</h3>
                        <a href="requests.php" class="btn" style="padding: 6px 12px; font-size: 0.85rem;">View All &rarr;</a>
                    </div>
                    
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; color: #888; font-size: 0.85rem; border-bottom: 2px solid #f3f4f6;">
                                <th style="padding: 10px;">File Name</th>
                                <th style="padding: 10px;">Requester</th>
                                <th style="padding: 10px;">Status</th>
                                <th style="padding: 10px;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($recent_req->rowCount() > 0): 
                                while($row = $recent_req->fetch()): 
                                    // Simple Status Color Logic
                                    $st = $row['current_status'];
                                    $color = '#6b7280'; // Gray default
                                    if($st == 'Released') $color = '#4f46e5'; // Indigo
                                    elseif($st == 'Requested') $color = '#ea580c'; // Orange
                                    elseif($st == 'Completed') $color = '#16a34a'; // Green
                            ?>
                            <tr style="border-bottom: 1px solid #f9fafb;">
                                <td style="padding: 12px 10px; font-weight: 500;"><?= sanitize($row['file_name']) ?></td>
                                <td style="padding: 12px 10px; color: #555;"><?= sanitize($row['full_name']) ?></td>
                                <td style="padding: 12px 10px;">
                                    <span style="background: <?= $color ?>15; color: <?= $color ?>; padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">
                                        <?= $st ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 10px; color: #888; font-size: 0.9rem;"><?= date('M j', strtotime($row['borrow_date'])) ?></td>
                            </tr>
                            <?php endwhile; 
                            else: ?>
                            <tr><td colspan="4" style="text-align:center; padding: 30px; color:#999;">No active requests found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card" style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <h3 style="margin:0; font-size: 1.1rem;">Audit Trail</h3>
                        <a href="reports.php" style="font-size: 0.85rem; color: var(--accent); text-decoration: none;">View Log</a>
                    </div>
                    
                    <?php 
                    
                    $logs = $pdo->query("
                        SELECT fh.*, u.full_name, f.file_name, f.department
                        FROM file_history fh
                        LEFT JOIN users u ON fh.performed_by = u.id
                        LEFT JOIN files f ON fh.file_id = f.id
                        ORDER BY fh.created_at DESC
                        LIMIT 5
                    ");
                    ?>
                    
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php if($logs->rowCount() > 0): 
                            while($row = $logs->fetch()): 
                                // Optional: display user with role
                                $userWithRole = $row['full_name'] . " (" . $row['performed_role'] . ")";
                        ?>
                        <div style="display: flex; gap: 10px; align-items: flex-start;">
                            <div style="background: #eff6ff; color: var(--accent); width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0;">
                                <?= strtoupper(substr($row['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-size: 0.9rem; font-weight: 600;"><?= sanitize($row['action']) ?></div>
                                <div style="font-size: 0.8rem; color: #666; margin-top: 2px;">
                                    by <?= sanitize($userWithRole) ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #999; margin-top: 4px;">
                                    <?= date('M j, H:i', strtotime($row['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; 
                        else: ?>
                            <div style="text-align:center; padding: 20px; color:#999; font-size: 0.9rem;">No recent logs.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>