<?php
/**
 * Complete Practice Archive tests.
 *
 * Builds a real ZIP via exportWorkerProcessArchive against self-cleaning
 * fixtures (case + local attachment + scanner file + missing file), then
 * validates structure, checksums, manifest, section coverage, secret
 * exclusion, and practice isolation. Local dev DB 3308.
 */

require __DIR__ . '/../api/appConfig.php';
require __DIR__ . '/../api/export-artifacts.php';
require __DIR__ . '/../api/hipaa-compliance.php';
require __DIR__ . '/../api/encryption.php';
require __DIR__ . '/../api/integrations/scanners/ScanFileStore.php';
require __DIR__ . '/../migrations/2026_11_13_data_exports_v2.php';
require __DIR__ . '/../migrations/2026_11_20_data_exports_v2_attempts.php';
require_once __DIR__ . '/../api/export-worker.php';

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
$otherPid = (int)$pdo->query("SELECT practice_id FROM cases_cache WHERE practice_id <> {$pid} LIMIT 1")->fetchColumn();

$cleanup = ['cases' => [], 'files' => [], 'exports' => [], 'scans' => []];
$uniq = 't' . substr(uniqid(), -6);
$caseId = 'archtest_' . $uniq;
$scanId = null; $scanFileId = null; $exportId = null;

// ---------- Fixtures ----------
$attContent = 'ARCH-ATTACHMENT-' . $uniq . str_repeat('a', 2000);
$attSha = hash('sha256', $attContent);
$attRel = "cases/{$pid}/{$caseId}/photos/pic.jpg";
$attAbs = __DIR__ . '/../uploads/scan-objects/' . $attRel;
@mkdir(dirname($attAbs), 0755, true);
file_put_contents($attAbs, $attContent);
$cleanup['files'][] = $attAbs;

$stlContent = 'ARCH-STL-' . $uniq . str_repeat('b', 3000);
$stlSha = hash('sha256', $stlContent);
$stlRel = "cases/{$pid}/pending_scan_{$uniq}/intraoralScans/s.stl";
$stlAbs = __DIR__ . '/../uploads/scan-objects/' . $stlRel;
@mkdir(dirname($stlAbs), 0755, true);
file_put_contents($stlAbs, $stlContent);
$cleanup['files'][] = $stlAbs;

$atts = json_encode([
    ['name' => 'pic.jpg', 'type' => 'photos', 'storagePath' => $attRel, 'storageType' => 'local', 'size' => strlen($attContent)],
    ['name' => 'ghost.pdf', 'type' => 'photos', 'storagePath' => "cases/{$pid}/{$caseId}/photos/ghost.pdf", 'storageType' => 'local', 'size' => 1],
]);
$pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name, patient_dob, status, archived, attachments_json, creation_date, last_update_date)
    VALUES (?,?,?,?,?,'originated',0,?,NOW(),NOW())")
    ->execute([$caseId, $pid, 'Arch', 'Test', '1990-01-01', $atts]);
$cleanup['cases'][] = $caseId;
// archived twin case (no attachments)
$archId = 'archtest_arch_' . $uniq;
$pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name, status, archived, archived_date, creation_date, last_update_date)
    VALUES (?,?,?,?, 'delivered',1,NOW(),NOW(),NOW())")
    ->execute([$archId, $pid, 'Arch', 'Archived']);
$cleanup['cases'][] = $archId;
// other-practice case must NOT appear
$pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name, status, creation_date, last_update_date)
    VALUES (?,?,?,?,'originated',NOW(),NOW())")
    ->execute(['archtest_other_' . $uniq, $otherPid, 'Other', 'Practice']);
$cleanup['cases'][] = 'archtest_other_' . $uniq;

$pdo->exec("INSERT INTO scanner_incoming_scans (practice_id,vendor,external_scan_id,status,dedup_key,created_at)
    VALUES ({$pid},'test','ext-{$uniq}','attached',MD5('{$uniq}'),NOW())");
$scanId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO scanner_scan_files (scan_id,external_file_id,file_name,content_type,declared_size,checksum_sha256,storage_driver,storage_path,status,created_at)
    VALUES (?,?,'s.stl','model/stl',?,?,'local',?,'stored',NOW())")
    ->execute([$scanId, 'f' . $uniq, strlen($stlContent), $stlSha, $stlRel]);
$scanFileId = (int)$pdo->lastInsertId();
$cleanup['scans'][] = $scanId;

// ---------- Run archive ----------
$pdo->exec("INSERT INTO data_exports (user_id,practice_id,export_type,format,status,created_at)
    VALUES ({$uid},{$pid},'practice_archive','zip','queued',NOW())");
$exportId = (int)$pdo->lastInsertId();
$cleanup['exports'][] = $exportId;

$export = $pdo->query("SELECT * FROM data_exports WHERE id={$exportId}")->fetch(PDO::FETCH_ASSOC);
$claimed = ewClaim($pdo, $exportId, 'archive-test');
ok('archive export claims', $claimed !== null);
$tmp = sys_get_temp_dir() . '/archtest-' . uniqid();
@mkdir($tmp, 0700, true);
$res = exportWorkerProcessArchive($pdo, $claimed, 'archive-test', $tmp);
ok('archive completes', ($res['status'] ?? '') === 'completed', json_encode($res));
ok('one missing-file warning', ($res['warnings'] ?? -1) === 1, json_encode($res['warnings'] ?? null));

$row = $pdo->query("SELECT * FROM data_exports WHERE id={$exportId}")->fetch(PDO::FETCH_ASSOC);
ok('db status completed', $row['status'] === 'completed');
ok('expires ~7d', $row['expires_at'] && strtotime($row['expires_at']) > time() + 6 * 86400);

// ---------- Validate ZIP ----------
$zipRel = $row['file_path'];
ok('local artifact recorded', $zipRel !== null && $zipRel !== '');
$zipAbs = __DIR__ . '/../exports-v2/' . basename($zipRel);
ok('zip file exists', is_file($zipAbs));
$cleanup['files'][] = $zipAbs;

$z = new ZipArchive();
ok('zip opens', $z->open($zipAbs) === true);
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);

foreach (['manifest.json','SHA256SUMS','README.txt','cases/cases.csv','cases/cases.json',
          'data/comments.csv','data/activity.csv','data/remakes.csv','data/phi_access_log.csv',
          'practice/settings.json','practice/members.csv','practice/subscription.json',
          'integrations/connections.json'] as $need) {
    ok("zip has {$need}", in_array($need, $names, true));
}
ok('attachment in zip', in_array("files/cases/{$caseId}/photos/pic.jpg", $names, true));
ok('scanner file in zip', count(array_filter($names, function($n){return strpos($n,'files/scanner/')===0;})) >= 1);

// SHA256SUMS verify every entry
$bad = 0; $n = 0;
foreach (explode("\n", trim($z->getFromName('SHA256SUMS'))) as $l) {
    if ($l === '') continue; $n++;
    [$h, $p] = preg_split('/\s+/', $l, 2);
    $c = $z->getFromName(trim($p));
    if ($c === false || hash('sha256', $c) !== $h) $bad++;
}
ok("all {$n} SHA256SUMS verify", $bad === 0);

// File bytes + checksum status
$attInZip = $z->getFromName("files/cases/{$caseId}/photos/pic.jpg");
ok('attachment bytes identical', $attInZip !== false && hash('sha256', $attInZip) === $attSha);
$stlEntry = null;
foreach ($names as $nm) if (strpos($nm, 'files/scanner/') === 0) $stlEntry = $nm;
$stlInZip = $stlEntry ? $z->getFromName($stlEntry) : false;
ok('scanner bytes identical + checksum match', $stlInZip !== false && hash('sha256', $stlInZip) === $stlSha);

// Manifest
$m = json_decode($z->getFromName('manifest.json'), true);
ok('manifest complete=false (missing file)', $m['complete'] === false);
ok('manifest records missing', $m['missingOrFailedCount'] === 1);
$statuses = array_column($m['files'], 'status', 'zipPath');
ok('missing file explicit', ($statuses["files/cases/{$caseId}/photos/ghost.pdf"] ?? '') === 'missing');
ok('cases count includes fixtures', ($m['exportInfo']['rowCounts']['cases'] ?? 0) >= 2);

// Secret exclusion
$membersCsv = $z->getFromName('practice/members.csv');
ok('members has no password_hash', strpos($membersCsv, 'password_hash') === false);
ok('members has role data', strpos($membersCsv, 'is_owner') !== false);
$settings = $z->getFromName('practice/settings.json');
ok('settings excludes secret-ish keys', !preg_match('/"(secret|token|password|private_key)[^"]*"\s*:/i', $settings));
$conn = $z->getFromName('integrations/connections.json');
ok('connections exclude config_json', strpos($conn, 'config_json') === false && strpos($conn, 'access_token') === false);
$casesJson = $z->getFromName('cases/cases.json');
ok('cases.json has decrypted PII', strpos($casesJson, 'Arch') !== false);
ok('cases.json excludes other practice', strpos($casesJson, 'archtest_other_' . $uniq) === false);
ok('cases.json includes archived case', strpos($casesJson, $archId) !== false);
$phiCsv = $z->getFromName('data/phi_access_log.csv');
ok('phi access log present', strpos($phiCsv, 'access_type') !== false || strlen($phiCsv) > 20);

// ---------- Cleanup ----------
foreach ($cleanup['exports'] as $id) $pdo->exec("DELETE FROM data_exports WHERE id=" . (int)$id);
foreach ($cleanup['cases'] as $cid) $pdo->exec("DELETE FROM cases_cache WHERE case_id=" . $pdo->quote($cid));
foreach ($cleanup['scans'] as $sid) {
    $pdo->exec("DELETE FROM scanner_scan_files WHERE scan_id=" . (int)$sid);
    $pdo->exec("DELETE FROM scanner_incoming_scans WHERE id=" . (int)$sid);
}
foreach ($cleanup['files'] as $f) @unlink($f);
@unlink(dirname($attAbs)); @unlink(dirname($stlAbs));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
