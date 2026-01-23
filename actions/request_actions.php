<?php
require_once '../config/db.php';
require_once '../config/functions.php';

session_start();

// --- 1. SECURITY & ROLE SETUP ---
 $activeRole = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'guest';

// Allow access if Admin
// OR if the specific action is allowed for Requestors
if (!isAdmin()) {
    
    // List of actions a standard Requestor is allowed to perform
    $allowedRequestorActions = ['create_request', 'request_return'];
    
    $currentAction = $_POST['action'] ?? '';
    
    // Check if user is a Requestor AND the action is in the allowed list
    $isAllowedRequestorAction = ($activeRole == 'requestor' && in_array($currentAction, $allowedRequestorActions));

    // If not an Admin, AND not an allowed Requestor action -> Kick them out
    if (!$isAllowedRequestorAction) {
        header("Location: ../index.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? '';
    $requestId = $_POST['request_id'] ?? null;
    $fileId = $_POST['file_id'] ?? null;
    $staffId = $_POST['assigned_staff_id'] ?? null; 
    $remarks = $_POST['remarks'] ?? '';

    // Helper: Get Request Info
    // We only fetch request details if we are NOT creating a new one
    $req = null;
    if ($action != 'create_request') {
        $stmt = $pdo->prepare("SELECT r.*, f.file_name, f.department FROM requests r JOIN files f ON r.file_id = f.id WHERE r.id = ?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            die("Request not found.");
        }
    }

    // --- UPDATED HELPER: Log to 'file_history' ---
    function logHistory($pdo, $reqId, $fileId, $actionName, $performedBy) {
        $sql = "INSERT INTO file_history (file_id, action, performed_by, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fileId, $actionName, $performedBy]);
    }

    // --- ACTION HANDLERS ---

    // 1. REQUESTOR SUBMIT (This was failing before because of security check)
    if ($action == 'create_request' && $activeRole == 'requestor') {
        
        // Double check file ID exists
        if($fileId) {
            $sql = "INSERT INTO requests (file_id, user_id, borrow_date, due_date, current_status) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Requested')";
            $stmt = $pdo->prepare($sql);
            
            if($stmt->execute([$fileId, $_SESSION['user_id']])) {
                // Update File Status immediately
                $pdo->prepare("UPDATE files SET status = 'borrowed' WHERE id = ?")->execute([$fileId]);
                
                // Log History
                $newRequestId = $pdo->lastInsertId();
                logHistory($pdo, $newRequestId, $fileId, 'Requested', 'Requestor ID: ' . $_SESSION['user_id']);
                
                header("Location: ../requestor/my_files.php"); 
                exit;
            } else {
                // Optional: Handle error (e.g. print_r($stmt->errorInfo()))
                header("Location: ../requestor/browse.php?error=failed");
                exit;
            }
        }
    }

    // 2. ADMIN: ASSIGN RETRIEVAL
    if ($action == 'assign_retrieval' && $activeRole == 'admin' && $req['current_status'] == 'Requested') {
        $sql = "UPDATE requests SET current_status = 'Retrieval Assigned', assigned_to = ?, retrieval_assigned_at = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$staffId, $requestId]);
        
        logHistory($pdo, $requestId, $req['file_id'], 'Retrieval Assigned', 'Admin assigned to Staff ID: ' . $staffId);
        header("Location: ../admin/requests.php");
        exit;
    }

    // 3. OPERATIONS: FILE RETRIEVED
    if ($action == 'confirm_retrieval' && $activeRole == 'operations' && $req['current_status'] == 'Retrieval Assigned') {
        // Check SLA
        $assignedTime = strtotime($req['retrieval_assigned_at']);
        $now = time();
        $diffMins = round(($now - $assignedTime) / 60);
        $isBreach = ($diffMins > 20) ? 1 : 0;

        $sql = "UPDATE requests SET current_status = 'File Retrieved', retrieved_at = NOW(), sla_breach = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$isBreach, $requestId]);
        
        logHistory($pdo, $requestId, $req['file_id'], 'File Retrieved', 'Operations Staff (Time: ' . $diffMins . ' mins)');
        header("Location: ../admin/requests.php"); 
        exit;
    }

    // 4. ADMIN: RELEASE FILE
    if ($action == 'release_file' && $activeRole == 'admin' && $req['current_status'] == 'File Retrieved') {
        $sql = "UPDATE requests SET current_status = 'Released' WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$requestId]);
        
        logHistory($pdo, $requestId, $req['file_id'], 'Released', 'Admin');
        header("Location: ../admin/requests.php");
        exit;
    }

    // 5. REQUESTOR: RETURN REQUEST
    if ($action == 'request_return' && $activeRole == 'requestor' && $req['current_status'] == 'Released') {
        $sql = "UPDATE requests SET current_status = 'Return Requested', return_date = CURDATE() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$requestId]);
        
        logHistory($pdo, $requestId, $req['file_id'], 'Return Requested', 'Requestor');
        header("Location: ../requestor/my_files.php");
        exit;
    }

    // 6. ADMIN: ASSIGN RESTORATION
    if ($action == 'assign_restoration' && $activeRole == 'admin' && $req['current_status'] == 'Return Requested') {
        $sql = "UPDATE requests SET current_status = 'Restoration Assigned', assigned_to = ?, retrieval_assigned_at = NOW() WHERE id = ?"; 
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$staffId, $requestId]);
        
        logHistory($pdo, $requestId, $req['file_id'], 'Restoration Assigned', 'Admin assigned to Staff ID: ' . $staffId);
        header("Location: ../admin/requests.php");
        exit;
    }

    // 7. OPERATIONS: FILE RESTORED
    if ($action == 'confirm_restoration' && $activeRole == 'operations' && $req['current_status'] == 'Restoration Assigned') {
        $sql = "UPDATE requests SET current_status = 'File Restored' WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$requestId]);
        
        $pdo->prepare("UPDATE files SET status = 'available' WHERE id = ?")->execute([$req['file_id']]);
        
        logHistory($pdo, $requestId, $req['file_id'], 'File Restored', 'Operations Staff');
        header("Location: ../admin/requests.php");
        exit;
    }

    // 8. ADMIN: COMPLETE
    if ($action == 'complete_transaction' && $activeRole == 'admin' && $req['current_status'] == 'File Restored') {
        $sql = "UPDATE requests SET current_status = 'Completed' WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$requestId]);
        
        logHistory($pdo, $requestId, $req['file_id'], 'Completed', 'Admin');
        header("Location: ../admin/requests.php");
        exit;
    }
}
?>