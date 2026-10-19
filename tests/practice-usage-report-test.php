<?php
/**
 * Practice Usage Report API tests (api/admin-practices.php?action=usage_report).
 *
 * Seeds fictional fixture practices/cases/storage rows, exercises the report
 * endpoint over the local dev server, verifies totals, per-practice counts,
 * demo flags, storage statuses, and authorization, then cleans up.
 */

require __DIR__ . '/../api/appConfig.php';

$base = __DIR__ . '/..';
$port = 18311;
$docRoot = realpath($base);

$serverCmd = "php -S 127.0.0.1:{$port} -t \"{$docRoot}\"";
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($serverCmd, $descriptors, $pipes);
if (!$process) {
    die("FAIL: Could not start dev server\n");
}
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
    curl_close($ch);
    $responseBody = substr($r, $headerSize);
    $json = json_decode($responseBody, true);
    return ['code' => $code, 'json' => $json, 'raw' => $responseBody];
}

$baseUrl = "http://127.0.0.1:{$port}";
$jar = tempnam(sys_get_temp_dir(), 'urcook');

$email = getenv('DENTATRAK_TEST_EMAIL') ?: 'e2e_test_browser2@dentatrak.local';
$password = getenv('DENTATRAK_TEST_PASSWORD') ?: 'DentaTrak_1q2w3e4r';

// ---------- Fixture setup (fictional) ----------
global $pdo;
$stmtU = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmtU->execute([$email]);
$ownerUserId = (int)$stmtU->fetchColumn();
if (!$ownerUserId) die("FAIL: test user {$email} not found\n");

$suffix = 'UR Test ' . substr(uniqid(), -6);
$fixturePracticeIds = [];

function makePractice(string $name): int {
    global $pdo, $fixturePracticeIds, $ownerUserId;
    $uuid = sprintf('%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffffffffffff));
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, legal_name, display_name, created_by)
                   VALUES (:pid, :name, :name2, :name3, :uid)")
        ->execute([':pid' => $uuid, ':name' => $name, ':name2' => $name, ':name3' => $name, ':uid' => $ownerUserId]);
    $id = (int)$pdo->lastInsertId();
    $fixturePracticeIds[] = $id;
    return $id;
}

// cases_cache has no soft-delete column - deleted cases are physically
// removed (delete-all-cases.php runs DELETE FROM). "Total cases" is therefore
// every stored row, split by the archived flag.
function addCases(int $practiceId, int $active, int $archived, ?int $demoRunId = null): void {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name, archived, demo_generation_run_id)
                           VALUES (:cid, :pid, 'Fixture', 'Patient', :arch, :run)");
    $mk = function (int $arch) use ($pdo, $stmt, $practiceId, $demoRunId) {
        $cid = 'urtest_' . uniqid();
        $stmt->execute([':cid' => $cid, ':pid' => $practiceId, ':arch' => $arch, ':run' => $demoRunId]);
    };
    for ($i = 0; $i < $active; $i++) $mk(0);
    for ($i = 0; $i < $archived; $i++) $mk(1);
}

try {
    // Practice A: measured storage + demo run + mixed cases
    $pa = makePractice("{$suffix} A Measured");
    $pdo->prepare("INSERT INTO demo_generation_runs (practice_id, dataset_size, created_by_user_id, status)
                   VALUES (:pid, 'small', :uid, 'completed')")->execute([':pid' => $pa, ':uid' => $ownerUserId]);
    $demoRunId = (int)$pdo->lastInsertId();
    addCases($pa, 3, 2, $demoRunId); // 3 active, 2 archived
    $pdo->prepare("INSERT INTO practice_storage_usage (practice_id, status, calculated_at, total_bytes, unmeasured_files)
                   VALUES (:pid, 'idle', '2025-06-15 10:30:00', 1073741824, 0)")->execute([':pid' => $pa]);

    // Practice B: partial storage, one case
    $pb = makePractice("{$suffix} B Partial");
    addCases($pb, 1, 0);
    $pdo->prepare("INSERT INTO practice_storage_usage (practice_id, status, calculated_at, total_bytes, unmeasured_files)
                   VALUES (:pid, 'idle', '2025-06-14 09:00:00', 536870912, 4)")->execute([':pid' => $pb]);

    // Practice C: refresh failed
    $pc = makePractice("{$suffix} C Error");
    $pdo->prepare("INSERT INTO practice_storage_usage (practice_id, status, last_error)
                   VALUES (:pid, 'idle', 'synthetic failure')")->execute([':pid' => $pc]);

    // Practice D: uncalculated + zero usage
    $pd = makePractice("{$suffix} D Empty");

    // ---------- Auth ----------
    $unauth = req('GET', "{$baseUrl}/api/admin-practices.php?action=usage_report");
    assertTrue('unauthenticated request returns 401', $unauth['code'] === 401, "code={$unauth['code']}");

    $login = req('POST', "{$baseUrl}/api/auth-email.php", ['action' => 'login', 'email' => $email, 'password' => $password], $jar);
    assertTrue('login as devtools user', $login['json']['success'] ?? false, $login['raw']);

    $r = req('GET', "{$baseUrl}/api/admin-practices.php?action=usage_report", [], $jar);
    assertTrue('usage_report returns 200', $r['code'] === 200, "code={$r['code']} {$r['raw']}");
    $data = $r['json'];
    assertTrue('response success', ($data['success'] ?? false) === true, $r['raw']);

    $practices = $data['practices'] ?? [];
    $byId = [];
    foreach ($practices as $p) $byId[$p['id']] = $p;

    // ---------- Totals (aggregated client-side over the included set) ----------
    $dbPracticeCount = (int)$pdo->query("SELECT COUNT(*) FROM practices")->fetchColumn();
    assertTrue('every DB practice appears in report', count($practices) === $dbPracticeCount, count($practices) . " != {$dbPracticeCount}");

    // Cases whose practice_id points at a deleted practice are orphaned
    // (no FK enforcement); they cannot be attributed to any practice row.
    $dbActive = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache c JOIN practices p ON p.id = c.practice_id WHERE c.archived = 0")->fetchColumn();
    $dbArchived = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache c JOIN practices p ON p.id = c.practice_id WHERE c.archived = 1")->fetchColumn();

    $sumActive = 0; $sumArchived = 0; $rowConsistency = true;
    $measured = 0; $partial = 0; $unmeasured = 0;
    foreach ($practices as $p) {
        $sumActive += $p['active_cases'];
        $sumArchived += $p['archived_cases'];
        if ($p['total_cases'] !== $p['active_cases'] + $p['archived_cases']) $rowConsistency = false;
        if (in_array($p['storage']['status'], ['measured', 'partial'], true)) $measured++;
        else $unmeasured++;
    }
    assertTrue('sum of per-practice active equals DB active', $sumActive === $dbActive, "{$sumActive} != {$dbActive}");
    assertTrue('sum of per-practice archived equals DB archived', $sumArchived === $dbArchived, "{$sumArchived} != {$dbArchived}");
    assertTrue('every row total = active + archived', $rowConsistency);
    assertTrue('storage coverage is a subset of practices', $measured <= count($practices) && $measured + $unmeasured === count($practices));

    // ---------- Fixture A: counts + demo + measured storage ----------
    $a = $byId[$pa] ?? null;
    assertTrue('fixture A present', $a !== null);
    if ($a) {
        assertTrue('A active=3', $a['active_cases'] === 3, json_encode($a));
        assertTrue('A archived=2', $a['archived_cases'] === 2);
        assertTrue('A total=5 (active+archived)', $a['total_cases'] === 5);
        assertTrue('A flagged demo', $a['is_demo'] === true);
        assertTrue('A demo_cases=5', $a['demo_cases'] === 5, json_encode($a));
        assertTrue('A storage measured', $a['storage']['status'] === 'measured');
        assertTrue('A storage bytes', $a['storage']['total_bytes'] === 1073741824);
        assertTrue('A storage calculated_at', $a['storage']['calculated_at'] === '2025-06-15 10:30:00');
        assertTrue('A storage unmeasured_files=0', $a['storage']['unmeasured_files'] === 0);
        assertTrue('A storage complete flag', $a['storage']['complete'] === true);
    }

    // ---------- Fixture B: partial storage ----------
    $b = $byId[$pb] ?? null;
    assertTrue('fixture B present', $b !== null);
    if ($b) {
        assertTrue('B storage partial', $b['storage']['status'] === 'partial');
        assertTrue('B storage bytes', $b['storage']['total_bytes'] === 536870912);
        assertTrue('B unmeasured_files=4', $b['storage']['unmeasured_files'] === 4);
        assertTrue('B not flagged demo', $b['is_demo'] === false);
    }

    // ---------- Fixture C: error status ----------
    $c = $byId[$pc] ?? null;
    assertTrue('fixture C present', $c !== null);
    if ($c) {
        assertTrue('C storage error', $c['storage']['status'] === 'error');
        assertTrue('C storage not complete', $c['storage']['complete'] === false);
        assertTrue('C total_bytes null (unknown not zero)', $c['storage']['total_bytes'] === null);
    }

    // ---------- Fixture D: zero usage, uncalculated ----------
    $d = $byId[$pd] ?? null;
    assertTrue('fixture D present (zero-usage practice included)', $d !== null);
    if ($d) {
        assertTrue('D all counts zero', $d['total_cases'] === 0 && $d['active_cases'] === 0 && $d['archived_cases'] === 0);
        assertTrue('D storage uncalculated', $d['storage']['status'] === 'uncalculated');
        assertTrue('D storage bytes null', $d['storage']['total_bytes'] === null);
    }

    // ---------- Row shape ----------
    $first = $practices[0] ?? [];
    foreach (['id', 'name', 'is_demo', 'active_cases', 'archived_cases', 'total_cases', 'demo_cases', 'storage'] as $k) {
        assertTrue("row has key {$k}", array_key_exists($k, $first), json_encode($first));
    }
    assertTrue('generated_at present', !empty($data['generated_at']));

} finally {
    // ---------- Cleanup ----------
    if (!empty($fixturePracticeIds)) {
        $in = implode(',', array_map('intval', $fixturePracticeIds));
        $pdo->exec("DELETE FROM cases_cache WHERE practice_id IN ({$in})");
        $pdo->exec("DELETE FROM demo_generation_runs WHERE practice_id IN ({$in})");
        $pdo->exec("DELETE FROM practice_storage_usage WHERE practice_id IN ({$in})");
        $pdo->exec("DELETE FROM practices WHERE id IN ({$in})");
    }
    if ($jar && file_exists($jar)) @unlink($jar);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
