<?php
/**
 * Announcement Administration API - Platform Admin only.
 *
 * Authorization: super users, or the local development environment - the
 * same gate as api/admin-practices.php. Every state-changing POST requires
 * a valid CSRF token and is written to admin_audit_log.
 *
 * GET  ?action=list            -> all announcements with computed status
 * GET  ?action=get&id=N        -> one announcement (edit form)
 * POST action=create|update|duplicate|delete|set_enabled|set_published
 *
 * Times are submitted as local datetime-local strings plus an IANA timezone
 * and stored normalized to UTC (display_start_utc / display_end_utc). The
 * entered timezone is kept in display_timezone so the admin UI can render
 * the window in the same zone the admin typed.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/announcement-store.php';

header('Content-Type: application/json');
ob_start();
ini_set('display_errors', '0');

if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
if (!isSuperUser($appConfig, $userEmail) && !$isDev) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super user privileges required.']);
    exit;
}

/** Audit helper mirroring logAdminAction() in api/admin-practices.php. */
function logAnnouncementAdminAction(string $action, array $details = []): void {
    global $pdo;
    static $schemaEnsured = false;
    try {
        if (!$schemaEnsured && !$pdo->inTransaction()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_user_id BIGINT UNSIGNED NOT NULL,
                admin_email VARCHAR(255),
                action VARCHAR(100) NOT NULL,
                details_json TEXT,
                ip_address VARCHAR(45),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin_user_id (admin_user_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $schemaEnsured = true;
        }
        $stmt = $pdo->prepare("
            INSERT INTO admin_audit_log (admin_user_id, admin_email, action, details_json, ip_address)
            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['db_user_id'],
            $_SESSION['user_email'] ?? '',
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (PDOException $e) {
        error_log('[announcement-admin] audit log failed: ' . $e->getMessage());
    }
}

function fetchAnnouncement(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Emit a 409 schedule-conflict response including the conflicting row. */
function scheduleConflictResponse(array $conflict): void {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'code'    => 'schedule_conflict',
        'message' => announcementConflictMessage($conflict),
        'conflict' => [
            'id'         => (int)$conflict['id'],
            'title'      => $conflict['title'],
            'start_utc'  => $conflict['display_start_utc'],
            'end_utc'    => $conflict['display_end_utc'],
            'timezone'   => $conflict['display_timezone'],
        ],
    ]);
}

/**
 * Enforce the global single-announcement schedule rule: a published+enabled
 * row may not overlap another published+enabled, not-yet-expired row.
 * Returns true when the change may proceed; otherwise emits a 409 and
 * returns false.
 */
function checkScheduleConflict(PDO $pdo, ?int $excludeId, string $startUtc, string $endUtc, bool $published, bool $enabled): bool {
    if (!$published || !$enabled) {
        return true;
    }
    // A window that has already fully elapsed reserves nothing.
    if ($endUtc <= gmdate('Y-m-d H:i:s')) {
        return true;
    }
    $conflict = findAnnouncementConflict($pdo, $excludeId, $startUtc, $endUtc);
    if ($conflict === null) {
        return true;
    }
    scheduleConflictResponse($conflict);
    return false;
}

function handleGetRequest(string $action): void {
    global $pdo;
    if (!announcementsSchemaReady($pdo)) {
        echo json_encode(['success' => true, 'announcements' => [], 'schema_missing' => true]);
        return;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? fetchAnnouncement($pdo, $id) : null;
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            return;
        }
        echo json_encode(['success' => true, 'announcement' => $row]);
        return;
    }

    // Default: list
    $stmt = $pdo->query("SELECT * FROM announcements ORDER BY updated_at DESC, id DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($rows as &$r) {
        $r['status'] = announcementStatus($r, $now);
    }
    unset($r);
    echo json_encode(['success' => true, 'announcements' => $rows]);
}

function handlePostRequest(string $action): void {
    global $pdo;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if (!validateCsrfToken($input['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token']);
        return;
    }

    if (!announcementsSchemaReady($pdo)) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Announcements schema is not installed yet']);
        return;
    }

    // Serialize all announcement writes so concurrent admin requests cannot
    // both pass the overlap check and create conflicting schedules.
    $lockStmt = $pdo->query("SELECT GET_LOCK('announcements_schedule_admin', 10)");
    $locked = $lockStmt !== false && (int)$lockStmt->fetchColumn() === 1;
    if (!$locked) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Announcement service is busy, please retry']);
        return;
    }
    try {
    switch ($action) {
        case 'create':
        case 'update':
            $fields = parseAnnouncementFields($input);
            if (is_string($fields)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => substr($fields, 6)]);
                return;
            }
            $window = parseAnnouncementWindow($input);
            if (isset($window['error'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $window['error']]);
                return;
            }

            $published = !empty($input['published']) ? 1 : 0;
            $enabled   = array_key_exists('enabled', $input) ? (!empty($input['enabled']) ? 1 : 0) : 1;

            if ($action === 'create') {
                // Creates have no self to exclude; updates re-validate below
                // with the row id so an announcement never conflicts with
                // itself when its schedule is unchanged.
                if (!checkScheduleConflict($pdo, null, $window['start_utc'], $window['end_utc'], (bool)$published, (bool)$enabled)) {
                    return;
                }
                $stmt = $pdo->prepare("INSERT INTO announcements
                    (title, message, type, audience, visible_to, display_start_utc, display_end_utc, display_timezone, published, enabled, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $fields['title'], $fields['message'], $fields['type'], $fields['audience'], $fields['visible_to'],
                    $window['start_utc'], $window['end_utc'], $window['timezone'],
                    $published, $enabled, $_SESSION['db_user_id'],
                ]);
                $id = (int)$pdo->lastInsertId();
                logAnnouncementAdminAction('announcement_created', [
                    'announcement_id' => $id, 'type' => $fields['type'], 'audience' => $fields['audience'],
                    'visible_to' => $fields['visible_to'],
                    'published' => $published, 'enabled' => $enabled,
                ]);
                echo json_encode(['success' => true, 'id' => $id]);
                return;
            }

            // update
            $id = (int)($input['id'] ?? 0);
            $existing = $id > 0 ? fetchAnnouncement($pdo, $id) : null;
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Announcement not found']);
                return;
            }
            // Preserve lifecycle flags unless the caller supplies them.
            $published = array_key_exists('published', $input) ? $published : (int)$existing['published'];
            $enabled   = array_key_exists('enabled', $input) ? $enabled : (int)$existing['enabled'];
            // Same for targeting: an update that omits visible_to must not
            // silently widen an admin-only announcement to all users.
            if (!array_key_exists('visible_to', $input)) {
                $fields['visible_to'] = $existing['visible_to'] ?? 'all';
            }
            if (!array_key_exists('audience', $input)) {
                $fields['audience'] = $existing['audience'] ?? 'all';
            }

            if (!checkScheduleConflict($pdo, $id, $window['start_utc'], $window['end_utc'], (bool)$published, (bool)$enabled)) {
                return;
            }

            $stmt = $pdo->prepare("UPDATE announcements SET
                title = ?, message = ?, type = ?, audience = ?, visible_to = ?,
                display_start_utc = ?, display_end_utc = ?, display_timezone = ?,
                published = ?, enabled = ?
                WHERE id = ?");
            $stmt->execute([
                $fields['title'], $fields['message'], $fields['type'], $fields['audience'], $fields['visible_to'],
                $window['start_utc'], $window['end_utc'], $window['timezone'],
                $published, $enabled, $id,
            ]);
            // Editing content/dates intentionally does NOT clear dismissals.
            logAnnouncementAdminAction('announcement_updated', [
                'announcement_id' => $id, 'type' => $fields['type'], 'audience' => $fields['audience'],
                'visible_to' => $fields['visible_to'],
            ]);
            echo json_encode(['success' => true, 'id' => $id]);
            return;

        case 'duplicate':
            $id = (int)($input['id'] ?? 0);
            $existing = $id > 0 ? fetchAnnouncement($pdo, $id) : null;
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Announcement not found']);
                return;
            }
            $copyTitle = $existing['title'];
            $copyTitle = $copyTitle === null ? 'Copy' : mb_substr($copyTitle, 0, 190) . ' (copy)';
            $stmt = $pdo->prepare("INSERT INTO announcements
                (title, message, type, audience, visible_to, display_start_utc, display_end_utc, display_timezone, published, enabled, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)");
            $stmt->execute([
                $copyTitle, $existing['message'], $existing['type'], $existing['audience'],
                $existing['visible_to'] ?? 'all',
                $existing['display_start_utc'], $existing['display_end_utc'], $existing['display_timezone'],
                $_SESSION['db_user_id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logAnnouncementAdminAction('announcement_duplicated', [
                'announcement_id' => $newId, 'source_id' => $id,
            ]);
            echo json_encode(['success' => true, 'id' => $newId]);
            return;

        case 'delete':
            $id = (int)($input['id'] ?? 0);
            $existing = $id > 0 ? fetchAnnouncement($pdo, $id) : null;
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Announcement not found']);
                return;
            }
            $pdo->prepare("DELETE FROM announcement_dismissals WHERE announcement_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
            logAnnouncementAdminAction('announcement_deleted', ['announcement_id' => $id]);
            echo json_encode(['success' => true]);
            return;

        case 'set_enabled':
        case 'set_published':
            $id = (int)($input['id'] ?? 0);
            $flag = !empty($input['value']) ? 1 : 0;
            $existing = $id > 0 ? fetchAnnouncement($pdo, $id) : null;
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Announcement not found']);
                return;
            }
            $col = $action === 'set_enabled' ? 'enabled' : 'published';
            // Re-validate the schedule whenever the resulting row is
            // published+enabled (covers re-enable and publish of drafts).
            $newEnabled   = $action === 'set_enabled' ? $flag : (int)$existing['enabled'];
            $newPublished = $action === 'set_published' ? $flag : (int)$existing['published'];
            if (!checkScheduleConflict($pdo, $id, (string)$existing['display_start_utc'], (string)$existing['display_end_utc'], (bool)$newPublished, (bool)$newEnabled)) {
                return;
            }
            $pdo->prepare("UPDATE announcements SET {$col} = ? WHERE id = ?")->execute([$flag, $id]);
            logAnnouncementAdminAction('announcement_' . ($action === 'set_enabled' ? 'enabled_changed' : 'published_changed'), [
                'announcement_id' => $id, 'value' => $flag,
            ]);
            echo json_encode(['success' => true]);
            return;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('announcements_schedule_admin')"); } catch (Throwable $e) {}
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        handleGetRequest($action);
    } elseif ($method === 'POST') {
        handlePostRequest($action);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    error_log('[announcement-admin] Unexpected error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Unexpected server error']);
}
while (ob_get_level() > 0) { ob_end_flush(); }
