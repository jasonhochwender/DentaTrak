<?php
/**
 * Phase 3 "Send to Open Dental" tests - create resolution, entity mapping
 * persistence, duplicate prevention, and UI-state surfaces. All local:
 * mocked transport / mocked adapter, real dev DB (port 3308) +
 * ENCRYPTION_KEY. No real Open Dental calls, no PHI.
 *
 *   A. Adapter read helpers: searchPatients / listProviders /
 *      listLaboratories / getProviderByNum / getLaboratory request shape
 *      and data-minimized projections.
 *   B. resolveCaseCreate: every gate/state the dialog drives off -
 *      flag_off, creates_disabled, case_missing, already_linked, pending,
 *      needs_mapping (each entity), timezone_unconfigured, ok payload.
 *   C. persistCreateSelections: patient/provider local-key mappings +
 *      laboratory_num connection config; nothing creates remote records.
 *   D. recordIdentityMappings: inbound-path local-key attachment.
 *   E. Duplicate prevention: rapid double enqueue, post-drain already
 *      -mapped skip, remote-collision conflict.
 *   F. updateOutboundConfig accepts laboratory_num.
 *   G. caseOutboundState projection for the card badge.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationEvents.php';
require_once __DIR__ . '/../api/integrations/IntegrationOutbound.php';
require_once __DIR__ . '/../api/integrations/adapters/OpenDentalAdapter.php';
require_once __DIR__ . '/../api/integrations/CanonicalCase.php';
require_once __DIR__ . '/../api/subscription-owner.php';

global $pdo;

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, ?string $detail = null): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$name}\n"; }
    else { $failed++; echo "FAIL: {$name}" . ($detail ? " ({$detail})" : "") . "\n"; }
}
function assertEquals(string $name, $expected, $actual, ?string $detail = null): void {
    assertTrue($name, $expected === $actual,
        ($detail ? $detail . ' - ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// =====================================================================
// A. Adapter read helpers (mocked transport)
// =====================================================================
echo "--- adapter: picker read endpoints ---\n";

putenv('OPEN_DENTAL_DEVELOPER_KEY=DEVTESTKEY');
$CREDS = ['customer_key' => 'CUSTTESTKEY'];

function crStubAdapter(array $response, ?array &$captured = null): OpenDentalAdapter {
    return new OpenDentalAdapter(function ($method, $url, $headers, $body = null) use ($response, &$captured) {
        $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return $response;
    }, true);
}

// searchPatients: GET /patients/Simple, whitelisted filters only,
// data-minimized projection (drops PHI-heavy fields entirely).
$captured = null;
$adapter = crStubAdapter(['code' => 200, 'body' => json_encode([
    ['PatNum' => 42, 'FName' => 'Ada', 'LName' => 'Smith', 'Birthdate' => '1980-03-04',
     'PatStatus' => 'Patient', 'Gender' => 'Female', 'SSN' => 'must-not-return', 'Address' => 'x'],
]), 'headers' => []], $captured);
$rows = $adapter->searchPatients($CREDS, ['LName' => 'Smith', 'FName' => 'Ada', 'Birthdate' => '1980-03-04', 'Hacked' => 'drop']);
assertEquals('search: GET method', 'GET', $captured['method']);
assertTrue('search: /patients/Simple path', strpos($captured['url'], '/patients/Simple') !== false);
assertTrue('search: LName param', strpos($captured['url'], 'LName=Smith') !== false);
assertTrue('search: Birthdate param', strpos($captured['url'], 'Birthdate=1980-03-04') !== false);
assertTrue('search: unknown filter stripped', strpos($captured['url'], 'Hacked') === false);
assertEquals('search: candidate PatNum', 42, $rows[0]['PatNum'] ?? null);
assertTrue('search: SSN not projected', !isset($rows[0]['SSN']));

// listProviders / listLaboratories
$captured = null;
$adapter = crStubAdapter(['code' => 200, 'body' => json_encode([
    ['ProvNum' => 7, 'Abbr' => 'SL', 'FName' => 'Sarah', 'LName' => 'Lexington', 'IsHidden' => 'false', 'SSN' => 'drop'],
]), 'headers' => []], $captured);
$provs = $adapter->listProviders($CREDS);
assertTrue('providers: /providers path', strpos($captured['url'], '/providers') !== false);
assertEquals('providers: ProvNum', 7, $provs[0]['ProvNum'] ?? null);
assertTrue('providers: SSN not projected', !isset($provs[0]['SSN']));

$adapter = crStubAdapter(['code' => 200, 'body' => json_encode([
    ['LaboratoryNum' => 3, 'Description' => 'Summit Ceramics', 'IsHidden' => 'false', 'Phone' => 'drop'],
]), 'headers' => []], $captured);
$labs = $adapter->listLaboratories($CREDS);
assertTrue('labs: /laboratories path', strpos($captured['url'], '/laboratories') !== false);
assertEquals('labs: description', 'Summit Ceramics', $labs[0]['Description'] ?? null);
assertTrue('labs: phone not projected', !isset($labs[0]['Phone']));

// single-record lookups return null on 404 (getOrNull path)
$adapter = crStubAdapter(['code' => 404, 'body' => '"Not found"', 'headers' => []], $captured);
assertEquals('getProviderByNum: 404 -> null', null, $adapter->getProviderByNum($CREDS, 999));
$adapter = crStubAdapter(['code' => 404, 'body' => '"Not found"', 'headers' => []], $captured);
assertEquals('getLaboratory: 404 -> null', null, $adapter->getLaboratory($CREDS, 999));

// =====================================================================
// Fixtures: owner + practice + create-capable connection
// =====================================================================
echo "--- resolveCaseCreate: gate + mapping states ---\n";

putenv('SHOW_PMS_INTEGRATIONS=true');
putenv('PMS_OUTBOUND_SYNC=true');

$pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
               VALUES (:e, '', 'email', 'CR', 'Owner', 'admin', 1, 1, NOW())")
    ->execute(['e' => 'cr-owner@test.local']);
$ownerId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), 'CR Practice', {$ownerId}, NOW())");
$practiceId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, 'admin', 1)")
    ->execute(['p' => $practiceId, 'u' => $ownerId]);
getOrCreateSubscriptionForOwner($pdo, $ownerId);
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), 'CR Other', {$ownerId}, NOW())");
$practiceId2 = (int)$pdo->lastInsertId();

$outboundConfig = json_encode(['outbound' => [
    'enabled'        => true,
    'create_enabled' => true,
    'timezone'       => 'America/New_York',
    'field_map'      => [
        'Sent To External Lab'       => 'DateTimeSent',
        'Received From External Lab' => 'DateTimeRecd',
    ],
]]);
$pdo->exec("INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, config_json, created_at)
            VALUES ({$practiceId}, 'open_dental', 'active', 1, " . $pdo->quote($outboundConfig) . ", NOW())");
$connId = (int)$pdo->lastInsertId();
IntegrationCredentials::store($pdo, $connId, 'customer_key', 'UNIT-CUST-KEY');

// Case with encrypted patient identity fields + a mapped lifecycle status.
$enc = function ($v) { return PIIEncryption::encrypt($v); };
$mkCase = function (string $cid, int $pid, array $extra = []) use ($pdo, $enc) {
    $cols = array_merge([
        'status' => 'Sent To External Lab', 'status_changed_at' => '2026-10-14 22:30:00',
        'due_date' => '2026-10-21', 'patient_first_name' => $enc('Ada'),
        'patient_last_name' => $enc('Smith'), 'patient_dob' => $enc('1980-03-04'),
        'dentist_name' => $enc('Sarah Lexington'),
    ], $extra);
    $set = [];
    $vals = [':c' => $cid, ':p' => $pid];
    foreach ($cols as $k => $v) {
        $set[] = "{$k} = :{$k}";
        $vals[":{$k}"] = $v;
    }
    $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, " . implode(',', array_keys($cols)) .
        ", created_at, updated_at) VALUES (:c, :p, " .
        implode(',', array_map(function ($k) { return ":{$k}"; }, array_keys($cols))) . ", NOW(), NOW())")
        ->execute($vals);
};

$mkCase('CR-CASE-1', $practiceId);

// -- gates -------------------------------------------------------------------
putenv('PMS_OUTBOUND_SYNC=false');
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-1');
assertEquals('resolve: flag off', 'flag_off', $r['reason']);
assertEquals('resolve: flag off state', 'unavailable', $r['state']);
putenv('PMS_OUTBOUND_SYNC=true');

$pdo->exec("UPDATE integration_connections SET config_json = '{\"outbound\":{\"enabled\":true,\"create_enabled\":false}}' WHERE id={$connId}");
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-1');
assertEquals('resolve: create_enabled off', 'creates_disabled', $r['reason']);
$pdo->exec("UPDATE integration_connections SET config_json = " . $pdo->quote($outboundConfig) . " WHERE id={$connId}");

$mkCase('CR-CASE-OTHER', $practiceId2);
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId2, 'CR-CASE-OTHER');
assertEquals('resolve: no connection in other practice', 'creates_disabled', $r['reason']);
// case belonging to practice2 resolved against practice1 -> not found there
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-OTHER');
assertEquals('resolve: cross-practice case -> case_missing', 'case_missing', $r['reason']);

// -- needs_mapping: nothing resolvable yet ------------------------------------
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-1');
assertEquals('resolve: needs_mapping reason', 'needs_mapping', $r['reason']);
assertEquals('resolve: unlinked state', 'unlinked', $r['state']);
assertTrue('resolve: needs patient', in_array('patient', $r['needs'], true));
assertTrue('resolve: needs provider', in_array('provider', $r['needs'], true));
assertTrue('resolve: needs laboratory', in_array('laboratory', $r['needs'], true));
assertEquals('resolve: patient hint lname', 'Smith', $r['patient_search']['lname'] ?? null);
assertEquals('resolve: patient hint dob', '1980-03-04', $r['patient_search']['birthdate'] ?? null);
assertEquals('resolve: provider hint name', 'Sarah Lexington', $r['provider_name'] ?? null);

// -- persist selections -> mappings drive re-resolution -----------------------
echo "--- persistCreateSelections -> resolved payload ---\n";
IntegrationOutbound::persistCreateSelections($pdo, $connId, 'CR-CASE-1', $practiceId, [
    'pat_num' => 42, 'prov_num' => 7, 'laboratory_num' => 3,
]);
// patient + provider land as local_key mappings; lab lands on the connection
$patMap = SyncEngine::findMapping($pdo, $connId, 'patient', '42');
assertTrue('persist: patient mapping created', $patMap !== null);
assertTrue('persist: patient keyed by local_key',
    isset($patMap['internal_id']) && strpos((string)$patMap['internal_id'], 'pat_') === 0);
$provMap = SyncEngine::findMapping($pdo, $connId, 'provider', '7');
assertTrue('persist: provider mapping created', $provMap !== null);
assertTrue('persist: provider keyed by local_key',
    isset($provMap['internal_id']) && strpos((string)$provMap['internal_id'], 'prov_') === 0);
$cfg = json_decode(
    $pdo->query("SELECT config_json FROM integration_connections WHERE id={$connId}")->fetchColumn(), true);
assertEquals('persist: laboratory_num in config', 3, $cfg['outbound']['laboratory_num'] ?? null);

// re-resolve: everything resolved, payload complete
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-1');
assertTrue('resolve: ok after persist', $r['ok'] === true);
assertEquals('resolve: PatNum', 42, $r['payload']['PatNum'] ?? null);
assertEquals('resolve: ProvNum', 7, $r['payload']['ProvNum'] ?? null);
assertEquals('resolve: LaboratoryNum', 3, $r['payload']['LaboratoryNum'] ?? null);
assertEquals('resolve: DateTimeDue wall-clock', '2026-10-21 00:00:00', $r['payload']['DateTimeDue'] ?? null);
// status 'Sent To External Lab' maps to DateTimeSent; status_changed_at is
// UTC 22:30 -> office-local EDT 18:30
assertEquals('resolve: lifecycle field office-local', '2026-10-14 18:30:00', $r['payload']['DateTimeSent'] ?? null);

// a second case with the SAME patient/provider identity auto-resolves
// through the shared local_key mappings - lab comes from the connection
$mkCase('CR-CASE-2', $practiceId, ['status' => 'Originated']);
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-2');
assertTrue('resolve: sibling case auto-resolves', $r['ok'] === true);
assertEquals('resolve: sibling PatNum', 42, $r['payload']['PatNum'] ?? null);
// unmapped status -> no lifecycle field stamped
assertTrue('resolve: unmapped status -> no lifecycle field', !isset($r['payload']['DateTimeRecd']));

// timezone gate still applies to an otherwise-complete resolution
$pdo->exec("UPDATE integration_connections SET config_json = '{\"outbound\":{\"enabled\":true,\"create_enabled\":true,\"laboratory_num\":3}}' WHERE id={$connId}");
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-1');
assertEquals('resolve: timezone required', 'timezone_unconfigured', $r['reason']);
$pdo->exec("UPDATE integration_connections SET config_json = " . $pdo->quote($outboundConfig) . " WHERE id={$connId}");

// -- already_linked / pending / attention states ------------------------------
// imported case (existing case mapping) can never be re-created
$mkCase('CR-CASE-IMPORTED', $practiceId);
SyncEngine::reserveMapping($pdo, $connId, 'case', '900', 'case', 'CR-CASE-IMPORTED', '42');
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-IMPORTED');
assertEquals('resolve: imported case -> already_linked', 'already_linked', $r['reason']);
assertEquals('resolve: already_linked state', 'linked', $r['state']);
assertEquals('resolve: external id surfaced', '900', $r['external_id'] ?? null);
$enq = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, 'CR-CASE-IMPORTED', [
    'PatNum' => 42, 'LaboratoryNum' => 3, 'ProvNum' => 7], $ownerId);
assertEquals('enqueue: imported case rejected', 'already_mapped', $enq['reason']);

// pending: queued create event -> state pending (double-click path)
$enq = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, 'CR-CASE-1', [
    'PatNum' => 42, 'LaboratoryNum' => 3, 'ProvNum' => 7,
    'DateTimeDue' => '2026-10-21 00:00:00'], $ownerId);
assertTrue('enqueue: first send queued', $enq['enqueued'] === true);
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-1');
assertEquals('resolve: queued event -> pending', 'pending', $r['reason']);
assertEquals('resolve: pending state', 'pending', $r['state']);

// -- worker: create delivery + duplicate drains --------------------------------
echo "--- create drain: delivery, dup-skip, collision ---\n";

$crBehavior = 'ok';
$crList = [];
IntegrationManager::registerAdapterFactory('open_dental', function ($conn) {
    return new class(null, true) extends OpenDentalAdapter {
        public function listLabCases(array $credentials, array $filters = [], int $offset = 0): array {
            global $crList;
            return $crList;
        }
        public function createLabCase(array $credentials, array $fields): array {
            global $crBehavior;
            if ($crBehavior !== 'ok') {
                throw new OpenDentalApiException($crBehavior, $crBehavior === 'auth' ? 401 : 500, '/labcases', 'mock');
            }
            return ['LabCaseNum' => 800] + $fields + ['DateTStamp' => '2026-10-14 20:00:00'];
        }
    };
});

$res = IntegrationOutbound::processEvent($pdo, (int)$enq['id']);
assertEquals('create: delivered', 'delivered', $res['outcome']);
$m = SyncEngine::findMapping($pdo, $connId, 'case', '800');
assertEquals('create: mapping attached', 'CR-CASE-1', $m['internal_id'] ?? null);
$meta = json_decode($m['metadata_json'], true);
assertEquals('create: provenance tstamp', '2026-10-14 20:00:00', $meta['outbound_sync']['last_tstamp'] ?? null);

// case card projection: linked case reports its external id
$st = IntegrationOutbound::caseOutboundState($pdo, $practiceId, 'CR-CASE-1');
assertTrue('state: linked reported', ($st['linked'] ?? false) === true);
assertEquals('state: external id', '800', $st['external_id'] ?? null);

// double-click race: a second create for a case whose mapping landed
// between resolve and drain must skip at the worker, never re-POST.
$enq2 = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, 'CR-CASE-2', [
    'PatNum' => 42, 'LaboratoryNum' => 3, 'ProvNum' => 7], $ownerId);
assertTrue('dup: second case enqueues', $enq2['enqueued'] === true);
// simulate a parallel path having already linked this case to remote 801
SyncEngine::reserveMapping($pdo, $connId, 'case', '801', 'case', 'CR-CASE-2', '42');
$res = IntegrationOutbound::processEvent($pdo, (int)$enq2['id']);
assertEquals('dup: already-mapped -> skipped', 'skipped', $res['outcome']);

// remote collision: OD hands back a LabCaseNum already mapped to ANOTHER
// case (800 -> CR-CASE-1 above) -> conflict, linkage never re-pointed
$mkCase('CR-CASE-4', $practiceId);
$enq4 = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, 'CR-CASE-4', [
    'PatNum' => 42, 'LaboratoryNum' => 3, 'ProvNum' => 7], $ownerId);
$crList = []; // fingerprint won't match -> POST proceeds -> returns 800
$res = IntegrationOutbound::processEvent($pdo, (int)$enq4['id']);
assertEquals('collision: conflict not steal', 'conflict', $res['outcome']);
$m = SyncEngine::findMapping($pdo, $connId, 'case', '800');
assertEquals('collision: original linkage kept', 'CR-CASE-1', $m['internal_id'] ?? null);

// auth failure on create -> terminal failed (no retry loop on 401)
$mkCase('CR-CASE-5', $practiceId);
$enq5 = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, 'CR-CASE-5', [
    'PatNum' => 42, 'LaboratoryNum' => 3, 'ProvNum' => 7], $ownerId);
$crBehavior = 'auth';
$res = IntegrationOutbound::processEvent($pdo, (int)$enq5['id']);
assertEquals('create: 401 -> terminal failed', 'failed', $res['outcome']);
$crBehavior = 'ok';

// =====================================================================
// D. recordIdentityMappings (inbound-path local keys)
// =====================================================================
echo "--- recordIdentityMappings ---\n";
$canonical = new CanonicalCase();
$canonical->externalCaseId = '901';
$canonical->externalPatientId = '42';
$canonical->externalProviderId = '7';
$canonical->patientFirstName = 'Ada';
$canonical->patientLastName = 'Smith';
$canonical->patientDob = '1980-03-04';
$canonical->providerName = 'Sarah Lexington';
// identity keys already attached -> idempotent no-op, no new rows
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE connection_id={$connId}")->fetchColumn();
IntegrationOutbound::recordIdentityMappings($pdo, $connId, $canonical);
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE connection_id={$connId}")->fetchColumn();
assertEquals('identity: existing mappings untouched', $countBefore, $countAfter);
// patient already mapped to pat_* key for Ada Smith stays put
$patMap = SyncEngine::findMapping($pdo, $connId, 'patient', '42');
assertTrue('identity: existing key preserved',
    strpos((string)$patMap['internal_id'], 'pat_') === 0);

// new remote identity -> reserved + keyed
$canonical2 = new CanonicalCase();
$canonical2->externalCaseId = '902';
$canonical2->externalPatientId = '55';
$canonical2->externalProviderId = null; // missing -> skipped, not fatal
$canonical2->patientFirstName = 'Bob';
$canonical2->patientLastName = 'Jones';
$canonical2->patientDob = '1975-01-01';
IntegrationOutbound::recordIdentityMappings($pdo, $connId, $canonical2);
$pm2 = SyncEngine::findMapping($pdo, $connId, 'patient', '55');
assertTrue('identity: new patient reserved + keyed',
    $pm2 !== null && strpos((string)$pm2['internal_id'], 'pat_') === 0);
// and that key resolves: a DentaTrak-native case for "Bob Jones" matches
$mkCase('CR-CASE-6', $practiceId, [
    'patient_first_name' => $enc('Bob'), 'patient_last_name' => $enc('Jones'),
    'patient_dob' => $enc('1975-01-01'), 'dentist_name' => $enc('Sarah Lexington'),
]);
$r = IntegrationOutbound::resolveCaseCreate($pdo, $practiceId, 'CR-CASE-6');
assertTrue('identity: Bob Jones resolves PatNum via local key',
    ($r['resolved']['pat_num'] ?? null) === 55 || ($r['payload']['PatNum'] ?? null) === 55);

// =====================================================================
// F. updateOutboundConfig accepts laboratory_num
// =====================================================================
IntegrationOutbound::updateOutboundConfig($pdo, $connId, ['laboratory_num' => 9]);
$cfg = json_decode(
    $pdo->query("SELECT config_json FROM integration_connections WHERE id={$connId}")->fetchColumn(), true);
assertEquals('config: laboratory_num updated', 9, $cfg['outbound']['laboratory_num'] ?? null);
assertTrue('config: field_map preserved', isset($cfg['outbound']['field_map']['Sent To External Lab']));
assertEquals('config: create_enabled preserved', true, $cfg['outbound']['create_enabled'] ?? null);
IntegrationOutbound::updateOutboundConfig($pdo, $connId, ['laboratory_num' => 3]);

// =====================================================================
// G. caseOutboundState (card badge projection)
// =====================================================================
$st = IntegrationOutbound::caseOutboundState($pdo, $practiceId, 'CR-CASE-4');
assertEquals('state: conflict event surfaced', 'conflict', $st['event_status'] ?? null);
$st = IntegrationOutbound::caseOutboundState($pdo, $practiceId, 'CR-CASE-5');
assertEquals('state: failed event surfaced', 'failed', $st['event_status'] ?? null);
$st = IntegrationOutbound::caseOutboundState($pdo, $practiceId, 'CR-CASE-OTHER');
assertEquals('state: untracked case -> null', null, $st);
// practice isolation: a case from practice2 queried under practice1
$st = IntegrationOutbound::caseOutboundState($pdo, $practiceId, 'CR-CASE-OTHER');
assertEquals('state: cross-practice -> null', null, $st);

// =====================================================================
// Cleanup
// =====================================================================
$pdo->exec("DELETE FROM integration_outbound_events WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_external_events WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_entity_mappings WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_credentials WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_connections WHERE id={$connId}");
$pdo->exec("DELETE FROM cases_cache WHERE practice_id IN ({$practiceId}, {$practiceId2})");
$pdo->exec("DELETE FROM case_activity_log WHERE case_id LIKE 'CR-CASE%'");
$pdo->exec("DELETE FROM practices WHERE id IN ({$practiceId}, {$practiceId2})");
$pdo->exec("DELETE FROM practice_users WHERE user_id={$ownerId}");
$pdo->exec("DELETE FROM subscriptions WHERE owner_user_id={$ownerId}");
$pdo->exec("DELETE FROM users WHERE id={$ownerId}");

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
