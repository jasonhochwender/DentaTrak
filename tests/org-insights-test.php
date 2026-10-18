<?php
/**
 * Organization Insights - metric math + isolation tests.
 *
 * Exercises computeOrgInsights() (api/org-insights-metrics.php) against
 * fixtures with known expected results: workload, overdue, on-time with
 * due-edit/missing-due exclusions, reopened cases, archived-completed,
 * activity-log delivery fallback, cross-practice aggregation, lab
 * performance with shared-user merge and label-practice scoping.
 *
 * Requires the local development database (per AGENTS.md: development
 * environment, MySQL on port 3308). Creates throwaway fixtures under
 * 'orgins-*' test emails and removes them afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/organization-access.php';
require_once __DIR__ . '/../api/cases-cache.php';
require_once __DIR__ . '/../api/org-insights-metrics.php';

global $pdo;

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

$createdUserIds = [];
$createdPracticeIds = [];
$createdCaseIds = [];
$createdLabelIds = [];

$mkUser = function (string $email) use ($pdo, &$createdUserIds): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'OrgIns', 'Test', 'user', 1, 1, NOW())")
        ->execute(['e' => $email]);
    $id = (int)$pdo->lastInsertId();
    $createdUserIds[] = $id;
    return $id;
};

$mkPractice = function (string $name, int $createdBy) use ($pdo, &$createdPracticeIds): int {
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at)
                   VALUES (UUID(), :n, :cb, 1, NOW())")
        ->execute(['n' => $name, 'cb' => $createdBy]);
    $id = (int)$pdo->lastInsertId();
    $createdPracticeIds[] = $id;
    return $id;
};

$attach = function (int $practiceId, int $userId, string $role = 'user', int $isOwner = 0, int $isLab = 0) use ($pdo): void {
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner, is_lab) VALUES (:p, :u, :r, :o, :l)")
        ->execute(['p' => $practiceId, 'u' => $userId, 'r' => $role, 'o' => $isOwner, 'l' => $isLab]);
};

ensureCasesCacheTable();
$mkCase = function (string $caseId, int $practiceId, string $status, ?string $dueDate,
                    ?string $created, ?string $statusChanged, int $archived = 0) use ($pdo, &$createdCaseIds): void {
    $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name,
                                            status, archived, creation_date, status_changed_at, due_date, last_update_date)
                   VALUES (:c, :p, 'Ins', 'Case', :s, :a, :cd, :sc, :dd, NOW())")
        ->execute([
            'c' => $caseId, 'p' => $practiceId, 's' => $status, 'a' => $archived,
            'cd' => $created, 'sc' => $statusChanged, 'dd' => $dueDate,
        ]);
    $createdCaseIds[] = $caseId;
};

$mkLog = function (string $caseId, string $eventType, ?string $newStatus, string $createdAt, ?string $meta = null) use ($pdo): void {
    $pdo->prepare("INSERT INTO case_activity_log (case_id, event_type, new_status, meta_json, created_at)
                   VALUES (:c, :e, :n, :m, :t)")
        ->execute(['c' => $caseId, 'e' => $eventType, 'n' => $newStatus, 'm' => $meta, 't' => $createdAt]);
};

$mkLabel = function (int $practiceId, string $label) use ($pdo, &$createdLabelIds): int {
    $pdo->prepare("INSERT INTO practice_assignment_labels (practice_id, label, sort_order, is_lab)
                   VALUES (:p, :l, 0, 1)")
        ->execute(['p' => $practiceId, 'l' => $label]);
    $id = (int)$pdo->lastInsertId();
    $createdLabelIds[] = $id;
    return $id;
};

$mkPeriod = function (string $caseId, int $practiceId, ?int $userId, ?int $labelId,
                      string $displayName, string $start, ?string $end, ?string $endReason,
                      string $quality = 'observed') use ($pdo): void {
    $pdo->prepare("INSERT INTO case_lab_assignment_periods
                       (case_id, practice_id, assignee_type, user_id, label_id,
                        assignee_display_name_snapshot, is_lab_snapshot,
                        started_at, ended_at, end_reason, history_quality)
                   VALUES (:c, :p, :t, :u, :l, :d, 1, :s, :e, :r, :q)")
        ->execute([
            'c' => $caseId, 'p' => $practiceId,
            't' => $userId !== null ? 'user' : 'label',
            'u' => $userId, 'l' => $labelId, 'd' => $displayName,
            's' => $start, 'e' => $end, 'r' => $endReason, 'q' => $quality,
        ]);
};

$d = function (int $daysAgo): string {
    return (new DateTimeImmutable('now'))->modify("-{$daysAgo} days")->format('Y-m-d H:i:s');
};
$dd = function (int $days): string { // negative = past due, positive = future
    return (new DateTimeImmutable('now'))->modify("{$days} days")->format('Y-m-d');
};

$owner   = $mkUser('orgins-owner@test.local');
$ownerB  = $mkUser('orgins-owner-b@test.local');
$labUser = $mkUser('orgins-lab@test.local');

$p1 = $mkPractice('OrgIns P1', $owner);
$p2 = $mkPractice('OrgIns P2', $owner);
$pB = $mkPractice('OrgIns Other-Org', $ownerB);

$attach($p1, $owner, 'admin', 1);
$attach($p2, $owner, 'admin', 1);
$attach($pB, $ownerB, 'admin', 1);
$attach($p1, $labUser, 'user', 0, 1);
$attach($p2, $labUser, 'user', 0, 1);
$labelB = $mkLabel($pB, 'Shared Lab Name');

try {
    // ---------------- Case fixtures (P1) ----------------
    $mkCase('OI-ACT-1',        $p1, 'Originated',   $dd(-3), $d(20), $d(20));
    $mkCase('OI-ACT-2',        $p1, 'Manufactured', $dd(1),  $d(20), $d(20));
    $mkCase('OI-ACT-3',        $p1, 'Designed',     null,    $d(20), $d(20));
    $mkCase('OI-DONE-ONTIME',  $p1, 'Delivered',    $dd(-5), $d(40), $d(10));
    $mkCase('OI-DONE-LATE',    $p1, 'Delivered',    $dd(-20),$d(40), $d(10));
    $mkCase('OI-DONE-NODUE',   $p1, 'Delivered',    null,    $d(40), $d(10));
    $mkCase('OI-DONE-EDIT',    $p1, 'Delivered',    $dd(-5), $d(40), $d(10));
    $mkLog('OI-DONE-EDIT', 'case_updated', null, $d(2), json_encode(['changed_fields' => ['dueDate']]));
    $mkCase('OI-REOPEN',       $p1, 'Originated',   $dd(10), $d(30), $d(20));
    $mkLog('OI-REOPEN', 'status_changed', 'Delivered', $d(15));
    $mkCase('OI-OLD',          $p1, 'Delivered',    $dd(-95),$d(120),$d(100));
    $mkCase('OI-ARCH',         $p1, 'Delivered',    $dd(-5), $d(40), $d(10), 1);
    $mkCase('OI-NEW',          $p1, 'Originated',   $dd(5),  $d(5),  $d(5));
    $mkCase('OI-CREATED-OLD',  $p1, 'Originated',   null,    $d(100),$d(90));
    $mkCase('OI-LOGONLY',      $p1, 'Delivered',    $dd(-5), $d(40), null);
    $mkLog('OI-LOGONLY', 'status_changed', 'Delivered', $d(10));

    // Lab-host cases (Delivered long ago: outside the 30d window, not active)
    $mkCase('OI-LABCASE-1',    $p1, 'Delivered',    $dd(-40),$d(50), $d(40));
    $mkCase('OI-LABCASE-2',    $p1, 'Delivered',    $dd(-40),$d(50), $d(40));
    $mkCase('OI-LABCASE-3',    $p1, 'Delivered',    $dd(-40),$d(50), $d(40));

    // ---------------- Case fixtures (P2) ----------------
    $mkCase('OI2-ACT-1',    $p2, 'Originated', $dd(-2), $d(15), $d(15));
    $mkCase('OI2-DONE',     $p2, 'Delivered',  $dd(-3), $d(30), $d(8));
    $mkCase('OI2-CREATED',  $p2, 'Originated', $dd(2),  $d(2),  $d(2));
    $mkCase('OI2-LABCASE',  $p2, 'Delivered',  $dd(-40),$d(50), $d(40));

    // Other-org rows must never leak into results
    $mkCase('OIB-LEAK',     $pB, 'Delivered',  $dd(-1), $d(5), $d(3));
    $mkCase('OIB-LABCASE',  $pB, 'Delivered',  $dd(-40),$d(50), $d(40));

    // ---------------- Lab fixtures ----------------
    // P1: 2 observed delivered periods (2d, 3d) + 1 backfilled (excluded from turnaround)
    $mkPeriod('OI-LABCASE-1', $p1, $labUser, null, 'LabCo', $d(12), $d(10), 'delivered');
    $mkPeriod('OI-LABCASE-2', $p1, $labUser, null, 'LabCo', $d(9),  $d(6),  'delivered');
    $mkPeriod('OI-LABCASE-3', $p1, $labUser, null, 'LabCo', $d(9),  $d(4),  'delivered', 'backfilled_unknown_start');
    // P2: same verified lab user -> merged org row (1 observed period, 4d)
    $mkPeriod('OI2-LABCASE',  $p2, $labUser, null, 'LabCo', $d(8),  $d(4),  'delivered');
    // Other-org label with the same display name — must not merge/leak
    $mkPeriod('OIB-LABCASE',  $pB, null, $labelB, 'LabCo', $d(7),  $d(2), 'delivered');

    // ------------------------------------------------------------------
    $now = new DateTimeImmutable('now', new DateTimeZone(ORG_INSIGHTS_TZ));
    $practices = [
        ['id' => $p1, 'name' => 'OrgIns P1'],
        ['id' => $p2, 'name' => 'OrgIns P2'],
    ];
    $opts = [
        'rangeStart'  => $now->modify('-29 days'),
        'rangeEnd'    => $now,
        'now'         => $now,
        'includeLabs' => true,
    ];
    $res = computeOrgInsights($pdo, $practices, $opts);
    $rows = [];
    foreach ($res['practices'] as $r) { $rows[$r['practiceId']] = $r; }

    // ---- P1 ----
    $r1 = $rows[$p1];
    assertEquals('P1 active count', 6, $r1['workload']['active']);
    assertEquals('P1 overdue count', 1, $r1['workload']['overdue']);
    assertEquals('P1 active with no due date', 2, $r1['workload']['activeNoDueDate']);
    assertEquals('P1 overdue rate (1/6)', 16.7, $r1['workload']['overdueRatePct']);
    assertEquals('P1 created in range', 4, $r1['history']['created']);
    assertEquals('P1 completed in range', 6, $r1['history']['completed']);
    assertEquals('P1 on-time eligible', 4, $r1['history']['onTime']['eligible']);
    assertEquals('P1 on-time', 3, $r1['history']['onTime']['onTime']);
    assertEquals('P1 on-time rate 75%', 75.0, $r1['history']['onTime']['pct']);
    assertEquals('P1 excluded missing due', 1, $r1['history']['onTime']['excludedNoDueDate']);
    assertEquals('P1 excluded due edited', 1, $r1['history']['onTime']['excludedDueEdited']);

    // ---- P2 ----
    $r2 = $rows[$p2];
    assertEquals('P2 active count', 2, $r2['workload']['active']);
    assertEquals('P2 overdue count', 1, $r2['workload']['overdue']);
    assertEquals('P2 created in range', 2, $r2['history']['created']);
    assertEquals('P2 completed', 1, $r2['history']['completed']);
    assertEquals('P2 on-time rate 100%', 100.0, $r2['history']['onTime']['pct']);

    // ---- Totals (other-org case never counted) ----
    assertEquals('Total active', 8, $res['totals']['workload']['active']);
    assertEquals('Total overdue', 2, $res['totals']['workload']['overdue']);
    assertEquals('Total overdue rate 25%', 25.0, $res['totals']['workload']['overdueRatePct']);
    assertEquals('Total created', 6, $res['totals']['history']['created']);
    assertEquals('Total completed', 7, $res['totals']['history']['completed']);
    assertEquals('Total on-time eligible', 5, $res['totals']['history']['onTimeEligible']);
    assertEquals('Total on-time', 4, $res['totals']['history']['onTime']);
    assertEquals('Total on-time rate 80%', 80.0, $res['totals']['history']['onTimePct']);
    assertEquals('Other-org practice absent', false, isset($rows[$pB]));

    // ---- Labs ----
    $labUserRow = null;
    $labelRows = 0;
    foreach ($res['labs']['rows'] as $row) {
        if ($row['type'] === 'user') { $labUserRow = $row; }
        else { $labelRows++; }
    }
    assertTrue('Shared lab user merged across practices', $labUserRow !== null);
    assertEquals('Shared lab user volume', 4, $labUserRow['volume'] ?? -1);
    assertEquals('Shared lab user practices', 2, count($labUserRow['practices'] ?? []));
    assertEquals('Shared lab user avg turnaround (observed only)', 3.0, $labUserRow['avgTurnaroundDays'] ?? -1);
    assertEquals('Other-org label row absent', 0, $labelRows);

    // ---- Trend (pooled monthly series matches totals) ----
    assertEquals('Trend created sums to total', 6, array_sum($res['trend']['created']));
    assertEquals('Trend completed sums to total', 7, array_sum($res['trend']['completed']));
    assertTrue('Trend has month labels', count($res['trend']['months']) >= 1);

    // ---- Practice scoping (what the endpoint does for ?practice_id=) ----
    $resP2 = computeOrgInsights($pdo, [$practices[1]], $opts);
    assertEquals('Single-practice scope active = P2 only', 2, $resP2['totals']['workload']['active']);
    assertEquals('Single-practice scope completed = P2 only', 1, $resP2['totals']['history']['completed']);
    // Lab merge collapses to P2-only volume
    $lu2 = null;
    foreach ($resP2['labs']['rows'] as $row) { if ($row['type'] === 'user') { $lu2 = $row; } }
    assertEquals('Single-practice lab volume = P2 only', 1, $lu2['volume'] ?? -1);

    // ---- 7d range boundary ----
    $res7d = computeOrgInsights($pdo, $practices, [
        'rangeStart' => $now->modify('-6 days'), 'rangeEnd' => $now,
        'now' => $now, 'includeLabs' => false,
    ]);
    assertTrue('7d range has fewer completions', $res7d['totals']['history']['completed'] < 7);
    assertEquals('7d created only recent', 2, $res7d['totals']['history']['created']);

    // ---- Empty scope ----
    $resNone = computeOrgInsights($pdo, [], $opts);
    assertEquals('Empty practice scope yields no rows', 0, count($resNone['practices']));
    assertEquals('Empty scope totals zero', 0, $resNone['totals']['workload']['active']);

    // ---- Meta ----
    assertEquals('Meta timezone', ORG_INSIGHTS_TZ, $res['meta']['timezone']);
    assertTrue('Meta definitions present', !empty($res['meta']['definitions']['onTime']));

} finally {
    if ($createdCaseIds) {
        $in = "'" . implode("','", array_map('addslashes', $createdCaseIds)) . "'";
        $pdo->query("DELETE FROM case_lab_assignment_periods WHERE case_id IN ({$in})");
        $pdo->query("DELETE FROM case_activity_log WHERE case_id IN ({$in})");
        $pdo->query("DELETE FROM cases_cache WHERE case_id IN ({$in})");
    }
    if ($createdLabelIds) {
        $pdo->query("DELETE FROM practice_assignment_labels WHERE id IN (" . implode(',', $createdLabelIds) . ")");
    }
    foreach ($createdPracticeIds as $pid) {
        $pdo->prepare("DELETE FROM practice_users WHERE practice_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM practices WHERE id = ?")->execute([$pid]);
    }
    foreach ($createdUserIds as $uid) {
        $pdo->prepare("DELETE FROM practice_users WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
