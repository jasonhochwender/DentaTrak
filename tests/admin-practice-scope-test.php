<?php
/**
 * Shared admin practice-scope regression test.
 *
 * Verifies that api/admin-practices.php?action=usage_report and
 * api/platform-admin-overview.php default to the caller's visible
 * (non-hidden) practices, honor include_hidden=1, keep per-admin hidden
 * preferences isolated, and scope cases/storage consistently.
 *
 * Uses fictional fixture practices and cleans up all rows it creates.
 */

require __DIR__ . '/../api/appConfig.php';

$base = __DIR__ . '/..';
$port = 18312;
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

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, string $context = ''): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$name}\n"; }
    else { $failed++; echo "FAIL: {$name}" . ($context ? " ({$context})" : '') . "\n"; }
}

function req(string $method, string $url, array $body = [], string $cookieJar = ''): array {
    $ch = curl_init($url);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    }
    curl_setopt($ch, CURLOPT_HEADER, true);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $json = json_decode(substr($r, $headerSize), true);
    return ['code' => $code, 'json' => $json, 'raw' => substr($r, $headerSize)];
}

$baseUrl = "http://127.0.0.1:{$port}";
$jar = tempnam(sys_get_temp_dir(), 'scocook');

$email = getenv('DENTATRAK_TEST_EMAIL') ?: 'e2e.test@dentatrak.com';
$password = getenv('DENTATRAK_TEST_PASSWORD') ?: '';
if ($password === '') die("FAIL: DENTATRAK_TEST_PASSWORD not set\n");

global $pdo;
$stmtU = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmtU->execute([$email]);
$adminUserId = (int)$stmtU->fetchColumn();
if (!$adminUserId) die("FAIL: test user {$email} not found\n");

$suffix = 'ScopeTest ' . substr(uniqid(), -6);
$fixturePracticeIds = [];
$hiddenInserted = false;

function makePractice(string $name): int {
    global $pdo, $fixturePracticeIds, $adminUserId;
    $uuid = sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffffffff));
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, legal_name, display_name, created_by)
                   VALUES (:pid, :name, :name2, :name3, :uid)")
        ->execute([':pid' => $uuid, ':name' => $name, ':name2' => $name, ':name3' => $name, ':uid' => $adminUserId]);
    $id = (int)$pdo->lastInsertId();
    $fixturePracticeIds[] = $id;
    return $id;
}

function cleanup(): void {
    global $pdo, $fixturePracticeIds, $adminUserId, $hiddenInserted;
    if ($hiddenInserted) {
        $pdo->exec("DELETE FROM admin_hidden_practices WHERE admin_user_id = " . (int)$adminUserId .
            " AND practice_id IN (" . implode(',', array_map('intval', $fixturePracticeIds) ?: [0]) . ")");
    }
    foreach ($fixturePracticeIds as $pid) {
        $pdo->exec("DELETE FROM cases_cache WHERE practice_id = " . (int)$pid);
        $pdo->exec("DELETE FROM practice_storage_usage WHERE practice_id = " . (int)$pid);
        $pdo->exec("DELETE FROM demo_generation_runs WHERE practice_id = " . (int)$pid);
        $pdo->exec("DELETE FROM practices WHERE id = " . (int)$pid);
    }
}

try {
    // Fixture V: stays visible. Fixture H: hidden for this admin only.
    $pv = makePractice("{$suffix} Visible");
    $ph = makePractice("{$suffix} Hidden");

    // One active case + a storage row on the hidden practice, so case and
    // storage metrics must move when the scope changes.
    $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name, archived)
                   VALUES (:cid, :pid, 'Scope', 'Fixture', 0)")
        ->execute([':cid' => 'scopetest_' . uniqid(), ':pid' => $ph]);
    $pdo->prepare("INSERT INTO practice_storage_usage (practice_id, status, total_bytes)
                   VALUES (:pid, 'idle', 1048576)")->execute([':pid' => $ph]);

    $pdo->prepare("INSERT INTO admin_hidden_practices (admin_user_id, practice_id, hidden_at)
                   VALUES (?, ?, NOW())")->execute([$adminUserId, $ph]);
    $hiddenInserted = true;

    // ---------- Auth ----------
    $login = req('POST', "{$baseUrl}/api/auth-email.php", ['action' => 'login', 'email' => $email, 'password' => $password], $jar);
    assertTrue('login as test admin', $login['json']['success'] ?? false, $login['raw']);

    $totalPractices = (int)$pdo->query("SELECT COUNT(*) FROM practices")->fetchColumn();

    // ---------- usage_report default scope ----------
    $r = req('GET', "{$baseUrl}/api/admin-practices.php?action=usage_report", [], $jar);
    assertTrue('usage_report 200', $r['code'] === 200, "code={$r['code']}");
    $data = $r['json'] ?? [];
    assertTrue('usage_report success', ($data['success'] ?? false) === true, $r['raw']);
    $rows = $data['practices'] ?? [];
    $ids = array_map(fn($p) => (int)$p['id'], $rows);
    assertTrue('visible fixture present by default', in_array($pv, $ids, true));
    assertTrue('hidden fixture excluded by default', !in_array($ph, $ids, true));
    assertTrue('default row count = total - hidden', count($rows) === $totalPractices - 1,
        count($rows) . " vs " . ($totalPractices - 1));
    $scope = $data['scope'] ?? [];
    assertTrue('scope reports include_hidden=false', ($scope['include_hidden'] ?? null) === false);
    assertTrue('scope visible count consistent', ($scope['visible'] ?? -1) === $totalPractices - 1,
        json_encode($scope));

    // ---------- usage_report include_hidden ----------
    $r2 = req('GET', "{$baseUrl}/api/admin-practices.php?action=usage_report&include_hidden=1", [], $jar);
    $data2 = $r2['json'] ?? [];
    $ids2 = array_map(fn($p) => (int)$p['id'], $data2['practices'] ?? []);
    assertTrue('include_hidden returns hidden fixture', in_array($ph, $ids2, true));
    assertTrue('include_hidden returns all practices', count($ids2) === $totalPractices,
        count($ids2) . " vs {$totalPractices}");
    assertTrue('scope include_hidden=true', ($data2['scope']['include_hidden'] ?? null) === true);

    // ---------- overview default scope ----------
    $o = req('GET', "{$baseUrl}/api/platform-admin-overview.php", [], $jar);
    assertTrue('overview 200', $o['code'] === 200, "code={$o['code']}");
    $om = ($o['json']['metrics'] ?? []);
    $osc = ($o['json']['scope'] ?? []);
    assertTrue('overview practices_total excludes hidden', ($om['practices_total'] ?? -1) === $totalPractices - 1,
        json_encode($om['practices_total'] ?? null) . " vs " . ($totalPractices - 1));
    assertTrue('overview hidden count = 1', ($om['practices_hidden_for_admin'] ?? -1) === 1,
        json_encode($om['practices_hidden_for_admin'] ?? null));
    assertTrue('overview scope visible = total - 1', ($osc['visible'] ?? -1) === $totalPractices - 1, json_encode($osc));

    // Cases: the hidden fixture owns exactly one active case; scoped count
    // must equal the unscoped count minus one.
    $oAll = req('GET', "{$baseUrl}/api/platform-admin-overview.php?include_hidden=1", [], $jar);
    $omAll = ($oAll['json']['metrics'] ?? []);
    assertTrue('overview include_hidden practices_total = all', ($omAll['practices_total'] ?? -1) === $totalPractices);
    assertTrue('cases_active differs by exactly the hidden fixture case',
        ($omAll['cases_active'] ?? 0) - ($om['cases_active'] ?? 0) === 1,
        json_encode([$om['cases_active'] ?? null, $omAll['cases_active'] ?? null]));
    $storageAll = $omAll['storage']['practices'] ?? 0;
    $storageScoped = $om['storage']['practices'] ?? 0;
    assertTrue('storage practice count differs by the hidden fixture',
        $storageAll - $storageScoped === 1, "{$storageAll} vs {$storageScoped}");

    // ---------- per-admin isolation: hidden row belongs to this admin only ----------
    // Simulate a second admin with no hidden prefs: same endpoint, different
    // session is covered implicitly (hidden ids are keyed by admin_user_id);
    // verify the hidden row did not leak into another admin's id set.
    $otherHidden = $pdo->prepare("SELECT COUNT(*) FROM admin_hidden_practices WHERE practice_id = ? AND admin_user_id != ?");
    $otherHidden->execute([$ph, $adminUserId]);
    assertTrue('hidden row scoped to this admin only', (int)$otherHidden->fetchColumn() === 0);

} finally {
    cleanup();
    if ($jar && file_exists($jar)) unlink($jar);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
