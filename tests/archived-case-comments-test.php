<?php
/**
 * Source-level regression checks for read-only comments on archived cases.
 *
 * Archived cases are read-only history: the composer must be hidden behind a
 * notice, every comment mutation path must be rejected server-side against
 * the case's current archived flag (never the UI alone), and a draft caught
 * mid-edit by an archive must stay visible so its text can be copied.
 *
 * Run: php tests/archived-case-comments-test.php
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
$commentsApi = file_get_contents($root . '/api/case-comments.php');
$signedUrl = file_get_contents($root . '/api/upload-signed-url.php');
$commentsJs = file_get_contents($root . '/js/case-comments.js');
$appJs = file_get_contents($root . '/js/app.js');
$mainPhp = file_get_contents($root . '/main.php');
$css = file_get_contents($root . '/css/case-comments.css');

// ── api/case-comments.php — server enforcement ───────────────────────────
check(strpos($commentsApi, "t('api.comments.case_archived')") !== false,
    'comments endpoint returns the localized archived message');

// Both mutation actions must evaluate the case's CURRENT archived flag from
// the requireCaseAccess() row, not a client-supplied value.
check(preg_match('/\$caseInfo = requireCaseAccess\(\$caseId, \$currentPracticeId\);[\s\S]{0,600}caseInfo\[\'archived\'\]/', $commentsApi) === 1,
    'create action checks archived on the case returned by requireCaseAccess');
check(preg_match('/\$commentCase = requireCaseAccess\(\$comment\[\'case_id\'\][\s\S]{0,600}commentCase\[\'archived\'\]/', $commentsApi) === 1,
    'delete action checks archived on the comment\'s case');

// The archived rejection carries a machine-readable flag so the client can
// flip its composer to read-only while preserving the draft.
check(substr_count($commentsApi, "'archived' => true") >= 2,
    'create and delete rejections both emit archived flag');

// GET (read) is not gated by archived - comments stay viewable.
check(preg_match('/\$method === \'GET\'[\s\S]{0,1200}requireCaseAccess\(\$caseId, \$currentPracticeId\);/', $commentsApi) === 1,
    'GET still requires case access');
check(!preg_match('/\$method === \'GET\'[\s\S]{0,900}archived/', $commentsApi),
    'GET is not blocked on archived cases');

// ── api/upload-signed-url.php ────────────────────────────────────────────
check(strpos($signedUrl, "\$uploadType === 'comments'") !== false &&
      strpos($signedUrl, "caseInfo['archived']") !== false,
    'signed-URL endpoint blocks comment-image staging on archived cases');
check(preg_match('/\$caseInfo = requireCaseAccess\(\$caseId, \$currentPracticeId\);/', $signedUrl) === 1,
    'signed-URL archived check reuses requireCaseAccess result');

// ── main.php markup ─────────────────────────────────────────────────────
check(strpos($mainPhp, 'id="caseCommentsArchivedNotice"') !== false,
    'archived read-only notice element exists in the comments panel');
check(strpos($mainPhp, "t('cases.comments.archived_readonly')") !== false,
    'notice text is translated');

// ── css ──────────────────────────────────────────────────────────────────
check(strpos($css, '.case-comments-archived-notice') !== false,
    'notice style exists in case-comments.css');

// ── js/case-comments.js — client behavior ────────────────────────────────
check(strpos($commentsJs, "dataset.caseArchived === '1'") !== false,
    'comments module reads the case archived marker at init');
check(strpos($commentsJs, 'applyArchivedCommentState') !== false,
    'archived state applies on init/clear');
check(strpos($commentsJs, 'keepDraftVisible') !== false &&
      strpos($commentsJs, 'input.readOnly = true') !== false,
    'mid-edit archive keeps draft visible and read-only for copying');
check(strpos($commentsJs, 'data.archived === true') !== false,
    'client flips to read-only when the server reports the case was archived');
check(strpos($commentsJs, 'commentsArchived ||') !== false,
    'submit button stays disabled while archived');
check(strpos($commentsJs, "t('cases.comments.archived_readonly')") !== false,
    'early client-side archived rejection uses the localized notice');
check(strpos($commentsJs, 'caseCommentLastError') !== false,
    'comments module exposes last failure reason to the shared save flow');

// ── js/app.js — shared save surfaces the real reason ────────────────────
check(strpos($appJs, 'caseCommentLastError') !== false,
    'shared save uses the comment error instead of only the generic toast');

// ── locales — every supported language gets both keys ───────────────────
$localeFiles = glob($root . '/locales/*.json');
check(count($localeFiles) >= 8, 'locale files discovered');
foreach ($localeFiles as $file) {
    $j = json_decode(file_get_contents($file), true);
    $name = basename($file, '.json');
    check(isset($j['api']['comments']['case_archived']),
        "locale {$name}: api.comments.case_archived present");
    check(isset($j['cases']['comments']['archived_readonly']),
        "locale {$name}: cases.comments.archived_readonly present");
    check(isset($j['api']['comments']['case_archived'])
        && stripos($j['api']['comments']['case_archived'], '{') === false,
        "locale {$name}: archived message needs no placeholders");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
