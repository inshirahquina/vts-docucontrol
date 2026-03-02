<?php
require_once 'config/db.php';
require_once 'config/functions.php';

// --- REDIRECT IF LOGGED IN ---
if (isLoggedIn() && isset($_SESSION['active_role'])) {
    $active = $_SESSION['active_role'];
    switch ($active) {
        case 'requestor':
            redirect('requestor/dashboard.php');
            break;
        case 'hod':
            redirect('hod/dashboard.php');
            break;
        case 'admin':
            redirect('admin/dashboard.php');
            break;
        case 'operations':
            redirect('operations/dashboard.php');
            break;
        default:
            session_destroy();
            redirect('index.php');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {

        // --- SESSION SETUP ---
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role']; // base role

        // --- ACTIVE ROLE LOGIC ---
        $dbRole = $user['role'];
        $dbActive = $user['active_role'];

        if ($dbRole === 'requestor') {
            $_SESSION['active_role'] = 'requestor';

        } elseif ($dbRole === 'hod') {
            $_SESSION['active_role'] = $dbActive ?: 'hod';
            if (!$dbActive) {
                $update = $pdo->prepare("UPDATE users SET active_role = 'hod' WHERE id = ?");
                $update->execute([$user['id']]);
            }

        } elseif ($dbRole === 'admin') {
            $_SESSION['active_role'] = $dbActive ?: 'admin';
            if (!$dbActive) {
                $update = $pdo->prepare("UPDATE users SET active_role = 'admin' WHERE id = ?");
                $update->execute([$user['id']]);
            }

        } elseif ($dbRole === 'operations') {
            $_SESSION['active_role'] = $dbActive ?: 'operations';
            if (!$dbActive) {
                $update = $pdo->prepare("UPDATE users SET active_role = 'operations' WHERE id = ?");
                $update->execute([$user['id']]);
            }
        }

        // --- LOG LOGIN ---
        $log = $pdo->prepare("INSERT INTO audit_logs (user_id, action, timestamp, details) VALUES (?, 'User Logged In', NOW(), ?)");
        $log->execute([$user['id'], json_encode(['base_role' => $dbRole, 'active_role' => $_SESSION['active_role']])]);


        switch ($_SESSION['active_role']) {
            case 'requestor':
                redirect('requestor/dashboard.php');
                break;
            case 'hod':
                redirect('hod/dashboard.php');
                break;
            case 'admin':
                redirect('admin/dashboard.php');
                break;
            case 'operations':
                redirect('operations/dashboard.php');
                break;
            default:
                session_destroy();
                redirect('index.php');
        }

    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - VTS DocuControl</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

    <div class="login-container">
        <!-- Left Panel -->
        <div class="left-panel">
            <div class="brand-logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                VTS Group
            </div>
            <div class="welcome-text">
                <h1>Document Control<br>Management System</h1>
                <p>Secure, efficient, and reliable filing management for the VTS Document Control Department.</p>
            </div>
            <div class="footer-info">
                &copy; <?= date("Y") ?> VTS Group Internal
            </div>
        </div>

        <!-- Right Panel -->
        <div class="right-panel">
            <div class="form-header">
                <h2>Welcome Back</h2>
                <p>Please enter your credentials to access the system.</p>
            </div>

            <?php if($error): ?>
                <div class="error-box">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <?= $error ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>Username / Email</label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input type="text" name="username" placeholder="e.g. admin or admin@vts.com" required autofocus>
                    </div>
                </div>

                <div class="input-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <input type="password" name="password" placeholder="•••••••••" required>
                    </div>
                </div>

                <button type="submit">Sign In</button>
            </form>
            
            <!-- <div style="margin-top: 20px; text-align: center;">
                <a href="#" style="color: var(--accent); text-decoration: none; font-size: 0.9rem; font-weight: 500;">Forgot password?</a>
            </div> -->
        </div>
    </div>
</body>
</html>