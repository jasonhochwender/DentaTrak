<?php
/**
 * Incoming Scans API
 *
 * Practice-scoped inbox for scanner imports (iTero / 3Shape TRIOS /
 * Primescan - DS Core / Medit Link once approved; today exercised via the
 * dev-only simulated connector).
 *
 *   GET  api/scan-inbox.php?action=list[&include_dismissed=1]
 *   POST {action:'dismiss',  scan_id}
 *   POST {action:'restore',  scan_id}
 *   POST {action:'link',     scan_id, case_id, append_instructions?}
 *   POST {action:'retry',    scan_id}
 *
 * SECURITY MODEL (every action):
 *   authenticated session -> valid practice context -> can_edit_cases ->
 *   not a lab collaborator -> CSRF (POST) -> SCANNER_INBOX feature flag ->
 *   every query scoped to the session-derived practice_id.
 *   The flag is enforced here at the endpoint, not just by hidden nav.
 *
 * All failures return a safe error_code category - never patient data,
 * storage internals, or vendor payloads.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/integrations/scanners/ScanInbox.php';
require_once __DIR__ . '/integrations/scanners/SimulatedScannerConnector.php';
require_once __DIR__ . '/integrations/dscore/DsCoreScanConnector.php';
require_once __DIR__ . '/integrations/DsCoreConfig.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
setApiSecurityHeaders();

set_exception_handler(function (Throwable $e) {
    error_log('[scan-inbox] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'The scan inbox is unavailable right now.']);
    exit;
});

function scanInboxFail(int $status, string $message, ?string $errorCode = null): void {
    http_response_code($status);
    $out = ['success' => false, 'message' => $message];
    if ($errorCode !== null) {
        $out['error_code'] = $errorCode;
    }
    echo json_encode($out);
    exit;
}

// --- Flag gate FIRST: the workflow is entirely off unless SCANNER_INBOX is
//     enabled. This is the server-side enforcement behind the hidden nav.
if (!isFeatureEnabled('SCANNER_INBOX')) {
    scanInboxFail(404, 'Not found', 'feature_disabled');
}

// --- Auth stack
if (!isset($_SESSION['db_user_id'])) {
    scanInboxFail(401, 'Authentication required', 'not_authenticated');
}
$userId = (int)$_SESSION['db_user_id'];
$currentPracticeId = requireValidPracticeContext();
requireNotLabCollaborator($currentPracticeId, 'Access denied.');
requireCurrentTermsAcceptedForApi($userId);
if (!canEditCases($currentPracticeId)) {
    logSecurityEvent('scan_inbox_denied', ['reason' => 'cannot_edit_cases']);
    scanInboxFail(403, 'You do not have permission to manage incoming scans.', 'forbidden');
}

// Rollout restriction: while the inbox is behind SCANNER_INBOX it is only
// reachable by practices permitted to use scanner integrations - the
// DS-Core allowlist in production, or the dev-only simulated connector.
// An empty allowlist permits nobody and no role/admin can widen it.
if (!DsCoreConfig::isPracticeAllowed((int)$currentPracticeId) && !SimulatedScannerConnector::isAvailable()) {
    scanInboxFail(404, 'Not found', 'feature_disabled');
}

global $pdo;
if (!$pdo) {
    scanInboxFail(500, 'Service unavailable', 'db_unavailable');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// GET: list inbox scans for the active practice
// -------------------------------------------------------------------------
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    if ($action === 'list') {
        // Legacy toggle still maps onto the view model; the filtered UI
        // sends explicit view/filter params. q/vendor/date params narrow
        // server-side; patient-name matching happens post-decrypt.
        $view = trim((string)($_GET['view'] ?? ''));
        if ($view === '' && !empty($_GET['include_dismissed'])) {
            $view = 'all';
        }
        $result = ScanInbox::listFiltered($pdo, (int)$currentPracticeId, $userId, [
            'view'      => $view !== '' ? $view : 'attention',
            'q'         => (string)($_GET['q'] ?? ''),
            'vendor'    => (string)($_GET['vendor'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to'   => (string)($_GET['date_to'] ?? ''),
            'page'      => (int)($_GET['page'] ?? 1),
            'per_page'  => (int)($_GET['per_page'] ?? 25),
        ]);
        echo json_encode(['success' => true] + $result);
        exit;
    }
    if ($action === 'cases') {
        // Lightweight picker list for the attach dialog: case id + display
        // fields only, restricted to cases THIS user may access (assigned-
        // only users see only their assigned cases), matching what 'link'
        // would allow them to attach to.
        $stmt = $pdo->prepare("
            SELECT case_id, patient_first_name, patient_last_name, case_type, status, practice_id, assigned_to
            FROM cases_cache
            WHERE practice_id = :pid AND (archived = 0 OR archived IS NULL)
            ORDER BY last_update_date DESC
            LIMIT 500
        ");
        $stmt->execute([':pid' => (int)$currentPracticeId]);
        $cases = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!canUserAccessCase($row, (int)$currentPracticeId, $userId)) {
                continue;
            }
            $cases[] = [
                'id'               => $row['case_id'],
                'patientFirstName' => ScanIngestion::decryptField($row['patient_first_name']),
                'patientLastName'  => ScanIngestion::decryptField($row['patient_last_name']),
                'caseType'         => $row['case_type'],
                'status'           => $row['status'],
            ];
        }
        echo json_encode(['success' => true, 'cases' => $cases]);
        exit;
    }
    scanInboxFail(400, 'Unknown action', 'unknown_action');
}

if ($method !== 'POST') {
    scanInboxFail(405, 'Method not allowed');
}

requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    scanInboxFail(400, 'Invalid request', 'invalid_json');
}

$action = (string)($input['action'] ?? '');
$scanId = (int)($input['scan_id'] ?? 0);
if ($scanId <= 0) {
    scanInboxFail(400, 'Invalid request', 'invalid_scan');
}

switch ($action) {
    // -------------------------------------------------------------
    case 'dismiss':
        $result = ScanInbox::dismiss($pdo, (int)$currentPracticeId, $userId, $scanId);
        break;

    case 'restore':
        $result = ScanInbox::restore($pdo, (int)$currentPracticeId, $userId, $scanId);
        break;

    case 'link': {
        $caseId = trim((string)($input['case_id'] ?? ''));
        if ($caseId === '' || strlen($caseId) > 64) {
            scanInboxFail(400, 'A case must be selected.', 'invalid_case');
        }
        $appendInstructions = !empty($input['append_instructions']);
        $result = ScanInbox::attachToCase($pdo, (int)$currentPracticeId, $userId, $scanId, $caseId, $appendInstructions);
        break;
    }

    case 'retry': {
        $scan = ScanIngestion::findScanForPractice($pdo, (int)$currentPracticeId, $scanId);
        if (!$scan) {
            scanInboxFail(404, 'Scan not found', 'not_found');
        }
        if (in_array($scan['status'], [ScanIngestion::SCAN_LINKED, ScanIngestion::SCAN_DISMISSED], true)) {
            scanInboxFail(409, 'This scan can no longer be retried.', 'not_retryable');
        }
        // Resolve a connector for the scan's vendor. DS Core resolves when
        // the feature is on (the connector re-verifies the connection is
        // still active per download); the simulated connector resolves
        // only where it is allowed (dev/test + SCANNER_SIMULATOR).
        $connector = null;
        if (strtolower((string)$scan['vendor']) === SimulatedScannerConnector::VENDOR
            && SimulatedScannerConnector::isAvailable()) {
            try {
                $connector = new SimulatedScannerConnector();
            } catch (Throwable $e) {
                $connector = null;
            }
        } elseif (strtolower((string)$scan['vendor']) === DsCoreScanConnector::VENDOR
            && isFeatureEnabled('DS_CORE_CONNECT')) {
            $connector = new DsCoreScanConnector($pdo);
        }
        if (!$connector) {
            scanInboxFail(409, 'The source connection for this scan is not available.', 'source_unavailable');
        }
        ScanIngestion::recordEvent($pdo, $scanId, (int)$currentPracticeId, $userId, 'retry');
        ScanIngestion::processPendingFiles($pdo, $scan, $connector, [
            'practice_id'    => (int)$currentPracticeId,
            'connection_id'  => $scan['connection_id'],
            'source_account' => $scan['source_account'],
            'is_synthetic'   => !empty($scan['is_synthetic']),
        ]);
        ScanIngestion::refreshScanStatus($pdo, $scanId);
        $fresh = ScanIngestion::findScanForPractice($pdo, (int)$currentPracticeId, $scanId);
        $result = ['success' => true, 'status' => $fresh['status'] ?? null];
        break;
    }

    default:
        scanInboxFail(400, 'Unknown action', 'unknown_action');
}

// ---- Result mapping
if (!empty($result['success'])) {
    echo json_encode($result);
    exit;
}

$code = $result['error'] ?? 'internal';
$status = 400;
$message = 'The request could not be completed.';
switch ($code) {
    case 'not_found':
    case 'case_not_found':
        $status = 404;
        $message = 'Not found';
        break;
    case 'case_access_denied':
        $status = 403;
        $message = 'You do not have access to that case.';
        break;
    case 'already_linked':
        $status = 409;
        $message = 'This scan is already attached to a case.';
        break;
    case 'dismissed':
        $status = 409;
        $message = 'Restore this scan before attaching it.';
        break;
    case 'no_ready_files':
        $status = 409;
        $message = 'No files are ready to attach yet.';
        break;
    case 'case_conflict':
        $status = 409;
        $message = 'The case was modified by someone else. Please try again.';
        break;
    case 'storage_error':
        $status = 500;
        $message = 'The scan files could not be stored. Please try again.';
        break;
}
scanInboxFail($status, $message, $code);
