<?php
/**
 * Ask DentaTrak telemetry retention worker.
 *
 * Deletes ask_dentatrak_usage rows older than the retention window
 * (default 180 days; override via ASK_DENTATRAK_USAGE_RETENTION_DAYS) in
 * bounded batches. Follows api/notification-maintenance.php:
 *   - HTTP: POST with X-Queue-Worker-Token header (Cloud Scheduler / cron)
 *   - CLI:  php api/ask-dentatrak-maintenance.php
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/session.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/queue-worker-auth.php';
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    requireQueueWorkerToken();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database not available']);
    exit;
}

$BATCH_SIZE = 1000;
$MAX_BATCHES = 50;
$RETENTION_DAYS = (int)(getEnvVar('ASK_DENTATRAK_USAGE_RETENTION_DAYS') ?? 180);
if ($RETENTION_DAYS < 7) {
    $RETENTION_DAYS = 7; // never delete recent telemetry by accident
}

try {
    $deleted = 0;
    for ($i = 0; $i < $MAX_BATCHES; $i++) {
        $n = $pdo->exec(
            "DELETE FROM ask_dentatrak_usage
             WHERE created_at < DATE_SUB(NOW(), INTERVAL {$RETENTION_DAYS} DAY)
             LIMIT {$BATCH_SIZE}"
        );
        $deleted += (int)$n;
        if ($n < $BATCH_SIZE) {
            break;
        }
    }

    $out = json_encode([
        'success' => true,
        'retention_days' => $RETENTION_DAYS,
        'deleted' => $deleted,
    ]);
    echo $out . ($isCli ? PHP_EOL : '');
} catch (Throwable $e) {
    error_log('[ask-dentatrak-maintenance] Cleanup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Cleanup failed']);
}
