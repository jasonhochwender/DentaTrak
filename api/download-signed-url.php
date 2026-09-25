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
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/attachment-display.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

// Set security headers
setApiSecurityHeaders();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => t('api.attachment_content.method_not_allowed')]);
    exit;
}

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => t('api.attachment_content.authentication_required')]);
    exit;
}

// Validate CSRF token
requireCsrfToken();

try {
    // Parse JSON request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('api.attachment_content.invalid_json')]);
        exit;
    }

    $storagePath = $input['storage_path'] ?? '';
    $filename    = $input['filename'] ?? '';

    if (empty($storagePath)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('api.attachment_content.missing_storage_path')]);
        exit;
    }

    // SECURITY: Validate storage path belongs to this practice
    $expectedPrefix = "cases/{$currentPracticeId}/";
    if (strpos($storagePath, $expectedPrefix) !== 0) {
        logSecurityEvent('attachment_access_denied', [
            'endpoint' => 'download-signed-url',
            'reason' => 'practice_prefix_mismatch',
            'attempted_practice_id' => explode('/', $storagePath)[1] ?? '',
        ]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => t('api.attachment_content.access_denied')]);
        exit;
    }

    // Prevent path traversal
    if (strpos($storagePath, '..') !== false) {
        logSecurityEvent('attachment_access_denied', [
            'endpoint' => 'download-signed-url',
            'reason' => 'path_traversal',
        ]);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => t('api.attachment_content.invalid_storage_path')]);
        exit;
    }

    // SECURITY: Path prefix only proves the file belongs to this practice.
    // For Assigned Only users, also verify the specific case (parsed from
    // cases/{practiceId}/{caseId}/...) is assigned to them. Pending uploads
    // (cases/{practiceId}/pending_.../...) precede case creation and have no
    // cases_cache row yet, so they are exempt from this per-case check.
    $storagePathParts = explode('/', $storagePath);
    $pathCaseId = $storagePathParts[2] ?? '';
    if ($pathCaseId !== '' && strpos($pathCaseId, 'pending_') !== 0) {
        requireCaseAccess($pathCaseId, $currentPracticeId);
    }

    // Resolve the download filename through the shared safe display-name
    // resolver. The client-supplied value is run through it too: legacy
    // records can carry a path-shaped fileName (cases/{p}/... or flattened
    // cases_...), which the resolver either recovers from the "{uuid}-{name}"
    // object tail or rejects, in which case the storage path itself is tried.
    // The resolved name is only used for Content-Disposition on the signed
    // response; raw storage paths must never become the saved filename.
    $downloadFilename = resolveAttachmentDisplayName(['fileName' => $filename])
        ?? resolveAttachmentDisplayName(['fileName' => $storagePath])
        ?? basename($storagePath);

    // Extension preservation: if the resolved name lost its extension but the
    // stored object name carries one, restore it so the OS/file associations
    // still work for the downloaded file.
    $objectExt = pathinfo(basename($storagePath), PATHINFO_EXTENSION);
    if ($objectExt !== '' && pathinfo($downloadFilename, PATHINFO_EXTENSION) === '') {
        $downloadFilename .= '.' . $objectExt;
    }

    // Generate short-lived signed download URL configured so GCS serves the
    // object with Content-Disposition: attachment - browser-viewable types
    // (JPG/PNG/PDF) then download instead of rendering inline.
    $signedUrl = generateSignedDownloadUrl($storagePath, null, $downloadFilename);

    // Audit the authorized download grant. The signed URL is the disclosure
    // event - GCS serves the bytes afterward without another app request, so
    // issuing the URL is the reliable point to record. Never deduplicated:
    // each issuance is a separate download grant.
    auditAttachmentAccess(PHI_ACTION_ATTACHMENT_DOWNLOAD, $storagePath);

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
    echo json_encode(['success' => false, 'error' => t('api.download.url_failed')]);
}
