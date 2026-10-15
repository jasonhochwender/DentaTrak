<?php
/**
 * OpenDentalAdapter unit tests (Phase C) - fully offline.
 *
 * Every HTTP interaction is replaced by an injected transport callable, so
 * no network and no Open Dental sandbox is required. Verifies:
 *   - ODFHIR Authorization header construction (and that it never leaks)
 *   - missing-credential failures
 *   - testConnection success/failure mapping
 *   - error normalization (401, 429, 504, network, malformed JSON)
 *   - resource getters incl. patient data minimization
 *   - CanonicalCase normalization (sentinel dates, zero FKs, no case type)
 *   - fetchCases Offset pagination + client-side DateTStamp cursor filter
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/integrations/adapters/OpenDentalAdapter.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: {$msg}\n"; }
    else       { $fail++; echo "FAIL: {$msg}\n"; }
}
function eq($expected, $actual, string $msg): void {
    ok($expected === $actual, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

// The Developer Key is now DentaTrak's central server secret
// (OPEN_DENTAL_DEVELOPER_KEY env) - only the practice Customer Key travels
// through the credential store.
putenv('OPEN_DENTAL_DEVELOPER_KEY=DEV-KEY-UNIT');
$CREDS = ['customer_key' => 'CUST-KEY-UNIT'];

/** Build a transport that captures the request and replays a canned response. */
function mockTransport(array $response, array &$captured = []): callable {
    return function (string $method, string $url, array $headers) use ($response, &$captured) {
        $captured = ['method' => $method, 'url' => $url, 'headers' => $headers];
        return $response;
    };
}

// ---------------------------------------------------------------------
// 1. Authorization header construction + secrecy
// ---------------------------------------------------------------------
$captured = [];
$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => '[]', 'headers' => []], $captured));
$adapter->testConnection($CREDS, []);

$auth = null;
foreach ($captured['headers'] as $h) {
    if (stripos($h, 'Authorization:') === 0) { $auth = $h; }
}
eq('Authorization: ODFHIR DEV-KEY-UNIT/CUST-KEY-UNIT', $auth, 'ODFHIR header format');
eq('GET', $captured['method'], 'testConnection uses GET');
ok(strpos($captured['url'], 'https://api.opendental.com/api/v1/clinics') === 0, 'testConnection hits /clinics');
ok(strpos($captured['url'], 'DEV-KEY') === false && strpos($captured['url'], 'CUST-KEY') === false,
    'credentials never appear in the URL');
ok(in_array('Content-Type: application/json', $captured['headers'], true), 'JSON content-type sent');

// The auth header must never surface through public results.
$res = $adapter->testConnection($CREDS, []);
ok(strpos(json_encode($res), 'DEV-KEY') === false && strpos(json_encode($res), 'ODFHIR') === false,
    'testConnection result contains no credential material');

// ---------------------------------------------------------------------
// 2. Missing credentials - testConnection returns a graceful failure
//    result (not a 500); resource getters still throw for callers.
// ---------------------------------------------------------------------
// Missing central developer key -> server_config_missing (fail closed).
putenv('OPEN_DENTAL_DEVELOPER_KEY');
$r = $adapter->testConnection($CREDS, []);
ok($r['success'] === false, 'missing env developer key -> failure result');
eq('server_config_missing', $r['error_code'] ?? null, 'missing env key error_code');
putenv('OPEN_DENTAL_DEVELOPER_KEY=DEV-KEY-UNIT');

// A legacy stored developer_key in the credential map is ignored entirely -
// the env secret is authoritative.
$r = $adapter->testConnection(['developer_key' => 'LEGACY-STALE', 'customer_key' => 'x'], []);
ok($r['success'] !== null, 'legacy developer_key credential tolerated');
$captured = [];
$adapter2 = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => '[]', 'headers' => []], $captured));
$adapter2->testConnection(['developer_key' => 'LEGACY-STALE', 'customer_key' => 'x'], []);
$auth2 = null;
foreach ($captured['headers'] as $h) {
    if (stripos($h, 'Authorization:') === 0) { $auth2 = $h; }
}
eq('Authorization: ODFHIR DEV-KEY-UNIT/x', $auth2, 'env developer key wins over stored legacy value');

$r = $adapter->testConnection(['customer_key' => 'x'], []);
ok($r['success'] === true, 'customer_key-only credentials succeed with env dev key');

$r = $adapter->testConnection([], []);
ok($r['success'] === false, 'empty credentials -> failure result');
eq('credentials_missing', $r['error_code'] ?? null, 'empty credentials error_code');

// Resource getters propagate the typed exception for worker callers.
try {
    $adapter->getClinics([]);
    ok(false, 'getClinics throws credentials_missing');
} catch (OpenDentalApiException $e) {
    eq('credentials_missing', $e->category, 'getClinics missing-key category');
    ok(strpos($e->getMessage(), 'CUST-KEY') === false, 'exception hides credential value');
}

// ---------------------------------------------------------------------
// 3. testConnection result mapping
// ---------------------------------------------------------------------
$adapter = new OpenDentalAdapter(mockTransport([
    'code' => 200,
    'body' => '[{"ClinicNum":1,"Description":"Test Office","Abbr":"TO"}]',
    'headers' => [],
]));
$r = $adapter->testConnection($CREDS, []);
ok($r['success'] === true, 'testConnection success on 200');
eq('Test Office', $r['external_account_id'], 'external_account_id = clinic Description');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => '[]', 'headers' => []]));
$r = $adapter->testConnection($CREDS, []);
ok($r['success'] === true, 'empty clinics list still verifies (clinics unused)');
eq(null, $r['external_account_id'], 'no account id when clinics unused');

// ---------------------------------------------------------------------
// 4. Error normalization
// ---------------------------------------------------------------------
$adapter = new OpenDentalAdapter(mockTransport(['code' => 401, 'body' => 'bad key SECRET-RESPONSE-BODY', 'headers' => []]));
$r = $adapter->testConnection($CREDS, []);
ok($r['success'] === false, '401 -> failure result');
ok(strpos($r['message'], 'SECRET-RESPONSE-BODY') === false, 'error message has no response body');
ok(strpos($r['message'], 'DEV-KEY') === false && strpos($r['message'], 'CUST-KEY') === false,
    'error message has no credentials');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 0, 'body' => '', 'headers' => []]));
$r = $adapter->testConnection($CREDS, []);
ok($r['success'] === false, 'network failure -> failure result');
ok(strpos($r['message'], 'network') !== false, 'network error categorized');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => '{broken json', 'headers' => []]));
try {
    $adapter->getClinics($CREDS);
    ok(false, 'malformed JSON throws');
} catch (OpenDentalApiException $e) {
    eq('unexpected_response', $e->category, 'malformed JSON category');
}

$adapter = new OpenDentalAdapter(mockTransport(['code' => 429, 'body' => '', 'headers' => ['retry-after' => '5']]));
try {
    $adapter->getClinics($CREDS);
    ok(false, '429 throws');
} catch (OpenDentalApiException $e) {
    eq('rate_limited', $e->category, '429 category');
    ok(strpos($e->getMessage(), '5') !== false, '429 includes Retry-After hint');
}

$adapter = new OpenDentalAdapter(mockTransport(['code' => 504, 'body' => '', 'headers' => []]));
try {
    $adapter->getClinics($CREDS);
    ok(false, '504 throws');
} catch (OpenDentalApiException $e) {
    eq('timeout', $e->category, '504 category');
}

// Open Dental returns HTTP 400 with a bare JSON *string* for operational
// failures; classify the two documented states distinctly.
$adapter = new OpenDentalAdapter(mockTransport([
    'code' => 400,
    'body' => '"The office\'s eConnector is not running. Please contact the office to start their eConnector."',
    'headers' => [],
]));
$r = $adapter->testConnection($CREDS, []);
ok($r['success'] === false, '400 eConnector-offline -> failure result');
eq('econnector_offline', $r['error_code'] ?? null, 'eConnector-offline error_code');
ok(strpos($r['message'], 'CUST-KEY') === false, 'eConnector-offline message has no credentials');

$adapter = new OpenDentalAdapter(mockTransport([
    'code' => 400,
    'body' => '"API key has not been assigned."',
    'headers' => [],
]));
$r = $adapter->testConnection($CREDS, []);
ok($r['success'] === false, '400 key-not-assigned -> failure result');
eq('credentials_missing', $r['error_code'] ?? null, 'key-not-assigned error_code');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 400, 'body' => '"Unrelated validation error."', 'headers' => []]));
$r = $adapter->testConnection($CREDS, []);
eq('bad_request', $r['error_code'] ?? null, 'other 400 stays bad_request');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 500, 'body' => 'oops', 'headers' => []]));
try {
    $adapter->getClinics($CREDS);
    ok(false, '500 throws');
} catch (OpenDentalApiException $e) {
    eq('server_error', $e->category, '500 category');
    ok(strpos($e->getMessage(), 'oops') === false, 'server error has no body text');
}

// ---------------------------------------------------------------------
// 5. Resource getters
// ---------------------------------------------------------------------
$labRow = [
    'LabCaseNum' => 226, 'PatNum' => 33, 'LaboratoryNum' => 1, 'AptNum' => 143,
    'PlannedAptNum' => 0, 'DateTimeDue' => '2022-10-04 14:23:12',
    'DateTimeCreated' => '2022-09-30 14:23:12', 'DateTimeSent' => '2000-01-01 00:00:00',
    'DateTimeRecd' => '0001-01-01 00:00:00', 'DateTimeChecked' => '0001-01-01 00:00:00',
    'ProvNum' => 3, 'Instructions' => 'PFM crown #28', 'LabFee' => 0.0,
    'DateTStamp' => '2022-10-05 14:24:12', 'InvoiceNum' => '',
];

$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => json_encode($labRow), 'headers' => []]));
eq(226, (int)$adapter->getLabCase($CREDS, 226)['LabCaseNum'], 'getLabCase returns row');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 404, 'body' => 'not found', 'headers' => []]));
eq(null, $adapter->getLabCase($CREDS, 999), 'getLabCase 404 -> null');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => json_encode([$labRow, $labRow]), 'headers' => []]));
eq(2, count($adapter->listLabCases($CREDS)), 'listLabCases returns rows');

// Patient minimization: full OD record in, 5 fields out.
$fullPatient = [
    'PatNum' => 33, 'LName' => 'Smith', 'FName' => 'Jane', 'MiddleI' => '',
    'Preferred' => '', 'PatStatus' => 'Patient', 'Gender' => 'Female',
    'Birthdate' => '1976-05-24', 'SSN' => '123-45-6789', 'Address' => '1 Main St',
    'HmPhone' => '5551234', 'Email' => 'j@x.com', 'EstBalance' => 500.0,
    'InsEst' => 200.0, 'WirelessPhone' => '5559999',
];
$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => json_encode($fullPatient), 'headers' => []]));
$pat = $adapter->getPatient($CREDS, 33);
eq(['PatNum','FName','LName','Birthdate','Gender'], array_keys($pat), 'getPatient returns only minimized keys');
ok(!isset($pat['SSN']) && !isset($pat['Address']) && !isset($pat['Email']) && !isset($pat['HmPhone']),
    'no SSN/address/email/phone in minimized patient');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => json_encode([
    'ProvNum' => 3, 'FName' => 'Brian', 'LName' => 'Albert', 'Abbr' => 'DOC1',
    'SSN' => '111222333', 'NationalProvID' => '999',
]), 'headers' => []]));
$prov = $adapter->getProviderByNum($CREDS, 3);
eq('Brian', $prov['FName'], 'getProviderByNum returns record');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => json_encode([
    'LaboratoryNum' => 1, 'Description' => 'Go Smiles Lab', 'Phone' => '555',
]), 'headers' => []]));
eq('Go Smiles Lab', $adapter->getLaboratory($CREDS, 1)['Description'], 'getLaboratory returns record');

$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => json_encode([
    'AptNum' => 143, 'AptDateTime' => '2022-10-10 09:00:00', 'AptStatus' => 'Scheduled',
    'PatNum' => 33, 'Note' => 'PHI note', 'InsPlan1' => 5,
]), 'headers' => []]));
$apt = $adapter->getAppointment($CREDS, 143);
eq(['AptNum','AptDateTime','AptStatus'], array_keys($apt), 'getAppointment minimized keys');
eq('2022-10-10 09:00:00', $apt['AptDateTime'], 'appointment datetime mapped');

// ---------------------------------------------------------------------
// 6. normalizeCase - bare labcase
// ---------------------------------------------------------------------
$adapter = new OpenDentalAdapter();
$c = $adapter->normalizeCase($labRow);
eq('226', $c->externalCaseId, 'LabCaseNum -> externalCaseId');
eq('33', $c->externalPatientId, 'PatNum -> externalPatientId');
eq('3', $c->externalProviderId, 'ProvNum -> externalProviderId');
eq('1', $c->externalLaboratoryId, 'LaboratoryNum -> externalLaboratoryId');
eq('143', $c->externalAppointmentId, 'AptNum -> externalAppointmentId (scheduled wins)');
eq('2022-10-04 14:23:12', $c->dueDate, 'DateTimeDue mapped');
eq('PFM crown #28', $c->instructions, 'Instructions mapped');
eq(null, $c->sourceCaseType, 'no case type invented');
eq(null, $c->metadata['date_time_sent'], '2000-01-01 sentinel -> null');
eq(null, $c->metadata['date_time_recd'], '0001-01-01 sentinel -> null');
eq('2022-10-05 14:24:12', $c->metadata['date_tstamp'], 'DateTStamp kept in metadata');
eq(null, $c->metadata['date_time_checked'], 'sentinel DateTimeChecked -> null');
eq('143', $c->metadata['apt_num'], 'AptNum preserved in metadata');
eq(null, $c->metadata['planned_apt_num'] ?? null, 'PlannedAptNum=0 -> null metadata');

// Zero FKs and empty strings normalize to null.
$zeroRow = array_merge($labRow, ['AptNum' => 0, 'PlannedAptNum' => 0, 'ProvNum' => 0,
    'PatNum' => 0, 'LaboratoryNum' => 0, 'Instructions' => '', 'DateTimeDue' => '0001-01-01 00:00:00']);
$c = $adapter->normalizeCase($zeroRow);
eq(null, $c->externalAppointmentId, 'AptNum=0 -> null');
eq(null, $c->externalProviderId, 'ProvNum=0 -> null');
eq(null, $c->externalPatientId, 'PatNum=0 -> null');
eq(null, $c->externalLaboratoryId, 'LaboratoryNum=0 -> null');
eq(null, $c->instructions, 'empty Instructions -> null');
eq(null, $c->dueDate, 'sentinel due date -> null');

// PlannedAptNum used when AptNum is 0.
$planned = array_merge($labRow, ['AptNum' => 0, 'PlannedAptNum' => 357]);
$c = $adapter->normalizeCase($planned);
eq('357', $c->externalAppointmentId, 'PlannedAptNum fallback');
eq('357', $c->metadata['planned_apt_num'], 'PlannedAptNum preserved in metadata');
eq(null, $c->metadata['apt_num'], 'AptNum=0 -> null metadata');

// ---------------------------------------------------------------------
// 7. normalizeCase - composite with supporting resources
// ---------------------------------------------------------------------
$c = $adapter->normalizeCase([
    'labcase'    => $labRow,
    'patient'    => ['PatNum' => 33, 'FName' => 'Jane', 'LName' => 'Smith', 'Birthdate' => '1976-05-24', 'Gender' => 'Female'],
    'provider'   => ['FName' => 'Brian', 'LName' => 'Albert', 'Abbr' => 'DOC1'],
    'laboratory' => ['Description' => 'Go Smiles Lab'],
    'appointment'=> ['AptNum' => 143, 'AptDateTime' => '2022-10-10 09:00:00', 'AptStatus' => 'Scheduled'],
]);
eq('Jane', $c->patientFirstName, 'patient FName mapped');
eq('Smith', $c->patientLastName, 'patient LName mapped');
eq('1976-05-24', $c->patientDob, 'Birthdate mapped');
eq('Female', $c->patientGender, 'Gender mapped');
eq('Brian Albert', $c->providerName, 'provider display name');
eq('Go Smiles Lab', $c->laboratoryName, 'lab Description -> laboratoryName');
eq('2022-10-10 09:00:00', $c->appointmentDate, 'AptDateTime mapped');

// Provider with no name falls back to Abbr.
$c = $adapter->normalizeCase(['labcase' => $labRow, 'provider' => ['FName' => '', 'LName' => '', 'Abbr' => 'DOC9']]);
eq('DOC9', $c->providerName, 'provider Abbr fallback');

// jsonSerialize remains PHI-safe.
$json = json_encode($c);
ok(strpos($json, 'Smith') === false && strpos($json, 'Jane') === false && strpos($json, '1976') === false,
    'jsonSerialize still excludes PHI');

// Birthdate sentinel normalizes too.
$c = $adapter->normalizeCase(['labcase' => $labRow, 'patient' => ['PatNum' => 33, 'Birthdate' => '0001-01-01']]);
eq(null, $c->patientDob, 'sentinel Birthdate -> null');

// ---------------------------------------------------------------------
// 8. fetchCases - pagination + cursor filter
// ---------------------------------------------------------------------
$page1 = [];
for ($i = 1; $i <= 100; $i++) {
    $page1[] = array_merge($labRow, ['LabCaseNum' => $i, 'DateTStamp' => sprintf('2022-10-%02d 10:00:00', ($i % 28) + 1)]);
}
$page2 = [
    array_merge($labRow, ['LabCaseNum' => 101, 'DateTStamp' => '2022-11-01 10:00:00']),
    array_merge($labRow, ['LabCaseNum' => 102, 'DateTStamp' => '2022-11-02 10:00:00']),
];
$calls = 0;
$adapter = new OpenDentalAdapter(function ($m, $u, $h) use ($page1, $page2, &$calls) {
    $calls++;
    $body = $calls === 1 ? $page1 : $page2;
    return ['code' => 200, 'body' => json_encode($body), 'headers' => []];
});
$r = $adapter->fetchCases($CREDS, [], null, 500);
eq(102, count($r['cases']), 'fetchCases pages through both pages');
eq(2, $calls, 'stops after short page');
ok($r['has_more'] === false, 'has_more false after short page');
eq('2022-11-02 10:00:00', $r['next_cursor'], 'next_cursor = max DateTStamp');

// Cursor filters out already-synced rows client-side.
$calls = 0;
$adapter = new OpenDentalAdapter(function ($m, $u, $h) use ($page1, $page2, &$calls) {
    $calls++;
    return ['code' => 200, 'body' => json_encode($calls === 1 ? $page1 : $page2), 'headers' => []];
});
$r = $adapter->fetchCases($CREDS, [], '2022-10-28 23:59:59', 500);
$ids = array_map(fn($c) => $c->externalCaseId, $r['cases']);
ok(!in_array('1', $ids, true), 'cursor excludes old DateTStamp rows');
ok(in_array('101', $ids, true) && in_array('102', $ids, true), 'cursor keeps newer rows');
eq('2022-11-02 10:00:00', $r['next_cursor'], 'cursor advances to max seen');

// Empty database -> empty result, cursor preserved.
$adapter = new OpenDentalAdapter(mockTransport(['code' => 200, 'body' => '[]', 'headers' => []]));
$r = $adapter->fetchCases($CREDS, [], '2022-01-01 00:00:00', 50);
eq(0, count($r['cases']), 'empty list -> no cases');
eq('2022-01-01 00:00:00', $r['next_cursor'], 'cursor preserved when nothing newer');
ok($r['has_more'] === false, 'no more pages on empty list');

// fetchCases output must not contain credentials.
$blob = json_encode($r);
ok(strpos($blob, 'DEV-KEY') === false && strpos($blob, 'CUST-KEY') === false, 'fetchCases result has no credentials');

// ---------------------------------------------------------------------
echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
