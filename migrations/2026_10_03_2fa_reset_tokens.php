<?php
/**
 * Two-factor recovery token migration.
 *
 * Adds two_factor_reset_tokens: single-use, hashed, expiring tokens that
 * authorize a user to reset their own two-factor authentication after
 * proving mailbox control AND re-verifying their identity (password, or a
 * fresh Google sign-in for Google-only accounts). requested_by_user_id
 * records when a practice owner/admin initiated the member-verified
 * request - it never authorizes the reset by itself.
 * Idempotent: safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the 2FA reset token migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function run2faResetTokensMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS two_factor_reset_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                requested_by_user_id INT UNSIGNED NULL,
                expires_at DATETIME NOT NULL,
                used BOOLEAN NOT NULL DEFAULT FALSE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $performed[] = "two_factor_reset_tokens table present";
    } catch (PDOException $e) {
        $errors[] = "two_factor_reset_tokens: " . $e->getMessage();
    }

    // Normalize column types: the first TIMESTAMP column can acquire implicit
    // ON UPDATE CURRENT_TIMESTAMP, which would silently extend expiry when a
    // token is marked used. DATETIME avoids that entirely. Idempotent.
    try {
        $pdo->exec("
            ALTER TABLE two_factor_reset_tokens
                MODIFY expires_at DATETIME NOT NULL,
                MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ");
        $performed[] = "two_factor_reset_tokens columns normalized";
    } catch (PDOException $e) {
        $errors[] = "two_factor_reset_tokens columns: " . $e->getMessage();
    }

    // users.remember_me_revoked_after: per-user revocation watermark for the
    // stateless HMAC remember-me cookies (they have no DB row to delete).
    // Validation rejects any cookie issued before this timestamp, so a 2FA
    // reset can actually invalidate remembered browsers.
    try {
        $q = $pdo->quote('remember_me_revoked_after');
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE {$q}")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE users ADD COLUMN remember_me_revoked_after DATETIME NULL DEFAULT NULL");
            $performed[] = "Added users.remember_me_revoked_after";
        } else {
            $performed[] = "users.remember_me_revoked_after already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "users.remember_me_revoked_after: " . $e->getMessage();
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

    $result = run2faResetTokensMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
