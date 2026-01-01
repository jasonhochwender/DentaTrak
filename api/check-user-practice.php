<?php
/**
 * Check if a user is already part of the CURRENT practice
 * 
 * Multi-practice membership is supported - users CAN belong to multiple practices.
 * This endpoint only checks if the user is already in the CURRENT practice to prevent duplicates.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Get current practice ID
$currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
if (!$currentPracticeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No practice selected']);
    exit;
}

// Get the email from the request
$data = json_decode(file_get_contents('php://input'), true);
$email = isset($data['email']) ? trim($data['email']) : '';

if (empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email address is required']);
    exit;
}

try {
    // First check if the user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $userId = $stmt->fetchColumn();
    
    if (!$userId) {
        // User doesn't exist yet, so they're not part of any practice
        // They can be added to the current practice
        echo json_encode([
            'success' => true,
            'exists' => false,
            'inCurrentPractice' => false,
            'inOtherPractices' => false,
            'message' => 'User does not exist yet - can be added'
        ]);
        exit;
    }
    
    // Check if the user is already in the CURRENT practice
    $stmt = $pdo->prepare("
        SELECT pu.role
        FROM practice_users pu
        WHERE pu.user_id = :user_id AND pu.practice_id = :practice_id
    ");
    $stmt->execute([
        'user_id' => $userId,
        'practice_id' => $currentPracticeId
    ]);
    $currentPracticeMembership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentPracticeMembership) {
        // User is already in the CURRENT practice - this is the only case we block
        echo json_encode([
            'success' => true,
            'exists' => true,
            'inCurrentPractice' => true,
            'role' => $currentPracticeMembership['role'],
            'message' => 'This user is already a member of this practice'
        ]);
        exit;
    }
    
    // Check if user is in OTHER practices (informational only, not blocking)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as practice_count
        FROM practice_users pu
        WHERE pu.user_id = :user_id AND pu.practice_id != :practice_id
    ");
    $stmt->execute([
        'user_id' => $userId,
        'practice_id' => $currentPracticeId
    ]);
    $otherPracticeCount = (int)$stmt->fetchColumn();
    
    // User exists but is NOT in the current practice - they can be added
    // (even if they're in other practices - multi-practice membership is allowed)
    echo json_encode([
        'success' => true,
        'exists' => true,
        'inCurrentPractice' => false,
        'inOtherPractices' => $otherPracticeCount > 0,
        'otherPracticeCount' => $otherPracticeCount,
        'message' => 'User can be added to this practice'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    userLog("Error checking user practice status: " . $e->getMessage(), true);
}
?>
