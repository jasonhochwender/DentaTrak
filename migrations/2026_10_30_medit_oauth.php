<?php
/**
 * Medit Link OAuth + webhook migration
 *
 * Creates:
 *   medit_oauth_attempts - one row per authorization attempt initiated by
 *     a practice administrator. The id doubles as the OAuth `state` value;
 *     expiry + single-use consumption are enforced exactly like
 *     dscore_oauth_attempts. secret_encrypted stores a per-attempt random
 *     nonce (reserved for PKCE should Medit document it; the current
 *     Medit OpenAPI guide defines no PKCE parameters).
 *   medit_webhook_events - durable store for Medit webhook deliveries.
 *     Rows are recorded by api/medit-webhook.php ONLY after signature
 *     verification; a per-minute worker pass then resolves the affected
 *     case through the connector. dedup_key makes redelivery idempotent.
 *
 * Idempotent, non-destructive, safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

/**
 * Run the Medit Link integration migration.
 */
function runMeditIntegrationMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    // --------------------------------------------------------------------
    // medit_oauth_attempts
    //   - id (UUID) doubles as the OAuth `state` value echoed back by
    //     Medit on the callback.
    //   - secret_encrypted: per-attempt nonce, encrypted via PIIEncryption.
    //   - expires_at: short TTL; stale attempts can never be redeemed.
    //   - consumed_at: atomic conditional UPDATE makes it single-use.
    //   - No tokens ever live here; issued tokens go to
    //     integration_credentials via IntegrationCredentials.
    // --------------------------------------------------------------------
    $createAttempts = "CREATE TABLE IF NOT EXISTS medit_oauth_attempts (
        id CHAR(36) NOT NULL PRIMARY KEY,
        practice_id INT(10) UNSIGNED NOT NULL,
        user_id INT(10) UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED DEFAULT NULL,
        secret_encrypted TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_moa_expiry (expires_at),
        INDEX idx_moa_connection (connection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createAttempts);
        $performed[] = "Ensured medit_oauth_attempts table";
    } catch (PDOException $e) {
        $errors[] = "medit_oauth_attempts: " . $e->getMessage();
    }

    // --------------------------------------------------------------------
    // medit_webhook_events
    //   - dedup_key UNIQUE: sha256(connection_id + event_code + case_uuid +
    //     payload hash). Replays/duplicate deliveries collapse to the same
    //     row (INSERT IGNORE) - processing is idempotent.
    //   - case_uuid nullable: unresolvable payloads still record a durable
    //     "poll this connection" hint instead of being dropped.
    //   - payload_json bounded (4KB) and PHI-free by receiver discipline.
    // --------------------------------------------------------------------
    $createEvents = "CREATE TABLE IF NOT EXISTS medit_webhook_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        practice_id INT(10) UNSIGNED NOT NULL,
        event_code VARCHAR(64) NOT NULL DEFAULT '',
        case_uuid VARCHAR(64) DEFAULT NULL,
        payload_json TEXT DEFAULT NULL,
        dedup_key CHAR(64) NOT NULL,
        status ENUM('received','processing','processed','failed') NOT NULL DEFAULT 'received',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_mwe_dedup (dedup_key),
        INDEX idx_mwe_status (status),
        INDEX idx_mwe_connection (connection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createEvents);
        $performed[] = "Ensured medit_webhook_events table";
    } catch (PDOException $e) {
        $errors[] = "medit_webhook_events: " . $e->getMessage();
    }

    // Best-effort FKs (same convention as other migrations).
    meditSafeAddForeignKey($pdo, 'fk_moa_practice', 'medit_oauth_attempts', 'practice_id', 'practices', 'id', 'CASCADE', $performed, $warnings);
    meditSafeAddForeignKey($pdo, 'fk_moa_user', 'medit_oauth_attempts', 'user_id', 'users', 'id', 'CASCADE', $performed, $warnings);
    meditSafeAddForeignKey($pdo, 'fk_moa_connection', 'medit_oauth_attempts', 'connection_id', 'integration_connections', 'id', 'SET NULL', $performed, $warnings);
    meditSafeAddForeignKey($pdo, 'fk_mwe_connection', 'medit_webhook_events', 'connection_id', 'integration_connections', 'id', 'CASCADE', $performed, $warnings);
    meditSafeAddForeignKey($pdo, 'fk_mwe_practice', 'medit_webhook_events', 'practice_id', 'practices', 'id', 'CASCADE', $performed, $warnings);

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
function meditSafeAddForeignKey(PDO $pdo, string $name, string $table, string $column, string $refTable, string $refColumn, string $onDelete, array &$performed, array &$warnings): void
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

    $result = runMeditIntegrationMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
