<?php
/**
 * Integration Event Worker
 *
 * Drains queued inbound PMS events (integration_external_events) recorded by
 * the webhook endpoints. Designed for Cloud Scheduler invoking this Cloud Run
 * endpoint every minute; safe under overlapping invocations because
 * IntegrationEvents claims rows atomically.
 *
 * Auth: shared-secret X-Queue-Worker-Token header (same mechanism as
 * notification-queue-worker.php). POST only. Never accepts caller-supplied
 * connection/event identifiers. Output is PHI-free counts only.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/queue-worker-auth.php';
require_once __DIR__ . '/integrations/IntegrationEvents.php';
require_once __DIR__ . '/integrations/register-adapters.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireQueueWorkerToken();

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database not available']);
    exit;
}

if (!isFeatureEnabled('SHOW_PMS_INTEGRATIONS')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'PMS integrations are disabled']);
    exit;
}

$BATCH_SIZE = (int)(getEnvVar('INTEGRATION_EVENT_WORKER_BATCH_SIZE') ?? 25);
$BATCH_SIZE = max(1, min(200, $BATCH_SIZE));

try {
    $summary = IntegrationEvents::processDue($pdo, $BATCH_SIZE);
    echo json_encode(['success' => true, 'totals' => $summary]);
} catch (Throwable $e) {
    error_log('[integration-event-worker] Batch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Batch processing failed']);
}
