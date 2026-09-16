<?php
/**
 * Insights usage tracking migration
 *
 * Adds last-viewed timestamps to practice_users so the internal Practice
 * Admin tools can show when each member most recently opened the Practice
 * Insights and Lab Insights screens in that practice.
 *
 * Columns:
 *   practice_users.practice_insights_viewed_at  DATETIME NULL
 *   practice_users.lab_insights_viewed_at       DATETIME NULL
 *
 * Both columns are written with UTC_TIMESTAMP() (explicit UTC, independent of
 * the connection time_zone) and are returned to the frontend as ISO 8601
 * values with a +00:00 offset. NULL means "not yet recorded" - no historical
 * data is invented and nothing is backfilled. This migration is idempotent
 * and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the insights usage tracking migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runInsightsUsageTrackingMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    $columns = [
        'practice_insights_viewed_at' => 'DATETIME DEFAULT NULL',
        'lab_insights_viewed_at' => 'DATETIME DEFAULT NULL',
    ];

    foreach ($columns as $column => $definition) {
        try {
            $quotedCol = $pdo->quote($column);
            $stmt = $pdo->query("SHOW COLUMNS FROM practice_users LIKE {$quotedCol}");
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE practice_users ADD COLUMN {$column} {$definition}");
                $performed[] = "Added practice_users.{$column}";
            } else {
                $performed[] = "practice_users.{$column} already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "practice_users.{$column}: " . $e->getMessage();
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

    $result = runInsightsUsageTrackingMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
