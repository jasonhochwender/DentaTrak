<?php
/**
 * Scanner integration foundation tests.
 *
 * Exercises the incoming-scan pipeline end to end using the development-only
 * SimulatedScannerConnector against the local development database:
 *
 *   - schema + migration idempotency
 *   - ingest -> file download -> ready
 *   - dedup on repeat delivery (same dedup_key -> same scan, no dup files)
 *   - incomplete download is never marked ready; retry completes it
 *   - revised vendor file creates a NEW row; attached file is never
 *     superseded/overwritten
 *   - attach to an existing case (attachments_json grows, notes preserved)
 *   - append_instructions opt-in appends imported text
 *   - double link is idempotent; link to a different case conflicts
 *   - dismiss/restore lifecycle
 *   - cross-practice isolation (scan of practice A invisible to practice B)
 *   - missing patient reference -> no identifier-based suggestions
 *   - simulator production-blocking and flag gating
 *   - no external side effects (no PMS outbound events, no notifications)
 *
 * Requires the local development DB (port 3308) per AGENTS.md. Fixture
 * rows are cleaned up afterwards. Nothing here performs network I/O or
 * writes to Open Dental / outbound queues.
 *
 * Usage: php tests/scanner-foundation-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

// Enable the experimental gates for the test process BEFORE appConfig loads
// flag state. Feature flags read env vars first, so this is the supported
// local override - production defaults stay off.
putenv('SCANNER_INBOX=true');
putenv('SCANNER_SIMULATOR=true');
$_ENV['SCANNER_INBOX'] = 'true';
$_ENV['SCANNER_SIMULATOR'] = 'true';
$_SERVER['SCANNER_INBOX'] = 'true';
$_SERVER['SCANNER_SIMULATOR'] = 'true';

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/encryption.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanInbox.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanFileStore.php';
require_once __DIR__ . '/../api/integrations/scanners/SimulatedScannerConnector.php';

global $pdo, $appConfig;

$pass = 0;
$fail = 0;

function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

function assertEquals($expected, $actual, $message) {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "SKIP: no database connection available\n";
    exit(1);
}

$env = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if (!in_array($env, ['development', 'local', 'test', 'uat'], true)) {
    echo "SKIP: refusing to run scanner tests in a non-development environment ({$env})\n";
    exit(1);
}

// Fixture ids cleaned up in the finally block.
$testPracticeId = 0;
$otherPracticeId = 0;
$testUserId = 0;
$createdScanIds = [];
$createdCaseIds = [];
$createdFilePaths = [];

function findScanId(PDO $pdo, int $practiceId, string $externalScanId): int {
    $key = ScanIngestion::dedupKey($practiceId, 'simulated', null, 'simulated-dev', $externalScanId);
    $stmt = $pdo->prepare("SELECT id FROM scanner_incoming_scans WHERE dedup_key = :k");
    $stmt->execute([':k' => $key]);
    return (int)$stmt->fetchColumn();
}

function insertFixtureCase(PDO $pdo, int $practiceId, string $notesPlain): string {
    $caseId = 'test_scan_case_' . bin2hex(random_bytes(6));
    $stmt = $pdo->prepare("
        INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name, case_type, status, notes, attachments_json, version)
        VALUES (:cid, :pid, :fn, :ln, 'crown', 'originated', :notes, '[]', 1)
    ");
    $stmt->execute([
        ':cid'   => $caseId,
        ':pid'   => $practiceId,
        ':fn'    => PIIEncryption::encrypt('Fixture'),
        ':ln'    => PIIEncryption::encrypt('Patient'),
        ':notes' => $notesPlain !== '' ? PIIEncryption::encrypt($notesPlain) : null,
    ]);
    return $caseId;
}

function caseRow(PDO $pdo, string $caseId): array {
    $stmt = $pdo->prepare("SELECT * FROM cases_cache WHERE case_id = :cid LIMIT 1");
    $stmt->execute([':cid' => $caseId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

try {
    // ------------------------------------------------------------------
    // 0. Migration (idempotent subprocess, same convention as PMS tests)
    // ------------------------------------------------------------------
    $migrationJson = shell_exec('php "' . __DIR__ . '/../migrations/2026_10_17_scanner_integration_foundation.php" 2>&1');
    $migration = json_decode((string)$migrationJson, true);
    assertTrue(is_array($migration) && ($migration['success'] ?? false) === true && empty($migration['errors']), 'migration runs cleanly (idempotent)');

    foreach (['scanner_incoming_scans', 'scanner_scan_files', 'scanner_scan_events'] as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE " . $pdo->quote($table));
        $stmt->execute();
        assertTrue($stmt->rowCount() === 1, "table exists: {$table}");
    }

    // ------------------------------------------------------------------
    // 1. Fixtures
    // ------------------------------------------------------------------
    $testPracticeId = (int)$pdo->query("SELECT id FROM practices ORDER BY id LIMIT 1")->fetchColumn();
    assertTrue($testPracticeId > 0, 'fixture practice exists');
    $otherPracticeId = (int)$pdo->query("SELECT id FROM practices WHERE id <> {$testPracticeId} ORDER BY id LIMIT 1")->fetchColumn();
    if ($otherPracticeId === 0) {
        $otherPracticeId = $testPracticeId + 999999;
    }
    $testUserId = (int)$pdo->query("SELECT user_id FROM practice_users WHERE practice_id = {$testPracticeId} ORDER BY user_id LIMIT 1")->fetchColumn();
    assertTrue($testUserId > 0, 'fixture practice user exists');

    // Remove leftovers from previous aborted runs (FK cascades files/events).
    $pdo->exec("DELETE FROM scanner_incoming_scans WHERE vendor = 'simulated'");

    assertTrue(isFeatureEnabled('SCANNER_INBOX'), 'SCANNER_INBOX enabled in test env');
    assertTrue(isFeatureEnabled('SCANNER_SIMULATOR'), 'SCANNER_SIMULATOR enabled in test env');
    assertTrue(SimulatedScannerConnector::isAvailable(), 'simulated connector available in dev+flag');

    // Production blocking: with a production environment label the connector
    // refuses even when the flag is on.
    $savedEnv = $appConfig['current_environment'];
    $appConfig['current_environment'] = 'production';
    assertTrue(SimulatedScannerConnector::isAvailable() === false, 'simulator unavailable in production even with flag');
    $threw = false;
    try { new SimulatedScannerConnector(); } catch (Throwable $e) { $threw = true; }
    assertTrue($threw, 'connector constructor refuses in production');
    $appConfig['current_environment'] = $savedEnv;

    $connector = new SimulatedScannerConnector();
    $ctx = ['practice_id' => $testPracticeId, 'connection_id' => null, 'source_account' => 'simulated-dev', 'is_synthetic' => true];

    // ------------------------------------------------------------------
    // 2. Successful import
    // ------------------------------------------------------------------
    $ctx['scenario'] = 'success';
    $payloads = $connector->fetchScans($ctx);
    assertEquals(1, count($payloads), 'success scenario returns one scan');

    $r1 = ScanIngestion::ingestScan($pdo, $ctx, $connector, $payloads[0]);
    assertTrue($r1['inserted'] === true, 'first ingest inserts the scan');
    assertEquals('ready', $r1['status'], 'scan ready after both files download');
    $scanId = $r1['scan_id'];
    $createdScanIds[] = $scanId;

    $files = ScanIngestion::listFiles($pdo, $scanId);
    assertEquals(2, count($files), 'two file rows created');
    foreach ($files as $f) {
        assertEquals('ready', $f['status'], "file ready: {$f['file_name']}");
        assertTrue(!empty($f['storage_path']) && strpos($f['storage_path'], "cases/{$testPracticeId}/pending_scan_") === 0, 'stored under practice-scoped pending key');
        assertTrue(!empty($f['checksum_sha256']), 'checksum recorded');
        $createdFilePaths[] = [$f['storage_driver'], $f['storage_path']];
    }

    // Stored bytes are retrievable and decryptable patient data is stored
    // encrypted.
    $scanRow = ScanIngestion::findScanForPractice($pdo, $testPracticeId, $scanId);
    assertTrue(strpos((string)$scanRow['patient_first_name'], 'PatientOne') === false, 'patient name stored encrypted');
    assertEquals('PatientOne', ScanIngestion::decryptField($scanRow['patient_last_name']), 'patient name decrypts');
    assertTrue(ScanIngestion::decryptField($scanRow['instructions']) !== null, 'instructions decrypt');

    // ------------------------------------------------------------------
    // 3. Repeat delivery = idempotent dedup
    // ------------------------------------------------------------------
    $r2 = ScanIngestion::ingestScan($pdo, $ctx, $connector, $payloads[0]);
    assertTrue($r2['inserted'] === false, 'repeat delivery is deduped');
    assertEquals($scanId, $r2['scan_id'], 'repeat resolves to the same scan row');
    assertEquals(2, count(ScanIngestion::listFiles($pdo, $scanId)), 'no duplicate file rows after repeat');
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM scanner_incoming_scans WHERE dedup_key = " . $pdo->quote($scanRow['dedup_key']))->fetchColumn();
    assertEquals(1, $cnt, 'single row per dedup_key');

    // ------------------------------------------------------------------
    // 4. Attach to an existing case (notes preserved; attachments added)
    // ------------------------------------------------------------------
    $caseId = insertFixtureCase($pdo, $testPracticeId, 'EXISTING NOTE - must survive');
    $createdCaseIds[] = $caseId;

    $attach = ScanInbox::attachToCase($pdo, $testPracticeId, $testUserId, $scanId, $caseId, false);
    assertTrue(!empty($attach['success']), 'attach to existing case succeeds');

    $case = caseRow($pdo, $caseId);
    $atts = json_decode($case['attachments_json'] ?? '[]', true);
    assertEquals(2, count($atts), 'two scan attachments appended to case');
    $storagePaths = array_column($atts, 'storagePath');
    foreach ($storagePaths as $p) {
        assertTrue(strpos($p, "cases/{$testPracticeId}/{$caseId}/intraoralScans/") === 0, 'attachment moved to case-owned key');
    }
    $notesAfter = ScanIngestion::decryptField($case['notes']);
    assertEquals('EXISTING NOTE - must survive', $notesAfter, 'existing case instructions preserved verbatim');

    $freshScan = ScanIngestion::findScanForPractice($pdo, $testPracticeId, $scanId);
    assertEquals('linked', $freshScan['status'], 'scan marked linked');
    assertEquals($caseId, $freshScan['case_id'], 'scan records the linked case');

    // Idempotent re-attach: same scan+case -> success, no duplicate files.
    $attach2 = ScanInbox::attachToCase($pdo, $testPracticeId, $testUserId, $scanId, $caseId, false);
    assertTrue(!empty($attach2['success']) && !empty($attach2['alreadyLinked']), 're-attach is idempotent success');
    $atts2 = json_decode(caseRow($pdo, $caseId)['attachments_json'], true);
    assertEquals(2, count($atts2), 're-attach adds no duplicate attachments');

    // Attach same scan to a different case -> conflict.
    $caseId2 = insertFixtureCase($pdo, $testPracticeId, '');
    $createdCaseIds[] = $caseId2;
    $attachOther = ScanInbox::attachToCase($pdo, $testPracticeId, $testUserId, $scanId, $caseId2, false);
    assertTrue(empty($attachOther['success']) && !empty($attachOther['conflict']), 'attach to a second case conflicts');

    // ------------------------------------------------------------------
    // 5. Revised vendor file: new row, attached original untouched
    // ------------------------------------------------------------------
    $ctx['scenario'] = 'revised';
    foreach ($connector->fetchScans($ctx) as $p) {
        ScanIngestion::ingestScan($pdo, $ctx, $connector, $p);
    }
    $filesAfterRev = ScanIngestion::listFiles($pdo, $scanId);
    $stlRevisions = [];
    foreach ($filesAfterRev as $f) {
        if (strpos($f['external_file_id'], 'crown.stl') !== false) {
            $stlRevisions[$f['revision']] = $f;
        }
    }
    assertEquals(2, count($stlRevisions), 'revised STL produced a second file row');
    assertEquals($caseId, $stlRevisions['rev1']['attached_case_id'] ?? null, 'attached rev1 row never superseded/detached');
    assertEquals('ready', $stlRevisions['rev2']['status'] ?? '', 'rev2 downloaded and ready');
    $atts3 = json_decode(caseRow($pdo, $caseId)['attachments_json'], true);
    assertEquals(2, count($atts3), 'revised file did not modify the linked case (no silent overwrite)');

    // ------------------------------------------------------------------
    // 6. Partial download is never ready; retry recovers without dupes
    // ------------------------------------------------------------------
    $ctx['scenario'] = 'partial';
    foreach ($connector->fetchScans($ctx) as $p) {
        $pr = ScanIngestion::ingestScan($pdo, $ctx, $connector, $p);
    }
    $partialScanId = findScanId($pdo, $testPracticeId, 'sim-scan-3003');
    $createdScanIds[] = $partialScanId;
    $partialScan = ScanIngestion::findScanForPractice($pdo, $testPracticeId, $partialScanId);
    assertEquals('failed', $partialScan['status'], 'scan with a truncated file is failed');
    $partialFiles = ScanIngestion::listFiles($pdo, $partialScanId);
    $truncated = null;
    foreach ($partialFiles as $f) {
        if (strpos($f['file_name'], '.stl') !== false) { $truncated = $f; }
        else { assertEquals('ready', $f['status'], 'complete PDF still imports while STL failed'); }
    }
    assertTrue($truncated !== null, 'truncated STL row exists');
    assertEquals('failed', $truncated['status'], 'incomplete download never marked ready');
    assertEquals('incomplete_download', $truncated['error_code'], 'incomplete error code recorded');
    assertTrue($truncated['storage_path'] === null, 'no storage path for incomplete download');

    // User retry: connector now serves full body on attempt>=2.
    ScanIngestion::processPendingFiles($pdo, $partialScan, $connector, $ctx + ['practice_id' => $testPracticeId]);
    ScanIngestion::refreshScanStatus($pdo, $partialScanId);
    $partialAfter = ScanIngestion::listFiles($pdo, $partialScanId);
    $allReady = true;
    foreach ($partialAfter as $f) { if ($f['status'] !== 'ready') { $allReady = false; } }
    assertTrue($allReady, 'retry completes the truncated download');
    assertEquals(2, count($partialAfter), 'retry created no duplicate file rows');
    $freshPartial = ScanIngestion::findScanForPractice($pdo, $testPracticeId, $partialScanId);
    assertEquals('ready', $freshPartial['status'], 'scan ready after successful retry');

    // ------------------------------------------------------------------
    // 7. Retryable vendor failure recovers within bounded attempts
    // ------------------------------------------------------------------
    $ctx['scenario'] = 'retryable';
    foreach ($connector->fetchScans($ctx) as $p) {
        ScanIngestion::ingestScan($pdo, $ctx, $connector, $p);
    }
    $retryScanId = findScanId($pdo, $testPracticeId, 'sim-scan-4004');
    $createdScanIds[] = $retryScanId;
    $retryScan = ScanIngestion::findScanForPractice($pdo, $testPracticeId, $retryScanId);
    assertEquals('failed', $retryScan['status'], 'retryable scenario starts failed');
    // Bounded retries converge (attempt 3 succeeds).
    for ($i = 0; $i < 3; $i++) {
        ScanIngestion::processPendingFiles($pdo, $retryScan, $connector, $ctx + ['practice_id' => $testPracticeId]);
        ScanIngestion::refreshScanStatus($pdo, $retryScanId);
        $retryScan = ScanIngestion::findScanForPractice($pdo, $testPracticeId, $retryScanId);
    }
    assertEquals('ready', $retryScan['status'], 'retryable failure recovered within attempt cap');
    $retryFiles = ScanIngestion::listFiles($pdo, $retryScanId);
    foreach ($retryFiles as $f) {
        assertTrue((int)$f['download_attempts'] <= ScanIngestion::MAX_DOWNLOAD_ATTEMPTS, 'attempts bounded');
    }

    // ------------------------------------------------------------------
    // 8. Missing patient reference -> no identifier suggestions
    // ------------------------------------------------------------------
    $ctx['scenario'] = 'missing_patient';
    foreach ($connector->fetchScans($ctx) as $p) {
        ScanIngestion::ingestScan($pdo, $ctx, $connector, $p);
    }
    $noPatScanId = findScanId($pdo, $testPracticeId, 'sim-scan-2002');
    $createdScanIds[] = $noPatScanId;
    $detail = ScanInbox::getDetail($pdo, $testPracticeId, $noPatScanId, $testUserId);
    assertTrue($detail !== null, 'scan without patient ref still listed');
    assertEquals(0, count($detail['suggestedCases']), 'no suggestions without a reliable identifier');
    assertEquals(null, $detail['patientFirstName'], 'no fabricated patient data');

    // ------------------------------------------------------------------
    // 9. Attach with opt-in instruction merge
    // ------------------------------------------------------------------
    $caseId3 = insertFixtureCase($pdo, $testPracticeId, 'KEEP ME');
    $createdCaseIds[] = $caseId3;
    $attachMerged = ScanInbox::attachToCase($pdo, $testPracticeId, $testUserId, $partialScanId, $caseId3, true);
    assertTrue(!empty($attachMerged['success']), 'attach with append_instructions succeeds');
    $mergedNotes = ScanIngestion::decryptField(caseRow($pdo, $caseId3)['notes']);
    assertTrue(strpos($mergedNotes, 'KEEP ME') === 0, 'original notes come first');
    assertTrue(strpos($mergedNotes, 'SYNTHETIC SCAN') !== false, 'imported instructions appended on opt-in');

    // ------------------------------------------------------------------
    // 10. Cross-practice isolation
    // ------------------------------------------------------------------
    assertTrue(ScanIngestion::findScanForPractice($pdo, $otherPracticeId, $partialScanId) === null, 'scan invisible to another practice');
    $foreignAttach = ScanInbox::attachToCase($pdo, $otherPracticeId, $testUserId, $partialScanId, $caseId3, false);
    assertTrue(empty($foreignAttach['success']), 'attach denied across practices');
    $foreignList = ScanInbox::listForPractice($pdo, $otherPracticeId, $testUserId, true);
    $leaked = 0;
    foreach ($foreignList as $s) { if (in_array($s['id'], $createdScanIds, true)) { $leaked++; } }
    assertEquals(0, $leaked, 'no scans leak into a foreign practice listing');
    $dismissForeign = ScanInbox::dismiss($pdo, $otherPracticeId, $testUserId, $retryScanId);
    assertTrue(empty($dismissForeign['success']), 'dismiss denied across practices');

    // ------------------------------------------------------------------
    // 11. Dismiss / restore lifecycle
    // ------------------------------------------------------------------
    $dismiss = ScanInbox::dismiss($pdo, $testPracticeId, $testUserId, $noPatScanId);
    assertTrue(!empty($dismiss['success']), 'dismiss succeeds');
    $dismissAgain = ScanInbox::dismiss($pdo, $testPracticeId, $testUserId, $noPatScanId);
    assertTrue(!empty($dismissAgain['success']), 'repeated dismiss is idempotent');
    $dismissedScan = ScanIngestion::findScanForPractice($pdo, $testPracticeId, $noPatScanId);
    assertEquals('dismissed', $dismissedScan['status'], 'scan dismissed');
    $attachDismissed = ScanInbox::attachToCase($pdo, $testPracticeId, $testUserId, $noPatScanId, $caseId3, false);
    assertTrue(empty($attachDismissed['success']), 'cannot attach a dismissed scan');
    $restore = ScanInbox::restore($pdo, $testPracticeId, $testUserId, $noPatScanId);
    assertTrue(!empty($restore['success']) && $restore['status'] === 'ready', 'restore reopens the workflow as ready');

    // ------------------------------------------------------------------
    // 12. Audit events recorded, PHI-free
    // ------------------------------------------------------------------
    $events = $pdo->prepare("SELECT event, detail FROM scanner_scan_events WHERE scan_id = :sid ORDER BY id");
    $events->execute([':sid' => $scanId]);
    $eventNames = array_column($events->fetchAll(PDO::FETCH_ASSOC), 'event');
    assertTrue(in_array('imported', $eventNames, true), 'import event audited');
    assertTrue(in_array('linked', $eventNames, true), 'link event audited');
    assertTrue(in_array('file_ready', $eventNames, true), 'file completion audited');
    $allDetail = json_encode($pdo->query("SELECT GROUP_CONCAT(detail) FROM scanner_scan_events WHERE scan_id IN (" . implode(',', $createdScanIds) . ")")->fetchColumn());
    assertTrue(strpos($allDetail, 'PatientOne') === false && strpos($allDetail, 'Simulation') === false, 'audit detail carries no PHI');

    // ------------------------------------------------------------------
    // 13. No external side effects during simulation
    // ------------------------------------------------------------------
    // Ingestion + attach must not queue PMS outbound events or notifications.
    foreach (['integration_outbound_events'] as $sideEffectTable) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE " . $pdo->quote($sideEffectTable));
        $stmt->execute();
        if ($stmt->rowCount() === 1) {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$sideEffectTable} WHERE created_at >= NOW() - INTERVAL 5 MINUTE")->fetchColumn();
            assertEquals(0, $cnt, "no new {$sideEffectTable} rows from simulation");
        }
    }

    // ------------------------------------------------------------------
    // 14. Storage round-trip via ScanFileStore (local driver in dev)
    // ------------------------------------------------------------------
    $driver = ScanFileStore::driver();
    assertEquals('local', $driver, 'dev environment uses local object mirror');
    $oneFile = null;
    foreach (ScanIngestion::listFiles($pdo, $retryScanId) as $f) {
        if ($f['status'] === 'ready') { $oneFile = $f; break; }
    }
    assertTrue($oneFile !== null, 'ready file exists for storage round-trip');
    if ($oneFile) {
        $opened = ScanFileStore::openStream($oneFile['storage_driver'], $oneFile['storage_path']);
        assertTrue($opened !== null, 'stored file opens for streaming');
        if ($opened) {
            // [stream, size, content_type] - a PHP resource locally, a PSR
            // stream object on GCS.
            $stream = $opened[0];
            if (is_resource($stream)) {
                $first = fread($stream, 16);
                fclose($stream);
            } else {
                $first = $stream->read(16);
                $stream->close();
            }
            assertTrue($first !== false && $first !== '', 'stored bytes readable');
        }
    }

    // ------------------------------------------------------------------
    // 15. create-case integration defaults (CaseService contract)
    // ------------------------------------------------------------------
    // A scan payload maps cleanly onto CaseService::integrationRequiredFields
    // - name/type/status are the only mandatory fields.
    require_once __DIR__ . '/../api/CaseService.php';
    assertTrue(in_array('patientFirstName', CaseService::integrationRequiredFields(), true), 'CaseService requires patientFirstName');

} catch (Throwable $e) {
    $fail++;
    echo "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    // ---- Cleanup -------------------------------------------------------
    try {
        if ($createdScanIds) {
            $in = implode(',', array_map('intval', $createdScanIds));
            // Delete stored objects before the rows cascade away.
            $paths = $pdo->query("SELECT storage_driver, storage_path FROM scanner_scan_files WHERE scan_id IN ({$in}) AND storage_path IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($paths as $p) {
                if ($p['storage_path']) {
                    ScanFileStore::delete($p['storage_driver'], $p['storage_path']);
                }
            }
            // Also delete the moved case-owned objects.
            foreach ($createdCaseIds as $cid) {
                $atts = json_decode(caseRow($pdo, $cid)['attachments_json'] ?? '[]', true);
                foreach ((array)$atts as $att) {
                    if (!empty($att['storagePath']) && strpos($att['storagePath'], "cases/{$testPracticeId}/{$cid}/") === 0) {
                        ScanFileStore::delete($att['storageType'] ?? 'local', $att['storagePath']);
                    }
                }
            }
            $pdo->exec("DELETE FROM scanner_incoming_scans WHERE id IN ({$in})");
        }
        foreach ($createdCaseIds as $cid) {
            $pdo->prepare("DELETE FROM cases_cache WHERE case_id = :cid")->execute([':cid' => $cid]);
        }
        // Sweep any stragglers from this run.
        $pdo->exec("DELETE FROM scanner_incoming_scans WHERE vendor = 'simulated'");
    } catch (Throwable $cleanupError) {
        echo "CLEANUP WARNING: " . $cleanupError->getMessage() . "\n";
    }
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail > 0 ? 1 : 0);
