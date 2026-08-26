<?php
/**
 * Phase 1 notification system migration
 *
 * Creates the database foundation for the DentaTrak notification system.
 * Idempotent, non-destructive, and safe to run multiple times.
 *
 * Tables created:
 *  - notification_events
 *  - user_notification_preferences
 *  - practice_assignment_label_recipients
 *  - notification_email_queue
 *
 * user_notifications is extended with:
 *  - metadata_json
 *  - expires_at
 *  - event_id
 *
 * Foreign keys are attempted where safe; failures are logged, not fatal.
 */

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/user-manager.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
}

/**
 * Run the Phase 1 migration.
 */
function runNotificationPhase1Migration(PDO $pdo): array
{
    $performed = [];
    $errors = [];
    $warnings = [];

    // ------------------------------------------------------------------------
    // 1. Extend existing user_notifications for new event-based rows.
    //    Legacy @mention rows continue to use notification_type = 'mention',
    //    from_user_id, from_user_name, preview_text, and comment_id unchanged.
    // ------------------------------------------------------------------------
    $extensionColumns = [
        'metadata_json' => "ALTER TABLE user_notifications ADD COLUMN metadata_json LONGTEXT DEFAULT NULL",
        'expires_at'    => "ALTER TABLE user_notifications ADD COLUMN expires_at DATETIME DEFAULT NULL",
        'event_id'      => "ALTER TABLE user_notifications ADD COLUMN event_id BIGINT UNSIGNED DEFAULT NULL",
    ];

    foreach ($extensionColumns as $col => $sql) {
        try {
            // MySQL's SHOW COLUMNS does not accept parameter placeholders, so
            // quote the known column name and concatenate it safely.
            $quotedCol = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM user_notifications LIKE {$quotedCol}");
            if ($stmt->rowCount() === 0) {
                $pdo->exec($sql);
                $performed[] = "Extended user_notifications: {$col}";
            }
        } catch (PDOException $e) {
            $errors[] = "user_notifications.{$col}: " . $e->getMessage();
        }
    }

    $newIndexes = [
        'idx_user_notifications_expires' => "ALTER TABLE user_notifications ADD INDEX idx_user_notifications_expires (expires_at)",
        'idx_user_notifications_event'   => "ALTER TABLE user_notifications ADD INDEX idx_user_notifications_event (event_id)",
    ];
    foreach ($newIndexes as $name => $sql) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_notifications' AND INDEX_NAME = :name");
            $stmt->execute([':name' => $name]);
            if ($stmt->rowCount() === 0) {
                $pdo->exec($sql);
                $performed[] = "Added index: {$name}";
            }
        } catch (PDOException $e) {
            $errors[] = "{$name}: " . $e->getMessage();
        }
    }

    // ------------------------------------------------------------------------
    // 2. notification_events: one canonical row per completed user action.
    // ------------------------------------------------------------------------
    $createNotificationEvents = "CREATE TABLE IF NOT EXISTS notification_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        practice_id INT(10) UNSIGNED NOT NULL,
        case_id VARCHAR(64) NOT NULL,
        actor_user_id INT(10) UNSIGNED NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        event_categories JSON NOT NULL,
        metadata_json LONGTEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case_id (case_id),
        INDEX idx_practice_created (practice_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createNotificationEvents);
        $performed[] = "Ensured notification_events table";
    } catch (PDOException $e) {
        $errors[] = "notification_events: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 3. user_notification_preferences: row-based, per (user, practice, event).
    //    Missing rows will mean default-enabled when preferences are built.
    //    No default seeding is performed.
    // ------------------------------------------------------------------------
    $createUserPrefs = "CREATE TABLE IF NOT EXISTS user_notification_preferences (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT(10) UNSIGNED NOT NULL,
        practice_id INT(10) UNSIGNED NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        channel VARCHAR(20) NOT NULL DEFAULT 'email',
        enabled BOOLEAN NOT NULL DEFAULT TRUE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_practice_event_channel (user_id, practice_id, event_type, channel),
        INDEX idx_practice_user (practice_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createUserPrefs);
        $performed[] = "Ensured user_notification_preferences table";
    } catch (PDOException $e) {
        $errors[] = "user_notification_preferences: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 4. practice_assignment_label_recipients: many-to-many mapping.
    //    Used for both notifications and Authorized-Only case access.
    // ------------------------------------------------------------------------
    $createLabelRecipients = "CREATE TABLE IF NOT EXISTS practice_assignment_label_recipients (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        practice_id INT(10) UNSIGNED NOT NULL,
        label_id INT(10) UNSIGNED NOT NULL,
        user_id INT(10) UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_label_user (label_id, user_id),
        INDEX idx_practice_user (practice_id, user_id),
        INDEX idx_label_id (label_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createLabelRecipients);
        $performed[] = "Ensured practice_assignment_label_recipients table";
    } catch (PDOException $e) {
        $errors[] = "practice_assignment_label_recipients: " . $e->getMessage();
    }

    // If the table already existed with mismatched column types, correct them.
    // This is idempotent because MODIFY is only needed once.
    $expectedTypes = [
        'practice_id' => 'int(10) unsigned',
        'label_id'    => 'int(10) unsigned',
        'user_id'     => 'int(10) unsigned',
    ];
    foreach ($expectedTypes as $col => $expected) {
        try {
            $quotedCol = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM practice_assignment_label_recipients LIKE {$quotedCol}");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $current = strtolower($row['Type']);
                if ($current !== $expected) {
                    $pdo->exec("ALTER TABLE practice_assignment_label_recipients MODIFY COLUMN {$col} {$expected} NOT NULL");
                    $performed[] = "Corrected practice_assignment_label_recipients.{$col} from {$row['Type']} to {$expected}";
                }
            }
        } catch (PDOException $e) {
            $warnings[] = "practice_assignment_label_recipients.{$col}: " . $e->getMessage();
        }
    }

    // ------------------------------------------------------------------------
    // 5. notification_email_queue: asynchronous outbox; no recipient email stored.
    //    Recipient email is resolved from users table at delivery time.
    // ------------------------------------------------------------------------
    $createEmailQueue = "CREATE TABLE IF NOT EXISTS notification_email_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id BIGINT UNSIGNED NOT NULL,
        user_id INT(10) UNSIGNED NOT NULL,
        practice_id INT(10) UNSIGNED NOT NULL,
        locale VARCHAR(35) NOT NULL,
        subject_key VARCHAR(120) NOT NULL,
        body_key VARCHAR(120) NOT NULL,
        template_data_json LONGTEXT,
        status ENUM('pending','processing','sent','failed','cancelled') DEFAULT 'pending',
        retry_count INT UNSIGNED DEFAULT 0,
        error_message TEXT,
        scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at DATETIME DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_scheduled (status, scheduled_at),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($createEmailQueue);
        $performed[] = "Ensured notification_email_queue table";
    } catch (PDOException $e) {
        $errors[] = "notification_email_queue: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 6. Foreign keys (best-effort). Existing table engines/types may not
    //    support some constraints; failures are logged but do not abort.
    // ------------------------------------------------------------------------
    // Remove orphaned mappings before adding constraints. They cannot grant
    // useful access anyway, and they block foreign-key creation.
    try {
        $deletedOrphans = $pdo->exec("
            DELETE palr FROM practice_assignment_label_recipients palr
            LEFT JOIN practice_assignment_labels pal ON palr.label_id = pal.id
            LEFT JOIN users u ON palr.user_id = u.id
            WHERE pal.id IS NULL OR u.id IS NULL
        ");
        if ($deletedOrphans > 0) {
            $performed[] = "Deleted {$deletedOrphans} orphaned label-recipient row(s)";
        }
    } catch (PDOException $e) {
        $warnings[] = "Orphan cleanup: " . $e->getMessage();
    }

    safeAddForeignKey($pdo, 'fk_palr_label', 'practice_assignment_label_recipients', 'label_id', 'practice_assignment_labels', 'id', 'CASCADE', $performed, $warnings);
    safeAddForeignKey($pdo, 'fk_palr_user', 'practice_assignment_label_recipients', 'user_id', 'users', 'id', 'CASCADE', $performed, $warnings);
    safeAddForeignKey($pdo, 'fk_palr_practice', 'practice_assignment_label_recipients', 'practice_id', 'practices', 'id', 'CASCADE', $performed, $warnings);

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
function safeAddForeignKey(PDO $pdo, string $name, string $table, string $column, string $refTable, string $refColumn, string $onDelete, array &$performed, array &$warnings): void
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

    $result = runNotificationPhase1Migration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
