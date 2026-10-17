<?php
/**
 * Scanner file role migration
 *
 * Adds scanner_scan_files.file_role - the OPTIONAL vendor-declared role of a
 * file within a scan (e.g. 'upper', 'lower', 'bite' when the source states
 * it explicitly). NULL when the vendor did not provide one; the pipeline
 * never infers clinical meaning from filenames.
 *
 * Idempotent and non-destructive.
 */

require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

function runScannerFileRoleMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];

    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'scanner_scan_files'
              AND COLUMN_NAME = 'file_role'
        ");
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE scanner_scan_files ADD COLUMN file_role VARCHAR(64) DEFAULT NULL AFTER content_type");
            $performed[] = "Added scanner_scan_files.file_role";
        }
    } catch (PDOException $e) {
        $errors[] = "scanner_scan_files.file_role: " . $e->getMessage();
    }

    return [
        'success' => empty($errors),
        'performed' => $performed,
        'errors' => $errors,
    ];
}

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

    $result = runScannerFileRoleMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
