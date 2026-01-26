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
