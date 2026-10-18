<?php
/**
 * Organization access foundation tests.
 *
 * Exercises api/organization-access.php (org practice scope resolution) and
 * the shared Scale entitlement gate hasScaleAccess() in
 * api/subscription-access.php against the local development database.
 *
 * Requires the local development database (per AGENTS.md: development
 * environment, MySQL on port 3308). Creates throwaway users/practices/
 * memberships/subscriptions under 'orgaccess-*' test emails and removes
 * them afterwards via FK cascades - no fixture data is left behind.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/organization-access.php';
require_once __DIR__ . '/../api/subscription-access.php';
require_once __DIR__ . '/../api/billing-bypass.php';
require_once __DIR__ . '/../api/plan-entitlements.php';

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
// Org A: ownerA owns A1, A2, A3 (active) + A4 (inactive).
// Org B: ownerB owns B1.
// member1: user membership on A1 only.
// member2: user membership on A1, admin membership on A2, admin on B1
//          (a member spanning two organizations).
// member3: deactivated user with a membership on A1.
// stranger: active user with no memberships at all.

$createdUserIds = [];
$createdPracticeIds = [];

$mkUser = function (string $email, int $active = 1) use ($pdo, &$createdUserIds): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'OrgAccess', 'Test', 'user', :a, 1, NOW())")
        ->execute(['e' => $email, 'a' => $active]);
    $id = (int)$pdo->lastInsertId();
    $createdUserIds[] = $id;
    return $id;
};

$mkPractice = function (string $name, int $createdBy, int $active = 1) use ($pdo, &$createdPracticeIds): int {
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at)
                   VALUES (UUID(), :n, :cb, :a, NOW())")
        ->execute(['n' => $name, 'cb' => $createdBy, 'a' => $active]);
    $id = (int)$pdo->lastInsertId();
    $createdPracticeIds[] = $id;
    return $id;
};

$attach = function (int $practiceId, int $userId, string $role = 'user', int $isOwner = 0) use ($pdo): void {
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, :r, :o)")
        ->execute(['p' => $practiceId, 'u' => $userId, 'r' => $role, 'o' => $isOwner]);
};

require_once __DIR__ . '/../api/subscription-owner.php';

$ownerA   = $mkUser('orgaccess-owner-a@test.local');
$ownerB   = $mkUser('orgaccess-owner-b@test.local');
$member1  = $mkUser('orgaccess-member1@test.local');
$member2  = $mkUser('orgaccess-member2@test.local');
$member3  = $mkUser('orgaccess-member3@test.local', 0); // deactivated
$stranger = $mkUser('orgaccess-stranger@test.local');

$practiceA1 = $mkPractice('OrgAccess A1', $ownerA);
$practiceA2 = $mkPractice('OrgAccess A2', $ownerA);
$practiceA3 = $mkPractice('OrgAccess A3', $ownerA);
$practiceA4 = $mkPractice('OrgAccess A4 inactive', $ownerA, 0);
$practiceB1 = $mkPractice('OrgAccess B1', $ownerB);

$attach($practiceA1, $ownerA, 'admin', 1);
$attach($practiceA2, $ownerA, 'admin', 1);
$attach($practiceA3, $ownerA, 'admin', 1);
$attach($practiceA4, $ownerA, 'admin', 1);
$attach($practiceB1, $ownerB, 'admin', 1);

$attach($practiceA1, $member1, 'user', 0);
$attach($practiceA1, $member2, 'user', 0);
$attach($practiceA2, $member2, 'admin', 0);
$attach($practiceB1, $member2, 'admin', 0);
$attach($practiceA1, $member3, 'user', 0);

getOrCreateSubscriptionForOwner($pdo, $ownerA);
getOrCreateSubscriptionForOwner($pdo, $ownerB);

$setSubscription = function (int $ownerUserId, ?string $plan, string $status, ?string $stripeSubId, ?string $trialEndsAt) use ($pdo): void {
    $pdo->prepare("UPDATE subscriptions
                   SET plan = :plan, status = :status,
                       stripe_subscription_id = :sub, trial_ends_at = :trial
                   WHERE owner_user_id = :owner")
        ->execute([
            'plan'  => $plan,
            'status' => $status,
            'sub'   => $stripeSubId,
            'trial' => $trialEndsAt,
            'owner' => $ownerUserId,
        ]);
};

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
    // 1. Organization owner resolves the whole organization
    // -----------------------------------------------------------------------
    echo "--- organization owner scope ---\n";

    assertEquals('anchor practice resolves org owner', $ownerA,
        getOrganizationOwnerUserId($pdo, $practiceA1));
    assertTrue('owner has organization authority', isOrganizationAuthority($pdo, $ownerA, $practiceA1));
    assertTrue('authority holds from any owned practice anchor', isOrganizationAuthority($pdo, $ownerA, $practiceA3));
    assertTrue('practice admin is not organization authority', !isOrganizationAuthority($pdo, $member2, $practiceA1));
    assertTrue('other org owner is not authority here', !isOrganizationAuthority($pdo, $ownerB, $practiceA1));
    assertTrue('deactivated owner-less member is not authority', !isOrganizationAuthority($pdo, $member1, $practiceA1));

    $ownerScope = getAuthorizedOrganizationPractices($pdo, $ownerA, $practiceA1);
    assertEquals('owner sees all three active org practices',
        [$practiceA1, $practiceA2, $practiceA3], $sortIds($ownerScope));
    assertTrue('inactive owned practice excluded from owner scope',
        !in_array($practiceA4, $sortIds($ownerScope), true));
    assertTrue('other org practice never included',
        !in_array($practiceB1, $sortIds($ownerScope), true));
    assertTrue('owner access marked as organization grant',
        count($ownerScope) === 3 && $ownerScope[0]['access'] === 'organization');
    assertTrue('owner membership permissions preserved (admin/owner)',
        $ownerScope[0]['is_member'] && $ownerScope[0]['role'] === 'admin' && $ownerScope[0]['is_owner']);

    // -----------------------------------------------------------------------
    // 2. Members see only explicitly assigned practices
    // -----------------------------------------------------------------------
    echo "--- member scope ---\n";

    $m1Scope = getAuthorizedOrganizationPracticeIds($pdo, $member1, $practiceA1);
    assertEquals('single-practice member sees only that practice', [$practiceA1], $m1Scope);

    $m2Scope = getAuthorizedOrganizationPractices($pdo, $member2, $practiceA1);
    assertEquals('multi-practice member sees both assigned practices',
        [$practiceA1, $practiceA2], $sortIds($m2Scope));
    $m2a2 = null;
    foreach ($m2Scope as $row) {
        if ((int)$row['id'] === $practiceA2) {
            $m2a2 = $row;
        }
    }
    assertTrue('member scope preserves per-practice role (admin on A2)',
        $m2a2 !== null && $m2a2['role'] === 'admin' && $m2a2['access'] === 'member');
    assertTrue('member scope preserves permission flags',
        $m2a2 !== null && $m2a2['can_edit_cases'] === true && $m2a2['is_owner'] === false);

    // -----------------------------------------------------------------------
    // 3. Denials: unassigned, other-organization, manipulated IDs
    // -----------------------------------------------------------------------
    echo "--- denial cases ---\n";

    assertTrue('unassigned org practice denied', !userCanAccessOrganizationPractice($pdo, $member1, $practiceA1, $practiceA2));
    assertTrue('other-organization practice denied', !userCanAccessOrganizationPractice($pdo, $member1, $practiceA1, $practiceB1));
    assertTrue('non-member denied entirely',
        getAuthorizedOrganizationPracticeIds($pdo, $stranger, $practiceA1) === []);
    assertTrue('deactivated member gets empty scope',
        getAuthorizedOrganizationPracticeIds($pdo, $member3, $practiceA1) === []);
    assertTrue('member of org A cannot reach org B practices',
        getAuthorizedOrganizationPracticeIds($pdo, $member1, $practiceA1) === [$practiceA1]
        && !userCanAccessOrganizationPractice($pdo, $member1, $practiceA1, $practiceB1));

    // Anchor isolation: when member2's session sits in org B's practice, only
    // org B is in scope - their org A memberships must not leak in.
    assertEquals('anchor in org B scopes to org B only',
        [$practiceB1], getAuthorizedOrganizationPracticeIds($pdo, $member2, $practiceB1));
    assertTrue('org A practice denied while anchored in org B',
        !userCanAccessOrganizationPractice($pdo, $member2, $practiceB1, $practiceA1));

    // Manipulated/invalid request IDs are rejected before any lookup.
    assertTrue('non-numeric practice id denied',
        !userCanAccessOrganizationPractice($pdo, $ownerA, $practiceA1, 'abc'));
    assertTrue('sql-fragment practice id denied',
        !userCanAccessOrganizationPractice($pdo, $ownerA, $practiceA1, "{$practiceA1} OR 1=1"));
    assertTrue('zero/negative practice id denied',
        !userCanAccessOrganizationPractice($pdo, $ownerA, $practiceA1, 0)
        && !userCanAccessOrganizationPractice($pdo, $ownerA, $practiceA1, -5));
    assertTrue('numeric-string id resolves normally',
        userCanAccessOrganizationPractice($pdo, $ownerA, $practiceA1, (string)$practiceA2));
    assertTrue('empty anchor/user denies', getAuthorizedOrganizationPracticeIds($pdo, 0, $practiceA1) === []
        && getAuthorizedOrganizationPracticeIds($pdo, $ownerA, 0) === []);

    // Session convenience wrapper mirrors the PDO resolver.
    $_SESSION['db_user_id'] = $member2;
    $_SESSION['current_practice_id'] = $practiceA1;
    assertEquals('session scope matches PDO resolver',
        [$practiceA1, $practiceA2], $sortIds(getSessionAuthorizedOrgPracticeIds($pdo)));
    unset($_SESSION['db_user_id'], $_SESSION['current_practice_id']);
    assertTrue('no session context -> empty scope', getSessionAuthorizedOrgPracticeIds($pdo) === []);

    // -----------------------------------------------------------------------
    // 4. Cross-practice query scoping fragment
    // -----------------------------------------------------------------------
    echo "--- scope SQL fragment ---\n";

    $clause = buildAuthorizedPracticeScopeSql([$practiceA1, $practiceA3], 'c.practice_id');
    assertTrue('scope clause builds IN list',
        strpos($clause, 'c.practice_id IN (') === 0
        && strpos($clause, (string)$practiceA1) !== false
        && strpos($clause, (string)$practiceA3) !== false);
    assertEquals('empty scope is a deny-all fragment', '1 = 0', buildAuthorizedPracticeScopeSql([], 'c.practice_id'));
    assertTrue('non-numeric ids filtered out',
        buildAuthorizedPracticeScopeSql(['x', $practiceA1], 'practice_id') === "practice_id IN ({$practiceA1})");
    $threw = false;
    try {
        buildAuthorizedPracticeScopeSql([$practiceA1], 'practice_id; DROP TABLE users');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrue('malicious column rejected', $threw);

    // -----------------------------------------------------------------------
    // 5. Scale entitlement vs non-Scale
    // -----------------------------------------------------------------------
    echo "--- hasScaleAccess ---\n";
    // Local dev sets BILLING_ENABLED=true in .env; guard so the assertions
    // only run when billing is actually enabled here.
    $billingOn = filter_var(getenv('BILLING_ENABLED') ?: ($_ENV['BILLING_ENABLED'] ?? ''), FILTER_VALIDATE_BOOLEAN);

    if (!$billingOn) {
        echo "NOTE: BILLING_ENABLED is off - all entitlement checks resolve true by design\n";
    } else {
        // Active Scale subscription -> entitled.
        $setSubscription($ownerA, 'scale', 'active', 'sub_oa_scale', null);
        assertTrue('scale active -> entitled', hasScaleAccess($pdo, $practiceA1));
        assertTrue('scale also meets control tier', hasControlAccess($pdo, $practiceA1));

        // Lower tiers -> not entitled.
        $setSubscription($ownerA, 'control', 'active', 'sub_oa_scale', null);
        assertTrue('control active -> not scale entitled', !hasScaleAccess($pdo, $practiceA1));
        assertTrue('control still meets control tier', hasControlAccess($pdo, $practiceA1));
        $setSubscription($ownerA, 'operate', 'active', 'sub_oa_scale', null);
        assertTrue('operate active -> not scale entitled', !hasScaleAccess($pdo, $practiceA1));

        // Existing trial/grace-period behavior is preserved: an active
        // DentaTrak/Stripe trial grants the entitlement regardless of plan.
        $setSubscription($ownerA, null, 'trialing', null, date('Y-m-d H:i:s', time() + 86400));
        assertTrue('trialing -> entitled (trial behavior)', hasScaleAccess($pdo, $practiceA1));
        $setSubscription($ownerA, 'scale', 'past_due', 'sub_oa_scale', null);
        assertTrue('past_due scale -> entitled (grace period)', hasScaleAccess($pdo, $practiceA1));

        // Lapsed subscription -> not entitled.
        $setSubscription($ownerA, 'scale', 'canceled', null, '2020-01-01 00:00:00');
        assertTrue('canceled scale -> not entitled', !hasScaleAccess($pdo, $practiceA1));

        // Entitlement is subscription-level, not per-user: a member of an
        // entitled org practice resolves the same result, a member of a
        // non-entitled org does not.
        $setSubscription($ownerA, 'scale', 'active', 'sub_oa_scale', null);
        $setSubscription($ownerB, 'operate', 'active', 'sub_ob_op', null);
        assertTrue('member resolves org scale entitlement via anchor',
            hasScaleAccess($pdo, $practiceA1, 'orgaccess-member1@test.local'));
        assertTrue('non-scale org not entitled', !hasScaleAccess($pdo, $practiceB1));

        // Billing-disabled master gate: everyone entitled (matches
        // hasControlAccess convention).
        $_ENV['BILLING_ENABLED'] = 'false';
        assertTrue('billing disabled -> entitled', hasScaleAccess($pdo, $practiceB1));
        $_ENV['BILLING_ENABLED'] = 'true';
    }

    // Included-practice entitlement: Scale includes 5 in base price.
    assertEquals('scale includes 5 practices', 5, getIncludedPracticeCount('scale'));
    assertEquals('operate includes 1 practice', 1, getIncludedPracticeCount('operate'));
    assertEquals('control includes 2 practices', 2, getIncludedPracticeCount('control'));
    assertTrue('scale owned-practice cap is uncapped (add-on billing applies)',
        getMaxOwnedPractices('scale') === null);

} finally {
    // -----------------------------------------------------------------------
    // Cleanup (FK cascades remove practice_users and subscriptions)
    // -----------------------------------------------------------------------
    if (!empty($createdPracticeIds)) {
        $pdo->exec("DELETE FROM practices WHERE id IN (" . implode(',', array_map('intval', $createdPracticeIds)) . ")");
    }
    if (!empty($createdUserIds)) {
        $pdo->exec("DELETE FROM practice_users WHERE user_id IN (" . implode(',', array_map('intval', $createdUserIds)) . ")");
        $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $createdUserIds)) . ")");
    }
    unset($_SESSION['db_user_id'], $_SESSION['current_practice_id']);
}

echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
