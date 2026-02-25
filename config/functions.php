<?php
// 1. DEFINE BASE URL (Must be at the very top)
// Change '/vts_library/' if your folder name is different
define('BASE_URL', '/vts_library/');

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

/**
 * Helper to build query strings for pagination while preserving filters.
 */
function buildQueryString($overrides = []) {
    $query = $_GET; 
    foreach ($overrides as $key => $value) {
        $query[$key] = $value; 
    }
    return http_build_query($query);
}
?>