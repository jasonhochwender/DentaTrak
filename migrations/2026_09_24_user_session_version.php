<?php
/**
 * User session-version migration.
 *
 * Adds users.session_version: a monotonic per-user counter stamped into
 * authenticated sessions as $_SESSION['auth_version'] at login. Account-wide
 * session revocation increments the counter; every authenticated request
 * compares its stamped version against the user row, so sessions created
 * before a revocation event stop working immediately. Existing rows default
 * to 0 - sessions that predate this feature carry no stamp and are treated
 * as version 0, so migration forces no logout until the first revocation.
 * Idempotent: safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the session-version migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runSessionVersionMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    try {
        $q = $pdo->quote('session_version');
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE {$q}")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0");
            $performed[] = "Added users.session_version";
        } else {
            $performed[] = "users.session_version already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "users.session_version: " . $e->getMessage();
    }

    return [
        'success' => empty($errors),
        'performed' => $performed,
        'errors' => $errors,
    ];
}

// If this file is invoked directly (CLI or HTTP), execute the migration and
// report. When required by another script, only the function is exposed.
$isMigrationEntryPoint = PHP_SAPI === 'cli'
    || (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));

if ($isMigrationEntryPoint) {
    if (PHP_SAPI !== 'cli') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
        $testMode = getEnvVar('DENTATRAK_TEST_MODE', 'false') === 'true'
            || ($appConfig['test_mode'] ?? false) === true
            || $environment === 'development';
        $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

        if (!$testMode && !$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: admin or test mode required']);
            exit;
        }
    }

    $result = runSessionVersionMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
