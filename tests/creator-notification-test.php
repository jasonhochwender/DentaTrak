<?php
/**
 * Source-level regression checks for creator/comment case notifications.
 *
 * Requirements verified here:
 * - Recipients resolve from case creator + current assignee(s) (+ labels),
 *   then filtered to active members currently authorized to access the case.
 * - Unassigned cases do not short-circuit resolution (creator still informed).
 * - Missing/invalid creator data adds no candidate - assignees still notify.
 * - Ordinary comments emit comment_added; mentioned users are excluded from
 *   it so the mention event/preference wins (one notification per channel).
 * - Actor is always excluded.
 * - Reassignment notifies creator + new assignee; unassignment notifies the
 *   creator (no early exit on empty assigned_to).
 * - Queue worker keeps creator pending emails alive across
 *   reassignment/unassignment while still enforcing membership, access,
 *   and email preferences.
 * - comment_added is a supported email preference, gated by SHOW_COMMENTS,
 *   with translations in all nine locales.
 *
 * Run: php tests/creator-notification-test.php
 */

$pass = 0;
$fail = 0;

function check($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

$root = dirname(__DIR__);
$service    = file_get_contents($root . '/api/notification-service.php');
$comments   = file_get_contents($root . '/api/case-comments.php');
$assignment = file_get_contents($root . '/api/update-case-assignment.php');
$worker     = file_get_contents($root . '/api/notification-queue-worker.php');
$prefs      = file_get_contents($root . '/api/notification-preferences.php');
$getPrefs   = file_get_contents($root . '/api/get-notification-preferences.php');
$savePrefs  = file_get_contents($root . '/api/save-notification-preferences.php');
$mainPhp    = file_get_contents($root . '/main.php');
$notifJs    = file_get_contents($root . '/js/notifications.js');
$helpers    = file_get_contents($root . '/api/test-helpers.php');

// ── api/notification-service.php — recipient resolution ─────────────────
check(strpos($service, 'created_by_user_id') !== false &&
      strpos($service, '_getCaseCreatorUserId') !== false,
    'creator resolved from cases_cache.created_by_user_id');
check(strpos($service, 'candidateIds[$creatorId]') !== false,
    'creator added to recipient candidates');
check(strpos($service, 'practice_assignment_label_recipients') !== false,
    'assignment-label expansion to mapped members preserved');
check(strpos($service, 'getCaseAuthorizedUsers($practiceId, $caseId)') !== false,
    'candidates filtered to active members currently authorized for the case');
check(strpos($service, 'excludeUserIds') !== false &&
      strpos($service, '[(int)$actorUserId => true]') !== false,
    'actor exclusion plus caller-supplied exclusions applied');
check(preg_match('/if \(\$assignedTo !== \'\'\)/', $service) === 1 &&
      preg_match('/creator[^;]*included even when the case is unassigned|even when the case is unassigned/s', $service) === 1,
    'no early return on unassigned cases - creator path runs when assigned_to is empty');
check(strpos($service, "'comment_id'") !== false,
    'comment_id is in the notification metadata allowlist (no comment text)');
check(strpos($service, 'INSERT INTO user_notifications') !== false &&
      strpos($service, 'INSERT INTO notification_email_queue') !== false,
    'in-app row + preference-gated email queue path preserved');

// ── api/case-comments.php — ordinary-comment notifications ──────────────
check(preg_match("/emitCaseNotificationEvent\([^;]*'comment_added'[^;]*'comment_added'/s", $comments) === 1 ||
      (strpos($comments, "'comment_added'") !== false && strpos($comments, 'emitCaseNotificationEvent') !== false),
    'ordinary comments emit a comment_added event');
check(strpos($comments, '$mentionedUserIds') !== false &&
      preg_match('/mentionedUserIds\[\]\s*=\s*\(int\)\(\$mention\[\'user_id\'\]/', $comments) === 1,
    'explicitly mentioned users collected for deduplication');
check(preg_match('/emitCaseNotificationEvent\([\s\S]*?\$mentionedUserIds\s*\)/', $comments) === 1,
    'mentioned users excluded from comment_added - mention event wins');
check(strpos($comments, 'comment notification emit error (non-fatal)') !== false,
    'comment notification failure is non-fatal (comment still saves)');

// ── api/update-case-assignment.php — reassignment + unassignment ────────
check(strpos($assignment, '(string)$assignedTo !== (string)$previousAssignee') !== false,
    'assignment event fires on any change including unassignment');
check(strpos($assignment, "'assignment_set' => ") !== false,
    'assignment_set metadata distinguishes assign vs unassign');
check(strpos($assignment, "\$assignedTo !== ''") === false ||
      strpos($assignment, "&& \$assignedTo !== ''") === false,
    'no guard suppressing the event when the new assignee is empty');

// ── api/notification-queue-worker.php — send-time revalidation ──────────
check(strpos($worker, "created_by_user_id") !== false &&
      strpos($worker, '$isCreator') !== false,
    'worker identifies the case creator from the live case row');
check(preg_match('/!\$isMention && !\$isCreator/', $worker) === 1,
    'assignment/label revocation check skipped for mention and creator recipients');
check(strpos($worker, 'practice_membership_revoked') !== false &&
      strpos($worker, 'case_unassigned') !== false &&
      strpos($worker, 'assignment_or_label_revoked') !== false &&
      strpos($worker, 'access_revoked') !== false &&
      strpos($worker, 'email_preferences_disabled') !== false,
    'membership, access, assignment, and preference cancel checks all retained');
check(strpos($worker, 'canUserAccessCase($case') !== false,
    'worker still enforces current case access before sending');

// Ordering: membership check must run before the creator exemption so a
// removed creator can never keep receiving email.
$memberPos = strpos($worker, 'practice_membership_revoked');
$creatorPos = strpos($worker, '$isCreator =');
check($memberPos !== false && $creatorPos !== false && $memberPos < $creatorPos,
    'membership revocation check runs before the creator exemption');

// ── preferences plumbing ────────────────────────────────────────────────
check(strpos($prefs, "'comment_added'") !== false,
    'comment_added is a supported email event type');
check(strpos($getPrefs, "['mention', 'comment_added']") !== false,
    'comment_added preference hidden when SHOW_COMMENTS is disabled (get)');
check(strpos($savePrefs, "['mention', 'comment_added']") !== false,
    'comment_added preference hidden when SHOW_COMMENTS is disabled (save)');

// ── main.php — preferences UI ───────────────────────────────────────────
check(strpos($mainPhp, 'data-event="comment_added"') !== false &&
      strpos($mainPhp, 'pref_comment_added') !== false &&
      strpos($mainPhp, "preferences.events.comment_added") !== false,
    'comment_added email preference toggle present in settings UI');
$commentItem = strpos($mainPhp, 'data-event="comment_added"');
$commentsIf = strrpos(substr($mainPhp, 0, $commentItem), "isFeatureEnabled('SHOW_COMMENTS')");
$endif = strpos($mainPhp, '<?php endif; ?>', $commentItem);
check($commentItem !== false && $commentsIf !== false && $endif !== false &&
      $commentsIf < $commentItem && $commentItem < $endif,
    'comment_added toggle sits inside the SHOW_COMMENTS conditional');

// ── js/notifications.js — navigation section ────────────────────────────
check(strpos($notifJs, "'comment_added'") !== false,
    'comment_added notifications navigate to the comments section');

// ── locales — all three keys in every supported locale ──────────────────
$locales = ['en-US', 'de-DE', 'es-ES', 'fr-CA', 'fr-FR', 'it-IT', 'ko-KR', 'pt-BR', 'ru-RU'];
foreach ($locales as $loc) {
    $data = json_decode(file_get_contents($root . "/locales/{$loc}.json"), true);
    check(is_array($data), "{$loc}: locale file is valid JSON");
    $feed = $data['notifications']['comment_added'] ?? null;
    $email = $data['notifications']['email']['event']['comment_added'] ?? null;
    $pref = $data['preferences']['events']['comment_added'] ?? null;
    check($feed !== null && $email !== null && $pref !== null,
        "{$loc}: feed, email, and preference strings for comment_added present");
    check($feed === null || strpos($feed, '{from}') !== false,
        "{$loc}: comment_added feed string keeps the {from} placeholder");
}

// ── test fixtures added for the e2e ─────────────────────────────────────
check(strpos($helpers, 'get_user_notifications') !== false &&
      strpos($helpers, 'ensure_assignment_label') !== false &&
      strpos($helpers, 'remove_practice_member') !== false,
    'dev-gated fixtures exist for notification assertions, labels, and member removal');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
