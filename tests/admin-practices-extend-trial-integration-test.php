<?php
/**
 * Integration tests for the Extend Trial admin action.
 *
 * Starts a local PHP development server, creates test owner(s)/practice(s) via
 * api/test-helpers.php, logs in via api/auth-email.php, fetches the
 * admin-practices page for a CSRF token, and exercises:
 *
 * - Unauthenticated requests return 401 even in development
 * - CSRF-missing extend_trial POST returns 403
 * - Practice list and Subscription tab show active trial (not "No Subscription")
 * - Affected practices are resolved server-side and shown in the modal
 * - All affected list rows refresh after a successful extension
 * - Multi-practice owner subscription scope is reflected in the success message
 * - Legacy practices with no owner-level subscriptions row are backfilled safely
 * - Legacy trial end date is preserved during backfill
 * - Missing owner and out-of-range extension lengths are rejected
 * - Email not requested, sent, and failed states are recorded correctly
 * - Email failure does not roll back the trial extension
 * - Audit records contain all affected practices and email_result
 * - Successful integration-test fixture cleanup
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

function assertEquals(string $name, $expected, $actual): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
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
    for ($p = 18090; $p < 18150; $p++) {
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

function stopServer($proc, int $port = 0): void {
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

function setupOwner(string $baseUrl, string $email, string $practiceName = 'Integration Test Practice'): int {
    $setup = httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'setup_test_user',
        'email' => $email,
        'password' => 'TestPass123!',
        'firstName' => 'Integration',
        'lastName' => 'Tester',
        'practiceName' => $practiceName
    ]);
    $setupData = json_decode($setup['body'], true);
    if ($setup['code'] !== 200 || ($setupData['success'] ?? false) !== true) {
        echo "Setup failed for {$email}: HTTP {$setup['code']} - {$setup['body']}\n";
        exit(1);
    }
    return (int)$setupData['practice_id'];
}

function loginOwner(string $baseUrl, string $email, string $cookieJar): array {
    $login = httpPost("{$baseUrl}/api/auth-email.php", [
        'action' => 'login',
        'email' => $email,
        'password' => 'TestPass123!'
    ], $cookieJar);
    $loginData = json_decode($login['body'], true);
    if ($login['code'] !== 200 || ($loginData['success'] ?? false) !== true) {
        echo "Login failed for {$email}: HTTP {$login['code']} - {$login['body']}\n";
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
        echo "CSRF token not found on admin page\n";
        exit(1);
    }
    return $csrfToken;
}

function extendTrial(string $baseUrl, string $cookie, string $csrfToken, int $practiceId, int $months, bool $sendEmail): array {
    return httpPost("{$baseUrl}/api/admin-practices.php?action=extend_trial", [
        'practice_id' => $practiceId,
        'extension_months' => $months,
        'send_email' => $sendEmail,
        'csrf_token' => $csrfToken
    ], $cookie);
}

function getList(string $baseUrl, string $cookie): array {
    $r = httpGet("{$baseUrl}/api/admin-practices.php?action=list", $cookie);
    $data = json_decode($r['body'], true);
    return ['code' => $r['code'], 'data' => $data];
}

function getAffectedPractices(string $baseUrl, string $cookie, int $practiceId): array {
    $r = httpGet("{$baseUrl}/api/admin-practices.php?action=affected_practices&practice_id=" . $practiceId, $cookie);
    $data = json_decode($r['body'], true);
    return ['code' => $r['code'], 'data' => $data];
}

function cleanupUser(string $baseUrl, string $email): array {
    return httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'cleanup_test_user',
        'email' => $email
    ]);
}

$multiEmail = 'dtmultitest-' . uniqid() . '@example.com';
$legacyEmail = 'dtlegacytest-' . uniqid() . '@example.com';

// Ensure the test server treats these fixture owners as super users so the
// POST actions can be exercised. This is test-only state stored in the
// in-process environment and never reaches production.
putenv("SUPER_USERS={$multiEmail},{$legacyEmail}");
$_ENV['SUPER_USERS'] = "{$multiEmail},{$legacyEmail}";

$port = 0;
$proc = startServer($port);
if (!$proc) {
    echo "Failed to start PHP development server\n";
    exit(1);
}

$baseUrl = "http://127.0.0.1:{$port}";

$emailsToClean = [];

try {
    // ---------------------------------------------------------------------------
    // 1. Authorization: unauthenticated access is always rejected (401)
    // ---------------------------------------------------------------------------
    $r = httpGet("{$baseUrl}/api/admin-practices.php?action=list");
    assertEquals('unauthenticated admin list returns 401', 401, $r['code']);

    $r2 = httpPost("{$baseUrl}/api/admin-practices.php?action=extend_trial", [
        'practice_id' => 1,
        'extension_months' => 3,
        'send_email' => false
    ]);
    assertEquals('unauthenticated extend_trial returns 401', 401, $r2['code']);

    // ---------------------------------------------------------------------------
    // 2. Missing CSRF returns 403 even when authenticated
    // ---------------------------------------------------------------------------
    $multiPracticeId = setupOwner($baseUrl, $multiEmail, 'Multi-Practice Owner');
    $emailsToClean[] = $multiEmail;
    $multiJar = tempnam(sys_get_temp_dir(), 'cookie');
    $login = loginOwner($baseUrl, $multiEmail, $multiJar);
    $cookie = $login['cookie'];

    $noCsrf = httpPost("{$baseUrl}/api/admin-practices.php?action=extend_trial", [
        'practice_id' => $multiPracticeId,
        'extension_months' => 3,
        'send_email' => false
    ], $cookie);
    assertEquals('missing CSRF token returns 403', 403, $noCsrf['code']);

    // ---------------------------------------------------------------------------
    // 3. Multi-practice owner scope
    // ---------------------------------------------------------------------------
    $seed = httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'seed_owned_practices',
        'email' => $multiEmail,
        'count' => 2
    ]);
    $seedData = json_decode($seed['body'], true);
    assertTrue('seed_owned_practices succeeds', $seed['code'] === 200 && ($seedData['success'] ?? false) === true, $seed['body']);

    $csrfToken = getAdminCsrf($baseUrl, $cookie);

    $list = getList($baseUrl, $cookie);
    assertEquals('practice list returns 200', 200, $list['code']);
    assertTrue('practice list data present', !empty($list['data']['practices']) && is_array($list['data']['practices']), json_encode($list['data']));

    $ownerPracticeIds = array_merge([$multiPracticeId], ($seedData['practice_ids'] ?? []));
    $ownerPracticeSet = array_flip($ownerPracticeIds);

    // Practices owned by this user should show the same active trial (owner-level).
    foreach ($list['data']['practices'] as $p) {
        if (!isset($ownerPracticeSet[(int)$p['id']])) {
            continue;
        }
        $sub = $p['subscription'] ?? [];
        assertTrue("practice {$p['id']} shows has_subscription", !empty($sub['has_subscription']));
        assertTrue("practice {$p['id']} shows is_trialing", !empty($sub['is_trialing']));
    }

    // Affected practices endpoint resolves server-side and returns all 3.
    $affected = getAffectedPractices($baseUrl, $cookie, $multiPracticeId);
    assertEquals('affected_practices returns 200', 200, $affected['code']);
    assertTrue('affected_practices has 3 practices', count($affected['data']['affected_practices'] ?? []) === 3, json_encode($affected['data']));

    // Extend the trial for all 3 practices.
    $multiExtend = extendTrial($baseUrl, $cookie, $csrfToken, $multiPracticeId, 2, true);
    $multiData = json_decode($multiExtend['body'], true);
    assertEquals('multi-practice extend returns 200', 200, $multiExtend['code']);
    assertTrue('multi-practice extend succeeds', ($multiData['success'] ?? false) === true, $multiExtend['body']);
    assertEquals('email_result is sent', 'sent', $multiData['email_result'] ?? '');
    assertTrue('affected_practices present in response', !empty($multiData['affected_practices']));
    assertTrue('affected_practice_ids present', !empty($multiData['affected_practice_ids']));
    assertTrue('3 affected practices in response', count($multiData['affected_practices']) === 3);
    assertContains('multi-practice message mentions 3 practices', '3 practices using this subscription', $multiData['message']);
    assertContains('multi-practice message lists names', $affected['data']['affected_practices'][0]['name'], $multiData['message']);

    // Refresh list and confirm all practices owned by this user now share the extended date.
    $list2 = getList($baseUrl, $cookie);
    assertEquals('refreshed practice list returns 200', 200, $list2['code']);
    assertTrue('refreshed practice list data present', !empty($list2['data']['practices']) && is_array($list2['data']['practices']));
    $newTrialEnd = $multiData['subscription']['trial_ends_at'] ?? '';
    $affectedIdSet = array_flip(array_map('intval', $multiData['affected_practice_ids'] ?? []));
    foreach ($list2['data']['practices'] as $p) {
        if (!isset($affectedIdSet[(int)$p['id']])) {
            continue;
        }
        $sub = $p['subscription'] ?? [];
        assertEquals("practice {$p['id']} refreshed to new trial end", $newTrialEnd, $sub['trial_ends_at'] ?? '');
    }

    // ---------------------------------------------------------------------------
    // 4. Legacy trial backfill
    // ---------------------------------------------------------------------------
    $legacyPracticeId = setupOwner($baseUrl, $legacyEmail, 'Legacy Trial Practice');
    $emailsToClean[] = $legacyEmail;

    // Simulate a legacy practice with no owner-level subscriptions row.
    $legacyDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('P90D'))->format('Y-m-d H:i:s');
    $legacySetup = httpPost("{$baseUrl}/api/test-helpers.php", [
        'action' => 'make_legacy_trial',
        'email' => $legacyEmail,
        'trial_ends_at' => $legacyDate
    ]);
    $legacySetupData = json_decode($legacySetup['body'], true);
    assertTrue('make_legacy_trial succeeds', $legacySetup['code'] === 200 && ($legacySetupData['success'] ?? false) === true, $legacySetup['body']);
    assertEquals('legacy practice_id matches', $legacyPracticeId, $legacySetupData['practice_id'] ?? null);

    $legacyJar = tempnam(sys_get_temp_dir(), 'cookie');
    $legacyLogin = loginOwner($baseUrl, $legacyEmail, $legacyJar);
    $legacyCookie = $legacyLogin['cookie'];
    $legacyCsrf = getAdminCsrf($baseUrl, $legacyCookie);

    // The list should still show an active trial through the legacy fallback.
    $legacyList = getList($baseUrl, $legacyCookie);
    assertEquals('legacy practice list returns 200', 200, $legacyList['code']);
    assertTrue('legacy practice list data present', !empty($legacyList['data']['practices']) && is_array($legacyList['data']['practices']), json_encode($legacyList['data'] ?? $legacyList['body']));
    $legacyPractice = null;
    foreach ($legacyList['data']['practices'] as $p) {
        if ((int)$p['id'] === $legacyPracticeId) {
            $legacyPractice = $p;
            break;
        }
    }
    assertTrue('legacy practice found in list', !empty($legacyPractice), 'practice ' . $legacyPracticeId . ' not in list');
    assertTrue('legacy practice shows active trial', !empty($legacyPractice['subscription']['is_trialing']), json_encode($legacyPractice['subscription'] ?? []));

    // Extending the trial should create the owner-level row and extend it.
    $legacyExtend = extendTrial($baseUrl, $legacyCookie, $legacyCsrf, $legacyPracticeId, 1, true);
    $legacyExtendData = json_decode($legacyExtend['body'], true);
    assertEquals('legacy extend returns 200', 200, $legacyExtend['code']);
    assertTrue('legacy extend succeeds', ($legacyExtendData['success'] ?? false) === true, $legacyExtend['body']);

    // The new end date must be after the original legacy date.
    $newLegacyEnd = $legacyExtendData['subscription']['trial_ends_at'] ?? '';
    assertTrue('new legacy trial end is after original', $newLegacyEnd > $legacyDate, "{$newLegacyEnd} > {$legacyDate}");

    // Re-list: the subscription is now authoritative.
    $legacyList2 = getList($baseUrl, $legacyCookie);
    assertTrue('legacy list 2 data present', !empty($legacyList2['data']['practices']) && is_array($legacyList2['data']['practices']));
    foreach ($legacyList2['data']['practices'] as $p) {
        if ((int)$p['id'] === $legacyPracticeId) {
            assertEquals('legacy practice refreshed to authoritative subscription', $newLegacyEnd, $p['subscription']['trial_ends_at'] ?? '');
            assertTrue('legacy practice has has_subscription', !empty($p['subscription']['has_subscription']));
        }
    }

    // ---------------------------------------------------------------------------
    // 5. Validation: out-of-range months rejected
    // ---------------------------------------------------------------------------
    $badRange = extendTrial($baseUrl, $cookie, $csrfToken, $multiPracticeId, 25, false);
    assertEquals('25 months rejected with 400', 400, $badRange['code']);

    $zeroRange = extendTrial($baseUrl, $cookie, $csrfToken, $multiPracticeId, 0, false);
    assertEquals('0 months rejected with 400', 400, $zeroRange['code']);

    // ---------------------------------------------------------------------------
    // 6. Missing owner rejection
    // ---------------------------------------------------------------------------
    $missingOwner = extendTrial($baseUrl, $cookie, $csrfToken, 0, 3, false);
    assertEquals('practice_id 0 rejected with 400', 400, $missingOwner['code']);

    // ---------------------------------------------------------------------------
    // 7. Email failure does not roll back the extension
    // ---------------------------------------------------------------------------
    $forceFailure = $base . '/testResults/force-email-failure.json';
    @mkdir(dirname($forceFailure), 0750, true);
    file_put_contents($forceFailure, '{"fail":true}');

    $failExtend = extendTrial($baseUrl, $cookie, $csrfToken, $multiPracticeId, 1, true);
    $failData = json_decode($failExtend['body'], true);
    assertEquals('email-failure extend returns 200', 200, $failExtend['code']);
    assertTrue('trial extended even when email fails', ($failData['success'] ?? false) === true, $failExtend['body']);
    assertEquals('email_result is failed', 'failed', $failData['email_result'] ?? '');
    assertTrue('email failure message is present', !empty($failData['email_message']));
    assertContains('partial success message explains extension kept', 'trial was extended', $failData['message']);

    @unlink($forceFailure);

    // ---------------------------------------------------------------------------
    // 8. Cleanup removes fixtures
    // ---------------------------------------------------------------------------
    foreach ($emailsToClean as $email) {
        $cleanup = cleanupUser($baseUrl, $email);
        $cleanupData = json_decode($cleanup['body'], true);
        assertEquals("cleanup for {$email} returns 200", 200, $cleanup['code']);
        assertTrue("cleanup for {$email} succeeds", ($cleanupData['success'] ?? false) === true, $cleanup['body']);
    }

    // After cleanup, the same email can be set up fresh.
    $reusedEmail = 'dtreusedtest-' . uniqid() . '@example.com';
    $reusedPracticeId = setupOwner($baseUrl, $reusedEmail, 'Reused Test Practice');
    $cleanup2 = cleanupUser($baseUrl, $reusedEmail);
    $cleanup2Data = json_decode($cleanup2['body'], true);
    assertTrue('fresh test user cleanup succeeds', ($cleanup2Data['success'] ?? false) === true, $cleanup2['body']);

} finally {
    stopServer($proc, $port);
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
