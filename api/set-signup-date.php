<?php
/**
 * Set Signup Date API Endpoint
 * Updates the user's created_at date
 * 
 * Access Control:
 * - Always allowed in development environment
 * - In UAT/Production: Only allowed for super users with dev_tools_enabled
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/dev-tools-access.php';
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Check dev tools access (handles both development and super user in UAT/Prod)
require_once __DIR__ . '/appConfig.php';
$userEmail = $_SESSION['user_email'] ?? '';
if (!canAccessDevTools($appConfig, $userEmail)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized to change signup date']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$signupDate = $input['signup_date'] ?? '';

if (empty($signupDate)) {
    echo json_encode(['success' => false, 'message' => 'Signup date is required']);
    exit;
}

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signupDate)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

try {
    // Update user's created_at date
    $stmt = $pdo->prepare("UPDATE users SET created_at = ? WHERE id = ?");
    $result = $stmt->execute([$signupDate . ' 00:00:00', $_SESSION['db_user_id']]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Signup date updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update signup date'
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
    
    error_log('Error setting signup date: ' . $e->getMessage());
}
?>
