<?php
/**
 * Update Case Review Status API Endpoint
 *
 * Allows an authorized practice user to explicitly mark a case as Reviewed
 * or return it to Needs Review. Assignment and other case state are preserved.
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

// Disable PHP error display for API - return only JSON
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/csrf.php';

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();
if (!$currentPracticeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$caseId = isset($input['caseId']) ? trim((string)$input['caseId']) : '';
$reviewed = isset($input['reviewed']) ? filter_var($input['reviewed'], FILTER_VALIDATE_BOOLEAN) : null;

if ($caseId === '' || $reviewed === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: caseId and reviewed are required']);
    exit;
}

// SECURITY: Verify the case belongs to this practice and the user can access it.
requireCaseAccess($caseId, $currentPracticeId);

$case = getSingleCaseFromCache($caseId, $currentPracticeId, 'core');
if (!$case) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Case not found']);
    exit;
}

// Case Review Tracking is an opt-in practice feature. The server rejects
// manual review mutations when the practice has not enabled it, even if a
// client-side flag or request was manipulated.
if (!isCaseReviewTrackingEnabled($currentPracticeId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('cases.review_tracking_disabled')]);
    exit;
}

// Archived cases are read-only; do not allow review changes.
if (!empty($case['archived'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Review state cannot be changed for archived cases']);
    exit;
}

$currentUserId = $_SESSION['db_user_id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

// Preserve assignment while recording who reviewed the case and when.
$previousStatus = !empty($case['reviewedAt']) ? 'reviewed' : 'needs_review';
$newStatus = $reviewed ? 'reviewed' : 'needs_review';

if ($previousStatus === $newStatus) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'changed' => false,
        'message' => '',
        'reviewData' => [
            'id' => $case['id'],
            'caseId' => $case['id'],
            'reviewStatus' => $case['reviewStatus'] ?? $previousStatus,
            'reviewedAt' => $case['reviewedAt'] ?? null,
            'reviewedByUserId' => $case['reviewedByUserId'] ?? null,
            'reviewedByName' => $case['reviewedByName'] ?? 'Unknown',
            'archived' => !empty($case['archived']),
        ],
    ]);
    exit;
}

if (!updateCaseReviewStatus($caseId, $currentPracticeId, $currentUserId, $reviewed)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update review status']);
    exit;
}

// Reload the case so returned data is authoritative.
$updatedCase = getSingleCaseFromCache($caseId, $currentPracticeId, 'core');
if (!$updatedCase) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to reload case']);
    exit;
}

// Log review activity. Reviewer name is derived server-side from the
// authenticated session, never from the client.
$reviewerName = $updatedCase['reviewedByName'] ?? 'Unknown';
ensureCaseActivityLogTable();
logCaseActivity(
    $caseId,
    'review_status_changed',
    $previousStatus,
    $newStatus,
    [
        'review_status' => $newStatus,
        'reviewed_by_user_id' => (int)$currentUserId,
        'reviewed_by_name' => $reviewerName,
        'source' => 'update-case-review.php',
    ]
);

// Notify other clients that the case changed.
if (function_exists('recordCaseUpdate')) {
    recordCaseUpdate($caseId, 'update');
}

echo json_encode([
    'success' => true,
    'changed' => true,
    'message' => $reviewed
        ? t('cases.marked_reviewed')
        : t('cases.marked_needs_review'),
    'reviewData' => [
        'id' => $updatedCase['id'],
        'caseId' => $updatedCase['id'],
        'reviewStatus' => $updatedCase['reviewStatus'] ?? $newStatus,
        'reviewedAt' => $updatedCase['reviewedAt'] ?? null,
        'reviewedByUserId' => $updatedCase['reviewedByUserId'] ?? null,
        'reviewedByName' => $updatedCase['reviewedByName'] ?? 'Unknown',
        'archived' => !empty($updatedCase['archived']),
    ],
]);
