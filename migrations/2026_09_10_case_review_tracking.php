<?php
/**
 * Case Review Tracking migration
 *
 * Adds a practice-level toggle for the Reviewed / Needs Review workflow.
 * The setting defaults to OFF so existing practices do not suddenly display
 * review badges after deployment. This migration is idempotent and safe to
 * run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the case review tracking migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runCaseReviewTrackingMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    try {
        $quotedCol = $pdo->quote('case_review_tracking_enabled');
        $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE {$quotedCol}");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN case_review_tracking_enabled TINYINT(1) NOT NULL DEFAULT 0");
            $performed[] = "Added practices.case_review_tracking_enabled";
        } else {
            $performed[] = "practices.case_review_tracking_enabled already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "practices.case_review_tracking_enabled: " . $e->getMessage();
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

    $result = runCaseReviewTrackingMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
