<?php
/**
 * Unit tests for api/admin-emails.php - the Practice Administration
 * "Send Email" composer. Runs against the local dev DB (port 3308) with
 * self-cleaning fixtures. Emails are recorded to disk by test_record
 * mode; nothing is delivered.
 */
$base = __DIR__ . '/..';
require_once $base . '/api/appConfig.php';
require_once $base . '/api/admin-emails.php';
global $pdo;

$passed = 0; $failed = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS: $name\n"; }
    else { $failed++; echo "FAIL: $name" . ($detail ? " ($detail)" : '') . "\n"; }
}

/* ---------------- fixtures ---------------- */
$tag = 'AEM' . substr(md5((string)microtime(true)), 0, 6);
$uid = null; $pid = null; $subId = null;

$pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
               VALUES (?, '', 'email', 'Ada', 'Adminson', 'user', 1, 1, NOW())")
    ->execute([$tag . '@example.com']);
$uid = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at)
               VALUES (UUID(), ?, ?, 1, NOW())")
    ->execute([$tag . ' Dental', $uid]);
$pid = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner, created_at) VALUES (?, ?, 'admin', 1, NOW())")
    ->execute([$pid, $uid]);

$pdo->prepare("INSERT INTO subscriptions (owner_user_id, plan, status, trial_ends_at, cancel_at_period_end, fabg_discount, created_at)
               VALUES (?, 'growth', 'trialing', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 DAY), 0, 0, UTC_TIMESTAMP())")
    ->execute([$uid]);
$subId = (int)$pdo->lastInsertId();

try {
    /* ---------------- type registry ---------------- */
    $types = adminPracticeEmailTypes();
    ok('eight email types', count($types) === 8, implode(',', $types));
    ok('user_guide removed', !in_array('user_guide', $types, true));
    ok('all required types present',
        array_diff(['getting_started','work_with_lab','explore_insights','connect_tools','whats_new','feedback','trial_reminder','custom'], $types) === []);

    /* ---------------- compose ---------------- */
    $c = composeAdminPracticeEmail($pdo, $pid, $uid, 'getting_started');
    ok('getting_started composes', !empty($c['success']), $c['message'] ?? '');
    ok('subject is template default', ($c['subject'] ?? '') === 'Getting started with DentaTrak');
    ok('body personalized with first name', strpos($c['text'] ?? '', 'Hi Ada,') !== false);
    ok('body personalized with practice', strpos($c['text'] ?? '', $tag . ' Dental') !== false);
    ok('signed by Jason', strpos($c['text'] ?? '', 'Jason' . "\n" . 'Co-Founder, DentaTrak') !== false);
    ok('encourages direct reply', stripos($c['text'] ?? '', 'reply to this email') !== false);
    ok('html escapes nothing unexpected', strpos($c['html'] ?? '', 'Hi Ada,') !== false);

    $c2 = composeAdminPracticeEmail($pdo, $pid, $uid, 'work_with_lab');
    ok('work_with_lab composes', !empty($c2['success']));
    ok('work_with_lab mentions lab coordination', stripos($c2['text'] ?? '', 'lab') !== false);

    foreach (['explore_insights','connect_tools','whats_new','feedback'] as $t) {
        $r = composeAdminPracticeEmail($pdo, $pid, $uid, $t);
        ok("$t composes", !empty($r['success']), $r['message'] ?? '');
    }
    ok('connect_tools mentions real integrations only',
        strpos(composeAdminPracticeEmail($pdo, $pid, $uid, 'connect_tools')['text'] ?? '', 'Open Dental') !== false
        && strpos(composeAdminPracticeEmail($pdo, $pid, $uid, 'connect_tools')['text'] ?? '', 'DS Core') !== false);

    /* ---------------- overrides ---------------- */
    $co = composeAdminPracticeEmail($pdo, $pid, $uid, 'whats_new', 'Custom subject', 'Custom body text.');
    ok('subject override wins', ($co['subject'] ?? '') === 'Custom subject');
    ok('body override wins', strpos($co['text'] ?? '', 'Custom body text.') !== false
        && strpos($co['text'] ?? '', "What's new") === false);
    ok('override still wrapped with greeting+signature',
        strpos($co['text'] ?? '', 'Hi Ada,') !== false && strpos($co['text'] ?? '', 'Co-Founder, DentaTrak') !== false);

    $cx = composeAdminPracticeEmail($pdo, $pid, $uid, 'custom', 'Hello', 'My own words.');
    ok('custom composes', !empty($cx['success']));
    $cxBad = composeAdminPracticeEmail($pdo, $pid, $uid, 'custom', 'Hello', '');
    ok('custom requires body', empty($cxBad['success']));
    $cxBad2 = composeAdminPracticeEmail($pdo, $pid, $uid, 'custom', '', 'body');
    ok('custom requires subject', empty($cxBad2['success']));

    $bad = composeAdminPracticeEmail($pdo, $pid, $uid, 'user_guide');
    ok('retired user_guide rejected', empty($bad['success']));
    $bad2 = composeAdminPracticeEmail($pdo, $pid, $uid, 'nonsense');
    ok('unknown type rejected', empty($bad2['success']));

    /* ---------------- html safety ---------------- */
    $ch = composeAdminPracticeEmail($pdo, $pid, $uid, 'custom', 'S', 'Line with <script>alert(1)</script> & "quotes"');
    ok('html escapes body', strpos($ch['html'] ?? '', '&lt;script&gt;') !== false
        && strpos($ch['html'] ?? '', '<script>') === false);

    /* ---------------- trial reminder ---------------- */
    $tr = composeAdminPracticeEmail($pdo, $pid, $uid, 'trial_reminder');
    ok('trial_reminder composes for trialing practice', !empty($tr['success']), $tr['message'] ?? '');
    ok('trial_reminder mentions remaining days', preg_match('/\d+ days remaining/', $tr['text'] ?? '') === 1);
    $trOv = composeAdminPracticeEmail($pdo, $pid, $uid, 'trial_reminder', 'T subject', 'Edited trial note.');
    ok('trial_reminder honors overrides', ($trOv['subject'] ?? '') === 'T subject');

    /* ---------------- context + warnings ---------------- */
    $ctx = adminEmailContext($pdo, $pid, $uid, 'explore_insights');
    ok('no-cases insights warning', count(array_filter($ctx['warnings'], fn($w) => stripos($w, 'no cases') !== false)) === 1,
        implode('|', $ctx['warnings']));
    $ctx2 = adminEmailContext($pdo, $pid, $uid, 'trial_reminder');
    ok('trial ending soon: no premature warning', count(array_filter($ctx2['warnings'], fn($w) => stripos($w, 'premature') !== false)) === 0);
    ok('last_email initially null', $ctx['last_email'] === null);

    /* ---------------- send + log ---------------- */
    @unlink($base . '/testResults/last-email.json');
    $logCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM admin_email_log WHERE practice_id=" . $pid)->fetchColumn();
    $s = sendAdminPracticeEmail($pdo, 1, 'admin@example.com', $pid, $uid, 'feedback');
    ok('send succeeds (test record mode)', !empty($s['success']), $s['message'] ?? '');
    $logCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM admin_email_log WHERE practice_id=" . $pid)->fetchColumn();
    ok('admin_email_log row written', $logCountAfter === $logCountBefore + 1);
    $logRow = $pdo->query("SELECT * FROM admin_email_log WHERE practice_id=" . $pid . " ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok('log records type+subject+recipient',
        $logRow && $logRow['email_type'] === 'feedback' && $logRow['recipient_email'] === $tag . '@example.com'
        && $logRow['success'] == 1 && trim($logRow['email_subject']) !== '');

    $ctx3 = adminEmailContext($pdo, $pid, $uid, 'feedback');
    ok('last_email now populated', $ctx3['last_email'] !== null && $ctx3['last_email']['email_type'] === 'feedback');
    ok('same-type resend warned', count(array_filter($ctx3['warnings'], fn($w) => stripos($w, 'already sent') !== false)) === 1,
        implode('|', $ctx3['warnings']));

    /* ---------------- non-trialing trial_reminder ---------------- */
    $pdo->prepare("UPDATE subscriptions SET status='active' WHERE id=?")->execute([$subId]);
    $trBad = composeAdminPracticeEmail($pdo, $pid, $uid, 'trial_reminder');
    ok('trial_reminder rejected when not trialing', empty($trBad['success']));
    $ctx4 = adminEmailContext($pdo, $pid, $uid, 'trial_reminder');
    ok('context warns no active trial', count(array_filter($ctx4['warnings'], fn($w) => stripos($w, 'active trial') !== false)) === 1);
    $pdo->prepare("UPDATE subscriptions SET status='trialing' WHERE id=?")->execute([$subId]);

    /* ---------------- recipient isolation ---------------- */
    $other = composeAdminPracticeEmail($pdo, $pid, $uid + 9999, 'getting_started');
    ok('non-member recipient rejected', empty($other['success']));
} finally {
    if ($subId) $pdo->prepare("DELETE FROM subscriptions WHERE id=?")->execute([$subId]);
    if ($pid) {
        $pdo->prepare("DELETE FROM admin_email_log WHERE practice_id=?")->execute([$pid]);
        $pdo->prepare("DELETE FROM practice_users WHERE practice_id=?")->execute([$pid]);
        $pdo->prepare("DELETE FROM practices WHERE id=?")->execute([$pid]);
    }
    if ($uid) $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
