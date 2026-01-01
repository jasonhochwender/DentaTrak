<?php
/**
 * Save Tour Completed API Endpoint
 * Marks the user's tour as completed so it won't show again
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = $_SESSION['db_user_id'];

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$tourCompleted = isset($input['tourCompleted']) ? (bool)$input['tourCompleted'] : true;

try {
    // $pdo is already available from appConfig.php
    
    // Check if user_preferences table has tour_completed column (MySQL compatible)
    $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'tour_completed'");
    if ($stmt->rowCount() === 0) {
        // Add the column if it doesn't exist
        $pdo->exec("ALTER TABLE user_preferences ADD COLUMN tour_completed TINYINT(1) DEFAULT 0");
    }
    
    // Update or insert the tour_completed status (MySQL compatible)
    $stmt = $pdo->prepare("
        INSERT INTO user_preferences (user_id, tour_completed)
        VALUES (:user_id, :tour_completed)
        ON DUPLICATE KEY UPDATE tour_completed = VALUES(tour_completed)
    ");
    
    $stmt->execute([
        ':user_id' => $userId,
        ':tour_completed' => $tourCompleted ? 1 : 0
    ]);
    
    // Update session
    $_SESSION['tour_completed'] = $tourCompleted;
    
    echo json_encode([
        'success' => true,
        'message' => 'Tour completion status saved'
    ]);
    
} catch (PDOException $e) {
    error_log('Error saving tour completion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}
