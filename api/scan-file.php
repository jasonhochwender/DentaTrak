<?php
/**
 * Scan File Download Endpoint
 *
 * Streams one scanner-inbox file to an authenticated, authorized user.
 * Mirrors the authorization contract of attachment-content.php:
 *   session -> valid practice context -> the file's scan must belong to the
 *   session practice -> if the file is attached to a case, the per-case
 *   access check (assigned-only included) applies to that case.
 *
 * Files are resolved by database row (file_id), never by raw path - so
 * there is no client-controlled path to sanitize beyond the stored value,
 * which ScanFileStore itself validates.
 *
 * GET api/scan-file.php?file_id=123[&download=1]
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/integrations/scanners/ScanFileStore.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

setApiSecurityHeaders();

function scanFileFail(int $status): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not found']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    scanFileFail(405);
}

if (!isset($_SESSION['db_user_id'])) {
    scanFileFail(401);
}
$userId = (int)$_SESSION['db_user_id'];
$currentPracticeId = requireValidPracticeContext();

global $pdo;
if (!$pdo) {
    scanFileFail(500);
}

$fileId = (int)($_GET['file_id'] ?? 0);
if ($fileId <= 0) {
    scanFileFail(400);
}

$stmt = $pdo->prepare("
    SELECT f.*, s.practice_id AS scan_practice_id
    FROM scanner_scan_files f
    JOIN scanner_incoming_scans s ON s.id = f.scan_id
    WHERE f.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file || (int)$file['scan_practice_id'] !== (int)$currentPracticeId) {
    logSecurityEvent('scan_file_denied', ['reason' => 'practice_mismatch']);
    scanFileFail(404);
}

// Attached file -> per-case ACL, same as attachment-content.php. Attached
// files are ordinary case attachments: they stay downloadable even if the
// experimental inbox flag is later turned off, because the case ACL (not
// the inbox workflow) governs them.
if (!empty($file['attached_case_id'])) {
    requireCaseAccess($file['attached_case_id'], $currentPracticeId);
} else {
    // Unattached inbox files only exist inside the experimental workflow:
    // the shared inbox check covers flag, allowlist, plan, and the
    // user-level permission. Attached files above keep normal case access.
    if (!userCanAccessScanInbox((int)$currentPracticeId)) {
        logSecurityEvent('scan_file_denied', ['reason' => 'inbox_permission']);
        scanFileFail(403);
    }
}

if ($file['status'] !== ScanIngestion::FILE_READY || empty($file['storage_path']) || empty($file['storage_driver'])) {
    scanFileFail(404);
}

// Release the session lock before streaming.
session_write_close();

$opened = ScanFileStore::openStream((string)$file['storage_driver'], (string)$file['storage_path']);
if ($opened === null) {
    scanFileFail(404);
}
[$stream, $size, $contentType] = $opened;

// Prefer the content type recorded at ingest.
if (!empty($file['content_type'])) {
    $contentType = $file['content_type'];
}

if (function_exists('auditAttachmentAccess') && defined('PHI_ACTION_ATTACHMENT_VIEW')) {
    auditAttachmentAccess(PHI_ACTION_ATTACHMENT_VIEW, (string)$file['storage_path']);
}
ScanIngestion::recordEvent($pdo, (int)$file['scan_id'], (int)$currentPracticeId, $userId, 'viewed', 'file_id=' . $fileId);

$downloadName = preg_replace('/[^\x20-\x7E]/', '_', (string)$file['file_name']);
$downloadName = str_replace(['"', '\\'], '_', $downloadName);

header('Content-Type: ' . $contentType);
if ($size > 0) {
    header('Content-Length: ' . $size);
}
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
http_response_code(200);

// The local driver yields a plain PHP stream resource; the GCS driver
// yields a PSR stream object.
$isResource = is_resource($stream);
while ($isResource ? !feof($stream) : !$stream->eof()) {
    echo $isResource ? fread($stream, 8192) : $stream->read(8192);
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}
if ($isResource) {
    fclose($stream);
} else {
    $stream->close();
}
exit;
