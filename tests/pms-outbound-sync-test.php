<?php
/**
 * Phase 2 two-way sync tests - outbound queue, adapter writes, conflict
 * detection, loop suppression. All fully local (mocked transport / mocked
 * adapter); no real Open Dental calls, no PHI.
 *
 *   A. Adapter unit tests (mocked transport):
 *      writes_disabled gating, PUT request shape + field whitelist,
 *      datetime validation, POST required fields, securityperms parsing,
 *      capability probe, unsupported_version classification, 429.
 *
 *   B. Queue tests (real DB, mocked adapter):
 *      enqueue gating (flag/opt-in/field_map), dedup, supersede-merge,
 *      practice isolation, PHI-key rejection, claim/deliver/conflict/
 *      skip/retry outcomes, provenance stamping.
 *
 *   C. Create path: enqueueCaseCreate gating, POST delivery + mapping
 *      attach, fingerprint idempotent recovery, already-mapped skip.
 *
 *   D. Loop suppression: an inbound echo of a delivered outbound write is
 *      finalized as suppressed, not re-applied.
 *
 * Requires the local development DB (port 3308) + ENCRYPTION_KEY.
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
// A. Adapter unit tests (mocked transport)
// =====================================================================
echo "--- adapter: write gating + request construction ---\n";

putenv('OPEN_DENTAL_DEVELOPER_KEY=DEVTESTKEY');
$OD_CREDS = ['customer_key' => 'CUSTTESTKEY'];

function obStubAdapter(array $response, ?array &$captured = null, bool $writesEnabled = true): OpenDentalAdapter {
    return new OpenDentalAdapter(function ($method, $url, $headers, $body = null) use ($response, &$captured) {
        $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return $response;
    }, $writesEnabled);
}
$labcaseResponse = ['code' => 200, 'body' => json_encode([
    'LabCaseNum' => 226, 'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
    'DateTimeSent' => '2026-10-14 09:30:00', 'DateTStamp' => '2026-10-14 12:00:00',
]), 'headers' => []];

// -- writes disabled -------------------------------------------------------
$captured = null;
$adapter = obStubAdapter($labcaseResponse, $captured, false);
try {
    $adapter->updateLabCase($OD_CREDS, 226, ['DateTimeSent' => '2026-10-14 09:30:00']);
    assertTrue('writes disabled: updateLabCase threw', false);
} catch (OpenDentalApiException $e) {
    assertEquals('writes disabled: category', 'writes_disabled', $e->category);
}
assertTrue('writes disabled: no request made', $captured === null);
try {
    $adapter->createLabCase($OD_CREDS, ['PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3]);
    assertTrue('writes disabled: createLabCase threw', false);
} catch (OpenDentalApiException $e) {
    assertEquals('writes disabled: create category', 'writes_disabled', $e->category);
}
assertTrue('writes disabled: still no request', $captured === null);

// -- updateLabCase request shape -------------------------------------------
$captured = null;
$adapter = obStubAdapter($labcaseResponse, $captured);
$out = $adapter->updateLabCase($OD_CREDS, 226, [
    'DateTimeSent'    => '2026-10-14 09:30:00',
    'Instructions'    => 'must be stripped',          // not whitelisted
    'PatNum'          => 99,                          // not whitelisted
]);
assertEquals('update: PUT method', 'PUT', $captured['method']);
assertTrue('update: /labcases/226 path', strpos($captured['url'], '/labcases/226') !== false);
$reqBody = json_decode($captured['body'], true);
assertEquals('update: whitelisted field sent', '2026-10-14 09:30:00', $reqBody['DateTimeSent'] ?? null);
assertTrue('update: Instructions stripped', !isset($reqBody['Instructions']));
assertTrue('update: PatNum stripped', !isset($reqBody['PatNum']));
assertEquals('update: response decoded', '2026-10-14 12:00:00', $out['DateTStamp'] ?? null);
$authHeader = implode("\n", $captured['headers']);
assertTrue('update: credentials not in URL', strpos($captured['url'], 'DEVTESTKEY') === false && strpos($captured['url'], 'CUSTTESTKEY') === false);

// -- updateLabCase validation ----------------------------------------------
$captured = null;
try {
    $adapter->updateLabCase($OD_CREDS, 226, ['DateTimeSent' => 'not-a-date']);
    assertTrue('update: invalid datetime rejected', false);
} catch (OpenDentalApiException $e) {
    assertEquals('update: bad datetime category', 'bad_request', $e->category);
}
try {
    $adapter->updateLabCase($OD_CREDS, 226, ['Instructions' => 'x']);
    assertTrue('update: empty whitelist rejected', false);
} catch (OpenDentalApiException $e) {
    assertEquals('update: empty body category', 'bad_request', $e->category);
}
try {
    $adapter->updateLabCase($OD_CREDS, 0, ['DateTimeSent' => '2026-10-14 09:30:00']);
    assertTrue('update: missing id rejected', false);
} catch (OpenDentalApiException $e) {
    assertEquals('update: bad id category', 'bad_request', $e->category);
}
assertTrue('update validation: no requests made', $captured === null);

// -- createLabCase ----------------------------------------------------------
$captured = null;
$adapter = obStubAdapter(['code' => 201, 'body' => json_encode([
    'LabCaseNum' => 555, 'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
    'DateTimeCreated' => '2026-10-14 09:30:00',
]), 'headers' => []], $captured);
$created = $adapter->createLabCase($OD_CREDS, [
    'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
    'DateTimeCreated' => '2026-10-14 09:30:00',
    'Instructions' => 'forbidden by create policy in queue but allowed by adapter whitelist',
]);
assertEquals('create: POST method', 'POST', $captured['method']);
assertTrue('create: /labcases path', strpos($captured['url'], '/labcases') !== false);
assertEquals('create: LabCaseNum returned', 555, $created['LabCaseNum'] ?? null);
try {
    $adapter->createLabCase($OD_CREDS, ['PatNum' => 33]); // missing Lab+Prov
    assertTrue('create: missing required rejected', false);
} catch (OpenDentalApiException $e) {
    assertEquals('create: required-field category', 'bad_request', $e->category);
}

// -- securityperms / capability probe --------------------------------------
$captured = null;
$adapter = obStubAdapter(['code' => 200, 'body' => json_encode([
    ['Permission' => 'ApiReadAll', 'HasPermission' => 'true'],
    ['Permission' => 'ApiAllOthers', 'HasPermission' => 'false'],
]), 'headers' => []], $captured);
$perms = $adapter->getApiPermissions($OD_CREDS);
assertEquals('securityperms: GET method', 'GET', $captured['method']);
assertTrue('securityperms: path', strpos($captured['url'], '/securityperms') !== false);
assertEquals('securityperms: ApiReadAll true', true, $perms['ApiReadAll'] ?? null);
assertEquals('securityperms: ApiAllOthers false', false, $perms['ApiAllOthers'] ?? null);
$cap = $adapter->checkLabCaseWriteCapability($OD_CREDS);
assertEquals('capability: not supported without ApiAllOthers', false, $cap['supported']);
assertEquals('capability: permission name', 'ApiAllOthers', $cap['permission']);
assertEquals('capability: min version', '24.4.19', $cap['min_od_version']);

$adapter = obStubAdapter(['code' => 200, 'body' => json_encode([
    ['Permission' => 'ApiAllOthers', 'HasPermission' => 'true'],
]), 'headers' => []], $captured);
assertEquals('capability: supported when granted', true, $adapter->checkLabCaseWriteCapability($OD_CREDS)['supported']);

// -- write error classification --------------------------------------------
$adapter = obStubAdapter(['code' => 400, 'body' => json_encode('The version of the dental office is not high enough to use this API method'), 'headers' => []], $captured);
try {
    $adapter->updateLabCase($OD_CREDS, 226, ['DateTimeSent' => '2026-10-14 09:30:00']);
    assertTrue('version: threw', false);
} catch (OpenDentalApiException $e) {
    assertEquals('version: unsupported_version category', 'unsupported_version', $e->category);
}
$adapter = obStubAdapter(['code' => 429, 'body' => '', 'headers' => ['retry-after' => '42']], $captured);
try {
    $adapter->updateLabCase($OD_CREDS, 226, ['DateTimeSent' => '2026-10-14 09:30:00']);
    assertTrue('429: threw', false);
} catch (OpenDentalApiException $e) {
    assertEquals('429: rate_limited category', 'rate_limited', $e->category);
    assertEquals('429: Retry-After captured', 42, $e->retryAfterSeconds);
}

// =====================================================================
// B. Queue lifecycle (real DB, mocked adapter)
// =====================================================================
echo "--- outbound queue: enqueue gating, dedup, supersede ---\n";

// Feature flags ON for this section (env-driven, test scope only).
putenv('SHOW_PMS_INTEGRATIONS=true');
putenv('PMS_OUTBOUND_SYNC=true');

// Fixture: owner + entitled practice + outbound-enabled connection + a
// mapped case (cases_cache row + finalized entity mapping).
$ownerId = 0;
$pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
               VALUES (:e, '', 'email', 'OB', 'Owner', 'admin', 1, 1, NOW())")
    ->execute(['e' => 'ob-sync-owner@test.local']);
$ownerId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), 'OB Sync Practice', {$ownerId}, NOW())");
$practiceId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, 'admin', 1)")
    ->execute(['p' => $practiceId, 'u' => $ownerId]);
getOrCreateSubscriptionForOwner($pdo, $ownerId);

// Approved field map ONLY: Sent -> DateTimeSent, Received -> DateTimeRecd.
// 'Delivered' deliberately unmapped (DateTimeChecked is OD's post-return
// quality check, not patient delivery). 'Custom-teststage' proves
// practice-specific column ids (Custom-*) can map too - the map keys on
// stable internal status identifiers, never display labels.
$outboundConfig = json_encode(['outbound' => [
    'enabled'        => true,
    'create_enabled' => true,
    'timezone'       => 'America/New_York',   // office TZ: UTC stamps convert
    'field_map'      => [
        'Sent To External Lab'       => 'DateTimeSent',
        'Received From External Lab' => 'DateTimeRecd',
        'Custom-teststage'           => 'DateTimeChecked',
    ],
]]);
$pdo->exec("INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, config_json, created_at)
            VALUES ({$practiceId}, 'open_dental', 'active', 1, " . $pdo->quote($outboundConfig) . ", NOW())");
$connId = (int)$pdo->lastInsertId();
IntegrationCredentials::store($pdo, $connId, 'customer_key', 'UNIT-CUST-KEY');

$caseId = 'OB-CASE-1';
$pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, status, status_changed_at, created_at, updated_at)
               VALUES (:c, :p, 'Sent To External Lab', '2026-10-14 09:30:00', NOW(), NOW())")
    ->execute([':c' => $caseId, ':p' => $practiceId]);
SyncEngine::reserveMapping($pdo, $connId, 'case', '226', 'case', $caseId, '33');
$mapping = SyncEngine::findMapping($pdo, $connId, 'case', '226');

// -- gating ------------------------------------------------------------------
// flag off -> nothing enqueues (simulate by clearing env)
putenv('PMS_OUTBOUND_SYNC=false');
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Originated', 'Sent To External Lab', $ownerId);
assertEquals('enqueue: flag off -> flag_off', 'flag_off', $r['reason']);
putenv('PMS_OUTBOUND_SYNC=true');

// unmapped status -> nothing
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Sent To External Lab', 'Designed', $ownerId);
assertEquals('enqueue: unmapped status', 'status_unmapped', $r['reason']);

// APPROVED MAP: 'Delivered' is deliberately NOT mapped to DateTimeChecked
// (OD's "checked" is post-return quality check, not patient delivery).
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Received From External Lab', 'Delivered', $ownerId);
assertEquals('enqueue: Delivered intentionally unmapped', 'status_unmapped', $r['reason']);

// office timezone is required before any write can queue
$pdo->exec("UPDATE integration_connections SET config_json = '" . addslashes(json_encode(['outbound' => ['enabled' => true, 'field_map' => ['Sent To External Lab' => 'DateTimeSent']]])) . "' WHERE id={$connId}");
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Originated', 'Sent To External Lab', $ownerId);
assertEquals('enqueue: timezone required', 'timezone_unconfigured', $r['reason']);
$pdo->exec("UPDATE integration_connections SET config_json = " . $pdo->quote($outboundConfig) . " WHERE id={$connId}");

// no-op transition -> nothing
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Sent To External Lab', 'Sent To External Lab', $ownerId);
assertEquals('enqueue: no-change transition', 'no_change', $r['reason']);

// case in another practice -> nothing
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), 'OB Other Practice', {$ownerId}, NOW())");
$practiceId2 = (int)$pdo->lastInsertId();
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId2, $caseId, 'Originated', 'Sent To External Lab', $ownerId);
assertEquals('enqueue: cross-practice rejected', 'unmapped', $r['reason']);

// outbound disabled on connection -> nothing
$pdo->exec("UPDATE integration_connections SET config_json = '{\"outbound\":{\"enabled\":false}}' WHERE id={$connId}");
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Originated', 'Sent To External Lab', $ownerId);
assertEquals('enqueue: connection opt-out', 'writes_disabled', $r['reason']);
$pdo->exec("UPDATE integration_connections SET config_json = " . $pdo->quote($outboundConfig) . " WHERE id={$connId}");

// PHI contract: payloads carrying instruction/note-named keys are refused.
try {
    IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, 'OB-CASE-UNMAPPED', [
        'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
        'Instructions' => 'PHI-adjacent text must never queue',
    ], $ownerId);
    assertTrue('enqueue: PHI-keyed payload rejected', false);
} catch (InvalidArgumentException $e) {
    assertTrue('enqueue: PHI-keyed payload rejected', true);
}

// -- enqueue + dedup + supersede ---------------------------------------------
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Originated', 'Sent To External Lab', $ownerId);
assertTrue('enqueue: mapped transition enqueued', $r['enqueued'] === true);
$ev1 = IntegrationOutbound::findEvent($pdo, (int)$r['id']);
assertEquals('enqueue: status queued', 'queued', $ev1['status']);
assertEquals('enqueue: operation', 'update_labcase', $ev1['operation']);
assertEquals('enqueue: external id', '226', $ev1['external_entity_id']);
assertEquals('enqueue: practice recorded', $practiceId, (int)$ev1['practice_id']);
assertEquals('enqueue: origin dentatrak', 'dentatrak', $ev1['origin']);
assertEquals('enqueue: actor recorded', $ownerId, (int)$ev1['actor_user_id']);
$p1 = json_decode($ev1['payload_json'], true);
// payload carries the TRANSITION timestamp converted to office-local
// (UTC 09:30 -> EDT 05:30), not enqueue/processing time
assertEquals('enqueue: payload field (office-local)', '2026-10-14 05:30:00', $p1['DateTimeSent'] ?? null);

// identical re-enqueue -> dedup collapse
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM integration_outbound_events WHERE connection_id={$connId}")->fetchColumn();
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Originated', 'Sent To External Lab', $ownerId);
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM integration_outbound_events WHERE connection_id={$connId}")->fetchColumn();
assertEquals('enqueue: identical change deduped', $countBefore, $countAfter);

// second mapped transition -> supersedes + merges payload
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Sent To External Lab', 'Received From External Lab', $ownerId);
assertTrue('enqueue: second transition enqueued', $r['enqueued'] === true);
$ev2 = IntegrationOutbound::findEvent($pdo, (int)$r['id']);
$p2 = json_decode($ev2['payload_json'], true);
assertTrue('supersede: merged DateTimeSent', isset($p2['DateTimeSent']));
assertTrue('supersede: merged DateTimeRecd', isset($p2['DateTimeRecd']));
$sup = (int)$pdo->query("SELECT COUNT(*) FROM integration_outbound_events WHERE connection_id={$connId} AND status='superseded'")->fetchColumn();
assertEquals('supersede: older queued event superseded', 1, $sup);
$queued = (int)$pdo->query("SELECT COUNT(*) FROM integration_outbound_events WHERE connection_id={$connId} AND status='queued'")->fetchColumn();
assertEquals('supersede: exactly one queued event', 1, $queued);

// =====================================================================
// Worker: mocked adapter delivering/contesting writes
// =====================================================================
echo "--- outbound worker: deliver / conflict / skip / retry ---\n";

$obBehavior = 'ok';
$obRemote = [
    'LabCaseNum' => 226, 'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
    'DateTimeSent' => '0001-01-01 00:00:00', 'DateTimeRecd' => '2000-01-01 00:00:00',
    'DateTimeChecked' => '0001-01-01 00:00:00', 'DateTStamp' => '2026-10-14 08:00:00',
];
$obWrites = [];   // PUT bodies the mock received
$obList   = [];   // listLabCases return for create-fingerprint scans
$obCreated = null;

IntegrationManager::registerAdapterFactory('open_dental', function ($conn) {
    return new class(null, true) extends OpenDentalAdapter {
        public function getLabCase(array $credentials, int $labCaseNum): ?array {
            global $obBehavior, $obRemote;
            if ($obBehavior === 'gone') {
                return null;
            }
            if ($obBehavior !== 'ok') {
                $e = new OpenDentalApiException(
                    $obBehavior === 'rate_limited' ? 'rate_limited' : $obBehavior,
                    $obBehavior === 'rate_limited' ? 429 : ($obBehavior === 'auth' ? 401 : 500),
                    '/labcases/' . $labCaseNum, 'mock');
                if ($obBehavior === 'rate_limited') {
                    $e->retryAfterSeconds = 7;
                }
                throw $e;
            }
            return $obRemote;
        }
        public function updateLabCase(array $credentials, int $labCaseNum, array $fields): array {
            global $obBehavior, $obRemote, $obWrites;
            if ($obBehavior !== 'ok') {
                throw new OpenDentalApiException($obBehavior, $obBehavior === 'auth' ? 401 : 500, '/labcases/' . $labCaseNum, 'mock');
            }
            $obWrites[] = $fields;
            foreach ($fields as $k => $v) {
                $obRemote[$k] = $v;
            }
            $obRemote['DateTStamp'] = '2026-10-14 12:00:00';
            return $obRemote;
        }
        public function listLabCases(array $credentials, array $filters = [], int $offset = 0): array {
            global $obList;
            return $obList;
        }
        public function createLabCase(array $credentials, array $fields): array {
            global $obBehavior, $obCreated;
            if ($obBehavior !== 'ok') {
                throw new OpenDentalApiException($obBehavior, $obBehavior === 'auth' ? 401 : 500, '/labcases', 'mock');
            }
            $obCreated = $fields;
            return ['LabCaseNum' => 555] + $fields + ['DateTStamp' => '2026-10-14 12:05:00'];
        }
        public function getPatient(array $credentials, int $patNum): ?array { return null; }
        public function getProviderByNum(array $credentials, int $provNum): ?array { return null; }
        public function getLaboratory(array $credentials, int $laboratoryNum): ?array { return null; }
        public function getAppointment(array $credentials, int $aptNum): ?array { return null; }
    };
});

// happy path: expected_remote is 'unknown' (no provenance), remote fields
// are sentinels -> conservative first-write permits the write.
$res = IntegrationOutbound::processEvent($pdo, (int)$ev2['id']);
assertEquals('worker: delivered outcome', 'delivered', $res['outcome']);
$ev2r = IntegrationOutbound::findEvent($pdo, (int)$ev2['id']);
assertEquals('worker: delivered status', 'delivered', $ev2r['status']);
assertTrue('worker: one PUT recorded', count($obWrites) === 1);
assertTrue('worker: PUT carried both fields',
    isset($obWrites[0]['DateTimeSent']) && isset($obWrites[0]['DateTimeRecd']));

// provenance stamped: outbound_sync marker + lifecycle values
$m = SyncEngine::findMapping($pdo, $connId, 'case', '226');
$meta = json_decode($m['metadata_json'], true);
assertEquals('provenance: outbound tstamp', '2026-10-14 12:00:00', $meta['outbound_sync']['last_tstamp'] ?? null);
assertEquals('provenance: date_time_sent (office-local)', '2026-10-14 05:30:00', $meta['date_time_sent'] ?? null);

// finalized event cannot re-process
$res = IntegrationOutbound::processEvent($pdo, (int)$ev2['id']);
assertEquals('worker: delivered event not reprocessed', 'duplicate', $res['outcome']);

// already-synced short-circuit at ENQUEUE: provenance equals the desired
// value (remote was already written with this exact timestamp)
$pdo->exec("UPDATE cases_cache SET status_changed_at = '2026-10-14 09:30:00' WHERE case_id = '{$caseId}'");
$obWrites = [];
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Delivered', 'Sent To External Lab', $ownerId);
assertEquals('enqueue: provenance-equal -> already_synced', 'already_synced', $r['reason']);

// worker-side in-sync path: remote already equals desired -> delivered,
// no PUT (crafted row - enqueue would have short-circuited this)
$pdo->exec("DELETE FROM integration_outbound_events WHERE connection_id={$connId} AND status='queued'");
$pdo->prepare("INSERT INTO integration_outbound_events
    (connection_id, practice_id, entity_type, internal_entity_id, external_entity_id, operation, payload_json, dedup_key)
    VALUES (:c, :p, 'case', 'OB-CASE-1', '226', 'update_labcase', '{\"DateTimeSent\":\"2026-10-14 05:30:00\"}', 'manual-insync-test')")
    ->execute([':c' => $connId, ':p' => $practiceId]);
$insyncId = (int)$pdo->lastInsertId();
$res = IntegrationOutbound::processEvent($pdo, $insyncId);
assertEquals('worker: already-in-sync delivered', 'delivered', $res['outcome']);
assertTrue('worker: no PUT for in-sync field', count($obWrites) === 0);

// conflict: remote lifecycle field populated with a DIFFERENT value than
// desired -> never overwritten; conflict parked for human reconciliation
$pdo->exec("UPDATE cases_cache SET status_changed_at = '2026-10-16 10:00:00' WHERE case_id = '{$caseId}'");
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Sent To External Lab', 'Received From External Lab', $ownerId);
$obRemote['DateTimeRecd'] = '2026-10-15 08:00:00'; // human wrote a real value
$res = IntegrationOutbound::processEvent($pdo, (int)$r['id']);
assertEquals('worker: remote drift -> conflict', 'conflict', $res['outcome']);
$evc = IntegrationOutbound::findEvent($pdo, (int)$r['id']);
assertEquals('worker: conflict status', 'conflict', $evc['status']);
assertTrue('worker: conflict is terminal', !empty($evc['processed_at']));
assertEquals('worker: remote value preserved', '2026-10-15 08:00:00', $obRemote['DateTimeRecd']);

// backward move must not rewrite an earlier lifecycle date: re-entering
// 'Sent To External Lab' with a new timestamp -> remote already holds the
// earlier stamp -> conflict, remote preserved
$obWrites = [];
$pdo->exec("UPDATE cases_cache SET status_changed_at = '2026-10-16 14:00:00' WHERE case_id = '{$caseId}'");
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Designed', 'Sent To External Lab', $ownerId);
assertTrue('backward: enqueue accepted', $r['enqueued'] === true);
$res = IntegrationOutbound::processEvent($pdo, (int)$r['id']);
assertEquals('backward: populated remote -> conflict', 'conflict', $res['outcome']);
assertTrue('backward: no PUT issued', count($obWrites) === 0);
assertEquals('backward: earlier stamp preserved', '2026-10-14 05:30:00', $obRemote['DateTimeSent']);

// sentinel variants all count as EMPTY -> writeable (T-separator too)
foreach (['0001-01-01 00:00:00', '0001-01-01T00:00:00', '2000-01-01 00:00:00', '', null] as $i => $sentinel) {
    $obRemote = [
        'LabCaseNum' => 226, 'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
        'DateTimeChecked' => $sentinel, 'DateTStamp' => '2026-10-14 12:00:00',
    ];
    $payloadJson = json_encode(['DateTimeChecked' => '2026-10-20 08:00:00']);
    $pdo->prepare("INSERT INTO integration_outbound_events
        (connection_id, practice_id, entity_type, internal_entity_id, external_entity_id, operation, payload_json, dedup_key)
        VALUES (:c, :p, 'case', 'OB-CASE-1', '226', 'update_labcase', :payload, :dedup)")
        ->execute([':c' => $connId, ':p' => $practiceId, ':payload' => $payloadJson, ':dedup' => 'sentinel-' . $i]);
    $sid = (int)$pdo->lastInsertId();
    $obWrites = [];
    $res = IntegrationOutbound::processEvent($pdo, $sid);
    assertEquals('sentinel ' . var_export($sentinel, true) . ' -> written', 'delivered', $res['outcome']);
    assertTrue('sentinel ' . var_export($sentinel, true) . ' PUT issued', count($obWrites) === 1);
}

// 404 remote -> skipped + source_deleted_at
$obBehavior = 'gone';
$pdo->exec("UPDATE cases_cache SET status_changed_at = '2026-10-17 10:00:00' WHERE case_id = '{$caseId}'");
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Received From External Lab', 'Sent To External Lab', $ownerId);
$res = IntegrationOutbound::processEvent($pdo, (int)$r['id']);
assertEquals('worker: remote 404 -> skipped', 'skipped', $res['outcome']);
$m = SyncEngine::findMapping($pdo, $connId, 'case', '226');
assertTrue('worker: source_deleted_at stamped', !empty($m['source_deleted_at']));
$obBehavior = 'ok';
$pdo->exec("UPDATE integration_entity_mappings SET source_deleted_at = NULL WHERE id=" . (int)$m['id']);

// 429 -> retry honoring Retry-After
$obBehavior = 'rate_limited';
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Delivered', 'Sent To External Lab', $ownerId);
$res = IntegrationOutbound::processEvent($pdo, (int)$r['id']);
assertEquals('worker: 429 -> retry', 'retry', $res['outcome']);
$ev429 = IntegrationOutbound::findEvent($pdo, (int)$r['id']);
assertEquals('worker: 429 row requeued', 'queued', $ev429['status']);
assertTrue('worker: 429 next_retry set', !empty($ev429['next_retry_at']));
assertTrue('worker: attempts incremented', (int)$ev429['attempts'] >= 1);
// retry window not yet due -> processDue does not claim it
$summary = IntegrationOutbound::processDue($pdo, 25);
$claimedThisRow = IntegrationOutbound::findEvent($pdo, (int)$r['id']);
assertEquals('worker: retry honored (not yet due)', 'queued', $claimedThisRow['status']);

// 401 -> terminal failed
$obBehavior = 'auth';
$pdo->exec("UPDATE integration_outbound_events SET next_retry_at = NULL WHERE id=" . (int)$r['id']);
$res = IntegrationOutbound::processEvent($pdo, (int)$r['id']);
assertEquals('worker: 401 -> failed', 'failed', $res['outcome']);

// eConnector offline -> transient retry, same class as inbound outages
$obBehavior = 'econnector_offline';
$pdo->exec("UPDATE cases_cache SET status_changed_at = '2026-10-19 09:00:00' WHERE case_id = '{$caseId}'");
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Originated', 'Received From External Lab', $ownerId);
$res = IntegrationOutbound::processEvent($pdo, (int)$r['id']);
assertEquals('worker: econnector offline -> retry', 'retry', $res['outcome']);
assertEquals('worker: offline row requeued', 'queued',
    IntegrationOutbound::findEvent($pdo, (int)$r['id'])['status']);
$obBehavior = 'ok';

// disabled connection at drain time -> skipped
$pdo->exec("UPDATE integration_connections SET config_json = '{\"outbound\":{\"enabled\":false}}' WHERE id={$connId}");
$pdo->exec("UPDATE cases_cache SET status_changed_at = '2026-10-18 10:00:00' WHERE case_id = '{$caseId}'");
// craft an event directly (enqueue is gated now) so the WORKER-side gate
// is what gets exercised:
$pdo->prepare("INSERT INTO integration_outbound_events
    (connection_id, practice_id, entity_type, internal_entity_id, external_entity_id, operation, payload_json, dedup_key)
    VALUES (:c, :p, 'case', 'OB-CASE-1', '226', 'update_labcase', '{\"DateTimeSent\":\"2026-10-18 10:00:00\"}', 'manual-skip-test')")
    ->execute([':c' => $connId, ':p' => $practiceId]);
$skipId = (int)$pdo->lastInsertId();
$res = IntegrationOutbound::processEvent($pdo, $skipId);
assertEquals('worker: disabled connection -> skipped', 'skipped', $res['outcome']);
$pdo->exec("UPDATE integration_connections SET config_json = " . $pdo->quote($outboundConfig) . " WHERE id={$connId}");

// practice mismatch: event practice != connection practice -> failed
$pdo->prepare("INSERT INTO integration_outbound_events
    (connection_id, practice_id, entity_type, internal_entity_id, external_entity_id, operation, payload_json, dedup_key)
    VALUES (:c, :p, 'case', 'OB-CASE-1', '226', 'update_labcase', '{\"DateTimeSent\":\"2026-10-18 11:00:00\"}', 'manual-mismatch-test')")
    ->execute([':c' => $connId, ':p' => $practiceId2]);
$mmId = (int)$pdo->lastInsertId();
$res = IntegrationOutbound::processEvent($pdo, $mmId);
assertEquals('worker: practice mismatch -> failed', 'failed', $res['outcome']);

// mapping removed between enqueue and drain -> skipped, never written
$pdo->exec("UPDATE cases_cache SET status_changed_at = '2026-10-19 10:00:00' WHERE case_id = '{$caseId}'");
$r = IntegrationOutbound::enqueueStatusTransition($pdo, $practiceId, $caseId, 'Designed', 'Received From External Lab', $ownerId);
$mapRow = SyncEngine::findMapping($pdo, $connId, 'case', '226');
$pdo->exec("UPDATE integration_entity_mappings SET internal_id = 'MOVED-ELSEWHERE' WHERE id=" . (int)$mapRow['id']);
$res = IntegrationOutbound::processEvent($pdo, (int)$r['id']);
assertEquals('worker: mapping no longer resolves -> skipped', 'skipped', $res['outcome']);
$pdo->exec("UPDATE integration_entity_mappings SET internal_id = '{$caseId}' WHERE id=" . (int)$mapRow['id']);

// =====================================================================
// C. create_labcase path
// =====================================================================
echo "--- create path: gating, delivery, fingerprint recovery ---\n";

$createCase = 'OB-CASE-NEW';
$pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, status, created_at, updated_at)
               VALUES (:c, :p, 'Originated', NOW(), NOW())")
    ->execute([':c' => $createCase, ':p' => $practiceId]);

// already-mapped case cannot enqueue a create
$r = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, $caseId, [
    'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
], $ownerId);
assertEquals('create: already-mapped rejected', 'already_mapped', $r['reason']);

// unmapped case enqueues
$r = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, $createCase, [
    'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
    'DateTimeDue' => '2026-10-20 00:00:00',
], $ownerId);
assertTrue('create: enqueued', $r['enqueued'] === true);
$createEv = IntegrationOutbound::findEvent($pdo, (int)$r['id']);
assertEquals('create: operation', 'create_labcase', $createEv['operation']);
$cp = json_decode($createEv['payload_json'], true);
assertTrue('create: fingerprint stamped', !empty($cp['DateTimeCreated']));

// deliver: POST called, mapping reserved+attached
$obList = [];
$res = IntegrationOutbound::processEvent($pdo, (int)$createEv['id']);
assertEquals('create: delivered', 'delivered', $res['outcome']);
assertTrue('create: POST sent required fields',
    $obCreated !== null && ($obCreated['PatNum'] ?? 0) === 33);
$newMap = SyncEngine::findMapping($pdo, $connId, 'case', '555');
assertTrue('create: mapping for new LabCaseNum', $newMap !== null);
assertEquals('create: mapping attached to case', $createCase, $newMap['internal_id'] ?? null);

// fingerprint recovery: POST "committed" remotely before a timeout; retry
// adopts the remote row instead of duplicating.
$createCase2 = 'OB-CASE-NEW2';
$pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, status, created_at, updated_at)
               VALUES (:c, :p, 'Originated', NOW(), NOW())")
    ->execute([':c' => $createCase2, ':p' => $practiceId]);
$r = IntegrationOutbound::enqueueCaseCreate($pdo, $practiceId, $createCase2, [
    'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
], $ownerId);
$createEv2 = IntegrationOutbound::findEvent($pdo, (int)$r['id']);
$fp = json_decode($createEv2['payload_json'], true)['DateTimeCreated'];
// remote list now contains a row whose DateTimeCreated == fingerprint
$obList = [[
    'LabCaseNum' => 777, 'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
    'DateTimeCreated' => $fp,
]];
$res = IntegrationOutbound::processEvent($pdo, (int)$createEv2['id']);
assertEquals('create: fingerprint recovery delivered', 'delivered', $res['outcome']);
$recMap = SyncEngine::findMapping($pdo, $connId, 'case', '777');
assertEquals('create: recovered mapping attached', $createCase2, $recMap['internal_id'] ?? null);
assertTrue('create: no duplicate POST on recovery', $obCreated['PatNum'] === 33); // unchanged from prior create

// create_enabled off -> queued create is skipped at drain time
$pdo->exec("UPDATE integration_connections SET config_json = '{\"outbound\":{\"enabled\":true,\"create_enabled\":false}}' WHERE id={$connId}");
$pdo->prepare("INSERT INTO integration_outbound_events
    (connection_id, practice_id, entity_type, internal_entity_id, external_entity_id, operation, payload_json, dedup_key)
    VALUES (:c, :p, 'case', 'OB-CASE-X', NULL, 'create_labcase', '{\"PatNum\":33,\"LaboratoryNum\":1,\"ProvNum\":3}', 'manual-create-off')")
    ->execute([':c' => $connId, ':p' => $practiceId]);
$coId = (int)$pdo->lastInsertId();
$res = IntegrationOutbound::processEvent($pdo, $coId);
assertEquals('create: create_enabled off -> skipped', 'skipped', $res['outcome']);
$pdo->exec("UPDATE integration_connections SET config_json = " . $pdo->quote($outboundConfig) . " WHERE id={$connId}");

// =====================================================================
// D. Loop suppression: inbound echo of our own write
// =====================================================================
echo "--- loop suppression: inbound echo ---\n";

// The delivered update left outbound_sync.last_tstamp = 2026-10-14 12:00:00
// on mapping '226'. An inbound event carrying that watermark is the echo.
$obBehavior = 'ok';
$obRemote = [
    'LabCaseNum' => 226, 'PatNum' => 33, 'LaboratoryNum' => 1, 'ProvNum' => 3,
    'DateTimeSent' => '2026-10-14 09:30:00', 'DateTimeRecd' => '2000-01-01 00:00:00',
    'DateTimeChecked' => '0001-01-01 00:00:00', 'DateTimeDue' => '0001-01-01 00:00:00',
    'Instructions' => '', 'DateTStamp' => '2026-10-14 12:00:00',
];
$inbound = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'echo-key-1', '2026-10-14 12:00:00');
$res = IntegrationEvents::processEvent($pdo, $inbound['id']);
assertEquals('echo: processed', 'processed', $res['outcome']);
assertTrue('echo: suppressed message', strpos($res['message'], 'Echo of DentaTrak') !== false, $res['message']);
// no local write: case row untouched (status still whatever we set)
$cs = $pdo->query("SELECT due_date, notes FROM cases_cache WHERE case_id='{$caseId}'")->fetch(PDO::FETCH_ASSOC);
assertTrue('echo: no local field writes', ($cs['due_date'] ?? null) === null && ($cs['notes'] ?? null) === null);

// =====================================================================
// Projection
// =====================================================================
echo "--- projection ---\n";
$conn = IntegrationManager::findConnection($pdo, $connId);
$pub = IntegrationManager::toPublicConnection($pdo, $conn);
assertTrue('projection: outbound block', isset($pub['outbound']));
assertEquals('projection: enabled', true, $pub['outbound']['enabled']);
assertTrue('projection: create_enabled', $pub['outbound']['create_enabled']);
assertTrue('projection: last_delivered_at set', !empty($pub['outbound']['last_delivered_at']));
assertTrue('projection: counts present', isset($pub['outbound']['pending']) && isset($pub['outbound']['conflicts']));

// =====================================================================
// Cleanup
// =====================================================================
$pdo->exec("DELETE FROM integration_outbound_events WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_external_events WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_entity_mappings WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_credentials WHERE connection_id={$connId}");
$pdo->exec("DELETE FROM integration_connections WHERE id={$connId}");
$pdo->exec("DELETE FROM cases_cache WHERE practice_id IN ({$practiceId}, {$practiceId2})");
$pdo->exec("DELETE FROM case_activity_log WHERE case_id LIKE 'OB-CASE%'");
$pdo->exec("DELETE FROM practices WHERE id IN ({$practiceId}, {$practiceId2})");
$pdo->exec("DELETE FROM practice_users WHERE user_id={$ownerId}");
$pdo->exec("DELETE FROM subscriptions WHERE owner_user_id={$ownerId}");
$pdo->exec("DELETE FROM users WHERE id={$ownerId}");

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
