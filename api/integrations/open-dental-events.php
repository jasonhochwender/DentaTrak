<?php
/**
 * Open Dental API Events inbound webhook (Phase D - Events POC)
 *
 * Receives WatchTable event deliveries from the practice's Open Dental
 * workstation/API service. This endpoint is intentionally SESSION-FREE:
 * the caller is Open Dental, not a browser.
 *
 * AUTHENTICATION MODEL (per official docs - Open Dental provides no HMAC):
 *   1. ?c={connection_id}&k={token} - the per-connection callback token in
 *      the registered EndPointUrl. Stored server-side only as sha256;
 *      verified with hash_equals.
 *   2. Authorization header carries the CUSTOMER API KEY (sent by Open
 *      Dental on every event). Compared with hash_equals against the
 *      decrypted stored credential. An attacker would need both the URL
 *      token AND the practice's API key to forge a delivery.
 *
 * PROCESSING MODEL: accept -> dedup -> store -> 200 fast. The actual
 * LabCase GET happens in the drainer (IntegrationEvents::processDue),
 * because OD read requests are throttled (~1/5s on ApiReadAll) and the
 * webhook must not block on them.
 *
 * RESPONSE SEMANTICS: 2xx acknowledges delivery (advances OD's cursor).
 * Non-2xx marks the delivery failed and triggers OD redelivery - used only
 * for genuinely unacceptable requests (bad auth, wrong event type,
 * malformed body), never for downstream fetch problems.
 */

require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/IntegrationManager.php';
require_once __DIR__ . '/IntegrationCredentials.php';
require_once __DIR__ . '/IntegrationEvents.php';
require_once __DIR__ . '/register-adapters.php';

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('[od-events] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
    exit;
});

function odEventFail(int $status): void {
    // Deliberately no error detail: this endpoint is internet-facing and
    // its only legitimate caller is Open Dental, which treats non-2xx as
    // "retry later" regardless of body content.
    http_response_code($status);
    echo json_encode(['success' => false]);
    exit;
}

function odEventOk(array $extra = []): void {
    echo json_encode(['success' => true] + $extra);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    odEventFail(405);
}

// Generous cap: documented max is 1000 labcase rows per delivery.
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4 * 1024 * 1024) {
    odEventFail(413);
}

$connectionId = (int)($_GET['c'] ?? 0);
$callbackKey  = (string)($_GET['k'] ?? '');
if ($connectionId <= 0 || $callbackKey === '') {
    odEventFail(404);
}

$connection = IntegrationManager::findConnection($pdo, $connectionId);
if (!$connection || ($connection['provider'] ?? '') !== 'open_dental') {
    odEventFail(404);
}

$sub = IntegrationEvents::getSubscriptionConfig($connection);
if ($sub === null) {
    odEventFail(404); // no subscription registered through us
}

// Factor 1: callback token (constant-time compare against stored hash).
$expectedHash = (string)($sub['callback_key_hash'] ?? '');
if ($expectedHash === '' || !hash_equals($expectedHash, IntegrationEvents::hashCallbackToken($callbackKey))) {
    odEventFail(403);
}

// Factor 2: Authorization header must be the stored customer API key.
// Apache does not always surface arbitrary Authorization headers in
// $_SERVER (CGI/FastCGI strip them unless CGIPassAuth is enabled), so fall
// back to getallheaders()/REDIRECT_HTTP_AUTHORIZATION before rejecting.
$authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($authHeader === '') {
    $authHeader = trim((string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
}
if ($authHeader === '' && function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $hName => $hValue) {
        if (strcasecmp((string)$hName, 'authorization') === 0) {
            $authHeader = trim((string)$hValue);
            break;
        }
    }
}
$credentials = IntegrationManager::getConnectionCredentials($pdo, $connectionId);
$customerKey = (string)($credentials['customer_key'] ?? '');
if ($customerKey === '' || !hash_equals($customerKey, $authHeader)) {
    odEventFail(403);
}

// From here the request is authenticated. A disabled feature flag or
// connection means a straggler delivery - acknowledge (200) and drop so
// Open Dental does not redelivery-loop a subscription we already retired.
if (!isFeatureEnabled('SHOW_PMS_INTEGRATIONS')
    || ($connection['status'] ?? '') !== 'active'
    || empty($sub['enabled'])) {
    odEventOk(['dropped' => true]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 4 * 1024 * 1024) {
    odEventFail(413);
}

$eventType = (string)($_SERVER['HTTP_EVENT_TYPE'] ?? '');

$adapter = IntegrationManager::getAdapter($connection);
try {
    // parseWatchTableEvent is defined on OpenDentalAdapter; guard for the
    // theoretical case of a different adapter class being registered.
    if (!method_exists($adapter, 'parseWatchTableEvent')) {
        odEventFail(400);
    }
    $parsed = $adapter->parseWatchTableEvent($rawBody, $eventType);
} catch (Throwable $e) {
    odEventFail(400);
}

$entityType = $parsed['entity_type'] . (!empty($parsed['deleted']) ? '_deleted' : '');
$new = 0;
$duplicates = 0;
foreach ($parsed['rows'] as $row) {
    $dedupKey = OpenDentalAdapter::eventDedupKey($row['external_id'], $row['watermark'], $row['row']);
    $r = IntegrationEvents::record(
        $pdo, $connectionId, $entityType, $row['external_id'], $dedupKey, $row['watermark']
    );
    $r['inserted'] ? $new++ : $duplicates++;
}

// Stamp delivery health on the subscription block (metadata only).
IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
    'last_delivery_at' => gmdate('Y-m-d H:i:s'),
]);

odEventOk(['received' => count($parsed['rows']), 'new' => $new, 'duplicates' => $duplicates]);
