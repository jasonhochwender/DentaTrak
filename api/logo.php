<?php
/**
 * Practice logo streaming endpoint
 *
 * Streams a practice logo stored in GCS to authenticated users who belong to
 * the practice that owns the logo. Logos live in the bucket (not the
 * container filesystem, which is ephemeral on Cloud Run), so they survive
 * deploys and instance recycling and are served identically by every
 * instance.
 *
 * GET /api/logo.php?p=logos/{practiceId}/{objectName}
 *
 * practices.logo_path stores this endpoint's URL for GCS-backed logos;
 * legacy rows may still hold local uploads/logos/... paths which are served
 * directly by the web server where those files exist.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/gcs-storage.php';

// Read-only GET; CSRF is not applicable to a credential-cookie image fetch.
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = $_SESSION['db_user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$objectPath = $_GET['p'] ?? '';

// Validate object path shape: logos/{practiceId}/{uuid-filename}.{ext}
// Flat single-segment filename - no traversal, no nested directories.
if (!is_string($objectPath) ||
    !preg_match('#^logos/(\d+)/[a-zA-Z0-9._-]+\.(jpe?g|png|gif|svg|webp)$#i', $objectPath, $m)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid logo path']);
    exit;
}
$logoPracticeId = (int)$m[1];

// The caller must belong to the practice that owns this logo.
$stmt = $pdo->prepare("SELECT 1 FROM practice_users WHERE practice_id = :pid AND user_id = :uid LIMIT 1");
$stmt->execute(['pid' => $logoPracticeId, 'uid' => $userId]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Authorization is complete. Release the session lock before the GCS stream
// so a slow transfer does not block other requests for this session. GET API
// reads already skip the inactivity-timer refresh in session.php.
session_write_close();

try {
    $bucket = getGcsBucket();
    $object = $bucket->object($objectPath);

    if (!$object->exists()) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Logo not found']);
        exit;
    }

    $info = $object->info();
    $contentType = $info['contentType'] ?? 'application/octet-stream';
    if (!in_array($contentType, ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'])) {
        $contentType = 'application/octet-stream';
    }
    $size = (int)($info['size'] ?? 0);

    header('Content-Type: ' . $contentType);
    if ($size > 0) {
        header('Content-Length: ' . $size);
    }
    // Object names are unique per upload, so cached copies stay valid.
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    // SVGs can carry scripts; never let the image document run them.
    header("Content-Security-Policy: default-src 'none'; script-src 'none'");
    http_response_code(200);

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }

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
    error_log('[logo] Error streaming logo: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to load logo']);
    exit;
}
