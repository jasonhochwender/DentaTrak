<?php
// This page has moved. Its content was consolidated into the
// "Dental Case Tracking Software" cornerstone page to avoid duplicate
// content competing for the same search queries. Redirect permanently
// so search engines transfer ranking signals to the new canonical URL.
require_once __DIR__ . '/api/appConfig.php';

$baseUrl = rtrim($appConfig['baseUrl'], '/') . '/';
$target = $baseUrl . ($appConfig['public_urls']['article_dental_case_tracking_software'] ?? 'dental-case-tracking-software');

header('Location: ' . $target, true, 301);
exit;
