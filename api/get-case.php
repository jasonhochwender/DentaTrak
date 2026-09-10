<?php
// Get Single Case API endpoint

require_once __DIR__ . '/session.php';      // Centralized session handling
header('Content-Type: application/json');

// Do not show errors in the browser for this endpoint
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
// Keep deprecations suppressed but allow other errors to be logged
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/hipaa-compliance.php';

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();
$caseStart = microtime(true);

try {

    // Get case ID from request
    $caseId = isset($_GET['id']) ? trim($_GET['id']) : '';

    if (empty($caseId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Case ID is required']);
        exit;
    }

    // SECURITY: Verify this case belongs to the current practice and, for
    // limited-visibility users, is assigned to them. Must happen BEFORE any
    // case data is loaded/returned below.
    $authStart = microtime(true);
    requireCaseAccess($caseId, $currentPracticeId);
    $authMs = round((microtime(true) - $authStart) * 1000, 2);

    $view = isset($_GET['view']) ? trim($_GET['view']) : 'full';
    if (!in_array($view, ['core', 'heavy', 'full'])) {
        $view = 'full';
    }

    // Fetch the requested view. The 'core' view intentionally omits the heavy
    // JSON columns (attachments_json, revisions_json, clinical_details_json)
    // so the modal can become usable as quickly as possible. Heavy data is
    // loaded in a follow-up request once the modal is already visible.
    $cacheStart = microtime(true);
    if ($view === 'full') {
        // Default call keeps the original two-argument contract for callers
        // that do not pass a view parameter.
        $targetCase = getSingleCaseFromCache($caseId, $currentPracticeId);
    } elseif ($view === 'core') {
        $targetCase = getSingleCaseFromCache($caseId, $currentPracticeId, 'core');
    } else {
        $targetCase = getSingleCaseFromCache($caseId, $currentPracticeId, 'heavy');
    }
    $caseFetchMs = round((microtime(true) - $cacheStart) * 1000, 2);

    if ($targetCase === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        exit;
    }

    $decryptedCase = $targetCase;
    $files = $decryptedCase['attachments'] ?? [];
    $activity = [];

    // Log PHI access for HIPAA compliance. The initial core/fetch is the
    // logical "view case" event; the heavy follow-up for attachments is
    // audited separately so one user action does not create two view_case rows.
    $logStart = microtime(true);
    if ($view === 'heavy') {
        logPHIAccess('view_case_attachments', $caseId);
    } else {
        logPHIAccess('view_case', $caseId);
    }
    $logMs = round((microtime(true) - $logStart) * 1000, 2);

    $serverTimeMs = round((microtime(true) - $caseStart) * 1000, 2);

    $response = [
        'success' => true,
        'view' => $view,
        'can_edit' => canEditCases($currentPracticeId) && empty($targetCase['archived']),
        'serverTimeMs' => $serverTimeMs,
        'authMs' => $authMs,
        'caseFetchMs' => $caseFetchMs,
        'logMs' => $logMs,
        'case' => $decryptedCase,
        'files' => $files,
        'activity' => $activity
    ];

    if ($view === 'core') {
        $response['heavy_available'] = true;
    }

    echo json_encode($response);

} catch (Throwable $e) {
    error_log('Error in get-case.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving case: ' . $e->getMessage()
    ]);
}
?>
