<?php
/**
 * PMS integration foundation tests (Phase A).
 *
 * Exercises the generic integration data model and helpers introduced by
 * migrations/2026_09_18_pms_integration_foundation.php and api/integrations/*.
 *
 * Requires the local development database (per AGENTS.md: development
 * environment, MySQL on port 3308) and a configured ENCRYPTION_KEY.
 * Creates throwaway connection rows under a real practice with a fake
 * provider ('test_pms_phase_a*') and removes them afterwards via the
 * FK cascade - no fixture data is left behind.
 *
 * These tests deliberately do NOT touch cases_cache, create-case.php,
 * notifications, or any network - Phase A creates no DentaTrak cases.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/SyncEngine.php';

global $pdo;

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

function assertThrows(callable $fn, string $message) {
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        echo "FAIL: {$message} (no exception thrown)\n";
    } catch (Throwable $e) {
        $pass++;
        echo "PASS: {$message}\n";
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "SKIP: no database connection available\n";
    exit(1);
}

// A fake adapter for registry tests - implements the interface, no network.
class PhaseAFakeAdapter implements PmsAdapterInterface {
    public function getProvider(): string { return 'test_pms_phase_a'; }
    public function testConnection(array $credentials, array $config): array {
        return ['success' => true, 'message' => 'fake', 'external_account_id' => 'acct-1'];
    }
    public function fetchCases(array $credentials, array $config, ?string $cursor, int $limit): array {
        return ['cases' => [], 'next_cursor' => null, 'has_more' => false];
    }
    public function normalizeCase(array $rawRecord): CanonicalCase {
        return new CanonicalCase();
    }
}

const TEST_PROVIDER   = 'test_pms_phase_a';
const TEST_PROVIDER_B = 'test_pms_phase_a_b';
const TEST_EXTERNAL   = 'EXT-LC-1001';
const TEST_SECRET     = 'sk_test_secret_value_phase_a';

$testPracticeId = 0;
$otherPracticeId = 0;
$connId = 0;
$connIdB = 0;

try {
    // ------------------------------------------------------------------
    // 0. Schema: apply the migration (idempotent). Invoked as a subprocess
    //    because the migration file's entry-point guard runs+exits under
    //    CLI when require'd directly.
    // ------------------------------------------------------------------
    $migrationJson = shell_exec('php "' . __DIR__ . '/../migrations/2026_09_18_pms_integration_foundation.php" 2>&1');
    $migration = json_decode((string)$migrationJson, true);
    assertTrue(is_array($migration) && ($migration['success'] ?? false) === true && empty($migration['errors']), 'migration runs cleanly (idempotent)');

    foreach (['integration_connections', 'integration_credentials', 'integration_entity_mappings', 'integration_sync_runs', 'integration_sync_events'] as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE " . $pdo->quote($table));
        $stmt->execute();
        assertTrue($stmt->rowCount() === 1, "table exists: {$table}");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_entity_mappings' AND INDEX_NAME = 'uk_integration_entity'");
    assertTrue((int)$stmt->fetchColumn() === 3, 'mapping unique key has 3 columns (connection_id, entity_type, external_id)');

    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_connections' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    assertTrue((int)$stmt->fetchColumn() === 2, 'integration_connections has 2 foreign keys');

    // ------------------------------------------------------------------
    // 1. Fixtures: a real practice + two test-provider connections.
    // ------------------------------------------------------------------
    $testPracticeId = (int)$pdo->query("SELECT id FROM practices ORDER BY id LIMIT 1")->fetchColumn();
    assertTrue($testPracticeId > 0, 'fixture practice exists');
    $otherPracticeId = (int)$pdo->query("SELECT id FROM practices WHERE id <> {$testPracticeId} ORDER BY id LIMIT 1")->fetchColumn();
    if ($otherPracticeId === 0) {
        $otherPracticeId = $testPracticeId + 999999; // non-existent practice is fine for isolation checks
    }

    // Clean leftovers from any previous aborted run (FK cascade handles children).
    $pdo->exec("DELETE FROM integration_connections WHERE provider IN ('" . TEST_PROVIDER . "', '" . TEST_PROVIDER_B . "')");

    $stmt = $pdo->prepare("INSERT INTO integration_connections (practice_id, provider, status) VALUES (:pid, :provider, 'active')");
    $stmt->execute([':pid' => $testPracticeId, ':provider' => TEST_PROVIDER]);
    $connId = (int)$pdo->lastInsertId();
    assertTrue($connId > 0, 'test connection created');

    // Duplicate (practice_id, provider) must be rejected by the unique key.
    assertThrows(function () use ($pdo, $testPracticeId) {
        $pdo->prepare("INSERT INTO integration_connections (practice_id, provider) VALUES (:pid, :provider)")
            ->execute([':pid' => $testPracticeId, ':provider' => TEST_PROVIDER]);
    }, 'duplicate (practice_id, provider) rejected');

    // Same provider under a DIFFERENT practice must succeed (scoping is per practice).
    $stmt = $pdo->prepare("INSERT INTO integration_connections (practice_id, provider, status) VALUES (:pid, :provider, 'active')");
    $stmt->execute([':pid' => $otherPracticeId, ':provider' => TEST_PROVIDER]);
    $connIdB = (int)$pdo->lastInsertId();
    assertTrue($connIdB > 0, 'same provider allowed on a different practice');

    // ------------------------------------------------------------------
    // 2. Practice isolation
    // ------------------------------------------------------------------
    $conn = IntegrationManager::getConnectionForPractice($pdo, $testPracticeId, $connId);
    assertTrue($conn !== null && (int)$conn['practice_id'] === $testPracticeId, 'connection loads for owning practice');
    assertTrue(IntegrationManager::getConnectionForPractice($pdo, $otherPracticeId, $connId) === null, 'connection invisible to foreign practice');
    assertTrue((int)IntegrationManager::findConnection($pdo, $connId)['id'] === $connId, 'internal findConnection works');

    $listed = IntegrationManager::listConnectionsForPractice($pdo, $testPracticeId);
    $ours = null;
    foreach ($listed as $c) {
        if ($c['id'] === $connId) { $ours = $c; }
    }
    assertTrue($ours !== null, 'listConnectionsForPractice returns test connection');
    assertTrue($ours !== null && !array_key_exists('config_json', $ours), 'public projection omits config_json');
    assertTrue($ours !== null && $ours['credentials_configured'] === false, 'credentials_configured=false before storing');

    $due = IntegrationManager::listConnectionsDueForSync($pdo);
    $foundDue = false;
    foreach ($due as $c) {
        if ((int)$c['id'] === $connId) { $foundDue = true; }
    }
    assertTrue($foundDue, 'active+enabled connection is due for sync');

    // ------------------------------------------------------------------
    // 3. Credential storage
    // ------------------------------------------------------------------
    IntegrationCredentials::store($pdo, $connId, 'api_key', TEST_SECRET);
    assertTrue(IntegrationCredentials::has($pdo, $connId, 'api_key'), 'credential stored');
    assertTrue(IntegrationCredentials::countForConnection($pdo, $connId) === 1, 'credential count is 1');

    // Ciphertext in the table must not be the plaintext.
    $raw = $pdo->query("SELECT credential_value_encrypted FROM integration_credentials WHERE connection_id = {$connId} AND credential_key = 'api_key'")->fetchColumn();
    assertTrue(is_string($raw) && $raw !== '' && $raw !== TEST_SECRET && strpos($raw, TEST_SECRET) === false, 'stored value is ciphertext, not plaintext');

    assertTrue(IntegrationCredentials::get($pdo, $connId, 'api_key') === TEST_SECRET, 'credential decrypts to original');

    // Upsert overwrites.
    IntegrationCredentials::store($pdo, $connId, 'api_key', TEST_SECRET . '_v2');
    assertTrue(IntegrationCredentials::get($pdo, $connId, 'api_key') === TEST_SECRET . '_v2', 'credential upsert overwrites');

    // Isolation: the other connection sees nothing.
    assertTrue(IntegrationCredentials::get($pdo, $connIdB, 'api_key') === null, 'credentials not visible across connections');
    assertTrue(IntegrationCredentials::countForConnection($pdo, $connIdB) === 0, 'credential count isolated per connection');

    // getAll returns a key=>plaintext map (server-side only).
    $all = IntegrationCredentials::getAll($pdo, $connId);
    assertTrue(($all['api_key'] ?? null) === TEST_SECRET . '_v2', 'getAll decrypts map');

    // Public projection: flag flips true, and plaintext never appears.
    $public = IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connId));
    assertTrue($public['credentials_configured'] === true, 'credentials_configured=true after storing');
    assertTrue(strpos(json_encode($public), TEST_SECRET) === false && strpos(json_encode($public), $raw) === false, 'public projection leaks no credential material');

    // ------------------------------------------------------------------
    // 4. Adapter registry
    // ------------------------------------------------------------------
    IntegrationManager::clearAdapterFactories();
    assertThrows(function () use ($conn) {
        IntegrationManager::getAdapter($conn);
    }, 'unregistered provider adapter throws (fail-closed)');

    IntegrationManager::registerAdapterFactory(TEST_PROVIDER, function (array $connection) {
        return new PhaseAFakeAdapter();
    });
    $adapter = IntegrationManager::getAdapter($conn);
    assertTrue($adapter instanceof PmsAdapterInterface && $adapter->getProvider() === TEST_PROVIDER, 'registered adapter resolves');

    IntegrationManager::registerAdapterFactory('test_pms_bad', function () { return new stdClass(); });
    assertThrows(function () {
        IntegrationManager::getAdapter(['provider' => 'test_pms_bad']);
    }, 'factory returning non-adapter throws');
    IntegrationManager::clearAdapterFactories();

    // ------------------------------------------------------------------
    // 5. Entity mapping dedup
    // ------------------------------------------------------------------
    $r1 = SyncEngine::reserveMapping($pdo, $connId, SyncEngine::ENTITY_CASE, TEST_EXTERNAL);
    assertTrue($r1['created'] === true && $r1['mapping']['internal_id'] === null, 'first reserve creates unattached mapping');
    $mappingId = (int)$r1['mapping']['id'];

    $r2 = SyncEngine::reserveMapping($pdo, $connId, SyncEngine::ENTITY_CASE, TEST_EXTERNAL);
    assertTrue($r2['created'] === false && (int)$r2['mapping']['id'] === $mappingId, 'duplicate reserve returns existing mapping (dedup)');

    // Same external_id under a different entity_type is a different mapping.
    $r3 = SyncEngine::reserveMapping($pdo, $connId, SyncEngine::ENTITY_PATIENT, TEST_EXTERNAL);
    assertTrue($r3['created'] === true, 'same external_id allowed for different entity_type');

    // Same external_id under a different CONNECTION is independent.
    $r4 = SyncEngine::reserveMapping($pdo, $connIdB, SyncEngine::ENTITY_CASE, TEST_EXTERNAL);
    assertTrue($r4['created'] === true, 'same external_id allowed on different connection');

    // Attach internal id once; refuse to re-point afterwards.
    assertTrue(SyncEngine::attachInternalId($pdo, $mappingId, 'case', 'dtcase_phasea_1') === true, 'attachInternalId fills once');
    assertTrue(SyncEngine::attachInternalId($pdo, $mappingId, 'case', 'dtcase_other') === false, 'attachInternalId refuses overwrite');

    $rev = SyncEngine::findMappingByInternalId($pdo, $connId, SyncEngine::ENTITY_CASE, 'dtcase_phasea_1');
    assertTrue($rev !== null && $rev['external_id'] === TEST_EXTERNAL, 'reverse lookup by internal_id');

    assertTrue(SyncEngine::findMapping($pdo, $connIdB, SyncEngine::ENTITY_CASE, 'dtcase_phasea_1') === null
        || SyncEngine::findMappingByInternalId($pdo, $connIdB, SyncEngine::ENTITY_CASE, 'dtcase_phasea_1') === null,
        'reverse lookup isolated per connection');

    // last_seen_at stamping.
    $before = SyncEngine::findMapping($pdo, $connId, SyncEngine::ENTITY_CASE, TEST_EXTERNAL)['last_seen_at'];
    sleep(1);
    SyncEngine::touchMapping($pdo, $mappingId);
    $after = SyncEngine::findMapping($pdo, $connId, SyncEngine::ENTITY_CASE, TEST_EXTERNAL)['last_seen_at'];
    assertTrue($after !== null && $after !== $before, 'touchMapping updates last_seen_at');

    // reserve with metadata: safe passes, PHI-named keys rejected.
    $r5 = SyncEngine::reserveMapping($pdo, $connId, SyncEngine::ENTITY_PROVIDER, 'PROV-7', null, null, null, ['httpStatus' => 200, 'source' => 'unit']);
    assertTrue($r5['created'] === true, 'mapping metadata accepts safe keys');
    assertThrows(function () use ($pdo, $connId) {
        SyncEngine::reserveMapping($pdo, $connId, SyncEngine::ENTITY_PROVIDER, 'PROV-8', null, null, null, ['patientName' => 'x']);
    }, 'mapping metadata rejects PHI-named keys');

    // ------------------------------------------------------------------
    // 6. Sync runs
    // ------------------------------------------------------------------
    $runId = SyncEngine::beginRun($pdo, $connId, SyncEngine::RUN_SCHEDULED, '2026-09-01T00:00:00Z');
    assertTrue($runId > 0, 'beginRun returns run id');
    $run = $pdo->query("SELECT * FROM integration_sync_runs WHERE id = {$runId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue($run['status'] === 'running' && $run['cursor_start'] === '2026-09-01T00:00:00Z', 'run starts running with cursor');

    SyncEngine::incrementRunCounter($pdo, $runId, 'cases_seen', 3);
    SyncEngine::incrementRunCounter($pdo, $runId, 'cases_skipped');
    $run = $pdo->query("SELECT * FROM integration_sync_runs WHERE id = {$runId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue((int)$run['cases_seen'] === 3 && (int)$run['cases_skipped'] === 1, 'run counters increment');

    assertThrows(function () use ($pdo, $runId) {
        SyncEngine::incrementRunCounter($pdo, $runId, 'not_a_counter');
    }, 'unknown counter name rejected');

    SyncEngine::completeRun($pdo, $runId, '2026-09-18T00:00:00Z');
    $run = $pdo->query("SELECT * FROM integration_sync_runs WHERE id = {$runId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue($run['status'] === 'completed' && $run['cursor_end'] === '2026-09-18T00:00:00Z' && $run['finished_at'] !== null, 'completeRun marks completed with cursor_end');

    // Run with errors -> completed_with_errors.
    $runId2 = SyncEngine::beginRun($pdo, $connId, SyncEngine::RUN_MANUAL);
    SyncEngine::incrementRunCounter($pdo, $runId2, 'errors_count', 2);
    SyncEngine::completeRun($pdo, $runId2);
    $run2 = $pdo->query("SELECT * FROM integration_sync_runs WHERE id = {$runId2}")->fetch(PDO::FETCH_ASSOC);
    assertTrue($run2['status'] === 'completed_with_errors', 'errors_count>0 yields completed_with_errors');

    // Failed run.
    $runId3 = SyncEngine::beginRun($pdo, $connId, SyncEngine::RUN_BACKFILL);
    SyncEngine::failRun($pdo, $runId3, 'provider unreachable (HTTP 503)');
    $run3 = $pdo->query("SELECT * FROM integration_sync_runs WHERE id = {$runId3}")->fetch(PDO::FETCH_ASSOC);
    assertTrue($run3['status'] === 'failed' && strpos($run3['error_summary'], '503') !== false, 'failRun marks failed with summary');

    // Connection-level sync bookkeeping.
    IntegrationManager::markSyncFinished($pdo, $connId, true);
    $c = IntegrationManager::findConnection($pdo, $connId);
    assertTrue($c['last_success_at'] !== null && $c['last_error'] === null, 'markSyncFinished(success) stamps success');
    IntegrationManager::markSyncFinished($pdo, $connId, false, 'HTTP 429');
    $c = IntegrationManager::findConnection($pdo, $connId);
    assertTrue($c['status'] === 'error' && strpos($c['last_error'], '429') !== false, 'markSyncFinished(failure) sets error state');
    IntegrationManager::markSyncFinished($pdo, $connId, true);
    $c = IntegrationManager::findConnection($pdo, $connId);
    assertTrue($c['status'] === 'active' && $c['last_error'] === null, 'success revives connection to active');

    // ------------------------------------------------------------------
    // 7. Sync events + PHI enforcement
    // ------------------------------------------------------------------
    $evtId = SyncEngine::recordEvent($pdo, $runId, SyncEngine::ENTITY_CASE, TEST_EXTERNAL, SyncEngine::ACTION_DISCOVERED, null, 'External case ' . TEST_EXTERNAL . ' discovered', ['httpStatus' => 200]);
    assertTrue($evtId > 0, 'recordEvent inserts event');
    $evt = $pdo->query("SELECT * FROM integration_sync_events WHERE id = {$evtId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue((int)$evt['connection_id'] === $connId && $evt['sync_run_id'] == $runId, 'event derives connection_id from run');
    assertTrue($evt['detail_json'] !== null && strpos($evt['detail_json'], 'httpStatus') !== false, 'safe detail_json persisted');

    assertThrows(function () use ($pdo, $connId) {
        SyncEngine::recordEvent($pdo, 999999999, SyncEngine::ENTITY_CASE, 'x', SyncEngine::ACTION_DISCOVERED);
    }, 'recordEvent on missing run throws');

    assertThrows(function () use ($pdo, $runId) {
        SyncEngine::recordEvent($pdo, $runId, SyncEngine::ENTITY_CASE, 'x', SyncEngine::ACTION_ERROR, null, 'oops', ['patientName' => 'Jane Doe']);
    }, 'event detail rejects patientName key');
    assertThrows(function () use ($pdo, $runId) {
        SyncEngine::recordEvent($pdo, $runId, SyncEngine::ENTITY_CASE, 'x', SyncEngine::ACTION_ERROR, null, 'oops', ['api_key' => 'secret']);
    }, 'event detail rejects api_key key');
    assertThrows(function () use ($pdo, $runId) {
        SyncEngine::recordEvent($pdo, $runId, SyncEngine::ENTITY_CASE, 'x', SyncEngine::ACTION_ERROR, null, 'oops', ['dob' => '1970-01-01']);
    }, 'event detail rejects dob key');
    assertThrows(function () use ($pdo, $runId) {
        SyncEngine::recordEvent($pdo, $runId, SyncEngine::ENTITY_CASE, 'x', SyncEngine::ACTION_ERROR, null, 'oops', ['payload' => new CanonicalCase(['patientFirstName' => 'Jane'])]);
    }, 'event detail rejects object values (cannot dump DTOs)');
    assertThrows(function () use ($pdo, $runId) {
        SyncEngine::recordEvent($pdo, $runId, SyncEngine::ENTITY_CASE, 'x', SyncEngine::ACTION_ERROR, null, 'oops', ['blob' => str_repeat('a', 5000)]);
    }, 'event detail rejects oversized payload');

    // A CanonicalCase passed whole to json_encode must not leak PHI either.
    $cc = CanonicalCase::fromArray(['externalCaseId' => 'LC-9', 'patientFirstName' => 'Jane', 'instructions' => 'shade A2']);
    $encoded = json_encode($cc);
    assertTrue(strpos($encoded, 'Jane') === false && strpos($encoded, 'A2') === false && strpos($encoded, 'LC-9') !== false, 'CanonicalCase jsonSerialize exposes no PHI');
    assertTrue(strpos((string)$cc, 'Jane') === false && strpos((string)$cc, 'LC-9') !== false, 'CanonicalCase __toString exposes only external id');

    // ------------------------------------------------------------------
    // 8. Feature flag
    // ------------------------------------------------------------------
    global $FEATURE_DEFAULTS;
    assertTrue(array_key_exists('SHOW_PMS_INTEGRATIONS', $FEATURE_DEFAULTS), 'SHOW_PMS_INTEGRATIONS flag registered');
    assertTrue($FEATURE_DEFAULTS['SHOW_PMS_INTEGRATIONS'] === false, 'SHOW_PMS_INTEGRATIONS defaults OFF');
    assertTrue(is_bool(isFeatureEnabled('SHOW_PMS_INTEGRATIONS')), 'SHOW_PMS_INTEGRATIONS resolves via isFeatureEnabled');

    // ------------------------------------------------------------------
    // 9. Cascade cleanup proof (before we delete the fixture).
    // ------------------------------------------------------------------
    $pdo->exec("DELETE FROM integration_connections WHERE id = {$connIdB}");
    $leftoverCreds = (int)$pdo->query("SELECT COUNT(*) FROM integration_credentials WHERE connection_id = {$connIdB}")->fetchColumn();
    $leftoverMaps  = (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE connection_id = {$connIdB}")->fetchColumn();
    assertTrue($leftoverCreds === 0 && $leftoverMaps === 0, 'connection delete cascades credentials + mappings');

} finally {
    // Fixture cleanup - cascades credentials, mappings, runs, and events.
    if ($connId) {
        $pdo->exec("DELETE FROM integration_connections WHERE id IN ({$connId}, {$connIdB})");
    }
    $pdo->exec("DELETE FROM integration_connections WHERE provider IN ('" . TEST_PROVIDER . "', '" . TEST_PROVIDER_B . "')");
    IntegrationManager::clearAdapterFactories();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
