<?php
/**
 * Set Case View Scope API Endpoint
 *
 * Toggles the consolidated "All practices" case view for Scale
 * organizations. The scope is a session flag (org_case_view) - it is NOT
 * a practice and never produces a synthetic practice_id. The session's
 * real current_practice_id stays untouched and keeps anchoring the
 * organization for every subsequent request.
 *
 * POST {scope: 'organization'} enables the consolidated view when the
 * organization holds the Scale entitlement AND the user is authorized
 * for more than one practice in it. POST {scope: 'practice'} returns to
 * the single-practice view. Both are session-only state changes.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/organization-access.php';
require_once __DIR__ . '/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'message' => 'Case view scope changes require a POST request.'
    ]);
    exit;
}

header('Content-Type: application/json');

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => t('auth.errors.not_authenticated')
    ]);
    exit;
}

$userId = (int)$_SESSION['db_user_id'];

// Require a valid CSRF token before any state change.
requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$scope = isset($input['scope']) ? (string)$input['scope'] : (isset($_POST['scope']) ? (string)$_POST['scope'] : '');

if (!in_array($scope, ['organization', 'practice'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid scope.'
    ]);
    exit;
}

// A valid practice context is required either way - the anchor practice
// identifies which organization the consolidated scope refers to.
$anchorPracticeId = requireValidPracticeContext();

if ($scope === 'practice') {
    unset($_SESSION['org_case_view']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    echo json_encode(['success' => true, 'scope' => 'practice']);
    exit;
}

// 'organization': live entitlement + authorized-practice resolution.
// hasScaleAccess covers billing-disabled, bypass, trial and grace states;
// getAuthorizedOrganizationPractices covers ownership vs explicit
// assignment and excludes other organizations entirely.
$authorized = [];
try {
    require_once __DIR__ . '/subscription-access.php';
    $scaleOk = hasScaleAccess($pdo, $anchorPracticeId, (string)(getCurrentUserEmail() ?? ''));
    if ($scaleOk) {
        $authorized = getAuthorizedOrganizationPractices($pdo, $userId, $anchorPracticeId);
    }
} catch (Throwable $e) {
    error_log('[set-case-view-scope] scope resolution failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error resolving organization scope']);
    exit;
}

if (!$scaleOk) {
    unset($_SESSION['org_case_view']);
    logSecurityEvent('access_denied', [
        'reason' => 'org_case_view_not_entitled',
        'practice_id' => $anchorPracticeId
    ]);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'The consolidated multi-practice view requires the Scale plan.'
    ]);
    exit;
}

if (count($authorized) < 2) {
    // One authorized practice renders the consolidated view pointless;
    // stay in single-practice mode (also clears a stale flag when access
    // shrank to a single practice).
    unset($_SESSION['org_case_view']);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'The consolidated view requires access to more than one practice.'
    ]);
    exit;
}

$_SESSION['org_case_view'] = 1;

if (function_exists('logUserActivity')) {
    logUserActivity($userId, 'org_case_view_enabled',
        'Enabled the consolidated All-practices case view from practice ' . $anchorPracticeId);
}
logSecurityEvent('org_case_view_enabled', [
    'anchor_practice_id' => $anchorPracticeId,
    'authorized_practice_count' => count($authorized)
]);

// Commit the new scope immediately so a fast-following page load cannot
// observe stale state (same pattern as switch-practice.php).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

echo json_encode([
    'success' => true,
    'scope' => 'organization',
    'practiceCount' => count($authorized)
]);
