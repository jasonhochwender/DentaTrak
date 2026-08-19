<?php
/**
 * Secure attachment content streaming endpoint
 *
 * Streams the binary contents of a case attachment to authenticated,
 * authorized users. This endpoint is used by the File Viewer to avoid
 * CORS and public URL issues with direct storage fetches.
 *
 * POST /api/attachment-content.php
 * Request body (JSON):
 *   storage_path - The GCS object path
 *
 * Response:
 *   On success: the file binary stream with appropriate Content-Type
 *   On failure: JSON { success: false, error: '...' }
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

// Set API security headers
setApiSecurityHeaders();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Validate CSRF token
requireCsrfToken();

// Parse JSON request body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid JSON request body']);
    exit;
}

$storagePath = $input['storage_path'] ?? '';

if (empty($storagePath)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing required field: storage_path']);
    exit;
}

// SECURITY: Validate storage path belongs to this practice
$expectedPrefix = "cases/{$currentPracticeId}/";
if (strpos($storagePath, $expectedPrefix) !== 0) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Prevent path traversal
if (strpos($storagePath, '..') !== false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid storage path']);
    exit;
}

// SECURITY: For assigned-only users, also verify the specific case.
// Pending uploads (cases/{practiceId}/pending_.../...) precede case creation.
$storagePathParts = explode('/', $storagePath);
$pathCaseId = $storagePathParts[2] ?? '';
if ($pathCaseId !== '' && strpos($pathCaseId, 'pending_') !== 0) {
    requireCaseAccess($pathCaseId, $currentPracticeId);
}

try {
    $bucket = getGcsBucket();
    $object = $bucket->object($storagePath);

    if (!$object->exists()) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }

    $info = $object->info();
    $contentType = $info['contentType'] ?? 'application/octet-stream';
    $size = (int)($info['size'] ?? 0);

    // Stream the file contents
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $size);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    http_response_code(200);

    $stream = $object->downloadAsStream();
    while (!$stream->eof()) {
        echo $stream->read(8192);
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
    $stream->close();
    exit;
} catch (Exception $e) {
    error_log('[AttachmentContent] Error streaming attachment: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to stream attachment']);
    exit;
}
