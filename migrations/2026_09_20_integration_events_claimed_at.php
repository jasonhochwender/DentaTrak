<?php
/**
 * Integration events claimed_at migration
 *
 * Adds claimed_at to integration_external_events so stale 'processing'
 * recovery is measured from when a worker actually claimed the row, not
 * from received_at (webhook arrival). Without this, an event received
 * >10 minutes ago could be reclaimed by a second worker while the first
 * worker is still mid-flight on it.
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

function runIntegrationEventsClaimedAtMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'integration_external_events'
              AND COLUMN_NAME = 'claimed_at'
        ");
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE integration_external_events ADD COLUMN claimed_at DATETIME DEFAULT NULL AFTER received_at");
            $performed[] = "Added column: integration_external_events.claimed_at";
        }
    } catch (PDOException $e) {
        $errors[] = "claimed_at column: " . $e->getMessage();
    }

    // Rows already mid-'processing' pre-migration have no claimed_at; treat
    // them as stale immediately so nothing is stranded.
    try {
        $pdo->exec("UPDATE integration_external_events SET claimed_at = received_at WHERE status = 'processing' AND claimed_at IS NULL");
    } catch (PDOException $e) {
        $warnings[] = "claimed_at backfill: " . $e->getMessage();
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

    $result = runIntegrationEventsClaimedAtMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
