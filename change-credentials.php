<?php
require_once 'config/db.php';
require_once 'config/functions.php';
require_once 'includes/header.php'; 

// --- SECURITY: REDIRECT IF NOT LOGGED IN ---
if (!isLoggedIn()) {
    redirect('index.php');
}

 $user_id = $_SESSION['user_id'];
 $current_username = $_SESSION['username'];
 $error = '';
 $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Get inputs
    $new_username = trim($_POST['new_username']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // 2. Verify Current Password (Security Check)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user['password'])) {
        $error = "Incorrect current password. Changes not saved.";
    } else {
        $updates = [];
        $params = [];
        $log_details = [];
        $requires_update = false;

        // 3. Check Username Change
        if ($new_username !== $current_username) {
            // Check if new username already exists for another user
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check_stmt->execute([$new_username, $user_id]);
            
            if ($check_stmt->fetch()) {
                $error = "Username '{$new_username}' is already taken.";
            } else {
                $updates[] = "username = ?";
                $params[] = $new_username;
                $log_details['old_username'] = $current_username;
                $log_details['new_username'] = $new_username;
                $_SESSION['username'] = $new_username; // Update session immediately
                $current_username = $new_username; // Update variable for display
                $requires_update = true;
            }
        }

        // 4. Check Password Change
        if (!empty($new_password)) {
            if (strlen($new_password) < 6) {
                $error = "New password must be at least 6 characters.";
            } elseif ($new_password !== $confirm_password) {
                $error = "New passwords do not match.";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updates[] = "password = ?";
                $params[] = $hashed_password;
                $log_details['password_changed'] = true;
                $requires_update = true;
            }
        }

        // 5. Execute Update if no errors and changes exist
        if (empty($error) && $requires_update) {
            $params[] = $user_id;
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $update_stmt = $pdo->prepare($sql);
            if ($update_stmt->execute($params)) {
                $success = "Credentials updated successfully.";
                
                // Log the action
                $log = $pdo->prepare("INSERT INTO audit_logs (user_id, action, timestamp, details) VALUES (?, 'Changed Credentials', NOW(), ?)");
                $log->execute([$user_id, json_encode($log_details)]);
            } else {
                $error = "Database error. Please try again.";
            }
        } elseif (empty($error) && !$requires_update) {
            $error = "No changes detected.";
        }
    }
}

?>

    <div class="form-container">
        <div class="form-header">
            <h2>Account Settings</h2>
            <p>Update your login credentials.</p>
        </div>

        <?php if($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="success-box"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <!-- Username Section -->
            <div class="input-group">
                <label>New Username</label>
                <div class="input-wrapper">
                    <input type="text" name="new_username" value="<?= htmlspecialchars($current_username) ?>" required>
                </div>
            </div>

            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

            <!-- Password Section -->
            <div class="input-group">
                <label>Current Password</label>
                <div class="input-wrapper">
                    <input type="password" name="current_password" placeholder="Enter current password" required>
                </div>
            </div>

            <div class="input-group">
                <label>New Password</label>
                <div class="input-wrapper">
                    <input type="password" name="new_password" placeholder="Leave blank to keep current">
                </div>
                <span class="note-text">Min 6 characters. Leave blank if you don't want to change it.</span>
            </div>

            <div class="input-group">
                <label>Confirm New Password</label>
                <div class="input-wrapper">
                    <input type="password" name="confirm_password" placeholder="Confirm new password">
                </div>
            </div>

            <div class="btn-group">
                <a href="<?= $_SESSION['active_role'] ?? '' ?>/dashboard.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>

    <style>

        .form-container {
            max-width: 450px;
            margin: 50px auto;
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .form-header { margin-bottom: 25px; text-align: center; }
        .form-header h2 { margin: 0; color: #333; }
        .form-header p { color: #666; font-size: 0.9rem; }
        
        .input-group { margin-bottom: 20px; }
        .input-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #444; }
        
        .input-wrapper {
            position: relative;
        }
        .input-wrapper input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
            box-sizing: border-box; /* Critical for padding */
        }
        .input-wrapper input:focus {
            border-color: #0056b3;
            outline: none;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        .btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background-color: #0056b3; color: white; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn:hover { opacity: 0.9; }

        .error-box { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 0.9rem; }
        .success-box { color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 0.9rem; }

        .note-text { font-size: 0.85rem; color: #888; margin-top: 5px; }
    </style>
</html>