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
 * and that the user has not dismissed. Ordered critical > warning >
 * info > success, then by start time. Returns [] on any failure.
 */
function getVisibleAnnouncements(PDO $pdo, int $userId, int $practiceId, bool $isSuperUser): array {
    if (!announcementsSchemaReady($pdo)) {
        return [];
    }
    try {
        $fabg = $isSuperUser || announcementsPracticeIsFabg($pdo, $practiceId);

        $sql = "SELECT a.id, a.title, a.message, a.type, a.audience,
                       a.display_start_utc, a.display_end_utc, a.display_timezone,
                       a.published, a.enabled
                FROM announcements a
                WHERE a.published = 1
                  AND a.enabled = 1
                  AND a.display_start_utc <= UTC_TIMESTAMP()
                  AND a.display_end_utc >= UTC_TIMESTAMP()
                  AND (a.audience = 'all'"
                . ($fabg ? " OR a.audience = 'fabg'" : "")
                . ")
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
 * Validate + normalize content fields.
 * Returns the field array, or a string 'error:<message>'.
 * Content is stored as plain text; all rendering escapes it.
 */
function parseAnnouncementFields(array $input) {
    $title    = trim((string)($input['title'] ?? ''));
    $message  = trim((string)($input['message'] ?? ''));
    $type     = (string)($input['type'] ?? 'info');
    $audience = (string)($input['audience'] ?? 'all');

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

    return [
        'title'    => $title === '' ? null : $title,
        'message'  => $message,
        'type'     => $type,
        'audience' => $audience,
    ];
}
