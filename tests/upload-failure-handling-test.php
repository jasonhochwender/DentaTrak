<?php
/**
 * Source-level regression checks for upload failure handling.
 *
 * The case-attachment and comment-image pipelines must surface accurate,
 * localized failure reasons (session, access, validation, size/type,
 * stall, server error) instead of mapping every failure to a misleading
 * "check your internet connection" toast, and must be traceable via a
 * request identifier without logging filenames or patient data.
 *
 * Run: php tests/upload-failure-handling-test.php
 */

$pass = 0;
$fail = 0;

function check($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

$root = dirname(__DIR__);
$gcsJs      = file_get_contents($root . '/js/gcs-upload.js');
$appJs      = file_get_contents($root . '/js/app.js');
$commentsJs = file_get_contents($root . '/js/case-comments.js');
$signedUrl  = file_get_contents($root . '/api/upload-signed-url.php');
$logApi     = file_get_contents($root . '/api/log-upload-error.php');
$mainPhp    = file_get_contents($root . '/main.php');

// ── js/gcs-upload.js — structured errors, not substring sniffing ─────────
check(strpos($gcsJs, 'ALLOWED_EXTENSIONS') !== false,
    'client-side allowed-extension allowlist exists');
check(strpos($gcsJs, 'upload_unsupported_type') !== false,
    'unsupported extensions rejected before upload with localized message');
check(strpos($gcsJs, "data.message || data.error") !== false ||
      strpos($gcsJs, 'data.message || data.error') !== false,
    'signed-URL responses read both message and error keys');
check(strpos($gcsJs, 'uploadStage') !== false,
    'errors carry uploadStage for classification');
check(strpos($gcsJs, 'uploadHttpStatus') !== false,
    'errors carry uploadHttpStatus for classification');
check(strpos($gcsJs, 'sessionExpired') !== false,
    'errors carry sessionExpired flag');
check(strpos($gcsJs, 'uploadRequestId') !== false,
    'errors carry the server request_id for correlation');
check(strpos($gcsJs, 'signedUrlErrorMessage') !== false,
    'signed-URL responses map error_code to localized messages');
check(preg_match('/signed-url[^\'"]*401|status === 401/', $gcsJs) === 1,
    '401 responses classified as session expiry');
check(strpos($gcsJs, '__gcsUploadedResult') !== false,
    'successful uploads tagged so retries skip them');
check(strpos($gcsJs, 'uploadErrors') !== false && strpos($gcsJs, 'uploadedFiles') !== false,
    'batch error carries structured per-file failures and successes');
check(strpos($gcsJs, 'reportUploadFailure') !== false &&
      strpos($gcsJs, 'log-upload-error.php') !== false,
    'permanent failures reported to the diagnostics endpoint');
check(preg_match('/AbortController\(\)[\s\S]{0,400}signed-url/', $gcsJs) === 1 ||
      strpos($gcsJs, 'signedUrlController') !== false,
    'signed-URL fetch has an abort/timeout controller');
check(preg_match('/xhr\.status === 403 \|\| xhr\.status === 408 \|\| xhr\.status === 429 \|\| xhr\.status >= 500/', $gcsJs) === 1,
    'transient GCS PUT rejections (403/408/429/5xx) are retryable');
check(strpos($gcsJs, 'normalizeContentType') !== false &&
      strpos($gcsJs, 'text/plain') !== false,
    'ASCII scan files misdetected as text/plain are normalized client-side');
check(preg_match("/'stl', 'obj', 'ply'|'stl','obj','ply'|\"stl\"/", $gcsJs) === 1 &&
      preg_match('/SCAN_TEXT_OK.*stl|stl.*SCAN_TEXT_OK/s', $gcsJs) === 1,
    'client normalization is scoped to scan extensions (stl/obj/ply/dcm)');
check(strpos($gcsJs, 'SCAN_ACCEPTED_TYPES') !== false,
    'client mirrors the server MIME allowlist for scan extensions');
check(preg_match('/SCAN_ACCEPTED_TYPES\.indexOf\(ct\) === -1/', $gcsJs) === 1,
    'any unlisted scan MIME (text/plain, model/x.stl-ascii, ...) normalizes to octet-stream');
check(substr_count($gcsJs, 'normalizeContentType(') >= 3,
    'normalization applied in batch collection AND uploadSingleFile');
check(strpos($gcsJs, 'empty_file') !== false &&
      strpos($gcsJs, 'fileSize <= 0') !== false,
    'zero-byte/unreadable files rejected client-side with a real message');
check(strpos($gcsJs, 'data.content_type') !== false,
    'PUT uses the content type the server actually signed');

// ── js/app.js — classifier uses structured props ─────────────────────────
check(strpos($appJs, "indexOf('Failed to upload')") === false,
    'no substring check on the old "Failed to upload" wrapper');
check(strpos($appJs, 'error.uploadStage') !== false,
    'handleCaseSubmissionError classifies on uploadStage');
check(strpos($appJs, 'error.sessionExpired') !== false,
    'handleCaseSubmissionError classifies on sessionExpired');

// ── js/case-comments.js — real reason + partial-success skip ─────────────
check(strpos($commentsJs, 'upload_failed_reason') !== false,
    'comment image failures surface the underlying reason');
check(strpos($commentsJs, '__gcsUploadedResult') !== false,
    'comment retry skips already-uploaded images');
check(strpos($commentsJs, 'sessionExpired') !== false,
    'comment upload propagates session expiry');

// ── api/upload-signed-url.php — request id + error codes + safe logging ──
check(strpos($signedUrl, 'uploadRequestId') !== false &&
      strpos($signedUrl, 'request_id') !== false,
    'signed-URL endpoint emits a request_id');
check(strpos($signedUrl, 'error_code') !== false,
    'signed-URL rejections carry a machine-readable error_code');
check(substr_count($signedUrl, "'[UPLOAD]'") >= 1 || substr_count($signedUrl, '[UPLOAD]') >= 2,
    'structured [UPLOAD] log lines emitted');
check(strpos($signedUrl, 'Throwable') !== false,
    'endpoint boundary catches Throwable, not only Exception');
check(strpos($signedUrl, "'filename' =>") === false || strpos($signedUrl, 'raw filename') !== false,
    'log context never includes the raw filename');
check(strpos($signedUrl, "t('api.comments.case_archived')") !== false,
    'archived-case comment-image rejection preserved');
check(strpos($signedUrl, 'requireCaseAccess') !== false,
    'existing-case access check preserved');
check(strpos($signedUrl, "'text/plain'") !== false &&
      strpos($signedUrl, 'application/octet-stream') !== false,
    'server normalizes text/plain detection artifact for scan extensions');
check(preg_match("/binaryScanExts\s*=\s*\[\s*'stl',\s*'obj',\s*'ply',\s*'dcm'\s*\]/", $signedUrl) === 1,
    'server normalization scoped exactly to stl/obj/ply/dcm');
check(strpos($signedUrl, "!in_array(\$contentType, \$allowedMimeTypes)") !== false,
    'any unlisted scan MIME normalizes to octet-stream (not only text/plain)');
check(strpos($signedUrl, "in_array(\$contentType, \$allowedMimeTypes)") !== false,
    'authoritative MIME allowlist check still runs after normalization');
// Extension validation must still run BEFORE the MIME check so the artifact
// normalization sees the validated extension (order safety).
check(strpos($signedUrl, 'in_array($ext, $allowedExtensions)') <
      strpos($signedUrl, 'binaryScanExts') &&
      strpos($signedUrl, 'binaryScanExts') <
      strrpos($signedUrl, 'in_array($contentType, $allowedMimeTypes)'),
    'validation order: extension -> normalization -> MIME allowlist');
// The URL must be signed for the content type the client declared, not the
// normalized value - V4 binds content-type into SignedHeaders, so an older
// cached client PUTting its raw file.type would otherwise 403.
check(strpos($signedUrl, "\$signedContentType") !== false &&
      preg_match('/generateSignedUploadUrl\(\$objectPath,\s*\$signedContentType\)/', $signedUrl) === 1,
    'signed URL binds the client-declared content type');
check(strpos($signedUrl, "'content_type'  => \$signedContentType") !== false,
    'response echoes the signed content type for the PUT header');
check(strpos($signedUrl, "'empty_file'") !== false,
    'zero-byte uploads rejected with a dedicated empty_file error code');
// The global MIME allowlist must NOT gain text/plain (arbitrary text files
// stay rejected by the extension gate; only scan exts get the artifact fix).
$config = file_get_contents($root . '/api/appConfig.php');
check(strpos($config, "'text/plain'") === false,
    'appConfig allowlist does not broadly allow text/plain');

// ── api/log-upload-error.php — sanitized beacon ─────────────────────────
check(file_exists($root . '/api/log-upload-error.php'),
    'upload failure report endpoint exists');
check(strpos($logApi, 'requireValidPracticeContext') !== false,
    'report endpoint requires a valid practice context');
check(strpos($logApi, 'requireCsrfToken') !== false,
    'report endpoint requires CSRF token');
check(strpos($logApi, '$allowedClasses') !== false && strpos($logApi, '$allowedStages') !== false,
    'report endpoint allowlists stage and error_class enums');
check(strpos($logApi, "'empty_file'") !== false,
    'report endpoint accepts the empty_file error class');
check(strpos($logApi, "'message'") === false || strpos($logApi, 'free-form') !== false,
    'report endpoint accepts no free-form message field');
check(strpos($logApi, "'filename'") === false && strpos($logApi, 'file_name') === false,
    'report endpoint never accepts filenames');
check(strpos($logApi, '[UPLOAD-CLIENT]') !== false,
    'report endpoint writes a structured [UPLOAD-CLIENT] log line');

// ── main.php — picker accept hints ───────────────────────────────────────
check(substr_count($mainPhp, 'class="attachment-input"') === 5,
    'all five attachment inputs still present');
check(substr_count($mainPhp, 'attachment-input') >= 5 &&
      substr_count($mainPhp, 'accept=') >= 5,
    'attachment inputs carry accept hints');

// ── Asset cache-busting — immutable-cached JS must never go stale ────────
// .htaccess serves css/js with max-age=1 year immutable; a hardcoded ?v=
// tag that is not bumped on change leaves users on the old code forever.
$assetHelper = file_exists($root . '/api/asset-url.php')
    ? file_get_contents($root . '/api/asset-url.php') : '';
check(strpos($assetHelper, 'appAssetUrl') !== false &&
      strpos($assetHelper, 'md5_file') !== false,
    'api/asset-url.php derives the asset version from file content');
check(strpos($mainPhp, 'appAssetUrl') !== false,
    'main.php uses content-derived asset URLs');
check(preg_match('#(?:js|css)/[a-zA-Z0-9._-]+\?v=[0-9a-z]+#', $mainPhp) === 0,
    'no hardcoded ?v= asset tags remain in main.php');
$loginPhp = file_get_contents($root . '/login.php');
check(strpos($loginPhp, 'appAssetUrl') !== false,
    'login.php uses content-derived asset URLs');

// ── Locales — new keys + neutral toast in every supported language ───────
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
foreach ($locales as $loc) {
    $j = json_decode(file_get_contents($root . "/locales/{$loc}.json"), true);
    check(is_array($j), "{$loc}: JSON parses");
    check(isset($j['attachments']['upload_unsupported_type']),
        "{$loc}: attachments.upload_unsupported_type");
    check(isset($j['attachments']['upload_errors']['session_expired'],
               $j['attachments']['upload_errors']['access_denied'],
               $j['attachments']['upload_errors']['unsupported_type'],
               $j['attachments']['upload_errors']['file_too_large'],
               $j['attachments']['upload_errors']['empty_file'],
               $j['attachments']['upload_errors']['server_error'],
               $j['attachments']['upload_errors']['unknown']),
        "{$loc}: attachments.upload_errors.*");
    check(isset($j['attachments']['upload_empty_file']),
        "{$loc}: attachments.upload_empty_file");
    check(isset($j['comments']['images']['upload_failed_reason']),
        "{$loc}: comments.images.upload_failed_reason");
    $toast = $j['cases']['toast']['upload_failed'] ?? '';
    check($toast !== '' &&
          stripos($toast, 'internet') === false &&
          mb_strpos($toast, 'Internet') === false &&
          mb_strpos($toast, 'Internetverbindung') === false &&
          mb_strpos($toast, 'интернет') === false &&
          mb_strpos($toast, '인터넷') === false,
        "{$loc}: toast is neutral (no internet-connection blame)");
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
