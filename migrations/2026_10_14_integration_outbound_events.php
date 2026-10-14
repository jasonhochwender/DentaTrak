<?php
/**
 * Integration outbound events migration (Phase 2 - two-way sync foundation)
 *
 * Creates the durable outbound queue used by IntegrationOutbound. Where
 * integration_external_events records INBOUND deliveries initiated by the
 * PMS, this table records DentaTrak-originated changes that must be pushed
 * to the PMS (Open Dental LabCase writes first).
 *
 * CONTRACT:
 *  - dedup_key is a deterministic content hash of
 *    (connection, operation, entity, payload); UNIQUE(connection_id,
 *    dedup_key) makes enqueue idempotent - the same local change can never
 *    produce two remote writes.
 *  - payload_json / expected_remote_json carry only non-PHI, non-credential
 *    scalar maps (SyncEngine::assertDetailSafe is enforced at enqueue).
 *    Lifecycle timestamps and external IDs only - never names, notes, or
 *    instruction text.
 *  - status drives the queue lifecycle:
 *      queued      accepted, awaiting delivery (next_retry_at throttles)
 *      processing  claimed by a worker (crash-safe, reclaimed when stale)
 *      delivered   remote write confirmed by the provider API
 *      superseded  a newer queued change for the same entity replaced this
 *                  one (payload merged forward; this row is audit history)
 *      conflict    remote state diverged from expected_remote_json - a
 *                  human must reconcile; never auto-retried, never written
 *      skipped     deliberately not written (disabled flag, unmapped case,
 *                  deleted source record, plan suspension)
 *      failed      terminal failure (auth/permission/bad data)
 *  - practice_id is denormalized for defense-in-depth: every worker read
 *    re-verifies it against the connection's practice before writing.
 *  - origin records which side produced the change ('dentatrak' today;
 *    'integration' reserved so a future inbound-derived write is
 *    distinguishable and suppressible).
 *  - Idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

function runIntegrationOutboundEventsMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    $create = "CREATE TABLE IF NOT EXISTS integration_outbound_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        entity_type VARCHAR(32) NOT NULL DEFAULT 'case',
        internal_entity_id VARCHAR(64) NOT NULL,
        external_entity_id VARCHAR(128) DEFAULT NULL,
        operation VARCHAR(32) NOT NULL,
        payload_json TEXT DEFAULT NULL,
        expected_remote_json TEXT DEFAULT NULL,
        origin VARCHAR(24) NOT NULL DEFAULT 'dentatrak',
        actor_user_id INT UNSIGNED DEFAULT NULL,
        dedup_key VARCHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'queued',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT DEFAULT NULL,
        next_retry_at DATETIME DEFAULT NULL,
        claimed_at DATETIME DEFAULT NULL,
        processed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_outbound_dedup (connection_id, dedup_key),
        INDEX idx_outbound_due (status, next_retry_at),
        INDEX idx_outbound_entity (connection_id, entity_type, internal_entity_id, status),
        INDEX idx_outbound_practice (practice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($create);
        $performed[] = "Ensured integration_outbound_events table";
    } catch (PDOException $e) {
        $errors[] = "integration_outbound_events: " . $e->getMessage();
    }

    // FK best-effort: deleting a connection removes its outbound queue.
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_ioe_connection' AND TABLE_NAME = 'integration_outbound_events'");
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE integration_outbound_events ADD CONSTRAINT fk_ioe_connection FOREIGN KEY (connection_id) REFERENCES integration_connections(id) ON DELETE CASCADE");
            $performed[] = "Added foreign key: fk_ioe_connection";
        }
    } catch (PDOException $e) {
        $warnings[] = "FK fk_ioe_connection: " . $e->getMessage();
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

    $result = runIntegrationOutboundEventsMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
