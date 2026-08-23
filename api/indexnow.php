<?php
/**
 * IndexNow search engine notification helper.
 *
 * This file provides a reusable server-side helper for submitting canonical
 * public DentaTrak URLs to IndexNow-compatible search engines.
 *
 * Configuration:
 * - INDEXNOW_KEY and INDEXNOW_HOST must be set in the environment (.env).
 * - The verification file must exist at /{INDEXNOW_KEY}.txt on the public host.
 *
 * Usage:
 *   php api/indexnow-submit.php all
 *   php api/indexnow-submit.php https://dentatrak.com/about
 *   php api/indexnow-submit.php https://dentatrak.com/about https://dentatrak.com/resources
 */

require_once __DIR__ . '/appConfig.php';

/**
 * Canonical, indexable public URL paths on dentatrak.com (relative to /).
 */
const INDEXNOW_PUBLIC_URLS = [
    'https://dentatrak.com/',
    'https://dentatrak.com/dental-case-tracking-software',
    'https://dentatrak.com/dental-case-tracking-software-vs-pms',
    'https://dentatrak.com/dental-lab-case-tracking',
    'https://dentatrak.com/crown-and-bridge-case-tracking',
    'https://dentatrak.com/implant-case-tracking',
    'https://dentatrak.com/how-to-track-dental-cases',
    'https://dentatrak.com/visual-dental-case-workflow',
    'https://dentatrak.com/dental-case-tracking-vs-spreadsheets',
    'https://dentatrak.com/dental-remake-cost',
    'https://dentatrak.com/resources',
    'https://dentatrak.com/about',
    'https://dentatrak.com/hipaa-security',
    'https://dentatrak.com/privacy',
    'https://dentatrak.com/terms',
];

function getIndexNowConfig(): array {
    $key = getEnvVar('INDEXNOW_KEY');
    $host = rtrim(getEnvVar('INDEXNOW_HOST', 'https://dentatrak.com'), '/');

    if (empty($key)) {
        throw new RuntimeException('INDEXNOW_KEY is not configured.');
    }

    return [
        'key'         => $key,
        'host'        => parse_url($host, PHP_URL_HOST) ?: 'dentatrak.com',
        'keyLocation' => $host . '/' . $key . '.txt',
        'apiEndpoint' => 'https://api.indexnow.org/IndexNow',
        'scheme'      => parse_url($host, PHP_URL_SCHEME) ?: 'https',
    ];
}

function getAllIndexNowUrls(): array {
    return INDEXNOW_PUBLIC_URLS;
}

function validateIndexNowUrls(array $urls): array {
    $allowed = array_flip(INDEXNOW_PUBLIC_URLS);
    $valid = [];

    foreach ($urls as $url) {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL: ' . $url);
        }

        $parts = parse_url($url);
        if (empty($parts['host']) || $parts['host'] !== 'dentatrak.com') {
            throw new InvalidArgumentException('URL is not on the production domain: ' . $url);
        }

        if (!empty($parts['query']) || !empty($parts['fragment'])) {
            throw new InvalidArgumentException('URL contains query or fragment and cannot be submitted: ' . $url);
        }

        if (!isset($allowed[$url])) {
            throw new InvalidArgumentException('URL is not in the canonical public URL set: ' . $url);
        }

        $valid[] = $url;
    }

    return $valid;
}

function submitIndexNowUrls(array $urls): array {
    $urls = validateIndexNowUrls($urls);
    $config = getIndexNowConfig();

    $payload = [
        'host'        => $config['host'],
        'key'         => $config['key'],
        'keyLocation' => $config['keyLocation'],
        'urlList'     => $urls,
    ];

    $ch = curl_init($config['apiEndpoint']);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('IndexNow request failed: ' . $error);
    }

    return [
        'httpCode' => $httpCode,
        'body'     => $responseBody,
    ];
}
