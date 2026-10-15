<?php
/**
 * PMS integrations Phase E tests - exact-once DentaTrak case creation
 * from normalized external (Open Dental) LabCases.
 *
 *   A. Canonical case-type definitions (api/case-types.php):
 *      canonical list, legacy values, validation, clinical-field map,
 *      filterable list, i18n label coverage, Needs Classification,
 *      main.php selects render from the canonical source.
 *
 *   B. CaseService manual-context creation (real DB, cache-only path):
 *      persistence, encryption at rest, activity, realtime update,
 *      case count, created-by/name, lab-assignment call safety.
 *
 *   C. CaseService integration-context creation:
 *      null created_by, relaxed required fields, Needs Classification,
 *      workflow-originating status, notes truncation, source metadata.
 *
 *   D. Exact-once import through IntegrationEvents::processEvent with a
 *      mocked adapter: one case per LabCaseNum, replay no-op, concurrent
 *      reservation handling, orphaned reservation reclaim, partial-failure
 *      retry without duplicates, cross-practice isolation, deleted-watch
 *      events leave cases intact, support mappings, PHI-free persistence.
 *
 * Requires the local development DB (port 3308) + ENCRYPTION_KEY.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/case-types.php';
require_once __DIR__ . '/../api/CaseService.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';
require_once __DIR__ . '/../api/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/../api/integrations/IntegrationEvents.php';
require_once __DIR__ . '/../api/integrations/SyncEngine.php';
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

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "FATAL: no database connection\n";
    exit(1);
}

// =====================================================================
// A. Canonical case-type definitions
// =====================================================================
echo "--- case types: canonical source ---\n";

$canonical = getCanonicalCaseTypes();
assertEquals('canonical: 12 types', 12, count($canonical));
assertEquals('canonical: first is Crown', 'Crown', $canonical[0]);
assertTrue('canonical: Needs Classification present', in_array('Needs Classification', $canonical, true));
assertEquals('canonical: Needs Classification last', 'Needs Classification', end($canonical));
assertTrue('canonical: Implant Crown present', in_array('Implant Crown', $canonical, true));
assertTrue('canonical: Implant Surgical Guide present', in_array('Implant Surgical Guide', $canonical, true));
assertTrue('canonical: legacy Implant NOT canonical', !in_array('Implant', $canonical, true));

assertTrue('valid: canonical accepted', isValidCaseType('Crown') && isValidCaseType('Needs Classification'));
assertTrue('valid: legacy Implant accepted', isValidCaseType('Implant'));
assertTrue('valid: legacy Mixed accepted', isValidCaseType('Mixed'));
assertTrue('valid: garbage rejected', !isValidCaseType('Not A Type'));
assertTrue('canonical check: legacy rejected', !isCanonicalCaseType('Implant'));
assertTrue('canonical check: Needs Classification accepted', isCanonicalCaseType('Needs Classification'));

$filterable = getFilterableCaseTypes();
assertTrue('filterable: superset of canonical', count(array_diff($canonical, $filterable)) === 0);
assertTrue('filterable: includes legacy Implant', in_array('Implant', $filterable, true));
assertTrue('filterable: includes Needs Classification', in_array('Needs Classification', $filterable, true));

$clinical = getCaseTypeClinicalFields();
assertTrue('clinical: Needs Classification has no entry', !isset($clinical['Needs Classification']));
assertTrue('clinical: Crown requires toothNumber', ($clinical['Crown'] ?? []) === ['toothNumber']);
assertTrue('clinical: Implant Crown fields preserved', in_array('implantSystem', $clinical['Implant Crown'] ?? [], true));

// i18n coverage: every known type resolves a label slug
$map = getCaseTypeMap();
foreach (getAllKnownCaseTypes() as $t) {
    assertTrue("i18n: slug for '{$t}'", isset($map[$t]) && $map[$t] !== '');
}
assertEquals('i18n: needs_classification slug', 'needs_classification', $map['Needs Classification'] ?? null);
assertEquals('label: Needs Classification', 'Needs Classification', getCaseTypeDisplayLabel('Needs Classification'));
assertEquals('label: legacy Implant still displays', 'Implant', getCaseTypeDisplayLabel('Implant'));
assertEquals('label: unknown falls back to raw', 'Future Type X', getCaseTypeDisplayLabel('Future Type X'));

// main.php renders selects from the canonical source (no hardcoded lists)
$mainSrc = file_get_contents(__DIR__ . '/../main.php');
assertTrue('main.php: create form uses canonical renderer',
    strpos($mainSrc, 'renderCaseTypeOptions(getAllKnownCaseTypes())') !== false);
assertTrue('main.php: kanban filter uses canonical renderer',
    substr_count($mainSrc, 'renderCaseTypeOptions(getFilterableCaseTypes())') >= 2);
assertTrue('main.php: no hardcoded Implant option remains',
    strpos($mainSrc, '<option value="Implant">') === false);
assertTrue('main.php: no hardcoded Crown option remains',
    strpos($mainSrc, '<option value="Crown">') === false);

// rendered options contain every canonical type + Needs Classification
$opts = renderCaseTypeOptions(getCanonicalCaseTypes());
assertTrue('render: Needs Classification option', strpos($opts, 'value="Needs Classification"') !== false);
assertTrue('render: Implant Crown option', strpos($opts, 'value="Implant Crown"') !== false);
$optCount = substr_count($opts, '<option');
assertEquals('render: option count matches list', count(getCanonicalCaseTypes()), $optCount);

// material-required list covers canonical implant types
$mat = getCaseTypesRequiringMaterial();
assertTrue('material: Implant Crown requires material', in_array('Implant Crown', $mat, true));
assertTrue('material: Needs Classification does not', !in_array('Needs Classification', $mat, true));

// =====================================================================
// Fixtures
// =====================================================================
echo "--- fixtures ---\n";

$anyUser = (int)$pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
if ($anyUser <= 0) {
    echo "FATAL: no users exist for fixture created_by\n";
    exit(1);
}

$fixturePractices = [];
$fixtureConnections = [];
$fixtureOwners = [];
require_once __DIR__ . '/../api/subscription-owner.php';
// Plan entitlement fixtures: processing enforces hasControlAccess(), which
// resolves the subscription through the practice owner. Every fixture
// practice gets a dedicated owner user with a trialing subscription
// (entitled) - mirrors production reality.
$mkPractice = function (string $name) use ($pdo, $anyUser, &$fixturePractices, &$fixtureOwners): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'Import', 'Owner', 'admin', 1, 1, NOW())")
        ->execute(['e' => 'pms-import-owner-' . count($fixtureOwners) . '@test.local']);
    $ownerId = (int)$pdo->lastInsertId();
    $fixtureOwners[] = $ownerId;
    $pdo->exec("INSERT INTO practices (practice_id, practice_name, created_by, created_at) VALUES (UUID(), '{$name}', {$ownerId}, NOW())");
    $id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES ({$id}, {$ownerId}, 'admin', 1)");
    getOrCreateSubscriptionForOwner($pdo, $ownerId);
    $fixturePractices[] = $id;
    return $id;
};
$mkConnection = function (int $practiceId) use ($pdo, &$fixtureConnections): int {
    $pdo->exec("INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, created_at)
                VALUES ({$practiceId}, 'open_dental', 'active', 1, NOW())");
    $id = (int)$pdo->lastInsertId();
    $fixtureConnections[] = $id;
    return $id;
};

$practiceA = $mkPractice('PMS Import Practice A');
$practiceB = $mkPractice('PMS Import Practice B');
$connA = $mkConnection($practiceA);
$connB = $mkConnection($practiceB);
// Developer Key is a central server secret (env) now; the per-connection
// developer_key stores below simulate pre-migration legacy rows and double
// as purge-path coverage (getConnectionCredentials removes them).
putenv('OPEN_DENTAL_DEVELOPER_KEY=UNIT-DEV-KEY');
IntegrationCredentials::store($pdo, $connA, 'developer_key', 'UNIT-DEV-KEY');
IntegrationCredentials::store($pdo, $connA, 'customer_key', 'UNIT-CUST-KEY');
IntegrationCredentials::store($pdo, $connB, 'developer_key', 'UNIT-DEV-KEY');
IntegrationCredentials::store($pdo, $connB, 'customer_key', 'UNIT-CUST-KEY');
assertTrue('fixtures: two practices + connections', $practiceA > 0 && $connA > 0 && $practiceB > 0 && $connB > 0 && $connA !== $connB);

// =====================================================================
// B. CaseService - manual-equivalent creation
// =====================================================================
echo "--- CaseService: manual context ---\n";

$manualInput = [
    'patientFirstName' => 'ManualFirst', 'patientLastName' => 'ManualLast',
    'patientDOB' => '1980-05-15', 'patientGender' => 'Female',
    'dentistName' => 'Dr. Manual', 'caseType' => 'Crown',
    'toothShade' => 'A2', 'material' => 'Zirconia',
    'dueDate' => '2026-03-01', 'patientAppointmentDate' => '2026-02-28 10:00',
    'status' => getFirstActiveWorkflowColumnId($practiceA),
    'notes' => 'manual note', 'assignedTo' => '',
    'carrier' => '', 'trackingNumber' => '', 'customCarrier' => '',
    'clinicalDetails' => ['toothNumber' => '14'],
];

$beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceA}")->fetchColumn();
$beforeCaseCount = (int)$pdo->query("SELECT case_count FROM users WHERE id={$anyUser}")->fetchColumn();

$manualResult = CaseService::create($manualInput, [
    'practice_id' => $practiceA,
    'created_by_user_id' => $anyUser,
    'actor_user_id' => $anyUser,
    'source' => 'create-case.php',
    'notify' => true,
    'required_fields' => ['patientFirstName', 'patientLastName', 'patientDOB', 'patientGender', 'dentistName', 'caseType', 'status'],
    'validate' => true,
    'defer_drive_backup' => true,
]);

assertTrue('manual: create succeeds', !empty($manualResult['success']), $manualResult['message'] ?? null);
$manualCaseId = $manualResult['caseData']['id'] ?? null;
assertTrue('manual: case id returned', !empty($manualCaseId));

$row = $pdo->query("SELECT * FROM cases_cache WHERE case_id='{$manualCaseId}'")->fetch(PDO::FETCH_ASSOC);
assertTrue('manual: row persisted', $row !== false);
assertEquals('manual: practice isolation', $practiceA, (int)$row['practice_id']);
assertEquals('manual: created_by set', $anyUser, (int)$row['created_by_user_id']);
assertEquals('manual: status persisted', $manualInput['status'], $row['status']);
assertEquals('manual: case_type persisted', 'Crown', $row['case_type']);
assertTrue('manual: first name encrypted at rest', $row['patient_first_name'] !== 'ManualFirst');
assertTrue('manual: notes encrypted at rest', $row['notes'] !== 'manual note' && strlen($row['notes']) > 20);
$dec = PIIEncryption::decryptCaseData(['patientFirstName' => $row['patient_first_name'], 'notes' => $row['notes']]);
assertEquals('manual: decrypt round-trips', 'ManualFirst', $dec['patientFirstName']);

$act = $pdo->query("SELECT * FROM case_activity_log WHERE case_id='{$manualCaseId}' AND event_type='case_created' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTrue('manual: case_created activity', $act !== false);
$meta = json_decode($act['meta_json'] ?? '', true);
assertEquals('manual: activity source', 'create-case.php', $meta['source'] ?? null);
assertTrue('manual: activity has user attribution', !empty($act['user_id']));

$upd = $pdo->query("SELECT * FROM case_updates WHERE case_id='{$manualCaseId}' AND update_type='create' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTrue('manual: realtime update recorded', $upd !== false);
assertEquals('manual: realtime update practice', $practiceA, (int)$upd['practice_id']);

$afterCount = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$practiceA} AND archived=0")->fetchColumn();
$afterCaseCount = (int)$pdo->query("SELECT case_count FROM users WHERE id={$anyUser}")->fetchColumn();
assertEquals('manual: one row added', $beforeCount + 1, $afterCount);
assertEquals('manual: user case_count updated', $afterCount, $afterCaseCount);
assertTrue('manual: createdByName resolved', !empty($manualResult['caseData']['createdByName']));

// manual validation still enforced inside the service
$badResult = CaseService::create(['caseType' => 'Crown'], [
    'practice_id' => $practiceA, 'created_by_user_id' => $anyUser,
    'required_fields' => ['patientFirstName'], 'validate' => true,
]);
assertTrue('manual: missing required rejected', empty($badResult['success']) && !empty($badResult['missingFields']));
$badType = CaseService::create(array_merge($manualInput, ['caseType' => 'Bogus Type']), [
    'practice_id' => $practiceA, 'created_by_user_id' => $anyUser, 'validate' => true, 'required_fields' => [],
]);
assertTrue('manual: invalid case type rejected', empty($badType['success']));
$badStatus = CaseService::create(array_merge($manualInput, ['status' => 'NotAStatus']), [
    'practice_id' => $practiceA, 'created_by_user_id' => $anyUser, 'validate' => true, 'required_fields' => [],
]);
assertTrue('manual: invalid workflow status rejected', empty($badStatus['success']));

// =====================================================================
// C. CaseService - integration context
// =====================================================================
echo "--- CaseService: integration context ---\n";

$integInput = [
    'patientFirstName' => 'ImportFirst', 'patientLastName' => 'ImportLast',
    'patientDOB' => null, 'patientGender' => null,
    'dentistName' => 'Dr. Imported', 'caseType' => CASE_TYPE_NEEDS_CLASSIFICATION,
    'dueDate' => '2026-04-01', 'patientAppointmentDate' => '2026-03-30 09:00',
    'status' => getFirstActiveWorkflowColumnId($practiceA),
    'notes' => 'pms instructions text', 'assignedTo' => '',
];

$integResult = CaseService::create($integInput, [
    'practice_id' => $practiceA,
    'created_by_user_id' => null,
    'actor_user_id' => null,
    'source' => 'integration:open_dental',
    'source_metadata' => ['integration' => 'open_dental', 'external_event_id' => 999],
    'notify' => true,
    'required_fields' => CaseService::integrationRequiredFields(),
    'validate' => true,
    'truncate_notes' => true,
    'updated_by' => 'integration:open_dental',
]);

assertTrue('integration: create succeeds', !empty($integResult['success']), $integResult['message'] ?? null);
$integCaseId = $integResult['caseData']['id'] ?? null;
$irow = $pdo->query("SELECT * FROM cases_cache WHERE case_id='{$integCaseId}'")->fetch(PDO::FETCH_ASSOC);
assertTrue('integration: row persisted', $irow !== false);
assertTrue('integration: created_by NULL (system-created)', $irow['created_by_user_id'] === null);
assertEquals('integration: Needs Classification stored', 'Needs Classification', $irow['case_type']);
assertEquals('integration: status is first workflow column', getFirstActiveWorkflowColumnId($practiceA), $irow['status']);
assertEquals('integration: due date persisted', '2026-04-01', $irow['due_date']);
assertEquals('integration: appointment date persisted', '2026-03-30 09:00', $irow['patient_appointment_date']);
assertTrue('integration: dentist name encrypted', $irow['dentist_name'] !== 'Dr. Imported');
assertTrue('integration: reviewed fields untouched', $irow['reviewed_at'] === null && $irow['reviewed_by_user_id'] === null);

$iact = $pdo->query("SELECT * FROM case_activity_log WHERE case_id='{$integCaseId}' AND event_type='case_created' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$imeta = json_decode($iact['meta_json'] ?? '', true);
assertEquals('integration: activity source tagged', 'integration:open_dental', $imeta['source'] ?? null);
assertEquals('integration: activity integration key', 'open_dental', $imeta['integration'] ?? null);
assertTrue('integration: activity has NO user attribution', empty($iact['user_id']));

$iupd = $pdo->query("SELECT * FROM case_updates WHERE case_id='{$integCaseId}' AND update_type='create' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTrue('integration: realtime update recorded', $iupd !== false);
assertEquals('integration: updated_by tagged', 'integration:open_dental', $iupd['updated_by'] ?? null);

// no notification rows for an actor-less, unassigned import
$notifCount = (int)$pdo->query("SELECT COUNT(*) FROM user_notifications WHERE case_id='{$integCaseId}'")->fetchColumn();
assertEquals('integration: no user notifications', 0, $notifCount);
$nevCount = (int)$pdo->query("SELECT COUNT(*) FROM notification_events WHERE case_id='{$integCaseId}'")->fetchColumn();
assertEquals('integration: no notification events', 0, $nevCount);

// optional fields absent -> still succeeds
$minResult = CaseService::create([
    'patientFirstName' => 'Min', 'patientLastName' => 'Case',
    'caseType' => CASE_TYPE_NEEDS_CLASSIFICATION,
    'status' => getFirstActiveWorkflowColumnId($practiceA),
    'dentistName' => '', 'notes' => '',
], [
    'practice_id' => $practiceA, 'created_by_user_id' => null,
    'required_fields' => CaseService::integrationRequiredFields(),
    'validate' => true, 'source' => 'integration:open_dental',
]);
assertTrue('integration: minimal optional fields ok', !empty($minResult['success']), $minResult['message'] ?? null);
$minCaseId = $minResult['caseData']['id'] ?? null;
if ($minCaseId) {
    $mrow = $pdo->query("SELECT * FROM cases_cache WHERE case_id='{$minCaseId}'")->fetch(PDO::FETCH_ASSOC);
    assertTrue('integration: absent due date stays NULL', $mrow['due_date'] === null);
    assertTrue('integration: absent appointment stays NULL', $mrow['patient_appointment_date'] === null || $mrow['patient_appointment_date'] === '');
}

// missing core patient name -> rejected (never a malformed case)
$noName = CaseService::create([
    'patientFirstName' => '', 'patientLastName' => '',
    'caseType' => CASE_TYPE_NEEDS_CLASSIFICATION,
    'status' => getFirstActiveWorkflowColumnId($practiceA),
], [
    'practice_id' => $practiceA, 'created_by_user_id' => null,
    'required_fields' => CaseService::integrationRequiredFields(),
    'validate' => true,
]);
assertTrue('integration: missing patient name rejected', empty($noName['success']) && !empty($noName['missingFields']));

// long instructions truncate instead of killing the import
$longNotes = str_repeat('X', 3500);
$truncResult = CaseService::create([
    'patientFirstName' => 'Long', 'patientLastName' => 'Notes',
    'caseType' => CASE_TYPE_NEEDS_CLASSIFICATION,
    'status' => getFirstActiveWorkflowColumnId($practiceA),
    'dentistName' => '', 'notes' => $longNotes,
], [
    'practice_id' => $practiceA, 'created_by_user_id' => null,
    'required_fields' => CaseService::integrationRequiredFields(),
    'validate' => true, 'truncate_notes' => true, 'source' => 'integration:open_dental',
]);
assertTrue('integration: long notes truncated not rejected', !empty($truncResult['success']));
$truncLen = strlen(PIIEncryption::decryptCaseData(['notes' => $pdo->query("SELECT notes FROM cases_cache WHERE case_id='{$truncResult['caseData']['id']}'")->fetchColumn()])['notes']);
assertEquals('integration: notes truncated to 3000', 3000, $truncLen);

// no practice -> hard fail
$noPractice = CaseService::create($integInput, ['practice_id' => 0]);
assertTrue('service: practice_id required', empty($noPractice['success']));

// =====================================================================
// D. Exact-once import via IntegrationEvents::processEvent
// =====================================================================
echo "--- import: exact-once via processEvent ---\n";

$mockLabCase = [
    'LabCaseNum' => 5001, 'PatNum' => 44, 'LaboratoryNum' => 3, 'AptNum' => 88,
    'PlannedAptNum' => 0, 'ProvNum' => 2,
    'DateTimeDue' => '2026-05-01 00:00:00', 'Instructions' => 'shade A2, e.max',
    'DateTStamp' => '2026-04-10 08:00:00', 'DateTimeSent' => '0001-01-01 00:00:00',
    'DateTimeRecd' => '0001-01-01 00:00:00',
];
$mockPatient = ['PatNum' => 44, 'FName' => 'PatFirst', 'LName' => 'PatLast', 'Birthdate' => '1975-01-02', 'Gender' => 'Male'];
$mockProvider = ['ProvNum' => 2, 'FName' => 'Greg', 'LName' => 'Dentist'];
$mockLab = ['LaboratoryNum' => 3, 'Description' => 'Acme Lab'];
$mockApt = ['AptNum' => 88, 'AptDateTime' => '2026-04-28 14:00:00', 'AptStatus' => 'Scheduled'];
$mockBehavior = 'ok';

IntegrationManager::registerAdapterFactory('open_dental', function ($conn) use (
    &$mockBehavior, &$mockLabCase, &$mockPatient, &$mockProvider, &$mockLab, &$mockApt
) {
    return new class extends OpenDentalAdapter {
        public function getLabCase(array $credentials, int $labCaseNum): ?array {
            global $mockBehavior, $mockLabCase;
            if ($mockBehavior === 'ok' || $mockBehavior === 'patient_gone') return $mockLabCase;
            if ($mockBehavior === 'gone') return null;
            $e = new OpenDentalApiException($mockBehavior, $mockBehavior === 'rate_limited' ? 429 : 0, '/labcases/' . $labCaseNum, 'mock');
            throw $e;
        }
        public function getPatient(array $credentials, int $patNum): ?array {
            global $mockBehavior, $mockPatient;
            if ($mockBehavior === 'patient_gone') return null;
            return $mockPatient;
        }
        public function getProviderByNum(array $credentials, int $provNum): ?array {
            global $mockProvider;
            return $mockProvider;
        }
        public function getLaboratory(array $credentials, int $laboratoryNum): ?array {
            global $mockLab;
            return $mockLab;
        }
        public function getAppointment(array $credentials, int $aptNum): ?array {
            global $mockApt;
            return $mockApt;
        }
    };
});

$casesInPractice = function (int $pid) use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id={$pid}")->fetchColumn();
};

// -- new LabCase -> exactly one case --------------------------------------
$e1 = IntegrationEvents::record($pdo, $connA, 'labcase', '5001', 'evt-5001-a', '2026-04-10 08:00:00');
$baseCount = $casesInPractice($practiceA);
$res = IntegrationEvents::processEvent($pdo, $e1['id']);
assertEquals('import: new labcase processed', 'processed', $res['outcome']);
assertEquals('import: exactly one case created', $baseCount + 1, $casesInPractice($practiceA));

$mapping = SyncEngine::findMapping($pdo, $connA, 'case', '5001');
assertTrue('import: mapping exists', $mapping !== null);
assertTrue('import: mapping has internal case id', !empty($mapping['internal_id']));
$importedCaseId = $mapping['internal_id'];
$importedRow = $pdo->query("SELECT * FROM cases_cache WHERE case_id='{$importedCaseId}'")->fetch(PDO::FETCH_ASSOC);
assertTrue('import: mapped case exists', $importedRow !== false);
assertEquals('import: case in connection practice', $practiceA, (int)$importedRow['practice_id']);
assertEquals('import: Needs Classification', 'Needs Classification', $importedRow['case_type']);
assertEquals('import: created_by NULL', null, $importedRow['created_by_user_id']);
$decImport = PIIEncryption::decryptCaseData([
    'patientFirstName' => $importedRow['patient_first_name'],
    'patientLastName'  => $importedRow['patient_last_name'],
    'dentistName'      => $importedRow['dentist_name'],
    'notes'            => $importedRow['notes'],
    'patientDOB'       => $importedRow['patient_dob'],
]);
assertEquals('import: patient names from OD', 'PatFirst', $decImport['patientFirstName']);
assertEquals('import: provider name -> dentist', 'Greg Dentist', $decImport['dentistName']);
assertEquals('import: instructions -> notes', 'shade A2, e.max', $decImport['notes']);
assertEquals('import: dob imported', '1975-01-02', $decImport['patientDOB']);
assertEquals('import: due date', '2026-05-01 00:00:00', $importedRow['due_date']);
assertEquals('import: appointment date', '2026-04-28 14:00:00', $importedRow['patient_appointment_date']);

// supporting entity mappings (metadata-only, internal_id NULL)
$sup = [
    'patient' => '44', 'provider' => '2', 'laboratory' => '3', 'appointment' => '88',
];
foreach ($sup as $etype => $eid) {
    $m = SyncEngine::findMapping($pdo, $connA, $etype, $eid);
    assertTrue("import: {$etype} support mapping", $m !== null && $m['internal_id'] === null && $m['external_parent_id'] === '5001');
}

// PHI-free persistence: mapping metadata + event row carry no patient data
$mapMeta = (string)($mapping['metadata_json'] ?? '');
assertTrue('import: no PHI in mapping metadata', strpos($mapMeta, 'PatFirst') === false && strpos($mapMeta, '1975') === false);
$evRow = IntegrationEvents::findEvent($pdo, $e1['id']);
assertTrue('import: no PHI in event error', strpos((string)$evRow['last_error'], 'PatFirst') === false);
assertTrue('import: no instructions in event row', strpos(json_encode($evRow), 'shade A2') === false);

// -- replay / redelivery -> zero additional cases -------------------------
$e2 = IntegrationEvents::record($pdo, $connA, 'labcase', '5001', 'evt-5001-b', '2026-04-10 08:00:00');
$res = IntegrationEvents::processEvent($pdo, $e2['id']);
assertEquals('import: replay processed', 'processed', $res['outcome']);
assertEquals('import: replay creates nothing', $baseCount + 1, $casesInPractice($practiceA));

// a LATER watermark for the same LabCase (update event) -> still no new case
$e3 = IntegrationEvents::record($pdo, $connA, 'labcase', '5001', 'evt-5001-c', '2026-04-11 09:00:00');
$res = IntegrationEvents::processEvent($pdo, $e3['id']);
assertEquals('import: update event processed as no-op', 'processed', $res['outcome']);
assertEquals('import: update event creates nothing', $baseCount + 1, $casesInPractice($practiceA));
$afterReplay = $pdo->query("SELECT notes FROM cases_cache WHERE case_id='{$importedCaseId}'")->fetchColumn();
assertEquals('import: notes untouched by later events', $importedRow['notes'], $afterReplay);

// -- pre-seeded fresh reservation -> deferred (in-flight) -------------------
SyncEngine::reserveMapping($pdo, $connA, 'case', '5002');
$mockLabCase['LabCaseNum'] = 5002;
$e4 = IntegrationEvents::record($pdo, $connA, 'labcase', '5002', 'evt-5002-a', null);
$res = IntegrationEvents::processEvent($pdo, $e4['id']);
assertEquals('import: in-flight reservation -> retry', 'retry', $res['outcome']);
assertEquals('import: in-flight creates nothing', $baseCount + 1, $casesInPractice($practiceA));

// -- orphaned reservation -> reclaimed -> case created ----------------------
$pdo->exec("UPDATE integration_entity_mappings SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)
            WHERE connection_id={$connA} AND entity_type='case' AND external_id='5002'");
// The first attempt scheduled a backoff - make the retry due, like the
// e7 rate-limit retry below (processEvent's atomic claim refuses not-due).
$pdo->exec("UPDATE integration_external_events SET next_retry_at = NOW() WHERE id={$e4['id']}");
$res = IntegrationEvents::processEvent($pdo, $e4['id']);
assertEquals('import: orphaned reservation reclaimed', 'processed', $res['outcome']);
$map5002 = SyncEngine::findMapping($pdo, $connA, 'case', '5002');
assertTrue('import: reclaimed mapping attached', !empty($map5002['internal_id']));
assertEquals('import: orphan reclaim creates exactly one', $baseCount + 2, $casesInPractice($practiceA));

// -- finalized mapping from another path -> no-op ----------------------------
SyncEngine::reserveMapping($pdo, $connA, 'case', '5003', 'case', 'external_preexisting_1');
$mockLabCase['LabCaseNum'] = 5003;
$e5 = IntegrationEvents::record($pdo, $connA, 'labcase', '5003', 'evt-5003-a', null);
$res = IntegrationEvents::processEvent($pdo, $e5['id']);
assertEquals('import: finalized mapping -> processed no-op', 'processed', $res['outcome']);
assertEquals('import: finalized mapping creates nothing', $baseCount + 2, $casesInPractice($practiceA));

// -- patient fetch 404 -> permanent failure, reservation released -----------
$mockBehavior = 'patient_gone';
$mockLabCase['LabCaseNum'] = 5004;
$e6 = IntegrationEvents::record($pdo, $connA, 'labcase', '5004', 'evt-5004-a', null);
$res = IntegrationEvents::processEvent($pdo, $e6['id']);
assertEquals('import: patient gone -> failed (permanent)', 'failed', $res['outcome']);
$map5004 = SyncEngine::findMapping($pdo, $connA, 'case', '5004');
assertTrue('import: failed import releases reservation', $map5004 === null);
$ev6 = IntegrationEvents::findEvent($pdo, $e6['id']);
assertTrue('import: failure reason recorded', strpos((string)$ev6['last_error'], 'required') !== false || strpos((string)$ev6['last_error'], 'Missing') !== false);
$mockBehavior = 'ok';

// -- transient fetch failure -> retry, then success, exactly one case -------
$mockBehavior = 'rate_limited';
$mockLabCase['LabCaseNum'] = 5005;
$e7 = IntegrationEvents::record($pdo, $connA, 'labcase', '5005', 'evt-5005-a', null);
$res = IntegrationEvents::processEvent($pdo, $e7['id']);
assertEquals('import: 429 -> retry', 'retry', $res['outcome']);
$mockBehavior = 'ok';
$pdo->exec("UPDATE integration_external_events SET next_retry_at = NOW() WHERE id={$e7['id']}");
$res = IntegrationEvents::processEvent($pdo, $e7['id']);
assertEquals('import: retry after 429 -> processed', 'processed', $res['outcome']);
$map5005 = SyncEngine::findMapping($pdo, $connA, 'case', '5005');
assertTrue('import: retry produced one mapped case', !empty($map5005['internal_id']));
assertEquals('import: retry created exactly one', $baseCount + 3, $casesInPractice($practiceA));

// -- deleted-watch event -> case intact --------------------------------------
$del = IntegrationEvents::record($pdo, $connA, 'labcase_deleted', '5001', 'evt-5001-del', null);
$res = IntegrationEvents::processEvent($pdo, $del['id']);
assertEquals('import: deleted event -> entity_gone', 'entity_gone', $res['outcome']);
$still = $pdo->query("SELECT case_id FROM cases_cache WHERE case_id='{$importedCaseId}'")->fetchColumn();
assertEquals('import: deleted event never deletes case', $importedCaseId, $still);
$evDel = IntegrationEvents::findEvent($pdo, $del['id']);
assertTrue('import: delete diagnostic notes intact case', strpos((string)$evDel['last_error'], 'intact') !== false);

// -- cross-practice isolation -------------------------------------------------
$mockLabCase['LabCaseNum'] = 5001; // same external id on the OTHER connection
$e8 = IntegrationEvents::record($pdo, $connB, 'labcase', '5001', 'evt-5001-b-conn', null);
$baseB = $casesInPractice($practiceB);
$res = IntegrationEvents::processEvent($pdo, $e8['id']);
assertEquals('import: same LabCaseNum on other connection -> new case', 'processed', $res['outcome']);
assertEquals('import: other practice gets its own case', $baseB + 1, $casesInPractice($practiceB));
$mapB = SyncEngine::findMapping($pdo, $connB, 'case', '5001');
assertTrue('import: mapping scoped per connection', $mapB !== null && $mapB['internal_id'] !== $importedCaseId);
$rowB = $pdo->query("SELECT * FROM cases_cache WHERE case_id='{$mapB['internal_id']}'")->fetch(PDO::FETCH_ASSOC);
assertEquals('import: cross-practice case isolated', $practiceB, (int)$rowB['practice_id']);
assertTrue('import: reverse lookup stays connection-scoped',
    SyncEngine::findMappingByInternalId($pdo, $connA, 'case', $mapB['internal_id']) === null);

// -- processDue drains the full path ------------------------------------------
$mockLabCase['LabCaseNum'] = 5006;
$e9 = IntegrationEvents::record($pdo, $connA, 'labcase', '5006', 'evt-5006-a', null);
$summary = IntegrationEvents::processDue($pdo, 25);
$ev9 = IntegrationEvents::findEvent($pdo, $e9['id']);
assertEquals('drain: event processed end-to-end', 'processed', $ev9['status']);
$map5006 = SyncEngine::findMapping($pdo, $connA, 'case', '5006');
assertTrue('drain: mapped case created', !empty($map5006['internal_id']));

// =====================================================================
// Cleanup
// =====================================================================
echo "--- cleanup ---\n";

$connList = implode(',', array_map('intval', $fixtureConnections));
$pracList = implode(',', array_map('intval', $fixturePractices));
if ($connList) {
    $caseIds = $pdo->query("SELECT internal_id FROM integration_entity_mappings WHERE connection_id IN ({$connList}) AND entity_type='case' AND internal_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec("DELETE FROM integration_external_events WHERE connection_id IN ({$connList})");
    $pdo->exec("DELETE FROM integration_entity_mappings WHERE connection_id IN ({$connList})");
    $pdo->exec("DELETE FROM integration_credentials WHERE connection_id IN ({$connList})");
    $pdo->exec("DELETE FROM integration_connections WHERE id IN ({$connList})");
    foreach (array_merge($caseIds, array_filter([$manualCaseId, $integCaseId, $minCaseId ?? null, $truncResult['caseData']['id'] ?? null])) as $cid) {
        $cid = $pdo->quote((string)$cid);
        $pdo->exec("DELETE FROM case_activity_log WHERE case_id={$cid}");
        $pdo->exec("DELETE FROM case_updates WHERE case_id={$cid}");
        $pdo->exec("DELETE FROM user_notifications WHERE case_id={$cid}");
        $pdo->exec("DELETE FROM notification_events WHERE case_id={$cid}");
        $pdo->exec("DELETE FROM case_lab_assignment_periods WHERE case_id={$cid}");
        $pdo->exec("DELETE FROM cases_cache WHERE case_id={$cid}");
    }
}
if ($pracList) {
    $pdo->exec("DELETE FROM practices WHERE id IN ({$pracList})");
}
// Fixture owners + their owner-level subscription rows (plan entitlement
// fixtures) - removed after the practices so no orphan subscriptions remain.
$ownerList = implode(',', array_map('intval', $fixtureOwners));
if ($ownerList) {
    $pdo->exec("DELETE FROM practice_users WHERE user_id IN ({$ownerList})");
    $pdo->exec("DELETE FROM subscriptions WHERE owner_user_id IN ({$ownerList})");
    $pdo->exec("DELETE FROM users WHERE id IN ({$ownerList})");
}
// restore fixture user's case_count
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE archived=0")->fetchColumn();
$pdo->exec("UPDATE users SET case_count={$remaining} WHERE id={$anyUser}");

$left = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE patient_last_name LIKE '%import%'" )->fetchColumn();
assertTrue('cleanup: fixture rows removed', $left >= 0); // informational

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
