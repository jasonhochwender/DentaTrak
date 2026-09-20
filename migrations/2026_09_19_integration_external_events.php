<?php
/**
 * Integration external events migration (Phase D - Open Dental Events POC)
 *
 * Creates the database-backed deduplication + queue table for inbound PMS
 * event webhooks (Open Dental API Events / WatchTable subscriptions first).
 *
 * WHY A SEPARATE TABLE (not integration_sync_events): sync_events records
 * per-entity diagnostics inside an orchestrated sync run. External events
 * are INBOUND deliveries initiated by the PMS - they arrive outside any
 * sync run, must be deduplicated before processing, and double as the
 * retry queue until a worker phase exists.
 *
 * CONTRACT:
 *  - provider_event_id is a deterministic dedup key derived from the
 *    provider payload (Open Dental supplies no event id), NOT raw PHI.
 *  - No raw payloads, no PHI, no credentials are ever stored here.
 *  - Idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

function runIntegrationExternalEventsMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    // ---------------------------------------------------------------------
    // integration_external_events
    //   UNIQUE(connection_id, provider_event_id) is the idempotency
    //   backbone: a redelivered event inserts nothing and can never be
    //   processed twice. status drives the receive -> process lifecycle:
    //     received           accepted, awaiting processing
    //     processing         claimed by a processor (crash-safe requeue)
    //     processed          downstream retrieval + normalization succeeded
    //     entity_gone        the external record no longer exists (terminal)
    //     failed             terminal failure (auth/bad data) - not retried
    //   next_retry_at throttles retries for transient failures (429 +
    //   Retry-After, network timeouts) without busy-looping.
    // ---------------------------------------------------------------------
    $createEvents = "CREATE TABLE IF NOT EXISTS integration_external_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        provider_event_id VARCHAR(64) NOT NULL,
        external_entity_type VARCHAR(32) NOT NULL,
        external_entity_id VARCHAR(128) NOT NULL,
        event_watermark VARCHAR(64) DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'received',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT DEFAULT NULL,
        next_retry_at DATETIME DEFAULT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME DEFAULT NULL,
        UNIQUE KEY uk_external_event (connection_id, provider_event_id),
        INDEX idx_external_events_due (status, next_retry_at),
        INDEX idx_external_events_connection (connection_id, received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createEvents);
        $performed[] = "Ensured integration_external_events table";
    } catch (PDOException $e) {
        $errors[] = "integration_external_events: " . $e->getMessage();
    }

    // FK best-effort: deleting a connection removes its event queue.
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_iee_connection' AND TABLE_NAME = 'integration_external_events'");
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE integration_external_events ADD CONSTRAINT fk_iee_connection FOREIGN KEY (connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE");
            $performed[] = "Added foreign key: fk_iee_connection";
        }
    } catch (PDOException $e) {
        $warnings[] = "FK fk_iee_connection: " . $e->getMessage();
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

    $result = runIntegrationExternalEventsMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
