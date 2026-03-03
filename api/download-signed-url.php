<?php
/**
 * Signed Download URL API Endpoint
 * 
 * Generates short-lived signed GET URLs for secure file downloads from GCS.
 * Files are NEVER served publicly — this endpoint validates authorization
 * and returns a time-limited URL.
 * 
 * POST /api/download-signed-url.php
 * 
 * Request body (JSON):
 *   storage_path - The GCS object path
 *   filename     - Original filename (for Content-Disposition)
 * 
 * Response (JSON):
 *   signed_url   - The signed GET URL for download
 *   expires_at   - ISO 8601 expiration timestamp
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

    $storagePath = $input['storage_path'] ?? '';
    $filename    = $input['filename'] ?? '';

    if (empty($storagePath)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required field: storage_path']);
        exit;
    }

    // SECURITY: Validate storage path belongs to this practice
    $expectedPrefix = "cases/{$currentPracticeId}/";
    if (strpos($storagePath, $expectedPrefix) !== 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    // Prevent path traversal
    if (strpos($storagePath, '..') !== false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid storage path']);
        exit;
    }

    // Generate short-lived signed download URL
    $signedUrl = generateSignedDownloadUrl($storagePath);

    global $appConfig;
    $expiry = $appConfig['gcs']['download_url_expiry'] ?? 300;

    echo json_encode([
        'success'    => true,
        'signed_url' => $signedUrl,
        'expires_at' => date('c', time() + $expiry),
    ]);

} catch (Exception $e) {
    error_log('[DownloadURL] Error generating signed URL: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to generate download URL. Please try again.']);
}
