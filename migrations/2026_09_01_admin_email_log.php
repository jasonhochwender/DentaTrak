<?php
/**
 * Admin email audit log table migration
 *
 * Creates the admin_email_log table used by api/admin-practices.php for
 * recording administrator email actions. Idempotent; preserves existing data.
 *
 * Execution:
 *   php migrations/2026_09_01_admin_email_log.php
 */

require_once __DIR__ . '/../api/appConfig.php';

function runAdminEmailLogMigration(PDO $pdo): array
{
    $result = [
        'success' => true,
        'performed' => [],
        'errors' => [],
    ];

    try {
        $check = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'admin_email_log'
            LIMIT 1
        ");
        $check->execute();
        $exists = (bool)$check->fetchColumn();

        if ($exists) {
            $result['performed'][] = 'admin_email_log already exists';
        } else {
            $pdo->exec("
                CREATE TABLE admin_email_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    admin_user_id BIGINT UNSIGNED NOT NULL,
                    admin_email VARCHAR(255),
                    recipient_user_id BIGINT UNSIGNED NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    practice_id BIGINT UNSIGNED NOT NULL,
                    email_type VARCHAR(100) NOT NULL,
                    email_subject VARCHAR(500) NOT NULL,
                    success TINYINT(1) NOT NULL DEFAULT 0,
                    provider VARCHAR(100),
                    error_message TEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_admin_user_id (admin_user_id),
                    INDEX idx_recipient_user_id (recipient_user_id),
                    INDEX idx_practice_id (practice_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $result['performed'][] = 'admin_email_log created';
        }
    } catch (PDOException $e) {
        $result['success'] = false;
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}

if (PHP_SAPI === 'cli') {
    $result = runAdminEmailLogMigration($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

// Web guard: do not run automatically on inclusion.
return runAdminEmailLogMigration($pdo);
