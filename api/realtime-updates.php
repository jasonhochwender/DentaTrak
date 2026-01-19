<?php
/**
 * Real-time Updates Server-Sent Events Endpoint
 * Provides real-time updates for case changes and assignments
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/practice-security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo "User not authenticated";
    exit;
}

// Get current practice ID
$currentPracticeId = $_SESSION['current_practice_id'] ?? null;
if (!$currentPracticeId) {
    http_response_code(400);
    echo "No practice context";
    exit;
}

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Prevent PHP from buffering output
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', 1);
}
ini_set('zlib.output_compression', 0);
ini_set('output_buffering', 'Off');

// Create a unique connection ID for this client
$connectionId = uniqid('sse_', true);
$userId = $_SESSION['db_user_id'];

// Store connection info in a file-based storage (simple approach)
$connectionsDir = __DIR__ . '/sse_connections';
if (!is_dir($connectionsDir)) {
    mkdir($connectionsDir, 0755, true);
}

// Store connection info
$connectionFile = $connectionsDir . '/' . $connectionId . '.json';
file_put_contents($connectionFile, json_encode([
    'userId' => $userId,
    'practiceId' => $currentPracticeId,
    'connectedAt' => time(),
    'lastPing' => time()
]));

// Send initial connection message
echo "id: " . $connectionId . "\n";
echo "event: connected\n";
echo "data: " . json_encode([
    'type' => 'connected',
    'connectionId' => $connectionId,
    'timestamp' => time()
]) . "\n\n";
ob_flush();
flush();

// Keep the connection alive with periodic pings
$lastActivity = time();
while (true) {
    // Check for any pending updates
    $updatesFile = $connectionsDir . '/updates_' . $userId . '.json';
    if (file_exists($updatesFile)) {
        $updates = json_decode(file_get_contents($updatesFile), true) ?: [];
        if (!empty($updates)) {
            foreach ($updates as $update) {
                echo "event: " . $update['event'] . "\n";
                echo "data: " . json_encode($update['data']) . "\n\n";
            }
            // Clear processed updates
            file_put_contents($updatesFile, json_encode([]));
        }
    }
    
    // Send periodic ping to keep connection alive
    if (time() - $lastActivity > 30) {
        echo "event: ping\n";
        echo "data: " . json_encode(['timestamp' => time()]) . "\n\n";
        ob_flush();
        flush();
        $lastActivity = time();
        
        // Update last ping in connection file
        if (file_exists($connectionFile)) {
            $connData = json_decode(file_get_contents($connectionFile), true);
            $connData['lastPing'] = time();
            file_put_contents($connectionFile, json_encode($connData));
        }
    }
    
    // Check if client has disconnected (by checking if connection file still exists)
    if (!file_exists($connectionFile)) {
        break;
    }
    
    // Sleep to prevent excessive CPU usage
    usleep(250000); // 0.25 seconds
}

// Clean up connection file
if (file_exists($connectionFile)) {
    unlink($connectionFile);
}
