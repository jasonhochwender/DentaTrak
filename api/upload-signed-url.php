<?php
/**
 * Signed Upload URL API Endpoint
 * 
 * Generates signed PUT URLs for direct browser-to-GCS uploads.
 * This endpoint validates the request, checks authorization,
 * and returns a time-limited signed URL that the browser uses
 * to upload directly to Google Cloud Storage.
 * 
 * POST /api/upload-signed-url.php
 * 
 * Request body (JSON):
 *   filename     - Original filename
 *   content_type - MIME type of the file
 *   file_size    - File size in bytes
 *   case_id      - Case ID (existing case) or "new" for new cases
 *   upload_type  - Attachment category (photos, intraoralScans, facialScans, photogrammetry, completedDesigns)
 * 
 * Response (JSON):
 *   signed_url    - The signed PUT URL for direct upload
 *   storage_path  - The GCS object path (needed for case submission)
 *   expires_at    - ISO 8601 expiration timestamp
 *   request_id    - Correlates this request with [UPLOAD] log entries and
 *                   client-side failure reports (api/log-upload-error.php)
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

// Set security headers
setApiSecurityHeaders();

// Request identifier for correlating client reports and server log lines.
$uploadRequestId = bin2hex(random_bytes(8));

/**
 * Emit a rejection response with a machine-readable error_code the client
 * can localize, plus a structured [UPLOAD] log line. Logs NEVER include
 * filenames, file contents, or patient data - extension, size, and MIME
 * type only.
 */
function uploadReject(int $status, string $errorCode, string $message, array $context = []) {
    global $uploadRequestId;
    $responseExtra = $context['response_extra'] ?? [];
    unset($context['response_extra']);
    $logEntry = array_merge([
        'timestamp'   => date('Y-m-d H:i:s'),
        'request_id'  => $uploadRequestId,
        'stage'       => 'signed-url',
        'status'      => $status,
        'error_code'  => $errorCode,
        'user_id'     => $_SESSION['db_user_id'] ?? null,
        'practice_id' => $_SESSION['current_practice_id'] ?? null,
    ], $context);
    error_log('[UPLOAD] ' . json_encode($logEntry));

    http_response_code($status);
    echo json_encode(array_merge([
        'success'    => false,
        'error'      => $message,
        'error_code' => $errorCode,
        'request_id' => $uploadRequestId,
    ], $responseExtra));
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed', 'request_id' => $uploadRequestId]);
    exit;
}

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

if (!$userId) {
    uploadReject(401, 'unauthenticated', 'Authentication required');
}

// Validate CSRF token
requireCsrfToken();

try {
    // Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        uploadReject(400, 'invalid_json', 'Invalid JSON request body');
    }

    $filename    = $input['filename'] ?? '';
    $contentType = $input['content_type'] ?? '';
    $fileSize    = (int)($input['file_size'] ?? 0);
    $caseId      = $input['case_id'] ?? '';
    $uploadType  = $input['upload_type'] ?? 'photos';

    // Safe logging context: extension, size, MIME - never the raw filename.
    $safeExt = strtolower(substr((string)pathinfo((string)$filename, PATHINFO_EXTENSION), 0, 10));
    $logCtx = [
        'upload_type'  => substr((string)$uploadType, 0, 30),
        'ext'          => $safeExt,
        'file_size'    => $fileSize,
        'content_type' => substr((string)$contentType, 0, 100),
        'case_id'      => ($caseId && $caseId !== 'new') ? $caseId : 'new',
    ];

    // --- Validation ---

    // Required fields
    if (empty($filename) || empty($contentType) || $fileSize <= 0) {
        uploadReject(400, 'missing_fields', 'Missing required fields: filename, content_type, file_size', $logCtx);
    }

    // Validate upload type. 'comments' carries images attached to case comments.
    $validUploadTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns', 'comments'];
    if (!in_array($uploadType, $validUploadTypes)) {
        uploadReject(400, 'invalid_upload_type', 'Invalid upload_type', $logCtx);
    }

    // Validate MIME type
    $allowedMimeTypes = $appConfig['gcs']['allowed_mime_types'] ?? [];
    if (!in_array($contentType, $allowedMimeTypes)) {
        uploadReject(400, 'invalid_mime', 'File type not allowed', $logCtx);
    }

    // Validate file extension
    $allowedExtensions = $appConfig['gcs']['allowed_extensions'] ?? [];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions)) {
        uploadReject(400, 'unsupported_type', 'File extension not allowed: .' . $ext, array_merge($logCtx, [
            'response_extra' => ['ext' => $ext],
        ]));
    }

    // Comment images are image-only and always belong to an existing case
    // (comments cannot exist before the case does), which also guarantees the
    // requireCaseAccess() check below runs for every comment-image upload.
    if ($uploadType === 'comments') {
        $commentImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg'];
        if (!in_array($ext, $commentImageExts) || strpos($contentType, 'image/') !== 0) {
            uploadReject(400, 'only_images', t('api.comments.only_images_allowed'), $logCtx);
        }
        if ($caseId === '' || $caseId === 'new') {
            uploadReject(400, 'case_required', t('api.comments.existing_case_required'), $logCtx);
        }
    }

    // Validate file size — type-specific limits
    $sizeByType = $appConfig['gcs']['max_file_size_by_type'] ?? [];
    $maxFileSize = $sizeByType[$ext] ?? ($sizeByType['default'] ?? (100 * 1024 * 1024));
    if ($fileSize > $maxFileSize) {
        $limitMb = round($maxFileSize / 1024 / 1024);
        uploadReject(400, 'file_too_large',
            '.' . $ext . ' files must be under ' . $limitMb . 'MB.',
            array_merge($logCtx, [
                'response_extra' => ['limit' => $limitMb, 'ext' => $ext],
            ])
        );
    }

    // SECURITY: Validate case ownership AND Assigned Only visibility for
    // existing cases before issuing an upload URL. requireCaseAccess()
    // exits with a JSON error itself on failure; log the attempt first so
    // its [SECURITY] entry can be correlated back to this request.
    if ($caseId && $caseId !== 'new') {
        error_log('[UPLOAD] ' . json_encode([
            'timestamp'   => date('Y-m-d H:i:s'),
            'request_id'  => $uploadRequestId,
            'stage'       => 'signed-url',
            'status'      => 0,
            'result'      => 'case_access_check',
            'case_id'     => $caseId,
            'upload_type' => substr((string)$uploadType, 0, 30),
            'user_id'     => $userId,
            'practice_id' => $currentPracticeId,
        ]));
        $caseInfo = requireCaseAccess($caseId, $currentPracticeId);

        // Comment images feed the comment composer only; archived cases are
        // read-only, so staging comment attachments is rejected here too.
        if ($uploadType === 'comments' && (int)($caseInfo['archived'] ?? 0) === 1) {
            uploadReject(400, 'case_archived', t('api.comments.case_archived'),
                array_merge($logCtx, ['response_extra' => ['archived' => true]]));
        }
    }

    // --- Generate signed URL ---

    // Build unique object path: cases/{practice_id}/{case_id}/{upload_type}/{uuid}-{sanitized_filename}
    $sanitizedFilename = sanitizeGcsFilename($filename);
    $uuid = bin2hex(random_bytes(8)); // 16-char hex string
    $casePath = ($caseId && $caseId !== 'new') ? $caseId : 'pending_' . $userId . '_' . date('Ymd_His');
    $objectPath = "cases/{$currentPracticeId}/{$casePath}/{$uploadType}/{$uuid}-{$sanitizedFilename}";

    $result = generateSignedUploadUrl($objectPath, $contentType);

    // Mark an active upload so the ping.php keepalive can extend the session
    // only while a real upload is in progress.
    $_SESSION['last_upload_ping'] = time();

    // Log URL issuance (object id only - the filename is deliberately
    // omitted so patient-identifying names never reach the log).
    error_log('[UPLOAD] ' . json_encode(array_merge([
        'timestamp'  => date('Y-m-d H:i:s'),
        'request_id' => $uploadRequestId,
        'stage'      => 'signed-url',
        'status'     => 200,
        'result'     => 'issued',
        'object_id'  => $uuid,
        'user_id'    => $userId,
        'practice_id'=> $currentPracticeId,
    ], $logCtx)));

    echo json_encode([
        'success'       => true,
        'signed_url'    => $result['signed_url'],
        'storage_path'  => $result['storage_path'],
        'expires_at'    => $result['expires_at'],
        'expected_size' => $fileSize,
        'max_file_size' => $maxFileSize,
        'request_id'    => $uploadRequestId,
    ]);

} catch (Throwable $e) {
    error_log('[UPLOAD] ' . json_encode([
        'timestamp'  => date('Y-m-d H:i:s'),
        'request_id' => $uploadRequestId,
        'stage'      => 'signed-url',
        'status'     => 500,
        'error_code' => 'signed_url_failed',
        'exception'  => get_class($e) . ': ' . $e->getMessage(),
    ]));
    http_response_code(500);
    echo json_encode([
        'success'    => false,
        'error'      => 'Failed to generate upload URL. Please try again.',
        'error_code' => 'signed_url_failed',
        'request_id' => $uploadRequestId,
    ]);
}
