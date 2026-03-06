<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if(!isLoggedIn() || ($_SESSION['active_role'] ?? '') !== 'hod'){
    redirect(BASE_URL . 'index.php');
}

$current_user_id = $_SESSION['user_id'];
$searchTerm = $_GET['search'] ?? '';

// --- MAIN QUERY ---
$sql = "
SELECT 
    h.id AS history_id,
    r.id AS request_id,
    f.file_name,
    f.department,
    f.allocation,
    r.released_at,
    r.extension_count,
    u.full_name AS requester_name,

    CASE 
        WHEN h.action LIKE 'Extension%' THEN (
            SELECT h2.created_at
            FROM file_history h2
            WHERE h2.request_id = r.id
            AND h2.action LIKE 'Extension Request%'
            AND h2.created_at <= h.created_at
            ORDER BY h2.created_at DESC
            LIMIT 1
        )
        ELSE r.created_at
    END AS requested_at,

    (
        SELECT h2.action
        FROM file_history h2
        WHERE h2.request_id = r.id
        AND h2.action LIKE 'Extension Request%'
        AND h2.created_at <= h.created_at
        ORDER BY h2.created_at DESC
        LIMIT 1
    ) AS extension_label,

    h.action AS hod_action,
    h.created_at AS hod_timestamp

FROM file_history h
JOIN requests r ON h.request_id = r.id
JOIN files f ON r.file_id = f.id
JOIN users u ON r.user_id = u.id

WHERE h.performed_role = 'hod'
AND h.action IN (
    'Approved by HOD',
    'Rejected by HOD',
    'Extension Approved',
    'Extension Rejected'
)
AND u.hod_id = :hod_id
";

$params = [':hod_id' => $current_user_id];

// --- SEARCH ---
if($searchTerm) {
    $sql .= " AND (f.file_name LIKE :search1 OR u.full_name LIKE :search2)";
    $params[':search1'] = "%$searchTerm%";
    $params[':search2'] = "%$searchTerm%";
}

$sql .= " ORDER BY h.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="layout-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="content-area">
            <h2>HOD Approval History</h2>

            <!-- Search Bar -->
            <div class="card filter-card">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search by file or requester..." value="<?= htmlspecialchars($searchTerm) ?>" style="padding:8px; width:250px; border-radius:5px; border:1px solid #ccc;">
                    <button type="submit" style="padding:8px 12px; border:none; background:#2563eb; color:white; border-radius:5px; cursor:pointer;">Search</button>
                </form>
            </div>

            <?php if(empty($history)): ?>
                <div class="card">You have not approved or rejected any requests yet.</div>
            <?php else: ?>
                <div class="card">
                    <table class="modern-table">
                        <thead>
                            <tr>
                               <th>Date Requested</th>
                                <th>File</th>
                                <th>Department / Project</th>
                                <th>Requester</th>
                                <th>Due Date</th>
                                <th>Type</th>
                                <th>Action by HOD</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($history as $h): 
                                $releasedAt = $h['released_at'] ?? null;
                                $dueDate = null;

                                if ($releasedAt) {

                                    $daysAllowed = 3;

                                    if (!empty($h['extension_label']) && preg_match('/#(\d+)/', $h['extension_label'], $m)) {
                                        $extNumber = (int)$m[1];
                                        $daysAllowed += ($extNumber * 3);
                                    }

                                    $due = calculateDueDate($pdo, $releasedAt, $daysAllowed);
                                    $dueDate = date('d M Y', strtotime($due));
                                }
                                if (!empty($h['extension_label'])) {
                                    $typeLabel = $h['extension_label'];
                                } else {
                                    $typeLabel = "New Request";
                                }

                                // HOD action color
                                if(!$h['hod_action']) {
                                    $actionDisplay = '<span style="color:gray;">Pending</span>';
                                } elseif(strpos($h['hod_action'],'Approved') !== false) {
                                    $actionDisplay = '<span style="color:green;">'.$h['hod_action'].'</span>';
                                } elseif(strpos($h['hod_action'],'Rejected') !== false) {
                                    $actionDisplay = '<span style="color:red;">'.$h['hod_action'].'</span>';
                                } else {
                                    $actionDisplay = '<span>'.$h['hod_action'].'</span>';
                                }
                            ?>
                            <tr>
                                <td><?= date('d M Y H:i:s', strtotime($h['requested_at'])) ?></td>
                                <td>
                                    <strong><?= sanitize($h['file_name']) ?></strong>
                                </td>

                                <td>
                                    <?= sanitize($h['department']) ?><br>
                                    <small style="color:#6b7280;">
                                        <?= sanitize($h['allocation']) ?>
                                    </small>
                                </td>

                                <td><?= sanitize($h['requester_name']) ?></td>
                                <td>
                                    <?= $dueDate ?? '--' ?>
                                </td>

                                <td><?= $typeLabel ?></td>
                                <td><?= $actionDisplay ?></td>
                                <td><?= $h['hod_timestamp'] ? date('d M Y H:i:s', strtotime($h['hod_timestamp'])) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once '../includes/footer.php'; ?>