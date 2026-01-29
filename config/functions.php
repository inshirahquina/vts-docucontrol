<?php
// Start session if not started
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
    return htmlspecialchars(strip_tags($data));
}

function format_date($date) {
    return date('M d, Y', strtotime($date));
}

function logAudit($pdo, $data) {
    // Memastikan syntax SQL betul
    $sql = "INSERT INTO audit_logs 
            (user_id, request_id, status, file_name, department, role, action, details)
            VALUES
            (:user_id, :request_id, :status, :file_name, :department, :role, :action, :details)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
}

function mapPerformedBy($user_id, $performed_role = null) {
    global $pdo;

    // Syntax SQL dibaiki
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        return $user['username'] . ($performed_role ? " ($performed_role)" : '');
    }

    return 'Unknown';
}

function addFileHistory($pdo, $file_id, $request_id, $action, $user_id = null, $role = null) {
    // Start session jika belum start
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // DEBUG: Log untuk pastikan data betul
    error_log("=== addFileHistory Called ===");
    error_log("File ID: " . $file_id);
    error_log("Request ID: " . $request_id);
    error_log("Action: " . $action);
    error_log("Provided user_id: " . ($user_id ?? 'NULL'));
    error_log("Provided role: " . ($role ?? 'NULL'));
    
    // AMBIL USER_ID DENGAN TEPAT
    if (empty($user_id)) {
        // 1. Check session dulu
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
            $user_id = $_SESSION['user_id'];
            error_log("Got user_id from SESSION: " . $user_id);
        }
        // 2. Jika tak ada, cuba dapat dari request
        elseif ($request_id) {
            $stmt = $pdo->prepare("SELECT user_id FROM requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($request && !empty($request['user_id'])) {
                $user_id = $request['user_id'];
                error_log("Got user_id from REQUEST: " . $user_id);
            }
        }
    }
    
    // AMBIL ROLE DENGAN TEPAT
    if (empty($role)) {
        // 1. Check session active_role dulu
        if (isset($_SESSION['active_role']) && !empty($_SESSION['active_role'])) {
            $role = $_SESSION['active_role'];
        }
        // 2. Kalau tak ada, guna session role
        elseif (isset($_SESSION['role']) && !empty($_SESSION['role'])) {
            $role = $_SESSION['role'];
        }
        // 3. Default
        else {
            $role = 'Unknown';
        }
        error_log("Got role: " . $role);
    }
    
    // VALIDATION: Pastikan user_id tak 0
    if (empty($user_id) || $user_id == 0) {
        error_log("ERROR: user_id is 0 or empty. Cannot insert history.");
        return false; // JANGAN simpan kalau user_id = 0
    }

    // Insert ke database
    $stmt = $pdo->prepare("INSERT INTO file_history
        (file_id, request_id, action, performed_by, performed_role, created_at)
        VALUES (:file_id, :request_id, :action, :performed_by, :performed_role, NOW())");

    try {
        $result = $stmt->execute([
            ':file_id' => $file_id,
            ':request_id' => $request_id,
            ':action' => $action,
            ':performed_by' => $user_id,  // PASTIKAN ini bukan 0
            ':performed_role' => $role
        ]);
        
        error_log("Insert successful! ID: " . $pdo->lastInsertId());
        return true;
        
    } catch (PDOException $e) {
        error_log("ERROR inserting file_history: " . $e->getMessage());
        return false;
    }
}
?>