<?php
/**
 * Session Status API
 * Returns session timeout information and allows extending the session
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

// Handle session extension/activity request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'extend' || $action === 'activity') {
        // Extend the session by updating last activity
        $_SESSION['last_activity'] = time();
        
        echo json_encode([
            'success' => true,
            'message' => $action === 'extend' ? 'Session extended' : 'Activity recorded',
            'timeRemaining' => SESSION_TIMEOUT,
            'warningTime' => SESSION_WARNING_TIME
        ]);
        exit;
    }
}

// Check if user is logged in
if (empty($_SESSION['db_user_id'])) {
    echo json_encode([
        'success' => false,
        'loggedIn' => false,
        'message' => 'Not logged in'
    ]);
    exit;
}

// Return session status
$timeRemaining = getSessionTimeRemaining();

echo json_encode([
    'success' => true,
    'loggedIn' => true,
    'timeRemaining' => $timeRemaining,
    'timeout' => SESSION_TIMEOUT,
    'warningTime' => SESSION_WARNING_TIME,
    'showWarning' => $timeRemaining <= SESSION_WARNING_TIME && $timeRemaining > 0
]);
