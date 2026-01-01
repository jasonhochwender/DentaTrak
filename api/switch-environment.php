<?php
/**
 * Environment Switcher API
 * Allows switching between development, UAT, and production configuration
 * 
 * This writes to the .env_mode file which is read by appConfig.php
 * Valid values: 'development' (local MAMP), 'uat' (bridge to prod DB), 'production' (Cloud Run)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'User not authenticated'
    ]);
    exit;
}

// Check if user is admin (only admins can switch environment)
$userId = $_SESSION['db_user_id'];
$isAdmin = false;

try {
    require_once __DIR__ . '/appConfig.php';
    
    if (isset($pdo)) {
        $stmt = $pdo->prepare("
            SELECT role 
            FROM practice_users 
            WHERE user_id = :user_id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $_SESSION['current_practice_id'] ?? 0
        ]);
        $role = $stmt->fetchColumn();
        $isAdmin = ($role === 'admin');
    }
} catch (Exception $e) {
    // If we can't check admin status, deny access
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to verify permissions'
    ]);
    exit;
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Only administrators can switch environment'
    ]);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['environment'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Environment is required'
    ]);
    exit;
}

$environment = $data['environment'];

// Valid environments for local switching (production is determined by Cloud Run)
if (!in_array($environment, ['development', 'uat'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid environment. Must be "development" or "uat"'
    ]);
    exit;
}

// Write environment to .env_mode file
$envModeFile = __DIR__ . '/../.env_mode';
$result = file_put_contents($envModeFile, $environment);

if ($result === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to write environment mode file'
    ]);
    exit;
}

// Also store in session for immediate use
$_SESSION['dev_environment'] = $environment;

$envLabel = ($environment === 'development') ? 'Development (Local DB)' : 'UAT (Production DB)';

echo json_encode([
    'success' => true,
    'message' => 'Environment switched to ' . $envLabel,
    'environment' => $environment
]);
?>
