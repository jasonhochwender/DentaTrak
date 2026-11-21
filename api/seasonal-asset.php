<?php
/**
 * Public streamer for admin-uploaded seasonal theme artwork.
 *
 * The login page is unauthenticated, so overrides for the built-in
 * decorations in images/seasonal/ are served here without a session.
 * Only objects under the seasonal/ prefix are reachable; keys are
 * strictly validated, responses are sniff-safe, and SVGs are served
 * with a scriptless CSP (they render inside <img>, which is already
 * script-inert, but the header is belt-and-suspenders).
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/seasonal-themes.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

$key = (string)($_GET['k'] ?? '');
if (!seasonalValidAssetKey($key)) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$mime = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];

$gcsBucketName = trim((string)($appConfig['gcs']['bucket_name'] ?? ''));
if ($gcsBucketName !== '') {
    require_once __DIR__ . '/gcs-storage.php';
    try {
        $bucket = getGcsBucket();
        $object = $bucket->object('seasonal/' . $key);
        if ($object->exists()) {
            header('Content-Type: ' . $mime);
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
            $stream = $object->downloadAsStream();
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
            exit;
        }
    } catch (Throwable $e) {
        error_log('[seasonal-asset] GCS read failed: ' . $e->getMessage());
    }
    http_response_code(404);
    exit;
}

// Local development without GCS: uploads/seasonal/<key>
$path = __DIR__ . '/../uploads/seasonal/' . $key;
$real = realpath($path);
$base = realpath(__DIR__ . '/../uploads/seasonal');
if ($real === false || $base === false || strpos($real, $base) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
readfile($real);
