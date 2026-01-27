<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once 'notify.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$activeRole = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'guest';
$currentAction = $_POST['action'] ?? '';

$allowedRequestorActions = ['create_request', 'cancel_request', 'request_return'];
$allowedStaffActions = ['confirm_retrieval', 'confirm_restoration'];
$allowedAdminActions = [
    'assign_retrieval',
    'release_file',
    'assign_restoration',
    'complete_transaction'
];

if (
    ($activeRole === 'requestor' && !in_array($currentAction, $allowedRequestorActions)) ||
    (in_array($activeRole, ['staff','operations']) && !in_array($currentAction, $allowedStaffActions)) ||
    ($activeRole === 'admin' && !in_array($currentAction, $allowedAdminActions))
) {
    header("Location: /vts_library/index.php");
    exit;
}

$requestId = $_POST['request_id'] ?? null;
$fileId = $_POST['file_id'] ?? null;
$staffId = $_POST['assigned_staff_id'] ?? null;
$remarks = $_POST['remarks'] ?? '';

$req = null;
if ($requestId) {
    $stmt = $pdo->prepare("
        SELECT r.*, f.file_name, f.file_number, f.department
        FROM requests r
        JOIN files f ON r.file_id = f.id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) {
        exit;
    }
}

if (!$req && $currentAction !== 'create_request') {
    exit;
}

function logHistory($pdo, $requestId, $fileId, $action, $by) {
    $stmt = $pdo->prepare("
        INSERT INTO file_history (request_id, file_id, action, performed_by, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$requestId, $fileId, $action, $by]);
}

if ($activeRole === 'requestor') {

    if ($currentAction === 'create_request' && $fileId) {
        $stmt = $pdo->prepare("
            INSERT INTO requests (file_id, user_id, borrow_date, due_date, current_status)
            VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Requested')
        ");
        $stmt->execute([$fileId, $_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();

        $pdo->prepare("UPDATE files SET status='borrowed' WHERE id=?")->execute([$fileId]);
        logHistory($pdo, $newId, $fileId, 'Requested', 'Requestor '.$_SESSION['user_id']);

        $admins = $pdo->query("SELECT id FROM users WHERE role='admin'");
        while ($a = $admins->fetch()) {
            sendNotification($pdo, $a['id'], "New file request submitted", "/vts_library/admin/requests.php");
        }

        header("Location: /vts_library/requestor/my_files.php");
        exit;
    }

    if ($currentAction === 'cancel_request' && $req['current_status'] === 'Requested') {
        $pdo->prepare("UPDATE requests SET current_status='Cancelled' WHERE id=?")->execute([$requestId]);
        $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
        logHistory($pdo, $requestId, $req['file_id'], 'Cancelled', 'Requestor '.$_SESSION['user_id']);

        header("Location: /vts_library/requestor/my_files.php");
        exit;
    }

    if ($currentAction === 'request_return' && $req['current_status'] === 'Released') {
        $pdo->prepare("UPDATE requests SET current_status='Return Requested', return_date=CURDATE() WHERE id=?")->execute([$requestId]);

        logHistory($pdo, $requestId, $req['file_id'], 'Return Requested', 'Requestor');

        $admins = $pdo->query("SELECT id FROM users WHERE role='admin'");
        while ($a = $admins->fetch()) {
            sendNotification($pdo, $a['id'], "Return requested", "/vts_library/admin/requests.php");
        }

        header("Location: /vts_library/requestor/my_files.php");
        exit;
    }
}

if (in_array($activeRole, ['staff','operations'])) {

    if ($currentAction === 'confirm_retrieval' && $req['current_status'] === 'Retrieval Assigned') {
        $assigned = strtotime($req['retrieval_assigned_at']);
        $mins = round((time() - $assigned) / 60);
        $breach = $mins > 20 ? 1 : 0;

        $pdo->prepare("UPDATE requests SET current_status='File Retrieved', retrieved_at=NOW(), sla_breach=? WHERE id=?")->execute([$breach, $requestId]);

        logHistory($pdo, $requestId, $req['file_id'], 'File Retrieved', 'Staff');

        header("Location: /vts_library/admin/requests.php");
        exit;
    }

    if ($currentAction === 'confirm_restoration' && $req['current_status'] === 'Restoration Assigned') {
        $pdo->prepare("UPDATE requests SET current_status='File Restored' WHERE id=?")->execute([$requestId]);
        $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
        logHistory($pdo, $requestId, $req['file_id'], 'File Restored', 'Staff');

        sendNotification($pdo, $req['user_id'], "Your file has been restored", "/vts_library/requestor/my_files.php");

        header("Location: /vts_library/admin/requests.php");
        exit;
    }
}

if ($activeRole === 'admin') {

    if ($currentAction === 'assign_retrieval' && $req['current_status'] === 'Requested') {
        $pdo->prepare("UPDATE requests SET current_status='Retrieval Assigned', assigned_to=?, retrieval_assigned_at=NOW() WHERE id=?")->execute([$staffId, $requestId]);

        logHistory($pdo, $requestId, $req['file_id'], 'Retrieval Assigned', 'Admin');

        sendNotification($pdo, $req['user_id'], "Request approved", "/vts_library/requestor/my_files.php");
        sendNotification($pdo, $staffId, "Retrieve assigned file", "/vts_library/staff/tasks.php");

        header("Location: /vts_library/admin/requests.php");
        exit;
    }

    if ($currentAction === 'release_file' && $req['current_status'] === 'File Retrieved') {
        $pdo->prepare("UPDATE requests SET current_status='Released' WHERE id=?")->execute([$requestId]);

        logHistory($pdo, $requestId, $req['file_id'], 'Released', 'Admin');

        sendNotification($pdo, $req['user_id'], "File ready for pickup", "/vts_library/requestor/my_files.php");

        header("Location: /vts_library/admin/requests.php");
        exit;
    }

    if ($currentAction === 'assign_restoration' && $req['current_status'] === 'Return Requested') {
        $pdo->prepare("UPDATE requests SET current_status='Restoration Assigned', assigned_to=?, retrieval_assigned_at=NOW() WHERE id=?")->execute([$staffId, $requestId]);

        logHistory($pdo, $requestId, $req['file_id'], 'Restoration Assigned', 'Admin');

        sendNotification($pdo, $req['user_id'], "Return processing", "/vts_library/requestor/my_files.php");
        sendNotification($pdo, $staffId, "Restore assigned file", "/vts_library/staff/tasks.php");

        header("Location: /vts_library/admin/requests.php");
        exit;
    }

    if ($currentAction === 'complete_transaction' && $req['current_status'] === 'File Restored') {
        $pdo->prepare("UPDATE requests SET current_status='Completed' WHERE id=?")->execute([$requestId]);

        logHistory($pdo, $requestId, $req['file_id'], 'Completed', 'Admin');

        sendNotification($pdo, $req['user_id'], "Transaction completed", "/vts_library/requestor/my_files.php");

        header("Location: /vts_library/admin/requests.php");
        exit;
    }
}
