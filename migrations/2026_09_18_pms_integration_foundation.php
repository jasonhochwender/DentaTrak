<?php
/**
 * PMS integration foundation migration (Phase A)
 *
 * Creates the generic, provider-agnostic data model for connecting a
 * DentaTrak practice to an external practice-management system (Open Dental
 * first; Dentrix, Eaglesoft, Curve, etc. later). No provider-specific
 * columns exist anywhere in this schema - Open Dental is just a `provider`
 * string on integration_connections.
 *
 * Idempotent, non-destructive, and safe to run multiple times.
 *
 * Tables created:
 *  - integration_connections     one connected PMS per practice per provider
 *  - integration_credentials     encrypted secrets, separate from config
 *  - integration_entity_mappings external->internal ID map + dedup backbone
 *  - integration_sync_runs       per-run orchestration record
 *  - integration_sync_events     per-entity diagnostics (PHI-free by contract)
 *
 * Foreign keys are attempted where safe; failures are logged, not fatal.
 */

require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

/**
 * Run the PMS integration foundation migration.
 */
function runPmsIntegrationFoundationMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    // ------------------------------------------------------------------------
    // 1. integration_connections
    //    One row per (practice, provider). UNIQUE(practice_id, provider) is
    //    the Phase 1 single-connection-per-provider constraint.
    //    config_json holds NON-SECRET configuration only (base URL, sync
    //    options, field maps). Credentials live in integration_credentials.
    // ------------------------------------------------------------------------
    $createConnections = "CREATE TABLE IF NOT EXISTS integration_connections (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        practice_id INT(10) UNSIGNED NOT NULL,
        provider VARCHAR(32) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        external_account_id VARCHAR(255) DEFAULT NULL,
        config_json LONGTEXT DEFAULT NULL,
        last_sync_at DATETIME DEFAULT NULL,
        last_success_at DATETIME DEFAULT NULL,
        last_error TEXT DEFAULT NULL,
        sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT(10) UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_integration_practice_provider (practice_id, provider),
        INDEX idx_integration_sync_candidates (sync_enabled, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createConnections);
        $performed[] = "Ensured integration_connections table";
    } catch (PDOException $e) {
        $errors[] = "integration_connections: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 2. integration_credentials
    //    Encrypted credential material only - there is deliberately no
    //    plaintext column. Values are written via IntegrationCredentials,
    //    which encrypts with PIIEncryption before INSERT.
    // ------------------------------------------------------------------------
    $createCredentials = "CREATE TABLE IF NOT EXISTS integration_credentials (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        credential_key VARCHAR(64) NOT NULL,
        credential_value_encrypted TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_integration_credential_key (connection_id, credential_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createCredentials);
        $performed[] = "Ensured integration_credentials table";
    } catch (PDOException $e) {
        $errors[] = "integration_credentials: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 3. integration_entity_mappings
    //    UNIQUE(connection_id, entity_type, external_id) is the dedup
    //    backbone: inserting this row FIRST (before any DentaTrak write) is
    //    what makes repeated syncs and concurrent workers safe.
    //    internal_type/internal_id are nullable so a mapping can be reserved
    //    before the internal record exists; attachInternalId() fills them in
    //    later instead of inserting placeholder IDs.
    // ------------------------------------------------------------------------
    $createMappings = "CREATE TABLE IF NOT EXISTS integration_entity_mappings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        entity_type VARCHAR(32) NOT NULL,
        external_id VARCHAR(128) NOT NULL,
        internal_type VARCHAR(32) DEFAULT NULL,
        internal_id VARCHAR(64) DEFAULT NULL,
        external_parent_id VARCHAR(128) DEFAULT NULL,
        metadata_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME DEFAULT NULL,
        UNIQUE KEY uk_integration_entity (connection_id, entity_type, external_id),
        INDEX idx_integration_reverse_lookup (connection_id, entity_type, internal_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createMappings);
        $performed[] = "Ensured integration_entity_mappings table";
    } catch (PDOException $e) {
        $errors[] = "integration_entity_mappings: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 4. integration_sync_runs
    //    run_type/status are VARCHAR (not ENUM) so new values do not require
    //    migrations; valid values are enforced by SyncEngine constants.
    // ------------------------------------------------------------------------
    $createRuns = "CREATE TABLE IF NOT EXISTS integration_sync_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        run_type VARCHAR(32) NOT NULL DEFAULT 'scheduled',
        status VARCHAR(32) NOT NULL DEFAULT 'running',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME DEFAULT NULL,
        cursor_start VARCHAR(255) DEFAULT NULL,
        cursor_end VARCHAR(255) DEFAULT NULL,
        cases_seen INT UNSIGNED NOT NULL DEFAULT 0,
        cases_created INT UNSIGNED NOT NULL DEFAULT 0,
        cases_updated INT UNSIGNED NOT NULL DEFAULT 0,
        cases_skipped INT UNSIGNED NOT NULL DEFAULT 0,
        errors_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_summary TEXT DEFAULT NULL,
        INDEX idx_integration_runs_connection (connection_id, started_at),
        INDEX idx_integration_runs_status (status, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createRuns);
        $performed[] = "Ensured integration_sync_runs table";
    } catch (PDOException $e) {
        $errors[] = "integration_sync_runs: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 5. integration_sync_events
    //    Per-entity diagnostics. connection_id is denormalized from
    //    sync_runs so practice-scoped retention/debug queries need no join.
    //    CONTRACT: message/detail_json must never contain PHI or credentials.
    //    SyncEngine::recordEvent() enforces this mechanically (scalar-only
    //    detail, PHI/credential key denylist, size cap).
    // ------------------------------------------------------------------------
    $createEvents = "CREATE TABLE IF NOT EXISTS integration_sync_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sync_run_id BIGINT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        entity_type VARCHAR(32) NOT NULL,
        external_id VARCHAR(128) DEFAULT NULL,
        action VARCHAR(32) NOT NULL,
        case_id VARCHAR(64) DEFAULT NULL,
        message TEXT DEFAULT NULL,
        detail_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_integration_events_run (sync_run_id),
        INDEX idx_integration_events_connection (connection_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createEvents);
        $performed[] = "Ensured integration_sync_events table";
    } catch (PDOException $e) {
        $errors[] = "integration_sync_events: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 6. Foreign keys (best-effort, same convention as other migrations).
    //    Connection deletion cascades credentials/mappings/runs/events.
    //    created_by_user_id is SET NULL so deleting a user never orphans or
    //    destroys a connection.
    // ------------------------------------------------------------------------
    pmsIntegrationSafeAddForeignKey($pdo, 'fk_ic_practice', 'integration_connections', 'practice_id', 'practices', 'id', 'CASCADE', $performed, $warnings);
    pmsIntegrationSafeAddForeignKey($pdo, 'fk_ic_creator', 'integration_connections', 'created_by_user_id', 'users', 'id', 'SET NULL', $performed, $warnings);
    pmsIntegrationSafeAddForeignKey($pdo, 'fk_icred_connection', 'integration_credentials', 'connection_id', 'integration_connections', 'id', 'CASCADE', $performed, $warnings);
    pmsIntegrationSafeAddForeignKey($pdo, 'fk_iem_connection', 'integration_entity_mappings', 'connection_id', 'integration_connections', 'id', 'CASCADE', $performed, $warnings);
    pmsIntegrationSafeAddForeignKey($pdo, 'fk_isr_connection', 'integration_sync_runs', 'connection_id', 'integration_connections', 'id', 'CASCADE', $performed, $warnings);
    pmsIntegrationSafeAddForeignKey($pdo, 'fk_ise_run', 'integration_sync_events', 'sync_run_id', 'integration_sync_runs', 'id', 'CASCADE', $performed, $warnings);
    pmsIntegrationSafeAddForeignKey($pdo, 'fk_ise_connection', 'integration_sync_events', 'connection_id', 'integration_connections', 'id', 'CASCADE', $performed, $warnings);

    return [
        'success' => empty($errors),
        'performed' => $performed,
        'warnings' => $warnings,
        'errors' => $errors,
    ];
}

/**
 * Attempt to add a foreign key. If the referenced table/column is missing,
 * the engine does not support FKs, or types are incompatible, log and continue.
 */
function pmsIntegrationSafeAddForeignKey(PDO $pdo, string $name, string $table, string $column, string $refTable, string $refColumn, string $onDelete, array &$performed, array &$warnings): void
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = :name AND TABLE_NAME = :table");
        $stmt->execute([':name' => $name, ':table' => $table]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES {$refTable}({$refColumn}) ON DELETE {$onDelete}");
        $performed[] = "Added foreign key: {$name}";
    } catch (PDOException $e) {
        $warnings[] = "FK {$name}: " . $e->getMessage();
    }
}

// If this file is the entry point (direct HTTP/CLI request), execute the
// migration and report. When required by another script, only the function
// definitions are exposed so callers can run the migration programmatically.
$isMigrationEntryPoint = PHP_SAPI === 'cli'
    || (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));
if ($isMigrationEntryPoint) {
    // In a web context, require admin or test mode for safety.
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

    $result = runPmsIntegrationFoundationMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
