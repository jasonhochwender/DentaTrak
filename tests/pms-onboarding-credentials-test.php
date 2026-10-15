<?php
/**
 * Phase F: central developer credentials + Developer Portal key generation.
 *
 * Covers:
 *   UNIT
 *   - OpenDentalConfig reads env secrets, fails closed on absence
 *   - Portal client request shapes (POST/PUT/GET /apikeys, ODFHIR dev/portal auth)
 *   - Portal client missing secrets -> server_config_missing
 *   - Adapter auth combines env dev key + stored customer key
 *
 *   E2E (php -S DentaTrak server + php -S Open Dental stub)
 *   - practice admin generates a customer key (POST /apikeys via portal)
 *   - key returned once to the admin, never in list/status projections
 *   - encrypted at rest; no plaintext outside the generate response
 *   - second generate without regenerate -> 409 (no key accumulation)
 *   - regenerate -> new key + old key DisabledByDeveloper remotely
 *   - test_connection uses env dev key + stored customer key -> active
 *   - disconnect -> remote key disabled; mappings/history preserved
 *   - reenable -> remote key re-enabled
 *   - non-admin / lab collaborator / cross-practice -> rejected
 *   - missing portal env -> safe 503 (separate server without the vars)
 *   - neither central secret appears in any response
 *
 * Fixtures are cleaned up afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/OpenDentalConfig.php';
require_once __DIR__ . '/../api/integrations/OpenDentalPortalClient.php';
require_once __DIR__ . '/../api/integrations/IntegrationEvents.php';
require_once __DIR__ . '/../api/integrations/register-adapters.php';

global $pdo;

$pass = 0;
$fail = 0;
function ok($cond, $msg, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: {$msg}\n"; }
    else { $fail++; echo "FAIL: {$msg}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; }
}
function eq($expected, $actual, $msg, $detail = '') {
    ok($expected === $actual, $msg, ($detail !== '' ? $detail . ' - ' : '')
        . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// =====================================================================
// A. UNIT - OpenDentalConfig + portal client transport
// =====================================================================
echo "--- unit: OpenDentalConfig + portal client ---\n";

// Clear ALL env sources: putenv covers getenv(), but a populated .env also
// fills $_ENV/$_SERVER through Dotenv and envSecret() reads those too.
function clearOdEnv(): void {
    putenv('OPEN_DENTAL_DEVELOPER_KEY');
    putenv('OPEN_DENTAL_DEVELOPER_PORTAL_KEY');
    unset($_ENV['OPEN_DENTAL_DEVELOPER_KEY'], $_ENV['OPEN_DENTAL_DEVELOPER_PORTAL_KEY'],
          $_SERVER['OPEN_DENTAL_DEVELOPER_KEY'], $_SERVER['OPEN_DENTAL_DEVELOPER_PORTAL_KEY']);
}
function setOdEnv(string $dev, string $portal): void {
    putenv("OPEN_DENTAL_DEVELOPER_KEY={$dev}");
    $_ENV['OPEN_DENTAL_DEVELOPER_KEY'] = $dev; $_SERVER['OPEN_DENTAL_DEVELOPER_KEY'] = $dev;
    putenv("OPEN_DENTAL_DEVELOPER_PORTAL_KEY={$portal}");
    $_ENV['OPEN_DENTAL_DEVELOPER_PORTAL_KEY'] = $portal; $_SERVER['OPEN_DENTAL_DEVELOPER_PORTAL_KEY'] = $portal;
}

clearOdEnv();
ok(OpenDentalConfig::developerKey() === null, 'config: missing developer key returns null');
ok(!OpenDentalConfig::hasDeveloperKey(), 'config: hasDeveloperKey false when unset');
setOdEnv('CENTRAL-DEV-KEY', 'CENTRAL-PORTAL-KEY');
eq('CENTRAL-DEV-KEY', OpenDentalConfig::developerKey(), 'config: developer key read from env');
eq('CENTRAL-PORTAL-KEY', OpenDentalConfig::developerPortalKey(), 'config: portal key read from env');

// -- portal client request shape -----------------------------------------
$requests = [];
$portal = new OpenDentalPortalClient(function ($method, $url, $headers, $body = null) use (&$requests) {
    $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    return ['code' => 201, 'body' => json_encode([
        'CustomerKey' => 'NEWKEY-ABC123', 'KeyStatus' => 'Enabled',
        'DateCreated' => '2026-09-20', 'DateDisabled' => '0001-01-01',
        'DateTimeAssigned' => '0001-01-01 00:00:00', 'DevRefId' => '',
    ]), 'headers' => []];
});
$created = $portal->createCustomerKey();
eq('NEWKEY-ABC123', $created['CustomerKey'], 'portal: create returns CustomerKey');
eq('POST', $requests[0]['method'], 'portal: create uses POST');
ok(strpos($requests[0]['url'], '/apikeys') !== false, 'portal: create hits /apikeys');
// OD's server rejects body-less POSTs (411 Length Required) - must send '{}'.
eq('{}', $requests[0]['body'], 'portal: POST sends explicit empty JSON body (411 guard)');
$authHdr = '';
foreach ($requests[0]['headers'] as $h) {
    if (stripos($h, 'Authorization:') === 0) { $authHdr = $h; }
}
eq('Authorization: ODFHIR CENTRAL-DEV-KEY/CENTRAL-PORTAL-KEY', $authHdr,
    'portal: auth header is ODFHIR developer/portal pair');
ok(strpos($requests[0]['url'], 'CENTRAL-') === false, 'portal: no secrets in URL');

// -- PUT update/disable/enable -------------------------------------------
$portal2 = new OpenDentalPortalClient(function ($method, $url, $headers, $body = null) use (&$requests) {
    $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    return ['code' => 200, 'body' => json_encode(['CustomerKey' => 'KEY1', 'KeyStatus' => 'DisabledByDeveloper']), 'headers' => []];
});
$requests = [];
$portal2->disableCustomerKey('KEY1');
eq('PUT', $requests[0]['method'], 'portal: disable uses PUT');
$putBody = json_decode($requests[0]['body'], true);
eq('DisabledByDeveloper', $putBody['KeyStatus'] ?? null, 'portal: disable sets DisabledByDeveloper');
eq('KEY1', $putBody['CustomerKey'] ?? null, 'portal: disable targets the key');

$requests = [];
$portal2->updateCustomerKey('KEY1', null, 'Some Practice');
$putBody = json_decode($requests[0]['body'], true);
eq('Some Practice', $putBody['DevRefId'] ?? null, 'portal: DevRefId sent');
ok(!isset($putBody['KeyStatus']), 'portal: DevRefId update omits KeyStatus');

$requests = [];
$portal2->enableCustomerKey('KEY1');
$putBody = json_decode($requests[0]['body'], true);
eq('Enabled', $putBody['KeyStatus'] ?? null, 'portal: enable sets Enabled');

// -- GET list -------------------------------------------------------------
$requests = [];
$portal3 = new OpenDentalPortalClient(function ($method, $url, $headers, $body = null) use (&$requests) {
    $requests[] = ['method' => $method];
    return ['code' => 200, 'body' => json_encode([['CustomerKey' => 'K1'], ['CustomerKey' => 'K2']]), 'headers' => []];
});
$list = $portal3->listCustomerKeys();
eq(2, count($list), 'portal: list returns key records');
eq('GET', $requests[0]['method'], 'portal: list uses GET');

// -- missing secrets fail closed ------------------------------------------
clearOdEnv();
try {
    (new OpenDentalPortalClient())->createCustomerKey();
    ok(false, 'portal: missing portal key throws');
} catch (OpenDentalApiException $e) {
    eq('server_config_missing', $e->category, 'portal: missing portal key category');
    ok(strpos($e->getMessage(), 'CENTRAL-DEV-KEY') === false, 'portal: exception has no secret');
}
setOdEnv('CENTRAL-DEV-KEY', 'CENTRAL-PORTAL-KEY');

// Unit env must NOT leak into spawned servers - each gets explicit env.
clearOdEnv();

// =====================================================================
// B. E2E - generate_key lifecycle against stub servers
// =====================================================================
echo "--- e2e: generate_key lifecycle ---\n";

function startPhpServer(int &$port, string $docroot, array $extraEnv = [], ?string $router = null) {
    for ($p = 18500; $p < 18580; $p++) {
        $s = @fsockopen('127.0.0.1', $p, $e, $er, 0.2);
        if ($s === false) { $port = $p; break; }
        fclose($s);
    }
    $log = sys_get_temp_dir() . '/obcred-' . $port . '.log';
    @file_put_contents(sys_get_temp_dir() . '/dt-null-in.txt', '');
    $desc = [
        0 => ['file', sys_get_temp_dir() . '/dt-null-in.txt', 'r'],
        1 => ['file', sys_get_temp_dir() . '/dt-null-out.txt', 'w'],
        2 => ['file', $log, 'w'],
    ];
    $cmd = "php -S 127.0.0.1:{$port} -t " . escapeshellarg($docroot);
    if ($router !== null) {
        $cmd .= ' ' . escapeshellarg($router);
    }
    $proc = proc_open($cmd, $desc, $pipes, null, array_merge(getenv() ?: [], $extraEnv));
    if (!is_resource($proc)) {
        return null;
    }
    $start = microtime(true);
    while (microtime(true) - $start < 10) {
        $s = @fsockopen('127.0.0.1', $port, $e, $er, 0.2);
        if ($s !== false) { fclose($s); return $proc; }
        usleep(100000);
    }
    proc_terminate($proc);
    return null;
}
function stopPhpServer($proc): void {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
}
function httpJson(string $method, string $url, ?string $jsonBody, array $headers = [], string $jar = ''): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$r];
}

$stubStatePath = tempnam(sys_get_temp_dir(), 'odstub-onboard-');
$stubPort = 0;
$stubProc = startPhpServer($stubPort, __DIR__, ['OD_STUB_STATE' => $stubStatePath], __DIR__ . '/mock-open-dental.php');
if (!$stubProc) { echo "FATAL: stub server\n"; exit(1); }
$stubUrl = "http://127.0.0.1:{$stubPort}/api/v1";

$dtPort = 0;
$dtProc = startPhpServer($dtPort, __DIR__ . '/..', [
    'SHOW_PMS_INTEGRATIONS'          => 'true',
    'OPEN_DENTAL_BASE_URL'           => $stubUrl,
    'OPEN_DENTAL_PORTAL_BASE_URL'    => $stubUrl,
    'OPEN_DENTAL_DEVELOPER_KEY'      => 'E2E-DEV-SECRET',
    'OPEN_DENTAL_DEVELOPER_PORTAL_KEY' => 'E2E-PORTAL-SECRET',
]);
if (!$dtProc) { echo "FATAL: DentaTrak server\n"; stopPhpServer($stubProc); exit(1); }
$dtUrl = "http://127.0.0.1:{$dtPort}";

// Second server WITHOUT the central secrets -> safe-failure path.
// Windows deletes empty-string env vars outright, so use a single space:
// it survives process-env inheritance AND envSecret() trims it to missing.
// Being defined also keeps immutable Dotenv from repopulating via .env.
$dtPort2 = 0;
$dtProc2 = startPhpServer($dtPort2, __DIR__ . '/..', [
    'SHOW_PMS_INTEGRATIONS'            => 'true',
    'OPEN_DENTAL_BASE_URL'             => $stubUrl,
    'OPEN_DENTAL_DEVELOPER_KEY'        => ' ',
    'OPEN_DENTAL_DEVELOPER_PORTAL_KEY' => ' ',
]);
if (!$dtProc2) { echo "FATAL: DentaTrak server 2\n"; stopPhpServer($stubProc); stopPhpServer($dtProc); exit(1); }
$dtUrl2 = "http://127.0.0.1:{$dtPort2}";

// -- fixtures: admin user A (practice A), member M (same practice), admin B --
function mkSession(string $dtUrl, string $tag, string $role, int $practiceId = 0): array {
    global $pdo;
    $email = 'obcred-' . $tag . '-' . uniqid() . '@example.com';
    $jar = tempnam(sys_get_temp_dir(), 'obcookie');
    $r = httpJson('POST', "{$dtUrl}/api/test-helpers.php", json_encode([
        'action' => 'setup_test_user', 'email' => $email, 'password' => 'TestPass123!',
        'firstName' => 'OB', 'lastName' => $tag, 'practiceName' => 'OB Practice ' . $tag,
    ]), ['Content-Type: application/json'], $jar);
    $d = json_decode($r['body'], true);
    if (($d['success'] ?? false) !== true) { echo "FATAL fixture {$tag}: {$r['body']}\n"; exit(1); }
    $uid = (int)$d['user_id'];
    $ownPid = (int)$d['practice_id'];
    $pid = $practiceId > 0 ? $practiceId : $ownPid;
    if ($practiceId > 0) {
        // Attach to the given practice as non-admin member.
        $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p,:u,'user',0)")
            ->execute([':p' => $practiceId, ':u' => $uid]);
    }
    $pdo->prepare("UPDATE users SET terms_accepted_version = :v, terms_accepted_at = NOW() WHERE id = :id")
        ->execute(['v' => currentTermsVersion(), 'id' => $uid]);
    $r = httpJson('POST', "{$dtUrl}/api/auth-email.php", json_encode([
        'action' => 'login', 'email' => $email, 'password' => 'TestPass123!']), ['Content-Type: application/json'], $jar);
    $d = json_decode($r['body'], true);
    if (($d['success'] ?? false) !== true) { echo "FATAL login {$tag}\n"; exit(1); }
    if ($practiceId > 0) {
        httpJson('GET', "{$dtUrl}/api/select-practice.php?practice_id={$practiceId}", null, [], $jar);
    }
    $r = httpJson('GET', "{$dtUrl}/main.php", null, [], $jar);
    $csrf = preg_match('/<meta name="csrf-token" content="([^"]+)">/', $r['body'], $m) ? $m[1] : '';
    return ['email' => $email, 'jar' => $jar, 'csrf' => $csrf, 'practice_id' => $pid,
            'own_practice_id' => $ownPid, 'user_id' => $uid];
}

$adminA = mkSession($dtUrl, 'adminA', 'admin');
$practiceA = $adminA['practice_id'];
$memberA = mkSession($dtUrl, 'memberA', 'user', $practiceA);
$adminB = mkSession($dtUrl, 'adminB', 'admin');
$practiceB = $adminB['practice_id'];
$fixtureUsers = [$adminA['user_id'], $memberA['user_id'], $adminB['user_id']];
$fixturePractices = [$practiceA, $practiceB, $memberA['own_practice_id']];

function postInt(array $sess, string $dtUrl, array $payload): array {
    return httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode($payload),
        ['Content-Type: application/json', 'X-CSRF-Token: ' . $sess['csrf']], $sess['jar']);
}

// -- non-admin member rejected ---------------------------------------------
$r = postInt($memberA, $dtUrl, ['action' => 'generate_key', 'provider' => 'open_dental']);
eq(403, $r['code'], 'generate_key: non-admin member rejected');

// -- admin A generates ------------------------------------------------------
$r = postInt($adminA, $dtUrl, ['action' => 'generate_key', 'provider' => 'open_dental']);
$d = json_decode($r['body'], true);
eq(200, $r['code'], 'generate_key: admin generates', $r['body']);
ok(($d['success'] ?? false) === true, 'generate_key: success');
$genKey = $d['customer_key'] ?? null;
ok(is_string($genKey) && strpos($genKey, 'STUBKEY') === 0, 'generate_key: customer key returned once');
$connIdA = (int)($d['connection']['id'] ?? 0);
ok($connIdA > 0, 'generate_key: connection created');
eq('pending', $d['connection']['status'] ?? null, 'generate_key: starts pending');
ok(strpos($r['body'], 'E2E-DEV-SECRET') === false && strpos($r['body'], 'E2E-PORTAL-SECRET') === false,
    'generate_key: central secrets not in response');

// -- stored encrypted, only customer_key ------------------------------------
$stmt = $pdo->prepare("SELECT credential_key, credential_value_encrypted FROM integration_credentials WHERE connection_id = :id");
$stmt->execute(['id' => $connIdA]);
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
eq(1, count($rows), 'generate_key: exactly one credential stored');
ok(isset($rows['customer_key']) && $rows['customer_key'] !== $genKey && strpos($rows['customer_key'], $genKey) === false,
    'generate_key: customer key encrypted at rest');
eq($genKey, IntegrationCredentials::get($pdo, $connIdA, 'customer_key'), 'generate_key: decrypts to generated key');
ok(!isset($rows['developer_key']), 'generate_key: no developer key stored');

// -- key not in list/status projections --------------------------------------
$r = httpJson('GET', "{$dtUrl}/api/integrations.php?action=list", null, [], $adminA['jar']);
ok(strpos($r['body'], $genKey) === false, 'list: customer key absent');
ok(strpos($r['body'], 'E2E-DEV-SECRET') === false && strpos($r['body'], 'E2E-PORTAL-SECRET') === false,
    'list: central secrets absent');
$d = json_decode($r['body'], true);
ok(in_array('customer_key', $d['connections'][0]['credential_keys'] ?? [], true),
    'list: credential key names show customer_key configured');

// -- repeat generate without regenerate -> 409 --------------------------------
$stubBefore = json_decode((string)file_get_contents($stubStatePath), true);
$r = postInt($adminA, $dtUrl, ['action' => 'generate_key', 'provider' => 'open_dental']);
eq(409, $r['code'], 'generate_key: repeat without regenerate rejected');
$stubAfter = json_decode((string)file_get_contents($stubStatePath), true);
eq(count($stubBefore['apikeys'] ?? []), count($stubAfter['apikeys'] ?? []),
    'generate_key: rejected repeat created no remote key');
eq($genKey, IntegrationCredentials::get($pdo, $connIdA, 'customer_key'),
    'generate_key: stored key unchanged after 409');

// -- stub saw DevRefId bookkeeping ---------------------------------------------
$firstKey = ($stubAfter['apikeys'] ?? [])[0] ?? [];
ok(($firstKey['DevRefId'] ?? '') !== '', 'generate_key: DevRefId recorded remotely (practice label)');

// -- test_connection uses env dev key + stored customer key --------------------
$stubState = json_decode((string)file_get_contents($stubStatePath), true);
$stubState['clinics'] = [['Description' => 'OB Test Office']];
file_put_contents($stubStatePath, json_encode($stubState));
$r = postInt($adminA, $dtUrl, ['action' => 'test_connection', 'connection_id' => $connIdA]);
$d = json_decode($r['body'], true);
ok(($d['success'] ?? false) === true, 'test_connection: central dev key + customer key verifies', $r['body']);
eq('active', $d['connection']['status'] ?? null, 'test_connection: pending -> active');
ok(strpos($r['body'], $genKey) === false && strpos($r['body'], 'E2E-DEV-SECRET') === false,
    'test_connection: no secrets in response');

// -- regenerate: new key, old disabled remotely --------------------------------
$r = postInt($adminA, $dtUrl, ['action' => 'generate_key', 'provider' => 'open_dental', 'regenerate' => true]);
$d = json_decode($r['body'], true);
eq(200, $r['code'], 'regenerate: succeeds', $r['body']);
$newKey = $d['customer_key'] ?? null;
ok(is_string($newKey) && $newKey !== $genKey, 'regenerate: returns a different key');
eq($newKey, IntegrationCredentials::get($pdo, $connIdA, 'customer_key'), 'regenerate: stored key replaced');
$stubState = json_decode((string)file_get_contents($stubStatePath), true);
$apikeys = array_column($stubState['apikeys'] ?? [], null, 'CustomerKey');
eq(2, count($apikeys), 'regenerate: two remote keys total');
eq('DisabledByDeveloper', $apikeys[$genKey]['KeyStatus'] ?? null, 'regenerate: old key disabled remotely');
eq('Enabled', $apikeys[$newKey]['KeyStatus'] ?? null, 'regenerate: new key enabled remotely');
eq('pending', $d['connection']['status'] ?? null, 'regenerate: back to pending until verified');
$genKey = $newKey;

// -- cross-practice isolation ----------------------------------------------------
$r = httpJson('GET', "{$dtUrl}/api/integrations.php?action=list", null, [], $adminB['jar']);
$d = json_decode($r['body'], true);
eq(0, count($d['connections'] ?? []), 'isolation: practice B sees no practice A connection');
ok(strpos($r['body'], $genKey) === false, 'isolation: practice A key never visible to B');
$r = postInt($adminB, $dtUrl, ['action' => 'test_connection', 'connection_id' => $connIdA]);
eq(404, $r['code'], 'isolation: cross-practice test_connection 404');
$r = postInt($adminB, $dtUrl, ['action' => 'disconnect', 'connection_id' => $connIdA]);
eq(404, $r['code'], 'isolation: cross-practice disconnect 404');

// -- disconnect: remote disable + local preservation -------------------------------
$pdo->prepare("INSERT INTO integration_entity_mappings (connection_id, entity_type, external_id, internal_type, internal_id, last_seen_at)
             VALUES (:id, 'case', 'ob-ext-1', 'case', 1, NOW())")->execute(['id' => $connIdA]);
$mapId = (int)$pdo->lastInsertId();
$r = postInt($adminA, $dtUrl, ['action' => 'disconnect', 'connection_id' => $connIdA]);
$d = json_decode($r['body'], true);
ok(($d['success'] ?? false) === true, 'disconnect: succeeds');
eq('disabled', $d['connection']['status'] ?? null, 'disconnect: status disabled');
$stubState = json_decode((string)file_get_contents($stubStatePath), true);
$apikeys = array_column($stubState['apikeys'] ?? [], null, 'CustomerKey');
eq('DisabledByDeveloper', $apikeys[$genKey]['KeyStatus'] ?? null, 'disconnect: key disabled remotely');
eq(1, (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE id = {$mapId}")->fetchColumn(),
    'disconnect: mappings preserved');
eq($genKey, IntegrationCredentials::get($pdo, $connIdA, 'customer_key'),
    'disconnect: encrypted key preserved locally');

// -- reenable: remote enable --------------------------------------------------------
$r = postInt($adminA, $dtUrl, ['action' => 'reenable', 'connection_id' => $connIdA]);
$d = json_decode($r['body'], true);
ok(($d['success'] ?? false) === true, 'reenable: succeeds');
eq('pending', $d['connection']['status'] ?? null, 'reenable: pending not active');
$stubState = json_decode((string)file_get_contents($stubStatePath), true);
$apikeys = array_column($stubState['apikeys'] ?? [], null, 'CustomerKey');
eq('Enabled', $apikeys[$genKey]['KeyStatus'] ?? null, 'reenable: key re-enabled remotely');

// =====================================================================
// C. Missing central secrets -> safe failure (server 2)
// =====================================================================
echo "--- e2e: missing server configuration ---\n";

$adminC = mkSession($dtUrl2, 'adminC', 'admin');
$fixtureUsers[] = $adminC['user_id'];
$fixturePractices[] = $adminC['practice_id'];

$r = postInt($adminC, $dtUrl2, ['action' => 'generate_key', 'provider' => 'open_dental']);
eq(503, $r['code'], 'generate_key: missing server secrets -> 503');
$d = json_decode($r['body'], true);
ok(($d['success'] ?? true) === false, 'generate_key: fails safely');
ok(strpos($r['body'], 'OPEN_DENTAL') === false, 'generate_key: no variable names exposed');

// test_connection without the env dev key -> server_config_missing error_code
$pdo->exec("INSERT IGNORE INTO integration_connections (practice_id, provider, status, sync_enabled, created_at)
            VALUES ({$adminC['practice_id']}, 'open_dental', 'pending', 1, NOW())");
$connIdC = (int)$pdo->query(
    "SELECT id FROM integration_connections WHERE practice_id = {$adminC['practice_id']} AND provider = 'open_dental'"
)->fetchColumn();
IntegrationCredentials::store($pdo, $connIdC, 'customer_key', 'C-KEY');
$r = postInt($adminC, $dtUrl2, ['action' => 'test_connection', 'connection_id' => $connIdC]);
$d = json_decode($r['body'], true);
ok(($d['success'] ?? true) === false, 'test_connection: missing dev key fails');
eq('server_config_missing', $d['error_code'] ?? null, 'test_connection: error_code server_config_missing');
ok(strpos($r['body'], 'OPEN_DENTAL_DEVELOPER_KEY') === false, 'test_connection: no variable names exposed');
$pdo->exec("DELETE FROM integration_connections WHERE id = {$connIdC}");

// =====================================================================
// Cleanup
// =====================================================================
echo "--- cleanup ---\n";
stopPhpServer($dtProc);
stopPhpServer($dtProc2);
stopPhpServer($stubProc);
@unlink($stubStatePath);

$connIds = [];
foreach ($fixturePractices as $pid) {
    foreach ($pdo->query("SELECT id FROM integration_connections WHERE practice_id = {$pid}")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $connIds[] = (int)$cid;
    }
}
foreach ($connIds as $cid) {
    $pdo->exec("DELETE FROM integration_connections WHERE id = {$cid}");
}
foreach ($fixturePractices as $pid) {
    $pdo->exec("DELETE FROM practice_users WHERE practice_id = {$pid}");
    $pdo->exec("DELETE FROM practices WHERE id = {$pid}");
}
foreach ($fixtureUsers as $uid) {
    $pdo->exec("DELETE FROM users WHERE id = {$uid}");
}
clearOdEnv();

echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";
exit($fail === 0 ? 0 : 1);
