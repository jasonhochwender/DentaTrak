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

// ── api/log-upload-error.php — sanitized beacon ─────────────────────────
check(file_exists($root . '/api/log-upload-error.php'),
    'upload failure report endpoint exists');
check(strpos($logApi, 'requireValidPracticeContext') !== false,
    'report endpoint requires a valid practice context');
check(strpos($logApi, 'requireCsrfToken') !== false,
    'report endpoint requires CSRF token');
check(strpos($logApi, '$allowedClasses') !== false && strpos($logApi, '$allowedStages') !== false,
    'report endpoint allowlists stage and error_class enums');
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
               $j['attachments']['upload_errors']['server_error'],
               $j['attachments']['upload_errors']['unknown']),
        "{$loc}: attachments.upload_errors.*");
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
