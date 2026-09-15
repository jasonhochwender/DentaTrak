<?php
/**
 * User environment migration
 *
 * Adds last-observed browser/OS columns to users so the internal Practice
 * Admin tools can show the environment each user was most recently seen with.
 * NULL values mean "not yet recorded" - no historical data is invented. This
 * migration is idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the user environment migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runUserEnvironmentMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    $columns = [
        'last_env_browser' => 'VARCHAR(100) DEFAULT NULL',
        'last_env_os' => 'VARCHAR(100) DEFAULT NULL',
        'last_env_seen_at' => 'DATETIME DEFAULT NULL',
    ];

    foreach ($columns as $column => $definition) {
        try {
            $quotedCol = $pdo->quote($column);
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE {$quotedCol}");
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
                $performed[] = "Added users.{$column}";
            } else {
                $performed[] = "users.{$column} already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "users.{$column}: " . $e->getMessage();
        }
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

    $result = runUserEnvironmentMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
