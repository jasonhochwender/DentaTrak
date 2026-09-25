<?php
/**
 * Ask DentaTrak usage telemetry migration.
 *
 * Adds ask_dentatrak_usage: privacy-conscious product-usage events for the
 * Ask DentaTrak assistant. Rows carry ONLY sanitized classification
 * metadata (category/intent/normalized_topic/tool/outcome/locale/latency)
 * plus ids - never the question text, model prompts/answers, patient or
 * case content, filenames, or tool payloads. This is deliberately separate
 * from phi_access_log (HIPAA audit) - PHI events stay there.
 *
 * Idempotent: safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the Ask DentaTrak usage telemetry migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runAskDentatrakUsageMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ask_dentatrak_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                practice_id INT UNSIGNED NOT NULL,
                locale VARCHAR(10) NULL,
                category VARCHAR(32) NOT NULL,
                intent VARCHAR(32) NULL,
                normalized_topic VARCHAR(64) NULL,
                tool_used VARCHAR(96) NULL,
                outcome VARCHAR(32) NOT NULL,
                latency_ms INT UNSIGNED NULL,
                feedback TINYINT NULL COMMENT '1 = positive, -1 = negative, NULL = none',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_created_at (created_at),
                INDEX idx_practice_created (practice_id, created_at),
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_category_outcome (category, outcome),
                INDEX idx_topic (normalized_topic)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $performed[] = "ask_dentatrak_usage table present";
    } catch (PDOException $e) {
        $errors[] = "ask_dentatrak_usage: " . $e->getMessage();
    }

    // Normalize column types explicitly (idempotent): keeps created_at a
    // plain DATETIME with no implicit ON UPDATE behavior.
    try {
        $pdo->exec("
            ALTER TABLE ask_dentatrak_usage
                MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ");
        $performed[] = "ask_dentatrak_usage.created_at normalized";
    } catch (PDOException $e) {
        $errors[] = "ask_dentatrak_usage.created_at: " . $e->getMessage();
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

    $result = runAskDentatrakUsageMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
