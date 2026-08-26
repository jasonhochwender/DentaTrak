<?php
/**
 * Notification Maintenance Worker
 *
 * Performs safe, bounded retention cleanup for the notification system.
 * Designed to be invoked by Cloud Scheduler or a protected cron job using
 * the same QUEUE_WORKER_TOKEN as the send worker.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/session.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$workerToken = getEnvVar('QUEUE_WORKER_TOKEN');
$isTestMode = getEnvVar('DENTATRAK_TEST_MODE') === 'true' || ($appConfig['current_environment'] ?? 'production') !== 'production';
if (!$workerToken && $isTestMode) {
    $workerToken = 'dentatrak-test-worker-token';
}
$submittedToken = '';

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($authHeader, 'Bearer ') === 0) {
    $submittedToken = substr($authHeader, 7);
}

if (!$submittedToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!$workerToken || !hash_equals($workerToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database not available']);
    exit;
}

$BATCH_SIZE = 1000;
$RETENTION_DAYS = (int)(getEnvVar('NOTIFICATION_RETENTION_DAYS') ?? 90);

$totals = [
    'expired_notifications_deleted' => 0,
    'orphaned_events_deleted' => 0,
    'queue_rows_deleted' => 0,
];

try {
    // 1. Delete expired in-app notifications.
    //    Rows with expires_at IS NULL are legacy mention rows and are preserved.
    $delNotifications = $pdo->exec("
        DELETE FROM user_notifications
        WHERE expires_at IS NOT NULL
          AND expires_at < NOW()
        LIMIT {$BATCH_SIZE}
    ");
    $totals['expired_notifications_deleted'] = (int)$delNotifications;

    // 2. Delete notification_events that are no longer referenced, in bounded batches.
    //    Select a limited batch of orphan event IDs, then delete only those IDs.
    $eventSelectStmt = $pdo->prepare("
        SELECT e.id FROM notification_events e
        LEFT JOIN user_notifications un ON un.event_id = e.id
        LEFT JOIN notification_email_queue q ON q.event_id = e.id
        WHERE e.created_at < DATE_SUB(NOW(), INTERVAL {$RETENTION_DAYS} DAY)
          AND un.id IS NULL
          AND q.id IS NULL
        ORDER BY e.id ASC
        LIMIT :batch_size
    ");
    $eventSelectStmt->bindValue(':batch_size', $BATCH_SIZE, PDO::PARAM_INT);
    $eventSelectStmt->execute();
    $eventIds = $eventSelectStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($eventIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
        $delEventsStmt = $pdo->prepare("
            DELETE FROM notification_events
            WHERE id IN ({$inPlaceholders})
        ");
        $delEventsStmt->execute($eventIds);
        $totals['orphaned_events_deleted'] = $delEventsStmt->rowCount();
    }

    // 3. Delete sent/failed/cancelled queue rows older than retention.
    //    Pending and processing rows are retained.
    $delQueue = $pdo->exec("
        DELETE FROM notification_email_queue
        WHERE status IN ('sent', 'failed', 'cancelled')
          AND (
              (status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL {$RETENTION_DAYS} DAY))
              OR (status IN ('failed', 'cancelled') AND created_at < DATE_SUB(NOW(), INTERVAL {$RETENTION_DAYS} DAY))
          )
        LIMIT {$BATCH_SIZE}
    ");
    $totals['queue_rows_deleted'] = (int)$delQueue;

    echo json_encode(['success' => true, 'totals' => $totals]);
} catch (Throwable $e) {
    error_log('[notification-maintenance] Cleanup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Cleanup failed']);
}
