<?php
/**
 * Case Review Tracking practice-level setting test.
 *
 * Verifies the practices.case_review_tracking_enabled toggle, the
 * isCaseReviewTrackingEnabled() helper, the update-case-review.php guard,
 * and that the backend review-state helper still functions while the UI
 * feature is disabled (so automatic resets keep review state accurate).
 *
 * Run: php tests/case-review-tracking-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/cases-cache.php';
require_once __DIR__ . '/../api/practice-security.php';

$environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if ($environment === 'production') {
    fwrite(STDERR, "Refusing to run case-review-tracking-test.php in production.\n");
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
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

function fail($message) {
    global $fail;
    $fail++;
    echo "FAIL: {$message}\n";
}

$run = microtime(true) . '-' . mt_rand(1000, 9999);
$userEmail    = "review-toggle-{$run}@example.test";
$practiceName = "Review Toggle Practice {$run}";
$caseId       = "review-toggle-{$run}";

$idsToClean = [
    'users' => [],
    'practices' => [],
    'practice_users' => [],
    'cases' => [],
];

function cleanup($pdo, &$idsToClean) {
    if (!$pdo) return;
    foreach ($idsToClean['cases'] as $cid) {
        $pdo->prepare("DELETE FROM cases_cache WHERE case_id = :case_id")
            ->execute(['case_id' => $cid]);
    }
    foreach ($idsToClean['practice_users'] as $pid) {
        $pdo->prepare("DELETE FROM practice_users WHERE id = :id")
            ->execute(['id' => $pid]);
    }
    foreach ($idsToClean['practices'] as $pid) {
        $pdo->prepare("DELETE FROM practices WHERE id = :id")
            ->execute(['id' => $pid]);
    }
    foreach ($idsToClean['users'] as $uid) {
        $pdo->prepare("DELETE FROM users WHERE id = :id")
            ->execute(['id' => $uid]);
    }
}

try {
    // ---- Ensure the migration has run ----
    assertTrue(
        $pdo->query("SHOW COLUMNS FROM practices LIKE 'case_review_tracking_enabled'")->rowCount() > 0,
        'practices.case_review_tracking_enabled column exists'
    );

    // ---- Setup test user and practice ----
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
        VALUES (:email, :pass, 'email', 'Toggle', 'Test', 'user', 1, 1, NOW())
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), first_name=VALUES(first_name), last_name=VALUES(last_name), is_active=1
    ");
    $stmt->execute([
        'email' => $userEmail,
        'pass' => password_hash('testpass', PASSWORD_BCRYPT),
    ]);
    $userId = (int)$pdo->lastInsertId();
    $idsToClean['users'] = [$userId];

    $practiceUuid = 'rev-toggle-' . $run;
    $stmt = $pdo->prepare("
        INSERT INTO practices (
            practice_id, practice_name, legal_name, display_name, practice_address,
            baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id,
            baa_signer_name, baa_signer_title, created_by,
            subscription_status, trial_ends_at, case_review_tracking_enabled
        ) VALUES (
            :uuid, :name1, :name2, :name3, '123 Test St',
            1, UTC_TIMESTAMP(), 'v1.0-test', :user_id1,
            :signer, 'Test Admin', :user_id2,
            'trialing', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY), 0
        )
    ");
    $stmt->execute([
        'uuid' => $practiceUuid,
        'name1' => $practiceName,
        'name2' => $practiceName,
        'name3' => $practiceName,
        'user_id1' => $userId,
        'user_id2' => $userId,
        'signer' => $userEmail,
    ]);
    $practiceId = (int)$pdo->lastInsertId();
    $idsToClean['practices'] = [$practiceId];

    $stmt = $pdo->prepare("
        INSERT INTO practice_users (practice_id, user_id, role, created_at)
        VALUES (:practice_id, :user_id, 'admin', NOW())
    ");
    $stmt->execute(['practice_id' => $practiceId, 'user_id' => $userId]);
    $idsToClean['practice_users'] = [(int)$pdo->lastInsertId()];

    // ---- Default OFF ----
    assertTrue(
        isCaseReviewTrackingEnabled($practiceId) === false,
        'case review tracking defaults to OFF'
    );

    // ---- Toggle ON ----
    $pdo->prepare("UPDATE practices SET case_review_tracking_enabled = 1 WHERE id = :id")
        ->execute(['id' => $practiceId]);
    assertTrue(
        isCaseReviewTrackingEnabled($practiceId) === true,
        'case review tracking returns true after enabling'
    );

    // ---- Toggle OFF again ----
    $pdo->prepare("UPDATE practices SET case_review_tracking_enabled = 0 WHERE id = :id")
        ->execute(['id' => $practiceId]);
    assertTrue(
        isCaseReviewTrackingEnabled($practiceId) === false,
        'case review tracking returns false after disabling'
    );

    // ---- Backend review-state helper still works while the UI feature is OFF ----
    $caseData = [
        'id' => $caseId,
        'practice_id' => $practiceId,
        'patientFirstName' => 'Test',
        'patientLastName' => 'Patient',
        'patientDOB' => '1990-01-01',
        'patientGender' => 'Female',
        'dentistName' => 'Dr. Test',
        'caseType' => 'Crown',
        'status' => 'Originated',
        'assignedTo' => $userEmail,
        'createdByUserId' => $userId,
    ];
    saveCaseToCache($caseData);
    $idsToClean['cases'] = [$caseId];

    $savedCase = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue(
        $savedCase && $savedCase['id'] === $caseId,
        'test case saved to cache'
    );

    assertTrue(
        updateCaseReviewStatus($caseId, $practiceId, $userId, true) === true,
        'backend review update works while feature flag is OFF'
    );

    $case = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue(
        $case && $case['reviewStatus'] === 'reviewed' && $case['reviewedByUserId'] === $userId,
        'review state persists even when UI feature is OFF'
    );

    // ---- update-case-review.php contains the feature guard ----
    $endpointSource = file_get_contents(__DIR__ . '/../api/update-case-review.php');
    assertTrue(
        strpos($endpointSource, 'isCaseReviewTrackingEnabled') !== false,
        'update-case-review.php checks isCaseReviewTrackingEnabled'
    );
    assertTrue(
        strpos($endpointSource, 'review_tracking_disabled') !== false,
        'update-case-review.php returns a disabled-feature response'
    );

    cleanup($pdo, $idsToClean);
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
} catch (Throwable $e) {
    cleanup($pdo, $idsToClean);
    fwrite(STDERR, "Exception: " . $e->getMessage() . "\n");
    exit(1);
}
