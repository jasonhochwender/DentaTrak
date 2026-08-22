<?php
/**
 * Set explicit session locale for unauthenticated users.
 *
 * Validates the requested locale against supported locales and stores it in
 * the session. No user or practice persistence occurs here.
 */

require_once __DIR__ . '/appConfig.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('auth.errors.method_not_allowed')]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$languageInput = $data['language'] ?? null;

if ($languageInput === null || $languageInput === 'use_practice_default') {
    unset($_SESSION['resolved_locale']);
    echo json_encode(['success' => true, 'language' => 'en-US']);
    exit;
}

$resolved = validateLocale($languageInput, 'en-US');
$_SESSION['resolved_locale'] = $resolved;

echo json_encode(['success' => true, 'language' => $resolved]);
