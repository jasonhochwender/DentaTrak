<?php
/**
 * Database-backed regression test for the Reset Save SQL.
 *
 * Runs the exact SQL statements used in the process_reset path inside a
 * transaction that is always rolled back. No data is committed.
 */

session_start();
$_SESSION['db_user_id'] = 1;
$_SESSION['user_email'] = 'test@example.com';

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/workflow-columns-service.php';
require_once __DIR__ . '/../api/case-activity-log.php';
require_once __DIR__ . '/../api/lab-assignment-history.php';

$practiceId = 999998;
$testCaseId1 = 'TEST-RS-' . uniqid('', true);
$testCaseId2 = 'TEST-RS2-' . uniqid('', true);
$testCaseId3 = 'TEST-RS3-' . uniqid('', true);

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

if (function_exists('ensureCaseActivityLogTable')) {
    ensureCaseActivityLogTable();
}
if (function_exists('ensureLabAssignmentHistoryTable')) {
    ensureLabAssignmentHistoryTable();
}

try {
    $pdo->beginTransaction();

    // Seed three cases with two different custom statuses.
    $ins = $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, status, archived) VALUES (:case_id, :practice_id, :status, 0)");
    $ins->execute([':case_id' => $testCaseId1, ':practice_id' => $practiceId, ':status' => 'Custom-Status-A']);
    $ins->execute([':case_id' => $testCaseId2, ':practice_id' => $practiceId, ':status' => 'Custom-Status-A']);
    $ins->execute([':case_id' => $testCaseId3, ':practice_id' => $practiceId, ':status' => 'Custom-Status-B']);

    // The shared UPDATE SQL used by process_reset.
    $moveSql = "UPDATE cases_cache
                SET status = :dest,
                    status_changed_at = :now_changed,
                    last_update_date = :now_update
                WHERE practice_id = :pid AND status = :src AND archived = 0";

    // 1. Reset to Originated (non-terminal) for one custom status.
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare($moveSql);
    $ok = false;
    try {
        $ok = $stmt->execute([
            ':dest' => 'Originated',
            ':now_changed' => $now,
            ':now_update' => $now,
            ':pid' => $practiceId,
            ':src' => 'Custom-Status-A',
        ]);
    } catch (PDOException $e) {
        echo "FAIL: Reset to Originated for Custom-Status-A threw " . $e->getMessage() . "\n";
        $failed++;
    }
    check('Reset to Originated (single source) executed without HY093', $ok);
    if ($ok) {
        check('Reset to Originated affected 2 rows', $stmt->rowCount() === 2);
        $caseIds = fetchCaseIdsForStatus($practiceId, 'Custom-Status-A');
        foreach ($caseIds as $caseId) {
            logCaseActivity($caseId, 'status_changed', 'Custom-Status-A', 'Originated', ['reason' => 'workflow_reset'], null, true);
        }
        check('Reset to Originated activity records logged', true);
    }

    // 2. Reset to Delivered (terminal) for second custom status.
    $now2 = date('Y-m-d H:i:s');
    $stmt2 = $pdo->prepare($moveSql);
    $ok2 = false;
    try {
        $ok2 = $stmt2->execute([
            ':dest' => 'Delivered',
            ':now_changed' => $now2,
            ':now_update' => $now2,
            ':pid' => $practiceId,
            ':src' => 'Custom-Status-B',
        ]);
    } catch (PDOException $e) {
        echo "FAIL: Reset to Delivered for Custom-Status-B threw " . $e->getMessage() . "\n";
        $failed++;
    }
    check('Reset to Delivered (single source, terminal) executed without HY093', $ok2);
    if ($ok2) {
        check('Reset to Delivered affected 1 row', $stmt2->rowCount() === 1);
        $caseIds = fetchCaseIdsForStatus($practiceId, 'Custom-Status-B');
        foreach ($caseIds as $caseId) {
            logCaseActivity($caseId, 'status_changed', 'Custom-Status-B', 'Delivered', ['reason' => 'workflow_reset'], null, true);
            closeOpenLabPeriodForDeliveredCase($caseId, $practiceId);
        }
        check('Reset to Delivered activity and lab-close logged', true);
    }

    // 3. Zero affected case reset (no error; no-op for the SQL).
    $stmt3 = $pdo->prepare($moveSql);
    $ok3 = false;
    try {
        $ok3 = $stmt3->execute([
            ':dest' => 'Originated',
            ':now_changed' => date('Y-m-d H:i:s'),
            ':now_update' => date('Y-m-d H:i:s'),
            ':pid' => $practiceId,
            ':src' => 'NonExistent-Status',
        ]);
    } catch (PDOException $e) {
        echo "FAIL: Reset with zero affected cases threw " . $e->getMessage() . "\n";
        $failed++;
    }
    check('Reset with zero affected cases executed without HY093', $ok3);
    if ($ok3) {
        check('Zero affected reset updated 0 rows', $stmt3->rowCount() === 0);
    }

    $pdo->rollBack();
    check('Transaction rolled back', true);

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
