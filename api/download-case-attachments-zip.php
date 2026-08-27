<?php
/**
 * Secure case-level bulk attachment download.
 *
 * Downloads all eligible GCS attachments for a single case as one ZIP.
 * Single-case only. No multi-case, board, practice, or search-result
 * bulk download is supported.
 *
 * POST /api/download-case-attachments-zip.php
 * Request body (JSON):
 *   case_id  - the case identifier
 *   csrf_token (optional if X-CSRF-Token header is sent)
 *
 * On success: application/zip stream with attachment disposition.
 * On failure: JSON error.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/case-zip-helpers.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['case_id'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing required field: case_id']);
    exit;
}

$caseId = $input['case_id'];

// Authoritative case access (includes Assigned Only and cross-practice checks).
$case = requireCaseAccess($caseId, $currentPracticeId);

// Load attachments from server-side case data only.
$attachments = [];
if (!empty($case['attachments_json'])) {
    $decoded = json_decode($case['attachments_json'], true);
    if (is_array($decoded)) {
        $attachments = $decoded;
    }
}

$MAX_IMMEDIATE_SIZE = getBulkZipMaxSize();

// Build the eligible list from authoritative case data and GCS metadata.
$eligible = [];
$totalActualSize = 0;
$usedNames = [];

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

foreach ($attachments as $att) {
    $storagePath = $att['storagePath'] ?? '';
    $fileName = $att['fileName'] ?? ($att['name'] ?? '');
    $storageType = $att['storageType'] ?? '';
    $storedSize = (int)($att['size'] ?? 0);

    // Only completed, current GCS attachments are eligible.
    if ($storageType !== 'gcs' || empty($storagePath) || empty($fileName)) {
        continue;
    }

    // Reject any record that does not have the exact case prefix.
    if (!isValidAttachmentPath($storagePath, $currentPracticeId, $caseId)) {
        continue;
    }

    $object = $bucket->object($storagePath);

    // Drop missing or inaccessible objects; this also catches deletions that
    // occurred after the page was rendered.
    if (!$object->exists()) {
        continue;
    }

    $info = $object->info();
    $actualSize = (int)($info['size'] ?? 0);

    // If we have both values, report a size mismatch but use the authoritative
    // GCS size for limit and ZIP calculation.
    if ($storedSize > 0 && $actualSize !== $storedSize) {
        error_log('[DownloadAllZIP] Size mismatch for ' . $storagePath . ': stored=' . $storedSize . ', actual=' . $actualSize);
    }

    $zipName = sanitizeZipFilename($fileName, $usedNames);
    $usedNames[] = strtolower($zipName);

    $eligible[] = [
        'storagePath' => $storagePath,
        'fileName' => $fileName,
        'zipName' => $zipName,
        'size' => $actualSize,
    ];
    $totalActualSize += $actualSize;
}

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

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'ZIP generation is not available on this server.']);
    exit;
}

$zipBaseName = 'case-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseId) . '-attachments';
$zipFile = tempnam(sys_get_temp_dir(), 'dt_zip_') . '.zip';
$zip = new ZipArchive();

$opened = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($opened !== true) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unable to create ZIP archive.']);
    exit;
}

$zipMembers = [];
$tempFiles = [];

try {
    foreach ($eligible as $item) {
        $object = $bucket->object($item['storagePath']);

        $tempFile = tempnam(sys_get_temp_dir(), 'dt_att_');
        $tempFiles[] = $tempFile;

        $stream = $object->downloadAsStream();
        $handle = fopen($tempFile, 'wb');
        while (!$stream->eof()) {
            fwrite($handle, $stream->read(8192));
        }
        fclose($handle);

        $zip->addFile($tempFile, $item['zipName']);
        $zipMembers[] = $item['zipName'];
    }
} catch (Exception $e) {
    $zip->close();
    foreach ($tempFiles as $tempFile) {
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }
    if (file_exists($zipFile)) {
        @unlink($zipFile);
    }
    error_log('[DownloadAllZIP] Error building ZIP for case ' . $caseId . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => t('attachments.download_all_failed', ['message' => 'Failed to build attachment archive'])]);
    exit;
}

if (count($zipMembers) === 0) {
    $zip->close();
    foreach ($tempFiles as $tempFile) {
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }
    if (file_exists($zipFile)) {
        @unlink($zipFile);
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => t('attachments.download_all_empty')]);
    exit;
}

$zip->close();

// Record that the server generated the ZIP; we cannot prove client delivery.
logCaseActivity($caseId, 'attachments_zip_generated', null, null, [
    'user_id' => $userId,
    'file_count' => count($zipMembers),
    'total_size' => (int)filesize($zipFile),
]);

// Stream the ZIP and clean up.
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipBaseName . '.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
http_response_code(200);

readfile($zipFile);

@unlink($zipFile);
foreach ($tempFiles as $tempFile) {
    if (file_exists($tempFile)) {
        @unlink($tempFile);
    }
}
exit;
