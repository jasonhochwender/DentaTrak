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
    requireCaseAccess($caseId, $currentPracticeId);

    // Fetch only the requested case from the cache.
    $targetCase = getSingleCaseFromCache($caseId, $currentPracticeId);

    if ($targetCase === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        exit;
    }

    // Attachments are already included in the single-case cache row.
    $files = $targetCase['attachments'] ?? [];

    // Activity is not needed for the initial View Case render; the UI loads
    // revision history and comments on demand after the primary form appears.
    $activity = [];

    // getAllCasesFromCache() already returns cases with PII decrypted.
    // Do not decrypt again — double decryption corrupts the data.
    $decryptedCase = $targetCase;

    // Resolve creator display name from the historical user record.
    // A user may no longer be a current practice member, but we still display
    // the historical attribution from the cases_cache.created_by_user_id value.
    $decryptedCase['createdByName'] = 'Unknown';
    if (!empty($decryptedCase['createdByUserId'])) {
        try {
            $userStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute(['id' => $decryptedCase['createdByUserId']]);
            $creator = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($creator) {
                $name = trim(($creator['first_name'] ?? '') . ' ' . ($creator['last_name'] ?? ''));
                if ($name !== '') {
                    $decryptedCase['createdByName'] = $name;
                }
            }
        } catch (Exception $e) {
            // Leave as Unknown on error
        }
    }

    // Log PHI access for HIPAA compliance
    logPHIAccess('view_case', $caseId);

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
