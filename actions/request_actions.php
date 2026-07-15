<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../config/workflow.php';

$role = $_SESSION['active_role'] ?? $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];

$action = $_POST['action'] ?? '';
$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : null;
$staffId = (int)($_POST['assigned_staff_id'] ?? 0);
$remarks = $_POST['remarks'] ?? null;

# ===============================
# REQUESTOR ACTIONS
# ===============================
if ($role == 'requestor') {
    if ($action == 'create_multiple_request') {

        $remarks = $_POST['remarks'] ?? '';
        $files = json_decode($_POST['selected_files'], true);

        if (empty($files)) {
            header("Location: ../requestor/browse.php");
            exit;
        }

        foreach ($files as $item) {
            $fileId = $item['id'];

            $stmtFile = $pdo->prepare("SELECT file_name FROM files WHERE id=?");
            $stmtFile->execute([$fileId]);
            $file = $stmtFile->fetch(PDO::FETCH_ASSOC);
            $file_name = $file['file_name'];

            $pdo->prepare("
                INSERT INTO requests
                (file_id,user_id,current_status,status,borrow_date,extension_count,remarks)
                VALUES(?,?,'Pending HOD Approval','pending_hod',NOW(),0,?)
            ")->execute([$fileId, $userId, $remarks]);

            $newRequestId = $pdo->lastInsertId();

            $pdo->prepare("UPDATE files SET status='requested' WHERE id=?")->execute([$fileId]);

            logHistory($pdo, $newRequestId, $fileId, 'Requested (Pending HOD)', $userId);
        }

        $count = count($files);
        $_SESSION['success'] = "$count requests submitted successfully.";

        header("Location: ../requestor/browse.php");
        exit;
    }

    # Borrow file - Redirect to HOD first
    if ($action == 'create_request') {
        $fileId = $_POST['file_id'];
        $stmtFile = $pdo->prepare("SELECT file_name FROM files WHERE id=?");
        $stmtFile->execute([$fileId]);
        $file = $stmtFile->fetch(PDO::FETCH_ASSOC);

        $file_name = $file['file_name'];

        $pdo->prepare("
            INSERT INTO requests
            (file_id,user_id,current_status,status,borrow_date,extension_count,remarks)
            VALUES(?,?,'Pending HOD Approval','pending_hod',NOW(),0,?)
        ")->execute([$fileId, $userId, $remarks]);

        $newRequestId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT full_name,email FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $subject = "New File Request Submitted";

        $message = "
        <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>
            <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>
                <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                    VTS e-Library System
                </div>
                <div style='padding:24px; color:#334155; font-size:14px;'>
                    <p style='margin-top:0;'>
                        A new file request has been submitted and is awaiting <b>HOD approval</b>.
                    </p>
                    <table style='width:100%; border-collapse:collapse; margin-top:15px;'>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>Requester</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$user['full_name']}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>File Name</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$file_name}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Status</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>Pending HOD Approval</td>
                        </tr>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Remarks</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$remarks}</td>
                        </tr>
                    </table>
                    <p style='margin-top:20px;'>
                        The request will proceed once it has been approved by the Head of Department.
                    </p>
                </div>
                <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                    This is an automated message from <b>VTS e-Library System</b>.
                </div>
            </div>
        </div>
        ";

        $stmtAdmins = $pdo->prepare("SELECT email FROM users WHERE role = 'operations'");
        $stmtAdmins->execute();
        foreach ($stmtAdmins->fetchAll(PDO::FETCH_ASSOC) as $admin) {
            sendEmail($admin['email'], $subject, $message);
        }

        $pdo->prepare("UPDATE files SET status='requested' WHERE id=?")->execute([$fileId]);
        logHistory($pdo, $newRequestId, $fileId, 'Requested (Pending HOD)', $userId);

        header("Location: ../requestor/my_files.php");
        exit;
    }

    # Cancel request
    if ($action == 'cancel_request') {
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($req && in_array($req['current_status'], ['Requested', 'Pending HOD Approval'])) {
            if ($req['status'] == 'extension_requested') {
                $pdo->prepare("
                    UPDATE requests
                    SET current_status='Released',
                        status='active'
                    WHERE id=?
                ")->execute([$requestId]);

                logHistory($pdo, $requestId, $req['file_id'], 'Extension Cancelled (Reverted to Released)', $userId);
            } else {
                $pdo->prepare("
                    UPDATE requests
                    SET current_status='Cancelled',
                        status='rejected'
                    WHERE id=?
                ")->execute([$requestId]);

                $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
                logHistory($pdo, $requestId, $req['file_id'], 'Cancelled', $userId);
            }
        }
    }

    # Single return
    if ($action == 'request_return' && $requestId) {
        $result = processReturnRequest($pdo, $requestId, $userId);
        if (!$result['ok'] && ($result['error'] ?? '') === 'Unauthorized return request.') {
            die($result['error']);
        }
        if (!$result['ok'] && ($result['error'] ?? '') === 'Request not found.') {
            die($result['error']);
        }
    }

    # Bulk return — same processor as single
    if ($action == 'bulk_request_return') {
        $ids = normalizeRequestIds($_POST['request_ids'] ?? []);
        $ok = 0;
        $fail = 0;

        foreach ($ids as $rid) {
            $result = processReturnRequest($pdo, $rid, $userId);
            if ($result['ok']) {
                $ok++;
            } else {
                $fail++;
            }
        }

        if ($ok > 0) {
            $_SESSION['success'] = $ok === 1
                ? '1 return request submitted.'
                : "$ok return requests submitted.";
        }
        if ($fail > 0 && $ok === 0) {
            $_SESSION['error'] = 'Unable to process return for the selected files.';
        }
    }

    # Request Extension
    if ($action == 'request_extension') {
        $stmt = $pdo->prepare("SELECT r.*, f.file_name
            FROM requests r
            JOIN files f ON r.file_id = f.id
            WHERE r.id=?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($req && $req['current_status'] == 'Released' && $req['extension_count'] < 2) {
            $newCount = $req['extension_count'] + 1;
            $pdo->prepare("
                UPDATE requests
                SET status='extension_requested',
                    current_status='Pending HOD Approval',
                    extension_count = ?
                WHERE id=?"
            )->execute([$newCount, $requestId]);

            logHistory($pdo, $requestId, $req['file_id'], "Extension Request #$newCount", $userId);
        }

        $stmt = $pdo->prepare("SELECT full_name,email FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $subject = "File Extension Request";
        $message = "
        <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>
            <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>
                <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                    VTS e-Library System
                </div>
                <div style='padding:24px; color:#334155; font-size:14px;'>
                    <p style='margin-top:0;'>
                        A request for <b>file extension</b> has been submitted.
                    </p>
                    <table style='width:100%; border-collapse:collapse; margin-top:15px;'>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>Requester</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$user['full_name']}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>File Name</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$req['file_name']}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Request ID</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$requestId}</td>
                        </tr>
                    </table>
                    <p style='margin-top:20px;'>
                        The request will be reviewed by the Head of Department for approval.
                    </p>
                </div>
                <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                    This is an automated message from <b>VTS e-Library System</b>.
                </div>
            </div>
        </div>
        ";

        $stmtAdmins = $pdo->prepare("SELECT email FROM users WHERE role = 'operations'");
        $stmtAdmins->execute();
        foreach ($stmtAdmins->fetchAll(PDO::FETCH_ASSOC) as $admin) {
            sendEmail($admin['email'], $subject, $message);
        }
    }

    $redirectTo = $_POST['redirect_to'] ?? '';

    if ($redirectTo === 'department_files') {
        header("Location: ../requestor/department_files.php");
    } else {
        header("Location: ../requestor/my_files.php");
    }
    exit;
}

# ===============================
# HOD ACTIONS
# ===============================
if ($role == 'hod') {

    # Bulk approve
    if ($action == 'bulk_approve_hod') {
        $ids = normalizeRequestIds($_POST['request_ids'] ?? []);
        $ok = 0;
        foreach ($ids as $rid) {
            $result = processHodApprove($pdo, $rid, $userId);
            if ($result['ok']) {
                $ok++;
            }
        }
        if ($ok > 0) {
            $_SESSION['success'] = $ok === 1
                ? '1 request approved.'
                : "$ok requests approved.";
        }
        header("Location: ../hod/approvals.php");
        exit;
    }

    # Bulk reject
    if ($action == 'bulk_reject_hod') {
        $ids = normalizeRequestIds($_POST['request_ids'] ?? []);
        $ok = 0;
        foreach ($ids as $rid) {
            $result = processHodReject($pdo, $rid, $userId);
            if ($result['ok']) {
                $ok++;
            }
        }
        if ($ok > 0) {
            $_SESSION['success'] = $ok === 1
                ? '1 request rejected.'
                : "$ok requests rejected.";
        }
        header("Location: ../hod/approvals.php");
        exit;
    }

    # Single approve / reject
    if ($requestId && $action == 'approve_hod') {
        processHodApprove($pdo, $requestId, $userId);
        header("Location: ../hod/approvals.php");
        exit;
    }

    if ($requestId && $action == 'reject_hod') {
        processHodReject($pdo, $requestId, $userId);
        header("Location: ../hod/approvals.php");
        exit;
    }

    header("Location: ../hod/approvals.php");
    exit;
}

# ===============================
# ADMIN ACTIONS
# ===============================
if ($role == 'admin') {

    # --- Bulk admin actions ---
    if ($action == 'bulk_assign_retrieval') {
        $ids = normalizeRequestIds($_POST['request_ids'] ?? []);
        $ok = 0;
        foreach ($ids as $rid) {
            $result = processAssignRetrieval($pdo, $rid, $staffId, $userId);
            if ($result['ok']) {
                $ok++;
            }
        }
        if ($ok > 0) {
            $_SESSION['success'] = "$ok retrieval assignment(s) completed.";
        }
        header("Location: ../admin/requests.php");
        exit;
    }

    if ($action == 'bulk_release_file') {
        $ids = normalizeRequestIds($_POST['request_ids'] ?? []);
        $ok = 0;
        foreach ($ids as $rid) {
            $result = processReleaseFile($pdo, $rid, $userId);
            if ($result['ok']) {
                $ok++;
            }
        }
        if ($ok > 0) {
            $_SESSION['success'] = "$ok file(s) released.";
        }
        header("Location: ../admin/requests.php");
        exit;
    }

    if ($action == 'bulk_assign_restoration') {
        $ids = normalizeRequestIds($_POST['request_ids'] ?? []);
        $ok = 0;
        foreach ($ids as $rid) {
            $result = processAssignRestoration($pdo, $rid, $staffId, $userId);
            if ($result['ok']) {
                $ok++;
            }
        }
        if ($ok > 0) {
            $_SESSION['success'] = "$ok restoration assignment(s) completed.";
        }
        header("Location: ../admin/requests.php");
        exit;
    }

    if ($action == 'bulk_complete_transaction') {
        $ids = normalizeRequestIds($_POST['request_ids'] ?? []);
        $ok = 0;
        foreach ($ids as $rid) {
            $result = processCompleteTransaction($pdo, $rid, $userId);
            if ($result['ok']) {
                $ok++;
            }
        }
        if ($ok > 0) {
            $_SESSION['success'] = "$ok transaction(s) completed.";
        }
        header("Location: ../admin/requests.php");
        exit;
    }

    # --- Single admin actions ---
    if ($requestId) {
        if ($action == 'assign_retrieval') {
            processAssignRetrieval($pdo, $requestId, $staffId, $userId);
        }
        if ($action == 'release_file') {
            processReleaseFile($pdo, $requestId, $userId);
        }
        if ($action == 'assign_restoration') {
            processAssignRestoration($pdo, $requestId, $staffId, $userId);
        }
        if ($action == 'complete_transaction') {
            processCompleteTransaction($pdo, $requestId, $userId);
        }
    }

    header("Location: ../admin/requests.php");
    exit;
}

# ===============================
# STAFF / OPERATIONS ACTIONS
# ===============================
if (in_array($role, ['staff', 'operations'])) {
    if (!$requestId) {
        header("Location: ../operations/dashboard.php");
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.*, f.file_name
        FROM requests r
        JOIN files f ON r.file_id = f.id
        WHERE r.id=?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($req) {
        if ($action == 'confirm_retrieval' && $req['current_status'] == 'Retrieval Assigned') {
            $pdo->prepare("UPDATE requests SET current_status='File Retrieved', retrieved_at=NOW() WHERE id=?")->execute([$requestId]);
            logHistory($pdo, $requestId, $req['file_id'], 'File Retrieved', $userId);
        }

        if ($action == 'confirm_restoration' && $req['current_status'] == 'Restoration Assigned') {
            $pdo->prepare("
                UPDATE requests
                SET current_status='File Restored',
                    status='returned',
                    return_date=NOW(),
                    updated_at=NOW()
                WHERE id=?
            ")->execute([$requestId]);

            $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
            logHistory($pdo, $requestId, $req['file_id'], 'File Restored', $userId);
        }
    }

    header("Location: ../operations/dashboard.php");
    exit;
}

header("Location: ../requestor/my_files.php");
exit;
