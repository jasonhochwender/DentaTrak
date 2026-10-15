<?php
// Send Case to Open Dental API endpoint (Phase 3 outbound create)
//
// Actions:
//   check           - resolve a case's outbound-create eligibility:
//                     current sync state, resolved remote ids, and the
//                     picker data (patients/providers/laboratories) for
//                     anything still unmapped. Read-only.
//   search_patients - refine the patient picker: {lname,fname,birthdate}
//                     -> GET /patients/Simple candidates. Read-only.
//   send            - persist any user-picked entity mappings and enqueue
//                     a create_labcase outbound event. The actual remote
//                     POST happens in the worker; this endpoint never
//                     writes to Open Dental directly.
//
// All gates apply: SHOW_PMS_INTEGRATIONS + PMS_OUTBOUND_SYNC flags, an
// active connection, outbound.enabled AND outbound.create_enabled. When
// any gate fails, every action reports the same inert state - the UI
// button is also hidden server-side, so reaching here without gates is a
// no-op rather than an error path.
require_once __DIR__ . '/session.php'; // centralized session handling
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/integrations/register-adapters.php';

setApiSecurityHeaders();

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
requireCsrfToken();

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$body   = json_decode(file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];
$action = (string)($body['action'] ?? 'check');
$caseId = trim((string)($body['case_id'] ?? ''));
$userId = $_SESSION['db_user_id'] ?? null;

if ($caseId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('od_send.case_required')]);
    exit;
}

// The case must exist inside the verified practice - case ids are
// unguessable but the boundary is always enforced explicitly.
$caseStmt = $pdo->prepare("SELECT practice_id FROM cases_cache WHERE case_id = :id LIMIT 1");
$caseStmt->execute([':id' => $caseId]);
$casePractice = $caseStmt->fetchColumn();
if ($casePractice === false || (int)$casePractice !== (int)$currentPracticeId) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => t('od_send.not_found')]);
    exit;
}

/**
 * Build picker payloads for the missing entity kinds, best-effort: a
 * remote read failure leaves the picker empty with remote_error set so
 * the dialog can say "Open Dental could not be reached" instead of
 * silently showing nothing.
 */
function odSendPickers($adapter, $credentials, array $needs, array $res): array {
    $pickers = [];
    $remoteError = null;
    try {
        if (in_array('provider', $needs, true) && method_exists($adapter, 'listProviders')) {
            $pickers['providers'] = array_values(array_filter(
                $adapter->listProviders($credentials),
                function ($p) { return ($p['IsHidden'] ?? 'false') !== 'true'; }));
        }
        if (in_array('laboratory', $needs, true) && method_exists($adapter, 'listLaboratories')) {
            $pickers['laboratories'] = array_values(array_filter(
                $adapter->listLaboratories($credentials),
                function ($l) { return ($l['IsHidden'] ?? 'false') !== 'true'; }));
        }
        if (in_array('patient', $needs, true) && method_exists($adapter, 'searchPatients')) {
            $q = $res['patient_search'] ?? [];
            $filters = [];
            if (!empty($q['lname'])) { $filters['LName'] = $q['lname']; }
            if (!empty($q['fname'])) { $filters['FName'] = $q['fname']; }
            if (!empty($q['birthdate'])) {
                $ts = strtotime((string)$q['birthdate']);
                if ($ts !== false) { $filters['Birthdate'] = gmdate('Y-m-d', $ts); }
            }
            $pickers['patient_candidates'] = $filters === []
                ? []
                : $adapter->searchPatients($credentials, $filters);
        }
    } catch (Throwable $e) {
        $remoteError = $e instanceof OpenDentalApiException ? $e->category : 'unexpected';
    }
    $pickers['remote_error'] = $remoteError;
    return $pickers;
}

try {
    $res = IntegrationOutbound::resolveCaseCreate($pdo, (int)$currentPracticeId, $caseId);
    $connection = $res['connection'] ?? null;
    $adapter = null;
    $credentials = null;
    if ($connection) {
        $adapter = IntegrationManager::getAdapter($connection);
        $credentials = IntegrationManager::getConnectionCredentials($pdo, (int)$connection['id']);
    }

    if ($action === 'check') {
        $pickers = ($adapter && !empty($res['needs']))
            ? odSendPickers($adapter, $credentials, $res['needs'], $res)
            : [];
        echo json_encode([
            'success'      => true,
            'state'        => $res['state'] ?? 'unlinked',
            'reason'       => $res['reason'] ?? null,
            'ok'           => !empty($res['ok']),
            'needs'        => $res['needs'] ?? [],
            'resolved'     => $res['resolved'] ?? [],
            'external_id'  => $res['external_id'] ?? null,
            'event_id'     => $res['event_id'] ?? null,
            'prior_state'  => $res['prior_state'] ?? null,
            'prior_error'  => $res['prior_error'] ?? null,
            'patient_name' => $res['patient_search'] ?? null,
            'provider_name'=> $res['provider_name'] ?? null,
            'pickers'      => $pickers,
        ]);
        exit;
    }

    if ($action === 'search_patients') {
        if (!$adapter || !method_exists($adapter, 'searchPatients')) {
            echo json_encode(['success' => true, 'candidates' => []]);
            exit;
        }
        $filters = [];
        foreach (['lname' => 'LName', 'fname' => 'FName'] as $in => $out) {
            $v = trim((string)($body[$in] ?? ''));
            if ($v !== '') { $filters[$out] = $v; }
        }
        $dob = trim((string)($body['birthdate'] ?? ''));
        if ($dob !== '') {
            $ts = strtotime($dob);
            if ($ts !== false) { $filters['Birthdate'] = gmdate('Y-m-d', $ts); }
        }
        try {
            $candidates = $adapter->searchPatients($credentials, $filters);
            echo json_encode(['success' => true, 'candidates' => $candidates]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'message' => t('od_send.remote_unreachable'),
                'candidates' => [],
            ]);
        }
        exit;
    }

    if ($action === 'send') {
        if (!$connection) {
            echo json_encode(['success' => true, 'queued' => false,
                'reason' => $res['reason'] ?? 'creates_disabled',
                'state'  => $res['state'] ?? 'unavailable']);
            exit;
        }
        if (!empty($res['reason']) && $res['reason'] !== 'needs_mapping') {
            // already_linked / pending / flag_off / case_missing / timezone
            echo json_encode(['success' => true, 'queued' => false,
                'reason' => $res['reason'], 'state' => $res['state'] ?? 'unlinked',
                'external_id' => $res['external_id'] ?? null,
                'event_id' => $res['event_id'] ?? null]);
            exit;
        }

        $resolved = $res['resolved'] ?? [];
        $sel = [
            'pat_num'        => (int)($body['pat_num'] ?? 0),
            'prov_num'       => (int)($body['prov_num'] ?? 0),
            'laboratory_num' => (int)($body['laboratory_num'] ?? 0),
        ];
        foreach ($sel as $k => $v) {
            if ($v > 0) {
                $resolved[$k] = $v;
            }
        }

        // Every remote id going into the payload must verify against the
        // office's own database right now - never trust a picker value.
        foreach (['pat_num' => 'getPatient', 'prov_num' => 'getProviderByNum', 'laboratory_num' => 'getLaboratory'] as $field => $method) {
            if (empty($resolved[$field])) {
                echo json_encode(['success' => true, 'queued' => false,
                    'reason' => 'needs_mapping', 'needs' => $res['needs'] ?? []]);
                exit;
            }
            try {
                $found = $adapter->$method($credentials, (int)$resolved[$field]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => t('od_send.remote_unreachable')]);
                exit;
            }
            if (!is_array($found)) {
                echo json_encode(['success' => true, 'queued' => false,
                    'reason' => 'invalid_selection', 'field' => $field]);
                exit;
            }
        }

        // Persist the picks as reusable mappings first, then re-resolve so
        // the payload comes from one code path (due date + lifecycle stamp
        // included) and the mapping rows provably drove it.
        IntegrationOutbound::persistCreateSelections($pdo, (int)$connection['id'], $caseId, (int)$currentPracticeId, $resolved);
        $res2 = IntegrationOutbound::resolveCaseCreate($pdo, (int)$currentPracticeId, $caseId);
        if (empty($res2['ok']) || empty($res2['payload'])) {
            echo json_encode(['success' => true, 'queued' => false,
                'reason' => $res2['reason'] ?? 'needs_mapping',
                'needs'  => $res2['needs'] ?? []]);
            exit;
        }

        $enq = IntegrationOutbound::enqueueCaseCreate($pdo, (int)$currentPracticeId, $caseId, $res2['payload'], $userId !== null ? (int)$userId : null);

        if (function_exists('logCaseActivity')) {
            try {
                logCaseActivity($caseId, 'integration_outbound_send_requested', null, null, [
                    'source'     => 'integration:' . ($connection['provider'] ?? 'unknown'),
                    'integration'=> $connection['provider'] ?? 'unknown',
                    'direction'  => 'outbound',
                    'operation'  => 'create_labcase',
                    'queued'     => !empty($enq['enqueued']),
                ], null, false, $userId !== null ? (int)$userId : null, null);
            } catch (Throwable $e) { /* audit must never break the request */ }
        }

        echo json_encode([
            'success'  => true,
            'queued'   => !empty($enq['enqueued']),
            'reason'   => $enq['reason'] ?? null,
            'event_id' => $enq['id'] ?? null,
            'state'    => !empty($enq['enqueued']) ? 'pending' : 'unlinked',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('api.integrations.unknown_action')]);
} catch (Throwable $e) {
    error_log('od-send-case error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('od_send.server_error')]);
}
