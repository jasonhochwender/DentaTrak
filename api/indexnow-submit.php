<?php
/**
 * IndexNow submission CLI.
 *
 * Usage:
 *   php api/indexnow-submit.php all
 *   php api/indexnow-submit.php https://dentatrak.com/about
 *   php api/indexnow-submit.php https://dentatrak.com/about https://dentatrak.com/resources
 */

require_once __DIR__ . '/indexnow.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

$args = array_slice($argv, 1);

if (empty($args)) {
    echo "Usage:\n";
    echo "  php api/indexnow-submit.php all\n";
    echo "  php api/indexnow-submit.php <url>\n";
    echo "  php api/indexnow-submit.php <url1> <url2> ...\n\n";
    echo "Examples:\n";
    echo "  php api/indexnow-submit.php all\n";
    echo "  php api/indexnow-submit.php https://dentatrak.com/about\n";
    exit(1);
}

try {
    $urls = ($args[0] === 'all') ? getAllIndexNowUrls() : $args;

    $config = getIndexNowConfig();
    echo "Submitting " . count($urls) . " URL(s) to IndexNow...\n";
    echo "Host: {$config['host']}\n";
    echo "Key Location: {$config['keyLocation']}\n\n";

    $result = submitIndexNowUrls($urls);

    echo "HTTP Status: " . $result['httpCode'] . "\n";
    echo "Response:\n" . $result['body'] . "\n";

    exit($result['httpCode'] >= 200 && $result['httpCode'] < 300 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
