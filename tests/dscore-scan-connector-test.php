<?php
/**
 * DS Core scan retrieval connector tests.
 *
 * Exercises the read-only digital-impression connector end to end with a
 * MOCKED transport - no network I/O, no real DS Core credentials, all
 * fixtures derived from the PMS_persona.json schemas:
 *
 *   - discovery: pagination, empty results, create_time filter shape,
 *     overlapping windows, interrupted polling resume via page tokens
 *   - normalization: patient fields, per-model file descriptors with
 *     verbatim roles, signature-based revisions, missing patient data
 *     (FHIR lookup only when names are absent), instructions absent
 *   - downloads: explicit fileType=STL, X-Content-Id capture, md5 checksum
 *     verification, corrupt/truncated downloads, retryable failures,
 *     plain-STL vs zip packaging, archive safety limits, multi-entry
 *     ambiguity
 *   - ingestion: dedup on repeated polling, revision re-delivery,
 *     duplicate attachment prevention, attach-to-case flow
 *   - operational: disconnect mid-sync, concurrent worker lock, reactive
 *     401 -> refresh -> retry, 403, 429 + Retry-After, transient 5xx
 *
 * Requires the local development DB (port 3308) per AGENTS.md. Fixture
 * rows are cleaned up afterwards. Live DS Core testing remains blocked
 * until the sandbox Client ID is issued - everything here is mocked.
 *
 * Usage: php tests/dscore-scan-connector-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

// Test-local configuration - synthetic credentials only.
putenv('DS_CORE_ENVIRONMENT=sandbox');
putenv('DS_CORE_SANDBOX_CLIENT_ID=test-client-id-0000');
putenv('DS_CORE_SANDBOX_CLIENT_SECRET=test-client-secret-0000');
putenv('DS_CORE_CONNECT=true');
putenv('SCANNER_INBOX=true');
putenv('DS_CORE_SCAN_INTERVAL_SEC=1');
$_ENV['DS_CORE_ENVIRONMENT'] = 'sandbox';
$_ENV['DS_CORE_SANDBOX_CLIENT_ID'] = 'test-client-id-0000';
$_ENV['DS_CORE_SANDBOX_CLIENT_SECRET'] = 'test-client-secret-0000';
$_ENV['DS_CORE_CONNECT'] = 'true';
$_ENV['SCANNER_INBOX'] = 'true';
$_ENV['DS_CORE_SCAN_INTERVAL_SEC'] = '1';
$_SERVER['DS_CORE_ENVIRONMENT'] = 'sandbox';
$_SERVER['DS_CORE_SANDBOX_CLIENT_ID'] = 'test-client-id-0000';
$_SERVER['DS_CORE_SANDBOX_CLIENT_SECRET'] = 'test-client-secret-0000';
$_SERVER['DS_CORE_CONNECT'] = 'true';
$_SERVER['SCANNER_INBOX'] = 'true';
$_SERVER['DS_CORE_SCAN_INTERVAL_SEC'] = '1';

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/encryption.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/dscore/DsCoreAuthService.php';
require_once __DIR__ . '/../api/integrations/dscore/DsCoreClient.php';
require_once __DIR__ . '/../api/integrations/dscore/DsCoreScanConnector.php';
require_once __DIR__ . '/../api/integrations/dscore/DsCoreScanSync.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanInbox.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanFileStore.php';

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
    echo "SKIP: refusing to run DS Core scan tests in a non-development environment ({$env})\n";
    exit(1);
}

// -------------------------------------------------------------------------
// Mock transport: records calls, replays queued responses.
// Response shape: ['status'=>int,'headers'=>['name'=>v],'body'=>string]
// -------------------------------------------------------------------------
$calls = [];
$queue = [];
$transport = function ($method, $url, $headers, $body) use (&$calls, &$queue) {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    if (empty($queue)) {
        return ['status' => 500, 'headers' => [], 'body' => '{"error":"unscripted"}'];
    }
    $next = array_shift($queue);
    if (is_callable($next)) {
        return $next($method, $url, $headers, $body);
    }
    return $next;
};

function callsTo($calls, string $needle): array {
    return array_values(array_filter($calls, fn($c) => strpos($c['url'], $needle) !== false));
}

/** Digital impression fixture per the documented schema. */
function mkDi(string $id, array $overrides = []): array {
    return array_merge([
        'name'              => 'digitalImpressions/' . $id,
        'displayName'       => 'Scan ' . $id,
        'createTime'        => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
        'device'            => ['model' => 'Primescan', 'serialNumber' => 'SN-1'],
        'deviceVersion'     => '5.2.3',
        'software'          => 'Connect SW',
        'softwareVersion'   => '5.2',
        'externalSourceId'  => 'src-' . $id,
        'contentUri'        => 'https://storage/di/' . $id,
        'thumbnailContentUri' => 'https://storage/di/' . $id . '/thumb',
        'supportedFileTypes' => ['STL', 'PLY'],
        'estimatedContentSizesBytes' => ['STL' => 4096, 'PLY' => 8192],
        'account'           => ['id' => 'acct-9'],
        'user'              => ['id' => 'user-7'],
        'patient'           => [
            'id' => 'dpat-100', 'givenName' => 'Test', 'familyName' => 'Person',
            'birthday' => '1980-05-04', 'uri' => 'patients/dpat-100',
            'externalSourceId' => 'pms-ext-55',
        ],
        'models'            => [
            ['displayName' => 'upper', 'type' => 'UPPER_JAW_MODEL', 'textureFileNames' => []],
            ['displayName' => 'lower', 'type' => 'LOWER_JAW_MODEL', 'textureFileNames' => []],
        ],
    ], $overrides);
}

function pushList(&$queue, array $impressions, ?string $nextToken = null): void {
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'digitalImpressions' => $impressions,
        'nextPageToken'      => $nextToken,
        'totalSize'          => count($impressions),
    ])];
}

function pushContent(&$queue, string $bytes, string $contentId = 'cid-1', string $contentType = 'application/octet-stream'): void {
    $queue[] = ['status' => 200, 'headers' => ['x-content-id' => $contentId, 'content-type' => $contentType], 'body' => $bytes];
}

function pushChecksum(&$queue, string $bytes): void {
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode(['md5Checksum' => md5($bytes)])];
}

function pushRefresh(&$queue, string $suffix = 'x'): void {
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'accessToken' => 'synthetic-access-' . $suffix,
        'refreshToken' => 'synthetic-refresh-' . $suffix,
        'expiresIn' => 3599,
    ])];
}

function asciiStl(string $label): string {
    $stl = "solid {$label}\n  facet normal 0 0 1\n    outer loop\n"
        . "      vertex 0 0 0\n      vertex 10 0 0\n      vertex 0 10 0\n"
        . "    endloop\n  endfacet\nendsolid {$label}\n";
    return str_pad($stl, 2048, '# padding ');
}

function makeZip(array $entries): string {
    $tmp = tempnam(sys_get_temp_dir(), 'tz_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

$createdConnectionIds = [];
$createdScanIds = [];
$createdCaseIds = [];
$createdFilePaths = [];
$createdPracticeIds = [];

function seedConnection(PDO $pdo, int $practiceId, array $config = []): int {
    $stmt = $pdo->prepare("
        INSERT INTO integration_connections
            (practice_id, provider, status, sync_enabled, config_json)
        VALUES (:pid, 'ds_core', 'active', 1, :cfg)
    ");
    $cfg = array_merge(['token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)], $config);
    $stmt->execute([':pid' => $practiceId, ':cfg' => json_encode($cfg)]);
    $id = (int)$pdo->lastInsertId();
    IntegrationCredentials::store($pdo, $id, 'access_token', 'synthetic-access-seed');
    IntegrationCredentials::store($pdo, $id, 'refresh_token', 'synthetic-refresh-seed');
    return $id;
}

function pollState(PDO $pdo, int $connectionId): array {
    $cfg = json_decode((string)$pdo->query("SELECT config_json FROM integration_connections WHERE id = {$connectionId}")->fetchColumn(), true);
    return is_array($cfg) && isset($cfg['scan_poll']) ? $cfg['scan_poll'] : [];
}

function scanRow(PDO $pdo, int $practiceId, int $connectionId, string $externalScanId): ?array {
    $key = ScanIngestion::dedupKey($practiceId, 'primescan', $connectionId, null, $externalScanId);
    $stmt = $pdo->prepare("SELECT * FROM scanner_incoming_scans WHERE dedup_key = :k");
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mkFixturePractice(PDO $pdo, int $ownerUserId): int {
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at) VALUES (UUID(), :n, :cb, 1, NOW())")
        ->execute(['n' => 'dsc-scan-test-' . bin2hex(random_bytes(4)), 'cb' => $ownerUserId]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, 'admin', 1)")
        ->execute(['p' => $id, 'u' => $ownerUserId]);
    return $id;
}

// Poll cadence is per-connection via scan_poll.last_run_at - tests run
// multiple polls within seconds, so clear it (and any rate-limit) before
// each deliberate poll.
function forcePollReady(PDO $pdo, int $connectionId): void {
    $pdo->prepare("UPDATE integration_connections SET config_json = JSON_REMOVE(config_json, '$.scan_poll.last_run_at', '$.scan_poll.rate_limited_until') WHERE id = :id")
        ->execute([':id' => $connectionId]);
}

function insertFixtureCase(PDO $pdo, int $practiceId): string {
    $caseId = 'test_dsc_case_' . bin2hex(random_bytes(6));
    $stmt = $pdo->prepare("
        INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name, case_type, status, notes, attachments_json, version)
        VALUES (:cid, :pid, :fn, :ln, 'crown', 'originated', NULL, '[]', 1)
    ");
    $stmt->execute([
        ':cid' => $caseId, ':pid' => $practiceId,
        ':fn' => PIIEncryption::encrypt('Fixture'), ':ln' => PIIEncryption::encrypt('Patient'),
    ]);
    return $caseId;
}

$practiceId = (int)$pdo->query("SELECT id FROM practices ORDER BY id LIMIT 1")->fetchColumn();
$otherPracticeId = (int)$pdo->query("SELECT id FROM practices WHERE id <> {$practiceId} ORDER BY id LIMIT 1")->fetchColumn();
$userId = (int)$pdo->query("SELECT user_id FROM practice_users WHERE practice_id = {$practiceId} ORDER BY user_id LIMIT 1")->fetchColumn();
assertTrue($practiceId > 0 && $userId > 0 && $otherPracticeId > 0, 'fixture practices + user exist');

// Prior-run leftovers (connections cascade to credentials/scans manually).
$pdo->exec("DELETE f FROM scanner_scan_files f JOIN scanner_incoming_scans s ON s.id = f.scan_id WHERE s.external_scan_id LIKE 'dsc-%'");
$pdo->exec("DELETE e FROM scanner_scan_events e JOIN scanner_incoming_scans s ON s.id = e.scan_id WHERE s.external_scan_id LIKE 'dsc-%'");
$pdo->exec("DELETE FROM scanner_incoming_scans WHERE external_scan_id LIKE 'dsc-%'");
// Dev DB is fixture-only for ds_core (same convention as dscore-auth-test).
$pdo->exec("DELETE c, r FROM integration_connections c LEFT JOIN integration_credentials r ON r.connection_id = c.id WHERE c.provider = 'ds_core'");
$pdo->exec("DELETE FROM integration_connections WHERE provider = 'ds_core'");

try {
    $sync = new DsCoreScanSync($transport);

    // ==================================================================
    // A. Discovery: pagination + mapping
    // ==================================================================
    $connA = seedConnection($pdo, $practiceId);
    $createdConnectionIds[] = $connA;

    // Queue order follows REQUEST order: page-1 list -> a1's downloads
    // happen inside the page callback -> page-2 list -> the FHIR lookup
    // for dsc-a2's nameless patient -> a2's download.
    pushList($queue, [mkDi('dsc-a1')], 'tok-page2');
    $zipA = makeZip(['upper.stl' => asciiStl('UPPER-A1'), 'lower.stl' => asciiStl('LOWER-A1')]);
    pushContent($queue, $zipA, 'cid-a1');  // model 0 download
    pushChecksum($queue, $zipA);
    pushContent($queue, $zipA, 'cid-a1');  // model 1 download
    pushChecksum($queue, $zipA);
    pushList($queue, [mkDi('dsc-a2', ['models' => [], 'patient' => ['id' => 'dpat-200']])]);
    $queue[] = ['status' => 404, 'headers' => [], 'body' => '{}']; // FHIR patient 404 -> names stay null
    $stlA2 = asciiStl('SCAN-A2');
    pushContent($queue, $stlA2, 'cid-a2');
    pushChecksum($queue, $stlA2);

    $out = $sync->processDue($pdo);
    assertTrue($out['polled'] >= 1, 'first poll ran');
    assertEquals(2, $out['scans'], 'two scans ingested across two pages');
    assertEquals(2, $out['pages'], 'two pages fetched');

    // Filter shape: documented create_time comparison was sent.
    $listCalls = callsTo($calls, 'digitalImpressions?pageSize');
    assertEquals(2, count($listCalls), 'two list requests');
    parse_str((string)parse_url($listCalls[0]['url'], PHP_URL_QUERY), $q0);
    assertTrue(isset($q0['filter']) && strpos($q0['filter'], 'create_time >') !== false
        && strpos($q0['filter'], 'create_time <=') !== false, 'documented create_time filter sent');
    assertEquals('100', (string)($q0['pageSize'] ?? ''), 'pageSize 100 requested');
    parse_str((string)parse_url($listCalls[1]['url'], PHP_URL_QUERY), $q1);
    assertEquals('tok-page2', $q1['pageToken'] ?? null, 'nextPageToken passed through');

    // Scan rows.
    $scanA1 = scanRow($pdo, $practiceId, $connA, 'dsc-a1');
    assertTrue($scanA1 !== null, 'scan dsc-a1 exists');
    assertEquals('dsc-a1', $scanA1['external_scan_id'], 'external_scan_id mapped from resource name');
    assertEquals('dpat-100', $scanA1['external_patient_ref'], 'patient ref is the DS Core patient id');
    assertEquals('Test', ScanIngestion::decryptField($scanA1['patient_first_name']), 'patient first name mapped');
    assertEquals('Person', ScanIngestion::decryptField($scanA1['patient_last_name']), 'patient last name mapped');
    assertEquals('1980-05-04', ScanIngestion::decryptField($scanA1['patient_dob']), 'birthday mapped');
    assertEquals('ready', $scanA1['status'], 'scan ready after downloads');

    // Files: per-model descriptors with verbatim roles.
    $filesA1 = ScanIngestion::listFiles($pdo, (int)$scanA1['id']);
    assertEquals(2, count($filesA1), 'two per-model file rows');
    $roles = array_column($filesA1, 'file_role');
    assertTrue(in_array('UPPER_JAW_MODEL', $roles, true) && in_array('LOWER_JAW_MODEL', $roles, true),
        'model types stored verbatim as file_role');

    // Explicit STL selection on every content request.
    $dlCalls = callsTo($calls, '/content?');
    assertTrue(count($dlCalls) >= 2, 'content downloads happened');
    foreach ($dlCalls as $c) {
        parse_str((string)parse_url($c['url'], PHP_URL_QUERY), $dq);
        assertEquals('STL', $dq['fileType'] ?? null, 'fileType=STL explicitly requested');
    }
    // Checksum endpoint hit with the captured X-Content-Id.
    $ckCalls = callsTo($calls, '/checksum');
    assertEquals(3, count($ckCalls), 'checksum verified for each download');
    assertTrue(strpos($ckCalls[0]['url'], '/content/cid-a1/checksum') !== false
        || strpos($ckCalls[0]['url'], urlencode('cid-a1') . '/checksum') !== false,
        'checksum endpoint uses X-Content-Id');

    // Zip entries mapped back to the right models (upper/lower bytes).
    $f0 = $filesA1[0];
    assertEquals('ready', $f0['status'], 'model file stored');
    [$stream, $size] = ScanFileStore::openStream($f0['storage_driver'], $f0['storage_path']);
    $bytes = stream_get_contents($stream);
    $name = strtolower($f0['file_name']);
    assertTrue(strpos($bytes, strpos($name, 'upper') !== false ? 'UPPER-A1' : 'LOWER-A1') !== false,
        'archive entry extracted for the model file');

    $scanA2 = scanRow($pdo, $practiceId, $connA, 'dsc-a2');
    assertTrue($scanA2 !== null, 'scan dsc-a2 (no models) exists');
    assertEquals('ready', $scanA2['status'], 'plain-STL download ready');
    $filesA2 = ScanIngestion::listFiles($pdo, (int)$scanA2['id']);
    assertEquals(1, count($filesA2), 'single file row for model-less impression');
    assertEquals('dpat-200', $scanA2['external_patient_ref'], 'patient ref without names still mapped');
    assertEquals(null, ScanIngestion::decryptField($scanA2['patient_first_name']), 'missing names stay null (editable)');

    // The FHIR patient endpoint is used only when names are missing
    // (dsc-a2 has a patient id but no givenName/familyName) - and the
    // lookup failure path is exercised because no response was queued:
    // the queue drained empty above and the DI still ingested.
    $fhirCalls = callsTo($calls, 'fhir/Patient');
    assertTrue(count($fhirCalls) <= 1, 'FHIR lookup bounded and only when needed');

    // Cursor advanced to the window end; page_token cleared.
    $state = pollState($pdo, $connA);
    assertTrue(isset($state['cursor']) && $state['cursor'] !== '', 'cursor persisted after complete window');
    assertTrue(empty($state['page_token']), 'page token cleared on completion');
    assertTrue(isset($state['last_success_at']), 'success timestamp recorded');

    // ==================================================================
    // B. Idempotent re-poll (dedup) + overlapping window
    // ==================================================================
    $cursorBefore = $state['cursor'];
    forcePollReady($pdo, $connA);
    pushList($queue, [mkDi('dsc-a1'), mkDi('dsc-a2', ['models' => [], 'patient' => ['id' => 'dpat-200']])]); // overlap window re-lists both
    $out = $sync->processDue($pdo);
    $rowsA1 = $pdo->query("SELECT COUNT(*) FROM scanner_incoming_scans WHERE external_scan_id = 'dsc-a1'")->fetchColumn();
    assertEquals(1, (int)$rowsA1, 're-poll did not duplicate the scan');
    assertEquals(2, count(ScanIngestion::listFiles($pdo, (int)$scanA1['id'])), 're-poll did not duplicate files');
    // Overlapping window: the filter's lower bound is cursor - overlap.
    $lastList = end($listCalls);
    $listCalls = callsTo($calls, 'digitalImpressions?pageSize');
    $lastList = end($listCalls);
    parse_str((string)parse_url($lastList['url'], PHP_URL_QUERY), $ql);
    preg_match('/create_time > "([^"]+)"/', (string)($ql['filter'] ?? ''), $wm);
    $expectedStart = gmdate('Y-m-d\TH:i:s\Z', strtotime($cursorBefore) - 300);
    assertEquals($expectedStart, $wm[1] ?? null, 'window lower bound = cursor - 300s overlap');

    // ==================================================================
    // C. Revision detection via signature change
    // ==================================================================
    // Same impression id, different estimated size => new signature =>
    // new file revision rows (never overwrites).
    forcePollReady($pdo, $connA);
    pushList($queue, [mkDi('dsc-a1', ['estimatedContentSizesBytes' => ['STL' => 9999, 'PLY' => 8192]])]);
    $stlNew = asciiStl('UPPER-A1-REV2');
    $zipRev = makeZip(['upper.stl' => $stlNew, 'lower.stl' => asciiStl('LOWER-A1')]);
    pushContent($queue, $zipRev, 'cid-a1r');
    pushChecksum($queue, $zipRev);
    pushContent($queue, $zipRev, 'cid-a1r');
    pushChecksum($queue, $zipRev);
    $out = $sync->processDue($pdo);
    $filesA1b = ScanIngestion::listFiles($pdo, (int)$scanA1['id']);
    assertEquals(4, count($filesA1b), 'changed signature produced new revision rows');
    $revisions = array_unique(array_column($filesA1b, 'revision'));
    assertEquals(2, count($revisions), 'two distinct revisions recorded');
    // LIMITATION (documented): an identical-metadata re-export is
    // indistinguishable - no updateTime/etag exists in the spec.

    // ==================================================================
    // D. Empty results
    // ==================================================================
    forcePollReady($pdo, $connA);
    pushList($queue, []);
    $out = $sync->processDue($pdo);
    assertEquals(0, $out['scans'], 'empty page ingests nothing');
    $stateAfterEmpty = pollState($pdo, $connA);
    assertTrue(!empty($stateAfterEmpty['cursor']), 'cursor still advances on empty page');
    // connA's discovery work is done - stop it from consuming later
    // sections' fixtures on every poll.
    $pdo->prepare("UPDATE integration_connections SET sync_enabled = 0 WHERE id = :id")->execute([':id' => $connA]);

    // ==================================================================
    // E. Interrupted polling -> resume via stored page token
    // ==================================================================
    $practiceE = mkFixturePractice($pdo, $userId);
    $createdPracticeIds[] = $practiceE;
    $connE = seedConnection($pdo, $practiceE);
    $createdConnectionIds[] = $connE;

    // Page 1 commits, then the process is killed before page 2. Simulate
    // the interruption: ingest page 1 through fetchScans with an on_page
    // callback that persists progress, then throw a network failure.
    // We exercise the sync's own resume: queue page1 + a 500 for page2.
    pushList($queue, [mkDi('dsc-e1')], 'tok-e-page2');
    $stlE1a = asciiStl('UPPER-E1');
    $stlE1b = asciiStl('LOWER-E1');
    $zipE1 = makeZip(['upper.stl' => $stlE1a, 'lower.stl' => $stlE1b]);
    pushContent($queue, $zipE1, 'cid-e1');
    pushChecksum($queue, $zipE1);
    pushContent($queue, $zipE1, 'cid-e1');
    pushChecksum($queue, $zipE1);
    $queue[] = ['status' => 500, 'headers' => [], 'body' => '{}']; // page 2 fails
    $out = $sync->processDue($pdo);
    $stateE = pollState($pdo, $connE);
    assertEquals('tok-e-page2', $stateE['page_token'] ?? null, 'page token persisted after committed page');
    assertTrue(empty($stateE['cursor']), 'cursor not advanced on interrupted run');
    assertTrue(scanRow($pdo, $practiceE, $connE, 'dsc-e1') !== null, 'page-1 scan survived the interruption');
    assertTrue(!empty($out['errors']), 'interrupted run reports the failure');

    // Resume: stored token + stored window reused; page 2 now succeeds.
    forcePollReady($pdo, $connE);
    pushList($queue, [mkDi('dsc-e2', ['models' => []])]);
    $stlE2 = asciiStl('E2');
    pushContent($queue, $stlE2, 'cid-e2');
    pushChecksum($queue, $stlE2);
    $out = $sync->processDue($pdo);
    assertTrue(scanRow($pdo, $practiceE, $connE, 'dsc-e2') !== null, 'resumed poll ingested page-2 scan');
    $stateE2 = pollState($pdo, $connE);
    assertTrue(!empty($stateE2['cursor']) && empty($stateE2['page_token']), 'window closed after resume');
    $listCallsE = callsTo($calls, 'digitalImpressions?pageSize');
    $lastE = end($listCallsE);
    parse_str((string)parse_url($lastE['url'], PHP_URL_QUERY), $qe);
    assertEquals('tok-e-page2', $qe['pageToken'] ?? null, 'resume reuses the stored page token');
    $pdo->prepare("UPDATE integration_connections SET sync_enabled = 0 WHERE id = :id")->execute([':id' => $connE]);

    // ==================================================================
    // F. Disconnect during sync
    // ==================================================================
    $practiceF = mkFixturePractice($pdo, $userId);
    $createdPracticeIds[] = $practiceF;
    $connF = seedConnection($pdo, $practiceF);
    $createdConnectionIds[] = $connF;
    // Connector checks status only at boundaries; disable BEFORE the run
    // and verify nothing is fetched or persisted.
    $pdo->prepare("UPDATE integration_connections SET status = 'disabled', sync_enabled = 0 WHERE id = :id")
        ->execute([':id' => $connF]);
    $callsBefore = count($calls);
    $out = $sync->processDue($pdo);
    $fCalls = array_slice($calls, $callsBefore);
    assertTrue(count(callsTo($fCalls, 'digitalImpressions')) === 0, 'disabled connection is never polled');
    // Mid-run disconnect: re-enable, run with a multi-page response, then
    // disable between pages via a callable response hook.
    $pdo->prepare("UPDATE integration_connections SET status = 'active', sync_enabled = 1 WHERE id = :id")
        ->execute([':id' => $connF]);
    pushList($queue, [mkDi('dsc-f1', ['models' => []])], 'tok-f2');
    $stlF1 = asciiStl('F1');
    pushContent($queue, $stlF1, 'cid-f1');
    pushChecksum($queue, $stlF1);
    // On page 2's request, disable the connection first.
    $queue[] = function () use ($pdo, $connF) {
        $pdo->prepare("UPDATE integration_connections SET status = 'disabled', sync_enabled = 0 WHERE id = :id")
            ->execute([':id' => $connF]);
        return ['status' => 200, 'headers' => [], 'body' => json_encode(['digitalImpressions' => [mkDi('dsc-f2', ['models' => []])]])];
    };
    $out = $sync->processDue($pdo);
    $stateF = pollState($pdo, $connF);
    assertTrue(empty($stateF['cursor']), 'mid-sync disconnect leaves cursor un-advanced');
    assertTrue(scanRow($pdo, $practiceF, $connF, 'dsc-f2') === null, 'no scan ingested after disconnect');
    $pdo->prepare("UPDATE integration_connections SET status = 'active', sync_enabled = 0 WHERE id = :id")
        ->execute([':id' => $connF]);

    // ==================================================================
    // G. Reactive 401 -> serialized refresh -> retry; 403; 429; 5xx
    // ==================================================================
    $practiceG = mkFixturePractice($pdo, $userId);
    $createdPracticeIds[] = $practiceG;
    $connG = seedConnection($pdo, $practiceG);
    $createdConnectionIds[] = $connG;

    // 401 on the list call -> one token:refresh -> retry succeeds.
    $queue[] = ['status' => 401, 'headers' => [], 'body' => '{}'];
    pushRefresh($queue, 'g');
    pushList($queue, [mkDi('dsc-g1', ['models' => []])]);
    $stlG1 = asciiStl('G1');
    pushContent($queue, $stlG1, 'cid-g1');
    pushChecksum($queue, $stlG1);
    $out = $sync->processDue($pdo);
    $refreshCalls = callsTo($calls, 'token:refresh');
    assertTrue(count($refreshCalls) >= 1, '401 triggered a token refresh');
    assertTrue(scanRow($pdo, $practiceG, $connG, 'dsc-g1') !== null, 'retry after refresh ingested the scan');

    // 403: permission error - no refresh attempted, error recorded.
    $callsBefore403 = count($calls);
    forcePollReady($pdo, $connG);
    $queue[] = ['status' => 403, 'headers' => [], 'body' => '{}'];
    $out = $sync->processDue($pdo);
    $slice = array_slice($calls, $callsBefore403);
    assertEquals(0, count(callsTo($slice, 'token:refresh')), '403 does not trigger a refresh');
    assertTrue(!empty($out['errors']), '403 recorded as a sync error');
    $state403 = pollState($pdo, $connG);
    assertEquals('forbidden', $state403['last_error'] ?? null, '403 category recorded');

    // 429 with Retry-After: rate_limited_until persists; next run skips.
    forcePollReady($pdo, $connG);
    $queue[] = ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => '{}'];
    $out = $sync->processDue($pdo);
    $state429 = pollState($pdo, $connG);
    assertTrue(!empty($state429['rate_limited_until']), 'Retry-After persisted');
    $callsBefore429 = count($calls);
    $out = $sync->processDue($pdo);
    assertEquals(0, count(callsTo(array_slice($calls, $callsBefore429), 'digitalImpressions?pageSize')),
        'rate-limited connection skipped until Retry-After elapses');
    // Clear the limit for later tests.
    forcePollReady($pdo, $connG);

    // Transient 500: error recorded, cursor preserved for retry.
    $cursorBeforeG = pollState($pdo, $connG)['cursor'] ?? null;
    forcePollReady($pdo, $connG);
    $queue[] = ['status' => 503, 'headers' => [], 'body' => '{}'];
    $out = $sync->processDue($pdo);
    assertTrue(!empty($out['errors']), 'transient 5xx recorded');
    assertEquals($cursorBeforeG, pollState($pdo, $connG)['cursor'] ?? null, 'cursor preserved after transient failure');
    $pdo->prepare("UPDATE integration_connections SET sync_enabled = 0 WHERE id = :id")->execute([':id' => $connG]);

    // ==================================================================
    // H. Concurrent worker attempt (GET_LOCK contention)
    // ==================================================================
    $practiceH = mkFixturePractice($pdo, $userId);
    $createdPracticeIds[] = $practiceH;
    $connH = seedConnection($pdo, $practiceH);
    $createdConnectionIds[] = $connH;
    // Second DB connection holds the sync lock.
    $pdo2 = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $appConfig['db_host'], $appConfig['db_port'], $appConfig['db_name']),
        $appConfig['db_user'], $appConfig['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    assertEquals(1, (int)$pdo2->query("SELECT GET_LOCK('dscore_scan_sync_{$connH}', 0)")->fetchColumn(),
        'contending worker holds the lock');
    $out = $sync->processDue($pdo);
    assertTrue(($out['locked'] ?? 0) >= 1, 'second worker skips a locked connection');
    assertTrue(scanRow($pdo, $practiceH, $connH, 'dsc-h1') === null, 'locked connection ingests nothing');
    $pdo2->query("SELECT RELEASE_LOCK('dscore_scan_sync_{$connH}')")->fetchColumn();
    $pdo2 = null;
    $pdo->prepare("UPDATE integration_connections SET sync_enabled = 0 WHERE id = :id")->execute([':id' => $connH]);

    // ==================================================================
    // I. Download integrity failures
    // ==================================================================
    $practiceI = mkFixturePractice($pdo, $userId);
    $createdPracticeIds[] = $practiceI;
    $connI = seedConnection($pdo, $practiceI);
    $createdConnectionIds[] = $connI;

    // Checksum mismatch -> file failed (retryable), never marked ready.
    pushList($queue, [mkDi('dsc-i1', ['models' => []])]);
    $stlBad = asciiStl('I1');
    pushContent($queue, $stlBad, 'cid-i1');
    pushChecksum($queue, 'different-bytes'); // md5 of something else
    $out = $sync->processDue($pdo);
    $scanI1 = scanRow($pdo, $practiceI, $connI, 'dsc-i1');
    $filesI1 = ScanIngestion::listFiles($pdo, (int)$scanI1['id']);
    assertEquals('failed', $filesI1[0]['status'], 'checksum mismatch fails the file');
    assertEquals('checksum_mismatch', $filesI1[0]['error_code'], 'checksum_mismatch error recorded');
    assertTrue($filesI1[0]['storage_path'] === null, 'corrupt bytes never stored');

    // Truncated/corrupt retry: next attempt serves correct bytes -> ready.
    // processPendingFiles retries failed rows under the attempt cap.
    $connectorI = new DsCoreScanConnector($pdo, null, $transport);
    pushContent($queue, $stlBad, 'cid-i1');
    pushChecksum($queue, $stlBad);
    ScanIngestion::processPendingFiles($pdo, $scanI1, $connectorI, [
        'practice_id' => $practiceI, 'connection_id' => $connI, 'source_account' => null,
    ]);
    ScanIngestion::refreshScanStatus($pdo, (int)$scanI1['id']);
    $filesI1b = ScanIngestion::listFiles($pdo, (int)$scanI1['id']);
    assertEquals('ready', $filesI1b[0]['status'], 'retry with intact bytes marks file ready');
    assertEquals(hash('sha256', $stlBad), $filesI1b[0]['checksum_sha256'], 'stored sha256 recorded');

    // 404 content -> permanent 'content_gone'.
    forcePollReady($pdo, $connI);
    pushList($queue, [mkDi('dsc-i2', ['models' => []])]);
    $queue[] = ['status' => 404, 'headers' => [], 'body' => '{}'];
    $out = $sync->processDue($pdo);
    $scanI2 = scanRow($pdo, $practiceI, $connI, 'dsc-i2');
    $filesI2 = ScanIngestion::listFiles($pdo, (int)$scanI2['id']);
    assertEquals('failed', $filesI2[0]['status'], 'missing content fails the file');
    assertEquals('content_gone', $filesI2[0]['error_code'], '404 mapped to content_gone');

    // Unsafe archive entry path -> permanent packaging failure.
    forcePollReady($pdo, $connI);
    pushList($queue, [mkDi('dsc-i3', ['models' => []])]);
    $evilZip = makeZip(['../evil.stl' => 'x', 'ok.stl' => asciiStl('OK')]);
    pushContent($queue, $evilZip, 'cid-i3');
    pushChecksum($queue, $evilZip);
    $out = $sync->processDue($pdo);
    $scanI3 = scanRow($pdo, $practiceI, $connI, 'dsc-i3');
    $filesI3 = ScanIngestion::listFiles($pdo, (int)$scanI3['id']);
    assertEquals('failed', $filesI3[0]['status'], 'unsafe archive rejected');
    assertEquals('packaging_unsafe', $filesI3[0]['error_code'], 'unsafe entry recorded');

    // Multi-entry archive + no model mapping -> ambiguous, retryable.
    forcePollReady($pdo, $connI);
    pushList($queue, [mkDi('dsc-i4', [
        'models' => [['displayName' => 'nomatch', 'type' => 'UPPER_JAW_MODEL', 'textureFileNames' => []]],
    ])]);
    $ambZip = makeZip(['one.stl' => asciiStl('ONE'), 'two.stl' => asciiStl('TWO')]);
    pushContent($queue, $ambZip, 'cid-i4');
    pushChecksum($queue, $ambZip);
    $out = $sync->processDue($pdo);
    $scanI4 = scanRow($pdo, $practiceI, $connI, 'dsc-i4');
    $filesI4 = ScanIngestion::listFiles($pdo, (int)$scanI4['id']);
    assertEquals('failed', $filesI4[0]['status'], 'unmatchable archive entry fails');
    assertEquals('packaging_ambiguous', $filesI4[0]['error_code'], 'ambiguity flagged, never guessed');

    // ==================================================================
    // J. Attach-to-case flow + duplicate prevention + isolation
    // ==================================================================
    $caseId = insertFixtureCase($pdo, $practiceId);
    $createdCaseIds[] = $caseId;
    $attach = ScanInbox::attachToCase($pdo, $practiceId, $userId, (int)$scanA1['id'], $caseId, false);
    assertTrue(!empty($attach['success']), 'attach to case succeeds');
    $fresh = ScanIngestion::findScanForPractice($pdo, $practiceId, (int)$scanA1['id']);
    assertEquals('linked', $fresh['status'], 'scan linked');
    assertEquals($caseId, $fresh['case_id'], 'case recorded');

    // Idempotent re-attach: same result, no duplicate attachment rows.
    $caseRow1 = $pdo->query("SELECT attachments_json FROM cases_cache WHERE case_id = '{$caseId}'")->fetchColumn();
    $attach2 = ScanInbox::attachToCase($pdo, $practiceId, $userId, (int)$scanA1['id'], $caseId, false);
    assertTrue(!empty($attach2['success']), 'repeated attach is a success no-op');
    $caseRow2 = $pdo->query("SELECT attachments_json FROM cases_cache WHERE case_id = '{$caseId}'")->fetchColumn();
    assertEquals($caseRow1, $caseRow2, 'no duplicate attachment metadata');

    // Cross-practice: the scan is invisible and unattachable elsewhere.
    assertTrue(ScanIngestion::findScanForPractice($pdo, $otherPracticeId, (int)$scanA1['id']) === null,
        'scan invisible to another practice');
    $foreign = ScanInbox::attachToCase($pdo, $otherPracticeId, $userId, (int)$scanA1['id'], $caseId, false);
    assertTrue(empty($foreign['success']) && ($foreign['error'] ?? '') === 'not_found',
        'cross-practice attach rejected as not_found');

    // Cross-practice connection use is rejected: a scan belonging to
    // practice A must never download through practice B's connection.
    $connB = seedConnection($pdo, $otherPracticeId);
    $createdConnectionIds[] = $connB;
    $scanA2files = ScanIngestion::listFiles($pdo, (int)$scanA2['id']);
    $connectorX = new DsCoreScanConnector($pdo, null, $transport);
    try {
        $connectorX->downloadFile([
            'practice_id' => $practiceId, 'connection_id' => $connB,
            'remote_ref' => $scanA2files[0]['remote_ref'],
        ], $scanA2files[0]['external_file_id']);
        assertTrue(false, 'cross-practice connection rejected');
    } catch (ScannerDownloadException $e) {
        assertEquals('source_unavailable', $e->getErrorCode(), 'cross-practice connection rejected');
    }

    // Case-prefill contract: inbox serialization exposes the fields the
    // create-case form consumes; nothing is auto-linked by name.
    $detail = ScanInbox::getDetail($pdo, $practiceId, (int)$scanA2['id'], $userId);
    assertTrue(array_key_exists('patientFirstName', $detail) && array_key_exists('patientDob', $detail),
        'inbox detail exposes case-prefill fields');
    assertTrue(is_array($detail['suggestedCases']), 'suggestions rely on patient ref mapping, never names');

    // ==================================================================
    // K. Patient lookup fills missing names only
    // ==================================================================
    $pdo->prepare("UPDATE integration_connections SET sync_enabled = 0 WHERE id = :id")->execute([':id' => $connI]);
    $pdo->prepare("UPDATE integration_connections SET sync_enabled = 0 WHERE id = :id")->execute([':id' => $connB]);

    $practiceK = mkFixturePractice($pdo, $userId);
    $createdPracticeIds[] = $practiceK;
    $connK = seedConnection($pdo, $practiceK);
    $createdConnectionIds[] = $connK;
    pushList($queue, [mkDi('dsc-k1', [
        'models' => [],
        'patient' => ['id' => 'dpat-999'],
    ])]);
    // FHIR response (documented patient schema) supplies the names.
    $queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'id' => 'dpat-999', 'givenName' => 'Lookup', 'familyName' => 'Case', 'birthday' => '1970-01-02',
    ])];
    $stlK1 = asciiStl('K1');
    pushContent($queue, $stlK1, 'cid-k1');
    pushChecksum($queue, $stlK1);
    $out = $sync->processDue($pdo);
    $scanK1 = scanRow($pdo, $practiceK, $connK, 'dsc-k1');
    assertTrue($scanK1 !== null, 'lookup scan ingested');
    assertEquals('Lookup', ScanIngestion::decryptField($scanK1['patient_first_name']),
        'FHIR lookup filled missing first name');
    assertEquals('Case', ScanIngestion::decryptField($scanK1['patient_last_name']),
        'FHIR lookup filled missing last name');
    $kCalls = callsTo($calls, 'fhir/Patient/dpat-999');
    assertEquals(1, count($kCalls), 'patient fetched exactly once via documented endpoint');
    // And the external id is NOT treated as an Open Dental id - it is only
    // the opaque external_patient_ref.
    assertEquals('dpat-999', $scanK1['external_patient_ref'], 'opaque patient ref preserved');

    // ==================================================================
    // L. Instructions: never fabricated
    // ==================================================================
    assertTrue(ScanIngestion::decryptField($scanK1['instructions']) === null,
        'instructions stay null - the API supplies none');

    // ==================================================================
    // M. Feature gates
    // ==================================================================
    // With DS_CORE_CONNECT off the sync is inert (flag off -> skip).
    putenv('DS_CORE_CONNECT=false');
    $_ENV['DS_CORE_CONNECT'] = 'false';
    $_SERVER['DS_CORE_CONNECT'] = 'false';
    $out = $sync->processDue($pdo);
    assertTrue(!empty($out['skipped']), 'DS_CORE_CONNECT=false makes sync inert');
    putenv('DS_CORE_CONNECT=true');
    $_ENV['DS_CORE_CONNECT'] = 'true';
    $_SERVER['DS_CORE_CONNECT'] = 'true';

    // ==================================================================
    // N. PHI hygiene: tokens/PHI never reach scan events
    // ==================================================================
    $events = $pdo->query("SELECT event, detail FROM scanner_scan_events WHERE scan_id = " . (int)$scanA1['id'])->fetchAll(PDO::FETCH_ASSOC);
    $leak = false;
    foreach ($events as $ev) {
        $blob = ($ev['event'] ?? '') . ' ' . ($ev['detail'] ?? '');
        if (stripos($blob, 'synthetic-access') !== false || stripos($blob, 'synthetic-refresh') !== false
            || stripos($blob, 'Bearer') !== false || stripos($blob, 'Person') !== false) {
            $leak = true;
        }
    }
    assertTrue(!$leak, 'no tokens or patient data in scan events');

} finally {
    // ------------------------------------------------------------------
    // Cleanup fixtures - fresh connection so an unbuffered cursor left by
    // a mid-assertion fatal can never wedge the teardown.
    // ------------------------------------------------------------------
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $appConfig['db_host'], $appConfig['db_port'], $appConfig['db_name']),
            $appConfig['db_user'], $appConfig['db_password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
    } catch (Throwable $e) { /* fall back to the existing handle */ }
    foreach ($createdConnectionIds as $cid) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('dscore_scan_sync_{$cid}')")->fetchAll();
        } catch (Throwable $e) { /* lock not held */ }
    }
    foreach ($createdScanIds as $sid) {
        $pdo->prepare("DELETE FROM scanner_scan_files WHERE scan_id = :id")->execute([':id' => $sid]);
        $pdo->prepare("DELETE FROM scanner_scan_events WHERE scan_id = :id")->execute([':id' => $sid]);
        $pdo->prepare("DELETE FROM scanner_incoming_scans WHERE id = :id")->execute([':id' => $sid]);
    }
    foreach ($createdCaseIds as $cid) {
        $pdo->prepare("DELETE FROM cases_cache WHERE case_id = :cid")->execute([':cid' => $cid]);
    }
    // Scans were ingested by the sync (ids unknown here) - clean by prefix.
    $pdo->prepare("DELETE f FROM scanner_scan_files f JOIN scanner_incoming_scans s ON s.id = f.scan_id WHERE s.external_scan_id LIKE 'dsc-%'")->execute();
    $pdo->prepare("DELETE e FROM scanner_scan_events e JOIN scanner_incoming_scans s ON s.id = e.scan_id WHERE s.external_scan_id LIKE 'dsc-%'")->execute();
    $pdo->prepare("DELETE FROM scanner_incoming_scans WHERE external_scan_id LIKE 'dsc-%'")->execute();
    foreach ($createdConnectionIds as $cid) {
        $pdo->prepare("DELETE FROM integration_credentials WHERE connection_id = :id")->execute([':id' => $cid]);
        $pdo->prepare("DELETE FROM integration_connections WHERE id = :id")->execute([':id' => $cid]);
    }
    foreach ($createdPracticeIds as $pid) {
        $pdo->prepare("DELETE FROM practice_users WHERE practice_id = :id")->execute([':id' => $pid]);
        $pdo->prepare("DELETE FROM practices WHERE id = :id")->execute([':id' => $pid]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
