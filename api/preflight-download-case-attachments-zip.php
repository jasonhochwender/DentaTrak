<?php
/**
 * Preflight validation for the case-level Download All ZIP feature.
 *
 * Performs all authoritative security, access, and size checks and returns
 * structured JSON. It does not download any attachment contents.
 *
 * POST /api/preflight-download-case-attachments-zip.php
 * Request body (form or JSON):
 *   case_id - the case identifier
 *   csrf_token (optional if X-CSRF-Token header is sent)
 *
 * On success: JSON with file_count, total_size, zip_filename.
 * On failure: JSON with a localized, safe error message.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/case-zip-helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

setApiSecurityHeaders();

// Shared dependency guard: preflight must fail before the browser tries
// to submit a download that the server cannot stream.
$zipStreamError = getZipStreamDependencyError();
if ($zipStreamError !== null) {
    error_log('[DownloadAllZIP] ZipStream-PHP dependency is missing at preflight time.');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $zipStreamError]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Feature flag: reject before any database or GCS work.
if (!isFeatureEnabled('SHOW_CASE_DOWNLOAD_ALL')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

requireCsrfToken();

// Accept either a form POST or a JSON body.
if (!empty($_POST['case_id'])) {
    $caseId = $_POST['case_id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $caseId = $input['case_id'] ?? '';
}

if (empty($caseId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing required field: case_id']);
    exit;
}

// Authoritative case access (includes Assigned Only and cross-practice checks).
$case = requireCaseAccess($caseId, $currentPracticeId);

$bucket = null;
try {
    $bucket = getGcsBucket();
} catch (Exception $e) {
    error_log('[DownloadAllZIP] Storage backend unavailable: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => t('attachments.download_all_failed', ['message' => 'Storage backend unavailable'])]);
    exit;
}

// Build the eligible list using the same shared logic as the download endpoint.
$result = getEligibleZipAttachments($case, $currentPracticeId, $bucket);
$eligible = $result['eligible'];
$totalActualSize = $result['totalActualSize'];

$MAX_IMMEDIATE_SIZE = getBulkZipMaxSize();

if (count($eligible) < 2) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => t('attachments.download_all_no_eligible')]);
    exit;
}

if ($totalActualSize > $MAX_IMMEDIATE_SIZE) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => t('attachments.download_all_bundle_too_large')]);
    exit;
}

$zipBaseName = 'case-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseId) . '-attachments';

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'file_count' => count($eligible),
    'total_size' => $totalActualSize,
    'zip_filename' => $zipBaseName . '.zip',
]);
exit;
