<?php
require_once '../config/db.php';
require_once '../config/functions.php';

$role = $_SESSION['active_role'] ?? $_SESSION['role'];
$userId = $_SESSION['user_id'];

$action = $_POST['action'] ?? '';
$requestId = $_POST['request_id'] ?? null;
$staffId = $_POST['assigned_staff_id'] ?? null;

function logHistory($pdo,$rid,$fid,$action,$user){
    $role = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'Unknown';
    $pdo->prepare("
        INSERT INTO file_history
        (request_id,file_id,action,performed_by,performed_role,created_at)
        VALUES(?,?,?,?,?,NOW())
    ")->execute([
        $rid,
        $fid,
        $action,
        $user,
        $role
    ]);
}

# ===============================
# REQUESTOR ACTIONS
# ===============================
if($role == 'requestor') {

    # Borrow file - Redirect to HOD first
    if($action == 'create_request') {
        $fileId = $_POST['file_id'];

        // Set status to Pending HOD Approval
        $pdo->prepare("
            INSERT INTO requests
            (file_id,user_id,current_status,status,borrow_date,extension_count)
            VALUES(?,?,'Pending HOD Approval','pending_hod',NOW(),0)
        ")->execute([$fileId,$userId]);

        $requestId = $pdo->lastInsertId();

        $pdo->prepare("UPDATE files SET status='requested' WHERE id=?")->execute([$fileId]);
        logHistory($pdo,$requestId,$fileId,'Requested (Pending HOD)',$userId);

        header("Location: /vts_library/requestor/my_files.php");
        exit;
    }

    # Cancel request
    if($action == 'cancel_request') {
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        // Allow cancel if Requested or Pending HOD
        if(in_array($req['current_status'], ['Requested', 'Pending HOD Approval'])) {
            $pdo->prepare("UPDATE requests SET current_status='Cancelled', status='rejected' WHERE id=?")->execute([$requestId]);
            logHistory($pdo,$requestId,$req['file_id'],'Cancelled',$userId);
        }
    }

    # Return request
    if($action == 'request_return') {
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if($req['current_status']=='Released') {
            $pdo->prepare("UPDATE requests SET current_status='Return Requested', status='active' WHERE id=?")->execute([$requestId]);
            logHistory($pdo,$requestId,$req['file_id'],'Return Requested',$userId);
        }
    }

    # Request Extension
    if($action == 'request_extension') {
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        // Logic: Max 2 extensions (count 0 -> 1, 1 -> 2)
        if($req['current_status']=='Released' && $req['extension_count'] < 2) {
            
            // Update status to pending HOD approval for extension, increment count
            $newCount = $req['extension_count'] + 1;
            $pdo->prepare("
                UPDATE requests 
                SET status='extension_requested', 
                    current_status='Pending HOD Approval', 
                    extension_count = ? 
                WHERE id=?"
            )->execute([$newCount, $requestId]);

            logHistory($pdo,$requestId,$req['file_id'],"Extension Request #$newCount",$userId);
        }
    }

    header("Location: /vts_library/requestor/my_files.php");
    exit;
}

# ===============================
# HOD ACTIONS
# ===============================
if($role == 'hod') {
    
    // 1. Fetch the request details along with the requestor's Department
    $stmt = $pdo->prepare("
        SELECT r.*, u.hod_id as requester_hod_id 
        FROM requests r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.id=?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmtHod = $pdo->prepare("SELECT department FROM users WHERE id = ?");
    $stmtHod->execute([$userId]);
    $hodData = $stmtHod->fetch(PDO::FETCH_ASSOC);
    $hod_department = $hodData['department'] ?? '';

    if (!$req || $req['requester_hod_id'] != $userId){
        header("Location: /vts_library/hod/approvals.php");
        exit;
    }
    
    if($action == 'approve_hod') {
        if($req['current_status'] == 'Pending HOD Approval') {
            if($req['extension_count'] > 0) {
                $pdo->prepare("
                    UPDATE requests 
                    SET current_status='Released', 
                        status='active', 
                        due_date = DATE_ADD(due_date, INTERVAL 3 DAY),
                        hod_timestamp = NOW()
                    WHERE id=?
                ")->execute([$requestId]);
                logHistory($pdo,$requestId,$req['file_id'],'Extension Approved',$userId);
            } else {
                $pdo->prepare("
                UPDATE requests 
                SET current_status = 'Approved by HOD',
                    hod_timestamp = NOW()
                WHERE id = ?
            ")->execute([$requestId]);
                logHistory($pdo,$requestId,$req['file_id'],'Approved by HOD',$userId);
            }
        }
    }

    if($action == 'reject_hod') {
        if($req['current_status'] == 'Pending HOD Approval') {
            if($req['extension_count'] > 0) {
                 $pdo->prepare("UPDATE requests SET current_status='Released', status='active' WHERE id=?")->execute([$requestId]);
                 logHistory($pdo,$requestId,$req['file_id'],'Extension Rejected',$userId);
            } else {
                 $pdo->prepare("UPDATE requests SET current_status='Cancelled', status='rejected' WHERE id=?")->execute([$requestId]);
                 logHistory($pdo,$requestId,$req['file_id'],'Rejected by HOD',$userId);
            }
        }
    }
    
    header("Location: /vts_library/hod/approvals.php");
    exit;
}

# ===============================
# LOAD REQUEST FOR ADMIN/STAFF
# ===============================
$stmt = $pdo->prepare("SELECT r.*, f.file_name FROM requests r JOIN files f ON r.file_id=f.id WHERE r.id=?");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$req) exit;

# ===============================
# ADMIN ACTIONS
# ===============================
if($role == 'admin') {

    // Changed: Check for 'Approved by HOD' instead of 'Requested'
    if($action == 'assign_retrieval' && $req['current_status']=='Approved by HOD') {
        $pdo->prepare("
            UPDATE requests
            SET current_status='Retrieval Assigned',
                status='approved',
                assigned_to=?,
                retrieval_assigned_at=NOW()
            WHERE id=?
        ")->execute([$staffId,$requestId]);
        logHistory($pdo,$requestId,$req['file_id'],'Retrieval Assigned',$userId);
    }

    if($action == 'release_file' && $req['current_status']=='File Retrieved') {

    $pdo->prepare("
        UPDATE requests
        SET current_status='Released',
            status='active',
            released_at = NOW(),
            due_date = DATE_ADD(NOW(), INTERVAL 3 DAY)
        WHERE id=?
    ")->execute([$requestId]);

    $pdo->prepare("
        UPDATE files
        SET status='borrowed'
        WHERE id=?
    ")->execute([$req['file_id']]);

    logHistory($pdo,$requestId,$req['file_id'],'Released',$userId);
    }
    
    if($action == 'assign_restoration' && $req['current_status']=='Return Requested') {
        $pdo->prepare("
            UPDATE requests
            SET current_status='Restoration Assigned',
                assigned_to=?,
                retrieval_assigned_at=NOW()
            WHERE id=?
        ")->execute([$staffId,$requestId]);
        logHistory($pdo,$requestId,$req['file_id'],'Restoration Assigned',$userId);
    }

    if($action == 'complete_transaction' && $req['current_status']=='File Restored') {
        $pdo->prepare("UPDATE requests SET current_status='Completed', status='returned' WHERE id=?")->execute([$requestId]);
        logHistory($pdo,$requestId,$req['file_id'],'Completed',$userId);
    }

}

# ===============================
# STAFF ACTIONS
# ===============================
if(in_array($role,['staff','operations'])) {

    if($action=='confirm_retrieval' && $req['current_status']=='Retrieval Assigned') {
        $pdo->prepare("UPDATE requests SET current_status='File Retrieved', retrieved_at=NOW() WHERE id=?")->execute([$requestId]);
        logHistory($pdo,$requestId,$req['file_id'],'File Retrieved',$userId);
    }

    if($action=='confirm_restoration' && $req['current_status']=='Restoration Assigned') {
        $pdo->prepare("UPDATE requests SET current_status='File Restored' WHERE id=?")->execute([$requestId]);
        $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
        logHistory($pdo,$requestId,$req['file_id'],'File Restored',$userId);
    }

}

header("Location: /vts_library/admin/requests.php");
exit;
?>
