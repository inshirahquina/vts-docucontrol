<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. CORE
require_once '../config/db.php';
require_once '../config/functions.php';

// 2. AUTH CHECK
if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
}

// Security: Only requestors allowed
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'requestor') {
    redirect('../index.php');
}

 $current_page = basename($_SERVER['PHP_SELF']);
 $uid = $_SESSION['user_id'];

// --- Debug: check session & user_id ---
if (!$uid) {
    echo "<pre>Session user_id not found!</pre>";
    var_dump($_SESSION);
    exit;
}

// 3. FETCH DATA
 $sql = "
    SELECT r.*, f.file_name, f.barcode, f.department
    FROM requests r
    JOIN files f ON r.file_id = f.id
    WHERE r.user_id = ?
    ORDER BY r.borrow_date DESC
";

 $stmt = $pdo->prepare($sql);
 $stmt->execute([$uid]);
 $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. STATUS MAPPING
// FIX: Added 'Cancelled' to the array
 $statusMap = [
    'Requested' => ['Pending Approval','#fef3c7','#d97706','⏳'],
    'Retrieval Assigned' => ['Processing','#e0f2fe','#0369a1','🛠️'],
    'File Retrieved' => ['Processing','#e0f2fe','#0369a1','🛠️'],
    'Released' => ['With You','#dcfce7','#15803d','📂'],
    'Return Requested' => ['Returning','#f3e8ff','#7e22ce','↩️'],
    'Restoration Assigned' => ['Returning','#f3e8ff','#7e22ce','↩️'],
    'File Restored' => ['Returning','#f3e8ff','#7e22ce','↩️'],
    'Completed' => ['Completed','#d1fae5','#065f46','✅'],
    'Cancelled' => ['Cancelled', '#fee2e2', '#b91c1c', '🚫'] // <--- ADDED THIS LINE
];

// 5. HEADER (includes CSS/JS)
require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <!-- SIDEBAR -->
    <?php require_once '../includes/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="content-area">

            <header>
                <div class="page-title">My Files</div>
            </header>

            <div class="card" style="padding:0; overflow:hidden; border-radius:8px; border:1px solid #eee;">
                <div style="padding:20px 25px; border-bottom:1px solid #eee; background:#f9fafb;">
                    <h3 style="margin:0; font-size:1.1rem;">Request History</h3>
                </div>

                <table style="width:100%; border-collapse:collapse; min-width:800px;">
                    <thead>
                        <tr style="background:#f8f9fa;">
                            <th style="padding:15px 25px;">File</th>
                            <th style="padding:15px 25px;">Dates</th>
                            <th style="padding:15px 25px;">Status</th>
                            <th style="padding:15px 25px; text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $actionBtn = false;
                                $actionType = null; // 'return' or 'cancel'
                                $statusKey = $row['current_status'];

                                // SAFETY CHECK: If the DB value is NULL or empty (due to previous Enum issue), force it to 'Cancelled'
                                if (empty($statusKey)) {
                                    $statusKey = 'Cancelled';
                                }

                                // fallback if status unknown
                                if (!isset($statusMap[$statusKey])) {
                                    $statusLabel = 'Unknown';
                                    $bg = '#f3f4f6';
                                    $color = '#6b7280';
                                    $icon = '❓';
                                } else {
                                    [$statusLabel, $bg, $color, $icon] = $statusMap[$statusKey];

                                    // Logic for Action Buttons
                                    if ($statusKey === 'Released') {
                                        $actionBtn = true;
                                        $actionType = 'return';
                                    } elseif ($statusKey === 'Requested') {
                                        $actionBtn = true;
                                        $actionType = 'cancel';
                                    }
                                }
                                ?>
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:20px 25px;">
                                        <strong><?= sanitize($row['file_name']) ?></strong><br>
                                        <small><?= sanitize($row['barcode']) ?> • <?= sanitize($row['department']) ?></small>
                                    </td>
                                    <td style="padding:20px 25px;">
                                        <div>Requested: <?= format_date($row['borrow_date']) ?></div>
                                        <?php if ($row['due_date']): ?>
                                            <small>Due: <?= format_date($row['due_date']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:20px 25px;">
                                        <span style="background:<?= $bg ?>; color:<?= $color ?>; padding:6px 12px; border-radius:20px; font-weight:600;">
                                            <?= $icon ?> <?= $statusLabel ?>
                                        </span>
                                    </td>
                                    <td style="padding:20px 25px; text-align:right;">
                                        <?php if ($actionBtn): ?>
                                            <form method="POST" action="../actions/request_actions.php" onsubmit="return confirm('Are you sure?');">
                                                <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                                
                                                <?php if ($actionType === 'return'): ?>
                                                    <input type="hidden" name="action" value="request_return">
                                                    <button style="background:#ef4444; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer;">
                                                        Return
                                                    </button>
                                                <?php elseif ($actionType === 'cancel'): ?>
                                                    <input type="hidden" name="action" value="cancel_request">
                                                    <button style="background:#fff; color:#6b7280; border:1px solid #d1d5db; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:0.9rem;">
                                                        Cancel Request
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        <?php else: ?>
                                            <em style="color:#9ca3af;">No Action</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="padding:50px; text-align:center; color:#9ca3af;">
                                    No files requested yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>