<?php
/**
 * Minimal Open Dental API stub for local automated tests (php -S router).
 *
 * Serves just enough of the documented API surface for the Events POC
 * suite to run fully locally:
 *   GET  /api/v1/clinics                 -> []
 *   GET  /api/v1/subscriptions           -> stored subscription list
 *   POST /api/v1/subscriptions           -> create + return subscription
 *   PUT  /api/v1/subscriptions/{num}     -> update + return subscription
 *   GET  /api/v1/labcases/{num}          -> canned row or 404
 *
 * State lives in a JSON file (OD_STUB_STATE env) so the test can seed
 * scenarios (existing subscriptions, missing labcases, injected 401/429).
 * Requires an "Authorization: ODFHIR ..." header on every call, matching
 * the real API's contract; anything else gets a 401.
 */

$statePath = getenv('OD_STUB_STATE') ?: (sys_get_temp_dir() . '/od-stub-state.json');

function stubState(string $path): array {
    if (is_file($path)) {
        $s = json_decode((string)file_get_contents($path), true);
        if (is_array($s)) {
            return $s;
        }
    }
    return ['subscriptions' => [], 'next_subscription_num' => 100, 'labcases' => [], 'apikeys' => [], 'next_apikey_num' => 1, 'next_labcase_num' => 9000];
}

function stubSave(string $path, array $state): void {
    file_put_contents($path, json_encode($state));
}

function stubJson($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$state = stubState($statePath);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Auth contract: every endpoint requires the ODFHIR header.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($auth, 'ODFHIR ') !== 0) {
    stubJson(['error' => 'Unauthorized'], 401);
}

// Injected failure modes for the labcase fetch.
$failLab = $state['fail_labcase'] ?? null;
if (is_array($failLab) && preg_match('#^/api/v1/labcases/\d+$#', $path)) {
    if (($failLab['code'] ?? 0) === 429) {
        if (isset($failLab['retry_after'])) {
            header('Retry-After: ' . (int)$failLab['retry_after']);
        }
        stubJson(['error' => 'Rate limited'], 429);
    }
    stubJson(['error' => 'Injected failure'], (int)($failLab['code'] ?? 500));
}

// ---- Clinics ------------------------------------------------------------
if ($method === 'GET' && $path === '/api/v1/clinics') {
    stubJson($state['clinics'] ?? []);
}

// ---- SecurityPerms (outbound capability probe) ---------------------------
// Seeded via state['securityperms'] as [{Permission, HasPermission}] rows -
// the shape the real GET /securityperms returns.
if ($method === 'GET' && $path === '/api/v1/securityperms') {
    stubJson($state['securityperms'] ?? []);
}

// ---- Picker list endpoints (providers/laboratories/patient search) -------
if ($method === 'GET' && $path === '/api/v1/providers') {
    stubJson($state['providers_list'] ?? []);
}
if ($method === 'GET' && $path === '/api/v1/laboratories') {
    stubJson($state['laboratories_list'] ?? []);
}
if ($method === 'GET' && $path === '/api/v1/patients/Simple') {
    stubJson($state['patients_simple'] ?? []);
}

// ---- Developer Portal: ApiKeys ------------------------------------------
// Mirrors the documented portal API:
//   GET  /api/v1/apikeys -> list CustomerKeys
//   POST /api/v1/apikeys -> create + return CustomerKey (201)
//   PUT  /api/v1/apikeys -> update {CustomerKey, KeyStatus?, DevRefId?}
if ($path === '/api/v1/apikeys') {
    if ($method === 'GET') {
        stubJson(array_values($state['apikeys']));
    }
    if ($method === 'POST') {
        $num = (int)$state['next_apikey_num'];
        $state['next_apikey_num'] = $num + 1;
        $key = [
            'CustomerKey'      => 'STUBKEY' . str_pad((string)$num, 10, '0', STR_PAD_LEFT),
            'KeyStatus'        => 'Enabled',
            'DateCreated'      => gmdate('Y-m-d'),
            'DateDisabled'     => '0001-01-01',
            'DateTimeAssigned' => '0001-01-01 00:00:00',
            'DevRefId'         => '',
        ];
        $state['apikeys'][] = $key;
        stubSave($statePath, $state);
        stubJson($key, 201);
    }
    if ($method === 'PUT') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['CustomerKey'])) {
            stubJson(['error' => 'BadRequest'], 400);
        }
        foreach ($state['apikeys'] as $i => $k) {
            if ($k['CustomerKey'] === $body['CustomerKey']) {
                if (isset($body['KeyStatus'])) {
                    $state['apikeys'][$i]['KeyStatus'] = $body['KeyStatus'];
                    if ($body['KeyStatus'] === 'DisabledByDeveloper') {
                        $state['apikeys'][$i]['DateDisabled'] = gmdate('Y-m-d');
                    }
                }
                if (isset($body['DevRefId'])) {
                    $state['apikeys'][$i]['DevRefId'] = $body['DevRefId'];
                }
                stubSave($statePath, $state);
                stubJson($state['apikeys'][$i]);
            }
        }
        stubJson(['error' => 'NotFound'], 404);
    }
}

// ---- Subscriptions ------------------------------------------------------
if ($path === '/api/v1/subscriptions') {
    if ($method === 'GET') {
        stubJson(array_values($state['subscriptions']));
    }
    if ($method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['EndPointUrl']) || empty($body['WatchTable'])) {
            stubJson(['error' => 'BadRequest'], 400);
        }
        // Optional: simulate an OD version that rejects a WatchTable
        // (LabCaseDeleted needs v26.1.6+). Seed state['reject_watch_tables'].
        if (in_array($body['WatchTable'], (array)($state['reject_watch_tables'] ?? []), true)) {
            stubJson(['error' => 'BadRequest'], 400);
        }
        $num = (int)$state['next_subscription_num'];
        $state['next_subscription_num'] = $num + 1;
        $sub = [
            'SubscriptionNum'    => $num,
            'EndPointUrl'        => $body['EndPointUrl'],
            'Workstation'        => $body['Workstation'] ?? '',
            'CustomerKey'        => 'STUB',
            'WatchTable'         => $body['WatchTable'],
            'PollingSeconds'     => (int)($body['PollingSeconds'] ?? 0),
            'UiEventType'        => '',
            'DateTimeStart'      => gmdate('Y-m-d H:i:s'),
            'DateTimeStop'       => '0001-01-01 00:00:00',
            'Note'               => $body['Note'] ?? '',
            'DateTimeLastFailure'=> '0001-01-01 00:00:00',
            'FailureReason'      => '',
            'SubsequentFailures' => 0,
            'DateTimeNextRetry'  => '0001-01-01 00:00:00',
        ];
        $state['subscriptions'][] = $sub;
        stubSave($statePath, $state);
        stubJson($sub, 201);
    }
}

if (preg_match('#^/api/v1/subscriptions/(\d+)$#', $path, $m)) {
    $num = (int)$m[1];
    foreach ($state['subscriptions'] as $i => $sub) {
        if ((int)$sub['SubscriptionNum'] !== $num) {
            continue;
        }
        if ($method === 'PUT') {
            $body = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($body)) {
                foreach (['EndPointUrl','Workstation','PollingSeconds','DateTimeStart','DateTimeStop','Note'] as $k) {
                    if (array_key_exists($k, $body)) {
                        $state['subscriptions'][$i][$k] = $body[$k];
                    }
                }
                stubSave($statePath, $state);
            }
            stubJson($state['subscriptions'][$i]);
        }
        if ($method === 'DELETE') {
            array_splice($state['subscriptions'], $i, 1);
            stubSave($statePath, $state);
            stubJson(['ok' => true]);
        }
        stubJson(['error' => 'Method not allowed'], 405);
    }
    stubJson(['error' => 'NotFound'], 404);
}

// ---- LabCases -----------------------------------------------------------
if (preg_match('#^/api/v1/labcases/(\d+)$#', $path, $m)) {
    $num = (string)(int)$m[1];
    if ($method === 'PUT') {
        if (!isset($state['labcases'][$num])) {
            stubJson(['error' => 'NotFound'], 404);
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            stubJson(['error' => 'BadRequest'], 400);
        }
        foreach ($body as $k => $v) {
            $state['labcases'][$num][$k] = $v;
        }
        stubSave($statePath, $state);
        stubJson($state['labcases'][$num]);
    }
    if ($method === 'GET') {
        if (isset($state['labcases'][$num])) {
            stubJson($state['labcases'][$num]);
        }
        stubJson(['error' => 'NotFound'], 404);
    }
}

if ($path === '/api/v1/labcases' && $method === 'POST') {
    // Mirrors the real API: PatNum, LaboratoryNum, and ProvNum are required.
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['PatNum']) || empty($body['LaboratoryNum']) || empty($body['ProvNum'])) {
        stubJson(['error' => 'BadRequest'], 400);
    }
    $num = (int)($state['next_labcase_num'] ?? 9000);
    $state['next_labcase_num'] = $num + 1;
    $case = [
        'LabCaseNum'    => $num,
        'PatNum'        => (int)$body['PatNum'],
        'LaboratoryNum' => (int)$body['LaboratoryNum'],
        'ProvNum'       => (int)$body['ProvNum'],
        'AptNum'        => 0,
        'PlannedAptNum' => 0,
        'DateTimeDue'   => '0001-01-01 00:00:00',
        'DateTimeSent'  => '0001-01-01 00:00:00',
        'DateTimeRecd'  => '0001-01-01 00:00:00',
        'DateTimeChecked' => '0001-01-01 00:00:00',
        'Instructions'  => '',
        'DateTimeCreated' => gmdate('Y-m-d H:i:s'),
    ];
    foreach ($body as $k => $v) {
        $case[$k] = $v;
    }
    $state['labcases'][(string)$num] = $case;
    stubSave($statePath, $state);
    stubJson($case, 201);
}

if ($path === '/api/v1/labcases' && $method === 'GET') {
    // Injected failure for the LIST endpoint (historical-import scans).
    $failList = $state['fail_labcases_list'] ?? null;
    if (is_array($failList)) {
        stubJson(['error' => 'Injected failure'], (int)($failList['code'] ?? 500));
    }
    // Honor documented Offset/Limit pagination so scan chaining is testable.
    $query = [];
    parse_str((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? ''), $query);
    $offset = max(0, (int)($query['Offset'] ?? 0));
    $limit = (int)($query['Limit'] ?? 100);
    if ($limit <= 0) {
        $limit = 100;
    }
    $all = array_values($state['labcases']);
    stubJson(array_slice($all, $offset, $limit));
}

// ---- Supporting resources (Phase E hydration) ----------------------------
// Serves state-seeded records, synthesizes minimal records for unknown ids,
// and 404s ids listed in state['missing_<resource>'] so tests can force
// "referenced record deleted upstream" scenarios.
$supportRoutes = [
    'patients'     => ['PatNum' => null, 'FName' => 'Stub', 'LName' => 'Patient', 'Birthdate' => '1985-06-15', 'Gender' => 'Female'],
    'providers'    => ['ProvNum' => null, 'FName' => 'Stub', 'LName' => 'Provider', 'Abbr' => 'SP'],
    'laboratories' => ['LaboratoryNum' => null, 'Description' => 'Stub Lab'],
    'appointments' => ['AptNum' => null, 'AptDateTime' => '2026-06-01 10:00:00', 'AptStatus' => 'Scheduled'],
];
foreach ($supportRoutes as $resource => $template) {
    if (preg_match("#^/api/v1/{$resource}/(\d+)$#", $path, $m) && $method === 'GET') {
        $num = (string)(int)$m[1];
        if (in_array($num, array_map('strval', (array)($state['missing_' . $resource] ?? [])), true)) {
            stubJson(['error' => 'NotFound'], 404);
        }
        if (isset($state[$resource][$num])) {
            stubJson($state[$resource][$num]);
        }
        $key = array_key_first($template);
        $record = $template;
        $record[$key] = (int)$num;
        stubJson($record);
    }
}

stubJson(['error' => 'NotFound'], 404);
