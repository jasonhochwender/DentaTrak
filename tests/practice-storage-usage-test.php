<?php
/**
 * Practice storage usage computation tests (DevTools reporting).
 *
 * Exercises computePracticeStorageTotals() and refreshPracticeStorageUsage()
 * against the local development database (127.0.0.1:3308) using fictional
 * fixture practices, attachment metadata, comment images, scanner files
 * (local driver), and a practice logo.
 *
 * Covers: category sums, dedup of shared storage paths, archived-case
 * inclusion, retained scan revisions, deleted/missing objects, unmeasured
 * entries, empty practices, cached-row persistence, duplicate-refresh
 * prevention, and failure-state preservation.
 *
 * CLI only: php tests/practice-storage-usage-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-storage-usage.php';

global $pdo, $appConfig;

if (($appConfig['current_environment'] ?? '') !== 'development') {
    die("SKIP: requires development environment\n");
}
if (!$pdo) {
    die("FAIL: no database connection\n");
}

$passed = 0;
$failed = 0;
function assertEq(string $name, $expected, $actual): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}
function assertTrue(string $name, bool $cond, string $ctx = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS: {$name}\n"; }
    else { $failed++; echo "FAIL: {$name}" . ($ctx ? " ({$ctx})" : '') . "\n"; }
}

$tag = 'storaget_' . substr(uniqid(), -6);
$pidA = 0; $pidB = 0;
$createdFiles = [];
$userId = null;

// Fixture users need a real users.id for practices.created_by FK.
$userStmt = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1");
$userId = (int)$userStmt->fetchColumn();
if (!$userId) {
    die("FAIL: no users table rows for fixture\n");
}

function mkLocalObject(int $pid, string $rel, int $bytes, string $fill = 'x'): string {
    // $rel is a cases/...-style storage path; writes under uploads/scan-objects.
    global $createdFiles;
    $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
         . 'scan-objects' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    @mkdir(dirname($abs), 0777, true);
    file_put_contents($abs, str_repeat($fill, $bytes));
    $createdFiles[] = $abs;
    return $rel;
}

function mkLogo(int $pid, int $bytes): string {
    global $createdFiles;
    $rel = "uploads/logos/storaget_logo_{$pid}.png";
    $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    file_put_contents($abs, str_repeat('i', $bytes));
    $createdFiles[] = $abs;
    return $rel;
}

try {
    // ---- Fixture practice A: populated ----------------------------------
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, logo_path) VALUES (?, ?, ?, '')")
        ->execute([$tag . '_a', $tag . ' Practice A', $userId]);
    $pidA = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE practices SET logo_path = ? WHERE id = ?")
        ->execute([mkLogo($pidA, 2048), $pidA]);

    // Case attachments: image (2 KB), scan stl (4 KB), other pdf (1 KB), plus
    // one archived case whose bytes must still count.
    $caseId1 = $tag . '_case1';
    $caseId2 = $tag . '_case2';
    $att1 = json_encode([
        ['id' => 'a1', 'type' => 'photos', 'fileName' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'size' => 2048, 'storagePath' => "cases/$pidA/$caseId1/photos/u1-photo.jpg", 'storageType' => 'gcs'],
        ['id' => 'a2', 'type' => 'completedDesigns', 'fileName' => 'design.pdf', 'mimeType' => 'application/pdf', 'size' => 1024, 'storagePath' => "cases/$pidA/$caseId1/completedDesigns/u2-design.pdf", 'storageType' => 'gcs'],
        ['id' => 'a3', 'type' => 'intraoralScans', 'fileName' => 'mesh.stl', 'mimeType' => 'model/stl', 'size' => 4096, 'storagePath' => "cases/$pidA/$caseId1/intraoralScans/u3-mesh.stl", 'storageType' => 'gcs'],
        // Attachment with NO recorded size - must be stat'ed (local mirror).
        ['id' => 'a4', 'type' => 'photos', 'fileName' => 'nostat.png', 'mimeType' => 'image/png', 'storagePath' => mkLocalObject($pidA, "cases/$pidA/$caseId1/photos/u4-nostat.png", 512), 'storageType' => 'local'],
        // Attachment with no size AND no stat-able object -> unmeasured.
        ['id' => 'a5', 'type' => 'photos', 'fileName' => 'gone.png', 'mimeType' => 'image/png', 'storagePath' => "cases/$pidA/$caseId1/photos/u5-gone.png", 'storageType' => 'local'],
    ]);
    $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, attachments_json, archived) VALUES (?, ?, ?, 0)")
        ->execute([$caseId1, $pidA, $att1]);

    // Archived case: 3 KB image still counts.
    $att2 = json_encode([
        ['id' => 'b1', 'type' => 'photos', 'fileName' => 'arch.jpg', 'mimeType' => 'image/jpeg', 'size' => 3072, 'storagePath' => "cases/$pidA/$caseId2/photos/u6-arch.jpg", 'storageType' => 'gcs'],
    ]);
    $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, attachments_json, archived) VALUES (?, ?, ?, 1)")
        ->execute([$caseId2, $pidA, $att2]);

    // Comment image: 1.5 KB image.
    $cmtAtt = json_encode([
        ['fileName' => 'note.png', 'fileType' => 'image/png', 'size' => 1536, 'storagePath' => "cases/$pidA/$caseId1/comments/u7-note.png", 'storageType' => 'gcs'],
    ]);
    $pdo->prepare("INSERT INTO case_comments (case_id, practice_id, user_id, user_name, user_email, comment_text, attachments_json) VALUES (?, ?, ?, 'T U', 't@t.t', 'x', ?)")
        ->execute([$caseId1, $pidA, $userId, $cmtAtt]);

    // Scanner inbox: two files - one retained revision, one attached to case
    // (its storage_path is duplicated inside the case's attachments_json ->
    // dedup check), plus one row whose object was deleted.
    $pdo->prepare("INSERT INTO scanner_incoming_scans (practice_id, vendor, external_scan_id, dedup_key, status) VALUES (?, 'medit', ?, ?, 'linked')")
        ->execute([$pidA, $tag . '_scan1', hash('sha256', $tag . 'scan1')]);
    $scanId = (int)$pdo->lastInsertId();

    $revPath = mkLocalObject($pidA, "cases/$pidA/pending_scan_9001/intraoralScans/rev1.stl", 1024);
    $attachedPath = mkLocalObject($pidA, "cases/$pidA/$caseId1/intraoralScans/attached.stl", 2048);

    // Retained superseded revision (still stored).
    $pdo->prepare("INSERT INTO scanner_scan_files (scan_id, external_file_id, file_name, revision, declared_size, storage_driver, storage_path, status) VALUES (?, 'ef1', 'rev1.stl', 'r1', 999, 'local', ?, 'superseded')")
        ->execute([$scanId, $revPath]);
    // Attached file - shared reference with attachments_json below.
    $pdo->prepare("INSERT INTO scanner_scan_files (scan_id, external_file_id, file_name, revision, declared_size, storage_driver, storage_path, status, attached_case_id) VALUES (?, 'ef2', 'attached.stl', 'r2', 999, 'local', ?, 'attached', ?)")
        ->execute([$scanId, $attachedPath, $caseId1]);
    // Dangling row: object deleted from storage -> excluded + partial note.
    $pdo->prepare("INSERT INTO scanner_scan_files (scan_id, external_file_id, file_name, revision, declared_size, storage_driver, storage_path, status) VALUES (?, 'ef3', 'gone.stl', 'r1', 5000, 'local', ?, 'ready')")
        ->execute([$scanId, "cases/$pidA/pending_scan_9002/intraoralScans/gone.stl"]);
    // Not-yet-ingested row (no storage_path) -> skipped.
    $pdo->prepare("INSERT INTO scanner_scan_files (scan_id, external_file_id, file_name, declared_size, status) VALUES (?, 'ef4', 'pending.stl', 7777, 'pending')")
        ->execute([$scanId]);

    // attachments_json referencing the SAME object as the attached scan file
    // (with a bogus declared size to prove the stat is authoritative).
    $pdo->prepare("UPDATE cases_cache SET attachments_json = ? WHERE case_id = ?")
        ->execute([json_encode([
            ['id' => 'a1', 'type' => 'photos', 'fileName' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'size' => 2048, 'storagePath' => "cases/$pidA/$caseId1/photos/u1-photo.jpg", 'storageType' => 'gcs'],
            ['id' => 'a2', 'type' => 'completedDesigns', 'fileName' => 'design.pdf', 'mimeType' => 'application/pdf', 'size' => 1024, 'storagePath' => "cases/$pidA/$caseId1/completedDesigns/u2-design.pdf", 'storageType' => 'gcs'],
            ['id' => 'a3', 'type' => 'intraoralScans', 'fileName' => 'mesh.stl', 'mimeType' => 'model/stl', 'size' => 4096, 'storagePath' => "cases/$pidA/$caseId1/intraoralScans/u3-mesh.stl", 'storageType' => 'gcs'],
            ['id' => 'a4', 'type' => 'photos', 'fileName' => 'nostat.png', 'mimeType' => 'image/png', 'storagePath' => "cases/$pidA/$caseId1/photos/u4-nostat.png", 'storageType' => 'local'],
            ['id' => 'a5', 'type' => 'photos', 'fileName' => 'gone.png', 'mimeType' => 'image/png', 'storagePath' => "cases/$pidA/$caseId1/photos/u5-gone.png", 'storageType' => 'local'],
            // Dedup target: same storagePath as scanner file ef2.
            ['id' => 'a6', 'type' => 'intraoralScans', 'fileName' => 'attached.stl', 'mimeType' => 'model/stl', 'size' => 777777, 'storagePath' => $attachedPath, 'storageType' => 'local', 'source' => 'scanner'],
        ]), $caseId1]);

    // ---- Fixture practice B: empty ---------------------------------------
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by) VALUES (?, ?, ?)")
        ->execute([$tag . '_b', $tag . ' Practice B', $userId]);
    $pidB = (int)$pdo->lastInsertId();

    // ============================ Assertions ==============================
    $t = computePracticeStorageTotals($pdo, $pidA);

    // images: 2048 (photo) + 512 (stat'ed nostat) + 3072 (archived) + 1536 (comment)
    assertEq('image bytes', 2048 + 512 + 3072 + 1536, $t['image_bytes']);
    assertEq('image files', 4, $t['image_files']);
    // scans: 4096 (mesh) + 1024 (superseded rev) + 2048 (attached, stat not declared)
    assertEq('scan bytes', 4096 + 1024 + 2048, $t['scan_bytes']);
    assertEq('scan files', 3, $t['scan_files']);
    assertEq('other bytes', 1024, $t['other_bytes']);
    assertEq('other files', 1, $t['other_files']);
    assertEq('branding bytes', 2048, $t['branding_bytes']);
    assertEq('branding files', 1, $t['branding_files']);
    assertEq('unmeasured (no-size missing-object attachment)', 1, $t['unmeasured_files']);
    assertTrue('scanner_objects_missing note', in_array('scanner_objects_missing', $t['notes'], true));
    assertTrue('unmeasured_attachments note', in_array('unmeasured_attachments', $t['notes'], true));
    assertEq('total', $t['image_bytes'] + $t['scan_bytes'] + $t['other_bytes'] + $t['branding_bytes'], $t['total_bytes']);

    // Empty practice -> genuine zero, not "unknown".
    $tB = computePracticeStorageTotals($pdo, $pidB);
    assertEq('empty practice total', 0, $tB['total_bytes']);
    assertEq('empty practice unmeasured', 0, $tB['unmeasured_files']);
    assertEq('empty practice notes', [], $tB['notes']);

    // Refresh: persists a cached row, then a second claim is blocked.
    $r1 = refreshPracticeStorageUsage($pdo, $pidA);
    assertTrue('refresh ok', !empty($r1['refreshed']));
    $cached = getPracticeStorageUsage($pdo, $pidA);
    assertEq('cached total matches compute', $t['total_bytes'], (int)$cached['total_bytes']);
    assertTrue('calculated_at set', !empty($cached['calculated_at']));
    assertTrue('last_error cleared', empty($cached['last_error']));

    // Force a running claim and confirm refresh is refused and data preserved.
    $pdo->prepare("UPDATE practice_storage_usage SET status='running', claimed_at=NOW() WHERE practice_id=?")->execute([$pidA]);
    $r2 = refreshPracticeStorageUsage($pdo, $pidA);
    assertTrue('duplicate refresh refused', empty($r2['refreshed']) && !empty($r2['in_progress']));
    assertEq('preserved totals during in-progress', $t['total_bytes'], $r2['usage']['total_bytes']);
    $pdo->prepare("UPDATE practice_storage_usage SET status='idle', claimed_at=NULL WHERE practice_id=?")->execute([$pidA]);

    // Failure path: record an error, verify payload keeps last totals + error.
    $pdo->prepare("UPDATE practice_storage_usage SET last_error='calculation_failed' WHERE practice_id=?")->execute([$pidA]);
    $payload = practiceStorageUsagePayload(getPracticeStorageUsage($pdo, $pidA));
    assertEq('payload preserves totals on error', $t['total_bytes'], $payload['total_bytes']);
    assertTrue('payload carries error', $payload['last_error'] === 'calculation_failed');
    assertTrue('payload marked partial (notes present)', $payload['partial'] === true);
    assertEq('payload category - scan files', 3, $payload['categories']['scan']['files']);

    // No PHI / identifiers in payload.
    $json = json_encode($payload);
    assertTrue('no case ids in payload', strpos($json, $caseId1) === false && strpos($json, 'storagePath') === false && strpos($json, 'fileName') === false);

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed ? 1 : 0);
} finally {
    // Cleanup fixtures (files + rows).
    if ($pidA) {
        $pdo->prepare("DELETE FROM scanner_scan_files WHERE scan_id IN (SELECT id FROM scanner_incoming_scans WHERE practice_id=?)")->execute([$pidA]);
        $pdo->prepare("DELETE FROM scanner_incoming_scans WHERE practice_id=?")->execute([$pidA]);
        $pdo->prepare("DELETE FROM case_comments WHERE practice_id=?")->execute([$pidA]);
        $pdo->prepare("DELETE FROM cases_cache WHERE practice_id=?")->execute([$pidA]);
        $pdo->prepare("DELETE FROM practice_storage_usage WHERE practice_id=?")->execute([$pidA]);
        $pdo->prepare("DELETE FROM practices WHERE id=?")->execute([$pidA]);
    }
    if ($pidB) {
        $pdo->prepare("DELETE FROM practice_storage_usage WHERE practice_id=?")->execute([$pidB]);
        $pdo->prepare("DELETE FROM practices WHERE id=?")->execute([$pidB]);
    }
    foreach ($createdFiles as $f) {
        @unlink($f);
    }
    // Remove empty fixture dirs.
    if ($pidA) {
        $dir = dirname(__DIR__) . '/uploads/scan-objects/cases/' . $pidA;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($dir);
        }
    }
}
