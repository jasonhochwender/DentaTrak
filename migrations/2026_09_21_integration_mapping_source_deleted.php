<?php
/**
 * Integration entity mapping source_deleted_at migration
 *
 * Adds source_deleted_at to integration_entity_mappings so a LabCase
 * reported deleted upstream (WatchTable: LabCaseDeleted event, or a 404 on
 * fetch) is recorded on the mapping without deleting the DentaTrak case.
 * The marker doubles as the idempotency guard: only the first delete event
 * writes the case activity entry.
 *
 * Idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

function runIntegrationMappingSourceDeletedMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'integration_entity_mappings'
              AND COLUMN_NAME = 'source_deleted_at'
        ");
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE integration_entity_mappings ADD COLUMN source_deleted_at DATETIME DEFAULT NULL AFTER last_seen_at");
            $performed[] = "Added column: integration_entity_mappings.source_deleted_at";
        }
    } catch (PDOException $e) {
        $errors[] = "source_deleted_at column: " . $e->getMessage();
    }

    return [
        'success' => empty($errors),
        'performed' => $performed,
        'warnings' => $warnings,
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

    $result = runIntegrationMappingSourceDeletedMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
