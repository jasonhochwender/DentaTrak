<?php
/**
 * Public User Guide endpoint
 * Serves the User Guide PDF without requiring authentication.
 */

$pdfPath = __DIR__ . '/../resource-assets/DentaTrak User Guide.pdf';

// Always set the robots tag, even for 404
header('X-Robots-Tag: noindex, nofollow');

if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User Guide not available']);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="DentaTrak User Guide.pdf"');
header('Content-Length: ' . filesize($pdfPath));

readfile($pdfPath);
exit;
