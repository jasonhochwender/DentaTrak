<?php
/**
 * Admin Practice API exception-boundary test.
 *
 * Proves that an unexpected exception in the admin API returns a safe JSON
 * payload with HTTP 500 and no sensitive details (no SQL, paths, or stack
 * traces in the browser response).
 */

$base = __DIR__ . '/..';
$port = 18400;
$docRoot = realpath($base);

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
$email = 'dtapierr-test-' . uniqid() . '@example.com';
$password = 'TestPass123!';
$cookieJar = tempnam(sys_get_temp_dir(), 'apierr_cookie_');

$setup = req('POST', "{$baseUrl}/api/test-helpers.php", [
    'action' => 'setup_test_user',
    'email' => $email,
    'password' => $password,
    'firstName' => 'ApiErr',
    'lastName' => 'Test',
    'practiceName' => 'API Error Test Practice'
]);
$setupData = json_decode($setup['body'], true);
assertTrue('setup_test_user succeeds', $setupData['success'] ?? false, $setup['body']);

$login = req('POST', "{$baseUrl}/api/auth-email.php", [
    'action' => 'login',
    'email' => $email,
    'password' => $password
], $cookieJar, true);
$loginData = json_decode($login['body'], true);
assertTrue('login succeeds', $loginData['success'] ?? false, $login['body']);

// Force an unexpected exception through the admin-practices API boundary.
$forced = req('GET', "{$baseUrl}/tests/force-admin-error.php", [], $cookieJar, true);
$forcedData = json_decode($forced['body'], true);

assertTrue('forced error returns HTTP 500', $forced['code'] === 500, "code={$forced['code']}");
assertTrue('forced error body is valid JSON', is_array($forcedData) && ($forcedData['success'] === false), $forced['body']);
assertTrue('forced error payload has generic error message', isset($forcedData['error']) && $forcedData['error'] === 'Unable to load practice information.', "error={$forcedData['error']}");
assertTrue('forced error response has no stack trace', strpos($forced['body'], 'Stack trace') === false, $forced['body']);
assertTrue('forced error response has no filesystem paths', strpos($forced['body'], 'C:\\') === false && strpos($forced['body'], 'C:/') === false, $forced['body']);
assertTrue('forced error response has no SQL keywords in error', !preg_match('/(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN|SQLSTATE)/i', $forced['body'] ?? ''), $forced['body']);

// Deliberate 400 is not masked by the generic catch.
$missing = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id=0", [], $cookieJar, true);
$missingData = json_decode($missing['body'], true);
assertTrue('deliberate validation still returns 400', $missing['code'] === 400, "code={$missing['code']}");
assertTrue('deliberate validation still has success=false', is_array($missingData) && $missingData['success'] === false, $missing['body']);

// Deliberate 401 is still returned.
$unauth = req('GET', "{$baseUrl}/api/admin-practices.php?action=adoption&practice_id=1", [], '', false);
$unauthData = json_decode($unauth['body'], true);
assertTrue('unauthenticated request still returns 401', $unauth['code'] === 401, "code={$unauth['code']}");

$clean = req('POST', "{$baseUrl}/api/test-helpers.php", ['action' => 'cleanup_test_user', 'email' => $email], $cookieJar, true);
$cleanData = json_decode($clean['body'], true);
assertTrue('cleanup succeeds', $cleanData['success'] ?? false, $clean['body']);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
