<?php
/**
 * Announcement store - shared persistence and visibility logic.
 *
 * Pure functions only; no session/auth handling here so the same code can
 * be exercised by api/announcements.php (users), api/announcement-admin.php
 * (platform admins), and CLI tests.
 *
 * Tables (migrations/2026_11_25_announcements.php):
 *   announcements            - admin-authored notices, UTC display window
 *   announcement_dismissals  - per-user durable dismissals
 */

const ANN_TYPES = ['info', 'warning', 'critical', 'success'];
const ANN_AUDIENCES = ['all', 'fabg'];
const ANN_VISIBLE_TO = ['all', 'admin'];
const ANN_TZ_FALLBACK = 'America/New_York';

/** Whether the announcements schema is installed. */
function announcementsSchemaReady(PDO $pdo): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $check = $pdo->query("SHOW TABLES LIKE 'announcements'");
            $ready = $check && $check->rowCount() > 0;
            if ($ready) {
                $check2 = $pdo->query("SHOW TABLES LIKE 'announcement_dismissals'");
                $ready = $check2 && $check2->rowCount() > 0;
                if ($ready) {
                    $check3 = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'visible_to'");
                    $ready = $check3 && $check3->rowCount() > 0;
                }
            }
        } catch (PDOException $e) {
            error_log('[announcements] schema check failed: ' . $e->getMessage());
            $ready = false;
        }
    }
    return $ready;
}

/** Compute the lifecycle status shown in the admin table. */
function announcementStatus(array $a, DateTimeImmutable $nowUtc): string {
    if (!(int)$a['enabled']) {
        return 'disabled';
    }
    if (!(int)$a['published']) {
        return 'draft';
    }
    $start = new DateTimeImmutable($a['display_start_utc'], new DateTimeZone('UTC'));
    $end   = new DateTimeImmutable($a['display_end_utc'], new DateTimeZone('UTC'));
    if ($nowUtc < $start) {
        return 'scheduled';
    }
    if ($nowUtc > $end) {
        return 'expired';
    }
    return 'active';
}

/**
 * Whether a practice context qualifies for the 'fabg' audience.
 * Uses the authoritative practices.fabg_pilot flag (the same flag the
 * admin settings card and FABG billing eligibility use); column-guarded
 * so a missing flag column degrades to "not FABG" instead of an error.
 */
function announcementsPracticeIsFabg(PDO $pdo, int $practiceId): bool {
    static $columnExists = null;
    if ($practiceId <= 0) {
        return false;
    }
    try {
        if ($columnExists === null) {
            $check = $pdo->query("SHOW COLUMNS FROM practices LIKE 'fabg_pilot'");
            $columnExists = $check && $check->rowCount() > 0;
        }
        if (!$columnExists) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT fabg_pilot FROM practices WHERE id = ? LIMIT 1");
        $stmt->execute([$practiceId]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        error_log('[announcements] fabg eligibility check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch visible announcements for a user + practice.
 *
 * Returns published + enabled announcements inside their UTC display
 * window whose audience matches (all users, or FABG when the current
 * practice is flagged fabg_pilot or the caller is a platform super user)
 * and whose role targeting matches (visible_to='admin' requires the
 * 'admin' role in practice_users for the current practice, or a platform
 * super user), excluding announcements the user has dismissed. Ordered
 * critical > warning > info > success, then by start time. Returns [] on
 * any failure.
 */
function getVisibleAnnouncements(PDO $pdo, int $userId, int $practiceId, bool $isSuperUser): array {
    if (!announcementsSchemaReady($pdo)) {
        return [];
    }
    try {
        $fabg = $isSuperUser || announcementsPracticeIsFabg($pdo, $practiceId);

        // Role targeting: 'admin' announcements require the administrator
        // role in the CURRENTLY SELECTED practice (practice_users.role),
        // evaluated per request so practice switching re-evaluates. Platform
        // super users bypass for preview, same as the FABG audience.
        $isPracticeAdmin = false;
        if ($practiceId > 0) {
            $roleStmt = $pdo->prepare(
                "SELECT role FROM practice_users WHERE user_id = ? AND practice_id = ? LIMIT 1"
            );
            $roleStmt->execute([$userId, $practiceId]);
            $isPracticeAdmin = $roleStmt->fetchColumn() === 'admin';
        }
        $canSeeAdminOnly = $isSuperUser || $isPracticeAdmin;

        $sql = "SELECT a.id, a.title, a.message, a.type, a.audience, a.visible_to,
                       a.display_start_utc, a.display_end_utc, a.display_timezone,
                       a.published, a.enabled
                FROM announcements a
                WHERE a.published = 1
                  AND a.enabled = 1
                  AND a.display_start_utc <= UTC_TIMESTAMP()
                  AND a.display_end_utc >= UTC_TIMESTAMP()
                  AND (a.audience = 'all'"
                . ($fabg ? " OR a.audience = 'fabg'" : "")
                . ")"
                . ($canSeeAdminOnly ? "" : " AND a.visible_to = 'all'")
                . "
                  AND NOT EXISTS (
                      SELECT 1 FROM announcement_dismissals d
                      WHERE d.announcement_id = a.id AND d.user_id = :uid
                  )
                ORDER BY FIELD(a.type, 'critical','warning','info','success'),
                         a.display_start_utc ASC, a.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[announcements] retrieval failed: ' . $e->getMessage());
        return [];
    }
}

/** Persist a dismissal for (announcement_id, user_id). Idempotent. */
function dismissAnnouncement(PDO $pdo, int $announcementId, int $userId): bool {
    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id) VALUES (?, ?)"
        );
        $stmt->execute([$announcementId, $userId]);
        return true;
    } catch (PDOException $e) {
        error_log('[announcements] dismiss failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Validate + normalize the schedule fields.
 * Accepts local 'Y-m-d\TH:i' (datetime-local) strings plus an IANA timezone
 * name; returns UTC-normalized values or ['error' => message].
 */
function parseAnnouncementWindow(array $input): array {
    $startLocal = trim((string)($input['display_start'] ?? ''));
    $endLocal   = trim((string)($input['display_end'] ?? ''));
    $tzName     = trim((string)($input['display_timezone'] ?? ''));

    if ($startLocal === '' || $endLocal === '') {
        return ['error' => 'Display start and end are required'];
    }
    if (!in_array($tzName, DateTimeZone::listIdentifiers(), true)) {
        $tzName = ANN_TZ_FALLBACK;
    }

    try {
        $tz = new DateTimeZone($tzName);
        $start = new DateTimeImmutable($startLocal, $tz);
        $end   = new DateTimeImmutable($endLocal, $tz);
    } catch (Exception $e) {
        return ['error' => 'Invalid display start or end date/time'];
    }

    // datetime-local carries minute precision; normalize both to the minute.
    $start = $start->setTime((int)$start->format('H'), (int)$start->format('i'), 0);
    $end   = $end->setTime((int)$end->format('H'), (int)$end->format('i'), 0);

    if ($end <= $start) {
        return ['error' => 'Display end must be after display start'];
    }

    return [
        'start_utc' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'end_utc'   => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'timezone'  => $tzName,
    ];
}

/**
 * Scheduling conflict detection.
 *
 * At most ONE published+enabled announcement may occupy a display window at
 * any time, globally (audience does not matter). Draft and disabled rows
 * reserve nothing, and already-expired windows (display_end_utc <= now)
 * do not block new schedules. Adjacent windows are allowed: a new
 * announcement may start exactly when another ends.
 *
 * All comparisons use the stored UTC timestamps.
 */

/** Whether two UTC windows overlap. Adjacent (end == start) does NOT overlap. */
function announcementWindowsOverlap(string $startA, string $endA, string $startB, string $endB): bool {
    return $startA < $endB && $endA > $startB;
}

/**
 * Find a published+enabled, not-yet-expired announcement whose window
 * overlaps the given UTC window. Returns the conflicting row or null.
 */
function findAnnouncementConflict(PDO $pdo, ?int $excludeId, string $startUtc, string $endUtc): ?array {
    if (!announcementsSchemaReady($pdo)) {
        return null;
    }
    $sql = "SELECT id, title, message, type, audience,
                   display_start_utc, display_end_utc, display_timezone
            FROM announcements
            WHERE published = 1 AND enabled = 1
              AND display_end_utc > UTC_TIMESTAMP()
              AND display_start_utc < :new_end
              AND display_end_utc > :new_start";
    $params = [':new_end' => $endUtc, ':new_start' => $startUtc];
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= " AND id != :exid";
        $params[':exid'] = $excludeId;
    }
    $sql .= " ORDER BY FIELD(type, 'critical','warning','info','success'), display_start_utc ASC, id ASC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Friendly label for a stored IANA timezone (falls back to the raw name). */
function announcementTimezoneLabel(string $tz): string {
    static $labels = [
        'America/New_York'    => 'Eastern Time',
        'America/Chicago'     => 'Central Time',
        'America/Denver'      => 'Mountain Time',
        'America/Phoenix'     => 'Arizona Time',
        'America/Los_Angeles' => 'Pacific Time',
        'America/Anchorage'   => 'Alaska Time',
        'Pacific/Honolulu'    => 'Hawaii Time',
        'UTC'                 => 'UTC',
    ];
    return $labels[$tz] ?? $tz;
}

/**
 * Format an announcement's display window in its own stored timezone for
 * admin-facing messages, e.g. "December 3 at 8:00 PM through December 4 at
 * 2:00 AM Eastern Time".
 */
function formatAnnouncementWindowForMessage(array $a): string {
    $tzName = (string)($a['display_timezone'] ?? 'UTC');
    $tz = in_array($tzName, DateTimeZone::listIdentifiers(), true)
        ? new DateTimeZone($tzName) : new DateTimeZone('UTC');
    $utc = new DateTimeZone('UTC');
    $fmt = function (string $utcStr) use ($tz, $utc): string {
        $d = (new DateTimeImmutable($utcStr, $utc))->setTimezone($tz);
        return $d->format('F j \a\t g:i A');
    };
    $label = announcementTimezoneLabel($tzName);
    return $fmt((string)$a['display_start_utc']) . ' through ' . $fmt((string)$a['display_end_utc']) . ' ' . $label;
}

/**
 * Build the administrator-facing overlap error for a conflicting row.
 * Includes the conflicting title and its window in its own timezone.
 */
function announcementConflictMessage(array $conflict): string {
    $title = trim((string)($conflict['title'] ?? ''));
    if ($title === '') {
        $title = '(untitled)';
    }
    return "This announcement overlaps with '" . $title . "', which is scheduled from "
        . formatAnnouncementWindowForMessage($conflict) . ". Please choose a different display period.";
}

/**
 * Validate + normalize content fields.
 * Returns the field array, or a string 'error:<message>'.
 * Content is stored as plain text; all rendering escapes it.
 */
function parseAnnouncementFields(array $input) {
    $title    = trim((string)($input['title'] ?? ''));
    $message  = trim((string)($input['message'] ?? ''));
    $type     = (string)($input['type'] ?? 'info');
    $audience = (string)($input['audience'] ?? 'all');
    $visibleTo = (string)($input['visible_to'] ?? 'all');

    if ($message === '') {
        return 'error:Message is required';
    }
    if (mb_strlen($message) > 4000) {
        return 'error:Message is too long (4000 characters max)';
    }
    if (mb_strlen($title) > 200) {
        return 'error:Title is too long (200 characters max)';
    }
    if (!in_array($type, ANN_TYPES, true)) {
        return 'error:Invalid announcement type';
    }
    if (!in_array($audience, ANN_AUDIENCES, true)) {
        return 'error:Invalid audience';
    }
    if (!in_array($visibleTo, ANN_VISIBLE_TO, true)) {
        return 'error:Invalid visibility target';
    }

    return [
        'title'      => $title === '' ? null : $title,
        'message'    => $message,
        'type'       => $type,
        'audience'   => $audience,
        'visible_to' => $visibleTo,
    ];
}
