<?php
/**
 * Session Keepalive Endpoint
 * 
 * Lightweight endpoint to keep the PHP session alive during long operations
 * like GCS file uploads. Simply touching this endpoint refreshes the session
 * activity timestamp.
 * 
 * Returns: { "ok": true, "ts": <unix_timestamp> }
 */

// Disable error display for API
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Set JSON header
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/session.php';
    
    // Check if user is authenticated
    if (empty($_SESSION['db_user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    // Session is valid and last_activity was already updated by session.php
    // (ping.php is NOT in the passiveEndpoints list)
    
    echo json_encode([
        'ok' => true,
        'ts' => time()
    ]);
    
} catch (Exception $e) {
    error_log('ping.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
