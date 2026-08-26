<?php
/**
 * Phase 4 notification system migration
 *
 * Extends the email outbox created in Phase 1 for transactional delivery.
 * Idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    }
    exit;
}

/**
 * Run the Phase 4 migration.
 */
function runNotificationPhase4Migration(PDO $pdo): array
{
    $performed = [];
    $errors = [];

    // ------------------------------------------------------------------------
    // 1. Add notification_id to the queue so the worker can verify ownership,
    //    build the View case link, and avoid duplicate queue rows.
    // ------------------------------------------------------------------------
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notification_email_queue LIKE 'notification_id'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE notification_email_queue ADD COLUMN notification_id BIGINT UNSIGNED NOT NULL AFTER event_id");
            $performed[] = "Added notification_email_queue.notification_id";
        }
    } catch (PDOException $e) {
        $errors[] = "notification_email_queue.notification_id: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 2. Unique constraint preventing one recipient receiving two emails for
    //    the same in-app notification.
    // ------------------------------------------------------------------------
    try {
        $stmt = $pdo->query("
            SELECT 1
            FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE()
              AND table_name = 'notification_email_queue'
              AND index_name = 'uk_queue_notification_user'
            LIMIT 1
        ");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE notification_email_queue ADD UNIQUE KEY uk_queue_notification_user (notification_id, user_id)");
            $performed[] = "Added unique key uk_queue_notification_user";
        }
    } catch (PDOException $e) {
        $errors[] = "notification_email_queue unique key: " . $e->getMessage();
    }

    // ------------------------------------------------------------------------
    // 3. Add a category_keys_json column for storing the structured,
    //    non-PHI event categories used by the worker.
    // ------------------------------------------------------------------------
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notification_email_queue LIKE 'category_keys_json'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE notification_email_queue ADD COLUMN category_keys_json LONGTEXT DEFAULT NULL AFTER template_data_json");
            $performed[] = "Added notification_email_queue.category_keys_json";
        }
    } catch (PDOException $e) {
        $errors[] = "notification_email_queue.category_keys_json: " . $e->getMessage();
    }

    return ['success' => empty($errors), 'performed' => $performed, 'errors' => $errors];
}

if (PHP_SAPI === 'cli') {
    $result = runNotificationPhase4Migration($pdo);
    echo json_encode($result) . PHP_EOL;
    exit;
}

// Web-safe entry point for Cloud Run / one-click deployment.
$isWebEntryPoint = isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
if ($isWebEntryPoint) {
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

    $result = runNotificationPhase4Migration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
