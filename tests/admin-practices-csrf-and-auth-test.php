<?php
/**
 * CSRF and authorization integration tests for Practice Administration.
 *
 * Exercises every state-changing POST endpoint through a local PHP server and
 * verifies:
 *
 * - Authentication is always required (401 for unauthenticated).
 * - CSRF token is required for every POST action (403 for missing or invalid).
 * - Rejected requests do not change state.
 * - Valid authorization + valid CSRF succeeds for each action.
 * - send_email is not queued/sent when CSRF validation fails.
 * - The development authorization rule (any authenticated user) works for reads
 *   and writes in the local development environment.
 */

$base = __DIR__ . '/..';

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
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

function assertContains(string $name, string $needle, string $haystack): void {
    global $passed, $failed;
    if (strpos($haystack, $needle) !== false) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name} (expected to find '{$needle}' in '{$haystack}')\n";
    }
}

function startServer(int &$port) {
    $base = __DIR__ . '/..';
    for ($p = 18150; $p < 18200; $p++) {
        $socket = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if ($socket === false) {
            $port = $p;
            break;
        }
        fclose($socket);
    }

    $stderrPath = sys_get_temp_dir() . '/dentatrak-server-' . $port . '.log';
    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', $stderrPath, 'w'],
    ];
    $proc = proc_open("php -S 127.0.0.1:{$port} -t " . escapeshellarg($base), $descriptors, $pipes);
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
    @unlink(sys_get_temp_dir() . '/dentatrak-server-' . $port . '.log');
}

function httpPost(string $url, array $body, string $cookieJar = ''): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $bodyStr = substr($response, $headerSize);
    curl_close($ch);
    return ['code' => $code, 'headers' => $headers, 'body' => $bodyStr];
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
    $headers = substr($response, 0, $headerSize);
    $bodyStr = substr($response, $headerSize);
    curl_close($ch);
    return ['code' => $code, 'headers' => $headers, 'body' => $bodyStr];
}

function extractCsrfToken(string $html): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)">/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function createCookieJar(): string {
    return tempnam(sys_get_temp_dir(), 'cookie');
}

function setupUser(string $baseUrl, string $email): array {
    $setup = httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'setup_test_user',
        'email' => $email,
        'password' => 'TestPass123!',
        'firstName' => 'CSRF',
        'lastName' => 'Test',
        'practiceName' => 'CSRF Test Practice'
    ]);
    $setupData = json_decode($setup['body'], true);
    if ($setup['code'] !== 200 || ($setupData['success'] ?? false) !== true) {
        echo "Setup failed: HTTP {$setup['code']} - {$setup['body']}\n";
        exit(1);
    }
    return ['practice_id' => (int)$setupData['practice_id'], 'user_id' => (int)$setupData['user_id']];
}

function loginUser(string $baseUrl, string $email, string $cookieJar): array {
    $login = httpPost("{$baseUrl}/api/auth-email.php", [
        'action' => 'login',
        'email' => $email,
        'password' => 'TestPass123!'
    ], $cookieJar);
    $loginData = json_decode($login['body'], true);
    if ($login['code'] !== 200 || ($loginData['success'] ?? false) !== true) {
        echo "Login failed: HTTP {$login['code']} - {$login['body']}\n";
        exit(1);
    }
    return ['cookie' => $cookieJar, 'data' => $loginData];
}

function getAdminCsrf(string $baseUrl, string $cookie): string {
    $adminPage = httpGet("{$baseUrl}/admin-practices.php", $cookie);
    if ($adminPage['code'] !== 200) {
        echo "Failed to fetch admin page: HTTP {$adminPage['code']}\n";
        exit(1);
    }
    $csrfToken = extractCsrfToken($adminPage['body']);
    if (!$csrfToken) {
        echo "CSRF token not found\n";
        exit(1);
    }
    return $csrfToken;
}

function cleanupUser(string $baseUrl, string $email): void {
    httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'cleanup_test_user',
        'email' => $email
    ]);
}

function lastAppEmail(string $baseUrl): ?array {
    $r = httpPost("{$baseUrl}/api/test-helpers.php", ['action' => 'get_last_app_email']);
    $data = json_decode($r['body'], true);
    return $data['email'] ?? null;
}

function clearEmailLog(string $baseUrl): void {
    httpPost("{$baseUrl}/api/test-helpers.php", ['action' => 'clear_test_email_log']);
}

function isPracticeActive(string $baseUrl, string $cookie, int $practiceId): bool {
    $r = httpGet("{$baseUrl}/api/admin-practices.php?action=list", $cookie);
    $data = json_decode($r['body'], true);
    if (empty($data['practices'])) {
        return false;
    }
    foreach ($data['practices'] as $p) {
        if ((int)$p['id'] === $practiceId) {
            return ($p['is_active'] ?? 0) == 1;
        }
    }
    return false;
}

function isPracticeHidden(string $baseUrl, string $cookie, int $practiceId): bool {
    $r = httpGet("{$baseUrl}/api/admin-practices.php?action=list", $cookie);
    $data = json_decode($r['body'], true);
    if (empty($data['practices'])) {
        return false;
    }
    foreach ($data['practices'] as $p) {
        if ((int)$p['id'] === $practiceId) {
            return !empty($p['is_hidden']);
        }
    }
    return false;
}

$port = 0;
$proc = startServer($port);
if (!$proc) {
    echo "Failed to start PHP development server\n";
    exit(1);
}

$baseUrl = "http://127.0.0.1:{$port}";
$email = 'dtcsrftest-' . uniqid() . '@example.com';

try {
    // ---------------------------------------------------------------------------
    // Authentication always required
    // ---------------------------------------------------------------------------
    $r = httpGet("{$baseUrl}/api/admin-practices.php?action=list");
    assertEquals('unauthenticated GET list returns 401', 401, $r['code']);

    $r2 = httpPost("{$baseUrl}/api/admin-practices.php?action=deactivate", [
        'practice_id' => 1,
        'reason' => 'test'
    ]);
    assertEquals('unauthenticated POST deactivate returns 401', 401, $r2['code']);

    // ---------------------------------------------------------------------------
    // Setup and development authorization
    // ---------------------------------------------------------------------------
    $setupResult = setupUser($baseUrl, $email);
    $practiceId = $setupResult['practice_id'];
    $userId = $setupResult['user_id'];
    $cookieJar = createCookieJar();
    $login = loginUser($baseUrl, $email, $cookieJar);
    $cookie = $login['cookie'];

    // Authenticated non-super user can read in development.
    $list = httpGet("{$baseUrl}/api/admin-practices.php?action=list", $cookie);
    assertEquals('authenticated GET list in development returns 200', 200, $list['code']);
    assertTrue('development user can read practice list', !empty(json_decode($list['body'], true)['practices']));

    $csrf = getAdminCsrf($baseUrl, $cookie);

    // ---------------------------------------------------------------------------
    // Missing and invalid CSRF for every state-changing action
    // ---------------------------------------------------------------------------
    $actions = [
        ['action' => 'deactivate', 'body' => ['practice_id' => $practiceId, 'reason' => 'test']],
        ['action' => 'reactivate', 'body' => ['practice_id' => $practiceId]],
        ['action' => 'hide', 'body' => ['practice_id' => $practiceId]],
        ['action' => 'unhide', 'body' => ['practice_id' => $practiceId]],
        ['action' => 'delete', 'body' => ['practice_id' => $practiceId, 'confirm' => true]],
        ['action' => 'send_email', 'body' => ['practice_id' => $practiceId, 'user_id' => 1, 'email_type' => 'trial_reminder']],
        ['action' => 'extend_trial', 'body' => ['practice_id' => $practiceId, 'extension_months' => 1, 'send_email' => false]],
    ];

    foreach ($actions as $a) {
        $missing = httpPost("{$baseUrl}/api/admin-practices.php?action=" . $a['action'], $a['body'], $cookie);
        assertEquals("missing CSRF for {$a['action']} returns 403", 403, $missing['code'], $missing['body']);
        assertContains("missing CSRF for {$a['action']} returns safe message", 'CSRF', $missing['body']);

        $invalid = httpPost("{$baseUrl}/api/admin-practices.php?action=" . $a['action'], array_merge($a['body'], ['csrf_token' => 'invalid-token']), $cookie);
        assertEquals("invalid CSRF for {$a['action']} returns 403", 403, $invalid['code'], $invalid['body']);
    }

    // Rejected requests must not change state.
    assertTrue('rejected deactivate did not change practice state', isPracticeActive($baseUrl, $cookie, $practiceId), httpGet("{$baseUrl}/api/admin-practices.php?action=list", $cookie)['body']);

    clearEmailLog($baseUrl);
    $badEmail = httpPost("{$baseUrl}/api/admin-practices.php?action=send_email", [
        'practice_id' => $practiceId,
        'user_id' => 1,
        'email_type' => 'trial_reminder'
    ], $cookie);
    assertEquals('rejected send_email returns 403', 403, $badEmail['code']);
    $emailAfter = lastAppEmail($baseUrl);
    assertTrue('rejected send_email sent no email', empty($emailAfter), json_encode($emailAfter));

    // ---------------------------------------------------------------------------
    // Valid CSRF succeeds for each action
    // ---------------------------------------------------------------------------
    $deactivate = httpPost("{$baseUrl}/api/admin-practices.php?action=deactivate", [
        'practice_id' => $practiceId,
        'reason' => 'CSRF test',
        'csrf_token' => $csrf
    ], $cookie);
    assertEquals('deactivate with valid CSRF returns 200', 200, $deactivate['code'], $deactivate['body']);
    assertTrue('deactivate changed practice to inactive', !isPracticeActive($baseUrl, $cookie, $practiceId), $deactivate['body']);

    $reactivate = httpPost("{$baseUrl}/api/admin-practices.php?action=reactivate", [
        'practice_id' => $practiceId,
        'csrf_token' => $csrf
    ], $cookie);
    assertEquals('reactivate with valid CSRF returns 200', 200, $reactivate['code'], $reactivate['body']);
    assertTrue('reactivate restored practice to active', isPracticeActive($baseUrl, $cookie, $practiceId), $reactivate['body']);

    $hide = httpPost("{$baseUrl}/api/admin-practices.php?action=hide", [
        'practice_id' => $practiceId,
        'csrf_token' => $csrf
    ], $cookie);
    assertEquals('hide with valid CSRF returns 200', 200, $hide['code'], $hide['body']);
    assertTrue('hide set practice hidden', isPracticeHidden($baseUrl, $cookie, $practiceId), $hide['body']);

    $unhide = httpPost("{$baseUrl}/api/admin-practices.php?action=unhide", [
        'practice_id' => $practiceId,
        'csrf_token' => $csrf
    ], $cookie);
    assertEquals('unhide with valid CSRF returns 200', 200, $unhide['code'], $unhide['body']);
    assertTrue('unhide removed practice hidden flag', !isPracticeHidden($baseUrl, $cookie, $practiceId), $unhide['body']);

    // delete requires confirmation and retention; with valid CSRF it is at least
    // considered, but should be rejected for retention reasons.
    $delete = httpPost("{$baseUrl}/api/admin-practices.php?action=delete", [
        'practice_id' => $practiceId,
        'confirm' => true,
        'csrf_token' => $csrf
    ], $cookie);
    assertEquals('delete with valid CSRF is processed (retention rejects)', 400, $delete['code'], $delete['body']);
    assertContains('delete retention check runs', 'retained', $delete['body']);

    clearEmailLog($baseUrl);
    $sendEmail = httpPost("{$baseUrl}/api/admin-practices.php?action=send_email", [
        'practice_id' => $practiceId,
        'user_id' => $userId,
        'email_type' => 'getting_started',
        'csrf_token' => $csrf
    ], $cookie);
    assertEquals('send_email with valid CSRF returns 200', 200, $sendEmail['code'], $sendEmail['body']);
    $emailAfterSend = lastAppEmail($baseUrl);
    assertTrue('send_email with valid CSRF queued/sent email', !empty($emailAfterSend), $sendEmail['body']);

    $extend = httpPost("{$baseUrl}/api/admin-practices.php?action=extend_trial", [
        'practice_id' => $practiceId,
        'extension_months' => 1,
        'send_email' => false,
        'csrf_token' => $csrf
    ], $cookie);
    assertEquals('extend_trial with valid CSRF returns 200', 200, $extend['code'], $extend['body']);
    assertContains('extend_trial message mentions one practice', 'for CSRF Test Practice', $extend['body']);

    // Cleanup
    $cleanup = httpPost("{$baseUrl}/api/test-helpers.php", ['action' => 'cleanup_test_user', 'email' => $email]);
    $cleanupData = json_decode($cleanup['body'], true);
    assertTrue('cleanup succeeds', ($cleanupData['success'] ?? false) === true, $cleanup['body']);

} finally {
    stopServer($proc, $port);
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
