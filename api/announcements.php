<?php
/**
 * Announcements API - user-facing retrieval and dismissal.
 *
 * GET  -> returns the announcements visible to the authenticated user in
 *         the CURRENT practice context: published + enabled + inside the
 *         UTC display window + audience match + not dismissed by this user.
 *         Ordered critical > warning > info > success.
 * POST action=dismiss -> persists a per-user dismissal for one announcement.
 *
 * Audience 'fabg' resolves through practices.fabg_pilot on the user's
 * currently selected practice, so multi-practice users see FABG notices only
 * while a FABG practice is selected. Platform super users see every
 * audience so they can verify targeting without changing memberships.
 *
 * Retrieval failures return an empty list rather than breaking the app.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/announcement-store.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('auth.errors.not_authenticated')]);
    exit;
}

$userId = (int)$_SESSION['db_user_id'];
$practiceId = isset($_SESSION['current_practice_id']) ? (int)$_SESSION['current_practice_id'] : 0;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    global $appConfig;
    require_once __DIR__ . '/dev-tools-access.php';
    $isSuperUser = isSuperUser($appConfig ?? [], $_SESSION['user_email'] ?? '');

    $rows = getVisibleAnnouncements($pdo, $userId, $practiceId, $isSuperUser);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'title'      => $r['title'],
            'message'    => $r['message'],
            'type'       => $r['type'],
            'audience'   => $r['audience'],
            'starts_at'  => $r['display_start_utc'],
            'ends_at'    => $r['display_end_utc'],
            'timezone'   => $r['display_timezone'],
        ];
    }

    echo json_encode(['success' => true, 'announcements' => $out]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? ($_GET['action'] ?? '');

    if ($action !== 'dismiss') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
    }

    requireCsrfToken();

    $announcementId = (int)($input['announcement_id'] ?? 0);
    if ($announcementId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'announcement_id required']);
        exit;
    }

    try {
        if (!announcementsSchemaReady($pdo)) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Announcements are not available yet']);
            exit;
        }

        // Only allow dismissing announcements that actually exist.
        $exists = $pdo->prepare("SELECT 1 FROM announcements WHERE id = ? LIMIT 1");
        $exists->execute([$announcementId]);
        if (!$exists->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            exit;
        }

        if (!dismissAnnouncement($pdo, $announcementId, $userId)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to save dismissal']);
            exit;
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        error_log('[announcements] dismiss failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to save dismissal']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
