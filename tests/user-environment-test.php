<?php
/**
 * User environment capture tests.
 *
 * Part A: pure metadata-parsing unit tests for parseUserEnvironment()
 *         (Chrome, Edge, Firefox, Safari, iOS, Android, reduced/empty UAs).
 * Part B: end-to-end - authenticated requests record the correct user's
 *         environment, UA changes are captured promptly, the write throttle
 *         holds, and unauthorized access to the admin view is rejected.
 */

$base = __DIR__ . '/..';
require_once $base . '/api/user-environment.php';

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, string $context = ''): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$name}\n"; }
    else { $failed++; echo "FAIL: {$name}" . ($context ? " ({$context})" : '') . "\n"; }
}

// ---------------------------------------------------------------------------
// Part A: UA metadata parsing (NOT actual browser testing - parser unit tests)
// ---------------------------------------------------------------------------
$uaCases = [
    ['Chrome on Windows 10/11', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36', 'Chrome 126.0.6478.127', 'Windows 10/11'],
    ['Edge on Windows (not misread as Chrome)', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.2535.67', 'Edge 125.0.2535.67', 'Windows 10/11'],
    ['Firefox on macOS', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0.2', 'Firefox 127.0.2', 'macOS 10.15'],
    ['Safari on macOS', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari 17.5', 'macOS 14.5'],
    ['Safari on iPhone', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari 17.5', 'iOS 17.5.1'],
    ['Chrome on Android', 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.122 Mobile Safari/537.36', 'Chrome 126.0.6478.122', 'Android 14'],
    ['Edge on Android', 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36 EdgA/124.0.2478.87', 'Edge 124.0.2478.87 (Android)', 'Android 13'],
    ['ChromeOS', 'Mozilla/5.0 (X11; CrOS x86_64 15604.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36', 'Chrome 125.0.0.0', 'ChromeOS'],
    ['Reduced/empty UA -> Unknown', '', null, null],
    ['Unparseable UA -> Unknown', 'curl/8.0.1', null, null],
];

foreach ($uaCases as [$name, $ua, $wantBrowser, $wantOs]) {
    $env = parseUserEnvironment($ua);
    $okBrowser = $env['browser'] === $wantBrowser;
    $okOs = $env['os'] === $wantOs;
    assertTrue("parse: {$name}", $okBrowser && $okOs,
        "got browser=" . var_export($env['browser'], true) . " os=" . var_export($env['os'], true));
}

// ---------------------------------------------------------------------------
// Part B: end-to-end capture through the app
// ---------------------------------------------------------------------------
$port = 18302;
$docRoot = realpath($base);
$serverCmd = "php -S 127.0.0.1:{$port} -t \"{$docRoot}\"";
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($serverCmd, $descriptors, $pipes);
if (!$process) die("FAIL: Could not start dev server\n");
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
register_shutdown_function(function () use ($process, $pipes) {
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_terminate($process);
    proc_close($process);
});

$ready = false;
for ($i = 0; $i < 20; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $ready = true; break; }
    usleep(200000);
}
if (!$ready) die("FAIL: Dev server did not start\n");

$baseUrl = "http://127.0.0.1:{$port}";

function req(string $method, string $url, array $body = [], string $cookieJar = '', ?string $ua = null): array {
    $ch = curl_init($url);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($ua !== null) {
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    }
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    }
    curl_setopt($ch, CURLOPT_HEADER, true);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = $r === false ? '' : substr($r, $headerSize);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

$uaChrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36';
$uaFirefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0.2';

$setup = json_decode(req('POST', "{$baseUrl}/api/test-helpers.php", [
    'action' => 'setup_test_user',
    'email' => 'dtenv-' . uniqid() . '@example.com',
    'password' => 'TestPass123!',
    'firstName' => 'Env', 'lastName' => 'Test',
    'practiceName' => 'Env Test Practice'
])['body'], true);
$practiceId = (int)($setup['practice_id'] ?? 0);
assertTrue('setup_test_user', ($setup['success'] ?? false) && $practiceId > 0, json_encode($setup));

// Unauthenticated access to the admin user list must be rejected.
$cookieAnon = tempnam(sys_get_temp_dir(), 'env_cookie_');
$unauth = req('GET', "{$baseUrl}/api/admin-practices.php?action=users&practice_id={$practiceId}", [], $cookieAnon, $uaChrome);
assertTrue('unauthenticated admin access rejected', in_array($unauth['code'], [401, 403]), "code={$unauth['code']}");

// Login as the new user using a Chrome UA.
$cookieJar = tempnam(sys_get_temp_dir(), 'env_cookie_');
$login = json_decode(req('POST', "{$baseUrl}/api/auth-email.php", [
    'action' => 'login', 'email' => $setup['email'], 'password' => 'TestPass123!'
], $cookieJar, $uaChrome)['body'], true);
assertTrue('login succeeds', $login['success'] ?? false, json_encode($login));

// An authenticated request records the environment.
req('GET', "{$baseUrl}/api/get-settings.php", [], $cookieJar, $uaChrome);
$users1 = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=users&practice_id={$practiceId}", [], $cookieJar, $uaChrome)['body'], true);
$me = ($users1['users'] ?? [])[0] ?? [];
assertTrue('env recorded for authenticated user', ($me['last_env_browser'] ?? null) === 'Chrome 126.0.6478.127'
    && ($me['last_env_os'] ?? null) === 'Windows 10/11',
    'browser=' . var_export($me['last_env_browser'] ?? null, true) . ' os=' . var_export($me['last_env_os'] ?? null, true));
$seenAt1 = $me['last_env_seen_at'] ?? null;
assertTrue('seen_at populated', !empty($seenAt1));

// Write throttle: same session + same UA -> timestamp does not advance.
sleep(2);
req('GET', "{$baseUrl}/api/get-settings.php", [], $cookieJar, $uaChrome);
$users2 = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=users&practice_id={$practiceId}", [], $cookieJar, $uaChrome)['body'], true);
$seenAt2 = ($users2['users'] ?? [])[0]['last_env_seen_at'] ?? null;
assertTrue('throttle: unchanged UA does not rewrite', $seenAt2 === $seenAt1, "t1={$seenAt1} t2={$seenAt2}");

// UA change is captured promptly even within the throttle window.
req('GET', "{$baseUrl}/api/get-settings.php", [], $cookieJar, $uaFirefox);
$users3 = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=users&practice_id={$practiceId}", [], $cookieJar, $uaFirefox)['body'], true);
$me3 = ($users3['users'] ?? [])[0] ?? [];
assertTrue('UA change captured promptly', ($me3['last_env_browser'] ?? null) === 'Firefox 127.0.2',
    'browser=' . var_export($me3['last_env_browser'] ?? null, true));
assertTrue('seen_at advanced on change', ($me3['last_env_seen_at'] ?? null) > $seenAt1
    || ($me3['last_env_seen_at'] ?? null) !== $seenAt1);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
