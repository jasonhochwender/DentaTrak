<?php
/**
 * Practice-wide two-factor authentication enforcement migration.
 *
 * Adds practices.require_2fa (default 0) so a Practice Owner/Admin can
 * require every member of that practice to use two-factor authentication.
 * Existing practices default to OFF - individual user 2FA settings
 * (users.totp_enabled) are never touched by this migration.
 * Idempotent: safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the practice require_2fa migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runPracticeRequire2faMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    try {
        $quoted = function (string $col) use ($pdo) {
            $q = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE {$q}");
            return $stmt && $stmt->rowCount() > 0;
        };

        if (!$quoted('require_2fa')) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN require_2fa TINYINT(1) NOT NULL DEFAULT 0");
            $performed[] = "Added practices.require_2fa";
        } else {
            $performed[] = "practices.require_2fa already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "practices.require_2fa: " . $e->getMessage();
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

    $result = runPracticeRequire2faMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
