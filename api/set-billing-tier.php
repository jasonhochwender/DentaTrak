<?php
/**
 * Set Billing Tier API Endpoint
 * Updates the user's billing tier
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
    echo json_encode(['success' => false, 'message' => 'Not authorized to change billing tier']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$billingTier = $input['billing_tier'] ?? '';

if (empty($billingTier)) {
    echo json_encode(['success' => false, 'message' => 'Billing tier is required']);
    exit;
}

// Validate billing tier - accept both lowercase and capitalized
$validTiers = ['evaluate', 'operate', 'control', 'Evaluate', 'Operate', 'Control'];
if (!in_array($billingTier, $validTiers)) {
    echo json_encode(['success' => false, 'message' => 'Invalid billing tier: ' . $billingTier]);
    exit;
}

// Convert to lowercase for database
$billingTier = strtolower($billingTier);

try {
    // Update user's billing tier
    $stmt = $pdo->prepare("UPDATE users SET billing_tier = ? WHERE id = ?");
    $result = $stmt->execute([$billingTier, $_SESSION['db_user_id']]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Billing tier updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update billing tier'
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
    
    error_log('Error setting billing tier: ' . $e->getMessage());
}
?>
