<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/case-view-preferences-store.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
$practiceId = requireValidPracticeContext();
$userId = (int)$_SESSION['db_user_id'];
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false]);
    exit;
}
try {
    if ($method === 'POST') {
        requireCsrfToken();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || ($input['practiceId'] ?? null) !== $practiceId || ($input['userId'] ?? null) !== $userId) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Practice or user changed. Reload before saving preferences.']);
            exit;
        }
        $preferences = normalizeCaseViewPreferences($input['preferences'] ?? null, true);
        $stmt = $pdo->prepare('UPDATE practice_users SET case_view_preferences = ? WHERE user_id = ? AND practice_id = ?');
        $stmt->execute([json_encode($preferences), $userId, $practiceId]);
    } else {
        $preferences = loadCaseViewPreferences($pdo, $userId, $practiceId);
    }
    echo json_encode(['success' => true, 'userId' => $userId, 'practiceId' => $practiceId, 'preferences' => $preferences]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[case-view-preferences] Storage unavailable');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Unable to save or load Filter & Sort preferences.']);
}
