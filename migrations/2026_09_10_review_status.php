<?php
/**
 * Review status migration
 *
 * Adds per-case review state (reviewed_at, reviewed_by_user_id) to cases_cache.
 * A missing reviewed_at represents "Needs Review"; a non-null value means the
 * case has been reviewed. This migration is idempotent and safe to run multiple
 * times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the review status migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runReviewStatusMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    // Add reviewed_at if it is not already present.
    try {
        $quotedCol = $pdo->quote('reviewed_at');
        $stmt = $pdo->query("SHOW COLUMNS FROM cases_cache LIKE {$quotedCol}");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cases_cache ADD COLUMN reviewed_at DATETIME DEFAULT NULL");
            $performed[] = "Added cases_cache.reviewed_at";
        } else {
            $performed[] = "cases_cache.reviewed_at already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "cases_cache.reviewed_at: " . $e->getMessage();
    }

    // Add reviewed_by_user_id if it is not already present.
    try {
        $quotedCol = $pdo->quote('reviewed_by_user_id');
        $stmt = $pdo->query("SHOW COLUMNS FROM cases_cache LIKE {$quotedCol}");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE cases_cache ADD COLUMN reviewed_by_user_id INT UNSIGNED DEFAULT NULL");
            $performed[] = "Added cases_cache.reviewed_by_user_id";
        } else {
            $performed[] = "cases_cache.reviewed_by_user_id already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "cases_cache.reviewed_by_user_id: " . $e->getMessage();
    }

    // Add supporting indexes if they are not already present.
    $indexes = [
        'idx_reviewed_at' => 'reviewed_at',
        'idx_reviewed_by_user_id' => 'reviewed_by_user_id',
    ];

    foreach ($indexes as $indexName => $columnName) {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'cases_cache'
                  AND INDEX_NAME = :name
                LIMIT 1
            ");
            $stmt->execute([':name' => $indexName]);
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE cases_cache ADD INDEX {$indexName} ({$columnName})");
                $performed[] = "Added {$indexName}";
            } else {
                $performed[] = "{$indexName} already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "{$indexName}: " . $e->getMessage();
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

    $result = runReviewStatusMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
