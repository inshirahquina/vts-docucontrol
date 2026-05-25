<?php
define('BASE_URL', '/vts_library/');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer/src/Exception.php';

// 2. Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
        return true;
    }
    
    if (isset($_SESSION['active_role']) && $_SESSION['active_role'] == 'admin') {
        return true;
    }

    return false;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function sanitize($data) {
    // FIX: Handle NULL values first
    if ($data === null) {
        return '';
    }
    
    // Handle arrays recursively (useful for checkboxes)
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }

    // Now safe to run strip_tags
    return htmlspecialchars(strip_tags($data));
}

function format_date($date) {
    if (empty($date) || $date == '0000-00-00') {
        return "N/A";
    }
    return date('M d, Y', strtotime($date));
}

function logAudit($pdo, $data) {
    $sql = "INSERT INTO audit_logs 
            (user_id, request_id, status, file_name, department, role, action, details)
            VALUES
            (:user_id, :request_id, :status, :file_name, :department, :role, :action, :details)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}

function mapPerformedBy($user_id, $performed_role = null) {
    global $pdo;
    
    // Handle cases where user_id might be null or 0
    if (empty($user_id)) {
        return 'System';
    }

    $stmt = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Prefer full_name, fallback to username
        $name = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
        return $name . ($performed_role ? " ($performed_role)" : '');
    }
    return 'Unknown User';
}

function addFileHistory($pdo, $file_id, $request_id, $action, $user_id = null, $role = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Get user_id
    if (empty($user_id)) {
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
            $user_id = $_SESSION['user_id'];
        } elseif ($request_id) {
            $stmt = $pdo->prepare("SELECT user_id FROM requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($request && !empty($request['user_id'])) {
                $user_id = $request['user_id'];
            }
        }
    }
    
    // Get role
    if (empty($role)) {
        $role = $_SESSION['active_role'] ?? $_SESSION['role'] ?? 'Unknown';
    }
    
    if (empty($user_id) || $user_id == 0) {
        return false; 
    }

    $stmt = $pdo->prepare("INSERT INTO file_history
        (file_id, request_id, action, performed_by, performed_role, created_at)
        VALUES (:file_id, :request_id, :action, :performed_by, :performed_role, NOW())");

    try {
        return $stmt->execute([
            ':file_id' => $file_id,
            ':request_id' => $request_id,
            ':action' => $action,
            ':performed_by' => $user_id,
            ':performed_role' => $role
        ]);
    } catch (PDOException $e) {
        error_log("ERROR inserting file_history: " . $e->getMessage());
        return false;
    }
}

function buildQueryString($overrides = []) {
    $query = $_GET; 
    foreach ($overrides as $key => $value) {
        $query[$key] = $value; 
    }
    return http_build_query($query);
}

function calculateDueDate($pdo, $startDate, $days = 3){

    $stmt = $pdo->query("SELECT holiday_date FROM holidays");
    $holidays = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $date = new DateTime($startDate);
    $added = 0;

    while ($added < $days) {

        $date->modify('+1 day');

        $isWeekend = $date->format('N') >= 6;
        $isHoliday = in_array($date->format('Y-m-d'), $holidays);

        if (!$isWeekend && !$isHoliday) {
            $added++;
        }
    }

    return $date->format('Y-m-d');
}

function sendEmail($to,$subject,$body){

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host = 'mail.vtsgroup.com.my';

        $mail->SMTPAuth = true;
        $mail->Username = 'elibrary@vtsgroup.com.my';
        // $mail->Password = '(mail password)';

        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        $mail->setFrom('elibrary@vtsgroup.com.my','VTS e-Library');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();

    } catch (Exception $e) {
        error_log("Mail error: " . $mail->ErrorInfo);
    }
}
?>