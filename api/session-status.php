<?php
/**
 * Session Status API
 * Returns session timeout information and allows extending the session.
 *
 * This is the client-visible endpoint for the inactivity timeout. It is the
 * only endpoint allowed to inspect a session that may already be expired, so
 * it can report the inactivity status to the client. All other endpoints rely
 * on api/session.php to enforce the timeout.
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$timeRemaining = getSessionTimeRemaining();
$isAuthenticated = !empty($_SESSION['db_user_id']);

// Reject requests from a session that is already expired, and never silently
// restore an expired session.
if (!$isAuthenticated || $timeRemaining <= 0) {
    if ($timeRemaining <= 0 && $isAuthenticated) {
        // Destroy the PHP session and clear/invalidate the remember-me token
        // so the client cannot be silently auto-logged in again.
        expireInactivitySession();
    }

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'loggedIn' => false,
        'message' => 'Not logged in',
        'reason' => ($timeRemaining <= 0 && $isAuthenticated) ? 'inactivity' : null,
        'timeRemaining' => 0,
        'timeout' => SESSION_TIMEOUT,
        'warningTime' => SESSION_WARNING_TIME,
    ]);
    exit;
}

// Handle session extension/activity ping
if ($requestMethod === 'POST') {
    $rawInput = file_get_contents('php://input');
    // In CLI tests php://input is not connected to stdin; fall back so the
    // test runner can pipe JSON. This branch is not reachable in a web SAPI.
    if ($rawInput === '' && PHP_SAPI === 'cli') {
        $rawInput = file_get_contents('php://stdin');
    }
    $input = json_decode($rawInput, true) ?: [];
    $action = $input['action'] ?? '';

    if ($action === 'extend' || $action === 'activity') {
        // Record both the server and the explicit user-action timestamps.
        // The JS client sends this ping only for genuine interactions
        // (click, keydown, scroll, touchstart, pointerdown).
        $_SESSION['last_activity'] = time();
        $_SESSION['last_user_action_at'] = time();

        $timeRemaining = getSessionTimeRemaining();
        echo json_encode([
            'success' => true,
            'message' => $action === 'extend' ? 'Session extended' : 'Activity recorded',
            'timeRemaining' => $timeRemaining,
            'timeout' => SESSION_TIMEOUT,
            'warningTime' => SESSION_WARNING_TIME,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// GET: return session status
$showWarning = $timeRemaining > 0 && $timeRemaining <= SESSION_WARNING_TIME;

echo json_encode([
    'success' => true,
    'loggedIn' => true,
    'timeRemaining' => $timeRemaining,
    'timeout' => SESSION_TIMEOUT,
    'warningTime' => SESSION_WARNING_TIME,
    'showWarning' => $showWarning,
]);
