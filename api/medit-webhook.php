<?php
/**
 * Medit Link Webhook Receiver
 *
 * Public endpoint registered in the Medit developer portal:
 *   https://dentatrak.com/api/medit-webhook.php
 *
 * SECURITY CONTRACT (checked in order, every request):
 *   1. POST only, bounded body (64KB).
 *   2. MEDIT_CONNECT flag enabled - 404 otherwise.
 *   3. Signature: the Medit guide documents signSecretKey ("payload
 *      signing, HMAC SHA256") but does NOT specify the delivery header
 *      name. The header is therefore configurable via
 *      MEDIT_WEBHOOK_SIGNATURE_HEADER (default x-meditlink-signature) and
 *      the digest is computed as HMAC-SHA256 of the RAW request body with
 *      MEDIT_WEBHOOK_SIGN_SECRET. UNSET secret -> 503 (fail closed -
 *      nothing is ever accepted unverified).
 *   4. Tenant resolution: the event's group must match a connection's
 *      bound group_uuid (config_json, written from /v1/me at connect
 *      time) AND the connection must be in the active environment AND
 *      an allowlisted practice. A delivery for any other group is
 *      rejected - a webhook can never select a tenant itself.
 *   5. Durability: accepted work is INSERT IGNORE'd into
 *      medit_webhook_events with a dedup key (connection + event +
 *      case uuid + payload hash). Replays/duplicate deliveries collapse
 *      onto the same row - processing is idempotent. NO downloads or
 *      vendor calls happen inside this request; the per-minute worker
 *      drains the queue (MeditScanSync::drainWebhookEvents).
 *
 * Payload schema note: the stage guide documents endpoint CRUD and the
 * event catalog (case.created/updated, files.*, ...) but does not show
 * a delivered-event JSON example. Extraction is defensive - event code
 * and case/group uuids are pulled from the plausible documented field
 * names - and the bounded raw payload is stored so the worker can
 * re-derive anything else without trusting assumed shapes.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/integrations/MeditConfig.php';
require_once __DIR__ . '/integrations/medit/MeditAuthService.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

function meditWebhookRespond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[medit-webhook] ' . $e->getMessage());
    meditWebhookRespond(500, ['success' => false, 'error' => 'internal']);
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    meditWebhookRespond(405, ['success' => false, 'error' => 'method_not_allowed']);
}

if (!isFeatureEnabled('MEDIT_CONNECT')) {
    meditWebhookRespond(404, ['success' => false, 'error' => 'not_found']);
}

$secret = MeditConfig::webhookSignSecret();
if ($secret === null) {
    // Fail closed: without the configured signing secret no delivery can
    // be verified, so nothing is accepted.
    meditWebhookRespond(503, ['success' => false, 'error' => 'webhook_not_configured']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 64 * 1024) {
    meditWebhookRespond(413, ['success' => false, 'error' => 'payload_too_large']);
}

// Signature verification (HMAC-SHA256 hex of the raw body).
$sigHeader = MeditConfig::webhookSignatureHeader();
$provided = '';
foreach ([$_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $sigHeader))] ?? '',
           apache_request_headers()[$sigHeader] ?? '',
           apache_request_headers()[ucwords($sigHeader, '-')] ?? ''] as $candidate) {
    if (is_string($candidate) && trim($candidate) !== '') {
        $provided = trim($candidate);
        break;
    }
}
$expected = hash_hmac('sha256', $rawBody, $secret);
// Tolerate an optional 'sha256=' prefix (common webhook convention).
$providedHex = preg_replace('/^sha256=/i', '', $provided);
if ($providedHex === '' || !hash_equals($expected, $providedHex)) {
    meditWebhookRespond(401, ['success' => false, 'error' => 'invalid_signature']);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    meditWebhookRespond(400, ['success' => false, 'error' => 'invalid_json']);
}

// ----------------------------------------------------------------------
// Defensive field extraction (delivered-payload schema is not shown in
// the guide; these are the documented field spellings).
// ----------------------------------------------------------------------
function meditExtract(array $payload, array $paths): ?string {
    foreach ($paths as $path) {
        $node = $payload;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                $node = null;
                break;
            }
            $node = $node[$key];
        }
        if (is_string($node) && $node !== '') {
            return substr($node, 0, 128);
        }
    }
    return null;
}

$eventCode = meditExtract($payload, ['event', 'eventType', 'event_type', 'code', 'type', 'event.code']) ?? '';
$caseUuid  = meditExtract($payload, [
    'caseUuid', 'case_uuid', 'uuid', 'case.uuid', 'data.caseUuid', 'data.uuid',
    'resource', 'resourceUuid', 'resource_uuid',
]);
$groupUuid = meditExtract($payload, [
    'groupUuid', 'group_uuid', 'group.uuid', 'data.groupUuid', 'data.group_uuid',
]);

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    meditWebhookRespond(500, ['success' => false, 'error' => 'internal']);
}

// ----------------------------------------------------------------------
// Tenant resolution: only authorized connections in the ACTIVE
// environment. A group uuid resolves to exactly its bound connection;
// without one, a delivery only resolves when exactly ONE active medit
// connection exists globally (staging topology), otherwise it cannot be
// attributed and is rejected.
// ----------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT * FROM integration_connections
    WHERE provider = :p AND status IN ('active', 'error')
");
$stmt->execute([':p' => MeditAuthService::PROVIDER]);
$candidates = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $connection) {
    if (!MeditConfig::connectionMatchesEnvironment($connection)) {
        continue;
    }
    if (!MeditConfig::isPracticeAllowed((int)$connection['practice_id'])) {
        continue;
    }
    $config = json_decode((string)($connection['config_json'] ?? ''), true);
    $boundGroup = is_array($config) ? (string)($config['group_uuid'] ?? '') : '';
    if ($groupUuid !== null && $boundGroup !== '' && !hash_equals($boundGroup, $groupUuid)) {
        continue; // event for a different group - never this tenant
    }
    $candidates[] = $connection;
}

if (count($candidates) !== 1) {
    // Zero = unauthorized/unknown tenant; many = ambiguous attribution.
    meditWebhookRespond(404, ['success' => false, 'error' => 'unresolved_tenant']);
}
$connection = $candidates[0];
$connectionId = (int)$connection['id'];
$practiceId = (int)$connection['practice_id'];

// ----------------------------------------------------------------------
// Durable, idempotent store - processing never happens inline.
// ----------------------------------------------------------------------
$dedupKey = hash('sha256',
    $connectionId . '|' . $eventCode . '|' . ($caseUuid ?? '') . '|' . hash('sha256', $rawBody));

$ins = $pdo->prepare("
    INSERT IGNORE INTO medit_webhook_events
        (connection_id, practice_id, event_code, case_uuid, payload_json, dedup_key)
    VALUES (:cid, :pid, :code, :case, :payload, :dedup)
");
$ins->execute([
    ':cid'     => $connectionId,
    ':pid'     => $practiceId,
    ':code'    => substr($eventCode, 0, 64),
    ':case'    => $caseUuid !== null && $caseUuid !== '' ? substr($caseUuid, 0, 64) : null,
    ':payload' => substr($rawBody, 0, 4096),
    ':dedup'   => $dedupKey,
]);

meditWebhookRespond(200, [
    'success' => true,
    'received' => true,
    'duplicate' => $ins->rowCount() === 0,
]);
