<?php
/**
 * Test-only helper to force an unexpected exception through the
 * api/admin-practices.php boundary. Only works in the development environment.
 */

require_once __DIR__ . '/../api/session.php';
require_once __DIR__ . '/../api/appConfig.php';

global $appConfig;

// SECURITY: mirror the environment guard in api/test-helpers.php.
$environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if ($environment === 'production') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Test helpers not available in production']);
    exit;
}

$testMode = getenv('DENTATRAK_TEST_MODE') === 'true' ||
            ($appConfig['test_mode'] ?? false) === true ||
            $environment === 'development';

if (!$testMode && $environment !== 'development') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Test mode not enabled']);
    exit;
}

class ForcedErrorPDO extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:');
    }
    public function prepare($query, $options = []) {
        throw new RuntimeException('Forced unexpected database error for testing');
    }
    public function exec($statement) {
        return 0;
    }
}

// Replace the global PDO handle with a mock that fails on every prepare().
$GLOBALS['pdo'] = new ForcedErrorPDO();

$_GET['action'] = 'adoption';
$_GET['practice_id'] = 1;

require_once __DIR__ . '/../api/admin-practices.php';
