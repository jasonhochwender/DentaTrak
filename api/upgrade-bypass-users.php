<?php
/**
 * One-time migration to upgrade existing billing bypass users
 * 
 * Run this once to upgrade any existing users with bypass email patterns
 * to the control tier. After running, new users will be auto-upgraded on signup/login.
 * 
 * Access: Development environment only, or super users with dev tools access
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/billing-bypass.php';

header('Content-Type: application/json');

// Check access
$userEmail = $_SESSION['user_email'] ?? '';
if (!canAccessDevTools($appConfig, $userEmail)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

// Run the migration
$result = upgradeExistingBypassUsers($pdo);

echo json_encode([
    'success' => true,
    'message' => "Upgraded {$result['upgraded']} users to control tier",
    'upgraded_count' => $result['upgraded'],
    'upgraded_emails' => $result['emails'],
    'bypass_patterns' => BILLING_BYPASS_PATTERNS
]);
