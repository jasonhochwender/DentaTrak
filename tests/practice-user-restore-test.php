<?php
/**
 * Practice user remove/re-add (pending removal) - source-level regression test.
 *
 * Verifies the client-side staged-removal implementation in js/app.js:
 * pending-removal snapshot + restore, normalized duplicate handling,
 * pending-row rendering with Undo, locale keys in all 9 files, CSS, and
 * that the server save path remains invite-safe for restored members.
 */

$base = dirname(__DIR__);
$app = file_get_contents($base . '/js/app.js');
$css = file_get_contents($base . '/css/settings-billing.css');
$save = file_get_contents($base . '/api/save-settings.php');

$passed = 0;
$failed = 0;

function check($name, $cond) {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        $failed++;
        echo "FAIL {$name}\n";
    }
}

function section($src, $startMarker, $endMarker) {
    $start = strpos($src, $startMarker);
    if ($start === false) return '';
    $end = strpos($src, $endMarker, $start);
    return substr($src, $start, $end === false ? strlen($src) : $end - $start);
}

// ─── State + snapshot ───────────────────────────────────────────────────

check('pendingRemovedUsers initialized on window',
    strpos($app, 'window.pendingRemovedUsers = {}') !== false);

check('applyUserSettings clears staged removals on reload',
    strpos($app, "window.pendingRemovedUsers = {};") !== false &&
    substr_count($app, 'window.pendingRemovedUsers') >= 3);

check('isPersistedPracticeUser consults the saved baseline',
    strpos($app, 'function isPersistedPracticeUser') !== false &&
    strpos(section($app, 'function isPersistedPracticeUser', 'function removePracticeUser'), 'originalSettingsValues') !== false);

$remove = section($app, 'function removePracticeUser(email)', 'function restorePendingRemovedUser');
check('removePracticeUser snapshots state for persisted members only',
    strpos($remove, 'isPersistedPracticeUser(lower)') !== false);
check('removePracticeUser snapshot captures admin flag',
    strpos($remove, 'wasAdmin') !== false);
check('removePracticeUser snapshot captures permission flags + last login',
    strpos($remove, 'lastLogin') !== false &&
    strpos($remove, 'limited') !== false &&
    strpos($remove, 'analytics') !== false &&
    strpos($remove, 'edit') !== false &&
    strpos($remove, 'isLab') !== false);
check('removePracticeUser still guards creator and current user',
    strpos($remove, 'normalizedCreator') !== false &&
    strpos($remove, 'normalizedCurrent') !== false &&
    strpos($remove, 'isPracticeAdmin') !== false);
check('removePracticeUser still drops member from both arrays',
    strpos($remove, 'window.gmailUsers = window.gmailUsers.filter') !== false &&
    strpos($remove, 'window.adminUsers = window.adminUsers.filter') !== false);

// ─── Restore path ───────────────────────────────────────────────────────

$restore = section($app, 'function restorePendingRemovedUser', 'function removeGmailUser');
check('restorePendingRemovedUser requires practice admin',
    strpos($restore, 'isPracticeAdmin') !== false);
check('restore clears the pending entry',
    strpos($restore, 'delete window.pendingRemovedUsers[lower]') !== false);
check('restore re-adds to exactly one list preserving admin flag',
    strpos($restore, 'entry.wasAdmin') !== false &&
    strpos($restore, 'window.adminUsers = window.adminUsers || []).push') !== false &&
    strpos($restore, 'window.gmailUsers = window.gmailUsers || []).push') !== false);
check('restore writes back all four permission maps + logins',
    strpos($restore, "restoreFlag('limitedVisibilityUsers'") !== false &&
    strpos($restore, "restoreFlag('canViewAnalyticsUsers'") !== false &&
    strpos($restore, "restoreFlag('canEditCasesUsers'") !== false &&
    strpos($restore, "restoreFlag('isLabUsers'") !== false &&
    strpos($restore, 'gmailUserLogins') !== false);
check('restore re-renders the grid',
    strpos($restore, 'displayPracticeUsers()') !== false);

// ─── Add-user flow ──────────────────────────────────────────────────────

$add = section($app, 'var email = newGmailUserInput.value.trim()', 'checkUserPracticeStatus(email).then');
check('addGmailUser trims and lowercases the typed address',
    strpos($add, 'toLowerCase()') !== false);
check('addGmailUser duplicate check covers both lists',
    strpos($add, 'listContainsEmail(window.gmailUsers, lowerEmail)') !== false &&
    strpos($add, 'listContainsEmail(window.adminUsers, lowerEmail)') !== false);
check('addGmailUser shows localized already-in-practice inline message',
    strpos($add, "validation.already_in_practice") !== false);
check('addGmailUser restores pending removal before the server check',
    strpos($add, 'window.pendingRemovedUsers[lowerEmail]') !== false &&
    strpos($add, 'restorePendingRemovedUser(lowerEmail)') !== false &&
    strpos($add, 'checkUserPracticeStatus') === false);
check('addGmailUser confirms restore via toast',
    strpos($add, "practice_users.restored") !== false &&
    strpos($add, 'showToast') !== false);

// Server membership check still exists for genuinely new addresses
check('checkUserPracticeStatus still consulted for new adds',
    strpos($app, "inCurrentPractice") !== false);

// ─── Save path ──────────────────────────────────────────────────────────

$saveAuto = section($app, 'Auto-add any pending email', 'var themeDropdown');
check('save auto-add cancels pending removal for typed address',
    strpos($saveAuto, 'pendingRemovedUsers[pendingLower]') !== false &&
    strpos($saveAuto, 'restorePendingRemovedUser(pendingLower)') !== false);
check('save auto-add skips existing members in both lists',
    strpos($saveAuto, 'listContainsEmail(window.gmailUsers, pendingLower)') !== false &&
    strpos($saveAuto, 'listContainsEmail(window.adminUsers, pendingLower)') !== false);

// ─── Pending-row rendering ──────────────────────────────────────────────

$pending = section($app, 'Pending removals: persisted members', 'function setAdminFlagForEmail');
check('pending rows get the pending-removal class',
    strpos($pending, 'pending-removal') !== false);
check('pending rows show the localized removal-applies-on-save note',
    strpos($pending, 'practice_users.pending_removal') !== false);
check('pending rows render frozen disabled checkboxes',
    strpos($pending, 'cb.disabled = true') !== false &&
    strpos($pending, 'cb.checked') !== false);
check('pending rows offer a localized Undo button',
    strpos($pending, 'practice-user-undo-btn') !== false &&
    strpos($pending, "practice_users.undo") !== false &&
    strpos($pending, 'restorePendingRemovedUser') !== false);
check('Undo button uses existing btn-outline styling',
    strpos($pending, 'btn-outline') !== false);
check('displayPracticeUsers no longer early-returns when only pending rows exist',
    strpos($app, 'if (!keys.length && !pendingKeys.length)') !== false);
check('cellLabel hoisted to displayPracticeUsers scope (shared by pending rows)',
    strpos(section($app, 'var normalizedCreator = (window.practiceCreatorEmail', 'keys.forEach'), 'function cellLabel') !== false);

// ─── CSS ────────────────────────────────────────────────────────────────

check('pending-removal row style exists',
    strpos($css, '.practice-user-row.pending-removal') !== false);
check('pending-removal email is struck through',
    strpos($css, 'text-decoration: line-through') !== false);
check('pending-removal note + undo button styles exist',
    strpos($css, '.pending-removal-note') !== false &&
    strpos($css, '.practice-user-undo-btn') !== false);

// ─── Locales: all 9 files ───────────────────────────────────────────────

$locales = ['en-US', 'de-DE', 'es-ES', 'fr-CA', 'fr-FR', 'it-IT', 'ko-KR', 'pt-BR', 'ru-RU'];
foreach ($locales as $loc) {
    $d = json_decode(file_get_contents($base . "/locales/{$loc}.json"), true);
    $pu = $d['settings']['users']['practice_users'] ?? [];
    $v = $pu['validation'] ?? [];
    check("$loc has pending_removal + undo + restored",
        !empty($pu['pending_removal']) && !empty($pu['undo']) && !empty($pu['restored']));
    check("$loc has validation.already_in_practice",
        !empty($v['already_in_practice']));
}

check('en-US restored message matches spec wording',
    strpos(file_get_contents($base . '/locales/en-US.json'), 'User restored. Pending removal canceled.') !== false);
check('en-US already_in_practice message matches spec wording',
    strpos(file_get_contents($base . '/locales/en-US.json'), 'This user is already in your practice.') !== false);

// ─── Server invariants (unchanged, but required for the fix to be safe) ──

check('save-settings still invites only users not previously members',
    strpos($save, 'newlyAddedUserIds') !== false &&
    strpos($save, 'oldUserIds') !== false &&
    strpos($save, 'sendPracticeInviteEmail') !== false);
check('save-settings still enforces admin/assigned-only exclusivity',
    strpos($save, 'invalid_role_combination') !== false);
check('save-settings still protects the practice creator row',
    strpos($save, 'user_id != :creator_id') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
