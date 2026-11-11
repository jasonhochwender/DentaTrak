<?php
/**
 * Lab Insights recommendation lifecycle endpoint.
 *
 * POST {action:'set_state', rec_key, state, snapshot?}
 * States: new | reviewed | dismissed (resolved is system-managed only -
 * deterministic recommendations resolve automatically when the underlying
 * condition verifiably disappears).
 *
 * Shares the ai_recommendation_states store with Practice Insights but
 * only accepts 'lab|'-prefixed keys so this endpoint can never mutate
 * Practice Insights recommendation states. Same permission as viewing
 * Lab Insights (valid practice context + can_view_analytics + CSRF).
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ai-recommendation-store.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}
requireCsrfToken();

$currentPracticeId = requireValidPracticeContext();

if (!canViewAnalytics($currentPracticeId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'access_denied']);
    exit;
}

$body = $_POST;
if (empty($body)) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) { $body = $decoded; }
}

$recKey = substr((string)($body['rec_key'] ?? ''), 0, 160);
$newState = (string)($body['state'] ?? '');
$snapshot = isset($body['snapshot']) && is_array($body['snapshot']) ? $body['snapshot'] : null;

if ($recKey === '' || strpos($recKey, 'lab|') !== 0
    || !in_array($newState, ['new', 'reviewed', 'dismissed'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_state_request']);
    exit;
}

$userId = (int)($_SESSION['db_user_id'] ?? 0);
// No signature is stored here: the next get-lab-insights merge stamps the
// current deterministic signature, which is what the reopen/resolve rules
// compare against. NULL means "unknown", never "changed".
aiRecSetState($pdo, (int)$currentPracticeId, $recKey, $newState, $userId, $snapshot, null);
echo json_encode(['success' => true]);
