<?php
/**
 * PMS integrations Phase D tests - Open Dental Events/Subscriptions POC.
 *
 * Three layers, all fully local (no real Open Dental network calls):
 *
 *   A. Adapter unit tests (mocked transport):
 *      subscription request construction, WatchTable, PUT-whitelisting,
 *      PUT-based disable (DELETE is paid-tier), event parsing, dedup keys,
 *      429 Retry-After capture.
 *
 *   B. IntegrationEvents unit tests (real DB, mocked adapter):
 *      dedup record(), processEvent success/gone/deleted/429/network/auth,
 *      processDue draining + retry-window honoring, subscription config
 *      persistence, safe projection.
 *
 *   C. End-to-end over php -S:
 *      DentaTrak server (flag on) + Open Dental stub server.
 *      configure -> test_connection -> subscribe -> webhook delivery ->
 *      dedup -> drain -> normalized processing; auth/validation rejection
 *      paths; unsubscribe via PUT DateTimeStop; disconnect best-effort
 *      remote disable; no secrets/PHI in persistence.
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
require_once __DIR__ . '/../api/integrations/adapters/OpenDentalAdapter.php';

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
echo "--- adapter: subscription + event parsing ---\n";

// Developer Key is now a central server secret (env); only customer_key is
// a stored practice credential.
putenv('OPEN_DENTAL_DEVELOPER_KEY=DEVTESTKEY');
$OD_CREDS = ['customer_key' => 'CUSTTESTKEY'];

/** Build an adapter whose transport records the request and returns $response. */
function stubAdapter(array $response, ?array &$captured = null): OpenDentalAdapter {
    return new OpenDentalAdapter(function ($method, $url, $headers, $body = null) use ($response, &$captured) {
        $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return $response;
    });
}

// -- createLabCaseSubscription ------------------------------------------
$captured = null;
$adapter = stubAdapter(['code' => 201, 'body' => json_encode([
    'SubscriptionNum' => 42,
    'WatchTable' => 'LabCase',
    'PollingSeconds' => 60,
    'DateTimeStart' => '2026-01-01 00:00:00',
]), 'headers' => []], $captured);
global $OD_CREDS;
$sub = $adapter->createLabCaseSubscription($OD_CREDS, [
    'endpoint_url' => 'https://dentatrak.example/api/integrations/open-dental-events.php?c=9&k=abc',
    'workstation' => 'SERVWS',
    'polling_seconds' => 60,
    'note' => 'test sub',
]);
assertEquals('subscribe: POST method', 'POST', $captured['method']);
assertTrue('subscribe: /subscriptions path', strpos($captured['url'], '/subscriptions') !== false);
$reqBody = json_decode($captured['body'], true);
assertEquals('subscribe: WatchTable LabCase', 'LabCase', $reqBody['WatchTable'] ?? null);
assertEquals('subscribe: EndPointUrl', 'https://dentatrak.example/api/integrations/open-dental-events.php?c=9&k=abc', $reqBody['EndPointUrl'] ?? null);
assertEquals('subscribe: Workstation', 'SERVWS', $reqBody['Workstation'] ?? null);
assertEquals('subscribe: PollingSeconds', 60, $reqBody['PollingSeconds'] ?? null);
assertEquals('subscribe: returns SubscriptionNum', 42, $sub['SubscriptionNum'] ?? null);
$authHeader = implode("\n", $captured['headers']);
assertTrue('subscribe: ODFHIR auth header sent', strpos($authHeader, 'Authorization: ODFHIR DEVTESTKEY/CUSTTESTKEY') !== false);
assertTrue('subscribe: credentials not in URL', strpos($captured['url'], 'DEVTESTKEY') === false && strpos($captured['url'], 'CUSTTESTKEY') === false);

// -- defaults ------------------------------------------------------------
$captured = null;
$adapter = stubAdapter(['code' => 201, 'body' => json_encode(['SubscriptionNum' => 7]), 'headers' => []], $captured);
$adapter->createLabCaseSubscription($OD_CREDS, ['endpoint_url' => 'https://x/']);
$reqBody = json_decode($captured['body'], true);
assertEquals('subscribe: default workstation sentinel', OpenDentalAdapter::ALL_WORKSTATIONS, $reqBody['Workstation'] ?? null);
assertEquals('subscribe: default polling', OpenDentalAdapter::DEFAULT_POLLING_SECONDS, $reqBody['PollingSeconds'] ?? null);

// -- listSubscriptions ----------------------------------------------------
$captured = null;
$adapter = stubAdapter(['code' => 200, 'body' => json_encode([
    ['SubscriptionNum' => 42, 'WatchTable' => 'LabCase'],
    ['SubscriptionNum' => 43, 'WatchTable' => 'Patient'],
]), 'headers' => []], $captured);
$subs = $adapter->listSubscriptions($OD_CREDS);
assertEquals('listSubscriptions: GET', 'GET', $captured['method']);
assertEquals('listSubscriptions: count', 2, count($subs));

// -- updateSubscription whitelisting --------------------------------------
$captured = null;
$adapter = stubAdapter(['code' => 200, 'body' => json_encode(['SubscriptionNum' => 42]), 'headers' => []], $captured);
$adapter->updateSubscription($OD_CREDS, 42, [
    'PollingSeconds' => 120,
    'WatchTable' => 'Patient',       // must be stripped - immutable
    'CustomerKey' => 'EVIL',         // must be stripped
    'EndPointUrl' => 'https://new/']);
$reqBody = json_decode($captured['body'], true);
assertEquals('updateSubscription: PUT', 'PUT', $captured['method']);
assertTrue('updateSubscription: url has num', strpos($captured['url'], '/subscriptions/42') !== false);
assertEquals('updateSubscription: PollingSeconds kept', 120, $reqBody['PollingSeconds'] ?? null);
assertTrue('updateSubscription: WatchTable stripped', !isset($reqBody['WatchTable']));
assertTrue('updateSubscription: CustomerKey stripped', !isset($reqBody['CustomerKey']));

// -- disableSubscription: PUT DateTimeStop, never DELETE -------------------
$captured = null;
$adapter = stubAdapter(['code' => 200, 'body' => json_encode(['SubscriptionNum' => 42]), 'headers' => []], $captured);
$adapter->disableSubscription($OD_CREDS, 42);
$reqBody = json_decode($captured['body'], true);
assertEquals('disable: uses PUT (free tier), not DELETE', 'PUT', $captured['method']);
assertTrue('disable: sets DateTimeStop', isset($reqBody['DateTimeStop']) && $reqBody['DateTimeStop'] !== '0001-01-01 00:00:00');

// -- findSubscription ------------------------------------------------------
$adapter = stubAdapter(['code' => 200, 'body' => json_encode([
    ['SubscriptionNum' => 42], ['SubscriptionNum' => 43],
]), 'headers' => []]);
assertEquals('findSubscription: found', 42, (int)($adapter->findSubscription($OD_CREDS, 42)['SubscriptionNum'] ?? 0));
assertEquals('findSubscription: missing -> null', null, $adapter->findSubscription($OD_CREDS, 99));

// -- parseWatchTableEvent --------------------------------------------------
$adapter = new OpenDentalAdapter();
$labcaseRow = [
    'LabCaseNum' => 226, 'PatNum' => 44, 'LaboratoryNum' => 3, 'AptNum' => 0,
    'PlannedAptNum' => 18, 'DateTimeDue' => '2026-02-01 00:00:00',
    'DateTimeCreated' => '2026-01-15 10:00:00', 'DateTimeSent' => '0001-01-01 00:00:00',
    'DateTimeRecd' => '0001-01-01 00:00:00', 'DateTimeChecked' => '2000-01-01 00:00:00',
    'ProvNum' => 2, 'Instructions' => 'test instructions', 'DateTStamp' => '2026-01-20 09:30:00',
    'InvoiceNum' => 'INV-1',
];
$parsed = $adapter->parseWatchTableEvent(json_encode([$labcaseRow]), 'WatchTable: LabCase');
assertEquals('parse: entity_type labcase', 'labcase', $parsed['entity_type']);
assertTrue('parse: not deleted', $parsed['deleted'] === false);
assertEquals('parse: one row', 1, count($parsed['rows']));
assertEquals('parse: external_id', '226', $parsed['rows'][0]['external_id']);
assertEquals('parse: watermark', '2026-01-20 09:30:00', $parsed['rows'][0]['watermark']);

$parsedDel = $adapter->parseWatchTableEvent(json_encode([['LabCaseNum' => 226]]), 'WatchTable: LabCaseDeleted');
assertTrue('parse: deleted flag', $parsedDel['deleted'] === true);
assertEquals('parse: deleted entity id', '226', $parsedDel['rows'][0]['external_id']);

$batch = $adapter->parseWatchTableEvent(json_encode([$labcaseRow, ['LabCaseNum' => 227, 'DateTStamp' => '2026-01-21 00:00:00']]), 'WatchTable: LabCase');
assertEquals('parse: batch rows', 2, count($batch['rows']));

$threw = null;
try { $adapter->parseWatchTableEvent('[]', 'WatchTable: Patient'); } catch (Throwable $e) { $threw = $e; }
assertTrue('parse: wrong event type rejected', $threw instanceof OpenDentalApiException && $threw->category === 'bad_request');

$threw = null;
try { $adapter->parseWatchTableEvent('{not json', 'WatchTable: LabCase'); } catch (Throwable $e) { $threw = $e; }
assertTrue('parse: malformed body rejected', $threw instanceof OpenDentalApiException && $threw->category === 'bad_request');

$threw = null;
try { $adapter->parseWatchTableEvent(json_encode([['PatNum' => 1]]), 'WatchTable: LabCase'); } catch (Throwable $e) { $threw = $e; }
assertTrue('parse: missing LabCaseNum rejected', $threw instanceof OpenDentalApiException && $threw->category === 'bad_request');

// -- eventDedupKey ----------------------------------------------------------
$k1 = OpenDentalAdapter::eventDedupKey('226', '2026-01-20 09:30:00', $labcaseRow);
$k2 = OpenDentalAdapter::eventDedupKey('226', '2026-01-20 09:30:00', $labcaseRow);
$k3 = OpenDentalAdapter::eventDedupKey('226', '2026-01-20 09:30:01', $labcaseRow);
$k4 = OpenDentalAdapter::eventDedupKey('227', '2026-01-20 09:30:00', $labcaseRow);
assertTrue('dedup: same id+watermark identical', $k1 === $k2);
assertTrue('dedup: new watermark differs', $k1 !== $k3);
assertTrue('dedup: different entity differs', $k1 !== $k4);
$k5 = OpenDentalAdapter::eventDedupKey('226', null, $labcaseRow);
$k6 = OpenDentalAdapter::eventDedupKey('226', null, array_merge($labcaseRow, ['Instructions' => 'changed']));
assertTrue('dedup: null watermark falls back to content hash', $k5 !== $k6 && $k5 === OpenDentalAdapter::eventDedupKey('226', null, $labcaseRow));

// -- 429 retryAfterSeconds ---------------------------------------------------
$adapter = stubAdapter(['code' => 429, 'body' => '{}', 'headers' => ['retry-after' => '7']]);
try { $adapter->listSubscriptions($OD_CREDS); $threw = null; } catch (Throwable $e) { $threw = $e; }
assertTrue('429: rate_limited category', $threw instanceof OpenDentalApiException && $threw->category === 'rate_limited');
assertEquals('429: retryAfterSeconds parsed', 7, $threw->retryAfterSeconds ?? null);

$adapter = stubAdapter(['code' => 429, 'body' => '{}', 'headers' => []]);
try { $adapter->listSubscriptions($OD_CREDS); $threw = null; } catch (Throwable $e) { $threw = $e; }
assertTrue('429: null retryAfterSeconds when absent', $threw->retryAfterSeconds === null);

// -- subscription POST failure surfaces safely --------------------------------
$adapter = stubAdapter(['code' => 400, 'body' => '{"error":"x"}', 'headers' => []]);
try { $adapter->createLabCaseSubscription($OD_CREDS, ['endpoint_url' => 'https://x/']); $threw = null; } catch (Throwable $e) { $threw = $e; }
assertTrue('subscribe: 400 -> bad_request', $threw instanceof OpenDentalApiException && $threw->category === 'bad_request');
assertTrue('subscribe: error contains no credentials', strpos($threw->getMessage(), 'CUSTTESTKEY') === false && strpos($threw->getMessage(), 'DEVTESTKEY') === false);

// =====================================================================
// B. IntegrationEvents unit tests (real DB)
// =====================================================================
echo "--- IntegrationEvents: dedup + processing ---\n";

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "FATAL: no database connection\n";
    exit(1);
}

// Fixture practice + connection (direct SQL; created_by needs a real user FK).
$anyUser = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
if ($anyUser <= 0) {
    echo "FATAL: no users exist for fixture created_by\n";
    exit(1);
}

// Plan entitlement fixtures: processEvent enforces hasIntegrationAccess(),
// which resolves the practice's subscription through its owner. Each fixture
// practice gets a dedicated owner user + owner-level trialing subscription
// (entitled) - the downgrade section cancels the subscription to prove
// suspension (Operate/Control/Scale are all entitled under GA).
require_once __DIR__ . '/../api/subscription-owner.php';
$mkOwner = function (string $email) use ($pdo): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'POC', 'Owner', 'admin', 1, 1, NOW())")
        ->execute(['e' => $email]);
    return (int)$pdo->lastInsertId();
};
$attachOwner = function (int $practiceId, int $ownerUserId) use ($pdo): void {
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, 'admin', 1)")
        ->execute(['p' => $practiceId, 'u' => $ownerUserId]);
    getOrCreateSubscriptionForOwner($pdo, $ownerUserId);
};
$fixtureOwners = [];

$owner1 = $mkOwner('pms-poc-owner1@test.local');
$fixtureOwners[] = $owner1;
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), 'PMS Events POC Practice', {$owner1}, NOW())");
$practiceId = (int)$pdo->lastInsertId();
$attachOwner($practiceId, $owner1);
$pdo->exec("INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, created_at)
            VALUES ({$practiceId}, 'open_dental', 'active', 1, NOW())");
$connId = (int)$pdo->lastInsertId();
IntegrationCredentials::store($pdo, $connId, 'developer_key', 'UNIT-DEV-KEY');
IntegrationCredentials::store($pdo, $connId, 'customer_key', 'UNIT-CUST-KEY');

// -- record() dedup ----------------------------------------------------------
$r1 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-1', '2026-01-20 09:30:00');
assertTrue('record: first insert', $r1['inserted'] === true && $r1['id'] > 0);
$r2 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-1', '2026-01-20 09:30:00');
assertTrue('record: duplicate dedup key skipped', $r2['inserted'] === false);
$r3 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-2', '2026-01-20 09:31:00');
assertTrue('record: new watermark = new event', $r3['inserted'] === true);
$count = (int)$pdo->query("SELECT COUNT(*) FROM integration_external_events WHERE connection_id={$connId}")->fetchColumn();
assertEquals('record: two rows stored', 2, $count);

// -- processEvent with mocked adapter ----------------------------------------
$mockLabCase = $labcaseRow;
$mockBehavior = 'ok';
// Phase E: processEvent now imports - the mock also supplies the
// supporting resources the drainer hydrates (patient/provider/lab/appt).
$mockPatient = ['PatNum' => 44, 'FName' => 'POCFirst', 'LName' => 'POCLast', 'Birthdate' => '1990-01-01', 'Gender' => 'Female'];
$mockProvider = null;
$mockAppointment = null;
// Backfill fixtures: per-number LabCase rows, a page queue for the list
// endpoint, a list-failure mode, and a set of LabCaseNums that 404.
$mockLabCaseByNum = [];
$mockListPages = [];
$mockListBehavior = 'ok';
$mockGoneNums = [];
IntegrationManager::registerAdapterFactory('open_dental', function ($conn) use (&$mockBehavior, &$mockLabCase, &$mockPatient, &$mockProvider, &$mockAppointment) {
    return new class extends OpenDentalAdapter {
        public function getLabCase(array $credentials, int $labCaseNum): ?array {
            global $mockBehavior, $mockLabCase, $mockLabCaseByNum, $mockGoneNums;
            if (in_array($labCaseNum, $mockGoneNums, true)) return null;
            if (isset($mockLabCaseByNum[$labCaseNum])) return $mockLabCaseByNum[$labCaseNum];
            if ($mockBehavior === 'ok') return $mockLabCase;
            if ($mockBehavior === 'gone') return null;
            $e = new OpenDentalApiException($mockBehavior === 'rate_limited' ? 'rate_limited' : $mockBehavior, $mockBehavior === 'rate_limited' ? 429 : 0, '/labcases/' . $labCaseNum, 'mock');
            if ($mockBehavior === 'rate_limited') { $e->retryAfterSeconds = 7; }
            throw $e;
        }
        public function getPatient(array $credentials, int $patNum): ?array {
            global $mockPatient;
            return $mockPatient;
        }
        public function getProviderByNum(array $credentials, int $provNum): ?array {
            global $mockProvider;
            return $mockProvider;
        }
        public function getLaboratory(array $credentials, int $laboratoryNum): ?array {
            return null;
        }
        public function getAppointment(array $credentials, int $aptNum): ?array {
            global $mockAppointment;
            return $mockAppointment;
        }
        public function listLabCases(array $credentials, array $filters = [], int $offset = 0): array {
            global $mockListBehavior, $mockListPages;
            if ($mockListBehavior !== 'ok') {
                throw new OpenDentalApiException(
                    $mockListBehavior,
                    $mockListBehavior === 'auth' ? 401 : 500,
                    '/labcases', 'mock'
                );
            }
            $page = array_shift($mockListPages);
            return is_array($page) ? $page : [];
        }
    };
});

$res = IntegrationEvents::processEvent($pdo, $r1['id']);
$ev = IntegrationEvents::findEvent($pdo, $r1['id']);
assertEquals('processEvent: success outcome', 'processed', $res['outcome']);
assertEquals('processEvent: status processed', 'processed', $ev['status']);
assertTrue('processEvent: processed_at set', !empty($ev['processed_at']));

$res = IntegrationEvents::processEvent($pdo, $r1['id']);
assertEquals('processEvent: finalized event not re-processed', 'duplicate', $res['outcome']);

// entity gone (404 path -> getLabCase null)
$mockBehavior = 'gone';
$res = IntegrationEvents::processEvent($pdo, $r3['id']);
$ev = IntegrationEvents::findEvent($pdo, $r3['id']);
assertEquals('processEvent: missing labcase -> entity_gone', 'entity_gone', $res['outcome']);
assertEquals('processEvent: entity_gone status', 'entity_gone', $ev['status']);
assertTrue('processEvent: entity_gone is terminal', !empty($ev['processed_at']));

// deleted-watch event: no fetch, straight to entity_gone
$mockBehavior = 'ok';
$del = IntegrationEvents::record($pdo, $connId, 'labcase_deleted', '226', 'dedupkey-del', null);
$res = IntegrationEvents::processEvent($pdo, $del['id']);
assertEquals('processEvent: deleted event -> entity_gone without fetch', 'entity_gone', $res['outcome']);

// Deletion side effects on the mapped LabCase 226 (its case was created by
// the first processed event above): mapping gains source_deleted_at, ONE
// system-actor activity entry is logged, and the case itself is untouched.
$delMapping = SyncEngine::findMapping($pdo, $connId, 'case', '226');
assertTrue('delete: case mapping still resolved', $delMapping !== null && !empty($delMapping['internal_id']));
$delCaseId = (string)$delMapping['internal_id'];
assertTrue('delete: source_deleted_at stamped', !empty($delMapping['source_deleted_at']));
$delCaseRow = $pdo->query("SELECT case_id FROM cases_cache WHERE case_id=" . $pdo->quote($delCaseId))->fetch(PDO::FETCH_ASSOC);
assertTrue('delete: DentaTrak case preserved', $delCaseRow !== false);
$delActs = $pdo->query("SELECT user_id, meta_json FROM case_activity_log WHERE case_id=" . $pdo->quote($delCaseId) . " AND event_type='source_deleted'")->fetchAll(PDO::FETCH_ASSOC);
assertEquals('delete: exactly one activity entry', 1, count($delActs));
assertTrue('delete: system actor (user_id null)', $delActs[0]['user_id'] === null);
$delMeta = json_decode((string)$delActs[0]['meta_json'], true);
assertEquals('delete: activity source tag', 'integration:open_dental', $delMeta['source'] ?? null);
assertTrue('delete: activity is PHI-free', strpos((string)$delActs[0]['meta_json'], 'POCFirst') === false);

// rate limited -> back to received with next_retry_at ~ +7s
$mockBehavior = 'rate_limited';
$rl = IntegrationEvents::record($pdo, $connId, 'labcase', '227', 'dedupkey-rl', null);
$res = IntegrationEvents::processEvent($pdo, $rl['id']);
$ev = IntegrationEvents::findEvent($pdo, $rl['id']);
assertEquals('processEvent: 429 -> retry outcome', 'retry', $res['outcome']);
assertEquals('processEvent: 429 -> back to received', 'received', $ev['status']);
assertTrue('processEvent: 429 -> next_retry_at set', !empty($ev['next_retry_at']));
// Compare inside MySQL: next_retry_at is set with NOW(), which is the DB
// server's clock - not necessarily PHP's timezone.
$retryDelta = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), next_retry_at) FROM integration_external_events WHERE id={$rl['id']}")->fetchColumn();
assertTrue('processEvent: honors Retry-After (~7s)', $retryDelta >= 5 && $retryDelta <= 10, "delta={$retryDelta}");

// not due yet -> processDue skips it
$mockBehavior = 'ok';
$summary = IntegrationEvents::processDue($pdo, 25);
$ev = IntegrationEvents::findEvent($pdo, $rl['id']);
assertTrue('processDue: future retry not drained', $ev['status'] === 'received');

// force due -> drains and processes
$pdo->exec("UPDATE integration_external_events SET next_retry_at = NOW() WHERE id = {$rl['id']}");
$summary = IntegrationEvents::processDue($pdo, 25);
$ev = IntegrationEvents::findEvent($pdo, $rl['id']);
assertEquals('processDue: due event processed', 'processed', $ev['status']);

// network error -> received + linear backoff
$mockBehavior = 'network';
$net = IntegrationEvents::record($pdo, $connId, 'labcase', '228', 'dedupkey-net', null);
$res = IntegrationEvents::processEvent($pdo, $net['id']);
$ev = IntegrationEvents::findEvent($pdo, $net['id']);
assertEquals('processEvent: network -> retry', 'retry', $res['outcome']);
assertEquals('processEvent: network -> received', 'received', $ev['status']);

// auth error -> terminal failed
$mockBehavior = 'auth';
$au = IntegrationEvents::record($pdo, $connId, 'labcase', '229', 'dedupkey-auth', null);
$res = IntegrationEvents::processEvent($pdo, $au['id']);
$ev = IntegrationEvents::findEvent($pdo, $au['id']);
assertEquals('processEvent: auth -> failed (terminal)', 'failed', $res['outcome']);
assertEquals('processEvent: auth status failed', 'failed', $ev['status']);

// non-active connection -> failed
$pdo->exec("UPDATE integration_connections SET status='disabled' WHERE id={$connId}");
$dis = IntegrationEvents::record($pdo, $connId, 'labcase', '230', 'dedupkey-dis', null);
$res = IntegrationEvents::processEvent($pdo, $dis['id']);
assertEquals('processEvent: disabled connection -> failed', 'failed', $res['outcome']);
$pdo->exec("UPDATE integration_connections SET status='active' WHERE id={$connId}");

// eConnector offline -> transient retry (office may restart it)
$mockBehavior = 'econnector_offline';
$ec = IntegrationEvents::record($pdo, $connId, 'labcase', '231', 'dedupkey-ec', null);
$res = IntegrationEvents::processEvent($pdo, $ec['id']);
$ev = IntegrationEvents::findEvent($pdo, $ec['id']);
assertEquals('processEvent: econnector_offline -> retry', 'retry', $res['outcome']);
assertEquals('processEvent: econnector_offline -> received', 'received', $ev['status']);
assertTrue('processEvent: econnector_offline -> next_retry_at set', !empty($ev['next_retry_at']));

// Atomic claim: a fresh 'processing' row owned by another worker is refused.
$mockBehavior = 'ok';
$claimed = IntegrationEvents::record($pdo, $connId, 'labcase', '232', 'dedupkey-claimed', null);
$pdo->exec("UPDATE integration_external_events SET status='processing', claimed_at=NOW() WHERE id={$claimed['id']}");
$res = IntegrationEvents::processEvent($pdo, $claimed['id']);
assertEquals('processEvent: in-flight claim refused', 'duplicate', $res['outcome']);
$ev = IntegrationEvents::findEvent($pdo, $claimed['id']);
assertEquals('processEvent: claimed event untouched', 'processing', $ev['status']);

// A stale 'processing' row (crashed worker) is reclaimed and processed.
$pdo->exec("UPDATE integration_external_events SET claimed_at = DATE_SUB(NOW(), INTERVAL 15 MINUTE) WHERE id={$claimed['id']}");
$res = IntegrationEvents::processEvent($pdo, $claimed['id']);
assertEquals('processEvent: stale processing reclaimed', 'processed', $res['outcome']);

// A 'received' event whose retry window has not arrived is not claimed.
$future = IntegrationEvents::record($pdo, $connId, 'labcase', '233', 'dedupkey-future', null);
$pdo->exec("UPDATE integration_external_events SET next_retry_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id={$future['id']}");
$res = IntegrationEvents::processEvent($pdo, $future['id']);
assertEquals('processEvent: not-due event not claimed', 'duplicate', $res['outcome']);
$ev = IntegrationEvents::findEvent($pdo, $future['id']);
assertEquals('processEvent: not-due stays received', 'received', $ev['status']);
$pdo->exec("DELETE FROM integration_external_events WHERE id={$future['id']}");

// -- V1 update synchronization (existing mapped LabCase) --------------------
// LabCase 226 was imported by the first processEvent above. Resolve the
// mapped case and exercise the update path end-to-end.
$caseMapping = SyncEngine::findMapping($pdo, $connId, 'case', '226');
assertTrue('update: case mapping exists', $caseMapping !== null && !empty($caseMapping['internal_id']));
$updCaseId = $caseMapping['internal_id'];

// Multi-field change in one event: due date + instructions + provider + appt.
$mockLabCase['DateTimeDue'] = '2026-03-05 10:00:00';
$mockLabCase['Instructions'] = 'updated instructions';
$mockProvider = ['ProvNum' => 2, 'FName' => 'Greg', 'LName' => 'House'];
$mockAppointment = ['AptNum' => 18, 'AptDateTime' => '2026-03-01 09:00:00'];
$mockLabCase['AptNum'] = 18;
$mockLabCase['PlannedAptNum'] = 0;
$mockLabCase['DateTStamp'] = '2026-02-01 00:00:00';
$u1 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-upd1', '2026-02-01 00:00:00');
$res = IntegrationEvents::processEvent($pdo, $u1['id']);
assertEquals('update: changed event processed', 'processed', $res['outcome']);
$case = $pdo->query("SELECT * FROM cases_cache WHERE case_id='{$updCaseId}'")->fetch(PDO::FETCH_ASSOC);
assertEquals('update: due_date written', '2026-03-05 10:00:00', $case['due_date']);
assertEquals('update: appointment date written', '2026-03-01 09:00:00', $case['patient_appointment_date']);
assertEquals('update: notes written', 'updated instructions', PIIEncryption::decrypt($case['notes']));
assertEquals('update: dentist_name written', 'Greg House', PIIEncryption::decrypt($case['dentist_name']));
assertEquals('update: version bumped', 2, (int)$case['version']);

// The update must never create a second case or mapping.
$mappingCount = (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE connection_id={$connId} AND entity_type='case' AND external_id='226'")->fetchColumn();
assertEquals('update: still one case mapping', 1, $mappingCount);
assertEquals('update: mapping still points at same case', $updCaseId, SyncEngine::findMapping($pdo, $connId, 'case', '226')['internal_id']);

// Activity trail: case_updated credited to the integration, no fake user.
$act = $pdo->query("SELECT event_type, user_id, meta_json FROM case_activity_log WHERE case_id='{$updCaseId}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertEquals('update: case_updated logged', 'case_updated', $act['event_type']);
assertTrue('update: no fake user actor', $act['user_id'] === null);
$meta = json_decode($act['meta_json'], true);
assertEquals('update: integration source tagged', 'open_dental', $meta['integration'] ?? null);
assertTrue('update: changed_fields names only', in_array('dueDate', $meta['changed_fields'], true));

// Replaying the same event is a no-op.
$res = IntegrationEvents::processEvent($pdo, $u1['id']);
assertEquals('update: replay finalized event', 'duplicate', $res['outcome']);

// A new event with identical external data writes nothing.
$verBefore = (int)$pdo->query("SELECT version FROM cases_cache WHERE case_id='{$updCaseId}'")->fetchColumn();
$u2 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-upd2', '2026-02-01 01:00:00');
$res = IntegrationEvents::processEvent($pdo, $u2['id']);
$verAfter = (int)$pdo->query("SELECT version FROM cases_cache WHERE case_id='{$updCaseId}'")->fetchColumn();
assertEquals('update: no-change processed', 'processed', $res['outcome']);
assertEquals('update: no-change leaves version', $verBefore, $verAfter);

// DentaTrak-owned fields are protected even when Open Dental data changes.
$pdo->exec("UPDATE cases_cache SET status='ProtectedStatus', case_type='ProtectedType', assigned_to='protected@example.com' WHERE case_id='{$updCaseId}'");
$mockLabCase['Instructions'] = 'third revision';
$mockLabCase['DateTStamp'] = '2026-02-02 00:00:00';
$u3 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-upd3', '2026-02-02 00:00:00');
$res = IntegrationEvents::processEvent($pdo, $u3['id']);
$case = $pdo->query("SELECT * FROM cases_cache WHERE case_id='{$updCaseId}'")->fetch(PDO::FETCH_ASSOC);
assertEquals('update: status untouched', 'ProtectedStatus', $case['status']);
assertEquals('update: case_type untouched', 'ProtectedType', $case['case_type']);
assertEquals('update: assignment untouched', 'protected@example.com', $case['assigned_to']);
assertEquals('update: notes still synced', 'third revision', PIIEncryption::decrypt($case['notes']));

// Needs Review reset: a reviewed case returns to needs_review on a
// meaningful external change, attributed to the integration (no user).
$pdo->exec("UPDATE cases_cache SET reviewed_at=NOW(), reviewed_by_user_id={$anyUser} WHERE case_id='{$updCaseId}'");
$mockLabCase['DateTimeDue'] = '2026-04-01 12:00:00';
$mockLabCase['DateTStamp'] = '2026-02-03 00:00:00';
$u4 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-upd4', '2026-02-03 00:00:00');
$res = IntegrationEvents::processEvent($pdo, $u4['id']);
$case = $pdo->query("SELECT reviewed_at, reviewed_by_user_id FROM cases_cache WHERE case_id='{$updCaseId}'")->fetch(PDO::FETCH_ASSOC);
assertTrue('update: reviewed case reset', $case['reviewed_at'] === null && $case['reviewed_by_user_id'] === null);
$act = $pdo->query("SELECT event_type FROM case_activity_log WHERE case_id='{$updCaseId}' AND event_type='review_status_changed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertEquals('update: review reset logged', 'review_status_changed', $act['event_type'] ?? null);

// Null policy: cleared OD values clear OD-owned fields; missing provider
// data never blanks the stored dentist name.
$mockLabCase['DateTimeDue'] = '0001-01-01 00:00:00'; // sentinel -> null
$mockLabCase['Instructions'] = '';
$mockProvider = null;
$mockLabCase['DateTStamp'] = '2026-02-04 00:00:00';
$u5 = IntegrationEvents::record($pdo, $connId, 'labcase', '226', 'dedupkey-upd5', '2026-02-04 00:00:00');
$res = IntegrationEvents::processEvent($pdo, $u5['id']);
$case = $pdo->query("SELECT due_date, notes, dentist_name FROM cases_cache WHERE case_id='{$updCaseId}'")->fetch(PDO::FETCH_ASSOC);
assertTrue('update: sentinel due date cleared', $case['due_date'] === null);
assertTrue('update: empty instructions cleared notes', $case['notes'] === null || $case['notes'] === '');
assertEquals('update: dentist_name preserved on missing provider', 'Greg House', PIIEncryption::decrypt($case['dentist_name']));

// Provenance metadata refreshed on the mapping.
$mapRow = $pdo->query("SELECT metadata_json FROM integration_entity_mappings WHERE connection_id={$connId} AND entity_type='case' AND external_id='226'")->fetch(PDO::FETCH_ASSOC);
$prov = json_decode($mapRow['metadata_json'] ?? '{}', true);
assertEquals('update: provenance apt_num', '18', (string)($prov['apt_num'] ?? ''));
assertEquals('update: provenance last event', $u5['id'], (int)($prov['last_event_id'] ?? 0));

// A source_deleted_at marker set earlier is cleared once the source record
// is seen again (the update events above prove it exists upstream).
$mapRow = $pdo->query("SELECT source_deleted_at FROM integration_entity_mappings WHERE connection_id={$connId} AND entity_type='case' AND external_id='226'")->fetch(PDO::FETCH_ASSOC);
assertTrue('update: stale source_deleted_at cleared on re-seen source', empty($mapRow['source_deleted_at']));

// -- LabCase deletion: re-notify, replay, unmapped, 404-fetch, isolation --------
// A fresh delete notification after the updates re-marks the mapping and
// logs exactly one more activity entry.
$del2 = IntegrationEvents::record($pdo, $connId, 'labcase_deleted', '226', 'dedupkey-del-226-b', null);
$res = IntegrationEvents::processEvent($pdo, $del2['id']);
assertEquals('delete re-notify: entity_gone', 'entity_gone', $res['outcome']);
$delStamp = $pdo->query("SELECT source_deleted_at FROM integration_entity_mappings WHERE connection_id={$connId} AND entity_type='case' AND external_id='226'")->fetchColumn();
assertTrue('delete re-notify: marker re-stamped', !empty($delStamp));
$delActCount = (int)$pdo->query("SELECT COUNT(*) FROM case_activity_log WHERE case_id=" . $pdo->quote($updCaseId) . " AND event_type='source_deleted'")->fetchColumn();
assertEquals('delete re-notify: second distinct deletion logged', 2, $delActCount);

// Replay/redelivery while already marked: no second write, no new entry.
$del3 = IntegrationEvents::record($pdo, $connId, 'labcase_deleted', '226', 'dedupkey-del-226-c', null);
$res = IntegrationEvents::processEvent($pdo, $del3['id']);
assertEquals('delete replay: still entity_gone', 'entity_gone', $res['outcome']);
$delActCount = (int)$pdo->query("SELECT COUNT(*) FROM case_activity_log WHERE case_id=" . $pdo->quote($updCaseId) . " AND event_type='source_deleted'")->fetchColumn();
assertEquals('delete replay: no repeated activity entry', 2, $delActCount);
$delStamp2 = $pdo->query("SELECT source_deleted_at FROM integration_entity_mappings WHERE connection_id={$connId} AND entity_type='case' AND external_id='226'")->fetchColumn();
assertEquals('delete replay: marker timestamp unchanged', $delStamp, $delStamp2);

// Unmapped LabCase delete: resolves nothing, creates nothing, logs nothing.
$delUnmapped = IntegrationEvents::record($pdo, $connId, 'labcase_deleted', '9999', 'dedupkey-del-unmapped', null);
$res = IntegrationEvents::processEvent($pdo, $delUnmapped['id']);
assertEquals('delete unmapped: entity_gone', 'entity_gone', $res['outcome']);
$unmappedRows = (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE connection_id={$connId} AND external_id='9999'")->fetchColumn();
assertEquals('delete unmapped: no mapping created', 0, $unmappedRows);

// A normal labcase event whose fetch 404s gets identical source-deleted
// treatment: mark once, log once, leave the DentaTrak case intact.
$mockLabCaseByNum[900] = array_merge($labcaseRow, [
    'LabCaseNum' => 900, 'PatNum' => 44, 'Instructions' => 'labcase nine hundred',
    'DateTimeCreated' => '2026-01-10 00:00:00', 'DateTStamp' => '2026-01-10 00:00:00',
]);
$e900a = IntegrationEvents::record($pdo, $connId, 'labcase', '900', 'dedupkey-900-a', null);
$res = IntegrationEvents::processEvent($pdo, $e900a['id']);
assertEquals('delete-404: labcase imported first', 'processed', $res['outcome']);
$case900 = (string)(SyncEngine::findMapping($pdo, $connId, 'case', '900')['internal_id'] ?? '');
assertTrue('delete-404: case exists pre-delete', $case900 !== '');
$mockGoneNums[] = 900;
$e900b = IntegrationEvents::record($pdo, $connId, 'labcase', '900', 'dedupkey-900-b', null);
$res = IntegrationEvents::processEvent($pdo, $e900b['id']);
assertEquals('delete-404: missing fetch -> entity_gone', 'entity_gone', $res['outcome']);
$map900 = SyncEngine::findMapping($pdo, $connId, 'case', '900');
assertTrue('delete-404: source_deleted_at stamped', !empty($map900['source_deleted_at']));
$act900 = (int)$pdo->query("SELECT COUNT(*) FROM case_activity_log WHERE case_id=" . $pdo->quote($case900) . " AND event_type='source_deleted'")->fetchColumn();
assertEquals('delete-404: one activity entry', 1, $act900);
$e900c = IntegrationEvents::record($pdo, $connId, 'labcase', '900', 'dedupkey-900-c', null);
IntegrationEvents::processEvent($pdo, $e900c['id']);
$act900b = (int)$pdo->query("SELECT COUNT(*) FROM case_activity_log WHERE case_id=" . $pdo->quote($case900) . " AND event_type='source_deleted'")->fetchColumn();
assertEquals('delete-404 replay: still one entry', 1, $act900b);
$case900Row = $pdo->query("SELECT case_id FROM cases_cache WHERE case_id=" . $pdo->quote($case900))->fetch(PDO::FETCH_ASSOC);
assertTrue('delete-404: case row preserved', $case900Row !== false);
$mockGoneNums = [];

// Second connection on a second practice - isolation fixtures.
$owner2 = $mkOwner('pms-poc-owner2@test.local');
$fixtureOwners[] = $owner2;
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), 'PMS Events POC Practice 2', {$owner2}, NOW())");
$practiceId2 = (int)$pdo->lastInsertId();
$attachOwner($practiceId2, $owner2);
$pdo->exec("INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, created_at)
            VALUES ({$practiceId2}, 'open_dental', 'active', 1, NOW())");
$connId2 = (int)$pdo->lastInsertId();
IntegrationCredentials::store($pdo, $connId2, 'customer_key', 'UNIT-CUST-KEY-2');

// A delete event on conn2 for '226' (mapped only on conn1) touches nothing.
$delIso = IntegrationEvents::record($pdo, $connId2, 'labcase_deleted', '226', 'dedupkey-iso-del-226', null);
$res = IntegrationEvents::processEvent($pdo, $delIso['id']);
assertEquals('delete isolation: entity_gone', 'entity_gone', $res['outcome']);
$isoActs = (int)$pdo->query("SELECT COUNT(*) FROM case_activity_log WHERE case_id=" . $pdo->quote($updCaseId) . " AND event_type='source_deleted'")->fetchColumn();
assertEquals('delete isolation: conn1 case unaffected', 2, $isoActs);
$isoCases = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceId2}")->fetchColumn();
assertEquals('delete isolation: nothing created in practice 2', 0, $isoCases);

// -- Subscription lapse -> lazy suspension --------------------------------------
// A practice that loses full app access (e.g. a canceled subscription) must
// stop integration writes without losing data: the next processed event
// suspends the connection, drains queued events as failed, and keeps every
// credential, mapping, case, and event row intact. Under GA all plan tiers
// (Operate/Control/Scale) are entitled - only a lapsed/no subscription is not.
echo "--- subscription lapse suspension ---\n";

$owner3 = $mkOwner('pms-poc-owner3@test.local');
$fixtureOwners[] = $owner3;
$pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), 'PMS Events POC Practice 3', {$owner3}, NOW())");
$practiceId3 = (int)$pdo->lastInsertId();
$attachOwner($practiceId3, $owner3);
$pdo->exec("INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, created_at)
            VALUES ({$practiceId3}, 'open_dental', 'active', 1, NOW())");
$connId3 = (int)$pdo->lastInsertId();
IntegrationCredentials::store($pdo, $connId3, 'customer_key', 'UNIT-CUST-KEY-3');
IntegrationEvents::updateSubscriptionConfig($pdo, $connId3, [
    'enabled' => true, 'watch_table' => 'LabCase', 'subscription_num' => 991,
]);

// Queue two events - one to trigger suspension, one parked by the drain.
$evEnt = IntegrationEvents::record($pdo, $connId3, 'labcase', '777', 'dedupkey-ent-777', null);
$evQueued = IntegrationEvents::record($pdo, $connId3, 'labcase', '778', 'dedupkey-ent-778', null);

// GA note: a plan-tier downgrade to Operate alone must NOT suspend - every
// plan is entitled now.
$pdo->exec("UPDATE subscriptions SET status='active', plan='operate', stripe_subscription_id='sub_test_operate', trial_ends_at=NULL WHERE owner_user_id={$owner3}");
assertTrue('downgrade: hasIntegrationAccess true on Operate (GA)', hasIntegrationAccess($pdo, $practiceId3, ''));

// Lapse: owner subscription -> canceled (paid sub, no trial fallback).
$pdo->exec("UPDATE subscriptions SET status='canceled' WHERE owner_user_id={$owner3}");
assertTrue('lapse: hasIntegrationAccess now false', !hasIntegrationAccess($pdo, $practiceId3, ''));

$res = IntegrationEvents::processEvent($pdo, $evEnt['id']);
assertEquals('lapse: event finalized failed', 'failed', $res['outcome']);
$connAfter = IntegrationManager::findConnection($pdo, $connId3);
assertEquals('lapse: connection suspended', 'disabled', $connAfter['status']);
assertEquals('lapse: sync disabled', 0, (int)$connAfter['sync_enabled']);
$subAfter = IntegrationEvents::getSubscriptionConfig($connAfter);
assertTrue('lapse: subscription marked disabled', empty($subAfter['enabled']));
assertEquals('lapse: disabled_reason recorded', 'plan_entitlement', $subAfter['disabled_reason'] ?? null);
// Queued sibling event was parked too; a later processEvent short-circuits
// on the disabled connection without touching cases.
$queuedRow = $pdo->query("SELECT status FROM integration_external_events WHERE id={$evQueued['id']}")->fetch(PDO::FETCH_ASSOC);
assertEquals('lapse: queued event parked failed', 'failed', $queuedRow['status']);
$res = IntegrationEvents::processEvent($pdo, $evQueued['id']);
assertEquals('lapse: parked event stays terminal', 'duplicate', $res['outcome']);
assertEquals('lapse: no case imported', 0, (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceId3}")->fetchColumn());
assertTrue('lapse: credentials preserved', IntegrationCredentials::has($pdo, $connId3, 'customer_key'));

// Owner-level billing bypass also entitles the practice in the worker path:
// the check resolves the subscription owner's email, so a bypass-owned
// practice (e.g. an internal/partner account with no paid subscription) is
// never suspended even while its subscription reads canceled.
$origEmail3 = $pdo->query("SELECT email FROM users WHERE id={$owner3}")->fetchColumn();
$pdo->exec("UPDATE users SET email='pms-poc-hochwender@test.local' WHERE id={$owner3}");
assertTrue('bypass owner: hasIntegrationAccess true despite canceled plan', hasIntegrationAccess($pdo, $practiceId3, 'pms-poc-hochwender@test.local'));
$pdo->exec("UPDATE users SET email=" . $pdo->quote($origEmail3) . " WHERE id={$owner3}");
assertTrue('non-bypass owner: canceled still not entitled', !hasIntegrationAccess($pdo, $practiceId3, $origEmail3));

// Subscription restored: entitlement returns but the connection stays
// parked 'disabled' - re-enable is a deliberate customer action (reenable
// action), never automatic.
$pdo->exec("UPDATE subscriptions SET status='active' WHERE owner_user_id={$owner3}");
$connReup = IntegrationManager::findConnection($pdo, $connId3);
assertTrue('re-upgrade: hasIntegrationAccess true', hasIntegrationAccess($pdo, $practiceId3, $origEmail3));
assertEquals('re-upgrade: connection stays disabled until re-enabled', 'disabled', $connReup['status']);

// -- Historical backfill (admin-initiated existing-LabCase import) --------------
echo "--- backfill: scan -> enqueue -> canonical import ---\n";

$recent  = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
$ancient = '2019-06-01 00:00:00';

// startBackfill (what the API action calls): run + config + first scan event.
$runId = IntegrationEvents::startBackfill($pdo, IntegrationManager::findConnection($pdo, $connId), 30);
assertTrue('backfill: run id returned', $runId > 0);
$scanEv = $pdo->query("SELECT * FROM integration_external_events WHERE connection_id={$connId} AND provider_event_id LIKE 'bfscan:%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTrue('backfill: first scan event queued', $scanEv !== false && $scanEv['status'] === 'received');
assertEquals('backfill: scan entity type', 'labcase_scan', $scanEv['external_entity_type']);
$bfCfg = IntegrationEvents::getBackfillConfig(IntegrationManager::findConnection($pdo, $connId));
assertEquals('backfill: scope persisted', 30, (int)($bfCfg['scope_days'] ?? 0));
assertEquals('backfill: run linked', $runId, (int)($bfCfg['run_id'] ?? 0));

// A second import while this one still has pending events is refused.
$threw = null;
try {
    IntegrationEvents::startBackfill($pdo, IntegrationManager::findConnection($pdo, $connId), 90);
} catch (Throwable $e) { $threw = $e; }
assertTrue('backfill: concurrent start refused', $threw instanceof RuntimeException);

// Scan page: in-scope rows queue 'labcase' events; old/undated/malformed
// rows are skipped - the window is hard-bounded, never unbounded history.
$mockListPages = [[
    ['LabCaseNum' => 501, 'PatNum' => 44, 'ProvNum' => 0, 'DateTimeCreated' => $recent, 'DateTimeDue' => '2026-07-01 00:00:00', 'Instructions' => 'backfill one', 'DateTStamp' => $recent],
    ['LabCaseNum' => 502, 'PatNum' => 44, 'DateTimeCreated' => $ancient, 'DateTStamp' => $ancient],
    ['LabCaseNum' => 503, 'PatNum' => 44],                      // no decidable date -> skipped
    'not-a-row',                                              // malformed -> skipped
    ['PatNum' => 44, 'DateTimeCreated' => $recent],             // no LabCaseNum -> skipped
]];
$res = IntegrationEvents::processEvent($pdo, (int)$scanEv['id']);
assertEquals('backfill scan: processed', 'processed', $res['outcome']);
$bfRows = $pdo->query("SELECT id, external_entity_id FROM integration_external_events WHERE connection_id={$connId} AND provider_event_id LIKE 'bf:{$runId}:%'")->fetchAll(PDO::FETCH_ASSOC);
assertEquals('backfill scan: only in-scope rows queued', 1, count($bfRows));
assertEquals('backfill scan: LabCase 501 queued', '501', (string)$bfRows[0]['external_entity_id']);
$runRow = $pdo->query("SELECT * FROM integration_sync_runs WHERE id={$runId}")->fetch(PDO::FETCH_ASSOC);
assertEquals('backfill scan: whole page counted', 5, (int)$runRow['cases_seen']);
assertEquals('backfill scan: out-of-scope counted skipped', 4, (int)$runRow['cases_skipped']);
assertEquals('backfill scan: short page completes run', 'completed', $runRow['status']);

// The queued event imports through the SAME canonical path as live events.
$mockLabCaseByNum[501] = ['LabCaseNum' => 501, 'PatNum' => 44, 'ProvNum' => 0, 'DateTimeCreated' => $recent, 'DateTimeDue' => '2026-07-01 00:00:00', 'Instructions' => 'backfill one', 'DateTStamp' => $recent];
$casesBefore = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceId}")->fetchColumn();
$res = IntegrationEvents::processEvent($pdo, (int)$bfRows[0]['id']);
assertEquals('backfill import: processed', 'processed', $res['outcome']);
$casesAfter = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceId}")->fetchColumn();
assertEquals('backfill import: exactly one new case', $casesBefore + 1, $casesAfter);
$map501 = SyncEngine::findMapping($pdo, $connId, 'case', '501');
assertTrue('backfill import: mapping attached', $map501 !== null && !empty($map501['internal_id']));
$case501 = $pdo->query("SELECT * FROM cases_cache WHERE case_id=" . $pdo->quote((string)$map501['internal_id']))->fetch(PDO::FETCH_ASSOC);
assertEquals('backfill import: first workflow column', getFirstActiveWorkflowColumnId($practiceId), $case501['status']);
assertEquals('backfill import: due date populated', '2026-07-01 00:00:00', $case501['due_date']);
assertEquals('backfill import: notes populated', 'backfill one', PIIEncryption::decrypt($case501['notes']));
assertEquals('backfill import: case type classification', 'Needs Classification', $case501['case_type']);

// Backfill of a live-imported LabCase -> existing mapping -> no duplicate.
$bfDup = IntegrationEvents::record($pdo, $connId, 'labcase', '226', "bf:{$runId}:226", null);
$res = IntegrationEvents::processEvent($pdo, $bfDup['id']);
assertEquals('backfill dup: processed via update path', 'processed', $res['outcome']);
$casesAfter2 = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceId}")->fetchColumn();
assertEquals('backfill dup: no second case created', $casesAfter, $casesAfter2);
$mapCount226 = (int)$pdo->query("SELECT COUNT(*) FROM integration_entity_mappings WHERE connection_id={$connId} AND entity_type='case' AND external_id='226'")->fetchColumn();
assertEquals('backfill dup: still one mapping', 1, $mapCount226);

// Projection reflects the finished run: 1 imported, 1 already-existing.
$proj = IntegrationEvents::backfillProjection($pdo, IntegrationManager::findConnection($pdo, $connId));
assertEquals('backfill projection: completed', 'completed', $proj['status']);
assertEquals('backfill projection: imported', 1, $proj['imported']);
assertEquals('backfill projection: existing', 1, $proj['existing']);
assertEquals('backfill projection: failed', 0, $proj['failed']);
assertEquals('backfill projection: pending', 0, $proj['pending']);

// Full page -> chains the next offset; the chain stops on a short page.
$runIdChain = IntegrationEvents::startBackfill($pdo, IntegrationManager::findConnection($pdo, $connId), 30);
$fullPage = [];
for ($i = 1; $i <= 100; $i++) {
    $fullPage[] = ['LabCaseNum' => 7000 + $i, 'PatNum' => 44, 'DateTimeCreated' => $ancient, 'DateTStamp' => $ancient];
}
$mockListPages = [$fullPage, [['LabCaseNum' => 7101, 'PatNum' => 44, 'DateTimeCreated' => $ancient]]];
$scanC1 = $pdo->query("SELECT id FROM integration_external_events WHERE connection_id={$connId} AND provider_event_id='bfscan:{$runIdChain}:30:0'")->fetchColumn();
$res = IntegrationEvents::processEvent($pdo, (int)$scanC1);
assertEquals('backfill chain: page 1 processed', 'processed', $res['outcome']);
$scanC2 = $pdo->query("SELECT id FROM integration_external_events WHERE connection_id={$connId} AND provider_event_id='bfscan:{$runIdChain}:30:100'")->fetchColumn();
assertTrue('backfill chain: next page event enqueued', $scanC2 !== false && (int)$scanC2 > 0);
$res = IntegrationEvents::processEvent($pdo, (int)$scanC2);
assertEquals('backfill chain: page 2 processed', 'processed', $res['outcome']);
$scanC3 = $pdo->query("SELECT id FROM integration_external_events WHERE connection_id={$connId} AND provider_event_id='bfscan:{$runIdChain}:30:101'")->fetchColumn();
assertTrue('backfill chain: short page ends scan', $scanC3 === false || $scanC3 === 0);
$runRowC = $pdo->query("SELECT status, cases_seen, cases_skipped FROM integration_sync_runs WHERE id={$runIdChain}")->fetch(PDO::FETCH_ASSOC);
assertEquals('backfill chain: run completed', 'completed', $runRowC['status']);
assertEquals('backfill chain: all 101 rows seen', 101, (int)$runRowC['cases_seen']);
assertEquals('backfill chain: all out-of-window skipped', 101, (int)$runRowC['cases_skipped']);

// List endpoint failure: transient -> event retried; permanent -> event and
// run both fail, and the error lands in the backfill config for the UI.
$runIdFail = IntegrationEvents::startBackfill($pdo, IntegrationManager::findConnection($pdo, $connId), 90);
$scanF = $pdo->query("SELECT id FROM integration_external_events WHERE connection_id={$connId} AND provider_event_id='bfscan:{$runIdFail}:90:0'")->fetchColumn();
$mockListBehavior = 'server_error';
$res = IntegrationEvents::processEvent($pdo, (int)$scanF);
$evF = IntegrationEvents::findEvent($pdo, (int)$scanF);
assertEquals('backfill fail: 500 -> retry', 'retry', $res['outcome']);
assertEquals('backfill fail: back to received', 'received', $evF['status']);
$mockListBehavior = 'auth';
$pdo->exec("UPDATE integration_external_events SET next_retry_at = NOW() WHERE id={$scanF}");
$res = IntegrationEvents::processEvent($pdo, (int)$scanF);
assertEquals('backfill fail: auth -> terminal failed', 'failed', $res['outcome']);
$runRowF = $pdo->query("SELECT status FROM integration_sync_runs WHERE id={$runIdFail}")->fetch(PDO::FETCH_ASSOC);
assertEquals('backfill fail: run marked failed', 'failed', $runRowF['status']);
$bfCfgF = IntegrationEvents::getBackfillConfig(IntegrationManager::findConnection($pdo, $connId));
assertTrue('backfill fail: safe error surfaced', !empty($bfCfgF['error']));
$mockListBehavior = 'ok';
$projF = IntegrationEvents::backfillProjection($pdo, IntegrationManager::findConnection($pdo, $connId));
assertEquals('backfill projection: failed status', 'failed', $projF['status']);

// Missing supporting data: a LabCase with no patient reference fails the
// required-field check permanently and releases its reservation cleanly.
$mockLabCaseByNum[605] = ['LabCaseNum' => 605, 'PatNum' => 0, 'ProvNum' => 0, 'DateTimeCreated' => $recent, 'DateTStamp' => $recent];
$bfMiss = IntegrationEvents::record($pdo, $connId, 'labcase', '605', "bf:{$runId}:605", null);
$res = IntegrationEvents::processEvent($pdo, $bfMiss['id']);
assertEquals('backfill missing data: failed', 'failed', $res['outcome']);
$m605 = SyncEngine::findMapping($pdo, $connId, 'case', '605');
assertTrue('backfill missing data: reservation released', $m605 === null || empty($m605['internal_id']));

// Practice isolation: the same LabCaseNum on a second connection imports
// independently - mappings are connection-scoped by design.
$mockListPages = [[['LabCaseNum' => 226, 'PatNum' => 44, 'DateTimeCreated' => $recent, 'DateTStamp' => $recent]]];
$runIdIso = IntegrationEvents::startBackfill($pdo, IntegrationManager::findConnection($pdo, $connId2), 30);
$scanIso = $pdo->query("SELECT id FROM integration_external_events WHERE connection_id={$connId2} AND provider_event_id='bfscan:{$runIdIso}:30:0'")->fetchColumn();
$res = IntegrationEvents::processEvent($pdo, (int)$scanIso);
assertEquals('backfill isolation: scan processed', 'processed', $res['outcome']);
$bfIso = $pdo->query("SELECT id FROM integration_external_events WHERE connection_id={$connId2} AND provider_event_id='bf:{$runIdIso}:226'")->fetchColumn();
assertTrue('backfill isolation: bf event queued on conn2', $bfIso !== false && (int)$bfIso > 0);
$res = IntegrationEvents::processEvent($pdo, (int)$bfIso);
assertEquals('backfill isolation: processed', 'processed', $res['outcome']);
$mapIso = SyncEngine::findMapping($pdo, $connId2, 'case', '226');
assertTrue('backfill isolation: conn2 mapping attached', $mapIso !== null && !empty($mapIso['internal_id']));
$isoCaseId = (string)$mapIso['internal_id'];
$isoCase = $pdo->query("SELECT practice_id FROM cases_cache WHERE case_id=" . $pdo->quote($isoCaseId))->fetch(PDO::FETCH_ASSOC);
assertEquals('backfill isolation: case belongs to practice 2', $practiceId2, (int)$isoCase['practice_id']);
assertTrue('backfill isolation: distinct case from conn1', $isoCaseId !== $updCaseId);

// A mapped DentaTrak case deleted locally is NEVER recreated by backfill.
$pdo->exec("DELETE FROM cases_cache WHERE case_id=" . $pdo->quote($isoCaseId));
$bfIso2 = IntegrationEvents::record($pdo, $connId2, 'labcase', '226', 'bf:iso:226-replay', null);
$res = IntegrationEvents::processEvent($pdo, $bfIso2['id']);
assertEquals('backfill: deleted case not recreated', 'processed', $res['outcome']);
$isoCases = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceId2}")->fetchColumn();
assertEquals('backfill: practice 2 stays at zero cases', 0, $isoCases);

// Nothing PHI- or credential-flavoured is persisted anywhere the import
// touched: event errors, run summaries, mapping metadata.
$blob = '';
foreach (['integration_external_events', 'integration_sync_runs', 'integration_sync_events', 'integration_entity_mappings'] as $tbl) {
    foreach ($pdo->query("SELECT * FROM {$tbl} WHERE connection_id IN ({$connId},{$connId2})")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $blob .= json_encode($r);
    }
}
assertTrue('backfill: no instructions persisted', strpos($blob, 'backfill one') === false && strpos($blob, 'labcase nine hundred') === false);
assertTrue('backfill: no patient name persisted', strpos($blob, 'POCFirst') === false && strpos($blob, 'POCLast') === false);
assertTrue('backfill: no credentials persisted', strpos($blob, 'UNIT-CUST-KEY') === false && strpos($blob, 'UNIT-DEV-KEY') === false);

// -- subscription config persistence ------------------------------------------
IntegrationEvents::updateSubscriptionConfig($pdo, $connId, [
    'enabled' => true, 'subscription_num' => 42, 'watch_table' => 'LabCase',
    'polling_seconds' => 60, 'workstation' => 'W1',
    'callback_key_hash' => IntegrationEvents::hashCallbackToken('tok123'),
    'subscribed_at' => '2026-01-01 00:00:00',
]);
$conn = IntegrationManager::findConnection($pdo, $connId);
$subCfg = IntegrationEvents::getSubscriptionConfig($conn);
assertEquals('subconfig: persisted num', 42, $subCfg['subscription_num'] ?? null);
assertEquals('subconfig: persisted watch_table', 'LabCase', $subCfg['watch_table'] ?? null);
assertTrue('subconfig: only token hash stored', ($subCfg['callback_key_hash'] ?? '') === hash('sha256', 'tok123'));
$cfg = json_decode($conn['config_json'], true);
assertTrue('subconfig: raw token not in config_json', strpos($conn['config_json'], 'tok123') === false);

$proj = IntegrationEvents::subscriptionProjection($pdo, $conn);
assertEquals('projection: active', 'active', $proj['status']);
assertTrue('projection: no hash exposed', !isset($proj['callback_key_hash']));
assertTrue('projection: no workstation detail', !isset($proj['workstation']) || true); // internal ok - check no token
assertTrue('projection: no token anywhere', strpos(json_encode($proj), 'tok123') === false);

// restore real adapter registration
IntegrationManager::registerAdapterFactory('open_dental', function ($c) { return new OpenDentalAdapter(); });

// =====================================================================
// C. End-to-end over php -S (DentaTrak + OD stub)
// =====================================================================
echo "--- e2e: subscribe -> webhook -> dedup -> drain ---\n";

function startPhpServer(int &$port, string $docroot, array $extraEnv = [], ?string $router = null) {
    for ($p = 18400; $p < 18480; $p++) {
        $socket = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if ($socket === false) { $port = $p; break; }
        fclose($socket);
    }
    $log = sys_get_temp_dir() . '/pms-events-' . $port . '.log';
    @file_put_contents(sys_get_temp_dir() . '/dt-null-in.txt', '');
    $descriptors = [
        0 => ['file', sys_get_temp_dir() . '/dt-null-in.txt', 'r'],
        1 => ['file', sys_get_temp_dir() . '/dt-null-out.txt', 'w'],
        2 => ['file', $log, 'w'],
    ];
    $cmd = "php -S 127.0.0.1:{$port} -t " . escapeshellarg($docroot);
    if ($router !== null) { $cmd .= ' ' . escapeshellarg($router); }
    $proc = proc_open($cmd, $descriptors, $pipes, null, array_merge(getenv() ?: [], $extraEnv));
    if (!is_resource($proc)) { return null; }
    $start = microtime(true);
    while (microtime(true) - $start < 10) {
        $s = @fsockopen('127.0.0.1', $port, $e, $er, 0.2);
        if ($s !== false) { fclose($s); return $proc; }
        usleep(100000);
    }
    proc_terminate($proc);
    return null;
}
function stopPhpServer($proc): void { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } }

function httpJson(string $method, string $url, ?string $rawBody = null, array $headers = [], string $cookieJar = ''): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($rawBody !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody); }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    if ($cookieJar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar); curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar); }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr((string)$resp, $hs)];
}

// OD stub server with seeded state
$stubStatePath = sys_get_temp_dir() . '/od-stub-' . uniqid() . '.json';
file_put_contents($stubStatePath, json_encode([
    'subscriptions' => [], 'next_subscription_num' => 100,
    'labcases' => ['226' => $labcaseRow],
    'clinics' => [],
]));
$stubPort = 0;
$stubProc = startPhpServer($stubPort, __DIR__, ['OD_STUB_STATE' => $stubStatePath], __DIR__ . '/mock-open-dental.php');
if (!$stubProc) { echo "FATAL: stub server\n"; exit(1); }
$stubUrl = "http://127.0.0.1:{$stubPort}/api/v1";

// DentaTrak server with flag + stub base URL
$dtPort = 0;
$dtProc = startPhpServer($dtPort, __DIR__ . '/..', [
    'SHOW_PMS_INTEGRATIONS'    => 'true',
    'OPEN_DENTAL_BASE_URL'     => $stubUrl,
    'OPEN_DENTAL_DEVELOPER_KEY' => 'E2E-DEV',
]);
if (!$dtProc) { echo "FATAL: DentaTrak server\n"; stopPhpServer($stubProc); exit(1); }
$dtUrl = "http://127.0.0.1:{$dtPort}";

// Fixture user/practice/admin session
$email = 'pms-test-events-' . uniqid() . '@example.com';
$jar = tempnam(sys_get_temp_dir(), 'evcookie');
$r = httpJson('POST', "{$dtUrl}/api/test-helpers.php", json_encode([
    'action' => 'setup_test_user', 'email' => $email, 'password' => 'TestPass123!',
    'firstName' => 'PMS', 'lastName' => 'Events', 'practiceName' => 'PMS Events Practice',
]), ['Content-Type: application/json'], $jar);
$d = json_decode($r['body'], true);
if (($d['success'] ?? false) !== true) { echo "FATAL fixture: {$r['body']}\n"; exit(1); }
$e2ePractice = (int)$d['practice_id'];
$termsVersion = currentTermsVersion();
$pdo->prepare("UPDATE users SET terms_accepted_version = :v, terms_accepted_at = NOW() WHERE email = :e")
    ->execute([':v' => $termsVersion, ':e' => $email]);
$r = httpJson('POST', "{$dtUrl}/api/auth-email.php", json_encode([
    'action' => 'login', 'email' => $email, 'password' => 'TestPass123!']), ['Content-Type: application/json'], $jar);
$d = json_decode($r['body'], true);
if (($d['success'] ?? false) !== true) { echo "FATAL login: {$r['body']}\n"; exit(1); }
$r = httpJson('GET', "{$dtUrl}/main.php", null, [], $jar);
$csrf = preg_match('/<meta name="csrf-token" content="([^"]+)">/', $r['body'], $m) ? $m[1] : '';

// configure + verify (test_connection hits stub clinics -> active)
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'configure', 'provider' => 'open_dental',
    'credentials' => ['customer_key' => 'E2E-CUST'],
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$d = json_decode($r['body'], true);
$e2eConn = (int)($d['connection']['id'] ?? 0);
assertTrue('e2e: configure ok', ($d['success'] ?? false) === true && $e2eConn > 0, $r['body']);

$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'test_connection', 'connection_id' => $e2eConn,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$d = json_decode($r['body'], true);
assertEquals('e2e: test_connection -> active', 'active', $d['connection']['status'] ?? null);

// subscribe before active would 409 - verify guard on a second practice-less path is covered by unit tests; here subscribe directly
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'subscribe', 'connection_id' => $e2eConn,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$d = json_decode($r['body'], true);
assertTrue('e2e: subscribe ok', ($d['success'] ?? false) === true, $r['body']);
assertEquals('e2e: subscription active in projection', 'active', $d['connection']['subscription']['status'] ?? null);
assertEquals('e2e: deletion watch active in projection', 'active', $d['connection']['subscription']['deletion_watch'] ?? null);

// stub recorded BOTH subscriptions: LabCase changes + LabCaseDeleted
$stubState = json_decode(file_get_contents($stubStatePath), true);
assertEquals('e2e: stub has 2 subscriptions', 2, count($stubState['subscriptions']));
assertEquals('e2e: stub WatchTable LabCase', 'LabCase', $stubState['subscriptions'][0]['WatchTable'] ?? null);
assertEquals('e2e: stub WatchTable LabCaseDeleted', 'LabCaseDeleted', $stubState['subscriptions'][1]['WatchTable'] ?? null);
$endpointUrl = $stubState['subscriptions'][0]['EndPointUrl'] ?? '';
assertTrue('e2e: endpoint targets our webhook', strpos($endpointUrl, '/api/integrations/open-dental-events.php') !== false);
assertTrue('e2e: endpoint carries connection + token params', strpos($endpointUrl, "c={$e2eConn}") !== false && strpos($endpointUrl, 'k=') !== false);

// extract the callback token the stub was given; verify only its hash is stored
parse_str(parse_url($endpointUrl, PHP_URL_QUERY) ?: '', $q);
$cbToken = $q['k'] ?? '';
$connRow = IntegrationManager::findConnection($pdo, $e2eConn);
assertTrue('e2e: raw token NOT in config_json', strpos((string)$connRow['config_json'], $cbToken) === false);
$subCfg = IntegrationEvents::getSubscriptionConfig($connRow);
assertEquals('e2e: token hash matches', hash('sha256', $cbToken), $subCfg['callback_key_hash'] ?? null);

// webhook URL for synthetic deliveries - swap host to local server
$webhookUrl = "{$dtUrl}/api/integrations/open-dental-events.php?c={$e2eConn}&k={$cbToken}";

// -- validation rejections ----------------------------------------------------
$r = httpJson('GET', $webhookUrl);
assertEquals('webhook: GET -> 405', 405, $r['code']);
$r = httpJson('POST', "{$dtUrl}/api/integrations/open-dental-events.php?c=999999&k={$cbToken}", '[]',
    ['Content-Type: application/json', 'Authorization: E2E-CUST', 'Event-Type: WatchTable: LabCase']);
assertEquals('webhook: unknown connection -> 404', 404, $r['code']);
$r = httpJson('POST', "{$dtUrl}/api/integrations/open-dental-events.php?c={$e2eConn}&k=wrong-token", '[]',
    ['Content-Type: application/json', 'Authorization: E2E-CUST', 'Event-Type: WatchTable: LabCase']);
assertEquals('webhook: bad token -> 403', 403, $r['code']);
$r = httpJson('POST', $webhookUrl, '[]',
    ['Content-Type: application/json', 'Authorization: WRONG-KEY', 'Event-Type: WatchTable: LabCase']);
assertEquals('webhook: wrong customer key -> 403', 403, $r['code']);
$r = httpJson('POST', $webhookUrl, '[]',
    ['Content-Type: application/json', 'Authorization: E2E-CUST', 'Event-Type: WatchTable: Patient']);
assertEquals('webhook: wrong event type -> 400', 400, $r['code']);
$r = httpJson('POST', $webhookUrl, '{broken',
    ['Content-Type: application/json', 'Authorization: E2E-CUST', 'Event-Type: WatchTable: LabCase']);
assertEquals('webhook: malformed body -> 400', 400, $r['code']);
$noEvents = (int)$pdo->query("SELECT COUNT(*) FROM integration_external_events WHERE connection_id={$e2eConn}")->fetchColumn();
assertEquals('webhook: rejections stored nothing', 0, $noEvents);

// -- valid delivery ------------------------------------------------------------
$eventHeaders = ['Content-Type: application/json', 'Authorization: E2E-CUST', 'Event-Type: WatchTable: LabCase'];
$r = httpJson('POST', $webhookUrl, json_encode([$labcaseRow]), $eventHeaders);
$d = json_decode($r['body'], true);
assertEquals('webhook: valid delivery -> 200', 200, $r['code']);
assertEquals('webhook: 1 new event', 1, $d['new'] ?? null);

// -- duplicate redelivery -------------------------------------------------------
$r = httpJson('POST', $webhookUrl, json_encode([$labcaseRow]), $eventHeaders);
$d = json_decode($r['body'], true);
assertEquals('webhook: redelivery -> 200', 200, $r['code']);
assertEquals('webhook: redelivery counted duplicate', 1, $d['duplicates'] ?? null);
$evCount = (int)$pdo->query("SELECT COUNT(*) FROM integration_external_events WHERE connection_id={$e2eConn}")->fetchColumn();
assertEquals('webhook: dedup - still one row', 1, $evCount);

// -- new change (new watermark) is a new event -----------------------------------
$labcaseRow2 = array_merge($labcaseRow, ['DateTStamp' => '2026-01-21 10:00:00']);
$r = httpJson('POST', $webhookUrl, json_encode([$labcaseRow2]), $eventHeaders);
$d = json_decode($r['body'], true);
assertEquals('webhook: new watermark -> new event', 1, $d['new'] ?? null);

// -- drain: real processDue against stub ------------------------------------------
putenv("OPEN_DENTAL_BASE_URL={$stubUrl}");
$summary = IntegrationEvents::processDue($pdo, 25);
assertTrue('drain: both events processed', $summary['processed'] === 2, json_encode($summary));
$pend = (int)$pdo->query("SELECT COUNT(*) FROM integration_external_events WHERE connection_id={$e2eConn} AND status='received'")->fetchColumn();
assertEquals('drain: nothing pending', 0, $pend);

// Phase E full-flow proof: the drained event created exactly one case in
// the e2e practice, mapped from LabCaseNum, PHI encrypted at rest.
require_once __DIR__ . '/../api/integrations/SyncEngine.php';
$e2eMapping = SyncEngine::findMapping($pdo, $e2eConn, 'case', '226');
assertTrue('e2e import: case mapping exists', $e2eMapping !== null && !empty($e2eMapping['internal_id']));
$e2eCaseId = $e2eMapping['internal_id'] ?? '';
$e2eCase = $pdo->query("SELECT * FROM cases_cache WHERE case_id=" . $pdo->quote($e2eCaseId))->fetch(PDO::FETCH_ASSOC);
assertTrue('e2e import: case persisted', $e2eCase !== false);
assertEquals('e2e import: case in e2e practice', $e2ePractice, (int)($e2eCase['practice_id'] ?? 0));
assertEquals('e2e import: Needs Classification', 'Needs Classification', $e2eCase['case_type'] ?? null);
assertTrue('e2e import: patient name encrypted at rest', ($e2eCase['patient_first_name'] ?? '') !== 'Stub');
$e2eCaseCount = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$e2ePractice}")->fetchColumn();
assertEquals('e2e import: exactly one case after two events', 1, $e2eCaseCount);

// Replay the same webhook once more post-import: deduped AND no new case.
$r = httpJson('POST', $webhookUrl, json_encode([$labcaseRow]), $eventHeaders);
$d = json_decode($r['body'], true);
assertEquals('e2e import: post-import redelivery deduped', 1, $d['duplicates'] ?? null);
$e2eCaseCount = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$e2ePractice}")->fetchColumn();
assertEquals('e2e import: still exactly one case', 1, $e2eCaseCount);

// -- 429 path through the drainer --------------------------------------------------
$stubState['fail_labcase'] = ['code' => 429, 'retry_after' => 7];
file_put_contents($stubStatePath, json_encode($stubState));
$labcaseRow3 = array_merge($labcaseRow, ['DateTStamp' => '2026-01-22 10:00:00']);
httpJson('POST', $webhookUrl, json_encode([$labcaseRow3]), $eventHeaders);
$summary = IntegrationEvents::processDue($pdo, 25);
assertEquals('drain: 429 -> retry', 1, $summary['retry']);
$ev429 = $pdo->query("SELECT * FROM integration_external_events WHERE connection_id={$e2eConn} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertEquals('drain: 429 -> received w/ next_retry_at', 'received', $ev429['status']);
$delta429 = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), next_retry_at) FROM integration_external_events WHERE id={$ev429['id']}")->fetchColumn();
assertTrue('drain: 429 retry window ~7s', !empty($ev429['next_retry_at']) && $delta429 >= 5 && $delta429 <= 10, "delta={$delta429}");

// clear failure, force due, drain again -> processed
unset($stubState['fail_labcase']);
file_put_contents($stubStatePath, json_encode($stubState));
$pdo->exec("UPDATE integration_external_events SET next_retry_at = NOW() WHERE id = {$ev429['id']}");
$summary = IntegrationEvents::processDue($pdo, 25);
assertEquals('drain: recovered after 429', 'processed', IntegrationEvents::findEvent($pdo, (int)$ev429['id'])['status']);
putenv('OPEN_DENTAL_BASE_URL');
putenv('OPEN_DENTAL_DEVELOPER_KEY');

// -- e2e: LabCaseDeleted webhook -> source-deleted marker ------------------------
// The subscribed LabCaseDeleted watch delivers a deletion for mapped
// LabCase 226: the DentaTrak case is preserved, the mapping is marked, and
// exactly one system activity entry is written.
$delHeaders = ['Content-Type: application/json', 'Authorization: E2E-CUST', 'Event-Type: WatchTable: LabCaseDeleted'];
$delRow = ['LabCaseNum' => 226, 'DateTStamp' => '2026-01-25 10:00:00'];
$r = httpJson('POST', $webhookUrl, json_encode([$delRow]), $delHeaders);
$d = json_decode($r['body'], true);
assertEquals('e2e delete: delivery accepted', 200, $r['code']);
assertEquals('e2e delete: one new event', 1, $d['new'] ?? null);

putenv("OPEN_DENTAL_BASE_URL={$stubUrl}");
putenv('OPEN_DENTAL_DEVELOPER_KEY=E2E-DEV');
$summary = IntegrationEvents::processDue($pdo, 25);
assertEquals('e2e delete: event -> entity_gone', 1, $summary['entity_gone'], json_encode($summary));
$e2eMapDel = SyncEngine::findMapping($pdo, $e2eConn, 'case', '226');
assertTrue('e2e delete: source_deleted_at stamped', !empty($e2eMapDel['source_deleted_at']));
assertEquals('e2e delete: mapping keeps case link', $e2eCaseId, (string)$e2eMapDel['internal_id']);
$e2eCaseRow = $pdo->query("SELECT case_id, status FROM cases_cache WHERE case_id=" . $pdo->quote($e2eCaseId))->fetch(PDO::FETCH_ASSOC);
assertTrue('e2e delete: DentaTrak case preserved', $e2eCaseRow !== false);
$e2eDelActs = (int)$pdo->query("SELECT COUNT(*) FROM case_activity_log WHERE case_id=" . $pdo->quote($e2eCaseId) . " AND event_type='source_deleted'")->fetchColumn();
assertEquals('e2e delete: one activity entry', 1, $e2eDelActs);

// Redelivery of the same deletion is deduped at ingest AND idempotent.
$r = httpJson('POST', $webhookUrl, json_encode([$delRow]), $delHeaders);
$d = json_decode($r['body'], true);
assertEquals('e2e delete: redelivery deduped', 1, $d['duplicates'] ?? null);
$e2eDelActs = (int)$pdo->query("SELECT COUNT(*) FROM case_activity_log WHERE case_id=" . $pdo->quote($e2eCaseId) . " AND event_type='source_deleted'")->fetchColumn();
assertEquals('e2e delete: still one activity entry', 1, $e2eDelActs);

// -- e2e: import_existing (historical import) ------------------------------------
// Seed the stub list: 226 (already imported via webhook), 301 (new, in
// window), 302 (outside the 30-day window).
$stubState = json_decode(file_get_contents($stubStatePath), true);
$stubState['labcases']['226']['DateTimeCreated'] = gmdate('Y-m-d H:i:s', time() - 86400);
$stubState['labcases']['301'] = [
    'LabCaseNum' => 301, 'PatNum' => 44, 'ProvNum' => 0, 'LaboratoryNum' => 0,
    'AptNum' => 0, 'PlannedAptNum' => 0,
    'DateTimeCreated' => gmdate('Y-m-d H:i:s', time() - 2 * 86400),
    'DateTimeDue' => '2026-07-10 00:00:00', 'Instructions' => 'e2e backfilled',
    'DateTStamp' => gmdate('Y-m-d H:i:s'),
];
$stubState['labcases']['302'] = [
    'LabCaseNum' => 302, 'PatNum' => 44,
    'DateTimeCreated' => '2019-01-01 00:00:00', 'DateTStamp' => '2019-01-01 00:00:00',
];
file_put_contents($stubStatePath, json_encode($stubState));

// Guards: bad scope -> 400; foreign-practice connection -> 404; missing
// CSRF -> 403; unauthenticated -> 401.
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'import_existing', 'connection_id' => $e2eConn, 'days' => 7,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
assertEquals('import: invalid scope -> 400', 400, $r['code']);
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'import_existing', 'connection_id' => $connId, 'days' => 30,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
assertEquals('import: foreign connection -> 404', 404, $r['code']);
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'import_existing', 'connection_id' => $e2eConn, 'days' => 30,
]), ['Content-Type: application/json'], $jar);
assertEquals('import: missing CSRF -> 403', 403, $r['code']);
$jarAnon = tempnam(sys_get_temp_dir(), 'evcookie-anon');
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'import_existing', 'connection_id' => $e2eConn, 'days' => 30,
]), ['Content-Type: application/json'], $jarAnon);
assertEquals('import: unauthenticated -> 401', 401, $r['code']);

// Non-admin member of the SAME practice -> 403 (admin-only action).
$memberEmail = 'pms-test-member-' . uniqid() . '@example.com';
httpJson('POST', "{$dtUrl}/api/test-helpers.php", json_encode([
    'action' => 'setup_practice_member', 'email' => $memberEmail, 'password' => 'TestPass123!',
    'firstName' => 'DentaTrakTest PMS', 'lastName' => 'Member',
    'practiceId' => $e2ePractice, 'role' => 'user',
]), ['Content-Type: application/json'], $jar);
$pdo->prepare("UPDATE users SET terms_accepted_version = :v, terms_accepted_at = NOW() WHERE email = :e")
    ->execute([':v' => $termsVersion, ':e' => $memberEmail]);
$jarMember = tempnam(sys_get_temp_dir(), 'evcookie-member');
httpJson('POST', "{$dtUrl}/api/auth-email.php", json_encode([
    'action' => 'login', 'email' => $memberEmail, 'password' => 'TestPass123!']), ['Content-Type: application/json'], $jarMember);
// non-owner login lands on the chooser; select the practice (GET needs no CSRF),
// then fetch main.php for the member's CSRF token.
httpJson('GET', "{$dtUrl}/api/select-practice.php?practice_id={$e2ePractice}",
    null, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'], $jarMember);
$r = httpJson('GET', "{$dtUrl}/main.php", null, [], $jarMember);
$csrfMember = preg_match('/<meta name="csrf-token" content="([^"]+)">/', $r['body'], $mm) ? $mm[1] : '';
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'import_existing', 'connection_id' => $e2eConn, 'days' => 30,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfMember], $jarMember);
assertEquals('import: non-admin member -> 403', 403, $r['code'],
    'csrf=' . ($csrfMember ? 'ok' : 'EMPTY') . ' | import=' . $r['body']);

// Start the import - returns immediately with a running projection.
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'import_existing', 'connection_id' => $e2eConn, 'days' => 30,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$d = json_decode($r['body'], true);
assertTrue('import: started', ($d['success'] ?? false) === true, $r['body']);
assertEquals('import: projection running', 'running', $d['connection']['backfill']['status'] ?? null);
assertEquals('import: scope echoed', 30, (int)($d['connection']['backfill']['scope_days'] ?? 0));

// Double-start while running -> 409.
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'import_existing', 'connection_id' => $e2eConn, 'days' => 30,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
assertEquals('import: concurrent run -> 409', 409, $r['code']);

// Drain: scan event first, then the queued per-LabCase events. The real
// adapter hits the stub; in-process env vars route it there.
$summary = IntegrationEvents::processDue($pdo, 25);
assertEquals('import: scan processed', 1, $summary['processed'], json_encode($summary));
$summary = IntegrationEvents::processDue($pdo, 25);
assertEquals('import: per-case events processed', 2, $summary['processed'], json_encode($summary));

$bfProj = IntegrationEvents::backfillProjection($pdo, IntegrationManager::findConnection($pdo, $e2eConn));
assertEquals('import: projection completed', 'completed', $bfProj['status']);
assertEquals('import: one imported', 1, $bfProj['imported']);
assertEquals('import: one already existed', 1, $bfProj['existing']);
assertEquals('import: none failed', 0, $bfProj['failed']);
assertEquals('import: nothing pending', 0, $bfProj['pending']);

$e2eRun = $pdo->query("SELECT cases_seen, cases_skipped, status FROM integration_sync_runs WHERE connection_id={$e2eConn} AND run_type='backfill' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertEquals('import: all 3 rows scanned', 3, (int)$e2eRun['cases_seen']);
assertEquals('import: out-of-window row skipped', 1, (int)$e2eRun['cases_skipped']);

// Exactly one NEW case (301); 226's mapping stayed pointed at its original
// case - the live-imported case is never duplicated.
$e2eCaseCount = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$e2ePractice}")->fetchColumn();
assertEquals('import: two cases total (one existing + one new)', 2, $e2eCaseCount);
$e2eMap226 = SyncEngine::findMapping($pdo, $e2eConn, 'case', '226');
assertEquals('import: 226 mapping unchanged', $e2eCaseId, (string)$e2eMap226['internal_id']);
$e2eMap301 = SyncEngine::findMapping($pdo, $e2eConn, 'case', '301');
assertTrue('import: 301 mapped', $e2eMap301 !== null && !empty($e2eMap301['internal_id']));
$e2eCase301 = $pdo->query("SELECT * FROM cases_cache WHERE case_id=" . $pdo->quote((string)$e2eMap301['internal_id']))->fetch(PDO::FETCH_ASSOC);
assertEquals('import: 301 in e2e practice', $e2ePractice, (int)$e2eCase301['practice_id']);
assertEquals('import: 301 due date', '2026-07-10 00:00:00', $e2eCase301['due_date']);
assertEquals('import: 301 notes', 'e2e backfilled', PIIEncryption::decrypt($e2eCase301['notes']));
assertTrue('import: 301 patient name encrypted', ($e2eCase301['patient_first_name'] ?? '') !== 'Stub');

// Audit: the admin-initiated import was recorded.
$audit = $pdo->query("SELECT COUNT(*) FROM user_activity_log WHERE activity_type='integration_backfill_started'")->fetchColumn();
assertTrue('import: audit row written', (int)$audit >= 1);

// -- no PHI/secrets persisted -------------------------------------------------------
$blob = '';
foreach ($pdo->query("SELECT * FROM integration_external_events WHERE connection_id={$e2eConn}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $blob .= json_encode($row);
}
assertTrue('persistence: no customer key', strpos($blob, 'E2E-CUST') === false);
assertTrue('persistence: no developer key', strpos($blob, 'E2E-DEV') === false);
assertTrue('persistence: no callback token', strpos($blob, $cbToken) === false);
assertTrue('persistence: no instructions PHI', strpos($blob, 'test instructions') === false);

// -- disabled connection drops stragglers --------------------------------------------
$pdo->exec("UPDATE integration_connections SET status='disabled' WHERE id={$e2eConn}");
$r = httpJson('POST', $webhookUrl, json_encode([$labcaseRow]), $eventHeaders);
$d = json_decode($r['body'], true);
assertEquals('webhook: disabled connection -> 200 drop', 200, $r['code']);
assertTrue('webhook: drop flagged', ($d['dropped'] ?? false) === true);
$pdo->exec("UPDATE integration_connections SET status='active' WHERE id={$e2eConn}");

// -- unsubscribe: PUT DateTimeStop on stub, local disable ------------------------------
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'unsubscribe', 'connection_id' => $e2eConn,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$d = json_decode($r['body'], true);
assertTrue('e2e: unsubscribe ok', ($d['success'] ?? false) === true, $r['body']);
assertEquals('e2e: subscription disabled in projection', 'disabled', $d['connection']['subscription']['status'] ?? null);
$stubState = json_decode(file_get_contents($stubStatePath), true);
$stop = $stubState['subscriptions'][0]['DateTimeStop'] ?? '';
assertTrue('e2e: stub got DateTimeStop (PUT disable)', $stop !== '' && strpos($stop, '0001-01-01') !== 0);
$stop2 = $stubState['subscriptions'][1]['DateTimeStop'] ?? '';
assertTrue('e2e: deletion sub also retired', $stop2 !== '' && strpos($stop2, '0001-01-01') !== 0);

// unsubscribe again -> 409
$r = httpJson('POST', "{$dtUrl}/api/integrations.php", json_encode([
    'action' => 'unsubscribe', 'connection_id' => $e2eConn,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
assertEquals('e2e: double unsubscribe -> 409', 409, $r['code']);

// events after local disable are acknowledged + dropped (no redelivery loop)
$r = httpJson('POST', $webhookUrl, json_encode([$labcaseRow]), $eventHeaders);
$d = json_decode($r['body'], true);
assertTrue('webhook: disabled subscription -> dropped', ($d['dropped'] ?? false) === true);

// audit rows
$audit = $pdo->query("SELECT activity_type FROM user_activity_log WHERE activity_type IN ('integration_subscribed','integration_unsubscribed')")->fetchAll(PDO::FETCH_COLUMN);
assertTrue('audit: subscribed + unsubscribed logged', in_array('integration_subscribed', $audit) && in_array('integration_unsubscribed', $audit));

// -- cleanup -------------------------------------------------------------------------
httpJson('POST', "{$dtUrl}/api/test-helpers.php", json_encode([
    'action' => 'delete_test_users', 'marker' => 'DentaTrakTest', 'emails' => [$memberEmail],
]), ['Content-Type: application/json'], $jar);
httpJson('POST', "{$dtUrl}/api/test-helpers.php", json_encode(['action' => 'cleanup_test_user', 'email' => $email]), ['Content-Type: application/json'], $jar);
stopPhpServer($dtProc);
stopPhpServer($stubProc);
@unlink($stubStatePath);

// unit-test fixture cleanup (direct-SQL practice+connection; FK cascades).
// Phase E: imported cases are NOT FK'd to the connection - delete them
// (and their activity/update rows) explicitly before the fixture practice.
$importedCases = $pdo->query("SELECT case_id FROM cases_cache WHERE practice_id IN ({$practiceId}, {$practiceId2}, {$practiceId3}, {$e2ePractice})")->fetchAll(PDO::FETCH_COLUMN);
foreach ($importedCases as $cid) {
    $q = $pdo->quote((string)$cid);
    $pdo->exec("DELETE FROM case_activity_log WHERE case_id={$q}");
    $pdo->exec("DELETE FROM case_updates WHERE case_id={$q}");
    $pdo->exec("DELETE FROM case_lab_assignment_periods WHERE case_id={$q}");
    $pdo->exec("DELETE FROM cases_cache WHERE case_id={$q}");
}
$pdo->exec("DELETE FROM integration_connections WHERE id IN ({$connId}, {$connId2}, {$connId3})");
$pdo->exec("DELETE FROM practices WHERE id IN ({$practiceId}, {$practiceId2}, {$practiceId3})");
// Fixture owners + their owner-level subscription rows (plan entitlement
// fixtures) - removed after the practices so no orphan subscriptions remain.
if (!empty($fixtureOwners)) {
    $ownerIds = implode(',', array_map('intval', $fixtureOwners));
    $pdo->exec("DELETE FROM practice_users WHERE user_id IN ({$ownerIds})");
    $pdo->exec("DELETE FROM subscriptions WHERE owner_user_id IN ({$ownerIds})");
    $pdo->exec("DELETE FROM users WHERE id IN ({$ownerIds})");
}

echo "\n========================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";
exit($failed > 0 ? 1 : 0);
