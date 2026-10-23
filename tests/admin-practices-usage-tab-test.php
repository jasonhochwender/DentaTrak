<?php
/**
 * Usage & Adoption tab integration tests.
 *
 * Exercises the api/admin-practices.php?action=adoption endpoint through the
 * local PHP development server and asserts the exact response shape used by the
 * Usage & Adoption tab. Also covers error, empty, and authorization paths.
 */

$base = __DIR__ . '/..';
$port = 18300;
$docRoot = realpath($base);

// Start a local PHP dev server for this test run.
$serverCmd = "php -S 127.0.0.1:{$port} -t \"{$docRoot}\"";
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($serverCmd, $descriptors, $pipes);
if (!$process) {
    die("FAIL: Could not start dev server\n");
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
register_shutdown_function(function () use ($process, $pipes) {
    foreach ($pipes as $p) {
        if (is_resource($p)) fclose($p);
    }
    proc_terminate($process);
    proc_close($process);
});

// Wait for the server to accept connections.
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) {
        fclose($fp);
        $ready = true;
        break;
    }
    usleep(200000);
}
if (!$ready) {
    die("FAIL: Dev server did not start\n");
}

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, string $context = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}" . ($context ? " ({$context})" : '') . "\n";
    }
}

function req(string $method, string $url, array $body = [], string $cookieJar = '', bool $saveCookies = false): array {
    $ch = curl_init($url);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        if ($saveCookies) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        }
    }
    curl_setopt($ch, CURLOPT_HEADER, true);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = $r === false ? '' : substr($r, $headerSize);
    return ['code' => $code, 'body' => $body];
}

$baseUrl = "http://127.0.0.1:{$port}";
$email = 'dtusage-test-' . uniqid() . '@example.com';
$password = 'TestPass123!';
$cookieJar = tempnam(sys_get_temp_dir(), 'usage_cookie_');

// Setup a test user and practice.
$setup = req('POST', "{$baseUrl}/api/test-helpers.php", [
    'action' => 'setup_test_user',
    'email' => $email,
    'password' => $password,
    'firstName' => 'Usage',
    'lastName' => 'Test',
    'practiceName' => 'Usage Test Practice'
]);
$setupData = json_decode($setup['body'], true);
$practiceId = (int)($setupData['practice_id'] ?? 0);
assertTrue('setup_test_user succeeds', $setupData['success'] ?? false, $setup['body']);
assertTrue('practice_id is numeric', $practiceId > 0, "practice_id={$practiceId}");

// Login to establish a session.
$login = req('POST', "{$baseUrl}/api/auth-email.php", [
    'action' => 'login',
    'email' => $email,
    'password' => $password
], $cookieJar, true);
$loginData = json_decode($login['body'], true);
assertTrue('login succeeds', $loginData['success'] ?? false, $login['body']);

// ---------------------------------------------------------------------------
// Adoption endpoint success and shape
// ---------------------------------------------------------------------------
$adoptResponse = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id={$practiceId}", [], $cookieJar, true);
$adoptData = json_decode($adoptResponse['body'], true);
$adopt = $adoptData['adoption'] ?? [];

assertTrue('adoption returns HTTP 200', $adoptResponse['code'] === 200, "code={$adoptResponse['code']}");
assertTrue('adoption returns valid JSON success', is_array($adoptData) && ($adoptData['success'] ?? false), "body={$adoptResponse['body']}");
assertTrue('adoption has total_users', isset($adopt['total_users']) && is_int($adopt['total_users']));
assertTrue('adoption has users_with_login', isset($adopt['users_with_login']) && is_int($adopt['users_with_login']));
assertTrue('adoption has users_without_login', isset($adopt['users_without_login']) && is_int($adopt['users_without_login']));
assertTrue('adoption has most_recent_login', array_key_exists('most_recent_login', $adopt));
assertTrue('adoption has active_cases', isset($adopt['active_cases']) && is_int($adopt['active_cases']));
assertTrue('adoption has created_last_30_days', isset($adopt['created_last_30_days']) && is_int($adopt['created_last_30_days']));
assertTrue('adoption has delivered_last_30_days', isset($adopt['delivered_last_30_days']) && is_int($adopt['delivered_last_30_days']));
assertTrue('adoption has terminal_status', isset($adopt['terminal_status']));
assertTrue('adoption has terminal_label', isset($adopt['terminal_label']));
assertTrue('adoption has demo_case_count', isset($adopt['demo_case_count']) && is_int($adopt['demo_case_count']));
assertTrue('adoption has last_case_activity', array_key_exists('last_case_activity', $adopt));
assertTrue('adoption has last_activity', array_key_exists('last_activity', $adopt));
assertTrue('adoption has summary', isset($adopt['summary']));
assertTrue('adoption summary is one of expected values', in_array($adopt['summary'], ['No recorded case activity', 'Recent case activity', 'Historical case activity'], true), "summary={$adopt['summary']}");

// Empty usage for a freshly created practice with no cases.
assertTrue('empty practice has zero active_cases', $adopt['active_cases'] === 0);
assertTrue('empty practice has zero created_last_30_days', $adopt['created_last_30_days'] === 0);
assertTrue('empty practice has zero delivered_last_30_days', $adopt['delivered_last_30_days'] === 0);
assertTrue('empty practice has zero demo_case_count', $adopt['demo_case_count'] === 0);

// ---------------------------------------------------------------------------
// Error and validation
// ---------------------------------------------------------------------------
$missing = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id=0", [], $cookieJar, true);
$missingData = json_decode($missing['body'], true);
assertTrue('practice_id 0 returns non-200', $missing['code'] >= 400, "code={$missing['code']}");
assertTrue('practice_id 0 returns JSON error', is_array($missingData) && !($missingData['success'] ?? true), $missing['body']);

$invalid = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id=abc", [], $cookieJar, true);
$invalidData = json_decode($invalid['body'], true);
assertTrue('non-numeric practice_id returns non-200', $invalid['code'] >= 400, "code={$invalid['code']}");

$nonExistent = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id=9999999", [], $cookieJar, true);
$neData = json_decode($nonExistent['body'], true);
assertTrue('non-existent practice returns success=false or empty data', ($neData['success'] ?? false) === false || ($neData['adoption']['total_users'] ?? 0) === 0, $nonExistent['body']);

// ---------------------------------------------------------------------------
// Storage usage endpoint: empty cached state, CSRF on refresh, refresh run
// ---------------------------------------------------------------------------
$st0 = req('GET', "{$baseUrl}/api/admin-practices.php?action=storage&practice_id={$practiceId}", [], $cookieJar, true);
$st0Data = json_decode($st0['body'], true);
assertTrue('storage GET returns 200', $st0['code'] === 200, "code={$st0['code']} body=" . substr($st0['body'], 0, 150));
assertTrue('storage GET reports not-yet-calculated', ($st0Data['storage']['calculated'] ?? true) === false);
assertTrue('storage GET total_bytes null before first calc', array_key_exists('total_bytes', $st0Data['storage'] ?? []) && $st0Data['storage']['total_bytes'] === null);

// Refresh requires CSRF.
$csrfPage = req('GET', "{$baseUrl}/admin-practices.php", [], $cookieJar, true);
$csrfToken = preg_match('/<meta name="csrf-token" content="([^"]+)">/', $csrfPage['body'], $m) ? $m[1] : '';
assertTrue('csrf token obtained', !empty($csrfToken));

// Owners/admins must accept current Terms before admin mutations.
$terms = req('POST', "{$baseUrl}/api/accept-terms.php", [
    'accepted' => true,
    'terms_version' => '2026-09-01',
    'csrf_token' => $csrfToken
], $cookieJar, true);
assertTrue('terms accepted', $terms['code'] === 200, "code={$terms['code']} body=" . substr($terms['body'], 0, 150));

$noCsrfRefresh = req('POST', "{$baseUrl}/api/admin-practices.php?action=refresh_storage", [
    'practice_id' => $practiceId
], $cookieJar, true);
assertTrue('refresh_storage without CSRF is rejected', $noCsrfRefresh['code'] === 403, "code={$noCsrfRefresh['code']}");

// Fresh practice has no files: refresh yields a genuine zero total.
$refresh = req('POST', "{$baseUrl}/api/admin-practices.php?action=refresh_storage", [
    'practice_id' => $practiceId,
    'csrf_token' => $csrfToken
], $cookieJar, true);
$refreshData = json_decode($refresh['body'], true);
assertTrue('refresh_storage succeeds', $refresh['code'] === 200 && ($refreshData['refreshed'] ?? false), "code={$refresh['code']} body=" . substr($refresh['body'], 0, 250));
assertTrue('empty practice totals are genuine zero', ($refreshData['usage']['total_bytes'] ?? -1) === 0);
assertTrue('usage has all categories', isset($refreshData['usage']['categories']['image'], $refreshData['usage']['categories']['scan'], $refreshData['usage']['categories']['other'], $refreshData['usage']['categories']['branding']));
assertTrue('calculated_at present after refresh', !empty($refreshData['usage']['calculated_at']));

// Cached read returns the same aggregate without recomputing.
$st1 = req('GET', "{$baseUrl}/api/admin-practices.php?action=storage&practice_id={$practiceId}", [], $cookieJar, true);
$st1Data = json_decode($st1['body'], true);
assertTrue('storage GET returns cached totals', ($st1Data['storage']['total_bytes'] ?? null) === 0 && ($st1Data['storage']['calculated'] ?? false) === true);

// Payload must not expose file identifiers.
assertTrue('storage payload has no file paths/names', strpos($st1['body'], 'storagePath') === false && strpos($st1['body'], 'fileName') === false && strpos($st1['body'], 'signed') === false);

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------
$unauth = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id={$practiceId}", [], '', false);
$unauthData = json_decode($unauth['body'], true);
assertTrue('unauthenticated adoption request returns 401', $unauth['code'] === 401, "code={$unauth['code']}");
assertTrue('unauthenticated adoption JSON success=false', is_array($unauthData) && !($unauthData['success'] ?? true), $unauth['body']);

$unauthStorage = req('GET', "{$baseUrl}/api/admin-practices.php?action=storage&practice_id={$practiceId}", [], '', false);
assertTrue('unauthenticated storage request returns 401', $unauthStorage['code'] === 401, "code={$unauthStorage['code']}");

// Cleanup.
$clean = req('POST', "{$baseUrl}/api/test-helpers.php", ['action' => 'cleanup_test_user', 'email' => $email], $cookieJar, true);
$cleanData = json_decode($clean['body'], true);
assertTrue('cleanup succeeds', $cleanData['success'] ?? false, $clean['body']);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
