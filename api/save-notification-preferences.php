<?php
/**
 * Save notification email preferences for the authenticated user.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

// CSRF protection
$submittedToken = '';
if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $submittedToken = trim($_SERVER['HTTP_X_CSRF_TOKEN']);
} elseif (!empty($input['csrf_token'])) {
    $submittedToken = trim($input['csrf_token']);
}

if (!validateCsrfToken($submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token']);
    exit;
}

$supported = array_merge(['all'], getSupportedEmailEventTypes());
$submitted = $input['preferences'] ?? null;

if (!is_array($submitted) || empty($submitted)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'preferences array is required']);
    exit;
}

if (count($submitted) > count($supported)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Too many preference entries']);
    exit;
}

$seen = [];
$normalized = [];
foreach ($submitted as $entry) {
    if (!is_array($entry) || !isset($entry['event_type']) || !isset($entry['enabled'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Each preference must have event_type and enabled']);
        exit;
    }

    $eventType = trim($entry['event_type']);
    $enabled = filter_var($entry['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($enabled === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'enabled must be a boolean']);
        exit;
    }

    if (!in_array($eventType, $supported, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown event type: ' . $eventType]);
        exit;
    }

    if (isset($seen[$eventType])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Duplicate event type: ' . $eventType]);
        exit;
    }
    $seen[$eventType] = true;

    // Only persist non-default values (enabled means no row needed)
    if ($enabled === false) {
        $normalized[$eventType] = 0;
    }
    // enabled=true means default-on; do not create a row
}

try {
    $pdo->beginTransaction();

    // Remove all existing email preferences for this user/practice
    $delStmt = $pdo->prepare("
        DELETE FROM user_notification_preferences
        WHERE user_id = :user_id
          AND practice_id = :practice_id
          AND channel = 'email'
    ");
    $delStmt->execute(['user_id' => $userId, 'practice_id' => $practiceId]);

    // Insert only disabled (non-default) preferences
    if (!empty($normalized)) {
        $insStmt = $pdo->prepare("
            INSERT INTO user_notification_preferences
                (user_id, practice_id, event_type, channel, enabled)
            VALUES
                (:user_id, :practice_id, :event_type, 'email', :enabled)
        ");
        foreach ($normalized as $eventType => $enabled) {
            $insStmt->execute([
                'user_id' => $userId,
                'practice_id' => $practiceId,
                'event_type' => $eventType,
                'enabled' => $enabled,
            ]);
        }
    }

    $pdo->commit();

    $preferences = getEffectiveEmailPreferences($pdo, $userId, $practiceId);
    $all = null;
    $events = [];
    foreach ($preferences as $p) {
        if ($p['event_type'] === 'all') {
            $all = $p;
        } else {
            $events[] = $p;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Preferences saved',
        'all' => $all,
        'preferences' => $events,
        'csrf_token' => generateCsrfToken(),
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[save-notification-preferences] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save preferences']);
}
