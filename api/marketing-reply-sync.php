<?php
/**
 * Marketing reply sync worker.
 *
 * Drains Gmail history for inbound replies to tracked outreach. Invoked by
 * Cloud Scheduler (~every 15 min) with the shared X-Queue-Worker-Token.
 * Read-only against Gmail; never sends mail. Output is PHI-light counts only.
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/queue-worker-auth.php';
require_once __DIR__ . '/marketing/MarketingMailer.php';
require_once __DIR__ . '/marketing/ReplyTracker.php';

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
require_once __DIR__ . '/session.php';
if (!isFeatureEnabled('MARKETING_OUTREACH')) {
    echo json_encode(['success' => true, 'skipped' => 'marketing_outreach_disabled']);
    exit;
}
$baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : '';
$mailer = new MarketingMailer($pdo, $baseUrl);
$tracker = new ReplyTracker($pdo, function (string $path, array $q = []) use ($mailer) {
    return $mailer->gmailGet($path, $q);
}, 'reply-sync');
try {
    $r = $tracker->sync();
    http_response_code(!empty($r['ok']) ? 200 : 502);
    echo json_encode(['success' => !empty($r['ok']), 'result' => $r]);
} catch (Throwable $e) {
    error_log('[reply-sync] fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'sync failed']);
}
