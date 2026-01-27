<?php
require_once '../config/db.php';
require_once '../config/functions.php';

// 1. Update Active Role in Session
if (isset($_POST['new_role'])) {
    $_SESSION['active_role'] = $_POST['new_role'];
}

// 2. Redirect to where they came from (Dashboard or Task List)
 $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../staff/dashboard.php';

header("Location: $referer");
exit;
?>