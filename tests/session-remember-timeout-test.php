<?php
/**
 * Integration regression test: a remember-me token cannot silently restore
 * authentication after an inactivity timeout.
 *
 * This test starts a local PHP development server, creates a test user,
 * logs them in with Remember Me, expires the session, and then proves that
 * the remember token is invalidated so login.php no longer auto-redirects.
 */

$baseDir = __DIR__ . '/..';
$port = 18501;
$base = 'http://127.0.0.1:' . $port;
$cookieFile = sys_get_temp_dir() . '/dentatrak-remember-test-' . getmypid() . '.txt';
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
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
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
        'lastName' => 'Timeout',
        'practiceName' => 'Remember Timeout Practice',
    ]);
    $setup = json_decode($r['body'], true);
    $results[] = '1. Test user created: ' . (($r['code'] === 200 && !empty($setup['success'])) ? 'PASS' : 'FAIL');

    // 2. Login with Remember Me
    $r = httpRequest($base . '/api/auth-email.php', 'POST', [
        'action' => 'login',
        'email' => $email,
        'password' => $password,
        'rememberMe' => true,
    ], $cookieFile);
    $hasRememberCookie = strpos($r['headers'], 'Set-Cookie: remember_token=') !== false;
    $results[] = '2. Login sets remember_token cookie: ' . ($hasRememberCookie ? 'PASS' : 'FAIL');

    // 2b. Accept current Terms (owners/admins must accept before accessing main.php)
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
        $results[] = '2b. Terms accepted: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');
    } else {
        $results[] = '2b. Terms accepted: SKIP';
    }

    // 3. main.php loads while session is active
    $r = httpRequest($base . '/main.php', 'GET', null, $cookieFile);
    $results[] = '3. main.php active session returns 200: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');

    // 4. Age the session
    $r = httpRequest($base . '/tmp-age-session-test-' . getmypid() . '.php', 'GET', null, $cookieFile);
    $results[] = '4. Session aged to expired: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');

    // 5. session-status returns 401 inactivity and clears remember cookie
    $r = httpRequest($base . '/api/session-status.php', 'GET', null, $cookieFile);
    $status = json_decode($r['body'], true);
    $clearedCookie = strpos($r['headers'], 'Set-Cookie: remember_token=deleted') !== false;
    $results[] = '5. Expired session-status returns 401 inactivity and clears remember cookie: ' .
        (($r['code'] === 401 && ($status['reason'] ?? '') === 'inactivity' && $clearedCookie) ? 'PASS' : 'FAIL');

    // 6. main.php redirects to login (server-side timeout or no-session fallback)
    $r = httpRequest($base . '/main.php', 'GET', null, $cookieFile);
    $results[] = '6. main.php after timeout redirects to login: ' .
        (($r['code'] === 302 && strpos($r['loc'] ?? '', 'login.php') !== false) ? 'PASS' : 'FAIL');

    // 7. login.php does NOT auto-redirect to main.php (remember token must be gone)
    $r = httpRequest($base . '/login.php', 'GET', null, $cookieFile);
    $results[] = '7. login.php does not auto-login after inactivity timeout: ' . ($r['code'] === 200 ? 'PASS' : 'FAIL');

    stopServer($proc);
} catch (Throwable $e) {
    $results[] = 'ERROR: ' . $e->getMessage();
    if (isset($proc)) stopServer($proc);
}

// Cleanup
@unlink($ageFile);
@unlink($cookieFile);

header('Content-Type: text/plain');
echo implode("\n", $results) . "\n";
