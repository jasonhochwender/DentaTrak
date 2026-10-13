<?php
/**
 * Client-side upload failure report endpoint.
 *
 * The browser reports upload failures that never reached a DentaTrak
 * endpoint (e.g. the direct-to-GCS PUT rejected or the network dropped),
 * so they can be correlated with [UPLOAD] log entries via request_id.
 *
 * POST /api/log-upload-error.php
 *
 * Request body (JSON) - sanitized enum/numeric fields only:
 *   request_id  - signed-URL request id when known
 *   stage       - client-validation | signed-url | gcs-put | batch | unknown
 *   http_status - HTTP status seen by the browser (0 when none)
 *   error_class - controlled classification enum
 *   file_size   - bytes (0 when unknown)
 *   ext         - lowercase file extension (no filename)
 *   upload_type - upload category
 *
 * Privacy: filenames, file contents, patient data, and free-form messages
 * are never accepted or logged.
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

setApiSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON request body']);
    exit;
}

$allowedStages = ['client-validation', 'signed-url', 'gcs-put', 'batch', 'unknown'];
$allowedClasses = [
    'unsupported_type', 'too_large', 'too_many', 'total_exceeded', 'empty_file',
    'session_expired', 'unauthenticated', 'access_denied', 'validation',
    'unsupported_type_server', 'invalid_mime', 'invalid_upload_type',
    'missing_fields', 'invalid_json', 'only_images', 'case_required',
    'case_archived', 'file_too_large', 'payload_too_large',
    'server_error', 'put_rejected', 'network', 'stalled', 'timeout',
    'invalid_response', 'aborted', 'unknown',
];
$allowedUploadTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns', 'comments', ''];

$stage = in_array($input['stage'] ?? '', $allowedStages, true) ? $input['stage'] : 'unknown';
$errorClass = in_array($input['error_class'] ?? '', $allowedClasses, true) ? $input['error_class'] : 'unknown';
$uploadType = in_array($input['upload_type'] ?? '', $allowedUploadTypes, true) ? $input['upload_type'] : '';
$requestId = isset($input['request_id']) && is_string($input['request_id']) && preg_match('/^[a-f0-9]{8,32}$/', $input['request_id'])
    ? $input['request_id'] : null;
$httpStatus = max(0, min(599, (int)($input['http_status'] ?? 0)));
$fileSize = max(0, (int)($input['file_size'] ?? 0));
$ext = isset($input['ext']) && is_string($input['ext']) && preg_match('/^[a-z0-9]{1,10}$/', $input['ext'])
    ? $input['ext'] : '';

error_log('[UPLOAD-CLIENT] ' . json_encode([
    'timestamp'   => date('Y-m-d H:i:s'),
    'request_id'  => $requestId,
    'stage'       => $stage,
    'http_status' => $httpStatus,
    'error_class' => $errorClass,
    'file_size'   => $fileSize,
    'ext'         => $ext,
    'upload_type' => $uploadType,
    'user_id'     => $userId,
    'practice_id' => $currentPracticeId,
]));

echo json_encode(['success' => true]);
