<?php
/**
 * DS Core OAuth connection tests.
 *
 * Exercises the connection foundation with a MOCKED transport - no network
 * I/O, no real DS Core credentials, synthetic tokens only:
 *
 *   - attempt lifecycle: fresh verifier per attempt, encrypted at rest,
 *     documented authorize URL shape (client_id, code_challenge, redirect_uri,
 *     state), 15-minute expiry
 *   - callback: success connects + stores encrypted tokens; cancelled,
 *     invalid, expired, replayed, and cross-practice/user callbacks rejected
 *   - refresh: documented token:refresh body (snake_case refresh_token/
 *     client_id/client_secret), rotates + persists replacement tokens,
 *     serialized via MySQL GET_LOCK, bounded retries on transient errors,
 *     invalid refresh credentials -> reconnect_required
 *   - business-call helper: one 401 -> one refresh + one retry, never a loop
 *   - disconnect deletes tokens and disables the connection
 *   - secrets never land in config_json or public projections
 *
 * Requires the local development DB (port 3308) per AGENTS.md. Fixture rows
 * are cleaned up afterwards. Live DS Core testing remains blocked until the
 * sandbox Client ID is issued - everything here is mocked.
 *
 * Usage: php tests/dscore-auth-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

// Test-local credentials for the sandbox profile. These are NOT real DS
// Core secrets - they only prove the plumbing reads the right env vars.
putenv('DS_CORE_ENVIRONMENT=sandbox');
putenv('DS_CORE_SANDBOX_CLIENT_ID=test-client-id-0000');
putenv('DS_CORE_SANDBOX_CLIENT_SECRET=test-client-secret-0000');
putenv('DS_CORE_CONNECT=true');
$_ENV['DS_CORE_ENVIRONMENT'] = 'sandbox';
$_ENV['DS_CORE_SANDBOX_CLIENT_ID'] = 'test-client-id-0000';
$_ENV['DS_CORE_SANDBOX_CLIENT_SECRET'] = 'test-client-secret-0000';
$_ENV['DS_CORE_CONNECT'] = 'true';
$_SERVER['DS_CORE_ENVIRONMENT'] = 'sandbox';
$_SERVER['DS_CORE_SANDBOX_CLIENT_ID'] = 'test-client-id-0000';
$_SERVER['DS_CORE_SANDBOX_CLIENT_SECRET'] = 'test-client-secret-0000';
$_SERVER['DS_CORE_CONNECT'] = 'true';

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/encryption.php';
require_once __DIR__ . '/../api/integrations/DsCoreConfig.php';
require_once __DIR__ . '/../api/integrations/dscore/DsCoreAuthService.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';

global $pdo, $appConfig;

$pass = 0;
$fail = 0;

function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

function assertEquals($expected, $actual, $message) {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "SKIP: no database connection available\n";
    exit(1);
}

$env = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if (!in_array($env, ['development', 'local', 'test', 'uat'], true)) {
    echo "SKIP: refusing to run DS Core tests in a non-development environment ({$env})\n";
    exit(1);
}

// -------------------------------------------------------------------------
// Mock transport: records calls and replays queued responses.
// -------------------------------------------------------------------------
$calls = [];
$queue = [];
$transport = function ($method, $url, $headers, $body) use (&$calls, &$queue) {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    if (empty($queue)) {
        return ['status' => 500, 'headers' => [], 'body' => '{"error":"unscripted"}'];
    }
    $next = array_shift($queue);
    if (is_callable($next)) {
        return $next($method, $url, $headers, $body);
    }
    return $next;
};
function pushTokenResponse(&$queue, string $suffix = 'a', bool $stringExpiresIn = false): void {
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'accessToken'  => 'synthetic-access-' . $suffix,
        'refreshToken' => 'synthetic-refresh-' . $suffix,
        'scope'        => 'p-eu-west4-dscore external_api r2-dscore',
        'tokenType'    => 'Bearer',
        // expiresIn is documented as an INTEGER (§4.1/§4.2). The
        // $stringExpiresIn variant exercises the parser's defensive
        // numeric-string tolerance only.
        'expiresIn'    => $stringExpiresIn ? '3599' : 3599,
    ])];
}

$service = new DsCoreAuthService($transport);
$connectionIds = [];
$attemptIds = [];

try {
    // ------------------------------------------------------------------
    // 0. Migration + fixtures
    // ------------------------------------------------------------------
    $migrationJson = shell_exec('php "' . __DIR__ . '/../migrations/2026_10_22_dscore_oauth.php" 2>&1');
    $migration = json_decode((string)$migrationJson, true);
    assertTrue(is_array($migration) && ($migration['success'] ?? false) === true && empty($migration['errors']), 'migration runs cleanly (idempotent)');

    $stmt = $pdo->prepare("SHOW TABLES LIKE 'dscore_oauth_attempts'");
    $stmt->execute();
    assertTrue($stmt->rowCount() === 1, 'table exists: dscore_oauth_attempts');

    $practiceId = (int)$pdo->query("SELECT id FROM practices ORDER BY id LIMIT 1")->fetchColumn();
    $otherPracticeId = (int)$pdo->query("SELECT id FROM practices WHERE id <> {$practiceId} ORDER BY id LIMIT 1")->fetchColumn();
    $userId = (int)$pdo->query("SELECT user_id FROM practice_users WHERE practice_id = {$practiceId} ORDER BY user_id LIMIT 1")->fetchColumn();
    $otherUserId = (int)$pdo->query("SELECT user_id FROM practice_users WHERE practice_id = {$practiceId} AND user_id <> {$userId} ORDER BY user_id LIMIT 1")->fetchColumn();
    if ($otherUserId === 0) {
        $otherUserId = $userId + 999999;
    }
    assertTrue($practiceId > 0 && $userId > 0, 'fixture practice + user exist');

    // Practice allowlist (DS_CORE_ALLOWED_PRACTICES): opt the two fixture
    // practices in. Everything else is denied - including initiation.
    putenv("DS_CORE_ALLOWED_PRACTICES={$practiceId},{$otherPracticeId}");
    $_ENV['DS_CORE_ALLOWED_PRACTICES'] = "{$practiceId},{$otherPracticeId}";
    $_SERVER['DS_CORE_ALLOWED_PRACTICES'] = "{$practiceId},{$otherPracticeId}";
    assertTrue(DsCoreConfig::isPracticeAllowed($practiceId), 'fixture practice allowlisted');
    assertTrue(DsCoreConfig::isPracticeAllowed($otherPracticeId), 'second fixture practice allowlisted');
    assertTrue(!DsCoreConfig::isPracticeAllowed($otherPracticeId + 999999), 'unlisted practice id denied');
    try {
        $service->beginAttempt($pdo, $otherPracticeId + 999999, $userId);
        assertTrue(false, 'beginAttempt refused for unlisted practice');
    } catch (DsCoreAuthException $e) {
        assertEquals('practice_not_allowed', $e->category, 'beginAttempt refused for unlisted practice');
    }

    // Prior-run leftovers
    $pdo->exec("DELETE FROM dscore_oauth_attempts WHERE 1=1");
    $pdo->exec("DELETE c, r FROM integration_connections c LEFT JOIN integration_credentials r ON r.connection_id = c.id WHERE c.provider = 'ds_core'");
    $pdo->exec("DELETE FROM integration_connections WHERE provider = 'ds_core'");

    // ------------------------------------------------------------------
    // 1. Config + flag gating
    // ------------------------------------------------------------------
    assertTrue(isFeatureEnabled('DS_CORE_CONNECT'), 'DS_CORE_CONNECT enabled via env in test');
    assertEquals('sandbox', DsCoreConfig::environment(), 'defaults to sandbox environment');
    assertEquals('https://api.r2.dscore.com', DsCoreConfig::authBaseUrl(), 'sandbox login host is REGIONAL api.r2.dscore.com');
    assertEquals('https://api.dscore.com', DsCoreConfig::apiBaseUrl(), 'token endpoints use GLOBAL api.dscore.com even in sandbox');
    assertTrue(DsCoreConfig::isConfigured(), 'client credentials detected from env');

    $base = rtrim((string)$appConfig['app_base_url'], '/');
    assertEquals($base . '/api/dscore-callback.php', DsCoreAuthService::redirectUri(), 'redirect URI follows app_base_url convention');

    // ------------------------------------------------------------------
    // 2. Begin attempt: fresh verifier, documented URL, encrypted at rest
    // ------------------------------------------------------------------
    $a1 = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $a1['attempt_id'];

    $url = $a1['authorize_url'];
    assertTrue(strpos($url, 'https://api.r2.dscore.com/secureLogin?') === 0, 'authorize URL hits documented /secureLogin');
    assertTrue(strpos($url, 'client_id=' . 'test-client-id-0000') !== false, 'authorize URL carries client_id');
    assertTrue(strpos($url, 'code_challenge=') !== false, 'authorize URL carries code_challenge');
    assertTrue(strpos($url, 'redirect_uri=' . rawurlencode(DsCoreAuthService::redirectUri())) !== false, 'authorize URL carries exact redirect_uri');
    assertTrue(strpos($url, 'state=' . $a1['attempt_id']) !== false, 'authorize URL carries state=attempt id');
    assertTrue(strpos($url, 'code_challenge_method') === false, 'no undocumented code_challenge_method sent');
    assertTrue(strpos($url, 'response_type') === false, 'no undocumented response_type sent');
    assertTrue(strpos($url, 'client_secret') === false, 'client secret never in authorize URL');

    $row = $pdo->prepare("SELECT * FROM dscore_oauth_attempts WHERE id = :id");
    $row->execute([':id' => $a1['attempt_id']]);
    $attempt = $row->fetch(PDO::FETCH_ASSOC);
    assertTrue($attempt !== false, 'attempt row persisted');
    assertEquals($practiceId, (int)$attempt['practice_id'], 'attempt bound to practice');
    assertEquals($userId, (int)$attempt['user_id'], 'attempt bound to initiating user');
    assertTrue(strtotime($attempt['expires_at']) > time() && strtotime($attempt['expires_at']) <= time() + 900,
        'attempt expires within 15 minutes');
    assertTrue($attempt['consumed_at'] === null, 'attempt unconsumed at start');

    // Verifier is stored encrypted (ciphertext != plaintext it yields) and
    // decrypts to the verifier that produced the documented challenge.
    $cipher = (string)$attempt['code_verifier_encrypted'];
    assertTrue(strlen($cipher) > 0, 'verifier persisted');
    $verifier = PIIEncryption::decrypt($cipher);
    assertTrue(is_string($verifier) && $cipher !== $verifier, 'verifier stored encrypted, decryptable');
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    assertTrue(strpos($url, 'code_challenge=' . rawurlencode($expectedChallenge)) !== false,
        'code_challenge = base64url(SHA256(verifier)) - S256');

    // Fresh verifier per attempt
    $a2 = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $a2['attempt_id'];
    $row->execute([':id' => $a2['attempt_id']]);
    $attempt2 = $row->fetch(PDO::FETCH_ASSOC);
    $verifier2 = PIIEncryption::decrypt($attempt2['code_verifier_encrypted']);
    assertTrue($verifier !== $verifier2, 'fresh verifier per attempt');
    assertTrue($a1['attempt_id'] !== $a2['attempt_id'], 'fresh attempt id (state) per attempt');

    // ------------------------------------------------------------------
    // 3. Callback: success path
    // ------------------------------------------------------------------
    pushTokenResponse($queue, 'one');
    $res = $service->handleCallback($pdo, [
        'code' => 'synthetic-code-1', 'iss' => 'https://openam-densirona-euw3.id.forgerock.io',
        'state' => $a1['attempt_id'], 'client_id' => 'test-client-id-0000',
    ], $userId, $practiceId);
    assertEquals('connected', $res['result'], 'callback completes the connection');
    $connectionId = (int)$res['connection_id'];
    $connectionIds[] = $connectionId;

    // Documented token request format: JSON body with the exact documented
    // fields (code/client_id/redirect_uri/code_verifier/client_secret/
    // grant_type) to /v1beta/auth/token on the API base.
    assertEquals(1, count($calls), 'one token exchange call');
    $call = $calls[0];
    assertEquals('https://api.dscore.com/v1beta/auth/token', $call['url'], 'token exchange hits GLOBAL api.dscore.com');
    assertTrue(in_array('Content-Type: application/json', $call['headers'], true), 'JSON content type');
    $payload = json_decode($call['body'], true);
    assertEquals('authorization_code', $payload['grant_type'], 'documented grant_type');
    assertEquals('synthetic-code-1', $payload['code'], 'code forwarded');
    assertEquals('test-client-id-0000', $payload['client_id'], 'client_id forwarded');
    assertEquals('test-client-secret-0000', $payload['client_secret'], 'client_secret in body (not URL/header)');
    assertEquals($verifier, $payload['code_verifier'], 'verifier forwarded verbatim');
    assertEquals(DsCoreAuthService::redirectUri(), $payload['redirect_uri'], 'redirect_uri echoed verbatim');
    $calls = [];

    $conn = IntegrationManager::findConnection($pdo, $connectionId);
    assertEquals('active', $conn['status'], 'connection becomes active');
    assertEquals('ds_core', $conn['provider'], 'provider is ds_core');
    assertEquals('connected', DsCoreAuthService::connectionState($conn), 'state projection: connected');

    // Tokens persisted encrypted; decryptable through the credential store.
    assertEquals('synthetic-access-one', IntegrationCredentials::get($pdo, $connectionId, 'access_token'), 'access token stored');
    assertEquals('synthetic-refresh-one', IntegrationCredentials::get($pdo, $connectionId, 'refresh_token'), 'refresh token stored');
    $raw = $pdo->query("SELECT credential_value_encrypted FROM integration_credentials WHERE connection_id = {$connectionId} AND credential_key = 'access_token'")->fetchColumn();
    assertTrue(is_string($raw) && strpos($raw, 'synthetic-access') === false, 'access token ciphertext in DB (no plaintext)');

    // config_json carries only non-secret metadata; expiry ~ now+3599.
    $cfg = json_decode((string)$conn['config_json'], true);
    assertTrue(!empty($cfg['token_expires_at']), 'token_expires_at recorded');
    $expTs = strtotime($cfg['token_expires_at']);
    assertTrue(abs($expTs - (time() + 3599)) < 60, 'expiry derived from expiresIn');
    assertTrue(strpos((string)$conn['config_json'], 'synthetic-refresh') === false, 'no token material in config_json');
    assertEquals('sandbox', $cfg['environment'], 'environment recorded');

    // Public projection never exposes tokens.
    $pub = IntegrationManager::toPublicConnection($pdo, $conn);
    assertTrue(strpos(json_encode($pub), 'synthetic-access') === false, 'public projection excludes tokens');

    // ------------------------------------------------------------------
    // 4. Replay: the same callback cannot run twice. No token response is
    // queued - a replay must be rejected BEFORE the exchange call.
    // ------------------------------------------------------------------
    $res = $service->handleCallback($pdo, [
        'code' => 'synthetic-code-1', 'state' => $a1['attempt_id'], 'client_id' => 'test-client-id-0000',
    ], $userId, $practiceId);
    assertEquals('replay', $res['result'], 'repeated callback rejected as replay');
    assertEquals(0, count($calls), 'no second token exchange on replay');

    // ------------------------------------------------------------------
    // 5. Cancelled / invalid / expired / foreign-session callbacks
    // ------------------------------------------------------------------
    $res = $service->handleCallback($pdo, ['error' => 'access_denied', 'state' => 'whatever'], $userId, $practiceId);
    assertEquals('cancelled', $res['result'], 'consent decline -> cancelled');

    $res = $service->handleCallback($pdo, ['code' => 'x', 'state' => ''], $userId, $practiceId);
    assertEquals('invalid', $res['result'], 'empty state -> invalid');
    $res = $service->handleCallback($pdo, ['code' => '', 'state' => $a2['attempt_id']], $userId, $practiceId);
    assertEquals('invalid', $res['result'], 'missing code -> invalid');
    $res = $service->handleCallback($pdo, ['code' => 'x', 'state' => 'not-a-real-attempt'], $userId, $practiceId);
    assertEquals('invalid', $res['result'], 'unknown attempt -> invalid');

    // Cross-practice session rejection: valid attempt id but the session
    // practice/user do not match the attempt.
    if ($otherPracticeId > 0) {
        $res = $service->handleCallback($pdo, [
            'code' => 'synthetic-code-2', 'state' => $a2['attempt_id'], 'client_id' => 'test-client-id-0000',
        ], $userId, $otherPracticeId);
        assertEquals('forbidden', $res['result'], 'wrong-practice session rejected');
    }
    $res = $service->handleCallback($pdo, [
        'code' => 'synthetic-code-2', 'state' => $a2['attempt_id'], 'client_id' => 'test-client-id-0000',
    ], $otherUserId, $practiceId);
    assertEquals('forbidden', $res['result'], 'wrong-user session rejected');

    // Attempt survived (unconsumed) after forbidden callbacks - the real
    // session could still legitimately complete it.
    $row->execute([':id' => $a2['attempt_id']]);
    assertTrue($row->fetch(PDO::FETCH_ASSOC)['consumed_at'] === null, 'foreign callback does not consume the attempt');

    // Expired attempt
    $expiredId = 'expired-attempt-' . bin2hex(random_bytes(4));
    $pdo->prepare("
        INSERT INTO dscore_oauth_attempts (id, practice_id, user_id, code_verifier_encrypted, expires_at)
        VALUES (:id, :pid, :uid, :v, :exp)
    ")->execute([
        ':id' => $expiredId, ':pid' => $practiceId, ':uid' => $userId,
        ':v' => PIIEncryption::encrypt('dead-verifier'), ':exp' => gmdate('Y-m-d H:i:s', time() - 60),
    ]);
    $res = $service->handleCallback($pdo, ['code' => 'c', 'state' => $expiredId], $userId, $practiceId);
    assertEquals('expired', $res['result'], 'expired attempt rejected');

    // Exchange failure: code rejected upstream -> attempt consumed, no
    // connection created, existing connection (if any) marked error.
    $a3 = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $a3['attempt_id'];
    $queue[] = ['status' => 400, 'headers' => [], 'body' => '{"error":"invalid_grant"}'];
    $res = $service->handleCallback($pdo, ['code' => 'bad-code', 'state' => $a3['attempt_id'], 'client_id' => 'test-client-id-0000'], $userId, $practiceId);
    assertEquals('exchange_failed', $res['result'], 'failed exchange reported');
    assertEquals(1, count($calls), 'exchange attempted once');
    $calls = [];

    // ------------------------------------------------------------------
    // 6. Refresh: documented request format + token rotation
    // ------------------------------------------------------------------
    // Force the stored expiry into the refresh margin.
    $cfg['token_expires_at'] = gmdate('Y-m-d H:i:s', time() + 60);
    $pdo->prepare("UPDATE integration_connections SET config_json = :c WHERE id = :id")
        ->execute([':c' => json_encode($cfg), ':id' => $connectionId]);

    pushTokenResponse($queue, 'two');
    $service->refreshConnectionToken($pdo, $connectionId);

    assertEquals(1, count($calls), 'one refresh call');
    $rc = $calls[0];
    assertEquals('https://api.dscore.com/v1beta/auth/token:refresh', $rc['url'], 'token:refresh hits GLOBAL api.dscore.com');
    assertTrue(in_array('Content-Type: application/json', $rc['headers'], true), 'refresh is JSON');
    $rbody = json_decode($rc['body'], true);
    assertEquals('synthetic-refresh-one', $rbody['refresh_token'], 'refresh body uses snake_case refresh_token (documented)');
    assertEquals('test-client-id-0000', $rbody['client_id'], 'refresh body uses snake_case client_id (documented)');
    assertEquals('test-client-secret-0000', $rbody['client_secret'], 'refresh body carries client_secret');
    assertTrue(!isset($rbody['clientId']) && !isset($rbody['refreshToken']), 'no camelCase keys in refresh body');
    assertTrue(!isset($rbody['grant_type']), 'refresh sends no grant_type (documented)');
    $calls = [];

    assertEquals('synthetic-access-two', IntegrationCredentials::get($pdo, $connectionId, 'access_token'), 'rotated access token persisted');
    assertEquals('synthetic-refresh-two', IntegrationCredentials::get($pdo, $connectionId, 'refresh_token'), 'replacement refresh token persisted');
    $conn = IntegrationManager::findConnection($pdo, $connectionId);
    $cfg2 = json_decode((string)$conn['config_json'], true);
    assertTrue(strtotime($cfg2['token_expires_at']) > time() + 3000, 'expiry pushed out by ~1h');

    // Second refresh while still fresh -> no network call (re-read under
    // the lock sees the fresh expiry - this is the racing-instance check).
    $service->refreshConnectionToken($pdo, $connectionId);
    assertEquals(0, count($calls), 'fresh token under lock -> no redundant refresh call');

    // refreshDueConnections skips fresh connections.
    $res = $service->refreshDueConnections($pdo);
    assertEquals(0, $res['refreshed'], 'due scan skips fresh connection');
    assertTrue($res['skipped'] >= 1, 'due scan reports the fresh connection as skipped');

    // ------------------------------------------------------------------
    // 7. Refresh failure handling
    // ------------------------------------------------------------------
    // Transient -> bounded retry then success.
    $cfg2['token_expires_at'] = gmdate('Y-m-d H:i:s', time() - 10);
    $pdo->prepare("UPDATE integration_connections SET config_json = :c WHERE id = :id")
        ->execute([':c' => json_encode($cfg2), ':id' => $connectionId]);
    $queue[] = ['status' => 500, 'headers' => [], 'body' => '{"error":"temporarily_unavailable"}'];
    pushTokenResponse($queue, 'three');
    $service->refreshConnectionToken($pdo, $connectionId);
    assertEquals(2, count($calls), 'transient refresh failure retried once');
    assertEquals('synthetic-access-three', IntegrationCredentials::get($pdo, $connectionId, 'access_token'), 'recovered access token stored');
    $calls = [];

    // Invalid refresh credentials -> reconnect_required, no token changes.
    $cfg3 = json_decode((string)IntegrationManager::findConnection($pdo, $connectionId)['config_json'], true);
    $cfg3['token_expires_at'] = gmdate('Y-m-d H:i:s', time() - 10);
    $pdo->prepare("UPDATE integration_connections SET config_json = :c WHERE id = :id")
        ->execute([':c' => json_encode($cfg3), ':id' => $connectionId]);
    $queue[] = ['status' => 400, 'headers' => [], 'body' => '{"error":"invalid_grant"}'];
    try {
        $service->refreshConnectionToken($pdo, $connectionId);
        assertTrue(false, 'invalid refresh throws');
    } catch (DsCoreAuthException $e) {
        assertEquals('invalid_credentials', $e->category, 'invalid refresh -> invalid_credentials');
    }
    $conn = IntegrationManager::findConnection($pdo, $connectionId);
    assertEquals('reconnect_required', $conn['status'], 'connection marked reconnect_required');
    assertEquals('reconnect_required', DsCoreAuthService::connectionState($conn), 'state projection: reconnect_required');
    assertEquals('synthetic-refresh-three', IntegrationCredentials::get($pdo, $connectionId, 'refresh_token'), 'rejected refresh token retained (nothing overwritten)');
    $calls = [];

    // reconnect_required is not picked up by the due-refresh sweep.
    $res = $service->refreshDueConnections($pdo);
    assertEquals(0, $res['refreshed'], 'reconnect_required connection skipped by sweep');

    // Cross-instance serialization: hold GET_LOCK on a SECOND connection
    // and confirm this process cannot rotate behind it.
    $pdo->prepare("UPDATE integration_connections SET status = 'active' WHERE id = :id")->execute([':id' => $connectionId]);
    $cfg4 = json_decode((string)IntegrationManager::findConnection($pdo, $connectionId)['config_json'], true);
    $cfg4['token_expires_at'] = gmdate('Y-m-d H:i:s', time() - 10);
    $pdo->prepare("UPDATE integration_connections SET config_json = :c WHERE id = :id")
        ->execute([':c' => json_encode($cfg4), ':id' => $connectionId]);

    $dbUser = getEnvVar('DB_USER_LOCAL', 'root');
    $dbPass = getEnvVar('DB_PASSWORD_LOCAL', '');
    $dbName = getEnvVar('DB_NAME', 'dental_case_tracker');
    $pdo2 = new PDO(
        'mysql:host=127.0.0.1;port=3308;dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $lockRes = $pdo2->query("SELECT GET_LOCK('dscore_refresh_{$connectionId}', 5)")->fetchColumn();
    assertEquals(1, (int)$lockRes, 'second connection holds the refresh lock');
    $t0 = microtime(true);
    try {
        $service->refreshConnectionToken($pdo, $connectionId);
        assertTrue(false, 'locked refresh throws');
    } catch (DsCoreAuthException $e) {
        assertEquals('lock_timeout', $e->category, 'concurrent refresh blocked by shared GET_LOCK');
    }
    assertTrue(microtime(true) - $t0 >= 9, 'lock wait honored the timeout (no silent in-process bypass)');
    $pdo2->query("SELECT RELEASE_LOCK('dscore_refresh_{$connectionId}')");

    // ------------------------------------------------------------------
    // 8. withAutoRefresh: single refresh + single retry on a 401
    // ------------------------------------------------------------------
    // Fresh token state after the successful refresh above ('three').
    $cfg5 = json_decode((string)IntegrationManager::findConnection($pdo, $connectionId)['config_json'], true);
    $cfg5['token_expires_at'] = gmdate('Y-m-d H:i:s', time() + 3600);
    $pdo->prepare("UPDATE integration_connections SET config_json = :c, status = 'active' WHERE id = :id")
        ->execute([':c' => json_encode($cfg5), ':id' => $connectionId]);

    $seen = [];
    $out = $service->withAutoRefresh($pdo, $connectionId, function ($token) use (&$seen) {
        $seen[] = $token;
        return 'ok';
    });
    assertEquals('ok', $out, 'business call runs with stored token');
    assertEquals(['synthetic-access-three'], $seen, 'no refresh when token is fresh');
    assertEquals(0, count($calls), 'no refresh HTTP call');

    // 401 -> exactly one refresh, exactly one retry.
    pushTokenResponse($queue, 'four');
    $attempts = 0;
    $seen = [];
    $out = $service->withAutoRefresh($pdo, $connectionId, function ($token) use (&$seen, &$attempts) {
        $seen[] = $token;
        $attempts++;
        if ($attempts === 1) {
            throw new DsCoreAuthException('unauthorized', '401 from business API');
        }
        return 'retried-ok';
    });
    assertEquals('retried-ok', $out, '401 -> refresh -> retry succeeds');
    assertEquals(1, count($calls), 'exactly one refresh call after 401');
    assertEquals(['synthetic-access-three', 'synthetic-access-four'], $seen, 'retry used the refreshed token');
    $calls = [];

    // A second 401 propagates - no loop.
    pushTokenResponse($queue, 'five');
    $attempts = 0;
    try {
        $service->withAutoRefresh($pdo, $connectionId, function () use (&$attempts) {
            $attempts++;
            throw new DsCoreAuthException('unauthorized', '401');
        });
        assertTrue(false, 'repeated 401 propagates');
    } catch (DsCoreAuthException $e) {
        assertEquals('unauthorized', $e->category, 'second 401 surfaces to caller');
    }
    assertEquals(2, $attempts, 'bounded: original + exactly one retry');
    assertEquals(1, count($calls), 'exactly one refresh for the retry');
    $calls = [];

    // ------------------------------------------------------------------
    // 9. Disconnect (§4.3): remote revocation attempted + honestly
    //    reported; local wipe is authoritative either way.
    // ------------------------------------------------------------------
    // (a) Revocation failure: local disconnect still succeeds and reports
    //     remote_revoked=false - never a false success.
    $queue[] = ['status' => 500, 'headers' => [], 'body' => '{"error":"upstream"}'];
    $res = $service->disconnect($pdo, $connectionId);
    assertEquals(false, $res['remote_revoked'], 'failed remote revoke reported honestly');
    assertEquals(1, count($calls), 'one revoke attempt made');
    $rc = $calls[0];
    assertEquals('https://api.dscore.com/v1beta/auth/token:revoke', $rc['url'], 'token:revoke hits GLOBAL api.dscore.com');
    assertTrue(in_array('Content-Type: application/x-www-form-urlencoded', $rc['headers'], true), 'revoke is form-urlencoded, NOT JSON');
    parse_str($rc['body'], $vbody);
    assertEquals('test-client-id-0000', $vbody['client_id'] ?? null, 'revoke body carries client_id');
    assertEquals('test-client-secret-0000', $vbody['client_secret'] ?? null, 'revoke body carries client_secret');
    assertEquals('synthetic-refresh-five', $vbody['token'] ?? null, 'revoke sends the stored refresh token');
    assertEquals('refresh_token', $vbody['token_type_hint'] ?? null, 'revoke token_type_hint=refresh_token');
    assertTrue(strpos($rc['body'], '{') !== 0, 'revoke body is not JSON');
    $calls = [];

    assertTrue(IntegrationCredentials::get($pdo, $connectionId, 'access_token') === null, 'access token deleted on disconnect');
    assertTrue(IntegrationCredentials::get($pdo, $connectionId, 'refresh_token') === null, 'refresh token deleted on disconnect');
    $conn = IntegrationManager::findConnection($pdo, $connectionId);
    assertEquals('disabled', $conn['status'], 'connection disabled');
    assertEquals(0, (int)$conn['sync_enabled'], 'sync disabled');
    assertEquals('disconnected', DsCoreAuthService::connectionState($conn), 'state projection: disconnected');
    assertEquals('unconfigured', DsCoreAuthService::connectionState(null), 'state projection: unconfigured');

    // (b) Reconnect, then disconnect with a successful remote revocation.
    $a4 = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $a4['attempt_id'];
    pushTokenResponse($queue, 'six');
    $res = $service->handleCallback($pdo, [
        'code' => 'synthetic-code-6', 'state' => $a4['attempt_id'], 'client_id' => 'test-client-id-0000',
    ], $userId, $practiceId);
    assertEquals('connected', $res['result'], 'reconnect after disconnect succeeds');
    $calls = [];

    $queue[] = ['status' => 200, 'headers' => [], 'body' => ''];
    $res = $service->disconnect($pdo, $connectionId);
    assertEquals(true, $res['remote_revoked'], '2xx revoke reported as remotely revoked');
    parse_str($calls[0]['body'], $vb2);
    assertEquals('synthetic-refresh-six', $vb2['token'] ?? null, 'successful revoke sent the new refresh token');
    $calls = [];

    // (c) Idempotent: already-disabled -> no revoke call, remote_revoked=false.
    $res = $service->disconnect($pdo, $connectionId);
    assertEquals(false, $res['remote_revoked'], 'repeat disconnect does not fabricate revocation');
    assertEquals(0, count($calls), 'repeat disconnect makes no network calls');
    $conn = IntegrationManager::findConnection($pdo, $connectionId);
    assertEquals('disabled', $conn['status'], 'still disabled after repeat disconnect');

    // (d) Disconnect contends on the same refresh lock: while a second
    //     connection holds it, disconnect fails with lock_timeout rather
    //     than racing an in-flight rotation.
    $pdo->prepare("UPDATE integration_connections SET status='active', sync_enabled=1 WHERE id=:id")->execute([':id' => $connectionId]);
    IntegrationCredentials::store($pdo, $connectionId, 'refresh_token', 'synthetic-refresh-seven');
    $lockRes = $pdo2->query("SELECT GET_LOCK('dscore_refresh_{$connectionId}', 5)")->fetchColumn();
    assertEquals(1, (int)$lockRes, 'second connection holds the lock for disconnect test');
    try {
        $service->disconnect($pdo, $connectionId);
        assertTrue(false, 'locked disconnect throws');
    } catch (DsCoreAuthException $e) {
        assertEquals('lock_timeout', $e->category, 'disconnect contends on the shared refresh lock');
    }
    $pdo2->query("SELECT RELEASE_LOCK('dscore_refresh_{$connectionId}')");
    // Credentials survived the blocked disconnect - nothing was half-wiped.
    assertEquals('synthetic-refresh-seven', IntegrationCredentials::get($pdo, $connectionId, 'refresh_token'), 'blocked disconnect wiped nothing');
    $pdo->prepare("UPDATE integration_connections SET status='disabled', sync_enabled=0 WHERE id=:id")->execute([':id' => $connectionId]);
    IntegrationCredentials::delete($pdo, $connectionId, 'refresh_token');

    // Defensive expiresIn tolerance (numeric string is NOT the documented
    // contract - verify the parser tolerates it without depending on it).
    $a5 = $service->beginAttempt($pdo, $practiceId, $userId);
    $attemptIds[] = $a5['attempt_id'];
    pushTokenResponse($queue, 'str', true);
    $res = $service->handleCallback($pdo, [
        'code' => 'synthetic-code-str', 'state' => $a5['attempt_id'], 'client_id' => 'test-client-id-0000',
    ], $userId, $practiceId);
    assertEquals('connected', $res['result'], 'numeric-string expiresIn tolerated (defensive, not contract)');
    $cfg6 = json_decode((string)IntegrationManager::findConnection($pdo, $connectionId)['config_json'], true);
    assertTrue(strtotime($cfg6['token_expires_at']) > time() + 3000, 'string expiresIn parsed to the same expiry');
    $calls = [];

    // ------------------------------------------------------------------
    // 10. Cross-practice isolation of the connection row
    // ------------------------------------------------------------------
    if ($otherPracticeId > 0) {
        $foreign = IntegrationManager::getConnectionForPractice($pdo, $otherPracticeId, $connectionId);
        assertTrue($foreign === null, 'connection invisible to other practices');
    }

} finally {
    // Fixture cleanup
    foreach ($attemptIds as $id) {
        $pdo->prepare("DELETE FROM dscore_oauth_attempts WHERE id = :id")->execute([':id' => $id]);
    }
    $pdo->exec("DELETE FROM dscore_oauth_attempts WHERE id LIKE 'expired-attempt-%'");
    foreach ($connectionIds as $cid) {
        $pdo->exec("DELETE FROM integration_credentials WHERE connection_id = {$cid}");
        $pdo->exec("DELETE FROM integration_connections WHERE id = {$cid}");
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
