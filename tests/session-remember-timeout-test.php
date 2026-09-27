<?php
/**
 * Integration regression test: "Remember my email" is a login-form prefill
 * only. It must never grant or restore authentication.
 *
 * Verified here:
 *  - Login with the box checked sets a `remembered_email` cookie and never
 *    a `remember_token` auth cookie.
 *  - An inactivity timeout destroys the session but leaves the prefill
 *    cookie untouched; the login page then shows the 60-minute message and
 *    re-prefills the email with the box still checked.
 *  - Simulated browser restart (session cookie dropped, remembered_email
 *    kept) still requires full credentials - no auto sign-in.
 *  - A forged legacy `remember_token` cookie is cleared on sight and grants
 *    nothing.
 *  - Unchecking the box clears the remembered email.
 *
 * This test starts a local PHP development server and creates a test user.
 */

$baseDir = __DIR__ . '/..';
$port = 18501;
$base = 'http://127.0.0.1:' . $port;
$cookieFile = sys_get_temp_dir() . '/dentatrak-remember-test-' . getmypid() . '.txt';
$reopenFile = sys_get_temp_dir() . '/dentatrak-remember-test-reopen-' . getmypid() . '.txt';
$ageFile = $baseDir . '/tmp-age-session-test-' . getmypid() . '.php';

$results = [];

// Clean up any stale state
@unlink($cookieFile);

// Write a temporary endpoint that ages the current session
file_put_contents($ageFile, '<?php
require_once __DIR__ . "/api/bootstrap.php";
require_once __DIR__ . "/api/session.php";
$_SESSION["last_activity"] = time() - 301;
$_SESSION["last_user_action_at"] = time() - 301;
echo json_encode(["ok" => true]);
');

function startServer($baseDir, $port) {
    $env = array_merge(
        array_filter(getenv(), function ($k) { return !in_array($k, ['SESSION_TIMEOUT', 'SESSION_WARNING_TIME', 'DENTATRAK_TEST_MODE'], true); }, ARRAY_FILTER_USE_KEY),
        [
            'SESSION_TIMEOUT' => '300',
            'SESSION_WARNING_TIME' => '60',
            'DENTATRAK_TEST_MODE' => 'true',
        ]
    );

    $cmd = 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($baseDir);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $baseDir, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start dev server');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    // Wait for server to be ready
    for ($i = 0; $i < 50; $i++) {
        $ch = curl_init('http://127.0.0.1:' . $port . '/index.php');
        if ($ch) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 0) {
                return $proc;
            }
        }
        usleep(100000);
    }

    throw new RuntimeException('Dev server did not start');
}

function stopServer($proc) {
    if (is_resource($proc)) {
        proc_terminate($proc, 9);
        proc_close($proc);
    }
}

function httpRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $headers = ['Content-Type: application/json'];
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    $headers = substr($r, 0, $headerSize);
    $body = substr($r, $headerSize);
    return ['code' => $code, 'loc' => $loc, 'headers' => $headers, 'body' => $body];
}

function jarHasCookie($cookieFile, $name) {
    if (!file_exists($cookieFile)) return false;
    foreach (file($cookieFile) as $line) {
        if (strpos($line, '#') === 0) continue;
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 7 && $parts[5] === $name) return true;
    }
    return false;
}

try {
    $proc = startServer($baseDir, $port);

    $email = 'remember_timeout_test_' . time() . '_' . getmypid() . '@example.com';
    $password = 'TestPass123!';

    // 1. Create test user
    $r = httpRequest($base . '/api/test-helpers.php', 'POST', [
        'action' => 'setup_test_user',
        'email' => $email,
        'password' => $password,
        'firstName' => 'Remember',
        'lastName' => 'Email',
        'practiceName' => 'Remember Email Practice',
    ]);
    $setup = json_decode($r['body'], true);
    $results[] = '1. Test user created: ' . (($r['code'] === 200 && !empty($setup['success'])) ? 'PASS' : 'FAIL');

    // 2. Login with "Remember my email" checked
    $r = httpRequest($base . '/api/auth-email.php', 'POST', [
        'action' => 'login',
        'email' => $email,
        'password' => $password,
        'rememberMe' => true,
    ], $cookieFile);
    $hasEmailCookie = strpos($r['headers'], 'Set-Cookie: remembered_email=') !== false
        && strpos($r['headers'], 'Set-Cookie: remembered_email=deleted') === false;
    $hasTokenCookie = strpos($r['headers'], 'Set-Cookie: remember_token=') !== false;
    $results[] = '2a. Login sets remembered_email cookie: ' . ($hasEmailCookie ? 'PASS' : 'FAIL');
    $results[] = '2b. Login never sets remember_token auth cookie: ' . (!$hasTokenCookie ? 'PASS' : 'FAIL');

    // 2c. Accept current Terms (owners/admins must accept before accessing main.php)
    $csrfRes = httpRequest($base . '/accept-terms.php', 'GET', null, $cookieFile);
    $csrfToken = null;
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $csrfRes['body'], $m)) {
        $csrfToken = $m[1];
    }
    if ($csrfToken) {
        $r = httpRequest($base . '/api/accept-terms.php', 'POST', [
            'accepted' => true,
            'terms_version' => '2026-09-01',
            'csrf_token' => $csrfToken,
        ], $cookieFile);
        $results[] = '2c. Terms accepted: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');
    } else {
        $results[] = '2c. Terms accepted: SKIP';
    }

    // 3. main.php loads while session is active
    $r = httpRequest($base . '/main.php', 'GET', null, $cookieFile);
    $results[] = '3. main.php active session returns 200: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');

    // 4. Age the session
    $r = httpRequest($base . '/tmp-age-session-test-' . getmypid() . '.php', 'GET', null, $cookieFile);
    $results[] = '4. Session aged to expired: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');

    // 5. session-status returns 401 inactivity; the remembered_email cookie
    //    is a prefill hint, not auth - it is intentionally left intact.
    $r = httpRequest($base . '/api/session-status.php', 'GET', null, $cookieFile);
    $status = json_decode($r['body'], true);
    $results[] = '5a. Expired session-status returns 401 inactivity: ' .
        (($r['code'] === 401 && ($status['reason'] ?? '') === 'inactivity') ? 'PASS' : 'FAIL');
    $results[] = '5b. remembered_email cookie survives the timeout: ' .
        (jarHasCookie($cookieFile, 'remembered_email') ? 'PASS' : 'FAIL');

    // 6. main.php redirects to login with the timeout flag
    $r = httpRequest($base . '/main.php', 'GET', null, $cookieFile);
    $results[] = '6. main.php after timeout redirects to login: ' .
        (($r['code'] === 302 && strpos($r['loc'] ?? '', 'login.php') !== false) ? 'PASS' : 'FAIL');

    // 7. login.php?timeout=1 shows the 60-minute message and re-prefills
    //    the remembered email with the box checked - but no auto sign-in.
    $r = httpRequest($base . '/login.php?timeout=1', 'GET', null, $cookieFile);
    $body = $r['body'];
    $results[] = '7a. login.php shows the 60-minute timeout message: ' .
        (strpos($body, 'Your session expired after 60 minutes of inactivity. Please sign in again.') !== false ? 'PASS' : 'FAIL');
    $results[] = '7b. login.php prefills the remembered email: ' .
        (strpos($body, 'value="' . $email . '"') !== false ? 'PASS' : 'FAIL');
    $results[] = '7c. checkbox stays checked for remembered email: ' .
        (preg_match('/id="rememberMe"[^>]*checked/', $body) === 1 ? 'PASS' : 'FAIL');
    $results[] = '7d. login.php does not auto-sign-in (stays 200, no main.php redirect): ' .
        ($r['code'] === 200 ? 'PASS' : 'FAIL');

    // 8. Simulate a browser restart: keep only the persistent cookies
    //    (remembered_email), drop the session cookie. Still no auto-auth.
    $lines = file($cookieFile);
    $keep = array_filter($lines, function ($l) { return strpos($l, 'PHPSESSID') === false; });
    file_put_contents($reopenFile, implode('', $keep));
    $r = httpRequest($base . '/login.php', 'GET', null, $reopenFile);
    $results[] = '8a. Browser reopen still requires fresh login: ' .
        ($r['code'] === 200 ? 'PASS' : 'FAIL');
    $results[] = '8b. Reopened login page still prefills the email: ' .
        (strpos($r['body'], 'value="' . $email . '"') !== false ? 'PASS' : 'FAIL');
    $r = httpRequest($base . '/main.php', 'GET', null, $reopenFile);
    $results[] = '8c. remembered_email alone cannot reach the app: ' .
        (($r['code'] === 302 && strpos($r['loc'] ?? '', 'login.php') !== false) ? 'PASS' : 'FAIL');

    // 9. A forged legacy remember_token is cleared on sight and grants
    //    nothing - login page stays on the login form. Seed the forged
    //    cookie through the jar so curl presents both cookies together.
    $forgeFile = sys_get_temp_dir() . '/dentatrak-remember-test-forge-' . getmypid() . '.txt';
    $forgeLines = implode('', $keep)
        . "127.0.0.1\tFALSE\t/\tFALSE\t0\tremember_token\tforgedselector:forgedsignature\n";
    file_put_contents($forgeFile, $forgeLines);
    $r = httpRequest($base . '/login.php', 'GET', null, $forgeFile);
    $cleared = strpos($r['headers'], 'remember_token=deleted') !== false
        || preg_match('/remember_token=[^;\r\n]*;\s*expires=[^;\r\n]*1970/i', $r['headers']);
    $results[] = '9a. legacy remember_token cleared on sight: ' . ($cleared ? 'PASS' : 'FAIL');
    $results[] = '9b. forged remember_token grants no access: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');

    // 10. Fresh login with the box UNCHECKED clears the remembered email.
    $r = httpRequest($base . '/api/auth-email.php', 'POST', [
        'action' => 'login',
        'email' => $email,
        'password' => $password,
        'rememberMe' => false,
    ], $cookieFile);
    $login = json_decode($r['body'], true);
    $clearsEmail = strpos($r['headers'], 'remembered_email=deleted') !== false
        || !jarHasCookie($cookieFile, 'remembered_email');
    $results[] = '10a. Unchecked login succeeds: ' . (!empty($login['success']) ? 'PASS' : 'FAIL');
    $results[] = '10b. Unchecked login clears remembered_email: ' . ($clearsEmail ? 'PASS' : 'FAIL');

    // Re-snapshot the jar post-login (the clear happened in this context).
    $lines = file($cookieFile);
    $keep = array_filter($lines, function ($l) { return strpos($l, 'PHPSESSID') === false; });
    file_put_contents($reopenFile, implode('', $keep));
    $r = httpRequest($base . '/login.php', 'GET', null, $reopenFile);
    $results[] = '10c. No prefill after unchecking: ' .
        (strpos($r['body'], 'value="' . $email . '"') === false ? 'PASS' : 'FAIL');

    stopServer($proc);
} catch (Throwable $e) {
    $results[] = 'ERROR: ' . $e->getMessage();
    if (isset($proc)) stopServer($proc);
}

// Cleanup
@unlink($ageFile);
@unlink($cookieFile);
@unlink($reopenFile);
@unlink(sys_get_temp_dir() . '/dentatrak-remember-test-forge-' . getmypid() . '.txt');

header('Content-Type: text/plain');
echo implode("\n", $results) . "\n";
