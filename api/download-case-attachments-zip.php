<?php
/**
 * Secure case-level bulk attachment download.
 *
 * Streams all eligible GCS attachments for a single case as one ZIP directly
 * to php://output using ZipStream-PHP. No source files and no completed ZIP
 * are written to the temporary filesystem.
 *
 * Single-case only. No multi-case, board, practice, or search-result
 * bulk download is supported.
 *
 * POST /api/download-case-attachments-zip.php
 * Request body (form or JSON):
 *   case_id  - the case identifier
 *   csrf_token (optional if X-CSRF-Token header is sent)
 *
 * On success: application/zip stream with attachment disposition.
 * On failure: JSON error before any ZIP content is written.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/case-zip-helpers.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

use ZipStream\ZipStream;
use ZipStream\CompressionMethod;

// Defense in depth: fail before any output if the ZIP library is missing.
$zipStreamError = getZipStreamDependencyError();
if ($zipStreamError !== null) {
    error_log('[DownloadAllZIP] ZipStream-PHP dependency is missing at download time.');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $zipStreamError]);
    exit;
}

setApiSecurityHeaders();

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

// Release the per-session advisory lock before any long-running GCS/ZIP work.
// Auth, CSRF, practice context, and case access are already in local variables.
$_SESSION['last_activity'] = time();
session_write_close();

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

// Build the eligible list using the same shared logic as the preflight endpoint.
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

// Once we start the ZIP, headers are committed; JSON errors are no longer possible.
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// Allow the same-origin hidden iframe to receive the attachment download;
// preflight and all other endpoints still use DENY.
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Content-Type: application/zip');
$zipBaseName = 'case-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseId) . '-attachments';
header('Content-Disposition: attachment; filename="' . $zipBaseName . '.zip"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');
header('Content-Encoding: identity');
http_response_code(200);

// Allow the script to outlive short PHP and Apache timeouts. The Cloud Run
// platform timeout is still the outer bound and is not changed here.
set_time_limit(0);
ignore_user_abort(true);

$zip = new ZipStream(
    outputStream: fopen('php://output', 'wb'),
    sendHttpHeaders: false,
    defaultCompressionMethod: CompressionMethod::STORE,
    enableZip64: true,
    defaultEnableZeroHeader: true,
    flushOutput: true,
);

try {
    foreach ($eligible as $item) {
        $object = $bucket->object($item['storagePath']);
        $stream = $object->downloadAsStream();

        $zip->addFileFromPsr7Stream(
            fileName: $item['zipName'],
            stream: $stream,
            compressionMethod: CompressionMethod::STORE,
            exactSize: $item['size'],
            enableZeroHeader: true,
        );

        // Release the GCS stream handle immediately.
        if ($stream->isSeekable() || method_exists($stream, 'close')) {
            $stream->close();
        }
    }

    $zip->finish();

    // Record that the server generated the ZIP; we cannot prove client delivery.
    logCaseActivity($caseId, 'attachments_zip_generated', null, null, [
        'user_id' => $userId,
        'file_count' => count($eligible),
        'total_size' => $totalActualSize,
    ]);
} catch (Exception $e) {
    error_log('[DownloadAllZIP] Stream error for case ' . $caseId . ': ' . $e->getMessage());
    // Headers and partial ZIP bytes may already be in transit. We cannot
    // recover safely, so we exit without sending additional JSON/HTML.
    exit;
}

exit;
