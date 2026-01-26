<?php
require_once '../config/db.php';
require_once '../config/functions.php';
session_start();

// --- 1. ROLE & SECURITY ---
$activeRole = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'guest';

// Define allowed actions per role
$allowedRequestorActions = ['create_request', 'request_return'];
$allowedStaffActions   = ['confirm_retrieval', 'confirm_restoration'];
$allowedAdminActions   = ['assign_retrieval', 'assign_restoration', 'release_file', 'complete_transaction'];

$currentAction = $_POST['action'] ?? '';

// Kick out if user not allowed
if (!isAdmin() && !in_array($currentAction, $allowedRequestorActions) && !in_array($currentAction, $allowedStaffActions)) {
    header("Location: ../index.php");
    exit;
}

// --- 2. GET POST DATA ---
$requestId = $_POST['request_id'] ?? null;
$fileId    = $_POST['file_id'] ?? null;
$staffId   = $_POST['assigned_staff_id'] ?? null;
$remarks   = $_POST['remarks'] ?? '';

// --- 3. HELPER: GET REQUEST INFO ---
$req = null;
if ($requestId) {
    $stmt = $pdo->prepare("SELECT r.*, f.file_name, f.department FROM requests r JOIN files f ON r.file_id = f.id WHERE r.id = ?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) {
        die("Request not found.");
    }
}

// --- 4. HELPER: LOG HISTORY ---
function logHistory($pdo, $reqId, $fileId, $actionName, $performedBy) {
    $sql = "INSERT INTO file_history (file_id, action, performed_by, created_at) VALUES (?, ?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fileId, $actionName, $performedBy]);
}

// --- 5. ACTION HANDLERS ---

// --- REQUESTOR ---
if ($activeRole == 'requestor') {
    if ($currentAction == 'create_request' && $fileId) {
        $sql = "INSERT INTO requests (file_id, user_id, borrow_date, due_date, current_status) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Requested')";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$fileId, $_SESSION['user_id']])) {
            $newRequestId = $pdo->lastInsertId();
            $pdo->prepare("UPDATE files SET status='borrowed' WHERE id=?")->execute([$fileId]);
            logHistory($pdo, $newRequestId, $fileId, 'Requested', 'Requestor ID: ' . $_SESSION['user_id']);
            header("Location: ../requestor/my_files.php"); exit;
        }
    }
    if ($currentAction == 'request_return' && $req['current_status'] == 'Released') {
        $stmt = $pdo->prepare("UPDATE requests SET current_status='Return Requested', return_date=CURDATE() WHERE id=?");
        $stmt->execute([$requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Return Requested', 'Requestor');
        header("Location: ../requestor/my_files.php"); exit;
    }
}

// --- STAFF / OPERATIONS ---
if ($activeRole == 'operations') {
    if ($currentAction == 'confirm_retrieval' && $req['current_status'] == 'Retrieval Assigned') {
        $assignedTime = strtotime($req['retrieval_assigned_at']);
        $diffMins = round((time() - $assignedTime)/60);
        $isBreach = ($diffMins > 20) ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE requests SET current_status='File Retrieved', retrieved_at=NOW(), sla_breach=? WHERE id=?");
        $stmt->execute([$isBreach, $requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'File Retrieved', 'Operations Staff (Time: '.$diffMins.' mins)');
        header("Location: ../admin/requests.php"); exit;
    }

    if ($currentAction == 'confirm_restoration' && $req['current_status'] == 'Restoration Assigned') {
        $stmt = $pdo->prepare("UPDATE requests SET current_status='File Restored' WHERE id=?");
        $stmt->execute([$requestId]);
        $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
        logHistory($pdo, $requestId, $req['file_id'], 'File Restored', 'Operations Staff');
        header("Location: ../admin/requests.php"); exit;
    }
}

// --- ADMIN ---
if ($activeRole == 'admin') {
    if ($currentAction == 'assign_retrieval' && $req['current_status']=='Requested') {
        $stmt = $pdo->prepare("UPDATE requests SET current_status='Retrieval Assigned', assigned_to=?, retrieval_assigned_at=NOW() WHERE id=?");
        $stmt->execute([$staffId, $requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Retrieval Assigned', 'Admin assigned to Staff ID: '.$staffId);
        header("Location: ../admin/requests.php"); exit;
    }

    if ($currentAction == 'release_file' && $req['current_status']=='File Retrieved') {
        $stmt = $pdo->prepare("UPDATE requests SET current_status='Released' WHERE id=?");
        $stmt->execute([$requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Released', 'Admin');
        header("Location: ../admin/requests.php"); exit;
    }

    if ($currentAction == 'assign_restoration' && $req['current_status']=='Return Requested') {
        $stmt = $pdo->prepare("UPDATE requests SET current_status='Restoration Assigned', assigned_to=?, retrieval_assigned_at=NOW() WHERE id=?");
        $stmt->execute([$staffId, $requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Restoration Assigned', 'Admin assigned to Staff ID: '.$staffId);
        header("Location: ../admin/requests.php"); exit;
    }

    if ($currentAction == 'complete_transaction' && $req['current_status']=='File Restored') {
        $stmt = $pdo->prepare("UPDATE requests SET current_status='Completed' WHERE id=?");
        $stmt->execute([$requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Completed', 'Admin');
        header("Location: ../admin/requests.php"); exit;
    }
}

?>
