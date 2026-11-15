<?php
/**
 * Platform announcements tests.
 *
 * Covers api/announcement-store.php logic against the local development
 * database (development environment, MySQL on port 3308 per AGENTS.md):
 *
 *  - timezone-aware window parsing -> UTC storage values
 *  - field validation (required message, type/audience enums, lengths)
 *  - lifecycle status computation (draft/scheduled/active/expired/disabled)
 *  - visibility: all-users vs FABG audience via practices.fabg_pilot,
 *    practice switching, super-user preview, enabled/published/window gates
 *  - per-user dismissal persistence, isolation between users, survival
 *    across "edits" (dismissal keyed by announcement id), and that new
 *    announcements still appear after prior dismissals
 *  - priority ordering (critical > warning > info > success)
 *
 * Creates throwaway 'announce-test-*' fixtures and removes them after.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/announcement-store.php';

global $pdo, $appConfig;

$pass = 0;
$fail = 0;

function assertTrue(string $message, $condition): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$message}\n"; }
    else { $fail++; echo "FAIL: {$message}\n"; }
}

function assertEquals(string $message, $expected, $actual): void {
    global $pass, $fail;
    if ($expected === $actual) { $pass++; echo "PASS: {$message}\n"; }
    else {
        $fail++;
        echo "FAIL: {$message} (expected " . var_export($expected, true) .
             ", got " . var_export($actual, true) . ")\n";
    }
}

$env = $appConfig['current_environment'] ?? 'production';
if (!isset($pdo) || !($pdo instanceof PDO) || $env === 'production') {
    echo "SKIP: requires the local development database\n";
    exit(1);
}

if (!announcementsSchemaReady($pdo)) {
    echo "SKIP: announcements schema missing - run migrations/2026_11_25_announcements.php\n";
    exit(1);
}

$createdUserIds = [];
$createdPracticeIds = [];
$createdAnnouncementIds = [];

$mkUser = function (string $email) use ($pdo, &$createdUserIds): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'Announce', 'Test', 'user', 1, 1, NOW())")
        ->execute(['e' => $email]);
    $id = (int)$pdo->lastInsertId();
    $createdUserIds[] = $id;
    return $id;
};

$mkPractice = function (string $name, int $fabg, int $createdBy) use ($pdo, &$createdPracticeIds): int {
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, fabg_pilot, created_at)
                   VALUES (UUID(), :n, :cb, 1, :f, NOW())")
        ->execute(['n' => $name, 'cb' => $createdBy, 'f' => $fabg]);
    $id = (int)$pdo->lastInsertId();
    $createdPracticeIds[] = $id;
    return $id;
};

$mkAnnouncement = function (array $over) use ($pdo, &$createdAnnouncementIds): int {
    $defaults = [
        'title' => null, 'message' => 'test', 'type' => 'info', 'audience' => 'all',
        'display_start_utc' => gmdate('Y-m-d H:i:s', time() - 3600),
        'display_end_utc'   => gmdate('Y-m-d H:i:s', time() + 86400),
        'display_timezone'  => 'UTC', 'published' => 1, 'enabled' => 1,
    ];
    $a = array_merge($defaults, $over);
    $pdo->prepare("INSERT INTO announcements
        (title, message, type, audience, display_start_utc, display_end_utc, display_timezone, published, enabled, created_by)
        VALUES (:t, :m, :ty, :au, :s, :e, :tz, :p, :en, NULL)")
        ->execute([
            't' => $a['title'], 'm' => $a['message'], 'ty' => $a['type'], 'au' => $a['audience'],
            's' => $a['display_start_utc'], 'e' => $a['display_end_utc'],
            'tz' => $a['display_timezone'], 'p' => $a['published'], 'en' => $a['enabled'],
        ]);
    $id = (int)$pdo->lastInsertId();
    $createdAnnouncementIds[] = $id;
    return $id;
};

$visibleIds = function (int $userId, int $practiceId, bool $super = false) use ($pdo): array {
    return array_map('intval', array_column(
        getVisibleAnnouncements($pdo, $userId, $practiceId, $super), 'id'));
};

try {
    // ---------------------------------------------------------------
    echo "--- window parsing / timezone handling ---\n";

    // 2026-01-15 09:00 Eastern (EST, UTC-5) -> 14:00 UTC
    $w = parseAnnouncementWindow([
        'display_start' => '2026-01-15T09:00',
        'display_end'   => '2026-01-15T17:00',
        'display_timezone' => 'America/New_York',
    ]);
    assertTrue('window parses without error', !isset($w['error']));
    assertEquals('EST start converts to UTC', '2026-01-15 14:00:00', $w['start_utc']);
    assertEquals('EST end converts to UTC', '2026-01-15 22:00:00', $w['end_utc']);
    assertEquals('timezone preserved for display', 'America/New_York', $w['timezone']);

    // DST: 2026-07-15 is EDT (UTC-4)
    $w2 = parseAnnouncementWindow([
        'display_start' => '2026-07-15T09:00',
        'display_end'   => '2026-07-15T10:00',
        'display_timezone' => 'America/New_York',
    ]);
    assertEquals('EDT start converts to UTC', '2026-07-15 13:00:00', $w2['start_utc']);

    // Pacific: 2026-01-15 09:00 PST (UTC-8) -> 17:00 UTC
    $w3 = parseAnnouncementWindow([
        'display_start' => '2026-01-15T09:00',
        'display_end'   => '2026-01-15T10:00',
        'display_timezone' => 'America/Los_Angeles',
    ]);
    assertEquals('PST start converts to UTC', '2026-01-15 17:00:00', $w3['start_utc']);

    $bad = parseAnnouncementWindow([
        'display_start' => '2026-01-15T10:00',
        'display_end'   => '2026-01-15T09:00',
        'display_timezone' => 'UTC',
    ]);
    assertTrue('end before start rejected', isset($bad['error']));

    $missing = parseAnnouncementWindow(['display_start' => '2026-01-15T09:00']);
    assertTrue('missing end rejected', isset($missing['error']));

    $badtz = parseAnnouncementWindow([
        'display_start' => '2026-01-15T09:00',
        'display_end'   => '2026-01-15T10:00',
        'display_timezone' => 'Not/AZone',
    ]);
    assertTrue('invalid timezone falls back, still parses', !isset($badtz['error']) && $badtz['timezone'] === 'America/New_York');

    // ---------------------------------------------------------------
    echo "--- field validation ---\n";

    $f = parseAnnouncementFields(['message' => 'Hello', 'type' => 'warning', 'audience' => 'fabg']);
    assertTrue('valid fields parse', is_array($f) && $f['type'] === 'warning' && $f['audience'] === 'fabg');

    assertTrue('empty message rejected', is_string(parseAnnouncementFields(['message' => '  '])));
    assertTrue('bad type rejected', is_string(parseAnnouncementFields(['message' => 'x', 'type' => 'mega'])));
    assertTrue('bad audience rejected', is_string(parseAnnouncementFields(['message' => 'x', 'audience' => 'everyone'])));
    assertTrue('long title rejected', is_string(parseAnnouncementFields(['message' => 'x', 'title' => str_repeat('a', 201)])));
    assertTrue('empty title -> null', parseAnnouncementFields(['message' => 'x', 'title' => '  '])['title'] === null);

    // ---------------------------------------------------------------
    echo "--- lifecycle status ---\n";

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $past = gmdate('Y-m-d H:i:s', time() - 7200);
    $future = gmdate('Y-m-d H:i:s', time() + 7200);
    $later = gmdate('Y-m-d H:i:s', time() + 86400);
    $earlier = gmdate('Y-m-d H:i:s', time() - 86400);

    assertEquals('disabled wins over everything', 'disabled',
        announcementStatus(['enabled' => 0, 'published' => 0, 'display_start_utc' => $past, 'display_end_utc' => $future], $now));
    assertEquals('unpublished = draft', 'draft',
        announcementStatus(['enabled' => 1, 'published' => 0, 'display_start_utc' => $past, 'display_end_utc' => $future], $now));
    assertEquals('future window = scheduled', 'scheduled',
        announcementStatus(['enabled' => 1, 'published' => 1, 'display_start_utc' => $future, 'display_end_utc' => $later], $now));
    assertEquals('past window = expired', 'expired',
        announcementStatus(['enabled' => 1, 'published' => 1, 'display_start_utc' => $earlier, 'display_end_utc' => $past], $now));
    assertEquals('current window = active', 'active',
        announcementStatus(['enabled' => 1, 'published' => 1, 'display_start_utc' => $past, 'display_end_utc' => $future], $now));

    // ---------------------------------------------------------------
    echo "--- visibility / audience targeting ---\n";

    $userA = $mkUser('announce-test-a@test.local');
    $userB = $mkUser('announce-test-b@test.local');
    $practiceNormal = $mkPractice('AnnounceTest Normal', 0, $userA);
    $practiceFabg   = $mkPractice('AnnounceTest FABG', 1, $userA);

    $aAll    = $mkAnnouncement(['message' => 'all-users notice', 'type' => 'info']);
    $aFabg   = $mkAnnouncement(['message' => 'fabg-only notice', 'type' => 'warning', 'audience' => 'fabg']);
    $aDraft  = $mkAnnouncement(['message' => 'draft notice', 'published' => 0]);
    $aDis    = $mkAnnouncement(['message' => 'disabled notice', 'enabled' => 0]);
    $aFuture = $mkAnnouncement(['message' => 'future notice', 'display_start_utc' => $later, 'display_end_utc' => gmdate('Y-m-d H:i:s', time() + 90000)]);
    $aPast   = $mkAnnouncement(['message' => 'expired notice', 'display_start_utc' => $earlier, 'display_end_utc' => $past]);

    // Non-FABG practice: only the all-users announcement.
    $vis = $visibleIds($userA, $practiceNormal);
    assertTrue('non-FABG practice sees all-users announcement', in_array($aAll, $vis, true));
    assertTrue('non-FABG practice does NOT see FABG announcement', !in_array($aFabg, $vis, true));
    assertTrue('draft hidden', !in_array($aDraft, $vis, true));
    assertTrue('disabled hidden', !in_array($aDis, $vis, true));
    assertTrue('future/scheduled hidden', !in_array($aFuture, $vis, true));
    assertTrue('expired hidden', !in_array($aPast, $vis, true));

    // FABG practice: sees both.
    $visFabg = $visibleIds($userA, $practiceFabg);
    assertTrue('FABG practice sees all-users announcement', in_array($aAll, $visFabg, true));
    assertTrue('FABG practice sees FABG announcement', in_array($aFabg, $visFabg, true));

    // Practice switching: same user, different current practice.
    $visSwitch = $visibleIds($userA, $practiceNormal);
    assertTrue('switching to non-FABG practice hides FABG announcement', !in_array($aFabg, $visSwitch, true));

    // Super user previews FABG targeting regardless of practice flag.
    $visSuper = $visibleIds($userA, $practiceNormal, true);
    assertTrue('super user sees FABG announcement for preview', in_array($aFabg, $visSuper, true));

    // ---------------------------------------------------------------
    echo "--- dismissal persistence ---\n";

    // userA dismisses the all-users announcement in the normal practice.
    assertTrue('dismiss returns true', dismissAnnouncement($pdo, $aAll, $userA));
    assertTrue('dismiss is idempotent', dismissAnnouncement($pdo, $aAll, $userA));

    $visAfter = $visibleIds($userA, $practiceNormal);
    assertTrue('dismissed announcement hidden for that user', !in_array($aAll, $visAfter, true));

    // Same user in FABG practice: dismissal is per user+announcement, not
    // per practice - still hidden.
    $visFabgAfter = $visibleIds($userA, $practiceFabg);
    assertTrue('dismissal follows the user across practices', !in_array($aAll, $visFabgAfter, true));
    assertTrue('other announcement still visible after one dismissal', in_array($aFabg, $visFabgAfter, true));

    // A different user is unaffected.
    $visB = $visibleIds($userB, $practiceNormal);
    assertTrue('other user still sees the announcement', in_array($aAll, $visB, true));

    // Editing the announcement (new message/dates) must not clear dismissals.
    $pdo->prepare("UPDATE announcements SET message = 'edited text', display_end_utc = ? WHERE id = ?")
        ->execute([gmdate('Y-m-d H:i:s', time() + 90000), $aAll]);
    $visEdited = $visibleIds($userA, $practiceNormal);
    assertTrue('dismissal survives announcement edits', !in_array($aAll, $visEdited, true));
    $visB2 = $visibleIds($userB, $practiceNormal);
    assertTrue('other user sees edited announcement', in_array($aAll, $visB2, true));

    // A brand-new announcement appears despite earlier dismissals.
    $aNew = $mkAnnouncement(['message' => 'brand new notice', 'type' => 'success']);
    $visNew = $visibleIds($userA, $practiceNormal);
    assertTrue('new announcement visible after prior dismissals', in_array($aNew, $visNew, true));

    // ---------------------------------------------------------------
    echo "--- ordering / multiple announcements ---\n";

    $aCrit = $mkAnnouncement(['message' => 'crit', 'type' => 'critical']);
    $aWarn = $mkAnnouncement(['message' => 'warn', 'type' => 'warning']);
    $aInfo = $mkAnnouncement(['message' => 'info', 'type' => 'info']);
    $aSucc = $mkAnnouncement(['message' => 'succ', 'type' => 'success']);

    $visOrd = getVisibleAnnouncements($pdo, $userB, $practiceFabg, false);
    $orderedTypes = array_column($visOrd, 'type');
    // Among this user's visible set, critical must precede warning precede
    // info precede success wherever they co-occur.
    $pos = function ($t) use ($orderedTypes) { return array_search($t, $orderedTypes, true); };
    assertTrue('critical sorts before warning', $pos('critical') < $pos('warning'));
    assertTrue('warning sorts before info', $pos('warning') < $pos('info'));
    assertTrue('info sorts before success', $pos('info') < $pos('success'));

    // ---------------------------------------------------------------
    echo "--- immediate disable ---\n";

    $pdo->prepare("UPDATE announcements SET enabled = 0 WHERE id = ?")->execute([$aNew]);
    assertTrue('disabled announcement disappears immediately', !in_array($aNew, $visibleIds($userA, $practiceNormal), true));
    $pdo->prepare("UPDATE announcements SET enabled = 1 WHERE id = ?")->execute([$aNew]);
    assertTrue('re-enabled announcement returns', in_array($aNew, $visibleIds($userA, $practiceNormal), true));

} finally {
    // Cleanup fixtures (dismissals first).
    if ($createdAnnouncementIds) {
        $in = implode(',', array_map('intval', $createdAnnouncementIds));
        $pdo->exec("DELETE FROM announcement_dismissals WHERE announcement_id IN ($in)");
        $pdo->exec("DELETE FROM announcements WHERE id IN ($in)");
    }
    if ($createdPracticeIds) {
        $in = implode(',', array_map('intval', $createdPracticeIds));
        $pdo->exec("DELETE FROM practice_users WHERE practice_id IN ($in)");
        $pdo->exec("DELETE FROM practices WHERE id IN ($in)");
    }
    if ($createdUserIds) {
        $in = implode(',', array_map('intval', $createdUserIds));
        $pdo->exec("DELETE FROM users WHERE id IN ($in)");
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
