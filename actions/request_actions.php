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

    # Borrow file
    if($action == 'create_request') {

        $fileId = $_POST['file_id'];

        $pdo->prepare("
            INSERT INTO requests
            (file_id,user_id,current_status,borrow_date)
            VALUES(?,?,'Requested',NOW())
        ")->execute([$fileId,$userId]);

        $requestId = $pdo->lastInsertId();

        $pdo->prepare("
            UPDATE files SET status='requested'
            WHERE id=?
        ")->execute([$fileId]);

        logHistory($pdo,$requestId,$fileId,'Requested',$userId);

        header("Location: /vts_library/requestor/my_files.php");
        exit;
    }

    # Cancel request
    if($action == 'cancel_request') {

        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if($req['current_status']=='Requested') {

            $pdo->prepare("
                UPDATE requests SET current_status='Cancelled'
                WHERE id=?
            ")->execute([$requestId]);

            logHistory($pdo,$requestId,$req['file_id'],'Cancelled',$userId);
        }
    }

    # Return request
    if($action == 'request_return') {

        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if($req['current_status']=='Released') {

            $pdo->prepare("
                UPDATE requests
                SET current_status='Return Requested'
                WHERE id=?
            ")->execute([$requestId]);

            logHistory($pdo,$requestId,$req['file_id'],'Return Requested',$userId);
        }
    }

    header("Location: /vts_library/requestor/my_files.php");
    exit;
}

# ===============================
# LOAD REQUEST FOR ADMIN/STAFF
# ===============================
$stmt = $pdo->prepare("
SELECT r.*, f.file_name
FROM requests r
JOIN files f ON r.file_id=f.id
WHERE r.id=?
");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$req) exit;

# ===============================
# ADMIN ACTIONS
# ===============================
if($role == 'admin') {

if($action == 'assign_retrieval' && $req['current_status']=='Requested') {

    $pdo->prepare("
        UPDATE requests
        SET current_status='Retrieval Assigned',
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
            released_at = NOW()
        WHERE id=?
    ")->execute([$requestId]);

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

    $pdo->prepare("
        UPDATE requests SET current_status='Completed'
        WHERE id=?
    ")->execute([$requestId]);

    logHistory($pdo,$requestId,$req['file_id'],'Completed',$userId);
}

}

# ===============================
# STAFF ACTIONS
# ===============================
if(in_array($role,['staff','operations'])) {

if($action=='confirm_retrieval' && $req['current_status']=='Retrieval Assigned') {

    $pdo->prepare("
        UPDATE requests
        SET current_status='File Retrieved',
            retrieved_at=NOW()
        WHERE id=?
    ")->execute([$requestId]);

    logHistory($pdo,$requestId,$req['file_id'],'File Retrieved',$userId);
}

if($action=='confirm_restoration' && $req['current_status']=='Restoration Assigned') {

    $pdo->prepare("
        UPDATE requests
        SET current_status='File Restored'
        WHERE id=?
    ")->execute([$requestId]);

    $pdo->prepare("
        UPDATE files SET status='available'
        WHERE id=?
    ")->execute([$req['file_id']]);

    logHistory($pdo,$requestId,$req['file_id'],'File Restored',$userId);
}

}

header("Location: /vts_library/admin/requests.php");
exit;
