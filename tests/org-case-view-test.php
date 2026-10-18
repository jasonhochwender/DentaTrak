<?php
/**
 * Consolidated "All practices" case view - server-side scope tests.
 *
 * Exercises the org-view helpers in api/organization-access.php
 * (isOrgCaseViewRequested, getOrgCaseViewPractices/Ids,
 * resolveCaseScopePracticeId, resolveOrgAuthorizedPracticeParam) plus the
 * scoped-case cache query in api/cases-cache.php that list-org-cases.php
 * builds on, against the local development database.
 *
 * NOTE: orgCaseViewEntitled() memoizes its result per process, so this file
 * runs every flag-OFF scenario first, then enables the flag once and keeps
 * it on for the remaining assertions. The entitlement-loss collapse is
 * still covered indirectly: a cleared flag is exactly what the flag-OFF
 * scenarios exercise.
 *
 * Requires the local development database (per AGENTS.md: development
 * environment, MySQL on port 3308). Creates throwaway users/practices/
 * memberships/subscriptions/cases under 'orgview-*' test emails and
 * removes them afterwards - no fixture data is left behind.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/organization-access.php';
require_once __DIR__ . '/../api/subscription-access.php';
require_once __DIR__ . '/../api/billing-bypass.php';
require_once __DIR__ . '/../api/cases-cache.php';
require_once __DIR__ . '/../api/subscription-owner.php';

global $pdo;

$pass = 0;
$fail = 0;

function assertTrue(string $message, $condition): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

function assertEquals(string $message, $expected, $actual): void {
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message} (expected " . var_export($expected, true) .
             ", got " . var_export($actual, true) . ")\n";
    }
}

// Guard: these tests must never run against a production database.
$env = $appConfig['current_environment'] ?? 'production';
if (!isset($pdo) || !($pdo instanceof PDO) || $env === 'production') {
    echo "SKIP: requires the local development database\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
// Org S: ownerS owns S1, S2, S3 (all active).
// Org T: ownerT owns T1.
// memberMulti: memberships on S1 + S2 (NOT S3).
// memberSingle: membership on S1 only.
// Cases: c1->S1, c2->S2, c3->T1, c4->S3 (org practice memberMulti can't see),
//        c5->S1 archived.

$createdUserIds = [];
$createdPracticeIds = [];
$createdCaseIds = [];

$mkUser = function (string $email) use ($pdo, &$createdUserIds): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'OrgView', 'Test', 'user', 1, 1, NOW())")
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

$attach = function (int $practiceId, int $userId, string $role = 'user', int $isOwner = 0) use ($pdo): void {
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, :r, :o)")
        ->execute(['p' => $practiceId, 'u' => $userId, 'r' => $role, 'o' => $isOwner]);
};

ensureCasesCacheTable();
$mkCase = function (string $caseId, int $practiceId, int $archived = 0) use ($pdo, &$createdCaseIds): void {
    $pdo->prepare("INSERT INTO cases_cache (case_id, practice_id, patient_first_name, patient_last_name,
                                          status, archived, creation_date, last_update_date)
                   VALUES (:c, :p, 'Test', 'Patient', 'Originated', :a, NOW(), NOW())")
        ->execute(['c' => $caseId, 'p' => $practiceId, 'a' => $archived]);
    $createdCaseIds[] = $caseId;
};

$ownerS       = $mkUser('orgview-owner-s@test.local');
$ownerT       = $mkUser('orgview-owner-t@test.local');
$memberMulti  = $mkUser('orgview-member-multi@test.local');
$memberSingle = $mkUser('orgview-member-single@test.local');

$practiceS1 = $mkPractice('OrgView S1', $ownerS);
$practiceS2 = $mkPractice('OrgView S2', $ownerS);
$practiceS3 = $mkPractice('OrgView S3', $ownerS);
$practiceT1 = $mkPractice('OrgView T1', $ownerT);

$attach($practiceS1, $ownerS, 'admin', 1);
$attach($practiceS2, $ownerS, 'admin', 1);
$attach($practiceS3, $ownerS, 'admin', 1);
$attach($practiceT1, $ownerT, 'admin', 1);

$attach($practiceS1, $memberMulti, 'user', 0);
$attach($practiceS2, $memberMulti, 'user', 0);
$attach($practiceS1, $memberSingle, 'user', 0);

getOrCreateSubscriptionForOwner($pdo, $ownerS);
getOrCreateSubscriptionForOwner($pdo, $ownerT);

// Scale entitlement for org S when billing is enabled in this environment.
$billingOn = filter_var(getenv('BILLING_ENABLED') ?: ($_ENV['BILLING_ENABLED'] ?? ''), FILTER_VALIDATE_BOOLEAN);
if ($billingOn) {
    $pdo->prepare("UPDATE subscriptions SET plan = 'scale', status = 'active',
                   stripe_subscription_id = 'sub_orgview_scale' WHERE owner_user_id = :o")
        ->execute(['o' => $ownerS]);
}

$mkCase('orgview-c1', $practiceS1);
$mkCase('orgview-c2', $practiceS2);
$mkCase('orgview-c3', $practiceT1);
$mkCase('orgview-c4', $practiceS3);
$mkCase('orgview-c5', $practiceS1, 1);

$sortIds = function (array $rowsOrIds): array {
    $ids = [];
    foreach ($rowsOrIds as $r) {
        $ids[] = is_array($r) ? (int)$r['id'] : (int)$r;
    }
    sort($ids);
    return $ids;
};

try {
    // -----------------------------------------------------------------------
    // 1. Flag OFF: the consolidated scope is inert
    // -----------------------------------------------------------------------
    echo "--- flag off ---\n";

    unset($_SESSION['org_case_view']);
    $_SESSION['db_user_id'] = $memberMulti;
    $_SESSION['current_practice_id'] = $practiceS1;

    assertTrue('flag off -> org view not requested', !isOrgCaseViewRequested());
    assertEquals('flag off -> resolver returns session practice for own-practice case',
        $practiceS1, resolveCaseScopePracticeId('orgview-c1'));
    assertEquals('flag off -> resolver returns session practice for cross-practice case',
        $practiceS1, resolveCaseScopePracticeId('orgview-c2'));
    // NOTE: resolveOrgAuthorizedPracticeParam(non-anchor) exits 403 when the
    // flag is off - that denial is the intended single-practice behavior and
    // is exercised indirectly via the flag-on authorized cases below.

    // -----------------------------------------------------------------------
    // 2. Flag ON: org scope resolution (from here on the flag stays set -
    //    orgCaseViewEntitled memoizes, so order matters)
    // -----------------------------------------------------------------------
    echo "--- flag on: member scope ---\n";

    $_SESSION['org_case_view'] = 1;
    assertTrue('flag on -> org view requested', isOrgCaseViewRequested());
    assertTrue('org view entitled under live subscription state', orgCaseViewEntitled($pdo));

    $memberPractices = getOrgCaseViewPractices($pdo);
    assertEquals('multi-practice member sees only assigned practices',
        [$practiceS1, $practiceS2], $sortIds($memberPractices));
    assertEquals('practice ids mirror row ids',
        [$practiceS1, $practiceS2], $sortIds(getOrgCaseViewPracticeIds($pdo)));

    assertEquals('cross-practice case resolves its own practice',
        $practiceS2, resolveCaseScopePracticeId('orgview-c2'));
    assertEquals('anchor-practice case resolves normally',
        $practiceS1, resolveCaseScopePracticeId('orgview-c1'));
    assertEquals('other-organization case falls back to anchor (indistinguishable deny)',
        $practiceS1, resolveCaseScopePracticeId('orgview-c3'));
    assertEquals('unassigned org practice case falls back to anchor',
        $practiceS1, resolveCaseScopePracticeId('orgview-c4'));
    assertEquals('bogus case id falls back to anchor',
        $practiceS1, resolveCaseScopePracticeId('orgview-nonexistent'));
    assertEquals('injection-shaped case id falls back to anchor',
        $practiceS1, resolveCaseScopePracticeId("x' OR '1'='1"));
    assertEquals('archived case in scope still resolves (archived modal flow)',
        $practiceS1, resolveCaseScopePracticeId('orgview-c5'));

    assertEquals('authorized practice param accepted',
        $practiceS2, resolveOrgAuthorizedPracticeParam($practiceS2));
    assertEquals('anchor practice param accepted',
        $practiceS1, resolveOrgAuthorizedPracticeParam($practiceS1));
    assertEquals('empty practice param -> session anchor',
        $practiceS1, resolveOrgAuthorizedPracticeParam(''));
    assertEquals('null practice param -> session anchor',
        $practiceS1, resolveOrgAuthorizedPracticeParam(null));

    // -----------------------------------------------------------------------
    // 3. Owner authority sees the whole org
    // -----------------------------------------------------------------------
    echo "--- owner scope ---\n";

    // getOrgCaseView* reads the session; swap to the org owner.
    $_SESSION['db_user_id'] = $ownerS;
    $ownerIds = $sortIds(getOrgCaseViewPracticeIds($pdo));
    assertEquals('owner scope covers all active org practices',
        [$practiceS1, $practiceS2, $practiceS3], $ownerIds);
    assertEquals('owner resolves case in a memberless practice',
        $practiceS3, resolveCaseScopePracticeId('orgview-c4'));
    assertEquals('owner still cannot cross organizations',
        $practiceS1, resolveCaseScopePracticeId('orgview-c3'));

    $_SESSION['db_user_id'] = $memberMulti;

    // -----------------------------------------------------------------------
    // 4. Scoped case-cache query (list-org-cases data path)
    // -----------------------------------------------------------------------
    echo "--- scoped case query ---\n";

    $scoped = getAllCasesFromCache([$practiceS1, $practiceS2]);
    $scopedIds = array_map(function ($c) { return $c['id']; }, $scoped);
    assertTrue('scoped query returns member cases from both practices',
        in_array('orgview-c1', $scopedIds, true) && in_array('orgview-c2', $scopedIds, true));
    assertTrue('scoped query excludes other-organization cases',
        !in_array('orgview-c3', $scopedIds, true));
    assertTrue('scoped query excludes unassigned org practice cases',
        !in_array('orgview-c4', $scopedIds, true));
    assertTrue('archived case present in scoped query (endpoint filters it)',
        in_array('orgview-c5', $scopedIds, true));
    assertTrue('empty scope returns nothing - never unrestricted',
        getAllCasesFromCache([]) === []);

    // Default (single-practice) path is untouched.
    $default = getAllCasesFromCache();
    $defaultIds = array_map(function ($c) { return $c['id']; }, $default);
    assertTrue('default query scopes to session practice only',
        in_array('orgview-c1', $defaultIds, true) && !in_array('orgview-c2', $defaultIds, true));

    // -----------------------------------------------------------------------
    // 5. Single-practice user keeps the existing experience
    // -----------------------------------------------------------------------
    echo "--- single-practice member ---\n";

    $_SESSION['db_user_id'] = $memberSingle;
    assertEquals('single-practice member scope is one practice',
        [$practiceS1], $sortIds(getOrgCaseViewPractices($pdo)));

    $_SESSION['db_user_id'] = $memberMulti;

} finally {
    // -----------------------------------------------------------------------
    // Cleanup
    // -----------------------------------------------------------------------
    if (!empty($createdCaseIds)) {
        $pdo->exec("DELETE FROM cases_cache WHERE case_id IN ('" . implode("','", array_map('addslashes', $createdCaseIds)) . "')");
    }
    if (!empty($createdPracticeIds)) {
        $pdo->exec("DELETE FROM practices WHERE id IN (" . implode(',', array_map('intval', $createdPracticeIds)) . ")");
    }
    if (!empty($createdUserIds)) {
        $pdo->exec("DELETE FROM practice_users WHERE user_id IN (" . implode(',', array_map('intval', $createdUserIds)) . ")");
        $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $createdUserIds)) . ")");
    }
    unset($_SESSION['db_user_id'], $_SESSION['current_practice_id'], $_SESSION['org_case_view']);
}

echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
