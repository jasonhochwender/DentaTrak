<?php
/**
 * Notification Destination API
 *
 * Secure deep-link destination for in-app (and future email) notifications.
 *
 * Authorization rules:
 *   1. User must be authenticated.
 *   2. The notification must belong to the authenticated user.
 *   3. The user must still be an active member of the notification's practice.
 *   4. If the current practice differs, request a safe practice switch.
 *   5. The case is resolved from the user_notifications row and still passes canUserAccessCase().
 *
 * No patient information is returned in the URL.  On success, the caller opens
 * the case via the existing case modal or archived-case flow.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';

header('Content-Type: application/json');

/**
 * Resolve the destination for a notification.
 *
 * @param int $notificationId
 * @return array
 */
function resolveNotificationDestination($notificationId) {
    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return ['success' => false, 'code' => 'error', 'message' => 'Database not available'];
    }

    if (empty($_SESSION['db_user_id'])) {
        return ['success' => false, 'code' => 'logged_out', 'message' => 'Authentication required'];
    }

    $userId = (int)$_SESSION['db_user_id'];
    $currentPracticeId = (int)($_SESSION['current_practice_id'] ?? 0);

    // 1. Verify notification ownership and resolve case_id from the row
    $stmt = $pdo->prepare("
        SELECT id, user_id, practice_id, case_id
        FROM user_notifications
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => (int)$notificationId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$notification || (int)$notification['user_id'] !== $userId) {
        return ['success' => false, 'code' => 'unauthorized', 'message' => 'Notification not found or does not belong to user'];
    }

    $caseId = (string)($notification['case_id'] ?? '');

    if (empty($caseId)) {
        return ['success' => false, 'code' => 'unavailable', 'message' => 'This case is no longer available to you.'];
    }

    $notificationPracticeId = (int)$notification['practice_id'];

    // 2. Verify active membership in the notification's practice
    $membershipStmt = $pdo->prepare("
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
    $membershipStmt->execute([
        'user_id' => $userId,
        'practice_id' => $notificationPracticeId,
    ]);
    if (!$membershipStmt->fetchColumn()) {
        return ['success' => false, 'code' => 'unavailable', 'message' => 'This case is no longer available to you.'];
    }

    // 3. If the user is currently in a different practice, require a safe switch.
    if ($currentPracticeId !== $notificationPracticeId) {
        return [
            'success' => false,
            'code' => 'practice_mismatch',
            'message' => 'Switch practice to continue',
            'practice_id' => $notificationPracticeId,
            'case_id' => $caseId,
            'notification_id' => (int)$notificationId,
        ];
    }

    // 4. Verify the case still exists and the user can still access it.
    $caseStmt = $pdo->prepare("
        SELECT *
        FROM cases_cache
        WHERE case_id = :case_id AND practice_id = :practice_id
        LIMIT 1
    ");
    $caseStmt->execute([
        'case_id' => $caseId,
        'practice_id' => $notificationPracticeId,
    ]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        return ['success' => false, 'code' => 'unavailable', 'message' => 'This case is no longer available to you.'];
    }

    if (!canUserAccessCase($case, $notificationPracticeId)) {
        return ['success' => false, 'code' => 'unavailable', 'message' => 'This case is no longer available to you.'];
    }

    $isArchived = !empty($case['archived']);

    return [
        'success' => true,
        'case_id' => $caseId,
        'notification_id' => (int)$notificationId,
        'is_archived' => $isArchived,
    ];
}

// HTTP entry point
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$notificationId = (int)($input['notification_id'] ?? 0);

if (!$notificationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'bad_request', 'message' => 'notification_id is required']);
    exit;
}

// Reject unexpected case_id input that was never released as a public format
if (isset($input['case_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'bad_request', 'message' => 'case_id is not supported in the destination request']);
    exit;
}

echo json_encode(resolveNotificationDestination($notificationId));
