<?php
require_once '../config/db.php';
require_once '../config/functions.php';

$role = $_SESSION['active_role'] ?? $_SESSION['role'];
$userId = $_SESSION['user_id'];

$action = $_POST['action'] ?? '';
$requestId = $_POST['request_id'] ?? null;
$staffId = $_POST['assigned_staff_id'] ?? null;
$remarks = $_POST['remarks'] ?? null;

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
        $stmtFile = $pdo->prepare("SELECT file_name FROM files WHERE id=?");
        $stmtFile->execute([$fileId]);
        $file = $stmtFile->fetch(PDO::FETCH_ASSOC);

        $file_name = $file['file_name'];
        // Set status to Pending HOD Approval
        $pdo->prepare("
            INSERT INTO requests
            (file_id,user_id,current_status,status,borrow_date,extension_count,remarks)
            VALUES(?,?,'Pending HOD Approval','pending_hod',NOW(),0,?)
        ")->execute([$fileId,$userId,$remarks]);

        $requestId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT full_name,email FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $subject = "New File Request Submitted";

        $message = "
        <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>

            <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>

                <!-- Header -->
                <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                    VTS e-Library System
                </div>

                <!-- Body -->
                <div style='padding:24px; color:#334155; font-size:14px;'>

                    <p style='margin-top:0;'>
                        A new file request has been submitted and is awaiting <b>HOD approval</b>.
                    </p>

                    <table style='width:100%; border-collapse:collapse; margin-top:15px;'>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>
                                Requester
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$user['full_name']}
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                File Name
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$file_name}
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                Status
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                Pending HOD Approval
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                Remarks
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$remarks}
                            </td>
                        </tr>

                    </table>

                    <p style='margin-top:20px;'>
                        The request will proceed once it has been approved by the Head of Department.
                    </p>

                </div>

                <!-- Footer -->
                <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                    This is an automated message from <b>VTS e-Library System</b>.
                </div>

            </div>

        </div>
        ";

        $stmtAdmins = $pdo->prepare("
            SELECT email 
            FROM users 
            WHERE role = 'operations'
        ");
        $stmtAdmins->execute();

        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        foreach($admins as $admin){
            sendEmail($admin['email'],$subject,$message);
        }

        $pdo->prepare("UPDATE files SET status='requested' WHERE id=?")->execute([$fileId]);
        logHistory($pdo,$requestId,$fileId,'Requested (Pending HOD)',$userId);

        header("Location: ../requestor/my_files.php");
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
            $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
            logHistory($pdo,$requestId,$req['file_id'],'Cancelled',$userId);
        }
    }

    # Return request
    if($action == 'request_return') {
        $stmt = $pdo->prepare("SELECT r.*, f.file_name 
        FROM requests r
        JOIN files f ON r.file_id = f.id
        WHERE r.id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if($req['current_status']=='Released') {
            $pdo->prepare("UPDATE requests SET current_status='Return Requested', status='active' WHERE id=?")->execute([$requestId]);
            logHistory($pdo,$requestId,$req['file_id'],'Return Requested',$userId);
        }
        $stmt = $pdo->prepare("SELECT full_name,email FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $subject = "File Return Requested";
        
        $message = "
        <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>

            <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>

                <!-- Header -->
                <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                    VTS e-Library System
                </div>

                <!-- Body -->
                <div style='padding:24px; color:#334155; font-size:14px;'>

                    <p style='margin-top:0;'>A file return request has been submitted.</p>

                    <table style='width:100%; border-collapse:collapse; margin-top:15px;'>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>
                                Requester
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$user['full_name']}
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                File Name
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$req['file_name']}
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                Request ID
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$requestId}
                            </td>
                        </tr>

                    </table>

                    <p style='margin-top:20px;'>
                        Please assign restoration staff in the system to process the file return.
                    </p>

                </div>

                <!-- Footer -->
                <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                    This is an automated message from <b>VTS e-Library System</b>.
                </div>

            </div>

        </div>
        ";
        
        $stmtAdmins = $pdo->prepare("
            SELECT email 
            FROM users 
            WHERE role = 'operations'
        ");
        $stmtAdmins->execute();

        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        foreach($admins as $admin){
            sendEmail($admin['email'],$subject,$message);
        }
    }

    # Request Extension
    if($action == 'request_extension') {
        $stmt = $pdo->prepare("SELECT r.*, f.file_name
        FROM requests r
        JOIN files f ON r.file_id = f.id
        WHERE r.id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        // Logic: Max 2 extensions (count 0 -> 1, 1 -> 2)
        if($req['current_status']=='Released' && $req['extension_count'] < 2) {

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
        $stmt = $pdo->prepare("SELECT full_name,email FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $subject = "File Extension Request";
        $message = "
        <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>

            <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>

                <!-- Header -->
                <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                    VTS e-Library System
                </div>

                <!-- Body -->
                <div style='padding:24px; color:#334155; font-size:14px;'>

                    <p style='margin-top:0;'>
                        A request for <b>file extension</b> has been submitted.
                    </p>

                    <table style='width:100%; border-collapse:collapse; margin-top:15px;'>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>
                                Requester
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$user['full_name']}
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                File Name
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$req['file_name']}
                            </td>
                        </tr>

                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                Request ID
                            </td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>
                                {$requestId}
                            </td>
                        </tr>

                    </table>

                    <p style='margin-top:20px;'>
                        The request will be reviewed by the Head of Department for approval.
                    </p>

                </div>

                <!-- Footer -->
                <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                    This is an automated message from <b>VTS e-Library System</b>.
                </div>

            </div>

        </div>
        ";

        $stmtAdmins = $pdo->prepare("
            SELECT email 
            FROM users 
            WHERE role = 'operations'
        ");
        $stmtAdmins->execute();

        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        foreach($admins as $admin){
            sendEmail($admin['email'],$subject,$message);
        }
    }

    header("Location: ../requestor/my_files.php");
    exit;
}

# ===============================
# HOD ACTIONS
# ===============================
if($role == 'hod') {
    
    $stmt = $pdo->prepare("
    SELECT r.*, f.file_name, u.email, u.hod_id as requester_hod_id
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN files f ON r.file_id = f.id
    WHERE r.id=?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmtHod = $pdo->prepare("SELECT department FROM users WHERE id = ?");
    $stmtHod->execute([$userId]);
    $hodData = $stmtHod->fetch(PDO::FETCH_ASSOC);
    $hod_department = $hodData['department'] ?? '';

    if (!$req || $req['requester_hod_id'] != $userId){
        header("Location: ../hod/approvals.php");
        exit;
    }
    
    if($action == 'approve_hod') {
        if($req['current_status'] == 'Pending HOD Approval') {

            if($req['extension_count'] > 0) {

                $newDue = calculateDueDate($pdo, $req['due_date'], 3);

                $pdo->prepare("
                    UPDATE requests 
                    SET current_status='Released',
                        status='active',
                        due_date = ?,
                        hod_timestamp = NOW()
                    WHERE id=?
                ")->execute([$newDue, $requestId]);

                logHistory($pdo,$requestId,$req['file_id'],'Extension Approved',$userId);

            } else {

                $pdo->prepare("
                    UPDATE requests 
                    SET current_status='Approved by HOD',
                        hod_timestamp = NOW()
                    WHERE id=?
                ")->execute([$requestId]);

                logHistory($pdo,$requestId,$req['file_id'],'Approved by HOD',$userId);
            }
            $userEmail = $req['email'];
            $stmt = $pdo->prepare("SELECT full_name,email FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $subject = "File Request Approved by HOD";

            $message = "
            <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>

                <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>

                    <!-- Header -->
                    <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                        VTS e-Library System
                    </div>

                    <!-- Body -->
                    <div style='padding:24px; color:#334155; font-size:14px;'>

                        <p style='margin-top:0;'>Your file request has been <b>approved</b> by your Head of Department.</p>

                        <table style='width:100%; border-collapse:collapse; margin-top:15px;'>

                            <tr>
                                <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>
                                    File Name
                                </td>
                                <td style='padding:8px; border:1px solid #e2e8f0;'>
                                    {$req['file_name']}
                                </td>
                            </tr>

                            <tr>
                                <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                                    Request ID
                                </td>
                                <td style='padding:8px; border:1px solid #e2e8f0;'>
                                    {$requestId}
                                </td>
                            </tr>

                        </table>

                        <p style='margin-top:20px;'>
                            Your request will now be processed by the archive team.<br>
                            You will receive another notification once the file is ready for collection.
                        </p>

                    </div>

                    <!-- Footer -->
                    <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                        This is an automated message from <b>VTS e-Library System</b>.
                    </div>

                </div>

            </div>
            ";
            
            sendEmail($req['email'],$subject,$message);
        }
    }

    if($action == 'reject_hod') {
        if($req['current_status'] == 'Pending HOD Approval') {
            if($req['extension_count'] > 0) {

                $pdo->prepare("UPDATE requests 
                            SET current_status='Released', status='active' 
                            WHERE id=?")->execute([$requestId]);

                logHistory($pdo,$requestId,$req['file_id'],'Extension Rejected',$userId);

            } else {

                $pdo->prepare("UPDATE requests 
                            SET current_status='Cancelled', status='rejected' 
                            WHERE id=?")->execute([$requestId]);

                $pdo->prepare("UPDATE files 
                            SET status='available' 
                            WHERE id=?")->execute([$req['file_id']]);

                logHistory($pdo,$requestId,$req['file_id'],'Rejected by HOD',$userId);
            }
        }
    }
    
    header("Location: ../hod/approvals.php");
    exit;
}

# ===============================
# LOAD REQUEST FOR ADMIN/STAFF
# ===============================
$stmt = $pdo->prepare("
    SELECT r.*, f.file_name
    FROM requests r
    JOIN files f ON r.file_id = f.id
    WHERE r.id=?
");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$req) exit;

# ===============================
# ADMIN ACTIONS
# ===============================
if($role == 'admin') {

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

    $dueDate = calculateDueDate($pdo, date('Y-m-d'), 3);

    $pdo->prepare("
        UPDATE requests
        SET current_status='Released',
            status='active',
            released_at = NOW(),
            due_date = ?
        WHERE id=?"
    )->execute([$dueDate,$requestId]);

    $pdo->prepare("
        UPDATE files
        SET status='borrowed'
        WHERE id=?"
    )->execute([$req['file_id']]);

    logHistory($pdo,$requestId,$req['file_id'],'Released',$userId);

    # EMAIL
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id=?");
    $stmt->execute([$req['user_id']]);
    $user = $stmt->fetch();

    $subject = "File Ready for Collection";

    $message = "
    <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>

        <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>

            <!-- Header -->
            <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                VTS e-Library System
            </div>

            <!-- Body -->
            <div style='padding:24px; color:#334155; font-size:14px;'>

                <p style='margin-top:0;'>Your requested file is now ready for collection.</p>

                <table style='width:100%; border-collapse:collapse; margin-top:15px;'>

                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>
                            File Name
                        </td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>
                            {$req['file_name']}
                        </td>
                    </tr>

                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                            Request ID
                        </td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>
                            {$requestId}
                        </td>
                    </tr>

                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>
                            Due Date
                        </td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>
                            {$dueDate}
                        </td>
                    </tr>

                </table>

                <p style='margin-top:20px;'>
                    Please collect the file from the archive counter. Kindly ensure the file is returned before the due date.
                </p>

            </div>

            <!-- Footer -->
            <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                This is an automated message from <b>VTS e-Library System</b>.
            </div>

        </div>

    </div>
    ";
   
    sendEmail($user['email'],$subject,$message);
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
        $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
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
if($role == 'admin'){
    header("Location: ../admin/requests.php");
}
elseif($role == 'operations' || $role == 'staff'){
    header("Location: ../operations/dashboard.php");
}
elseif($role == 'hod'){
    header("Location: ../hod/approvals.php");
}
else{
    header("Location: ../requestor/my_files.php");
}

exit;
?>
