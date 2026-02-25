<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../index.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// --- GET USER ---
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

// --- VERIFY LOGIN ---
if (!$user || !password_verify($password, $user['password'])) {
    redirect('../index.php?error=invalid');
    exit;
}

// --- SECURITY ---
session_regenerate_id(true);

// --- SESSION SETUP ---
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];


// =================================================
// ACTIVE ROLE LOGIC (AS ORIGINAL)
// =================================================
$dbRole   = $user['role'];
$dbActive = $user['active_role'];

switch ($dbRole) {

    case 'hod':
        $_SESSION['active_role'] = $dbActive ?: 'hod';
        break;

    case 'staff':
        $_SESSION['active_role'] = $dbActive ?: 'staff';
        break;

    case 'admin':
        $_SESSION['active_role'] = $dbActive ?: 'admin';
        break;

    default: // requestor
        $_SESSION['active_role'] = 'requestor';
        break;
}


// --- OPTIONAL: AUTO UPDATE active_role IF EMPTY ---
if (!$dbActive) {
    $update = $pdo->prepare("UPDATE users SET active_role = ? WHERE id = ?");
    $_updateRole = $_SESSION['active_role'];
    $update->execute([$_updateRole, $user['id']]);
}


// =================================================
// LOG LOGIN
// =================================================
$log = $pdo->prepare("
    INSERT INTO audit_logs (user_id, action, timestamp, details)
    VALUES (?, 'User Logged In', NOW(), ?)
");

$log->execute([
    $user['id'],
    json_encode([
        'base_role'   => $dbRole,
        'active_role' => $_SESSION['active_role']
    ])
]);


// =================================================
// REDIRECT BASED ON ACTIVE ROLE
// =================================================
switch ($_SESSION['active_role']) {

    case 'hod':
        redirect('../hod/dashboard.php');
        break;

    case 'operations':
        redirect('../operations/dashboard.php');
        break;

    case 'admin':
        redirect('../admin/dashboard.php');
        break;

    default:
        redirect('../requestor/dashboard.php');
        break;
}

exit;
?>
