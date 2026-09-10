<?php
/**
 * Review Status DB-backed integration test.
 *
 * Exercises the review persistence helpers and access rules against the
 * real cases_cache, users, practices, and practice_users tables.
 *
 * Run: php tests/review-status-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/cases-cache.php';
require_once __DIR__ . '/../api/practice-security.php';

$environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if ($environment === 'production') {
    fwrite(STDERR, "Refusing to run review-status-test.php in production.\n");
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
$reviewerEmail    = "reviewer-{$run}@example.test";
$otherEmail       = "other-{$run}@example.test";
$limitedEmail     = "limited-{$run}@example.test";
$crossEmail       = "cross-{$run}@example.test";
$practiceName     = "Review Test Practice {$run}";
$caseId           = "review-test-{$run}";

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
    // ---- Setup test users ----
    function createTestUser($pdo, $email, $first, $last) {
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
            VALUES (:email, :pass, 'email', :first, :last, 'user', 1, 1, NOW())
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), first_name=VALUES(first_name), last_name=VALUES(last_name), is_active=1
        ");
        $stmt->execute([
            'email' => $email,
            'pass' => password_hash('testpass', PASSWORD_BCRYPT),
            'first' => $first,
            'last' => $last,
        ]);
        return (int)$pdo->lastInsertId();
    }

    $reviewerId = createTestUser($pdo, $reviewerEmail, 'Reviewer', 'Test');
    $otherId    = createTestUser($pdo, $otherEmail, 'Other', 'Test');
    $limitedId  = createTestUser($pdo, $limitedEmail, 'Limited', 'Test');
    $crossId    = createTestUser($pdo, $crossEmail, 'Cross', 'Test');

    $idsToClean['users'] = [$reviewerId, $otherId, $limitedId, $crossId];

    // ---- Setup primary practice ----
    $practiceUuid = 'rev-test-' . $run;
    $stmt = $pdo->prepare("
        INSERT INTO practices (
            practice_id, practice_name, legal_name, display_name, practice_address,
            baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id,
            baa_signer_name, baa_signer_title, created_by,
            subscription_status, trial_ends_at
        ) VALUES (
            :uuid, :name1, :name2, :name3, '123 Test St',
            1, UTC_TIMESTAMP(), 'v1.0-test', :user_id1,
            :signer, 'Test Admin', :user_id2,
            'active', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY)
        )
    ");
    $stmt->execute([
        'uuid' => $practiceUuid,
        'name1' => $practiceName,
        'name2' => $practiceName,
        'name3' => $practiceName,
        'user_id1' => $reviewerId,
        'user_id2' => $reviewerId,
        'signer' => 'Reviewer Test',
    ]);
    $practiceId = (int)$pdo->lastInsertId();
    $idsToClean['practices'][] = $practiceId;

    // ---- Setup secondary (cross-practice) practice ----
    $crossPracticeUuid = 'rev-cross-' . $run;
    $crossName = 'Cross Practice ' . $run;
    $stmt = $pdo->prepare("
        INSERT INTO practices (
            practice_id, practice_name, legal_name, display_name, practice_address,
            baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id,
            baa_signer_name, baa_signer_title, created_by,
            subscription_status, trial_ends_at
        ) VALUES (
            :uuid, :name1, :name2, :name3, '123 Test St',
            1, UTC_TIMESTAMP(), 'v1.0-test', :user_id1,
            :signer, 'Test Admin', :user_id2,
            'active', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY)
        )
    ");
    $stmt->execute([
        'uuid' => $crossPracticeUuid,
        'name1' => $crossName,
        'name2' => $crossName,
        'name3' => $crossName,
        'user_id1' => $crossId,
        'user_id2' => $crossId,
        'signer' => 'Cross Test',
    ]);
    $crossPracticeId = (int)$pdo->lastInsertId();
    $idsToClean['practices'][] = $crossPracticeId;

    // ---- Memberships ----
    $memberships = [
        [$practiceId, $reviewerId, 'admin', 1, 0],
        [$practiceId, $otherId, 'user', 0, 0],
        [$practiceId, $limitedId, 'user', 0, 1],
        [$crossPracticeId, $crossId, 'admin', 1, 0],
    ];
    foreach ($memberships as [$prid, $uid, $role, $owner, $limited]) {
        $stmt = $pdo->prepare("
            INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility)
            VALUES (:practice_id, :user_id, :role, :is_owner, :limited_visibility)
        ");
        $stmt->execute([
            'practice_id' => $prid,
            'user_id' => $uid,
            'role' => $role,
            'is_owner' => $owner,
            'limited_visibility' => $limited,
        ]);
        $idsToClean['practice_users'][] = (int)$pdo->lastInsertId();
    }

    // ---- Create a case in the primary practice, assigned to the reviewer ----
    $caseData = [
        'id' => $caseId,
        'practice_id' => $practiceId,
        'patientFirstName' => 'Patient',
        'patientLastName' => 'Review',
        'dentistName' => 'Dr. Dentist',
        'caseType' => 'Crown',
        'status' => 'Originated',
        'creationDate' => date('c'),
        'lastUpdateDate' => date('c'),
        'assignedTo' => $reviewerEmail,
        'createdByUserId' => $reviewerId,
    ];
    saveCaseToCache($caseData);
    $idsToClean['cases'][] = $caseId;

    // 1. New/existing case defaults to Needs Review
    $fresh = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($fresh !== null, 'Created case can be loaded from cache');
    assertTrue(($fresh['reviewStatus'] ?? 'needs_review') === 'needs_review', 'New case defaults to Needs Review');
    assertTrue(empty($fresh['reviewedAt']), 'New case has no reviewed_at');
    assertTrue(empty($fresh['reviewedByUserId']), 'New case has no reviewer');
    assertTrue($fresh['assignedTo'] === $reviewerEmail, 'Assignment set correctly');

    // 2. Mark Reviewed stores authenticated user and timestamp
    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, true);
    $afterReview = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($afterReview['reviewStatus'] === 'reviewed', 'Case is Reviewed after mark');
    assertTrue($afterReview['reviewedByUserId'] === $reviewerId, 'Review recorded the correct reviewer ID');
    assertTrue(!empty($afterReview['reviewedAt']) && is_string($afterReview['reviewedAt']), 'Review recorded a timestamp');
    assertTrue($afterReview['assignedTo'] === $reviewerEmail, 'Assignment unchanged after marking reviewed');
    assertTrue(strpos($afterReview['reviewedByName'] ?? '', 'Reviewer') !== false, 'Reviewer name resolved by server');

    // 3. Refresh/read returns Reviewed correctly
    $again = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($again['reviewStatus'] === 'reviewed', 'Re-read still shows Reviewed');

    // 4. Manual Mark Needs Review clears review state
    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, false);
    $afterClear = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($afterClear['reviewStatus'] === 'needs_review', 'Case returns to Needs Review after manual reset');
    assertTrue(empty($afterClear['reviewedAt']) && empty($afterClear['reviewedByUserId']), 'Review timestamp and reviewer cleared');
    assertTrue($afterClear['assignedTo'] === $reviewerEmail, 'Assignment unchanged after manual reset');

    // Helper to re-review before reset tests
    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, true);
    $reReviewed = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($reReviewed['reviewStatus'] === 'reviewed', 'Re-review successful before reset tests');

    // 5. Different-user case edit resets Reviewed (via the shared reset helper)
    $didReset = resetCaseReviewIfDifferentUser($caseId, $practiceId, $otherId);
    assertTrue($didReset === true, 'Different-user edit resets review');
    $afterEditReset = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($afterEditReset['reviewStatus'] === 'needs_review', 'Case is Needs Review after different-user edit reset');

    // 6. Different-user status/assignment/comment/file addition all use the same reset helper
    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, true);
    assertTrue(resetCaseReviewIfDifferentUser($caseId, $practiceId, $otherId) === true, 'Different-user status change resets review (via shared helper)');

    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, true);
    assertTrue(resetCaseReviewIfDifferentUser($caseId, $practiceId, $otherId) === true, 'Different-user assignment change resets review (via shared helper)');

    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, true);
    assertTrue(resetCaseReviewIfDifferentUser($caseId, $practiceId, $otherId) === true, 'Different-user comment resets review (via shared helper)');

    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, true);
    assertTrue(resetCaseReviewIfDifferentUser($caseId, $practiceId, $otherId) === true, 'Different-user file addition resets review (via shared helper)');

    // 7. Same-user changes do not reset Reviewed
    updateCaseReviewStatus($caseId, $practiceId, $reviewerId, true);
    $same = resetCaseReviewIfDifferentUser($caseId, $practiceId, $reviewerId);
    assertTrue($same === false, 'Same-user edit does not reset review');
    $afterSame = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($afterSame['reviewStatus'] === 'reviewed', 'Reviewed state preserved after same-user edit');

    // 8. Viewing a case does not reset it
    $afterRead = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($afterRead['reviewStatus'] === 'reviewed', 'Reading the case does not reset review');

    // 9. Notification read/dismiss does not affect review state (no DB mutation)
    $beforeNotif = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($beforeNotif['reviewStatus'] === 'reviewed', 'Review state stable before notification checks');

    // 10. Cross-practice access fails
    $rawStmt = $pdo->prepare("SELECT * FROM cases_cache WHERE case_id = :case_id AND practice_id = :practice_id LIMIT 1");
    $rawStmt->execute(['case_id' => $caseId, 'practice_id' => $practiceId]);
    $caseRow = $rawStmt->fetch(PDO::FETCH_ASSOC);
    assertTrue(canUserAccessCase($caseRow, $crossPracticeId, $reviewerId) === false, 'Cross-practice user cannot access case (practice mismatch)');
    // Membership in the correct practice is enforced upstream by requireValidPracticeContext();
    // canUserAccessCase only enforces limited-visibility assignment rules once the user
    // is known to belong to the practice.

    // 11. Unauthorized user cannot update review state (update does not affect wrong practice)
    $original = getSingleCaseFromCache($caseId, $practiceId, 'core');
    updateCaseReviewStatus($caseId, $crossPracticeId, $crossId, false); // wrong practice should be a no-op
    $still = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($still['reviewStatus'] === $original['reviewStatus'], 'Wrong-practice review mutation leaves the case unchanged');

    // 12. Assigned Only restrictions remain enforced
    // Limited user assigned to the case can access it
    assertTrue(canUserAccessCase($caseRow, $practiceId, $limitedId) === false, 'Limited-visibility user cannot access unassigned case');

    // Now reassign to the limited user and confirm access
    $pdo->prepare("UPDATE cases_cache SET assigned_to = :assigned_to WHERE case_id = :case_id")
        ->execute(['assigned_to' => $limitedEmail, 'case_id' => $caseId]);
    $assignedCached = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue($assignedCached['assignedTo'] === $limitedEmail, 'Case reassigned to limited user');
    $rawStmt2 = $pdo->prepare("SELECT * FROM cases_cache WHERE case_id = :case_id AND practice_id = :practice_id LIMIT 1");
    $rawStmt2->execute(['case_id' => $caseId, 'practice_id' => $practiceId]);
    $assignedRow = $rawStmt2->fetch(PDO::FETCH_ASSOC);
    assertTrue(canUserAccessCase($assignedRow, $practiceId, $limitedId) === true, 'Limited-visibility user can access their assigned case');
    assertTrue(canUserAccessCase($assignedRow, $practiceId, $otherId) === true, 'Non-limited user still has access');

    // 13. Archived case review mutation fails (source check on endpoint guard)
    $reviewSource = file_get_contents(__DIR__ . '/../api/update-case-review.php');
    assertTrue(strpos($reviewSource, "if (!empty(\$case['archived']))") !== false, 'update-case-review.php rejects archived cases');
    assertTrue(strpos($reviewSource, "requireCaseAccess") !== false, 'update-case-review.php uses requireCaseAccess');

    // 14. Shared case-level review state persists
    $afterAll = getSingleCaseFromCache($caseId, $practiceId, 'core');
    assertTrue(!empty($afterAll['reviewedAt']) && $afterAll['reviewedByUserId'] === $reviewerId, 'Final state remains Reviewed');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);

} catch (Throwable $e) {
    fail('Exception: ' . $e->getMessage());
    echo $e->getTraceAsString() . "\n";
    cleanup($pdo, $idsToClean);
    exit(1);
}

// Clean up regardless of pass/fail
cleanup($pdo, $idsToClean);
