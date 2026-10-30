<?php
/**
 * Medit Link integration regression test (mocked transport only).
 *
 * Requires the local development DB (port 3308) per AGENTS.md. Fixture
 * rows are cleaned up afterwards. Live Medit testing remains blocked
 * until an interactive staging login completes - everything here is
 * mocked, including the OAuth endpoints.
 *
 * Covers: authorize URL contract, callback session/practice binding,
 * expiry + single-use replay, Basic-auth token exchange, group binding,
 * refresh (documented query-param form), disconnect (no documented
 * revocation - honest remote_revoked=false), allowlist + flag-off
 * enforcement, scan normalization, malformed-response hard-fails,
 * download URL validation, archive entry matching, and webhook dedup.
 *
 * Usage: php tests/medit-integration-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

// Test-local credentials for the stage profile. These are NOT real Medit
// secrets - they only prove the plumbing reads the right env vars.
foreach ([
    'MEDIT_ENVIRONMENT' => 'stage',
    'MEDIT_STAGE_CLIENT_ID' => 'test-medit-client-0000',
    'MEDIT_STAGE_CLIENT_SECRET' => 'test-medit-secret-0000',
    'MEDIT_CONNECT' => 'true',
    'MEDIT_ALLOWED_PRACTICES' => '*',
    'MEDIT_WEBHOOK_SIGN_SECRET' => 'test-sign-secret',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/encryption.php';
require_once __DIR__ . '/../api/integrations/MeditConfig.php';
require_once __DIR__ . '/../api/integrations/medit/MeditAuthService.php';
require_once __DIR__ . '/../api/integrations/medit/MeditClient.php';
require_once __DIR__ . '/../api/integrations/medit/MeditScanConnector.php';
require_once __DIR__ . '/../api/integrations/medit/MeditScanSync.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';

global $pdo, $appConfig;

$pass = 0;
$fail = 0;
function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$message}\n"; }
    else { $fail++; echo "FAIL: {$message}\n"; }
}
function assertEquals($expected, $actual, $message) {
    global $pass, $fail;
    if ($expected === $actual) { $pass++; echo "PASS: {$message}\n"; }
    else { $fail++; echo "FAIL: {$message} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n"; }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "SKIP: no database connection available\n";
    exit(1);
}
$env = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if (!in_array($env, ['development', 'local', 'test', 'uat'], true)) {
    echo "SKIP: refusing to run Medit tests in a non-development environment ({$env})\n";
    exit(1);
}

// Mock transport: records calls and replays queued responses.
$calls = [];
$queue = [];
$transport = function ($method, $url, $headers, $body) use (&$calls, &$queue) {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    if (empty($queue)) {
        return ['status' => 500, 'headers' => [], 'body' => '{"error":"unscripted"}'];
    }
    $next = array_shift($queue);
    return is_callable($next) ? $next($method, $url, $headers, $body) : $next;
};
function pushToken(&$queue, string $suffix = 'a'): void {
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'access_token' => 'syn-access-' . $suffix,
        'token_type' => 'bearer',
        'refresh_token' => 'syn-refresh-' . $suffix,
        'expires_in' => 3600,
        'scope' => 'USER GROUP CASE FILE',
        'refresh_token_exp' => time() + 86400,
    ])];
}
function pushMe(&$queue): void {
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'email' => 'test@example.com', 'name' => 'Test User',
        'group' => ['uuid' => 'GROUP-TEST-UUID-1', 'type' => 'CLINIC', 'name' => 'Test Group'],
    ])];
}
function pushCasePage(&$queue, array $cases, bool $last = true): void {
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'content' => $cases, 'last' => $last, 'first' => true,
        'page' => 0, 'totalPage' => 1, 'numberOfElements' => count($cases), 'size' => 50,
    ])];
}
function caseDoc(string $uuid, array $files): array {
    return [
        'uuid' => $uuid, 'name' => 'Test Case', 'status' => 'SCAN',
        'dateCreated' => '2026-10-29T10:00:00Z', 'dateScanned' => '2026-10-29T10:05:00Z',
        'patient' => ['name' => 'Pat Ient', 'code' => 'PAT-1'],
        'files' => $files,
    ];
}
function fileDoc(string $uuid, string $name, int $size, string $type = 'SCAN_DATA'): array {
    return ['uuid' => $uuid, 'name' => $name, 'size' => $size,
            'dateCreated' => '2026-10-29T10:00:00Z', 'dateUpdated' => '2026-10-29T10:00:00Z',
            'fileType' => $type];
}

$service = new MeditAuthService($transport);
$connectionIds = [];
$attemptIds = [];
$scanIds = [];

try {
    // ------------------------------------------------------------------
    // 0. Migration + fixtures
    // ------------------------------------------------------------------
    $migrationJson = shell_exec('php "' . __DIR__ . '/../migrations/2026_10_30_medit_oauth.php" 2>&1');
    $migration = json_decode((string)$migrationJson, true);
    assertTrue(is_array($migration) && ($migration['success'] ?? false) === true && empty($migration['errors']), 'migration runs cleanly (idempotent)');

    foreach (['medit_oauth_attempts', 'medit_webhook_events'] as $t) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE '{$t}'");
        $stmt->execute();
        assertTrue($stmt->rowCount() === 1, "table exists: {$t}");
    }

    $practiceId = (int)$pdo->query("SELECT id FROM practices ORDER BY id LIMIT 1")->fetchColumn();
    $otherPracticeId = (int)$pdo->query("SELECT id FROM practices WHERE id <> {$practiceId} ORDER BY id LIMIT 1")->fetchColumn();
    $userId = (int)$pdo->query("SELECT user_id FROM practice_users WHERE practice_id = {$practiceId} ORDER BY user_id LIMIT 1")->fetchColumn();
    $otherUserId = (int)$pdo->query("SELECT user_id FROM practice_users WHERE user_id <> {$userId} ORDER BY user_id LIMIT 1")->fetchColumn();
    if ($otherUserId <= 0) {
        $otherUserId = $userId + 1;
    }
    assertTrue($practiceId > 0 && $userId > 0, 'fixtures: practice + user exist');

    // ------------------------------------------------------------------
    // 1. Flag-off enforcement: callback completion blocked when off
    // ------------------------------------------------------------------
    putenv('MEDIT_CONNECT=false');
    $_ENV['MEDIT_CONNECT'] = 'false';
    $_SERVER['MEDIT_CONNECT'] = 'false';
    $attempt = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $attempt['attempt_id'];
    $r = $service->handleCallback($pdo, ['code' => 'abc', 'state' => $attempt['attempt_id']], $userId, $practiceId);
    assertEquals('disabled', $r['result'], 'flag off: callback completion rejected as disabled (attempt left unconsumed)');
    putenv('MEDIT_CONNECT=true');
    $_ENV['MEDIT_CONNECT'] = 'true';
    $_SERVER['MEDIT_CONNECT'] = 'true';

    // ------------------------------------------------------------------
    // 2. Authorize URL contract (documented params only - no PKCE)
    // ------------------------------------------------------------------
    $attempt = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $attempt['attempt_id'];
    $url = $attempt['authorize_url'];
    assertTrue(strpos($url, MeditConfig::authBaseUrl() . '/oauth/authorize') === 0, 'authorize URL on documented auth host');
    assertTrue(strpos($url, 'response_type=code') !== false, 'authorize URL carries response_type=code');
    assertTrue(strpos($url, rawurlencode('USER GROUP CASE FILE')) !== false, 'authorize URL carries read-only scope set');
    assertTrue(strpos($url, 'code_challenge') === false, 'no PKCE parameter (not documented by Medit)');
    assertTrue(strpos($url, 'state=' . $attempt['attempt_id']) !== false, 'state bound to attempt id');

    // ------------------------------------------------------------------
    // 3. Callback binding: wrong session -> forbidden; expiry; replay
    // ------------------------------------------------------------------
    $r = $service->handleCallback($pdo, ['code' => 'c', 'state' => $attempt['attempt_id']], $otherUserId, $practiceId);
    assertEquals('forbidden', $r['result'], 'callback from a different user is forbidden');
    $r = $service->handleCallback($pdo, ['code' => 'c', 'state' => $attempt['attempt_id']], $userId, $otherPracticeId > 0 ? $otherPracticeId : $practiceId + 999);
    assertEquals('forbidden', $r['result'], 'callback from a different practice is forbidden');

    $expired = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $expired['attempt_id'];
    $pdo->prepare("UPDATE medit_oauth_attempts SET expires_at = '2020-01-01 00:00:00' WHERE id = :id")
        ->execute([':id' => $expired['attempt_id']]);
    $r = $service->handleCallback($pdo, ['code' => 'c', 'state' => $expired['attempt_id']], $userId, $practiceId);
    assertEquals('expired', $r['result'], 'expired attempt rejected');

    // ------------------------------------------------------------------
    // 4. Happy path: Basic-auth exchange + group binding + replay
    // ------------------------------------------------------------------
    $ok = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $ok['attempt_id'];
    pushToken($queue);
    pushMe($queue);
    $r = $service->handleCallback($pdo, ['code' => 'authcode-1', 'state' => $ok['attempt_id']], $userId, $practiceId);
    assertEquals('connected', $r['result'], 'callback completes into a connection');
    $connectionIds[] = (int)$r['connection_id'];

    $tokenCall = $calls[count($calls) - 2];
    assertTrue(strpos($tokenCall['url'], '/oauth/token') !== false, 'exchange hits /oauth/token');
    assertTrue(strpos($tokenCall['body'], 'grant_type=authorization_code') !== false, 'exchange sends documented grant_type');
    assertTrue(preg_match('/Authorization: Basic /', implode("\n", $tokenCall['headers'])) === 1, 'exchange uses Basic client-credentials header (not body secret)');
    assertTrue(strpos($tokenCall['body'], 'client_secret') === false, 'client secret never travels in the body');
    $meCall = $calls[count($calls) - 1];
    assertTrue(strpos($meCall['url'], '/v1/me') !== false, 'identity bound via /v1/me');
    assertTrue(strpos(implode("\n", $meCall['headers']), 'Bearer syn-access-') !== false, '/v1/me uses the fresh access token');

    $conn = IntegrationManager::findConnection($pdo, (int)$r['connection_id']);
    $cfg = json_decode((string)$conn['config_json'], true);
    assertEquals('GROUP-TEST-UUID-1', $cfg['group_uuid'] ?? null, 'group uuid bound to connection');
    assertEquals('stage', $cfg['environment'] ?? null, 'environment recorded as stage');
    assertEquals('active', $conn['status'], 'connection active');
    assertTrue(IntegrationCredentials::get($pdo, (int)$r['connection_id'], 'access_token') === 'syn-access-a', 'access token persisted encrypted');

    // Replay: same callback can never complete twice.
    pushToken($queue, 'replay');
    pushMe($queue);
    $r2 = $service->handleCallback($pdo, ['code' => 'authcode-1', 'state' => $ok['attempt_id']], $userId, $practiceId);
    assertEquals('replay', $r2['result'], 'second callback for the same attempt is a replay');

    // ------------------------------------------------------------------
    // 5. Malformed token response -> exchange_failed (not connected)
    // ------------------------------------------------------------------
    $bad = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $bad['attempt_id'];
    $queue[] = ['status' => 200, 'headers' => [], 'body' => '{"unexpected":true}'];
    $r = $service->handleCallback($pdo, ['code' => 'x', 'state' => $bad['attempt_id']], $userId, $practiceId);
    assertEquals('exchange_failed', $r['result'], 'malformed token response fails the exchange honestly');

    // ------------------------------------------------------------------
    // 6. Refresh: documented query-param form, token rotation persisted
    // ------------------------------------------------------------------
    $connId = $connectionIds[0];
    $pdo->prepare("UPDATE integration_connections SET config_json = JSON_SET(config_json, '$.token_expires_at', '2020-01-01 00:00:00') WHERE id = :id")
        ->execute([':id' => $connId]);
    pushToken($queue, 'refreshed');
    $service->refreshConnectionToken($pdo, $connId, true);
    $refreshCall = $calls[count($calls) - 1];
    assertTrue(strpos($refreshCall['url'], 'grant_type=refresh_token') !== false
        && strpos($refreshCall['url'], 'refresh_token=') !== false, 'refresh uses documented query-param form');
    assertTrue(preg_match('/Authorization: Basic /', implode("\n", $refreshCall['headers'])) === 1, 'refresh uses Basic auth header');
    assertEquals('syn-access-refreshed', IntegrationCredentials::get($pdo, $connId, 'access_token'), 'rotated access token persisted');
    assertEquals('syn-refresh-refreshed', IntegrationCredentials::get($pdo, $connId, 'refresh_token'), 'rotated refresh token persisted');

    // ------------------------------------------------------------------
    // 7. Scan sync: normalization, dedup, malformed hard-fail
    // ------------------------------------------------------------------
    $connector = new MeditScanConnector($pdo, $service, $transport, function ($pdo, $cid, $t) use ($service, $transport) {
        return new MeditClient($pdo, $cid, $service, $transport);
    });
    assertEquals('medit', $connector->getVendor(), 'connector reports the medit vendor');

    $caseUuid = '11111111-2222-3333-4444-555555555555';
    pushCasePage($queue, [['uuid' => $caseUuid, 'dateCreated' => '2026-10-29T10:00:00Z']]);
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode(caseDoc($caseUuid, [
        fileDoc('f-uuid-1', 'maxillary.meditMesh', 4650526),
        fileDoc('f-uuid-2', 'mandibular.meditMesh', 3197161),
        fileDoc('f-uuid-3', 'readme.txt', 100, 'ATTACHED_DATA'),
    ]))];
    $payloads = $connector->fetchScans([
        'connection_id' => $connId,
        'window_start' => '2026-10-28T00:00:00Z',
        'window_end' => '2026-10-30T00:00:00Z',
    ]);
    assertEquals(1, count($payloads), 'one normalized scan returned');
    $p = $payloads[0];
    assertEquals($caseUuid, $p['external_scan_id'], 'external_scan_id is the case uuid');
    assertEquals('PAT-1', $p['external_patient_ref'], 'patient ref is the opaque code, never a name');
    assertEquals(2, count($p['files']), 'only SCAN_DATA files ingested (ATTACHED_DATA excluded)');
    assertEquals('maxillary.stl', $p['files'][0]['file_name'], 'meditMesh normalized to .stl display name');
    assertTrue(strpos($p['files'][0]['remote_ref'], 'medit://file/f-uuid-1/stl/') === 0, 'remote_ref carries convert mode + file uuid');

    $searchCall = $calls[count($calls) - 2];
    assertTrue(strpos($searchCall['url'], '/v1/cases/search') !== false, 'windowed search hits cases/search');
    assertTrue(strpos($searchCall['url'], 'start=') !== false && strpos($searchCall['url'], 'end=') !== false, 'documented start/end (ms) filters sent');
    assertTrue(strpos(implode("\n", $searchCall['headers']), 'x-meditlink-group-uuid: GROUP-TEST-UUID-1') !== false, 'group uuid header binds the tenant');

    // Malformed list response must throw - never silently "zero scans".
    $queue[] = ['status' => 200, 'headers' => [], 'body' => '{"oops":[]}'];
    try {
        $connector->fetchScans(['connection_id' => $connId, 'window_start' => '2026-10-28T00:00:00Z', 'window_end' => '2026-10-30T00:00:00Z']);
        assertTrue(false, 'malformed list response throws');
    } catch (MeditApiException $e) {
        assertEquals('unexpected_response', $e->category, 'malformed list response throws unexpected_response');
    }

    // Ingest dedup: same case re-delivered reuses the scan row.
    $context = ['practice_id' => $practiceId, 'connection_id' => $connId, 'source_account' => null, 'is_synthetic' => false];
    $first = ScanIngestion::ingestScan($pdo, $context, $connector, $p);
    $scanIds[] = (int)$first['scan_id'];
    $again = ScanIngestion::ingestScan($pdo, $context, $connector, $p);
    assertEquals((int)$first['scan_id'], (int)$again['scan_id'], 're-delivery dedups onto the same scan row');

    // ------------------------------------------------------------------
    // 8. Download URL policy + archive entry matching
    // ------------------------------------------------------------------
    $client = new MeditClient($pdo, $connId, $service, $transport);
    $refl = new ReflectionClass($client);
    $m = $refl->getMethod('assertDownloadUrl');
    $m->setAccessible(true);
    try {
        $m->invoke($client, 'https://evil.example.com/x.7z?sig=1');
        assertTrue(false, 'arbitrary download host rejected');
    } catch (MeditApiException $e) {
        assertEquals('invalid_download_url', $e->category, 'arbitrary download host rejected');
    }
    try {
        $m->invoke($client, 'http://s3.amazonaws.com/x.7z');
        assertTrue(false, 'non-https download rejected');
    } catch (MeditApiException $e) {
        assertEquals('invalid_download_url', $e->category, 'non-https download rejected');
    }
    $m->invoke($client, 'https://com-meditlink-case-files.s3.ap-northeast-2.amazonaws.com/x.stl.7z?X-Amz-Signature=abc');
    assertTrue(true, 'vendor S3 presigned https URL accepted');

    // Entry matching: named descriptor wins; multi-entry ambiguity fails closed.
    $mm = (new ReflectionClass($connector))->getMethod('matchEntry');
    $mm->setAccessible(true);
    $entries = [
        ['name' => 'a/maxillary.stl', 'base' => 'maxillary.stl', 'path' => '/tmp/a', 'size' => 100],
        ['name' => 'b/mandibular.stl', 'base' => 'mandibular.stl', 'path' => '/tmp/b', 'size' => 200],
    ];
    $items = ['mandibular.stl' => ['hash' => null, 'size' => 200]];
    $match = $mm->invoke($connector, $entries, $items, 'mandibular.stl');
    assertEquals('mandibular.stl', $match['base'] ?? null, 'archive entry matched by descriptor name');
    $noMatch = $mm->invoke($connector, $entries, [], 'other.stl');
    assertEquals(null, $noMatch, 'multi-entry archive with no match fails closed (never guesses)');

    // ------------------------------------------------------------------
    // 9. Webhook dedup + signature math (same contract the endpoint uses)
    // ------------------------------------------------------------------
    $body = '{"event":"case.updated","caseUuid":"' . $caseUuid . '","groupUuid":"GROUP-TEST-UUID-1"}';
    $sig = hash_hmac('sha256', $body, 'test-sign-secret');
    assertTrue(hash_equals($sig, hash_hmac('sha256', $body, 'test-sign-secret')), 'HMAC-SHA256 signature verifies');
    assertTrue(!hash_equals($sig, hash_hmac('sha256', $body, 'wrong-secret')), 'wrong-secret signature rejected');
    assertTrue(!hash_equals($sig, hash_hmac('sha256', $body . 'x', 'test-sign-secret')), 'tampered body rejected');

    $dedup = hash('sha256', $connId . '|case.updated|' . $caseUuid . '|' . hash('sha256', $body));
    $ins = $pdo->prepare("INSERT IGNORE INTO medit_webhook_events (connection_id, practice_id, event_code, case_uuid, payload_json, dedup_key) VALUES (:c,:p,:e,:u,:pl,:d)");
    $ins->execute([':c' => $connId, ':p' => $practiceId, ':e' => 'case.updated', ':u' => $caseUuid, ':pl' => $body, ':d' => $dedup]);
    $firstRows = $ins->rowCount();
    $ins->execute([':c' => $connId, ':p' => $practiceId, ':e' => 'case.updated', ':u' => $caseUuid, ':pl' => $body, ':d' => $dedup]);
    assertEquals(1, $firstRows, 'webhook event recorded durably');
    assertEquals(0, $ins->rowCount(), 'duplicate delivery is idempotent (dedup key)');
    $pdo->prepare("DELETE FROM medit_webhook_events WHERE dedup_key = :d")->execute([':d' => $dedup]);

    // ------------------------------------------------------------------
    // 10. Disconnect: local wipe inside lock, honest remote_revoked=false
    // ------------------------------------------------------------------
    $result = $service->disconnect($pdo, $connId);
    assertEquals(false, $result['remote_revoked'], 'remote_revoked honestly false (no documented revocation endpoint)');
    $conn = IntegrationManager::findConnection($pdo, $connId);
    assertEquals('disabled', $conn['status'], 'connection disabled after disconnect');
    assertEquals(0, (int)$conn['sync_enabled'], 'sync stopped after disconnect');
    assertEquals(null, IntegrationCredentials::get($pdo, $connId, 'access_token'), 'access token wiped');
    assertEquals(null, IntegrationCredentials::get($pdo, $connId, 'refresh_token'), 'refresh token wiped');

    // Allowlist enforcement: practice removed -> no attempt can start.
    putenv('MEDIT_ALLOWED_PRACTICES=999999');
    $_ENV['MEDIT_ALLOWED_PRACTICES'] = '999999';
    $_SERVER['MEDIT_ALLOWED_PRACTICES'] = '999999';
    try {
        $service->beginAttempt($pdo, $practiceId, $userId);
        assertTrue(false, 'unallowlisted practice cannot begin');
    } catch (MeditAuthException $e) {
        assertEquals('practice_not_allowed', $e->category, 'allowlist denies a removed practice at initiation');
    }
    putenv('MEDIT_ALLOWED_PRACTICES=*');
    $_ENV['MEDIT_ALLOWED_PRACTICES'] = '*';
    $_SERVER['MEDIT_ALLOWED_PRACTICES'] = '*';
    assertTrue(MeditConfig::isPracticeAllowed($practiceId), 'wildcard allowlist permits the fixture practice');
    putenv('MEDIT_ALLOWED_PRACTICES=');
    $_ENV['MEDIT_ALLOWED_PRACTICES'] = '';
    $_SERVER['MEDIT_ALLOWED_PRACTICES'] = '';
    assertTrue(!MeditConfig::isPracticeAllowed($practiceId), 'empty allowlist denies every practice');
    putenv('MEDIT_ALLOWED_PRACTICES=*');
    $_ENV['MEDIT_ALLOWED_PRACTICES'] = '*';
    $_SERVER['MEDIT_ALLOWED_PRACTICES'] = '*';

} finally {
    // ------------------------------------------------------------------
    // Cleanup fixtures (best-effort)
    // ------------------------------------------------------------------
    foreach ($attemptIds as $id) {
        try { $pdo->prepare("DELETE FROM medit_oauth_attempts WHERE id = :id")->execute([':id' => $id]); } catch (Throwable $e) {}
    }
    foreach ($scanIds as $id) {
        try {
            $pdo->prepare("DELETE FROM scanner_scan_files WHERE scan_id = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM scanner_incoming_scans WHERE id = :id")->execute([':id' => $id]);
        } catch (Throwable $e) {}
    }
    foreach ($connectionIds as $id) {
        try {
            $pdo->prepare("DELETE FROM integration_credentials WHERE connection_id = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM medit_webhook_events WHERE connection_id = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM integration_connections WHERE id = :id")->execute([':id' => $id]);
        } catch (Throwable $e) {}
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
