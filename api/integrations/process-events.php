<?php
/**
 * Integration external-event drainer (Phase D - Events POC)
 *
 * Processes queued inbound PMS events stored by the webhook endpoints
 * (open-dental-events.php). For each due event it resolves the connection,
 * fetches the current external record through the provider adapter, and
 * normalizes it to CanonicalCase - then stops. No DentaTrak case writes.
 *
 * CLI-only. In production the same drain runs via Cloud Scheduler ->
 * /api/integration-event-worker.php (QUEUE_WORKER_TOKEN-authenticated);
 * locally use --watch for a continuous loop or run once for a manual drain.
 * There is deliberately no unauthenticated HTTP trigger for processing.
 *
 * Usage:
 *   php api/integrations/process-events.php [--limit=25] [--watch[=seconds]]
 *
 * Output is PHI-free: counts and event/connection ids only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/IntegrationManager.php';
require_once __DIR__ . '/IntegrationEvents.php';
require_once __DIR__ . '/register-adapters.php';

global $pdo;

$limit = 25;
$watchSeconds = 0;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif ($arg === '--watch') {
        $watchSeconds = 30;
    } elseif (strpos($arg, '--watch=') === 0) {
        $watchSeconds = max(5, (int)substr($arg, 8));
    }
}

if (!isFeatureEnabled('SHOW_PMS_INTEGRATIONS')) {
    echo "PMS integrations feature flag is disabled; nothing to process.\n";
    exit(0);
}

$drain = function () use ($pdo, $limit) {
    $summary = IntegrationEvents::processDue($pdo, $limit);
    echo date('H:i:s') . " drained: claimed={$summary['claimed']} processed={$summary['processed']}"
        . " entity_gone={$summary['entity_gone']} retry={$summary['retry']}"
        . " failed={$summary['failed']} duplicate={$summary['duplicate']}\n";
};

$drain();
while ($watchSeconds > 0) {
    sleep($watchSeconds);
    $drain();
}
