<?php
/**
 * Notification dismissal state migration
 *
 * Adds the per-recipient dismissed_at column and supporting index to
 * user_notifications. This migration is idempotent and safe to run multiple
 * times; it is the authoritative way to introduce dismissal schema, while
 * api/notifications.php only verifies the schema is present during ordinary
 * requests and does not perform DDL.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the notification dismissal migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runNotificationDismissalMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    // Create the table if it does not exist. If an older schema is already
    // present, the CREATE TABLE is a no-op and the ALTER statements below
    // bring it up to date. This avoids assuming a particular starting state.
    $createTableSql = "CREATE TABLE IF NOT EXISTS user_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        notification_type VARCHAR(50) NOT NULL DEFAULT 'mention',
        case_id VARCHAR(64) DEFAULT NULL,
        comment_id BIGINT UNSIGNED DEFAULT NULL,
        from_user_id BIGINT UNSIGNED NOT NULL,
        from_user_name VARCHAR(255) NOT NULL,
        preview_text VARCHAR(255) DEFAULT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        read_at DATETIME DEFAULT NULL,
        dismissed_at DATETIME DEFAULT NULL,
        metadata_json LONGTEXT,
        event_id BIGINT UNSIGNED DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_is_read (is_read),
        INDEX idx_dismissed_at (dismissed_at),
        INDEX idx_created_at (created_at),
        INDEX idx_case_id (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createTableSql);
        $performed[] = "Ensured user_notifications table exists";
    } catch (PDOException $e) {
        $errors[] = "user_notifications create: " . $e->getMessage();
    }

    // Add dismissed_at if it is not already present.
    try {
        $quotedCol = $pdo->quote('dismissed_at');
        $stmt = $pdo->query("SHOW COLUMNS FROM user_notifications LIKE {$quotedCol}");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE user_notifications ADD COLUMN dismissed_at DATETIME DEFAULT NULL");
            $performed[] = "Added user_notifications.dismissed_at";
        } else {
            $performed[] = "user_notifications.dismissed_at already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "user_notifications.dismissed_at: " . $e->getMessage();
    }

    // Add the supporting index if it is not already present.
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'user_notifications'
              AND INDEX_NAME = :name
            LIMIT 1
        ");
        $stmt->execute([':name' => 'idx_dismissed_at']);
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE user_notifications ADD INDEX idx_dismissed_at (dismissed_at)");
            $performed[] = "Added idx_dismissed_at";
        } else {
            $performed[] = "idx_dismissed_at already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "idx_dismissed_at: " . $e->getMessage();
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

    $result = runNotificationDismissalMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
