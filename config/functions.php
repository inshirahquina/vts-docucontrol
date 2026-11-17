
<?php

// =====================================================
// 1. APPLICATION BASE URL
// =====================================================

if (!defined('BASE_URL')) {

    $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath($_SERVER['DOCUMENT_ROOT'])
        : false;

    $appRoot = realpath(__DIR__ . '/..');

    $base = '/';

    if ($docRoot && $appRoot && strpos($appRoot, $docRoot) === 0) {

        $rel = str_replace(
            '\\',
            '/',
            substr($appRoot, strlen($docRoot))
        );

        $rel = '/' . trim($rel, '/');

        $base = ($rel === '/' ? '/' : $rel . '/');
    }

    define('BASE_URL', $base);
}


// =====================================================
// 2. PHPMailer CONFIGURATION
// =====================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';


// =====================================================
// 3. SESSION
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// =====================================================
// 4. AUTHENTICATION FUNCTIONS
// =====================================================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}


function isAdmin() {

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (
        isset($_SESSION['role']) &&
        $_SESSION['role'] == 'admin'
    ) {
        return true;
    }

    if (
        isset($_SESSION['active_role']) &&
        $_SESSION['active_role'] == 'admin'
    ) {
        return true;
    }

    return false;
}


function redirect($url) {
    header("Location: $url");
    exit;
}


// =====================================================
// 5. DATA SANITIZATION
// =====================================================

function sanitize($data) {

    if ($data === null) {
        return '';
    }

    if (is_array($data)) {
        return array_map('sanitize', $data);
    }

    return htmlspecialchars(strip_tags((string) $data));
}


// =====================================================
// 6. DATE FORMATTING
// =====================================================

function format_date($date) {

    if (empty($date) || $date == '0000-00-00') {
        return "N/A";
    }

    return date('M d, Y', strtotime($date));
}


// =====================================================
// 7. AUDIT LOG
// =====================================================

function logAudit($pdo, $data) {

    $sql = "INSERT INTO audit_logs
            (
                user_id,
                request_id,
                status,
                file_name,
                department,
                role,
                action,
                details
            )
            VALUES
            (
                :user_id,
                :request_id,
                :status,
                :file_name,
                :department,
                :role,
                :action,
                :details
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}


// =====================================================
// 8. MAP PERFORMED BY
// =====================================================

function mapPerformedBy($user_id, $performed_role = null) {

    global $pdo;

    if (empty($user_id)) {
        return 'System';
    }

    $stmt = $pdo->prepare(
        "SELECT username, full_name FROM users WHERE id = ?"
    );

    $stmt->execute([$user_id]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {

        $name = !empty($user['full_name'])
            ? $user['full_name']
            : $user['username'];

        return $name . ($performed_role ? " ($performed_role)" : '');
    }

    return 'Unknown User';
}


// =====================================================
// 9. FILE HISTORY
// =====================================================

function addFileHistory(
    $pdo,
    $file_id,
    $request_id,
    $action,
    $user_id = null,
    $role = null
) {

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Get user ID
    if (empty($user_id)) {

        if (
            isset($_SESSION['user_id']) &&
            $_SESSION['user_id'] > 0
        ) {
            $user_id = $_SESSION['user_id'];

        } elseif ($request_id) {

            $stmt = $pdo->prepare(
                "SELECT user_id FROM requests WHERE id = ?"
            );

            $stmt->execute([$request_id]);

            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($request && !empty($request['user_id'])) {
                $user_id = $request['user_id'];
            }
        }
    }

    // Get role
    if (empty($role)) {

        $role = $_SESSION['active_role']
            ?? $_SESSION['role']
            ?? 'Unknown';
    }

    if (empty($user_id) || $user_id == 0) {
        return false;
    }

    try {

        $sql = "INSERT INTO file_history
                (
                    file_id,
                    request_id,
                    action,
                    performed_by,
                    performed_role,
                    created_at
                )
                VALUES
                (
                    :file_id,
                    :request_id,
                    :action,
                    :performed_by,
                    :performed_role,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([
            ':file_id'       => $file_id,
            ':request_id'    => $request_id,
            ':action'        => $action,
            ':performed_by'  => $user_id,
            ':performed_role'=> $role
        ]);

    } catch (PDOException $e) {

        error_log(
            "ERROR inserting file_history: " . $e->getMessage()
        );

        return false;
    }
}


// =====================================================
// 10. BUILD QUERY STRING
// =====================================================

function buildQueryString($overrides = []) {

    $query = $_GET;

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    return http_build_query($query);
}


// =====================================================
// 11. CALCULATE DUE DATE
// =====================================================

function calculateDueDate($pdo, $startDate, $days = 3) {

    $stmt = $pdo->query(
        "SELECT holiday_date FROM holidays"
    );

    $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $date = new DateTime($startDate);

    $added = 0;

    while ($added < $days) {

        $date->modify('+1 day');

        $isWeekend = $date->format('N') >= 6;

        $isHoliday = in_array(
            $date->format('Y-m-d'),
            $holidays
        );

        if (!$isWeekend && !$isHoliday) {
            $added++;
        }
    }

    return $date->format('Y-m-d');
}


// =====================================================
// 12. EMAIL ACTIVITY LOGGING
// =====================================================

function logEmailActivity(
    $recipient,
    $subject,
    $status,
    $error = null,
    $emailType = null
) {

    global $pdo;

    try {

        if (!isset($pdo)) {
            throw new RuntimeException(
                'Database connection ($pdo) is unavailable.'
            );
        }

        $sql = "INSERT INTO email_logs
                (
                    recipient,
                    subject,
                    email_type,
                    status,
                    error_message,
                    sent_at
                )
                VALUES
                (
                    :recipient,
                    :subject,
                    :email_type,
                    :status,
                    :error_message,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':recipient'     => $recipient,
            ':subject'       => $subject,
            ':email_type'    => $emailType,
            ':status'        => $status,
            ':error_message' => $error
        ]);

        return true;

    } catch (Throwable $e) {

        error_log(
            'Email log insertion failed: ' . $e->getMessage()
        );

        return false;
    }
}


// =====================================================
// 13. SEND EMAIL + RECORD ACTIVITY
// =====================================================

function sendEmail($to, $subject, $body, $emailType = null) {

    error_log(
        '[sendEmail] to=[' . $to . '] subject=[' . $subject . ']'
    );

    $mail = new PHPMailer(true);

    try {

        // SMTP configuration
        $mail->isSMTP();

        $mail->Host = 'mail.vtsgroup.com.my';

        $mail->SMTPAuth = true;

        $mail->Username = 'elibrary@vtsgroup.com.my';

        $smtpPassword = '3Lry@vTs*!';

        if ($smtpPassword === false || $smtpPassword === '') {
            throw new RuntimeException(
                'SMTP password environment variable is not configured.'
            );
        }

        $mail->Password = $smtpPassword;

        $mail->SMTPSecure = 'ssl';

        $mail->Port = 465;


        $mail->setFrom(
            'elibrary@vtsgroup.com.my',
            'VTS e-Library'
        );

        $mail->addAddress($to);


        // Email content
        $mail->isHTML(true);

        $mail->Subject = $subject;

        $mail->Body = $body;


        // Send email
        $mail->send();


        // Record successful email
        logEmailActivity(
            $to,
            $subject,
            'SUCCESS',
            null,
            $emailType
        );

        return true;


    } catch (Throwable $e) {

        $error = $mail->ErrorInfo ?: $e->getMessage();

        error_log(
            'sendEmail failed: ' . $error .
            ' | to=' . $to .
            ' | subject=' . $subject
        );


        // Record failed email
        logEmailActivity(
            $to,
            $subject,
            'FAILED',
            $error,
            $emailType
        );

        return false;
    }
}

?>
