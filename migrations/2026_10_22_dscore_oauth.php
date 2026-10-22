<?php
/**
 * DS Core OAuth attempt tracking migration
 *
 * Creates dscore_oauth_attempts: one row per authorization attempt
 * initiated by a practice administrator. The row carries the server-side
 * PKCE verifier (encrypted - it must be recoverable for the token
 * exchange, unlike a password-style hash), the attempt expiry, and a
 * consumed marker that makes every attempt single-use.
 *
 * CORRELATION: the attempt id is sent to DS Core as the `state` parameter
 * (documented in the portal guide's callback URL template, which echoes
 * `state` back). On callback the row binds code -> attempt -> initiating
 * user + practice; the callback also requires the live session to still
 * match both.
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
 * Run the DS Core OAuth attempt migration.
 */
function runDsCoreOAuthMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    // --------------------------------------------------------------------
    // dscore_oauth_attempts
    //   - id (UUID) doubles as the OAuth `state` value.
    //   - code_verifier_encrypted: the PKCE S256 verifier, encrypted via
    //     PIIEncryption (recoverable - required verbatim at exchange time).
    //   - expires_at: short TTL; stale attempts can never be redeemed.
    //   - consumed_at: set once by an atomic conditional UPDATE; a second
    //     callback for the same attempt can never re-consume it.
    //   - No tokens ever live here; issued tokens go to
    //     integration_credentials via IntegrationCredentials.
    // --------------------------------------------------------------------
    $create = "CREATE TABLE IF NOT EXISTS dscore_oauth_attempts (
        id CHAR(36) NOT NULL PRIMARY KEY,
        practice_id INT(10) UNSIGNED NOT NULL,
        user_id INT(10) UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED DEFAULT NULL,
        code_verifier_encrypted TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dsoa_expiry (expires_at),
        INDEX idx_dsoa_connection (connection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($create);
        $performed[] = "Ensured dscore_oauth_attempts table";
    } catch (PDOException $e) {
        $errors[] = "dscore_oauth_attempts: " . $e->getMessage();
    }

    // Best-effort FKs (same convention as other migrations).
    dsCoreSafeAddForeignKey($pdo, 'fk_dsoa_practice', 'dscore_oauth_attempts', 'practice_id', 'practices', 'id', 'CASCADE', $performed, $warnings);
    dsCoreSafeAddForeignKey($pdo, 'fk_dsoa_user', 'dscore_oauth_attempts', 'user_id', 'users', 'id', 'CASCADE', $performed, $warnings);
    dsCoreSafeAddForeignKey($pdo, 'fk_dsoa_connection', 'dscore_oauth_attempts', 'connection_id', 'integration_connections', 'id', 'SET NULL', $performed, $warnings);

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
function dsCoreSafeAddForeignKey(PDO $pdo, string $name, string $table, string $column, string $refTable, string $refColumn, string $onDelete, array &$performed, array &$warnings): void
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

    $result = runDsCoreOAuthMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
