<?php
/**
 * Open Dental patient launch tests (Phase 1: toolbar bridge + patient view).
 *
 *   A. OdPatientLaunch::resolvePatientCases - PatNum -> case ids under the
 *      caller's practice connection only: multi-case patients, legacy
 *      fallback via the patient mapping's external_parent_id, patient with
 *      no cases, unknown PatNum, invalid input, missing connection, archived
 *      exclusion, and cross-practice isolation.
 *   B. api/list-cases.php case_ids filter semantics (unit-level mirror of
 *      the endpoint's validation + intersection rules).
 *   C. Static contract checks: od-launch.php param handling, login.php /
 *      main.php pendingOdPatient stash + auth preservation, endpoint auth
 *      guard, PHI-safe logging (no PatNum/names in error_log).
 *
 * Requires the local development DB (port 3308) + ENCRYPTION_KEY.
 * Creates throwaway practices/connections/cases and removes them.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/encryption.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/IntegrationEvents.php';
require_once __DIR__ . '/../api/integrations/SyncEngine.php';
require_once __DIR__ . '/../api/integrations/OdPatientLaunch.php';
require_once __DIR__ . '/../api/integrations/CanonicalCase.php';

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

$hasDb = isset($pdo) && ($pdo instanceof PDO);
if (!$hasDb) {
    echo "NOTE: no database connection - sections A (resolution) are skipped;\n";
    echo "      sections B/C (semantics + static contracts) still run.\n";
}

// =====================================================================
// Fixtures
// =====================================================================
if ($hasDb) echo "--- fixtures ---\n";

$fixtureUserIds = [];
$fixturePracticeIds = [];
$fixtureConnectionIds = [];
$fixtureCaseIds = [];

$mkPractice = function (string $name) use ($pdo, &$fixtureUserIds, &$fixturePracticeIds): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, 'x', 'email', 'OD', 'Launch', 'admin', 1, 1, NOW())")
        ->execute(['e' => 'od-launch-owner-' . count($fixtureUserIds) . '@test.local']);
    $ownerId = (int)$pdo->lastInsertId();
    $fixtureUserIds[] = $ownerId;
    $pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), '{$name}', {$ownerId}, NOW())");
    $id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES ({$id}, {$ownerId}, 'admin', 1)");
    $fixturePracticeIds[] = $id;
    return $id;
};

$mkConnection = function (int $practiceId) use ($pdo, &$fixtureConnectionIds): int {
    $pdo->exec("INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, created_at)
                VALUES ({$practiceId}, 'open_dental', 'active', 1, NOW())");
    $id = (int)$pdo->lastInsertId();
    $fixtureConnectionIds[] = $id;
    return $id;
};

$mkCase = function (int $practiceId, string $caseId, string $first, string $last, int $archived = 0) use ($pdo, &$fixtureCaseIds): void {
    $fixtureCaseIds[] = $caseId;
    $stmt = $pdo->prepare("INSERT INTO cases_cache
        (case_id, practice_id, patient_first_name, patient_last_name, status, last_update_date, archived)
        VALUES (:cid, :pid, :fn, :ln, 'New', '2026-01-01', :arch)");
    $stmt->execute([
        ':cid' => $caseId,
        ':pid' => $practiceId,
        ':fn'  => PIIEncryption::encrypt($first),
        ':ln'  => PIIEncryption::encrypt($last),
        ':arch' => $archived,
    ]);
};

$mkCaseMapping = function (int $connId, string $labCaseNum, ?string $internalId, ?string $parentPat) use ($pdo): void {
    $stmt = $pdo->prepare("INSERT INTO integration_entity_mappings
        (connection_id, entity_type, external_id, internal_type, internal_id, external_parent_id, last_seen_at)
        VALUES (:c, 'case', :e, 'case', :i, :p, NOW())");
    $stmt->execute([':c' => $connId, ':e' => $labCaseNum, ':i' => $internalId, ':p' => $parentPat]);
};

$mkPatientMapping = function (int $connId, string $patNum, ?string $firstLabCase) use ($pdo): void {
    $stmt = $pdo->prepare("INSERT INTO integration_entity_mappings
        (connection_id, entity_type, external_id, external_parent_id, last_seen_at)
        VALUES (:c, 'patient', :e, :p, NOW())");
    $stmt->execute([':c' => $connId, ':e' => $patNum, ':p' => $firstLabCase]);
};

try {
if ($hasDb) {
    $practiceA = $mkPractice('OD Launch Practice A');
    $practiceB = $mkPractice('OD Launch Practice B');
    $practiceC = $mkPractice('OD Launch Practice C (no connection)');
    $connA = $mkConnection($practiceA);
    $connB = $mkConnection($practiceB);

    // Patient 101: two synced cases (new-style parent stamp).
    $mkCase($practiceA, 'odlt_a1', 'Alice', 'Anderson');
    $mkCase($practiceA, 'odlt_a2', 'Alice', 'Anderson');
    $mkPatientMapping($connA, '101', '9001');
    $mkCaseMapping($connA, '9001', 'odlt_a1', '101');
    $mkCaseMapping($connA, '9002', 'odlt_a2', '101');

    // Patient 102: one legacy case (mapping has no parent stamp; reachable
    // only via the patient mapping's external_parent_id fallback).
    $mkCase($practiceA, 'odlt_a3', 'Bob', 'Barker');
    $mkPatientMapping($connA, '102', '9003');
    $mkCaseMapping($connA, '9003', 'odlt_a3', null);

    // Patient 103: mapped patient, no case mappings at all.
    $mkPatientMapping($connA, '103', null);

    // Patient 104: case mapping exists but the case is archived.
    $mkCase($practiceA, 'odlt_a4', 'Carol', 'Chen', 1);
    $mkPatientMapping($connA, '104', '9004');
    $mkCaseMapping($connA, '9004', 'odlt_a4', '104');

    // Practice B: same PatNum 101 exists there with its own case - must
    // never leak into practice A's resolution.
    $mkCase($practiceB, 'odlt_b1', 'Dave', 'Davis');
    $mkPatientMapping($connB, '101', '8001');
    $mkCaseMapping($connB, '8001', 'odlt_b1', '101');

    // Practices D/E: dedicated toolbar-launch-detection fixtures. D is
    // launched into; E never receives a launch (isolation control).
    $practiceD = $mkPractice('OD Launch Practice D (toolbar)');
    $practiceE = $mkPractice('OD Launch Practice E (toolbar control)');
    $connD = $mkConnection($practiceD);
    $connE = $mkConnection($practiceE);
}

    // =====================================================================
    // A. Resolution
    // =====================================================================
if ($hasDb) {
    echo "--- resolvePatientCases ---\n";

    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceA, '101');
    assertEquals('multi-case: status ok', 'ok', $r['status']);
    sort($r['case_ids']);
    assertEquals('multi-case: both case ids', ['odlt_a1', 'odlt_a2'], $r['case_ids']);
    assertEquals('multi-case: count', 2, $r['case_count']);
    assertEquals('multi-case: decrypted name', 'Alice Anderson', $r['patient_name']);

    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceA, '102');
    assertEquals('legacy fallback: status ok', 'ok', $r['status']);
    assertEquals('legacy fallback: one case', ['odlt_a3'], $r['case_ids']);
    assertEquals('legacy fallback: name', 'Bob Barker', $r['patient_name']);

    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceA, '103');
    assertEquals('mapped patient, no cases: status', 'no_cases', $r['status']);
    assertEquals('mapped patient, no cases: empty', [], $r['case_ids']);

    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceA, '104');
    assertEquals('archived only: status no_cases', 'no_cases', $r['status']);
    assertEquals('archived only: empty ids', [], $r['case_ids']);

    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceA, '999');
    assertEquals('unknown PatNum: no_patient', 'no_patient', $r['status']);
    assertEquals('unknown PatNum: empty', [], $r['case_ids']);

    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceC, '101');
    assertEquals('no connection: no_integration', 'no_integration', $r['status']);

    foreach (['abc', '1;DROP', '', ' 12 ', '12.5', '-4', str_repeat('9', 30)] as $bad) {
        $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceA, $bad);
        assertEquals("invalid '{$bad}': invalid", 'invalid', $r['status']);
    }

    // Cross-practice isolation: practice A must never see B's case.
    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceA, '101');
    assertTrue('isolation: no B case in A result', !in_array('odlt_b1', $r['case_ids'], true));
    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceB, '101');
    assertEquals('isolation: B resolves own case', ['odlt_b1'], $r['case_ids']);
}

    // =====================================================================
    // A5. Toolbar first-launch detection
    // =====================================================================
if ($hasDb) {
    echo "--- toolbar first-launch detection ---\n";

    $configOf = function (int $connId) use ($pdo): ?array {
        $raw = $pdo->prepare("SELECT config_json FROM integration_connections WHERE id = :id");
        $raw->execute([':id' => $connId]);
        $json = $raw->fetchColumn();
        return (is_string($json) && $json !== '') ? json_decode($json, true) : null;
    };

    // Fresh connection: no marker before any launch.
    $cfg = $configOf($connD);
    assertTrue('toolbar: no marker before launch',
        $cfg === null || empty($cfg['toolbar_first_launch_at']));

    // A launch (even for an unknown PatNum) stamps the connection.
    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceD, '999');
    assertEquals('toolbar: launch resolves even w/o patient', 'no_patient', $r['status']);
    $cfg = $configOf($connD);
    assertTrue('toolbar: marker stamped after launch',
        is_array($cfg) && !empty($cfg['toolbar_first_launch_at']));
    $firstTs = $cfg['toolbar_first_launch_at'] ?? null;
    assertTrue('toolbar: marker is a UTC timestamp',
        is_string($firstTs) && (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $firstTs));
    // Only a timestamp - the PatNum and no other PHI are persisted.
    assertTrue('toolbar: PatNum not persisted',
        strpos(json_encode($cfg), '999') === false);

    // Idempotent: a second launch keeps the original timestamp.
    OdPatientLaunch::resolvePatientCases($pdo, $practiceD, '888');
    $cfg = $configOf($connD);
    assertEquals('toolbar: idempotent on second launch', $firstTs,
        $cfg['toolbar_first_launch_at'] ?? null);

    // Isolation: the launch wrote only to D's connection.
    $cfg = $configOf($connE);
    assertTrue('toolbar: control connection untouched',
        $cfg === null || empty($cfg['toolbar_first_launch_at']));

    // Direct call is also idempotent and non-fatal on a bad id.
    OdPatientLaunch::recordToolbarLaunch($pdo, $connD);
    $cfg = $configOf($connD);
    assertEquals('toolbar: direct call preserves marker', $firstTs,
        $cfg['toolbar_first_launch_at'] ?? null);
    try {
        OdPatientLaunch::recordToolbarLaunch($pdo, -1);
        assertTrue('toolbar: bad connection id non-fatal', true);
    } catch (Throwable $e) {
        assertTrue('toolbar: bad connection id non-fatal', false);
    }

    // Projection: toPublicConnection surfaces the marker and never leaks
    // the raw config_json.
    $stmt = $pdo->prepare("SELECT * FROM integration_connections WHERE id = :id");
    $stmt->execute([':id' => $connD]);
    $pub = IntegrationManager::toPublicConnection($pdo, $stmt->fetch(PDO::FETCH_ASSOC));
    assertEquals('toolbar: projection exposes marker', $firstTs, $pub['toolbar_first_launch_at'] ?? null);
    assertTrue('toolbar: projection hides config_json', !array_key_exists('config_json', $pub));
    $stmt->execute([':id' => $connE]);
    $pub = IntegrationManager::toPublicConnection($pdo, $stmt->fetch(PDO::FETCH_ASSOC));
    assertEquals('toolbar: null marker when absent', null, $pub['toolbar_first_launch_at'] ?? null);
    // Malformed config_json must not break the projection.
    $pdo->prepare("UPDATE integration_connections SET config_json = '{bad json' WHERE id = :id")
        ->execute([':id' => $connE]);
    $stmt->execute([':id' => $connE]);
    $pub = IntegrationManager::toPublicConnection($pdo, $stmt->fetch(PDO::FETCH_ASSOC));
    assertEquals('toolbar: malformed config tolerated', null, $pub['toolbar_first_launch_at'] ?? null);

    // Practice with no connection: resolution must not stamp anything.
    $r = OdPatientLaunch::resolvePatientCases($pdo, $practiceC, '101');
    assertEquals('toolbar: no_connection still clean', 'no_integration', $r['status']);
}

    // =====================================================================
    // B. case_ids filter semantics (mirror of list-cases.php rules)
    // =====================================================================
    echo "--- case_ids param semantics ---\n";

    $parse = function (string $raw): array {
        $allowed = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            if ($token !== '' && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $token)) {
                $allowed[$token] = true;
            }
            if (count($allowed) >= 500) break;
        }
        return array_keys($allowed);
    };

    assertEquals('case_ids: parses tokens', ['odlt_a1', 'odlt_a2'], $parse('odlt_a1,odlt_a2'));
    assertEquals('case_ids: drops injection', ['odlt_a1'], $parse("odlt_a1,' OR 1=1-- ,bad id"));
    assertEquals('case_ids: empty for garbage', [], $parse(';;,,  ,<script>'));
    assertTrue('case_ids: cap 500', count($parse(implode(',', array_map(fn($i) => 'c' . $i, range(1, 600))))) === 500);

    // =====================================================================
    // C. Static contract checks
    // =====================================================================
    echo "--- static contracts ---\n";

    $launchPhp = file_get_contents(__DIR__ . '/../od-launch.php');
    assertTrue('od-launch: digits-only PatNum', (bool)preg_match('/\^\\\\d\{1,20\}\$/', $launchPhp));
    assertTrue('od-launch: routes to login when unauthenticated', strpos($launchPhp, 'login.php') !== false);
    assertTrue('od-launch: forwards od_pat to main.php', strpos($launchPhp, "'?od_pat='") !== false || strpos($launchPhp, '?od_pat=') !== false);

    $loginPhp = file_get_contents(__DIR__ . '/../login.php');
    assertTrue('login: stashes pendingOdPatient', strpos($loginPhp, 'pendingOdPatient') !== false);
    assertTrue('login: PatNum digits gate', strpos($loginPhp, 'od_pat') !== false);

    $mainPhp = file_get_contents(__DIR__ . '/../main.php');
    assertTrue('main: preserves od_pat into login URL', (bool)preg_match('/od_pat.*urlencode/', $mainPhp));
    assertTrue('main: stashes pendingOdPatient', strpos($mainPhp, 'pendingOdPatient') !== false);
    assertTrue('main: invokes processPendingOdPatient', strpos($mainPhp, 'processPendingOdPatient()') !== false);
    assertTrue('main: banner markup present', strpos($mainPhp, 'odPatientBanner') !== false);
    assertTrue('main: loads od-launch.js', strpos($mainPhp, 'js/od-launch.js') !== false);

    $api = file_get_contents(__DIR__ . '/../api/od-patient-cases.php');
    assertTrue('api: requires practice context', strpos($api, 'requireValidPracticeContext()') !== false);
    assertTrue('api: rejects non-digit PatNum', (bool)preg_match('/\^\\\\d\{1,20\}\$/', $api));
    assertTrue('api: no PatNum in logs', strpos($api, '$pat') !== false
        && !preg_match('/error_log\([^\n]*\$pat/', $api));

    $list = file_get_contents(__DIR__ . '/../api/list-cases.php');
    assertTrue('list-cases: case_ids handled', strpos($list, "['case_ids']") !== false || strpos($list, 'case_ids') !== false);
    assertTrue('list-cases: token charset validated', strpos($list, 'A-Za-z0-9_\\-]{1,64}') !== false);

    $js = file_get_contents(__DIR__ . '/../js/od-launch.js');
    assertTrue('js: sessionStorage key consumed', strpos($js, 'pendingOdPatient') !== false);
    assertTrue('js: resolver endpoint called', strpos($js, 'api/od-patient-cases.php') !== false);
    assertTrue('js: banner cleared via button', strpos($js, 'odPatientClear') !== false);

    $app = file_get_contents(__DIR__ . '/../js/app.js');
    $cfs = file_get_contents(__DIR__ . '/../js/case-filter-sort.js');
    assertTrue('app.js: case_ids appended in applyFilters', strpos($app, "params.append('case_ids'") !== false);
    assertTrue('case-filter-sort: case_ids appended in query', strpos($cfs, "search.set('case_ids'") !== false);

    // Import path stamps the patient link on new reservations.
    $events = file_get_contents(__DIR__ . '/../api/integrations/IntegrationEvents.php');
    assertTrue('import: externalParentId stamped on reserve',
        strpos($events, 'externalPatientId !== null') !== false
        && strpos($events, 'reserveMapping(') !== false);
    assertTrue('update path: legacy mapping self-heals',
        strpos($events, 'external_parent_id = :pat') !== false);

    // Setup guide + icon exist.
    assertTrue('setup guide exists', file_exists(__DIR__ . '/../docs/open-dental-toolbar-setup.md'));
    $guide = file_get_contents(__DIR__ . '/../docs/open-dental-toolbar-setup.md');
    assertTrue('guide: Program Links steps', strpos($guide, 'Program Links') !== false);
    assertTrue('guide: PatNum substitution documented', strpos($guide, '?pat=[PatNum]') !== false);
    // Workstation-verified config: PatNum embedded in Path, args blank,
    // Enabled required. (OD passes the args field to the browser as process
    // arguments - they never reach the URL.)
    assertTrue('guide: PatNum embedded in Path',
        strpos($guide, 'od-launch.php?pat=[PatNum]') !== false);
    assertTrue('guide: args field left blank',
        (bool)preg_match('/Optional command line arguments.*\(leave blank\)/', $guide));
    assertTrue('guide: Enabled step', strpos($guide, '**Enabled**') !== false);
    $icon = @getimagesize(__DIR__ . '/../images/dentatrak-od-bridge-22.png');
    assertTrue('icon: 22x22 png', $icon && $icon[0] === 22 && $icon[1] === 22);

    // Guided in-app toolbar setup (Settings > Integrations > Open Dental).
    assertTrue('main: guided toolbar block', strpos($mainPhp, 'integrationToolbarSetup-') !== false);
    assertTrue('main: launch URL prefilled', strpos($mainPhp, "od-launch.php';") !== false || strpos($mainPhp, '/od-launch.php') !== false);
    assertTrue('main: PatNum embedded in path', strpos($mainPhp, "od-launch.php?pat=[PatNum]") !== false);
    assertTrue('main: PatNum arg prefilled', strpos($mainPhp, '?pat=[PatNum]') !== false);
    assertTrue('main: Enabled step present', strpos($mainPhp, 'toolbar.step_enabled') !== false);
    assertTrue('main: args shown as leave-blank', strpos($mainPhp, 'field_args_blank') !== false);
    assertTrue('main: copyable fields', strpos($mainPhp, 'od-toolbar-copy') !== false);
    assertTrue('main: icon download link', strpos($mainPhp, 'dentatrak-od-bridge-22.png') !== false);
    assertTrue('main: troubleshooting details', strpos($mainPhp, 'integration-toolbar-troubleshooting') !== false);
    assertTrue('main: launch status badge', strpos($mainPhp, 'integrationToolbarBadge-') !== false);
    assertTrue('main: setup gated to open_dental', strpos($mainPhp, "\$providerKey === 'open_dental'") !== false);
    assertTrue('main: setup uses localized strings', strpos($mainPhp, "settings.integrations.toolbar.title") !== false);
    assertTrue('app.js: renders toolbar setup', strpos($app, 'renderToolbarSetup') !== false);
    assertTrue('app.js: reads launch marker', strpos($app, 'toolbar_first_launch_at') !== false);
    assertTrue('app.js: copy handler wired', strpos($app, 'od-toolbar-copy') !== false);

    // Marker persistence contract: timestamp only, never the PatNum.
    $launchSrc = file_get_contents(__DIR__ . '/../api/integrations/OdPatientLaunch.php');
    assertTrue('marker: stored in config_json', strpos($launchSrc, 'toolbar_first_launch_at') !== false);
    assertTrue('marker: no PatNum stored', !preg_match('/toolbar_first_launch_at[^\n]*\$pat/i', $launchSrc));
    assertTrue('marker: no logging of PatNum', !preg_match('/error_log\([^\n]*pat/i', $launchSrc));
    $mgrSrc = file_get_contents(__DIR__ . '/../api/integrations/IntegrationManager.php');
    assertTrue('projection: exposes marker', strpos($mgrSrc, 'toolbar_first_launch_at') !== false);

    // All toolbar.* keys exist in every locale file.
    $toolbarKeys = ['title','intro','step1','step_enabled','step2_intro','step3','step4','step5',
        'field_description','field_path','field_args','field_args_blank','field_button','copy','copied',
        'icon_download','how_it_works','test_title','test_body','troubleshooting_title',
        'trouble_1','trouble_2','trouble_3','trouble_4','trouble_5','launch_pending','launch_detected'];
    foreach (glob(__DIR__ . '/../locales/*.json') as $lf) {
        $loc = json_decode(file_get_contents($lf), true);
        $tb = $loc['settings']['integrations']['toolbar'] ?? [];
        $missing = array_diff($toolbarKeys, array_keys($tb));
        assertTrue('locale ' . basename($lf) . ': toolbar keys', empty($missing),
            'missing: ' . implode(',', $missing));
    }

} finally {
    // Cleanup fixtures.
    foreach ($fixtureCaseIds as $cid) {
        try { $pdo->prepare("DELETE FROM cases_cache WHERE case_id = :id")->execute([':id' => $cid]); } catch (Throwable $e) {}
    }
    foreach ($fixtureConnectionIds as $cid) {
        try { $pdo->prepare("DELETE FROM integration_connections WHERE id = :id")->execute([':id' => $cid]); } catch (Throwable $e) {}
    }
    foreach ($fixturePracticeIds as $pid) {
        try {
            $pdo->prepare("DELETE FROM practice_users WHERE practice_id = :id")->execute([':id' => $pid]);
            $pdo->prepare("DELETE FROM practices WHERE id = :id")->execute([':id' => $pid]);
        } catch (Throwable $e) {}
    }
    foreach ($fixtureUserIds as $uid) {
        try { $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $uid]); } catch (Throwable $e) {}
    }
    echo "--- fixtures cleaned ---\n";
}

echo "\n============================================\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
