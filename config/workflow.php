<?php
/**
 * Shared workflow processors for single + bulk actions.
 * Keep all status transitions, history, and emails here — call from request_actions.php.
 */

if (!function_exists('logHistory')) {
    function logHistory($pdo, $rid, $fid, $action, $user) {
        $role = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'Unknown';
        $pdo->prepare("
            INSERT INTO file_history
            (request_id, file_id, action, performed_by, performed_role, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$rid, $fid, $action, $user, $role]);
    }
}

/**
 * Request return for a Released file. Owner or same-department actor allowed.
 * @return array{ok:bool, error?:string, file_name?:string}
 */
function processReturnRequest(PDO $pdo, int $requestId, int $actorId): array
{
    $stmt = $pdo->prepare("
        SELECT
            r.*,
            f.file_name,
            owner.full_name AS owner_name,
            owner.department AS owner_department,
            actor.full_name AS actor_name,
            actor.email AS actor_email,
            actor.department AS actor_department
        FROM requests r
        JOIN files f ON r.file_id = f.id
        JOIN users owner ON r.user_id = owner.id
        JOIN users actor ON actor.id = ?
        WHERE r.id = ?
    ");
    $stmt->execute([$actorId, $requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }

    $canReturn = (
        (int)$req['user_id'] === $actorId ||
        (
            !empty($req['owner_department']) &&
            !empty($req['actor_department']) &&
            $req['owner_department'] === $req['actor_department']
        )
    );

    if (!$canReturn) {
        return ['ok' => false, 'error' => 'Unauthorized return request.'];
    }

    if ($req['current_status'] !== 'Released') {
        return ['ok' => false, 'error' => 'File is not in Released status.'];
    }

    $pdo->prepare("
        UPDATE requests
        SET current_status='Return Requested',
            status='active',
            updated_at=NOW()
        WHERE id=?
    ")->execute([$requestId]);

    logHistory($pdo, $requestId, $req['file_id'], 'Return Requested', $actorId);

    $requestedByText = $req['actor_name'];
    if ((int)$req['user_id'] !== $actorId) {
        $requestedByText .= ' on behalf of ' . $req['owner_name'];
    }

    $subject = 'File Return Requested';
    $fileName = htmlspecialchars($req['file_name']);
    $ownerName = htmlspecialchars($req['owner_name']);
    $ownerDept = htmlspecialchars($req['owner_department'] ?? '');
    $requestedBy = htmlspecialchars($requestedByText);

    $message = "
    <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>
        <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>
            <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                VTS e-Library System
            </div>
            <div style='padding:24px; color:#334155; font-size:14px;'>
                <p style='margin-top:0;'>A file return request has been submitted.</p>
                <table style='width:100%; border-collapse:collapse; margin-top:15px;'>
                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>Requested By</td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>{$requestedBy}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>File Owner</td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>{$ownerName}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Department</td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>{$ownerDept}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>File Name</td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>{$fileName}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Request ID</td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>{$requestId}</td>
                    </tr>
                </table>
                <p style='margin-top:20px;'>Please assign restoration staff in the system to process the file return.</p>
            </div>
            <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                This is an automated message from <b>VTS e-Library System</b>.
            </div>
        </div>
    </div>
    ";

    $stmtOps = $pdo->prepare("SELECT email FROM users WHERE role = 'operations'");
    $stmtOps->execute();
    foreach ($stmtOps->fetchAll(PDO::FETCH_ASSOC) as $ops) {
        sendEmail($ops['email'], $subject, $message);
    }

    return ['ok' => true, 'file_name' => $req['file_name']];
}

/**
 * Load a request visible to the given HOD (requester.hod_id must match).
 */
function loadHodRequest(PDO $pdo, int $requestId, int $hodId): ?array
{
    $stmt = $pdo->prepare("
        SELECT r.*, f.file_name, u.email, u.hod_id AS requester_hod_id
        FROM requests r
        JOIN users u ON r.user_id = u.id
        JOIN files f ON r.file_id = f.id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req || (int)$req['requester_hod_id'] !== $hodId) {
        return null;
    }

    return $req;
}

/**
 * Approve a Pending HOD Approval request (new borrow or extension).
 * @return array{ok:bool, error?:string}
 */
function processHodApprove(PDO $pdo, int $requestId, int $hodId): array
{
    $req = loadHodRequest($pdo, $requestId, $hodId);
    if (!$req) {
        return ['ok' => false, 'error' => 'Unauthorized or request not found.'];
    }

    if ($req['current_status'] !== 'Pending HOD Approval') {
        return ['ok' => false, 'error' => 'Request is not pending HOD approval.'];
    }

    if ((int)$req['extension_count'] > 0) {
        $newDue = calculateDueDate($pdo, $req['due_date'], 3);
        $pdo->prepare("
            UPDATE requests
            SET current_status='Released',
                status='active',
                due_date = ?,
                hod_timestamp = NOW()
            WHERE id=?
        ")->execute([$newDue, $requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Extension Approved', $hodId);
    } else {
        $pdo->prepare("
            UPDATE requests
            SET current_status='Approved by HOD',
                hod_timestamp = NOW()
            WHERE id=?
        ")->execute([$requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Approved by HOD', $hodId);
    }

    $fileName = htmlspecialchars($req['file_name']);
    $subject = 'File Request Approved by HOD';
    $message = "
    <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>
        <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>
            <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                VTS e-Library System
            </div>
            <div style='padding:24px; color:#334155; font-size:14px;'>
                <p style='margin-top:0;'>Your file request has been <b>approved</b> by your Head of Department.</p>
                <table style='width:100%; border-collapse:collapse; margin-top:15px;'>
                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>File Name</td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>{$fileName}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Request ID</td>
                        <td style='padding:8px; border:1px solid #e2e8f0;'>{$requestId}</td>
                    </tr>
                </table>
                <p style='margin-top:20px;'>
                    Your request will now be processed by the archive team.<br>
                    You will receive another notification once the file is ready for collection.
                </p>
            </div>
            <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                This is an automated message from <b>VTS e-Library System</b>.
            </div>
        </div>
    </div>
    ";
    sendEmail($req['email'], $subject, $message);

    return ['ok' => true];
}

/**
 * Reject a Pending HOD Approval request.
 * @return array{ok:bool, error?:string}
 */
function processHodReject(PDO $pdo, int $requestId, int $hodId): array
{
    $req = loadHodRequest($pdo, $requestId, $hodId);
    if (!$req) {
        return ['ok' => false, 'error' => 'Unauthorized or request not found.'];
    }

    if ($req['current_status'] !== 'Pending HOD Approval') {
        return ['ok' => false, 'error' => 'Request is not pending HOD approval.'];
    }

    if ((int)$req['extension_count'] > 0) {
        $pdo->prepare("
            UPDATE requests
            SET current_status='Released', status='active'
            WHERE id=?
        ")->execute([$requestId]);
        logHistory($pdo, $requestId, $req['file_id'], 'Extension Rejected', $hodId);
    } else {
        $pdo->prepare("
            UPDATE requests
            SET current_status='Cancelled', status='rejected'
            WHERE id=?
        ")->execute([$requestId]);
        $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
        logHistory($pdo, $requestId, $req['file_id'], 'Rejected by HOD', $hodId);
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool, error?:string}
 */
function processAssignRetrieval(PDO $pdo, int $requestId, int $staffId, int $adminId): array
{
    $stmt = $pdo->prepare("SELECT r.*, f.file_name FROM requests r JOIN files f ON r.file_id = f.id WHERE r.id=?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if ($req['current_status'] !== 'Approved by HOD') {
        return ['ok' => false, 'error' => 'Invalid status for assign retrieval.'];
    }
    if (empty($staffId)) {
        return ['ok' => false, 'error' => 'Staff required.'];
    }

    $pdo->prepare("
        UPDATE requests
        SET current_status='Retrieval Assigned',
            status='approved',
            assigned_to=?,
            retrieval_assigned_at=NOW()
        WHERE id=?
    ")->execute([$staffId, $requestId]);
    logHistory($pdo, $requestId, $req['file_id'], 'Retrieval Assigned', $adminId);

    return ['ok' => true];
}

/**
 * @return array{ok:bool, error?:string}
 */
function processReleaseFile(PDO $pdo, int $requestId, int $adminId): array
{
    $stmt = $pdo->prepare("SELECT r.*, f.file_name FROM requests r JOIN files f ON r.file_id = f.id WHERE r.id=?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if ($req['current_status'] !== 'File Retrieved') {
        return ['ok' => false, 'error' => 'Invalid status for release.'];
    }

    $dueDate = calculateDueDate($pdo, date('Y-m-d'), 3);

    $pdo->prepare("
        UPDATE requests
        SET current_status='Released',
            status='active',
            released_at = NOW(),
            due_date = ?
        WHERE id=?
    ")->execute([$dueDate, $requestId]);

    $pdo->prepare("UPDATE files SET status='borrowed' WHERE id=?")->execute([$req['file_id']]);
    logHistory($pdo, $requestId, $req['file_id'], 'Released', $adminId);

    $stmtUser = $pdo->prepare("SELECT email FROM users WHERE id=?");
    $stmtUser->execute([$req['user_id']]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($user && !empty($user['email'])) {
        $fileName = htmlspecialchars($req['file_name']);
        $subject = 'File Ready for Collection';
        $message = "
        <div style='font-family:Arial, Helvetica, sans-serif; background:#f4f6f8; padding:20px;'>
            <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;'>
                <div style='background:#1e293b; color:#ffffff; padding:16px 24px; font-size:18px; font-weight:bold;'>
                    VTS e-Library System
                </div>
                <div style='padding:24px; color:#334155; font-size:14px;'>
                    <p style='margin-top:0;'>Your requested file is now ready for collection.</p>
                    <table style='width:100%; border-collapse:collapse; margin-top:15px;'>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; width:35%; font-weight:bold;'>File Name</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$fileName}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Request ID</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$requestId}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px; background:#f8fafc; border:1px solid #e2e8f0; font-weight:bold;'>Due Date</td>
                            <td style='padding:8px; border:1px solid #e2e8f0;'>{$dueDate}</td>
                        </tr>
                    </table>
                    <p style='margin-top:20px;'>
                        Please collect the file from the archive counter. Kindly ensure the file is returned before the due date.
                    </p>
                </div>
                <div style='background:#f8fafc; padding:14px 24px; font-size:12px; color:#64748b; text-align:center;'>
                    This is an automated message from <b>VTS e-Library System</b>.
                </div>
            </div>
        </div>
        ";
        sendEmail($user['email'], $subject, $message);
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool, error?:string}
 */
function processAssignRestoration(PDO $pdo, int $requestId, int $staffId, int $adminId): array
{
    $stmt = $pdo->prepare("SELECT r.*, f.file_name FROM requests r JOIN files f ON r.file_id = f.id WHERE r.id=?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if ($req['current_status'] !== 'Return Requested') {
        return ['ok' => false, 'error' => 'Invalid status for assign restoration.'];
    }
    if (empty($staffId)) {
        return ['ok' => false, 'error' => 'Staff required.'];
    }

    $pdo->prepare("
        UPDATE requests
        SET current_status='Restoration Assigned',
            assigned_to=?,
            retrieval_assigned_at=NOW()
        WHERE id=?
    ")->execute([$staffId, $requestId]);
    logHistory($pdo, $requestId, $req['file_id'], 'Restoration Assigned', $adminId);

    return ['ok' => true];
}

/**
 * @return array{ok:bool, error?:string}
 */
function processCompleteTransaction(PDO $pdo, int $requestId, int $adminId): array
{
    $stmt = $pdo->prepare("SELECT r.*, f.file_name FROM requests r JOIN files f ON r.file_id = f.id WHERE r.id=?");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if ($req['current_status'] !== 'File Restored') {
        return ['ok' => false, 'error' => 'Invalid status for complete.'];
    }

    $pdo->prepare("
        UPDATE requests
        SET current_status='Completed',
            status='returned',
            return_date = COALESCE(return_date, NOW()),
            updated_at=NOW()
        WHERE id=?
    ")->execute([$requestId]);

    $pdo->prepare("UPDATE files SET status='available' WHERE id=?")->execute([$req['file_id']]);
    logHistory($pdo, $requestId, $req['file_id'], 'Completed', $adminId);

    return ['ok' => true];
}

/**
 * Normalize POST request_ids[] into a list of positive ints.
 */
function normalizeRequestIds($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}
