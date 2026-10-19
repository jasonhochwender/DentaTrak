<?php
/**
 * Lab Acknowledgment workflow test.
 *
 * Verifies the practices.lab_acknowledgment_enabled toggle, handoff-time
 * 'awaiting' row creation (no backfill), lab confirmation, info requests,
 * promised-date tracking, reassignment supersede/stale-identity guards,
 * and disable/re-enable semantics - all at the module level using real
 * database fixtures.
 *
 * Run: php tests/lab-acknowledgment-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/cases-cache.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/lab-assignment-history.php';
require_once __DIR__ . '/../api/lab-acknowledgment.php';

$environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if ($environment === 'production') {
    fwrite(STDERR, "Refusing to run lab-acknowledgment-test.php in production.\n");
    exit(1);
}

if (!$pdo) {
    fwrite(STDERR, "No database connection available.\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$message}\n"; }
    else { $fail++; echo "FAIL: {$message}\n"; }
}

$run = microtime(true) . '-' . mt_rand(1000, 9999);
$staffEmail = "laback-staff-{$run}@example.test";
$labEmailA  = "laback-laba-{$run}@example.test";
$labEmailB  = "laback-labb-{$run}@example.test";
$caseId     = "laback-case-{$run}";

$ids = ['users' => [], 'practices' => [], 'practice_users' => [], 'cases' => [], 'labels' => [], 'label_recipients' => []];

function labackCleanup($pdo, $ids) {
    if (!$pdo) return;
    $pdo->exec("DELETE FROM case_lab_acknowledgments WHERE case_id LIKE 'laback-case-%'");
    foreach ($ids['cases'] as $c) {
        $pdo->prepare("DELETE FROM cases_cache WHERE case_id = :c")->execute(['c' => $c]);
        $pdo->prepare("DELETE FROM case_activity_log WHERE case_id = :c")->execute(['c' => $c]);
        $pdo->prepare("DELETE FROM case_lab_assignment_periods WHERE case_id = :c")->execute(['c' => $c]);
        $pdo->prepare("DELETE FROM case_comments WHERE case_id = :c")->execute(['c' => $c]);
    }
    foreach ($ids['label_recipients'] as $r) {
        $pdo->prepare("DELETE FROM practice_assignment_label_recipients WHERE id = :i")->execute(['i' => $r]);
    }
    foreach ($ids['labels'] as $l) {
        $pdo->prepare("DELETE FROM practice_assignment_labels WHERE id = :i")->execute(['i' => $l]);
    }
    foreach ($ids['practice_users'] as $p) {
        $pdo->prepare("DELETE FROM practice_users WHERE id = :i")->execute(['i' => $p]);
    }
    foreach ($ids['practices'] as $p) {
        $pdo->prepare("DELETE FROM practices WHERE id = :i")->execute(['i' => $p]);
    }
    foreach ($ids['users'] as $u) {
        $pdo->prepare("DELETE FROM users WHERE id = :i")->execute(['i' => $u]);
    }
}

try {
    ensureLabAcknowledgmentSchema();
    assertTrue(
        $pdo->query("SHOW COLUMNS FROM practices LIKE 'lab_acknowledgment_enabled'")->rowCount() > 0,
        'practices.lab_acknowledgment_enabled column exists'
    );
    assertTrue(
        $pdo->query("SHOW TABLES LIKE 'case_lab_acknowledgments'")->rowCount() > 0,
        'case_lab_acknowledgments table exists'
    );

    // ---- fixtures ----
    $mkUser = function($email, $first, $last) use ($pdo, &$ids) {
        $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
            VALUES (:e, :p, 'email', :f, :l, 'user', 1, 1, NOW())")->execute([
            'e' => $email, 'p' => password_hash('testpass', PASSWORD_BCRYPT), 'f' => $first, 'l' => $last]);
        $id = (int)$pdo->lastInsertId();
        $ids['users'][] = $id;
        return $id;
    };
    $staffId  = $mkUser($staffEmail, 'Staff', 'User');
    $labAId   = $mkUser($labEmailA, 'Lab', 'Alpha');
    $labBId   = $mkUser($labEmailB, 'Lab', 'Beta');

    $practiceUuid = 'laback-' . $run;
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, legal_name, display_name, practice_address,
            baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id, baa_signer_name, baa_signer_title,
            created_by, subscription_status, trial_ends_at)
        VALUES (:u, :n1, :n2, :n3, '1 Test St', 1, UTC_TIMESTAMP(), 'v1.0-test', :uid, 'Tester', 'Admin', :uid2, 'trialing', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY))")
        ->execute(['u' => $practiceUuid, 'n1' => "LabAck {$run}", 'n2' => "LabAck {$run}", 'n3' => "LabAck {$run}", 'uid' => $staffId, 'uid2' => $staffId]);
    $practiceId = (int)$pdo->lastInsertId();
    $ids['practices'][] = $practiceId;

    $mkMembership = function($userId, $isLab) use ($pdo, $practiceId, &$ids) {
        $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_lab, can_edit_cases, created_at)
            VALUES (:p, :u, 'user', :lab, 1, NOW())")->execute(['p' => $practiceId, 'u' => $userId, 'lab' => $isLab ? 1 : 0]);
        $ids['practice_users'][] = (int)$pdo->lastInsertId();
    };
    $mkMembership($staffId, false);
    $mkMembership($labAId, true);
    $mkMembership($labBId, true);

    // Minimal cache row for the case.
    $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, status, patient_first_name, patient_last_name, created_by_user_id, creation_date)
        VALUES (:c, :p, 'New', 'Test', 'Patient', :u, NOW())")->execute(['c' => $caseId, 'p' => $practiceId, 'u' => $staffId]);
    $ids['cases'][] = $caseId;

    // ---- setting OFF: no row, helper false ----
    assertTrue(!isLabAcknowledgmentEnabled($practiceId), 'Setting defaults to OFF');
    $r = recordLabAckAssignmentTransition($caseId, $practiceId, '', $labEmailA, $staffId);
    assertTrue(!$r['opened'], 'Setting OFF: lab handoff opens no acknowledgment');
    assertTrue(getOpenLabAcknowledgment($caseId, $practiceId) === null, 'Setting OFF: no open row created');

    // ---- enable ----
    $pdo->prepare("UPDATE practices SET lab_acknowledgment_enabled = 1 WHERE id = ?")->execute([$practiceId]);
    assertTrue(isLabAcknowledgmentEnabled($practiceId), 'Setting ON after update');

    // Existing case already assigned to lab A: a no-change transition must not backfill.
    $r = recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailA, $labEmailA, $staffId);
    assertTrue(!$r['opened'] && getOpenLabAcknowledgment($caseId, $practiceId) === null, 'No retroactive marking on enable');

    // Real handoff to a different lab user opens a pending ack.
    $r = recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailA, $labEmailB, $staffId);
    $open = getOpenLabAcknowledgment($caseId, $practiceId);
    assertTrue($r['opened'] && $open && $open['status'] === 'awaiting' && $open['lab_key'] === "user:{$labBId}",
        'New lab handoff opens awaiting acknowledgment for the new lab only');

    $mirror = $pdo->prepare("SELECT lab_ack_status FROM cases_cache WHERE case_id = ?");
    $mirror->execute([$caseId]);
    assertTrue($mirror->fetchColumn() === 'awaiting', 'cases_cache.lab_ack_status mirrors awaiting');

    // Identity scoping: lab B matches; staff and lab A (previous lab) do not.
    assertTrue(userMatchesLabAckIdentity($labBId, $open), 'Assigned lab user matches ack identity');
    assertTrue(!userMatchesLabAckIdentity($labAId, $open), 'Previous lab cannot act on new lab ack');
    assertTrue(!userMatchesLabAckIdentity($staffId, $open), 'Staff user cannot act as lab');

    // ---- reassignment supersedes ----
    recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailB, $labEmailA, $staffId);
    $open = getOpenLabAcknowledgment($caseId, $practiceId);
    assertTrue($open && $open['lab_key'] === "user:{$labAId}" && $open['status'] === 'awaiting',
        'Reassignment supersedes old ack and opens new one for lab A');
    $superseded = $pdo->prepare("SELECT COUNT(*) FROM case_lab_acknowledgments WHERE case_id = ? AND status = 'superseded'");
    $superseded->execute([$caseId]);
    assertTrue((int)$superseded->fetchColumn() === 1, 'Previous lab ack preserved as superseded history');

    // Re-enabling the same lab does not duplicate.
    recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailA, $labEmailA, $staffId);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM case_lab_acknowledgments WHERE case_id = ? AND status <> 'superseded'");
    $cnt->execute([$caseId]);
    assertTrue((int)$cnt->fetchColumn() === 1, 'Same-lab transition does not duplicate the open ack');

    // ---- promised date (setting-independent helper) ----
    assertTrue(updateCaseLabPromisedDate($caseId, $practiceId, '2026-03-20', $staffId), 'Promised date writes');
    $pd = $pdo->prepare("SELECT lab_promised_date FROM cases_cache WHERE case_id = ?");
    $pd->execute([$caseId]);
    assertTrue($pd->fetchColumn() === '2026-03-20', 'lab_promised_date persisted, distinct from due/appointment dates');

    // ---- disable preserves data; re-enable does not resend/duplicate ----
    $pdo->prepare("UPDATE practices SET lab_acknowledgment_enabled = 0 WHERE id = ?")->execute([$practiceId]);
    assertTrue(getOpenLabAcknowledgment($caseId, $practiceId) !== null, 'Disabling preserves the recorded ack row');
    $r = recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailA, $labEmailB, $staffId);
    assertTrue(!$r['opened'], 'Setting OFF: reassignment opens no new ack');
    // but the stale open row for lab A was still superseded so a later
    // re-enable cannot resurrect it as the current lab's commitment.
    $open = getOpenLabAcknowledgment($caseId, $practiceId);
    assertTrue($open === null, 'Reassignment while OFF still supersedes the stale ack');

    $pdo->prepare("UPDATE practices SET lab_acknowledgment_enabled = 1 WHERE id = ?")->execute([$practiceId]);
    $cnt->execute([$caseId]);
    assertTrue((int)$cnt->fetchColumn() === 0, 'Re-enable creates no ack rows and resends nothing');

    // A fresh handoff after re-enable works again.
    $r = recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailB, $labEmailA, $staffId);
    assertTrue($r['opened'], 'Re-enabled: new handoff opens ack again');

    // ---- view model ----
    $open = getOpenLabAcknowledgment($caseId, $practiceId);
    $vm = buildLabAckViewModel($open, true, $labAId, false, true, false);
    assertTrue($vm && $vm['labUser'] === true && $vm['staffUser'] === false, 'View model: lab A can act');
    $vm = buildLabAckViewModel($open, true, $staffId, true, false, false);
    assertTrue($vm && $vm['staffUser'] === true && $vm['labUser'] === false, 'View model: staff can record');
    $vm = buildLabAckViewModel($open, false, $staffId, true, false, false);
    assertTrue($vm === null, 'View model hidden when setting OFF');
    $vm = buildLabAckViewModel($open, true, $staffId, true, false, true);
    assertTrue($vm === null, 'View model hidden for archived cases');

    // ---- normalize helper ----
    assertTrue(normalizeLabAckDate('2026-03-20') === '2026-03-20', 'Date normalize passes Y-m-d');
    assertTrue(normalizeLabAckDate('March 20 2026') === '2026-03-20', 'Date normalize parses loose input');
    assertTrue(normalizeLabAckDate('not-a-date') === null, 'Date normalize rejects garbage');
    assertTrue(normalizeLabAckDate('') === null, 'Date normalize accepts empty as null');

    // ---- reassignment clears the previous lab's promised date ----
    // Live state: setting ON, open 'awaiting' ack for lab A.
    updateCaseLabPromisedDate($caseId, $practiceId, '2026-04-01', $staffId);
    $pd->execute([$caseId]);
    assertTrue($pd->fetchColumn() === '2026-04-01', 'Promised date set before reassignment');
    // Mirror onto the ack row like the action endpoint does, and mark the
    // row confirmed so lab-identity revision checks run on a real state.
    $openNow = getOpenLabAcknowledgment($caseId, $practiceId);
    $pdo->prepare("UPDATE case_lab_acknowledgments SET status='confirmed', promised_return_date='2026-04-01' WHERE id=?")
        ->execute([(int)$openNow['id']]);

    // View model: only the confirmed, currently-assigned lab may revise.
    $vm = buildLabAckViewModel(getOpenLabAcknowledgment($caseId, $practiceId), true, $labAId, false, true, false);
    assertTrue($vm['labCanPromised'] === true, 'Confirmed lab can revise its own promised date');
    $vm = buildLabAckViewModel(getOpenLabAcknowledgment($caseId, $practiceId), true, $labBId, false, true, false);
    assertTrue($vm['labCanPromised'] === false, 'A different lab cannot revise lab A\'s promise');
    $vm = buildLabAckViewModel(getOpenLabAcknowledgment($caseId, $practiceId), true, $staffId, true, false, false);
    assertTrue(($vm['labCanPromised'] ?? false) === false, 'Staff flag stays separate from lab capability');

    // lab A -> lab B: the previous commitment leaves the live cache.
    recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailA, $labEmailB, $staffId);
    $pd->execute([$caseId]);
    assertTrue($pd->fetchColumn() === null, 'Reassignment clears previous lab promised date from cache');
    $open = getOpenLabAcknowledgment($caseId, $practiceId);
    assertTrue($open && $open['lab_key'] === "user:{$labBId}" && $open['promised_return_date'] === null,
        'New lab ack opens fresh: no inherited promised date');
    $supRow = $pdo->prepare("SELECT promised_return_date FROM case_lab_acknowledgments WHERE case_id = ? AND lab_key = ? AND status='superseded' ORDER BY id DESC LIMIT 1");
    $supRow->execute([$caseId, "user:{$labAId}"]);
    assertTrue($supRow->fetchColumn() === '2026-04-01', 'Superseded row preserves lab A promised date as history');
    $metaRow = $pdo->prepare("SELECT meta_json FROM case_activity_log WHERE case_id = ? AND event_type='lab_ack_superseded' ORDER BY id DESC LIMIT 1");
    $metaRow->execute([$caseId]);
    $meta = json_decode((string)$metaRow->fetchColumn(), true);
    assertTrue(($meta['cleared_promised_date'] ?? null) === '2026-04-01', 'Supersede history records the cleared promised date');

    // lab B -> internal: the promise clears too.
    updateCaseLabPromisedDate($caseId, $practiceId, '2026-04-05', $labBId);
    recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailB, $staffEmail, $staffId);
    $pd->execute([$caseId]);
    assertTrue($pd->fetchColumn() === null, 'Lab->internal reassignment clears promised date');
    assertTrue(getOpenLabAcknowledgment($caseId, $practiceId) === null, 'No open ack after internal reassignment');

    // internal -> lab (first lab context): a staff-recorded date survives
    // the first handoff; a same-lab save never clears it either.
    updateCaseLabPromisedDate($caseId, $practiceId, '2026-04-07', $staffId);
    recordLabAckAssignmentTransition($caseId, $practiceId, $staffEmail, $labEmailA, $staffId);
    $pd->execute([$caseId]);
    assertTrue($pd->fetchColumn() === '2026-04-07', 'First lab handoff keeps staff-recorded date');
    recordLabAckAssignmentTransition($caseId, $practiceId, $labEmailA, $labEmailA, $staffId);
    $pd->execute([$caseId]);
    assertTrue($pd->fetchColumn() === '2026-04-07', 'Same-lab save keeps the promised date');

    // Delivered-path supersede clears the live date as well.
    supersedeOpenLabAcknowledgment($caseId, $practiceId, 'case_delivered', $staffId);
    $pd->execute([$caseId]);
    assertTrue($pd->fetchColumn() === null, 'Delivered supersede clears promised date');

    // ---- endpoint static checks: disable gate + lab revision path ----
    $src = file_get_contents(__DIR__ . '/../api/lab-acknowledgment-action.php');
    $spdPos  = strpos($src, "\$action === 'set_promised_date'");
    $labGate = strpos($src, "if (!\$enabled)", $spdPos);
    $mainGate = strrpos($src, "if (!\$enabled)");
    assertTrue($labGate !== false && $labGate < $mainGate,
        'Lab promised-date path requires the setting ON');
    assertTrue($spdPos < $mainGate
        && $mainGate < strpos($src, "\$action === 'confirm'")
        && $mainGate < strpos($src, "\$action === 'request_info'")
        && $mainGate < strpos($src, "\$action === 'staff_confirm'"),
        'Disabled setting blocks confirm/request_info/staff_confirm while staff date entry stays open');
    assertTrue(strpos($src, "\$openAck['status'] !== 'confirmed'") !== false
        && strpos($src, 'labAckRowIsCurrent($openAck') !== false
        && strpos($src, 'userMatchesLabAckIdentity($currentUserId, $openAck)') !== false,
        'Lab revision requires a current confirmed acknowledgment it owns');
    assertTrue(strpos($src, "'actor_role'") !== false, 'History distinguishes lab vs staff date changes');

    $js = file_get_contents(__DIR__ . '/../js/app.js');
    assertTrue(strpos($js, 'labCanPromised') !== false, 'UI exposes the lab promised-date revision action');
    assertTrue(strpos($js, 'lab_ack_promised_date_changed_lab') !== false, 'Timeline distinguishes lab-recorded changes');
    foreach (glob(__DIR__ . '/../locales/*.json') as $lf) {
        $ld = json_decode(file_get_contents($lf), true);
        assertTrue(
            isset($ld['cases']['activity']['events']['lab_ack_promised_date_changed_lab']),
            basename($lf) . ' has lab_ack_promised_date_changed_lab'
        );
    }

    // ---- activity log entries written ----
    $logCount = $pdo->prepare("SELECT COUNT(*) FROM case_activity_log WHERE case_id = ? AND event_type LIKE 'lab_%'");
    $logCount->execute([$caseId]);
    assertTrue((int)$logCount->fetchColumn() >= 4, 'Ack transitions recorded in case activity history');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
} finally {
    labackCleanup($pdo, $ids);
}
