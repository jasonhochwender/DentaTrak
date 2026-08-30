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
    curl_close($ch);
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
// Authorization
// ---------------------------------------------------------------------------
$unauth = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id={$practiceId}", [], '', false);
$unauthData = json_decode($unauth['body'], true);
assertTrue('unauthenticated adoption request returns 401', $unauth['code'] === 401, "code={$unauth['code']}");
assertTrue('unauthenticated adoption JSON success=false', is_array($unauthData) && !($unauthData['success'] ?? true), $unauth['body']);

// Cleanup.
$clean = req('POST', "{$baseUrl}/api/test-helpers.php", ['action' => 'cleanup_test_user', 'email' => $email], $cookieJar, true);
$cleanData = json_decode($clean['body'], true);
assertTrue('cleanup succeeds', $cleanData['success'] ?? false, $clean['body']);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
