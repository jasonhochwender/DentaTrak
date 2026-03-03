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
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

// Set security headers
setApiSecurityHeaders();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Validate CSRF token
requireCsrfToken();

try {
    // Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON request body']);
        exit;
    }

    $filename    = $input['filename'] ?? '';
    $contentType = $input['content_type'] ?? '';
    $fileSize    = (int)($input['file_size'] ?? 0);
    $caseId      = $input['case_id'] ?? '';
    $uploadType  = $input['upload_type'] ?? 'photos';

    // --- Validation ---

    // Required fields
    if (empty($filename) || empty($contentType) || $fileSize <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields: filename, content_type, file_size']);
        exit;
    }

    // Validate upload type
    $validUploadTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns'];
    if (!in_array($uploadType, $validUploadTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid upload_type. Must be one of: ' . implode(', ', $validUploadTypes)]);
        exit;
    }

    // Validate MIME type
    $allowedMimeTypes = $appConfig['gcs']['allowed_mime_types'] ?? [];
    if (!in_array($contentType, $allowedMimeTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $contentType]);
        exit;
    }

    // Validate file extension
    $allowedExtensions = $appConfig['gcs']['allowed_extensions'] ?? [];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File extension not allowed: .' . $ext]);
        exit;
    }

    // Validate file size — type-specific limits
    $sizeByType = $appConfig['gcs']['max_file_size_by_type'] ?? [];
    $maxFileSize = $sizeByType[$ext] ?? ($sizeByType['default'] ?? (100 * 1024 * 1024));
    if ($fileSize > $maxFileSize) {
        // Build a friendly category name for the error
        $scanExts = ['stl', 'obj', 'ply', 'dcm'];
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg'];
        if (in_array($ext, $scanExts)) {
            $label = 'STL / 3D scan files';
        } elseif (in_array($ext, $imageExts)) {
            $label = 'Image files';
        } else {
            $label = '.' . $ext . ' files';
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $label . ' must be under ' . round($maxFileSize / 1024 / 1024) . 'MB. Your file is ' . round($fileSize / 1024 / 1024, 1) . 'MB.',
        ]);
        exit;
    }

    // Validate case ownership for existing cases
    if ($caseId && $caseId !== 'new') {
        $stmt = $pdo->prepare("SELECT id FROM cases_cache WHERE case_id = ? AND practice_id = ?");
        $stmt->execute([$caseId, $currentPracticeId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Case not found or access denied']);
            exit;
        }
    }

    // --- Generate signed URL ---

    // Build unique object path: cases/{practice_id}/{case_id}/{upload_type}/{uuid}-{sanitized_filename}
    $sanitizedFilename = sanitizeGcsFilename($filename);
    $uuid = bin2hex(random_bytes(8)); // 16-char hex string
    $casePath = ($caseId && $caseId !== 'new') ? $caseId : 'pending_' . $userId . '_' . date('Ymd_His');
    $objectPath = "cases/{$currentPracticeId}/{$casePath}/{$uploadType}/{$uuid}-{$sanitizedFilename}";

    $result = generateSignedUploadUrl($objectPath, $contentType);

    echo json_encode([
        'success'       => true,
        'signed_url'    => $result['signed_url'],
        'storage_path'  => $result['storage_path'],
        'expires_at'    => $result['expires_at'],
        'expected_size' => $fileSize,
        'max_file_size' => $maxFileSize,
    ]);

} catch (Exception $e) {
    error_log('[SignedURL] Error generating signed URL: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to generate upload URL. Please try again.']);
}
