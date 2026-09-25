<?php
/**
 * Ask DentaTrak - optional thumbs feedback on a single response.
 *
 * Updates ONLY the feedback column of a telemetry row that belongs to the
 * calling user + practice. No question text, answers, or content is ever
 * accepted or stored here.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/ask-dentatrak-telemetry.php';

header('Content-Type: application/json');

$currentPracticeId = requireValidPracticeContext();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => t('errors.generic')]);
    exit;
}
requireCsrfToken();

if (!isFeatureEnabled('SHOW_AI_CHAT')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => t('errors.generic')]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$usageId = isset($input['usage_id']) ? (int)$input['usage_id'] : 0;
$value = isset($input['value']) ? (string)$input['value'] : '';

$userId = (int)($_SESSION['db_user_id'] ?? 0);
if ($usageId <= 0 || $userId <= 0 || !in_array($value, ['up', 'down', 'none'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => t('errors.generic')]);
    exit;
}

$ok = recordAskDentatrakFeedback($usageId, $userId, (int)$currentPracticeId, $value);
echo json_encode(['success' => $ok]);
