<?php
/**
 * Bulk Incoming Scans test: hundreds of synthetic rows, multiple vendor
 * keys, mixed statuses, combined filters, cross-page search, stable
 * pagination, partial-link visibility, counts, and the 5000-row cap.
 * Local dev DB (3308), fixtures cleaned up. php tests/scan-inbox-bulk-test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/encryption.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanInbox.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';

$pdo = new PDO('mysql:host=127.0.0.1;port=3308;dbname=dental_case_tracker;charset=utf8mb4', 'root', 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$passed = 0; $failed = 0;
function check($c, $l) { global $passed, $failed; $c ? $passed++ : $failed++; echo ($c ? "PASS" : "FAIL") . ": $l\n"; }

// fixture practice + user
$pdo->exec("INSERT INTO users (email, role, is_active, created_at) VALUES ('bulk-".bin2hex(random_bytes(4))."@t.invalid','user',1,NOW())");
$userId = (int)$pdo->lastInsertId();
$ext = 'bulk-' . bin2hex(random_bytes(4));
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at) VALUES ('$ext','Bulk Inbox Test',$userId,1,NOW())");
$practiceId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO practice_users (practice_id, user_id, role, is_owner, created_at) VALUES ($practiceId,$userId,'admin',1,NOW())");

$vendors = ['primescan','itero','trios','medit','simulated'];
$statuses = ['received','ready','failed','linked','dismissed'];
$ins = $pdo->prepare("INSERT INTO scanner_incoming_scans
    (practice_id, vendor, external_scan_id, dedup_key, status, patient_first_name, patient_last_name, scan_at, created_at)
    VALUES (:p,:v,:ext,:dk,:st,:fn,:ln,:sa,:ca)");
$insF = $pdo->prepare("INSERT INTO scanner_scan_files (scan_id, external_file_id, file_name, content_type, file_role, status, storage_driver, storage_path, checksum_sha256, attached_case_id, created_at)
    VALUES (:sid,:efi,:fn,:ct,:fr,:st,:sd,:sp,:sh,:cid,NOW())");

$seeded = 0; $needle = 'BulkNeedle-Zelda';
for ($i = 0; $i < 300; $i++) {
    $v = $vendors[$i % count($vendors)];
    $s = $statuses[$i % count($statuses)];
    $fn = $i === 42 ? PIIEncryption::encrypt($needle) : PIIEncryption::encrypt("Patient$i");
    $ins->execute([
        'p' => $practiceId, 'v' => $v, 'ext' => "SCAN-" . str_pad($i, 4, '0', STR_PAD_LEFT),
        'dk' => "dk-$i", 'st' => $s, 'fn' => $fn, 'ln' => PIIEncryption::encrypt("Last$i"),
        'sa' => date('Y-m-d H:i:s', strtotime('2026-01-01') + $i * 86400),
        'ca' => date('Y-m-d H:i:s', strtotime('2026-01-01') + $i * 86400),
    ]);
    $sid = (int)$pdo->lastInsertId();
    // one ready unlinked file on linked scans -> partial link for some
    $insF->execute([
        'sid' => $sid, 'efi' => "ef-$i", 'fn' => "UpperJaw.stl", 'fr' => 'upper',
        'ct' => 'application/octet-stream', 'st' => 'ready',
        'sd' => 'gcs', 'sp' => "test/$i.stl", 'sh' => str_repeat('a', 64),
        'cid' => ($s === 'linked' && $i % 2 === 0) ? null : ($s === 'linked' ? 'CASE-X' : null),
    ]);
    $seeded++;
}

function L($pdo, $pid, $uid, $o) { return ScanInbox::listFiltered($pdo, $pid, $uid, $o); }

// totals / views
$r = L($pdo, $practiceId, $userId, ['view' => 'all', 'per_page' => 100]);
check($r['total'] === 300, "all view sees 300 (got {$r['total']})");
check(count($r['scans']) === 100, 'per_page=100 honored');
$r = L($pdo, $practiceId, $userId, ['view' => 'attention', 'per_page' => 500]);
// attention = received/ready/failed (180) + linked-with-unlinked-ready (i%2==0 linked = 30)
check($r['total'] === 210, "attention = 180 unlinked + 30 partially linked (got {$r['total']})");
$r = L($pdo, $practiceId, $userId, ['view' => 'linked', 'per_page' => 500]);
check($r['total'] === 60, "linked view 60 (got {$r['total']})");

// pagination stability + ordering
$p1 = L($pdo, $practiceId, $userId, ['view' => 'all', 'page' => 1]);
$p2 = L($pdo, $practiceId, $userId, ['view' => 'all', 'page' => 2]);
$ids1 = array_column($p1['scans'], 'id'); $ids2 = array_column($p2['scans'], 'id');
check(count(array_intersect($ids1, $ids2)) === 0, 'pages do not overlap');
$dates = array_map(fn($s) => $s['scanAt'], $p1['scans']);
$sorted = $dates; rsort($sorted);
check($dates === $sorted, 'stable DESC ordering');

// search across pages (needle is row 42, ~page 2 region, encrypted name)
$r = L($pdo, $practiceId, $userId, ['view' => 'all', 'q' => 'zelda', 'per_page' => 500]);
check($r['total'] === 1 && $r['scans'][0]['patientFirstName'] === $needle, 'encrypted name search finds needle');
$r = L($pdo, $practiceId, $userId, ['view' => 'all', 'q' => 'SCAN-0299']);
check($r['total'] === 1, 'scan-id search works');

// combined filters
$r = L($pdo, $practiceId, $userId, ['view' => 'all', 'vendor' => 'itero', 'per_page' => 500]);
check($r['total'] === 60, "vendor filter (itero=60, got {$r['total']})");
$r = L($pdo, $practiceId, $userId, ['view' => 'dismissed', 'vendor' => 'simulated', 'per_page' => 500]);
check($r['total'] === 60, "view+vendor combined (simulated dismissed=60, got {$r['total']})");
$r = L($pdo, $practiceId, $userId, ['view' => 'all', 'date_from' => '2026-01-10', 'date_to' => '2026-01-20', 'per_page' => 500]);
check($r['total'] === 11, "date range (11 days, got {$r['total']})");

// vendor list for filter UI
$keys = array_column($r['vendors'], 'key');
sort($vendors);
check($keys === $vendors, 'vendor list = all 5 keys, sorted');
$psLabel = null;
foreach ($r['vendors'] as $v) { if ($v['key'] === 'primescan') $psLabel = $v['label']; }
check($psLabel === 'Primescan / DS Core', 'vendor label readable');

// partial-link flag
$r = L($pdo, $practiceId, $userId, ['view' => 'attention', 'per_page' => 500, 'q' => 'Patient']);
$pl = array_filter($r['scans'], fn($s) => $s['isPartiallyLinked']);
check(count($pl) === 30, 'partially-linked scans flagged (got ' . count($pl) . ')');

// --- truncation cap (5000): seed 5101 thin rows into a second practice ---
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at) VALUES ('$ext-2','Bulk Cap Test',$userId,1,NOW())");
$capPid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO practice_users (practice_id, user_id, role, is_owner, created_at) VALUES ($capPid,$userId,'admin',1,NOW())");
$vals = [];
for ($i = 0; $i < 5101; $i++) {
    $vals[] = "($capPid,'primescan','CAP-$i','cap-$i','ready',NULL,NULL,NOW(),NOW())";
}
foreach (array_chunk($vals, 500) as $chunk) {
    $pdo->exec("INSERT INTO scanner_incoming_scans (practice_id,vendor,external_scan_id,dedup_key,status,patient_first_name,patient_last_name,scan_at,created_at) VALUES " . implode(',', $chunk));
}
$r = L($pdo, $capPid, $userId, ['view' => 'all', 'per_page' => 25]);
check($r['truncated'] === true, 'cap flags truncated at >5000');
check($r['total'] === 5000, "cap reports bounded count (got {$r['total']})");

// cleanup
$pdo->exec("DELETE f FROM scanner_scan_files f JOIN scanner_incoming_scans s ON f.scan_id = s.id WHERE s.practice_id IN ($practiceId,$capPid)");
$pdo->exec("DELETE FROM scanner_incoming_scans WHERE practice_id IN ($practiceId,$capPid)");
$pdo->exec("DELETE FROM practice_users WHERE practice_id IN ($practiceId,$capPid)");
$pdo->exec("DELETE FROM practices WHERE id IN ($practiceId,$capPid)");
$pdo->exec("DELETE FROM users WHERE id = $userId");

echo "\n--- $passed passed, $failed failed ---\n";
exit($failed ? 1 : 0);
