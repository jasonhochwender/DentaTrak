<?php
/**
 * PMS integrations Phase B API tests.
 *
 * Exercises api/integrations.php end-to-end through a local php -S server:
 *   - auth/admin/CSRF/feature-flag enforcement
 *   - configure/upsert lifecycle (pending, never verified)
 *   - credential save/replace/blank-preserve/explicit-remove
 *   - no credential material in API responses or audit rows
 *   - disconnect preserves mappings/history
 *   - cross-practice isolation
 *   - flag-off hides the Settings UI and rejects the API
 *
 * Requires the local development database (AGENTS.md: development env,
 * MySQL on port 3308) and a configured ENCRYPTION_KEY. Test fixtures are
 * created through api/test-helpers.php and removed afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/register-adapters.php';

global $pdo;

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, ?string $detail = null): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

function assertEquals(string $name, $expected, $actual, ?string $detail = null): void {
    assertTrue($name, $expected === $actual,
        ($detail ? $detail . ' - ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// ---------------------------------------------------------------------
// Server + HTTP helpers (pattern from admin-practices-csrf-and-auth-test)
// ---------------------------------------------------------------------
function startServer(int &$port, array $extraEnv = []) {
    $base = __DIR__ . '/..';
    for ($p = 18300; $p < 18380; $p++) {
        $socket = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if ($socket === false) {
            $port = $p;
            break;
        }
        fclose($socket);
    }

    $stderrPath = sys_get_temp_dir() . '/dentatrak-integrations-' . $port . '.log';
    @file_put_contents(sys_get_temp_dir() . '/dt-null-in.txt', '');
    $descriptors = [
        0 => ['file', sys_get_temp_dir() . '/dt-null-in.txt', 'r'],
        1 => ['file', sys_get_temp_dir() . '/dt-null-out.txt', 'w'],
        2 => ['file', $stderrPath, 'w'],
    ];
    $env = array_merge(getenv() ?: [], $extraEnv);
    $proc = proc_open("php -S 127.0.0.1:{$port} -t " . escapeshellarg($base), $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        return null;
    }

    $start = microtime(true);
    while (microtime(true) - $start < 10) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            return $proc;
        }
        usleep(100000);
    }

    proc_terminate($proc);
    return null;
}

function stopServer($proc, int $port): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    @unlink(sys_get_temp_dir() . '/dentatrak-integrations-' . $port . '.log');
}

function httpPost(string $url, array $body, string $cookieJar = '', ?string $csrf = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($csrf !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-CSRF-Token: ' . $csrf]);
    }
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $bodyStr = substr($response, $headerSize);
    curl_close($ch);
    return ['code' => $code, 'body' => $bodyStr];
}

function httpGet(string $url, string $cookieJar = ''): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $bodyStr = substr($response, $headerSize);
    curl_close($ch);
    return ['code' => $code, 'body' => $bodyStr];
}

function newCookieJar(): string {
    return tempnam(sys_get_temp_dir(), 'intcookie');
}

function extractCsrf(string $html): string {
    return preg_match('/<meta name="csrf-token" content="([^"]+)">/', $html, $m) ? $m[1] : '';
}

function setupUser(string $baseUrl, string $email): array {
    $r = httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'setup_test_user',
        'email' => $email,
        'password' => 'TestPass123!',
        'firstName' => 'PMS',
        'lastName' => 'Test',
        'practiceName' => 'PMS Test Practice'
    ]);
    $d = json_decode($r['body'], true);
    if ($r['code'] !== 200 || ($d['success'] ?? false) !== true) {
        echo "FATAL setup_user {$email}: HTTP {$r['code']} {$r['body']}\n";
        exit(1);
    }
    return ['practice_id' => (int)$d['practice_id'], 'user_id' => (int)$d['user_id']];
}

function setupMember(string $baseUrl, string $email, string $adminEmail): array {
    $r = httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'setup_practice_member',
        'email' => $email,
        'password' => 'TestPass123!',
        'adminEmail' => $adminEmail,
        'role' => 'user',
    ]);
    $d = json_decode($r['body'], true);
    if ($r['code'] !== 200 || ($d['success'] ?? false) !== true) {
        echo "FATAL setup_member {$email}: HTTP {$r['code']} {$r['body']}\n";
        exit(1);
    }
    return $d;
}

function loginUser(string $baseUrl, string $email, string $cookieJar): void {
    $r = httpPost("{$baseUrl}/api/auth-email.php", [
        'action' => 'login',
        'email' => $email,
        'password' => 'TestPass123!'
    ], $cookieJar);
    $d = json_decode($r['body'], true);
    if ($r['code'] !== 200 || ($d['success'] ?? false) !== true) {
        echo "FATAL login {$email}: HTTP {$r['code']} {$r['body']}\n";
        exit(1);
    }
}

function cleanupUser(string $baseUrl, string $email): void {
    httpPost("{$baseUrl}/api/test-helpers.php", ['action' => 'cleanup_test_user', 'email' => $email]);
}

function getCsrf(string $baseUrl, string $cookie): array {
    $r = httpGet("{$baseUrl}/main.php", $cookie);
    return ['csrf' => extractCsrf($r['body']), 'html' => $r['body'], 'code' => $r['code']];
}

// ---------------------------------------------------------------------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "SKIP: no database connection available\n";
    exit(1);
}

$port = 0;
$server = startServer($port, ['SHOW_PMS_INTEGRATIONS' => 'true']);
if (!$server) {
    echo "FATAL: could not start test server (flag on)\n";
    exit(1);
}
$baseUrl = "http://127.0.0.1:{$port}";

$emailA   = 'pms-test-admin-a-' . uniqid() . '@example.com';
$emailB   = 'pms-test-admin-b-' . uniqid() . '@example.com';
$emailMem = 'pms-test-member-'  . uniqid() . '@example.com';

$cookieA = newCookieJar();
$cookieB = newCookieJar();
$cookieM = newCookieJar();

$fixturesA = setupUser($baseUrl, $emailA);
$fixturesB = setupUser($baseUrl, $emailB);
$practiceA = $fixturesA['practice_id'];
$practiceB = $fixturesB['practice_id'];
$userA     = $fixturesA['user_id'];

setupMember($baseUrl, $emailMem, $emailA);

// Owner/admin fixtures must have accepted the current Terms (same gate
// save-settings.php enforces via requireCurrentTermsAcceptedForApi).
$termsVersion = currentTermsVersion();
$stmt = $pdo->prepare("UPDATE users SET terms_accepted_version = :v, terms_accepted_at = NOW() WHERE email IN (:a, :b)");
$stmt->execute(['v' => $termsVersion, 'a' => $emailA, 'b' => $emailB]);

loginUser($baseUrl, $emailA, $cookieA);
loginUser($baseUrl, $emailB, $cookieB);
loginUser($baseUrl, $emailMem, $cookieM);

// Member selects the shared practice so the admin check (not the context
// check) is what rejects them.
httpGet("{$baseUrl}/api/select-practice.php?practice_id={$practiceA}", $cookieM);

$csrfA = getCsrf($baseUrl, $cookieA);
$csrfB = getCsrf($baseUrl, $cookieB);
$csrfM = getCsrf($baseUrl, $cookieM);

// ---------------- UI presence (flag on) -------------------------------
assertTrue('flag on: main.php renders integrations nav item',
    strpos($csrfA['html'], 'data-nav-target="integrations"') !== false);
assertTrue('flag on: main.php renders integrations panel',
    strpos($csrfA['html'], 'data-twisty-id="integrations"') !== false);
assertTrue('flag on: main.php renders config modal',
    strpos($csrfA['html'], 'id="integrationConfigModal"') !== false);

// ---------------- Unauthenticated ------------------------------------
$r = httpGet("{$baseUrl}/api/integrations.php?action=list");
assertEquals('unauthenticated GET returns 401', 401, $r['code'], $r['body']);

$r = httpPost("{$baseUrl}/api/integrations.php", ['action' => 'configure', 'provider' => 'open_dental']);
assertEquals('unauthenticated POST returns 401', 401, $r['code'], $r['body']);

// ---------------- CSRF ------------------------------------------------
$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'configure', 'provider' => 'open_dental'], $cookieA);
assertEquals('missing CSRF returns 403', 403, $r['code'], $r['body']);

$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'configure', 'provider' => 'open_dental'], $cookieA, 'bogus-token');
assertEquals('invalid CSRF returns 403', 403, $r['code'], $r['body']);

$connRows = $pdo->query("SELECT COUNT(*) FROM integration_connections WHERE practice_id = {$practiceA}")->fetchColumn();
assertEquals('CSRF rejection created no connection', 0, (int)$connRows);

// ---------------- Empty list ------------------------------------------
$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieA);
$d = json_decode($r['body'], true);
assertEquals('GET list succeeds for admin', 200, $r['code'], $r['body']);
assertEquals('empty list before configure', 0, count($d['connections'] ?? []));

// ---------------- Plan entitlement (GA: every plan) --------------------
// PMS integration management is open to ALL subscription plans
// (hasIntegrationAccess - any practice with full app access). Operate,
// Control, and Scale all pass; only a practice with no working
// subscription is refused with 403 plan_required.
echo "--- plan entitlement (GA) ---\n";
function setPlan(string $baseUrl, string $email, string $plan, string $status = 'active'): void {
    $r = httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'set_subscription_plan', 'email' => $email,
        'plan' => $plan, 'status' => $status,
    ]);
    $d = json_decode($r['body'], true);
    if ($r['code'] !== 200 || ($d['success'] ?? false) !== true) {
        echo "FATAL set_plan {$plan}/{$status}: HTTP {$r['code']} {$r['body']}\n";
        exit(1);
    }
}

setPlan($baseUrl, $emailA, 'operate', 'active');

$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieA);
$d = json_decode($r['body'], true);
assertEquals('operate: list allowed (GA)', 200, $r['code'], $r['body']);

// Actions now pass the plan gate and fail only on their own validation
// (these connection ids do not exist for this practice -> 404, never 403
// plan_required, and no side effects).
$notFound = [
    'test_connection'  => ['action' => 'test_connection', 'connection_id' => 1],
    'subscribe'        => ['action' => 'subscribe', 'connection_id' => 1],
    'unsubscribe'      => ['action' => 'unsubscribe', 'connection_id' => 1],
    'import_existing'  => ['action' => 'import_existing', 'connection_id' => 1, 'scope_days' => 30],
    'disconnect'       => ['action' => 'disconnect', 'connection_id' => 1],
    'reenable'         => ['action' => 'reenable', 'connection_id' => 1],
    'update_settings'  => ['action' => 'update_settings', 'connection_id' => 1, 'settings' => []],
    'outbound_config'  => ['action' => 'update_outbound_config', 'connection_id' => 1, 'enabled' => true],
    'retry_failed'     => ['action' => 'retry_outbound_failed', 'connection_id' => 1],
    'od_lookups'       => ['action' => 'list_od_lookups', 'connection_id' => 1],
];
foreach ($notFound as $name => $payload) {
    $r = httpPost("{$baseUrl}/api/integrations.php", $payload, $cookieA, $csrfA['csrf']);
    $d = json_decode($r['body'], true);
    assertEquals("operate: {$name} not plan-blocked", 404, $r['code'], $r['body']);
    assertTrue("operate: {$name} no plan_required", ($d['error_code'] ?? null) !== 'plan_required');
}
$connRows = $pdo->query("SELECT COUNT(*) FROM integration_connections WHERE practice_id = {$practiceA}")->fetchColumn();
assertEquals('operate: no connection created', 0, (int)$connRows);

// Card is NOT plan-locked for Operate (GA): no data-locked attribute, the
// connect action renders.
$mainA = getCsrf($baseUrl, $cookieA);
assertTrue('operate: card NOT locked (GA)', strpos($mainA['html'], 'data-locked="true"') === false);
assertTrue('operate: connect button rendered', strpos($mainA['html'], 'integration-action-connect') !== false);

// A lapsed subscription still blocks every action with 403 plan_required
// and renders the locked card.
setPlan($baseUrl, $emailA, 'operate', 'canceled');
$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieA);
$d = json_decode($r['body'], true);
assertEquals('canceled: list blocked', 403, $r['code'], $r['body']);
assertEquals('canceled: error_code plan_required', 'plan_required', $d['error_code'] ?? null);
$mainA = getCsrf($baseUrl, $cookieA);
assertTrue('canceled: integration card marked locked', strpos($mainA['html'], 'data-locked="true"') !== false);

// Control and Scale remain entitled.
setPlan($baseUrl, $emailA, 'control', 'active');
$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieA);
assertEquals('control: list allowed', 200, $r['code'], $r['body']);
setPlan($baseUrl, $emailA, 'scale', 'active');
$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieA);
assertEquals('scale: list allowed', 200, $r['code'], $r['body']);

// Back to trialing so the rest of the suite exercises the entitled path
// exactly as before.
setPlan($baseUrl, $emailA, 'operate', 'trialing');
$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieA);
assertEquals('trialing restored: list allowed', 200, $r['code'], $r['body']);

// ---------------- Invalid provider ------------------------------------
$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'configure', 'provider' => 'dentrix', 'credentials' => ['developer_key' => 'x']],
    $cookieA, $csrfA['csrf']);
assertEquals('invalid provider rejected', 400, $r['code'], $r['body']);

// ---------------- Initial configure -----------------------------------
// Only customer_key is a practice credential now - developer_key is a
// central server secret and is rejected if submitted.
$DEV_SECRET = 'dev-secret-PHASEB-' . bin2hex(random_bytes(6));
$CUST_SECRET = 'cust-secret-PHASEB-' . bin2hex(random_bytes(6));
$CUST_SECRET_2 = 'cust-secret-NEW-' . bin2hex(random_bytes(6));

$r = httpPost("{$baseUrl}/api/integrations.php", [
    'action' => 'configure',
    'provider' => 'open_dental',
    'credentials' => ['developer_key' => $DEV_SECRET],
], $cookieA, $csrfA['csrf']);
assertEquals('developer_key rejected (central server secret now)', 400, $r['code'], $r['body']);

$r = httpPost("{$baseUrl}/api/integrations.php", [
    'action' => 'configure',
    'provider' => 'open_dental',
    'credentials' => ['customer_key' => $CUST_SECRET],
], $cookieA, $csrfA['csrf']);
$d = json_decode($r['body'], true);
assertEquals('configure succeeds', 200, $r['code'], $r['body']);
assertEquals('initial status is pending', 'pending', $d['connection']['status'] ?? null);
assertTrue('created flag true on first configure', ($d['created'] ?? null) === true);
assertTrue('credentials_configured reported', ($d['connection']['credentials_configured'] ?? null) === true);
$connId = (int)($d['connection']['id'] ?? 0);
assertTrue('connection id returned', $connId > 0);
assertTrue('configure response contains no customer secret', strpos($r['body'], $CUST_SECRET) === false);

// ---------------- DB state: ciphertext, not plaintext ------------------
$stmt = $pdo->prepare("SELECT credential_key, credential_value_encrypted FROM integration_credentials WHERE connection_id = :id");
$stmt->execute(['id' => $connId]);
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
assertEquals('one credential stored (customer_key only)', 1, count($rows));
assertTrue('customer_key stored encrypted (not plaintext)',
    isset($rows['customer_key']) && $rows['customer_key'] !== $CUST_SECRET && strpos($rows['customer_key'], $CUST_SECRET) === false);
assertEquals('customer_key decrypts to submitted value', $CUST_SECRET,
    IntegrationCredentials::get($pdo, $connId, 'customer_key'));
assertTrue('no developer_key stored per practice',
    IntegrationCredentials::get($pdo, $connId, 'developer_key') === null);

// ---------------- GET list projection is secret-free -------------------
$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieA);
$d = json_decode($r['body'], true);
assertEquals('list shows one connection', 1, count($d['connections'] ?? []));
assertTrue('list response contains no developer secret', strpos($r['body'], $DEV_SECRET) === false);
assertTrue('list response contains no customer secret', strpos($r['body'], $CUST_SECRET) === false);
assertTrue('list response contains no ciphertext',
    !isset($rows['customer_key']) || strpos($r['body'], $rows['customer_key']) === false);
assertTrue('list exposes credential key names for UI state',
    in_array('customer_key', $d['connections'][0]['credential_keys'] ?? [], true));

// ---------------- Legacy developer_key cleanup -------------------------
// Rows written before the central-secret change are ignored and purged on
// the adapter credential-read path.
IntegrationCredentials::store($pdo, $connId, 'developer_key', $DEV_SECRET);
assertTrue('legacy developer_key seeded', IntegrationCredentials::has($pdo, $connId, 'developer_key'));
$legacyCheck = IntegrationManager::getConnectionCredentials($pdo, $connId);
assertTrue('legacy developer_key not handed to adapters', !isset($legacyCheck['developer_key']));
assertTrue('legacy developer_key purged from store', !IntegrationCredentials::has($pdo, $connId, 'developer_key'));
assertEquals('customer_key survives legacy purge', $CUST_SECRET,
    IntegrationCredentials::get($pdo, $connId, 'customer_key'));

// ---------------- Re-configure: upsert, blank preserves ---------------
$r = httpPost("{$baseUrl}/api/integrations.php", [
    'action' => 'configure',
    'provider' => 'open_dental',
    'credentials' => ['customer_key' => $CUST_SECRET_2],
], $cookieA, $csrfA['csrf']);
$d = json_decode($r['body'], true);
assertEquals('reconfigure succeeds', 200, $r['code'], $r['body']);
assertEquals('reconfigure returns same connection (upsert)', $connId, (int)($d['connection']['id'] ?? -1));
assertTrue('created flag false on reconfigure', ($d['created'] ?? null) === false);
assertEquals('still one connection row', 1,
    (int)$pdo->query("SELECT COUNT(*) FROM integration_connections WHERE practice_id = {$practiceA} AND provider = 'open_dental'")->fetchColumn());
assertEquals('customer_key replaced', $CUST_SECRET_2,
    IntegrationCredentials::get($pdo, $connId, 'customer_key'));

// Blank preserves the stored value.
$r = httpPost("{$baseUrl}/api/integrations.php", [
    'action' => 'configure',
    'provider' => 'open_dental',
    'credentials' => ['customer_key' => ''],
], $cookieA, $csrfA['csrf']);
assertEquals('blank reconfigure succeeds', 200, $r['code'], $r['body']);
assertEquals('blank customer_key preserved existing', $CUST_SECRET_2,
    IntegrationCredentials::get($pdo, $connId, 'customer_key'));

// ---------------- Explicit credential removal --------------------------
$r = httpPost("{$baseUrl}/api/integrations.php", [
    'action' => 'configure',
    'provider' => 'open_dental',
    'remove_credentials' => ['developer_key'],
], $cookieA, $csrfA['csrf']);
assertEquals('developer_key removal rejected (not a stored credential)', 400, $r['code'], $r['body']);

$r = httpPost("{$baseUrl}/api/integrations.php", [
    'action' => 'configure',
    'provider' => 'open_dental',
    'remove_credentials' => ['customer_key'],
], $cookieA, $csrfA['csrf']);
assertEquals('remove_credentials succeeds', 200, $r['code'], $r['body']);
assertTrue('customer_key removed', IntegrationCredentials::get($pdo, $connId, 'customer_key') === null);
IntegrationCredentials::store($pdo, $connId, 'customer_key', $CUST_SECRET_2);

// ---------------- Whitelisted settings only ----------------------------
$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'update_settings', 'connection_id' => $connId, 'sync_enabled' => false],
    $cookieA, $csrfA['csrf']);
$d = json_decode($r['body'], true);
assertEquals('update_settings succeeds', 200, $r['code'], $r['body']);
assertTrue('sync_enabled updated to false', ($d['connection']['sync_enabled'] ?? null) === false);

$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'update_settings', 'connection_id' => $connId],
    $cookieA, $csrfA['csrf']);
assertEquals('update_settings with no supported keys rejected', 400, $r['code'], $r['body']);

$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'update_settings', 'connection_id' => $connId, 'sync_enabled' => true, 'config_json' => ['evil' => 'injection']],
    $cookieA, $csrfA['csrf']);
$d = json_decode($r['body'], true);
$cfg = $pdo->query("SELECT config_json FROM integration_connections WHERE id = {$connId}")->fetchColumn();
assertTrue('arbitrary config_json injection ignored', $cfg === null || strpos((string)$cfg, 'evil') === false);

// ---------------- Seed mapping + run, then disconnect ------------------
$pdo->prepare("INSERT INTO integration_entity_mappings (connection_id, entity_type, external_id, internal_type, internal_id, last_seen_at)
             VALUES (:id, 'case', 'ext-123', 'case', 999, NOW())")->execute(['id' => $connId]);
$mappingId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO integration_sync_runs (connection_id, run_type, status, started_at)
             VALUES (:id, 'manual', 'completed', NOW())")->execute(['id' => $connId]);
$runId = (int)$pdo->lastInsertId();

$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'disconnect', 'connection_id' => $connId], $cookieA, $csrfA['csrf']);
$d = json_decode($r['body'], true);
assertEquals('disconnect succeeds', 200, $r['code'], $r['body']);
assertEquals('disconnect sets disabled status', 'disabled', $d['connection']['status'] ?? null);
assertTrue('disconnect clears sync_enabled', ($d['connection']['sync_enabled'] ?? true) === false);
assertEquals('connection row preserved (no delete)', 1,
    (int)$pdo->query("SELECT COUNT(*) FROM integration_connections WHERE id = {$connId}")->fetchColumn());
assertEquals('entity mapping preserved after disconnect', 1,
    (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE id = {$mappingId}")->fetchColumn());
assertEquals('sync run preserved after disconnect', 1,
    (int)$pdo->query("SELECT COUNT(*) FROM integration_sync_runs WHERE id = {$runId}")->fetchColumn());
assertEquals('credentials preserved after disconnect', $CUST_SECRET_2,
    IntegrationCredentials::get($pdo, $connId, 'customer_key'));

$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'update_settings', 'connection_id' => $connId, 'sync_enabled' => true],
    $cookieA, $csrfA['csrf']);
assertEquals('update_settings on disabled connection rejected', 409, $r['code'], $r['body']);

// ---------------- Re-enable ---------------------------------------------
$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'reenable', 'connection_id' => $connId], $cookieA, $csrfA['csrf']);
$d = json_decode($r['body'], true);
assertEquals('reenable succeeds', 200, $r['code'], $r['body']);
assertEquals('reenable returns to pending (not active)', 'pending', $d['connection']['status'] ?? null);

// ---------------- Non-admin member rejected ------------------------------
$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieM);
assertTrue('non-admin member GET rejected', $r['code'] === 403 || $r['code'] === 401, "HTTP {$r['code']}");

$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'configure', 'provider' => 'open_dental'], $cookieM, $csrfM['csrf']);
assertTrue('non-admin member POST rejected', $r['code'] === 403 || $r['code'] === 401, "HTTP {$r['code']}");

// ---------------- Cross-practice isolation ------------------------------
$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'disconnect', 'connection_id' => $connId], $cookieB, $csrfB['csrf']);
assertEquals('foreign practice cannot disconnect connection', 404, $r['code'], $r['body']);

$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'update_settings', 'connection_id' => $connId, 'sync_enabled' => false], $cookieB, $csrfB['csrf']);
assertEquals('foreign practice cannot update settings', 404, $r['code'], $r['body']);

$r = httpGet("{$baseUrl}/api/integrations.php?action=list", $cookieB);
$d = json_decode($r['body'], true);
$foreignIds = array_map(fn($c) => (int)$c['id'], $d['connections'] ?? []);
assertTrue('practice B list does not include practice A connection', !in_array($connId, $foreignIds, true));

$status = $pdo->query("SELECT status FROM integration_connections WHERE id = {$connId}")->fetchColumn();
assertEquals('practice A connection unchanged by foreign attempts', 'pending', $status);

// ---------------- Unknown action ----------------------------------------
$r = httpPost("{$baseUrl}/api/integrations.php",
    ['action' => 'sync_now', 'connection_id' => $connId], $cookieA, $csrfA['csrf']);
assertEquals('unknown action rejected', 400, $r['code'], $r['body']);

// ---------------- Audit trail -------------------------------------------
$stmt = $pdo->prepare("SELECT activity_type, description FROM user_activity_log
                     WHERE user_id = :uid AND activity_type LIKE 'integration_%'
                     ORDER BY id");
$stmt->execute(['uid' => $userA]);
$auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$auditTypes = array_column($auditRows, 'activity_type');
assertTrue('audit: integration_configured logged', in_array('integration_configured', $auditTypes, true));
assertTrue('audit: integration_credentials_updated logged', in_array('integration_credentials_updated', $auditTypes, true));
assertTrue('audit: integration_disabled logged', in_array('integration_disabled', $auditTypes, true));
assertTrue('audit: integration_reenabled logged', in_array('integration_reenabled', $auditTypes, true));
$auditBlob = json_encode($auditRows);
assertTrue('audit rows contain no developer secret', strpos($auditBlob, $DEV_SECRET) === false);
assertTrue('audit rows contain no customer secret', strpos($auditBlob, $CUST_SECRET) === false && strpos($auditBlob, $CUST_SECRET_2) === false);
assertTrue('audit rows reference provider', strpos($auditBlob, 'open_dental') !== false);

// =====================================================================
// Server 2: flag OFF -> UI hidden and API rejects everything
// =====================================================================
$port2 = 0;
// Explicit 'false' - the repo .env may set the flag true and the FEATURE_DEFAULTS
// default is now true, so absence alone no longer disables it.
$server2 = startServer($port2, ['SHOW_PMS_INTEGRATIONS' => 'false']);
if (!$server2) {
    echo "FATAL: could not start test server (flag off)\n";
    stopServer($server, $port);
    exit(1);
}
$baseUrl2 = "http://127.0.0.1:{$port2}";

// Session cookie is shared (file sessions, same cookie scope).
$off = getCsrf($baseUrl2, $cookieA);
// With SHOW_PMS_INTEGRATIONS off, an admin still sees the informational
// scanner section (permission-gated disclosure), but no PMS provider card,
// action buttons, or credential modal may render.
assertTrue('flag off: no PMS provider card for admin', strpos($off['html'], 'data-provider=') === false);
assertTrue('flag off: scanner disclosure visible to admin', strpos($off['html'], 'data-scanner=') !== false);
assertTrue('flag off: no config modal', strpos($off['html'], 'id="integrationConfigModal"') === false);
// A non-admin member still gets no integrations UI at all.
$offMember = getCsrf($baseUrl2, $cookieM);
assertTrue('flag off: no integrations nav item for member', strpos($offMember['html'], 'data-nav-target="integrations"') === false);
assertTrue('flag off: no integrations panel for member', strpos($offMember['html'], 'data-twisty-id="integrations"') === false);

$r = httpGet("{$baseUrl2}/api/integrations.php?action=list", $cookieA);
assertEquals('flag off: GET list rejected', 403, $r['code'], $r['body']);

$r = httpPost("{$baseUrl2}/api/integrations.php",
    ['action' => 'configure', 'provider' => 'open_dental'], $cookieA, $off['csrf']);
assertEquals('flag off: configure rejected', 403, $r['code'], $r['body']);

$r = httpPost("{$baseUrl2}/api/integrations.php",
    ['action' => 'disconnect', 'connection_id' => $connId], $cookieA, $off['csrf']);
assertEquals('flag off: disconnect rejected', 403, $r['code'], $r['body']);

// =====================================================================
// Cleanup
// =====================================================================
$pdo->exec("DELETE FROM integration_connections WHERE id = {$connId}");
// (FK cascade removes credentials/mappings/runs belonging to the connection)

cleanupUser($baseUrl, $emailA);
cleanupUser($baseUrl, $emailB);
cleanupUser($baseUrl, $emailMem);

stopServer($server, $port);
stopServer($server2, $port2);
@unlink($cookieA);
@unlink($cookieB);
@unlink($cookieM);

echo "\n========================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
