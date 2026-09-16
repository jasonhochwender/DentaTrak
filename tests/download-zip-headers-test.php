<?php
/**
 * Static verification that the streamed ZIP download sets the intended
 * same-origin iframe headers and does not weaken the preflight endpoint.
 */

declare(strict_types=1);

$downloadSrc = file_get_contents(__DIR__ . '/../api/download-case-attachments-zip.php');
$preflightSrc = file_get_contents(__DIR__ . '/../api/preflight-download-case-attachments-zip.php');

$results = [];
$results[] = 'Download X-Frame-Options SAMEORIGIN: ' . (
    strpos($downloadSrc, "header('X-Frame-Options: SAMEORIGIN')") !== false ? 'PASS' : 'FAIL'
);
$results[] = 'Download CSP frame-ancestors self: ' . (
    strpos($downloadSrc, "Content-Security-Policy: frame-ancestors 'self'") !== false ? 'PASS' : 'FAIL'
);
$results[] = 'Preflight does not override X-Frame-Options: ' . (
    strpos($preflightSrc, 'X-Frame-Options: SAMEORIGIN') === false ? 'PASS' : 'FAIL'
);
$results[] = 'Preflight keeps DENY from setApiSecurityHeaders: ' . (
    strpos($preflightSrc, 'setApiSecurityHeaders()') !== false ? 'PASS' : 'FAIL'
);

// Regression: the hidden download iframe lives inside main.php, whose CSP must
// allow same-origin frame loads. A frame-src directive without 'self' makes
// the browser block the download navigation before it ever reaches the server.
$headersSrc = file_get_contents(__DIR__ . '/../api/security-headers.php');
$results[] = 'Page CSP frame-src allows self (download iframe): ' . (
    strpos($headersSrc, "frame-src 'self'") !== false ? 'PASS' : 'FAIL'
);

// The download endpoint must emit the handoff cookie the client polls for.
$results[] = 'Download endpoint sets handoff cookie: ' . (
    strpos($downloadSrc, "dt_zip_dl_") !== false ? 'PASS' : 'FAIL'
);
$results[] = 'Download endpoint validates download_token: ' . (
    strpos($downloadSrc, 'download_token') !== false
    && strpos($downloadSrc, '/^[A-Za-z0-9_-]{8,64}$/') !== false ? 'PASS' : 'FAIL'
);

header('Content-Type: text/plain');
echo implode("\n", $results);
