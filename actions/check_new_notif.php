<?php
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['has_new' => false]);
    exit;
}

 $uid = $_SESSION['user_id'];
 $last_known_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

 $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
 $stmt->execute([$uid]);
 $latest = $stmt->fetch();

 $response = ['has_new' => false];

if ($latest) {
    // Calculate threshold time (Current time - 10 seconds)
    $threshold_time = time() - 10; 
    
    // Convert DB time to timestamp
    $notif_time = strtotime($latest['created_at']);

    // Robust Check: Is notification time greater than threshold?
    if ($latest['id'] > $last_known_id && $notif_time > $threshold_time) {
        $response = [
            'has_new' => true,
            'message' => $latest['message'],
            'link' => $latest['link'],
            'id' => $latest['id']
        ];
    }
}

echo json_encode($response);
?>