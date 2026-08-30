<?php
/**
 * Static regression test: session timeout handling is loaded on every
 * authenticated HTML page and not loaded on public/unauthenticated pages.
 */

$baseDir = __DIR__ . '/..';

$authenticatedPages = [
    'main.php',
    'admin-practices.php',
    'billing.php',
    'practice-setup.php',
    'baa-acceptance.php',
];

$publicPages = [
    'index.php',
    'login.php',
    'forgot-password.php',
    'reset-password.php',
    'set-password.php',
    'verify-email.php',
    'about.php',
    'privacy.php',
    'terms.php',
    'resources.php',
    'hipaa-security.php',
];

$results = [];

$timeoutMarker = 'auth-timeout-script.php';

// Shared loader must actually inject session-timeout.js
$sharedLoader = @file_get_contents($baseDir . '/api/' . $timeoutMarker);
$sharedLoaderHasScript = $sharedLoader !== false && strpos($sharedLoader, 'js/session-timeout.js') !== false;
$results[] = 'Shared auth-timeout-script.php loads session-timeout.js: ' . ($sharedLoaderHasScript ? 'PASS' : 'FAIL');

foreach ($authenticatedPages as $page) {
    $content = @file_get_contents($baseDir . '/' . $page);
    $found = $content !== false && strpos($content, $timeoutMarker) !== false;
    $results[] = "Authenticated page {$page} loads session timeout: " . ($found ? 'PASS' : 'FAIL');
}

foreach ($publicPages as $page) {
    $content = @file_get_contents($baseDir . '/' . $page);
    $found = $content !== false && (strpos($content, $timeoutMarker) !== false || strpos($content, 'js/session-timeout.js') !== false);
    $results[] = "Public page {$page} does not load session timeout: " . ($found ? 'FAIL' : 'PASS');
}

header('Content-Type: text/plain');
echo implode("\n", $results) . "\n";
