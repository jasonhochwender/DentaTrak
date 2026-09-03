<?php
/**
 * Get notification email preferences for the authenticated user.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notification-preferences.php';

header('Content-Type: application/json');

if (!isFeatureEnabled('SHOW_NOTIFICATIONS')) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (empty($_SESSION['current_practice_id']) || empty($pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Practice context required']);
    exit;
}

$userId = (int)$_SESSION['db_user_id'];
$practiceId = (int)$_SESSION['current_practice_id'];

// Verify active membership
$memberStmt = $pdo->prepare("
    SELECT 1
    FROM practice_users pu
    JOIN users u ON u.id = pu.user_id
    JOIN practices p ON p.id = pu.practice_id
    WHERE pu.user_id = :user_id
      AND pu.practice_id = :practice_id
      AND u.is_active = 1
      AND (p.is_active = 1 OR p.is_active IS NULL)
    LIMIT 1
");
$memberStmt->execute(['user_id' => $userId, 'practice_id' => $practiceId]);
if (!$memberStmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Practice membership required']);
    exit;
}

$preferences = getEffectiveEmailPreferences($pdo, $userId, $practiceId);

$all = null;
$events = [];
foreach ($preferences as $p) {
    if ($p['event_type'] === 'all') {
        $all = $p;
    } else {
        // Hide the mention email preference when Comments is disabled.
        if ($p['event_type'] === 'mention' && !isFeatureEnabled('SHOW_COMMENTS')) {
            continue;
        }
        $events[] = $p;
    }
}

echo json_encode([
    'success' => true,
    'all' => $all,
    'preferences' => $events,
    'csrf_token' => generateCsrfToken(),
]);
