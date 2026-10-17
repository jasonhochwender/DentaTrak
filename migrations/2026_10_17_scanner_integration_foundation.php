<?php
/**
 * Scanner integration foundation migration
 *
 * Creates the shared data model for intraoral scanner imports (iTero,
 * 3Shape TRIOS, Primescan / DS Core, Medit Link, and the dev-only simulated
 * connector). No vendor-specific columns exist anywhere in this schema -
 * a scanner source is just a `vendor` string plus an opaque `remote_ref`
 * supplied by the connector.
 *
 * Idempotent, non-destructive, and safe to run multiple times.
 *
 * Tables created:
 *  - scanner_incoming_scans  one incoming scan/case per (practice, vendor,
 *                            source connection/account, external scan id)
 *  - scanner_scan_files      per-file download/link state, revisioned
 *                            (a revised vendor file is a NEW row - it never
 *                            overwrites a previously attached file)
 *  - scanner_scan_events     PHI-free audit trail (import, access, linking,
 *                            case creation, dismissal, retry)
 *
 * DEDUP: scanner_incoming_scans.dedup_key is a SHA-256 of
 * practice|vendor|connection|account|external_scan_id, giving a global
 * UNIQUE key that is immune to NULL connection_id. scanner_scan_files
 * dedups on (scan_id, external_file_id, revision).
 *
 * PHI CONTRACT: patient first/last name, DOB, and instructions are stored
 * encrypted via PIIEncryption (same mechanism as cases_cache). Event rows
 * and error fields must never carry names, DOBs, or note text.
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
 * Run the scanner integration foundation migration.
 */
function runScannerIntegrationFoundationMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    // ------------------------------------------------------------------------
    // 1. scanner_incoming_scans
    //    One row per external scan/case delivered to a practice.
    //    UNIQUE(dedup_key) is the ingestion dedup backbone: inserting this row
    //    FIRST (before any file download or case write) is what makes repeated
    //    vendor deliveries and concurrent processing safe.
    //    status lifecycle: received -> processing -> ready | failed
    //                      ready -> linked | dismissed (dismissed -> ready via
    //                      restore; linked stays linked - revised files surface
    //                      as additional unattached file rows)
    // ------------------------------------------------------------------------
    $createScans = "CREATE TABLE IF NOT EXISTS scanner_incoming_scans (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        practice_id INT(10) UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED DEFAULT NULL,
        vendor VARCHAR(32) NOT NULL,
        source_account VARCHAR(128) DEFAULT NULL,
        remote_ref VARCHAR(255) DEFAULT NULL,
        external_scan_id VARCHAR(128) NOT NULL,
        external_patient_ref VARCHAR(128) DEFAULT NULL,
        patient_first_name TEXT DEFAULT NULL,
        patient_last_name TEXT DEFAULT NULL,
        patient_dob VARCHAR(190) DEFAULT NULL,
        scan_at DATETIME DEFAULT NULL,
        instructions MEDIUMTEXT DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'received',
        error_code VARCHAR(64) DEFAULT NULL,
        error_detail VARCHAR(255) DEFAULT NULL,
        case_id VARCHAR(64) DEFAULT NULL,
        linked_by_user_id INT(10) UNSIGNED DEFAULT NULL,
        linked_at DATETIME DEFAULT NULL,
        dismissed_by_user_id INT(10) UNSIGNED DEFAULT NULL,
        dismissed_at DATETIME DEFAULT NULL,
        import_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        claimed_at DATETIME DEFAULT NULL,
        is_synthetic TINYINT(1) NOT NULL DEFAULT 0,
        dedup_key CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_scan_dedup (dedup_key),
        INDEX idx_scans_practice_status (practice_id, status, created_at),
        INDEX idx_scans_practice_case (practice_id, case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createScans);
        $performed[] = "Ensured scanner_incoming_scans table";
    } catch (PDOException $e) {
        $errors[] = "scanner_incoming_scans: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 2. scanner_scan_files
    //    One row per vendor-supplied file revision. UNIQUE(scan_id,
    //    external_file_id, revision) - a vendor re-delivering the same file
    //    under a new revision creates a NEW row so previously attached
    //    bytes are never silently overwritten.
    //    status lifecycle: pending -> downloading -> ready | failed
    //                      ready -> superseded | (attached: attached_case_id
    //                      + storage_path point at the case-owned object)
    //    storage_path always uses the cases/{practiceId}/... namespace so the
    //    existing attachment endpoints' practice-prefix and per-case ACL
    //    checks apply verbatim (driver column says where the bytes live:
    //    'gcs' bucket or the dev-only local mirror).
    // ------------------------------------------------------------------------
    $createFiles = "CREATE TABLE IF NOT EXISTS scanner_scan_files (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scan_id BIGINT UNSIGNED NOT NULL,
        external_file_id VARCHAR(128) NOT NULL,
        revision VARCHAR(64) NOT NULL DEFAULT '',
        remote_ref VARCHAR(255) DEFAULT NULL,
        file_name VARCHAR(255) NOT NULL,
        content_type VARCHAR(128) DEFAULT NULL,
        declared_size BIGINT UNSIGNED DEFAULT NULL,
        checksum_sha256 CHAR(64) DEFAULT NULL,
        storage_driver VARCHAR(16) DEFAULT NULL,
        storage_path VARCHAR(512) DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        attached_case_id VARCHAR(64) DEFAULT NULL,
        attached_at DATETIME DEFAULT NULL,
        download_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        claimed_at DATETIME DEFAULT NULL,
        error_code VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_scan_file_rev (scan_id, external_file_id, revision),
        INDEX idx_scan_files_scan (scan_id, status),
        INDEX idx_scan_files_case (attached_case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createFiles);
        $performed[] = "Ensured scanner_scan_files table";
    } catch (PDOException $e) {
        $errors[] = "scanner_scan_files: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 3. scanner_scan_events
    //    PHI-free audit trail for the scan lifecycle. event is a fixed
    //    vocabulary (imported, viewed, linked, case_created, dismissed,
    //    restored, retry, file_ready, file_failed, revision_received);
    //    detail may carry ids/counts/statuses only - never names, DOBs,
    //    instruction text, credentials, or raw vendor payloads.
    // ------------------------------------------------------------------------
    $createEvents = "CREATE TABLE IF NOT EXISTS scanner_scan_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scan_id BIGINT UNSIGNED NOT NULL,
        practice_id INT(10) UNSIGNED NOT NULL,
        user_id INT(10) UNSIGNED DEFAULT NULL,
        event VARCHAR(48) NOT NULL,
        detail VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_scan_events_scan (scan_id, created_at),
        INDEX idx_scan_events_practice (practice_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createEvents);
        $performed[] = "Ensured scanner_scan_events table";
    } catch (PDOException $e) {
        $errors[] = "scanner_scan_events: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 4. Foreign keys (best-effort, same convention as other migrations).
    //    Scan deletion cascades files/events. connection_id / user ids are
    //    SET NULL so removing a connection or user keeps the audit trail.
    // ------------------------------------------------------------------------
    scannerSafeAddForeignKey($pdo, 'fk_sis_practice', 'scanner_incoming_scans', 'practice_id', 'practices', 'id', 'CASCADE', $performed, $warnings);
    scannerSafeAddForeignKey($pdo, 'fk_sis_connection', 'scanner_incoming_scans', 'connection_id', 'integration_connections', 'id', 'SET NULL', $performed, $warnings);
    scannerSafeAddForeignKey($pdo, 'fk_sis_linker', 'scanner_incoming_scans', 'linked_by_user_id', 'users', 'id', 'SET NULL', $performed, $warnings);
    scannerSafeAddForeignKey($pdo, 'fk_sis_dismisser', 'scanner_incoming_scans', 'dismissed_by_user_id', 'users', 'id', 'SET NULL', $performed, $warnings);
    scannerSafeAddForeignKey($pdo, 'fk_ssf_scan', 'scanner_scan_files', 'scan_id', 'scanner_incoming_scans', 'id', 'CASCADE', $performed, $warnings);
    scannerSafeAddForeignKey($pdo, 'fk_sse_scan', 'scanner_scan_events', 'scan_id', 'scanner_incoming_scans', 'id', 'CASCADE', $performed, $warnings);

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
function scannerSafeAddForeignKey(PDO $pdo, string $name, string $table, string $column, string $refTable, string $refColumn, string $onDelete, array &$performed, array &$warnings): void
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

    $result = runScannerIntegrationFoundationMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
