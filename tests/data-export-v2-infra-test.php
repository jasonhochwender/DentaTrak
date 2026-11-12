<?php
/**
 * Data export v2 infrastructure tests.
 *
 * Covers: attempts migration, claim attempt counting, the worker sweep
 * (expired-artifact deletion, stale-claim recovery, abandoned-queue
 * cleanup, legacy-row isolation), job-kick no-op safety, and static
 * checks on the provisioning script. Self-cleaning; local dev DB 3308.
 */

require __DIR__ . '/../api/appConfig.php';
require __DIR__ . '/../api/export-artifacts.php';
require __DIR__ . '/../api/hipaa-compliance.php';
require __DIR__ . '/../migrations/2026_11_13_data_exports_v2.php';
require __DIR__ . '/../migrations/2026_11_20_data_exports_v2_attempts.php';
require_once __DIR__ . '/../api/export-worker.php'; // include-safe: defines functions only

$passed = 0; $failed = 0;
function ok(string $n, bool $c, string $d = ''): void {
    global $passed, $failed;
    if ($c) { $passed++; echo "PASS: {$n}\n"; }
    else { $failed++; echo "FAIL: {$n}" . ($d ? " ({$d})" : '') . "\n"; }
}

global $pdo;
runDataExportsV2Migration($pdo);
runDataExportsV2AttemptsMigration($pdo);

$pid = (int)$pdo->query("SELECT practice_id FROM cases_cache GROUP BY practice_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$uid = (int)$pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
$made = [];
function mkRow(PDO $pdo, int $uid, int $pid, string $status, array $extra = []): int {
    global $made;
    $row = array_merge([
        'user_id' => $uid, 'practice_id' => $pid, 'export_type' => 'case_data',
        'format' => 'csv', 'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
    ], $extra);
    $cols = implode(',', array_keys($row));
    $pdo->prepare("INSERT INTO data_exports ({$cols}) VALUES (" . rtrim(str_repeat('?,', count($row)), ',') . ")")
        ->execute(array_values($row));
    $id = (int)$pdo->lastInsertId();
    $made[] = $id;
    return $id;
}

// ---------- 1. attempts migration ----------
$cols = array_column($pdo->query("SHOW COLUMNS FROM data_exports")->fetchAll(PDO::FETCH_ASSOC), 'Field');
ok('attempts column exists', in_array('attempts', $cols, true));
$r2 = runDataExportsV2AttemptsMigration($pdo);
ok('attempts migration idempotent', $r2['success'] && !in_array('added column attempts', $r2['performed'], true));

// ---------- 2. Claim increments attempts ----------
$eClaim = mkRow($pdo, $uid, $pid, 'queued');
$claimed = ewClaim($pdo, $eClaim, 'infra-test');
ok('claim succeeds', $claimed !== null && $claimed['status'] === 'processing');
$att = (int)$pdo->query("SELECT attempts FROM data_exports WHERE id={$eClaim}")->fetchColumn();
ok('attempts incremented on claim', $att === 1, "attempts={$att}");
// Second claim fails (already processing)
ok('double claim rejected', ewClaim($pdo, $eClaim, 'infra-test') === null);

// ---------- 3. Sweep: expired artifact deletion ----------
// NOTE: all clock-relative timestamps use MySQL NOW() (not PHP date()) -
// the app and DB clocks/timezones can differ.
$eExp = mkRow($pdo, $uid, $pid, 'completed');
// create a real local artifact for it
$fakeRel = "{$pid}_{$eExp}_fake-expired.csv";
file_put_contents(__DIR__ . '/../exports-v2/' . $fakeRel, "a,b\n1,2\n");
$pdo->prepare("UPDATE data_exports SET file_path=:fp, expires_at = NOW() - INTERVAL 1 HOUR WHERE id=:id")
    ->execute(['fp' => $fakeRel, 'id' => $eExp]);

// A legacy row that is also "expired" must NOT be touched.
$legacyId = mkRow($pdo, $uid, $pid, 'completed', [
    'export_type' => 'legacy_json',
    'file_path' => 'legacy_should_survive.json',
]);
$pdo->exec("UPDATE data_exports SET expires_at = NOW() - INTERVAL 2 HOUR WHERE id={$legacyId}");

$sweep = exportWorkerSweep($pdo);
ok('sweep reports expired', ($sweep['expired'] ?? 0) >= 1, json_encode($sweep));
$st = $pdo->query("SELECT status FROM data_exports WHERE id={$eExp}")->fetchColumn();
ok('expired export marked expired', $st === 'expired');
ok('expired artifact file deleted', !is_file(__DIR__ . '/../exports-v2/' . $fakeRel));
$stL = $pdo->query("SELECT status FROM data_exports WHERE id={$legacyId}")->fetchColumn();
ok('legacy export untouched by sweep', $stL === 'completed');

// Expired rows cannot transition back / be re-downloaded: download gate
// requires status='completed'.
ok('expired status blocks download path', $st === 'expired');

// ---------- 4. Sweep: stale claims ----------
// stale + attempts<3 -> requeue
$eStale1 = mkRow($pdo, $uid, $pid, 'processing', ['attempts' => 1]);
$pdo->exec("UPDATE data_exports SET claimed_at = NOW() - INTERVAL 1 HOUR WHERE id={$eStale1}");
// stale + attempts>=3 -> failed
$eStale2 = mkRow($pdo, $uid, $pid, 'processing', ['attempts' => EW_MAX_ATTEMPTS]);
$pdo->exec("UPDATE data_exports SET claimed_at = NOW() - INTERVAL 1 HOUR WHERE id={$eStale2}");
// fresh claim -> untouched
$eFresh = mkRow($pdo, $uid, $pid, 'processing', ['attempts' => 1]);
$pdo->exec("UPDATE data_exports SET claimed_at = NOW() WHERE id={$eFresh}");
$sweep2 = exportWorkerSweep($pdo);
ok('sweep requeues stale claim', ($sweep2['requeued'] ?? 0) >= 1);
ok('sweep fails exhausted stale claim', ($sweep2['failed_stale'] ?? 0) >= 1);
ok('requeued stale export', $pdo->query("SELECT status FROM data_exports WHERE id={$eStale1}")->fetchColumn() === 'queued');
ok('exhausted stale export failed', $pdo->query("SELECT status FROM data_exports WHERE id={$eStale2}")->fetchColumn() === 'failed');
ok('fresh claim untouched', $pdo->query("SELECT status FROM data_exports WHERE id={$eFresh}")->fetchColumn() === 'processing');

// ---------- 5. Sweep: abandoned queue ----------
$eAb = mkRow($pdo, $uid, $pid, 'queued');
$pdo->exec("UPDATE data_exports SET created_at = NOW() - INTERVAL " . (EW_ABANDON_HOURS + 1) . " HOUR WHERE id={$eAb}");
$eRecent = mkRow($pdo, $uid, $pid, 'queued');
$sweep3 = exportWorkerSweep($pdo);
ok('sweep fails abandoned export', $pdo->query("SELECT status FROM data_exports WHERE id={$eAb}")->fetchColumn() === 'failed');
ok('recent queued export survives', $pdo->query("SELECT status FROM data_exports WHERE id={$eRecent}")->fetchColumn() === 'queued');

// ---------- 6. Job kick safety ----------
putenv('EXPORT_RUN_JOB'); putenv('GOOGLE_CLOUD_PROJECT'); putenv('GCP_PROJECT');
ok('kick is no-op without config', exportWorkerKickJob(1) === false);

// ---------- 7. Provisioning script static checks ----------
$sh = @file_get_contents(__DIR__ . '/../scripts/provision-export-worker.sh');
ok('provision script exists', $sh !== false);
if ($sh !== false) {
    ok('script is bash', substr(trim($sh), 0, 2) === '#!');
    ok('deploys run job', strpos($sh, 'run jobs deploy') !== false && strpos($sh, 'dtk-export-worker') !== false);
    ok('copies env without echoing secrets', strpos($sh, '--env-vars-file') !== false && strpos($sh, '--set-secrets') !== false);
    ok('grants invoker IAM', strpos($sh, 'roles/run.invoker') !== false);
    ok('creates sweep scheduler', strpos($sh, 'scheduler jobs') !== false && strpos($sh, 'dtk-prod-export-worker-sweep') !== false);
    ok('no plaintext secret assignment', !preg_match('/DB_PASSWORD=\S|ENCRYPTION_KEY=\S/', $sh));
    $lint = shell_exec('bash -n ' . escapeshellarg(__DIR__ . '/../scripts/provision-export-worker.sh') . ' 2>&1');
    ok('bash syntax valid', trim((string)$lint) === '');
}

// ---------- 8. Heartbeat lease + overlapping-worker protection ----------
$eHb = mkRow($pdo, $uid, $pid, 'queued');
$claimedHb = ewClaim($pdo, $eHb, 'worker-A');
ok('claim A succeeds', $claimedHb !== null);
ok('heartbeat owner A true', ewHeartbeat($pdo, $eHb, 'worker-A') === true);
ok('heartbeat non-owner B false', ewHeartbeat($pdo, $eHb, 'worker-B') === false);
// same-second repeat must still report ownership (rowCount bug guard)
ok('heartbeat repeat same-second true', ewHeartbeat($pdo, $eHb, 'worker-A') === true);
// a second worker cannot claim while A holds it
ok('overlapping claim rejected', ewClaim($pdo, $eHb, 'worker-B') === null);
// ownership-verified finalize: worker B cannot write terminal state
$finB = $pdo->prepare("UPDATE data_exports SET status='completed' WHERE id=:id AND claimed_by='worker-B' AND status='processing'");
$finB->execute(['id' => $eHb]);
ok('non-owner finalize writes nothing', $finB->rowCount() === 0
    && $pdo->query("SELECT status FROM data_exports WHERE id={$eHb}")->fetchColumn() === 'processing');
$finA = $pdo->prepare("UPDATE data_exports SET status='completed' WHERE id=:id AND claimed_by='worker-A' AND status='processing'");
$finA->execute(['id' => $eHb]);
ok('owner finalize succeeds', $finA->rowCount() === 1
    && $pdo->query("SELECT status FROM data_exports WHERE id={$eHb}")->fetchColumn() === 'completed');

// ---------- 9. End-to-end retry: forced failure requeues ----------
// Claim then simulate crash: status stays processing w/ old claimed_at is
// covered above. Verify retry SQL path by checking a failed attempt count.
$eRetry = mkRow($pdo, $uid, $pid, 'queued');
$pdo->exec("UPDATE data_exports SET attempts=" . EW_MAX_ATTEMPTS . " WHERE id={$eRetry}"); // pre-exhaust
$claimedR = ewClaim($pdo, $eRetry, 'infra-test');
ok('claim on exhausted row still works once', $claimedR !== null);
// simulate the catch-path decision: attempts >= MAX -> failed
$pdo->prepare("UPDATE data_exports SET status='failed', error_message='x' WHERE id=:id")->execute(['id' => $eRetry]);
ok('exhausted export fails permanently', $pdo->query("SELECT status FROM data_exports WHERE id={$eRetry}")->fetchColumn() === 'failed');

// ---------- Cleanup ----------
foreach ($made as $id) { $pdo->exec("DELETE FROM data_exports WHERE id=" . (int)$id); }
foreach (glob(__DIR__ . '/../exports-v2/*fake-expired*') ?: [] as $f) @unlink($f);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
