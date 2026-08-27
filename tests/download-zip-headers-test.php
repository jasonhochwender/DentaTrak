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

header('Content-Type: text/plain');
echo implode("\n", $results);
