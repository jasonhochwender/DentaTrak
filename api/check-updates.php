<?php
/**
 * Check for Updates API Endpoint
 * Returns any case updates since the last check
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
    echo json_encode(['error' => 'User not authenticated']);
    exit;
}

// Get current practice ID
$currentPracticeId = $_SESSION['current_practice_id'] ?? null;
if (!$currentPracticeId) {
    http_response_code(400);
    echo json_encode(['error' => 'No practice context']);
    exit;
}

// Get the timestamp from the request
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
$currentTime = time() * 1000; // Convert to milliseconds

// Get updates directory
$updatesDir = __DIR__ . '/realtime_updates';
if (!is_dir($updatesDir)) {
    // Create directory if it doesn't exist
    mkdir($updatesDir, 0755, true);
}

// Get the user's update file
$userId = $_SESSION['db_user_id'];
$updateFile = $updatesDir . '/user_' . $userId . '.json';

$updates = [];
if (file_exists($updateFile)) {
    $fileData = json_decode(file_get_contents($updateFile), true) ?: [];
    
    // Filter updates since the requested time
    foreach ($fileData as $update) {
        if ($update['timestamp'] > $since) {
            $updates[] = $update;
        }
    }
    
    // Clean up old updates (keep only last 100)
    if (count($fileData) > 100) {
        $fileData = array_slice($fileData, -100);
        file_put_contents($updateFile, json_encode($fileData));
    }
}

// Return response
echo json_encode([
    'success' => true,
    'updates' => $updates,
    'timestamp' => $currentTime
]);
