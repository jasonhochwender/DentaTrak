<?php
/**
 * GA two-way sync enablement tests - HTTP end-to-end through php -S
 * against the local OD stub (mock-open-dental.php).
 *
 *   - enable requires a valid office timezone (400 without one)
 *   - enable requires connection health (testConnection via stub)
 *   - enable requires THIS key's ApiAllOthers permission - verified per
 *     connection, never assumed (403 missing_write_permission + the
 *     capability probe is still recorded for the settings UI)
 *   - successful enable applies the approved default lifecycle map and
 *     flips enabled + create_enabled atomically
 *   - re-enable is idempotent; disable needs no probes and leaves the
 *     inbound subscription/sync_enabled untouched
 *   - retry_outbound_failed requeues only 'failed' rows (conflicts stay
 *     parked for human reconciliation)
 *   - list_od_lookups returns the stub provider/laboratory directories
 *   - a non-admin member is refused by requirePracticeAdmin
 *   - invalid timezone rejected; clearing lab default works
 *
 * Requires the local development database (port 3308) + ENCRYPTION_KEY.
 * Fixtures are removed afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/IntegrationOutbound.php';
require_once __DIR__ . '/../api/integrations/register-adapters.php';

global $pdo;

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, ?string $detail = null): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$name}\n"; }
    else { $failed++; echo "FAIL: {$name}" . ($detail ? " ({$detail})" : "") . "\n"; }
}
function assertEquals(string $name, $expected, $actual, ?string $detail = null): void {
    assertTrue($name, $expected === $actual,
        ($detail ? $detail . ' - ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function startPhpServer(int &$port, string $docroot, array $extraEnv = [], ?string $router = null) {
    for ($p = 18500; $p < 18580; $p++) {
        $socket = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if ($socket === false) { $port = $p; break; }
        fclose($socket);
    }
    $log = sys_get_temp_dir() . '/pms-enable-' . $port . '.log';
    @file_put_contents(sys_get_temp_dir() . '/dt-null-in.txt', '');
    $descriptors = [
        0 => ['file', sys_get_temp_dir() . '/dt-null-in.txt', 'r'],
        1 => ['file', sys_get_temp_dir() . '/dt-null-out.txt', 'w'],
        2 => ['file', $log, 'w'],
    ];
    $cmd = "php -S 127.0.0.1:{$port} -t " . escapeshellarg($docroot);
    if ($router !== null) { $cmd .= ' ' . escapeshellarg($router); }
    $proc = proc_open($cmd, $descriptors, $pipes, null, array_merge(getenv() ?: [], $extraEnv));
    if (!is_resource($proc)) { return null; }
    $start = microtime(true);
    while (microtime(true) - $start < 10) {
        $s = @fsockopen('127.0.0.1', $port, $e, $er, 0.2);
        if ($s !== false) { fclose($s); return $proc; }
        usleep(100000);
    }
    proc_terminate($proc);
    return null;
}
function stopPhpServer($proc): void { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } }

function httpJson(string $method, string $url, ?string $rawBody = null, array $headers = [], string $cookieJar = ''): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($rawBody !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody); }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    if ($cookieJar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar); curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar); }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr((string)$resp, $hs)];
}

function stubStateWrite(string $path, array $patch): void {
    $s = is_file($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
    file_put_contents($path, json_encode(array_merge($s, $patch)));
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "SKIP: no database connection available\n";
    exit(1);
}

// ---------------------------------------------------------------------
// OD stub seeded WITHOUT the write permission, then DentaTrak server.
// ---------------------------------------------------------------------
$stubStatePath = sys_get_temp_dir() . '/od-stub-enable-' . uniqid() . '.json';
file_put_contents($stubStatePath, json_encode([
    'subscriptions' => [], 'labcases' => [], 'clinics' => [['Description' => 'Stub Office']],
    'securityperms' => [
        ['Permission' => 'ApiReadAll',    'HasPermission' => true],
        ['Permission' => 'ApiAllOthers',  'HasPermission' => false],
    ],
    'providers_list' => [
        ['ProvNum' => 1, 'Abbr' => 'SL', 'FName' => 'Stub', 'LName' => 'Provider', 'IsHidden' => 'false'],
    ],
    'laboratories_list' => [
        ['LaboratoryNum' => 3, 'Description' => 'Stub Lab'],
    ],
    'patients_simple' => [],
]));
$stubPort = 0;
$stubProc = startPhpServer($stubPort, __DIR__, ['OD_STUB_STATE' => $stubStatePath], __DIR__ . '/mock-open-dental.php');
if (!$stubProc) { echo "FATAL: stub server\n"; exit(1); }
$stubUrl = "http://127.0.0.1:{$stubPort}/api/v1";

$dtPort = 0;
$dtProc = startPhpServer($dtPort, __DIR__ . '/..', [
    'SHOW_PMS_INTEGRATIONS'    => 'true',
    'OPEN_DENTAL_BASE_URL'     => $stubUrl,
    'OPEN_DENTAL_DEVELOPER_KEY' => 'E2E-DEV',
]);
if (!$dtProc) { echo "FATAL: DentaTrak server\n"; stopPhpServer($stubProc); exit(1); }
$baseUrl = "http://127.0.0.1:{$dtPort}";

$email = 'pms-test-enable-' . uniqid() . '@example.com';
$emailMember = 'pms-test-enable-m-' . uniqid() . '@example.com';
$jar = tempnam(sys_get_temp_dir(), 'encookie');
$jarM = tempnam(sys_get_temp_dir(), 'encookie');

$connId = 0;
$practiceId = 0;
$userId = 0;
$memberId = 0;

try {
    // ---- fixtures ----------------------------------------------------
    $r = httpJson('POST', "{$baseUrl}/api/test-helpers.php", json_encode([
        'action' => 'setup_test_user', 'email' => $email, 'password' => 'TestPass123!',
        'firstName' => 'GA', 'lastName' => 'Admin', 'practiceName' => 'GA Practice',
    ]), ['Content-Type: application/json'], $jar);
    $d = json_decode($r['body'], true);
    if (($d['success'] ?? false) !== true) { echo "FATAL fixture: {$r['body']}\n"; exit(1); }
    $practiceId = (int)$d['practice_id'];
    $userId = (int)$d['user_id'];
    $termsVersion = function_exists('currentTermsVersion') ? currentTermsVersion() : '1.0';
    $pdo->prepare("UPDATE users SET terms_accepted_version = :v, terms_accepted_at = NOW() WHERE email = :e")
        ->execute([':v' => $termsVersion, ':e' => $email]);
    $r = httpJson('POST', "{$baseUrl}/api/auth-email.php", json_encode([
        'action' => 'login', 'email' => $email, 'password' => 'TestPass123!']), ['Content-Type: application/json'], $jar);
    if ((json_decode($r['body'], true)['success'] ?? false) !== true) { echo "FATAL login\n"; exit(1); }
    $r = httpJson('GET', "{$baseUrl}/main.php", null, [], $jar);
    $csrf = preg_match('/<meta name="csrf-token" content="([^"]+)">/', $r['body'], $m) ? $m[1] : '';
    $H = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];

    // non-admin member for the authorization checks
    $r = httpJson('POST', "{$baseUrl}/api/test-helpers.php", json_encode([
        'action' => 'setup_practice_member', 'email' => $emailMember,
        'password' => 'TestPass123!', 'adminEmail' => $email, 'role' => 'user',
    ]), ['Content-Type: application/json'], $jarM);
    $d = json_decode($r['body'], true);
    if (($d['success'] ?? false) !== true) { echo "FATAL member fixture: {$r['body']}\n"; exit(1); }
    $memberId = (int)($d['user_id'] ?? 0);
    $pdo->prepare("UPDATE users SET terms_accepted_version = :v, terms_accepted_at = NOW() WHERE email = :e")
        ->execute([':v' => $termsVersion, ':e' => $emailMember]);
    httpJson('POST', "{$baseUrl}/api/auth-email.php", json_encode([
        'action' => 'login', 'email' => $emailMember, 'password' => 'TestPass123!']), ['Content-Type: application/json'], $jarM);
    // Member selects the shared practice so the ADMIN check (not the
    // practice-context check) is what rejects the call.
    httpJson('GET', "{$baseUrl}/api/select-practice.php?practice_id={$practiceId}", null, [], $jarM);
    $r = httpJson('GET', "{$baseUrl}/main.php", null, [], $jarM);
    $csrfM = preg_match('/<meta name="csrf-token" content="([^"]+)">/', $r['body'], $mm) ? $mm[1] : '';
    $HM = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfM];

    // ---- connect + verify (stub /clinics) ------------------------------
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'configure', 'provider' => 'open_dental',
        'credentials' => ['customer_key' => 'E2E-CUST'],
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    $connId = (int)($d['connection']['id'] ?? 0);
    assertTrue('enable: configure ok', ($d['success'] ?? false) === true && $connId > 0, $r['body']);

    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'test_connection', 'connection_id' => $connId,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertEquals('enable: test_connection -> active', 'active', $d['connection']['status'] ?? null, $r['body']);

    // ---- GA enable path -----------------------------------------------
    echo "--- enable: verify-on-enable gates ---\n";

    // non-admin member refused outright (admin gate precedes the probe)
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'enabled' => true, 'timezone' => 'America/New_York',
    ]), $HM, $jarM);
    assertTrue('enable: non-admin refused', $r['code'] === 403 || $r['code'] === 401, "HTTP {$r['code']} {$r['body']}");

    // enabled=true with no timezone -> 400 timezone_required
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'enabled' => true,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertEquals('enable: no tz -> 400', 400, $r['code']);
    assertEquals('enable: no tz -> timezone_required', 'timezone_required', $d['error_code'] ?? null);

    // invalid timezone string -> 400
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'enabled' => true, 'timezone' => 'Not/AZone',
    ]), $H, $jar);
    assertEquals('enable: bad tz -> 400', 400, $r['code']);

    // key lacks ApiAllOthers -> 403 missing_write_permission, NOT enabled,
    // capability probe still recorded for the settings UI
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'enabled' => true, 'timezone' => 'America/New_York',
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertEquals('enable: no write perm -> 403', 403, $r['code']);
    assertEquals('enable: missing_write_permission code', 'missing_write_permission', $d['error_code'] ?? null);
    $cfg = json_decode($pdo->query("SELECT config_json FROM integration_connections WHERE id={$connId}")->fetchColumn(), true);
    assertTrue('enable: refused enable stays off', empty($cfg['outbound']['enabled']));
    assertEquals('enable: failed probe recorded', false, $cfg['outbound']['capability']['has_permission'] ?? null);

    // grant the permission on the stub -> enable succeeds
    stubStateWrite($stubStatePath, ['securityperms' => [
        ['Permission' => 'ApiReadAll',   'HasPermission' => true],
        ['Permission' => 'ApiAllOthers', 'HasPermission' => true],
    ]]);
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'enabled' => true, 'create_enabled' => true,
        'timezone' => 'America/New_York', 'laboratory_num' => 3,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertTrue('enable: verified enable ok', ($d['success'] ?? false) === true, $r['body']);
    $cfg = json_decode($pdo->query("SELECT config_json FROM integration_connections WHERE id={$connId}")->fetchColumn(), true);
    $ob = $cfg['outbound'] ?? [];
    assertTrue('enable: enabled persisted', !empty($ob['enabled']));
    assertTrue('enable: create_enabled persisted', !empty($ob['create_enabled']));
    assertEquals('enable: timezone persisted', 'America/New_York', $ob['timezone'] ?? null);
    assertEquals('enable: lab default persisted', 3, $ob['laboratory_num'] ?? null);
    assertEquals('enable: approved default field map applied',
        IntegrationOutbound::DEFAULT_FIELD_MAP, $ob['field_map'] ?? null);
    assertEquals('enable: capability recorded true', true, $ob['capability']['has_permission'] ?? null);
    assertEquals('enable: projection surfaces config', true, $d['connection']['outbound']['enabled'] ?? null);
    assertEquals('enable: projection tz', 'America/New_York', $d['connection']['outbound']['timezone'] ?? null);

    // re-enable is idempotent (probe re-runs, state converges)
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'enabled' => true,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertTrue('enable: re-enable idempotent', ($d['success'] ?? false) === true, $r['body']);

    // ---- list_od_lookups ------------------------------------------------
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'list_od_lookups', 'connection_id' => $connId,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertTrue('lookups: ok', ($d['success'] ?? false) === true, $r['body']);
    assertEquals('lookups: one laboratory', 'Stub Lab', $d['laboratories'][0]['Description'] ?? null);
    assertEquals('lookups: one provider', 1, (int)($d['providers'][0]['ProvNum'] ?? 0));

    // ---- retry_outbound_failed ------------------------------------------
    $pdo->prepare("INSERT INTO integration_outbound_events
        (connection_id, practice_id, entity_type, internal_entity_id, operation, dedup_key, status, attempts, created_at)
        VALUES (:c, :p, 'case', 'GA-CASE-F', 'update_labcase', :dk, 'failed', 10, NOW())")
        ->execute([':c' => $connId, ':p' => $practiceId, ':dk' => 'ga-fail-' . uniqid()]);
    $pdo->prepare("INSERT INTO integration_outbound_events
        (connection_id, practice_id, entity_type, internal_entity_id, operation, dedup_key, status, attempts, created_at)
        VALUES (:c, :p, 'case', 'GA-CASE-C', 'update_labcase', :dk, 'conflict', 3, NOW())")
        ->execute([':c' => $connId, ':p' => $practiceId, ':dk' => 'ga-conf-' . uniqid()]);

    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'retry_outbound_failed', 'connection_id' => $connId,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertTrue('retry: ok', ($d['success'] ?? false) === true, $r['body']);
    assertEquals('retry: failed row requeued', 1, (int)($d['requeued'] ?? -1));
    $st = $pdo->query("SELECT internal_entity_id, status FROM integration_outbound_events WHERE connection_id={$connId} ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    assertEquals('retry: failed now queued', 'queued', $st['GA-CASE-F'] ?? null);
    assertEquals('retry: conflict stays parked', 'conflict', $st['GA-CASE-C'] ?? null);

    // ---- disable: no probes, inbound untouched ---------------------------
    stubStateWrite($stubStatePath, ['securityperms' => []]); // key now broken
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'enabled' => false,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    assertTrue('disable: ok without probes', ($d['success'] ?? false) === true, $r['body']);
    $row = $pdo->query("SELECT config_json, sync_enabled, status FROM integration_connections WHERE id={$connId}")->fetch(PDO::FETCH_ASSOC);
    $ob = json_decode($row['config_json'], true)['outbound'] ?? [];
    assertTrue('disable: enabled flipped off', empty($ob['enabled']));
    assertEquals('disable: inbound sync untouched', '1', (string)$row['sync_enabled']);
    assertEquals('disable: connection still active', 'active', $row['status']);

    // clearing the lab default works through the same action
    $r = httpJson('POST', "{$baseUrl}/api/integrations.php", json_encode([
        'action' => 'update_outbound_config', 'connection_id' => $connId,
        'laboratory_num' => 0,
    ]), $H, $jar);
    $d = json_decode($r['body'], true);
    $cfg = json_decode($pdo->query("SELECT config_json FROM integration_connections WHERE id={$connId}")->fetchColumn(), true);
    assertTrue('lab: default cleared', !isset($cfg['outbound']['laboratory_num']) || $cfg['outbound']['laboratory_num'] === null);
} finally {
    // ---- cleanup --------------------------------------------------------
    if ($connId) {
        $pdo->exec("DELETE FROM integration_outbound_events WHERE connection_id={$connId}");
        $pdo->exec("DELETE FROM integration_external_events WHERE connection_id={$connId}");
        $pdo->exec("DELETE FROM integration_entity_mappings WHERE connection_id={$connId}");
        $pdo->exec("DELETE FROM integration_credentials WHERE connection_id={$connId}");
        $pdo->exec("DELETE FROM integration_connections WHERE id={$connId}");
    }
    httpJson('POST', "{$baseUrl}/api/test-helpers.php", json_encode([
        'action' => 'cleanup_test_user', 'email' => $emailMember]), ['Content-Type: application/json']);
    httpJson('POST', "{$baseUrl}/api/test-helpers.php", json_encode([
        'action' => 'cleanup_test_user', 'email' => $email]), ['Content-Type: application/json']);
    stopPhpServer($dtProc);
    stopPhpServer($stubProc);
    @unlink($stubStatePath);
}

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
