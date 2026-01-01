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

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();

try {

    // Get case ID from request
    $caseId = isset($_GET['id']) ? trim($_GET['id']) : '';
    
    if (empty($caseId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Case ID is required']);
        exit;
    }

    // Get all cases from cache
    $cases = getAllCasesFromCache();
    
    // Find the specific case
    $targetCase = null;
    foreach ($cases as $case) {
        if (isset($case['id']) && $case['id'] === $caseId) {
            $targetCase = $case;
            break;
        }
    }

    if ($targetCase === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        exit;
    }

    // Get case files if drive folder exists
    $files = [];
    if (isset($targetCase['driveFolderId']) && !empty($targetCase['driveFolderId'])) {
        try {
            $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
            $files = getDriveFolderFiles($currentPracticeId, $targetCase['driveFolderId']);
        } catch (Throwable $e) {
            error_log('Error getting case files: ' . $e->getMessage());
            $files = [];
        }
    }

    // Get case activity/history
    $activity = [];
    try {
        // Use the existing function from case-activity-log.php
        if (function_exists('getCaseActivity')) {
            $activity = getCaseActivity($caseId);
        } else {
            // Fallback: try to load the function if not available
            require_once __DIR__ . '/case-activity-log.php';
            $activity = getCaseActivity($caseId);
        }
    } catch (Throwable $e) {
        error_log('Error getting case activity: ' . $e->getMessage());
        $activity = [];
    }

    // Decrypt PII fields before returning
    $decryptedCase = PIIEncryption::decryptCaseData($targetCase);

    echo json_encode([
        'success' => true,
        'case' => $decryptedCase,
        'files' => $files,
        'activity' => $activity
    ]);

} catch (Throwable $e) {
    error_log('Error in get-case.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving case: ' . $e->getMessage()
    ]);
}
?>
