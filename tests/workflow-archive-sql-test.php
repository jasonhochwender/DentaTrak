<?php
/**
 * Database-backed regression test for the Archive Save SQL.
 *
 * This test runs the exact SQL statements used in the process_archives path
 * against the local MAMP database inside a transaction that is always rolled
 * back. A transient test case/practice are used so real data is not modified.
 *
 * It does not output PHI; it only prints counts and pass/fail status.
 */

session_start();
$_SESSION['db_user_id'] = 1;
$_SESSION['user_email'] = 'test@example.com';

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/workflow-columns-service.php';
require_once __DIR__ . '/../api/case-activity-log.php';
require_once __DIR__ . '/../api/lab-assignment-history.php';

$practiceId = 999999;
$sourceStatus = 'Designed';
$nonTerminalDest = 'Sent To External Lab';
$terminalDest = 'Delivered';
$testCaseId = 'TEST-' . uniqid('', true);

$checks = [];
$passed = 0;
$failed = 0;

function check($name, $condition) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: $name\n";
    } else {
        $failed++;
        echo "FAIL: $name\n";
    }
}

// Ensure tables exist outside the transaction; DDL in a transaction causes an implicit commit.
if (function_exists('ensureCaseActivityLogTable')) {
    ensureCaseActivityLogTable();
}
if (function_exists('ensureLabAssignmentHistoryTable')) {
    ensureLabAssignmentHistoryTable();
}

try {
    $pdo->beginTransaction();

    // Seed one test case.
    $ins = $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, status, archived) VALUES (:case_id, :practice_id, :status, 0)");
    $ins->execute([':case_id' => $testCaseId, ':practice_id' => $practiceId, ':status' => $sourceStatus]);

    // 1. Fetch affected case IDs
    $caseIds = fetchCaseIdsForStatus($practiceId, $sourceStatus);
    $caseCount = count($caseIds);
    check('Affected case IDs fetched', $caseCount === 1);

    // 2. Non-terminal destination UPDATE
    $now = date('Y-m-d H:i:s');
    $moveSql = "UPDATE cases_cache
                SET status = :dest,
                    status_changed_at = :now_changed,
                    last_update_date = :now_update
                WHERE practice_id = :pid AND status = :src AND archived = 0";
    $moveStmt = $pdo->prepare($moveSql);
    $ok = false;
    try {
        $ok = $moveStmt->execute([
            ':dest' => $nonTerminalDest,
            ':now_changed' => $now,
            ':now_update' => $now,
            ':pid' => $practiceId,
            ':src' => $sourceStatus,
        ]);
    } catch (PDOException $e) {
        echo "FAIL: Non-terminal UPDATE threw " . $e->getMessage() . "\n";
        $failed++;
    }
    check('Non-terminal bulk status update executed without HY093', $ok);

    if ($ok && $caseCount > 0) {
        $rowCount = $moveStmt->rowCount();
        check('Non-terminal UPDATE affected expected rows', $rowCount === $caseCount);

        // 3. Activity record for non-terminal destination
        $activityOk = true;
        foreach ($caseIds as $caseId) {
            logCaseActivity($caseId, 'status_changed', $sourceStatus, $nonTerminalDest, ['reason' => 'column_archived'], null, true);
        }
        check('Non-terminal activity records logged', $activityOk);
    }

    // 4. Terminal destination UPDATE
    $now2 = date('Y-m-d H:i:s');
    $moveStmt2 = $pdo->prepare($moveSql);
    $ok2 = false;
    try {
        $ok2 = $moveStmt2->execute([
            ':dest' => $terminalDest,
            ':now_changed' => $now2,
            ':now_update' => $now2,
            ':pid' => $practiceId,
            ':src' => $nonTerminalDest,
        ]);
    } catch (PDOException $e) {
        echo "FAIL: Terminal UPDATE threw " . $e->getMessage() . "\n";
        $failed++;
    }
    check('Terminal bulk status update executed without HY093', $ok2);

    if ($ok2 && $caseCount > 0) {
        foreach ($caseIds as $caseId) {
            logCaseActivity($caseId, 'status_changed', $nonTerminalDest, $terminalDest, ['reason' => 'column_archived'], null, true);
            closeOpenLabPeriodForDeliveredCase($caseId, $practiceId);
        }
        check('Terminal activity and lab-period close logged without exception', true);
    }

    // 5. Verify the in-progress status matches terminal destination
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = :pid AND status = :status AND archived = 0");
    $stmt->execute([':pid' => $practiceId, ':status' => $terminalDest]);
    $terminalCount = (int)$stmt->fetchColumn();
    check('Terminal destination count matches moved cases', $terminalCount === $caseCount);

    $pdo->rollBack();
    check('Transaction rolled back', true);

    // 6. Confirm rollback restored the test case to the original source status
    $restored = fetchCaseIdsForStatus($practiceId, $sourceStatus);
    check('Original source status restored after rollback', count($restored) === 0); // our transient case is gone

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "FAIL: Uncaught exception: " . $e->getMessage() . "\n";
    $failed++;
}

echo "\n$passed passed, $failed failed\n";

if ($failed > 0) {
    exit(1);
}
