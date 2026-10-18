<?php
/**
 * Administrative plan-override and platform-admin entitlement tests.
 *
 * Exercises api/plan-overrides.php plus the entitlement gates that consume
 * it: hasPlanTierAccess() (via hasControlAccess/hasScaleAccess in
 * api/subscription-access.php) and evaluatePracticeCreationEntitlement()
 * in api/plan-entitlements.php.
 *
 * Coverage:
 *  - super users (SUPER_USERS config - internal platform admins) are never
 *    plan-gated, while ordinary users on the same subscription are;
 *  - an owner-level override changes the effective plan for every practice
 *    the owner owns (organization scope), never writes to subscriptions;
 *  - override clears restore the purchased plan;
 *  - overrides govern even during trial so admins can verify each tier;
 *  - downgrades gate features without deleting data, and the existing
 *    over-limit rule blocks creating additional owned practices.
 *
 * Requires the local development database (per AGENTS.md: development
 * environment, MySQL on port 3308). Creates throwaway fixtures under
 * 'planoverride-*' test emails and removes them afterwards.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/subscription-access.php';
require_once __DIR__ . '/../api/subscription-owner.php';
require_once __DIR__ . '/../api/billing-bypass.php';
require_once __DIR__ . '/../api/plan-entitlements.php';
require_once __DIR__ . '/../api/plan-overrides.php';
require_once __DIR__ . '/../api/dev-tools-access.php';

global $pdo, $appConfig;

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

// Entitlement checks only gate when billing is enabled (master switch).
putenv('BILLING_ENABLED=true');
$_ENV['BILLING_ENABLED'] = 'true';

// Temporary platform-admin identity: internal staff use SUPER_USERS config,
// so a throwaway email is added for the duration of this test only.
$adminEmail = 'planoverride-admin@test.local';
$previousSuperUsers = $appConfig['super_users'] ?? [];
$appConfig['super_users'] = array_merge($previousSuperUsers, [$adminEmail]);

$createdUserIds = [];
$createdPracticeIds = [];

$mkUser = function (string $email) use ($pdo, &$createdUserIds): int {
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'PlanOverride', 'Test', 'user', 1, 1, NOW())")
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

$setSubscription = function (int $ownerUserId, ?string $plan, string $status, ?string $stripeSubId, ?string $trialEndsAt) use ($pdo): void {
    $pdo->prepare("UPDATE subscriptions
                   SET plan = :plan, status = :status,
                       stripe_subscription_id = :sub, trial_ends_at = :trial
                   WHERE owner_user_id = :owner")
        ->execute([
            'plan' => $plan, 'status' => $status,
            'sub' => $stripeSubId, 'trial' => $trialEndsAt,
            'owner' => $ownerUserId,
        ]);
};

try {
    // Org A: ownerA owns A1, A2, A3. memberA is a plain member of A1.
    // Org B: ownerB owns B1 only.
    $ownerA  = $mkUser('planoverride-owner-a@test.local');
    $ownerB  = $mkUser('planoverride-owner-b@test.local');
    $memberA = $mkUser('planoverride-member-a@test.local');
    $adminId = $mkUser($adminEmail);

    $practiceA1 = $mkPractice('PlanOverride A1', $ownerA);
    $practiceA2 = $mkPractice('PlanOverride A2', $ownerA);
    $practiceA3 = $mkPractice('PlanOverride A3', $ownerA);
    $practiceB1 = $mkPractice('PlanOverride B1', $ownerB);

    $attach($practiceA1, $ownerA, 'admin', 1);
    $attach($practiceA2, $ownerA, 'admin', 1);
    $attach($practiceA3, $ownerA, 'admin', 1);
    $attach($practiceB1, $ownerB, 'admin', 1);
    $attach($practiceA1, $memberA, 'user', 0);

    getOrCreateSubscriptionForOwner($pdo, $ownerA);
    getOrCreateSubscriptionForOwner($pdo, $ownerB);

    // -------------------------------------------------------------------
    echo "--- platform administrator entitlement ---\n";

    // Customer on Control: member has Control features but not Scale.
    $setSubscription($ownerA, 'control', 'active', 'sub_po_a', null);
    assertTrue('control member meets control tier',
        hasControlAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));
    assertTrue('control member does not meet scale tier',
        !hasScaleAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));

    // Platform admin on the exact same subscription is never plan-gated.
    assertTrue('super user passes scale gate on control subscription',
        hasScaleAccess($pdo, $practiceA1, $adminEmail));
    assertTrue('super user passes control gate',
        hasControlAccess($pdo, $practiceA1, $adminEmail));
    assertTrue('super user passes integration gate',
        hasIntegrationAccess($pdo, $practiceA1, $adminEmail));

    // Unrestricted means unrestricted - even a lapsed subscription does not
    // block internal staff from plan-gated features (membership checks still
    // decide which practices/cases they can open).
    $setSubscription($ownerA, 'scale', 'canceled', null, '2020-01-01 00:00:00');
    assertTrue('super user entitled even on canceled subscription',
        hasScaleAccess($pdo, $practiceA1, $adminEmail));
    assertTrue('ordinary member not entitled on canceled subscription',
        !hasScaleAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));

    // Owner-level practice quota does not constrain internal staff.
    $setSubscription($ownerA, 'operate', 'active', 'sub_po_a', null);
    $entNormal = evaluatePracticeCreationEntitlement($pdo, $ownerA, 'planoverride-owner-a@test.local');
    assertTrue('operate owner at cap cannot create another practice',
        $entNormal['allowed'] === false && $entNormal['max_practices'] === 1);
    $entSuper = evaluatePracticeCreationEntitlement($pdo, $ownerA, $adminEmail);
    assertTrue('super user owner is uncapped regardless of plan',
        $entSuper['allowed'] === true && $entSuper['max_practices'] === null);

    // -------------------------------------------------------------------
    echo "--- administrative plan overrides ---\n";

    ensurePlanOverridesSchema($pdo);

    // Upgrade override: Control subscription + Scale override -> Scale for
    // every practice the owner owns (organization-wide, not per-practice).
    $setSubscription($ownerA, 'control', 'active', 'sub_po_a', null);
    setPlanOverrideForOwner($pdo, $ownerA, 'scale', $adminId, $adminEmail, 'eval');
    assertEquals('override stored', 'scale', getPlanOverrideForOwner($pdo, $ownerA));
    assertTrue('scale override grants scale at anchor practice',
        hasScaleAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));
    assertTrue('scale override applies to every owned practice',
        hasScaleAccess($pdo, $practiceA3, 'planoverride-owner-a@test.local'));

    // The purchased plan row is untouched - override is entitlement-only.
    $access = getPracticeSubscriptionAccess($pdo, $practiceA1);
    assertEquals('purchased plan preserved in access row', 'control', $access['subscription_plan']);
    assertEquals('plan_override exposed', 'scale', $access['plan_override']);
    assertEquals('effective_plan carries override', 'scale', $access['effective_plan']);
    $subRow = getSubscriptionForOwner($pdo, $ownerA);
    assertEquals('subscriptions.plan never modified', 'control', $subRow['plan']);

    // Clearing restores the purchased plan.
    setPlanOverrideForOwner($pdo, $ownerA, null, $adminId, $adminEmail, '');
    assertEquals('override cleared', null, getPlanOverrideForOwner($pdo, $ownerA));
    assertTrue('cleared override restores purchased-plan gating',
        !hasScaleAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));

    // Downgrade override: Scale purchase + Operate override -> features gate
    // down; no practices/users/cases are deleted (read-only-ish narrowing).
    $setSubscription($ownerA, 'scale', 'active', 'sub_po_a', null);
    setPlanOverrideForOwner($pdo, $ownerA, 'operate', $adminId, $adminEmail, 'downgrade test');
    assertTrue('operate override removes scale access',
        !hasScaleAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));
    assertTrue('operate override removes control access',
        !hasControlAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));
    $accessDown = getPracticeSubscriptionAccess($pdo, $practiceA1);
    assertTrue('downgraded org still has full app access (data preserved)',
        !empty($accessDown['full_access']));
    assertEquals('downgrade kept purchased plan', 'scale', $accessDown['purchased_plan']);

    // Over-limit downgrade: owner still owns 3 practices; creating a 4th is
    // blocked by the existing allowance rule - nothing is deleted.
    $entDown = evaluatePracticeCreationEntitlement($pdo, $ownerA, 'planoverride-owner-a@test.local');
    assertTrue('operate override blocks creating practice #4',
        $entDown['allowed'] === false && $entDown['current_count'] === 3);
    assertEquals('all three practices still exist',
        3, getOwnedPracticeCount($pdo, $ownerA));

    // Upgrading the override re-opens creation without touching billing.
    setPlanOverrideForOwner($pdo, $ownerA, 'scale', $adminId, $adminEmail, 'restore');
    $entUp = evaluatePracticeCreationEntitlement($pdo, $ownerA, 'planoverride-owner-a@test.local');
    assertTrue('scale override allows additional practice (uncapped + add-ons)',
        $entUp['allowed'] === true && $entUp['max_practices'] === null);

    // Overrides govern during trial so admins can verify real tier behavior.
    setPlanOverrideForOwner($pdo, $ownerA, 'operate', $adminId, $adminEmail, 'trial check');
    $setSubscription($ownerA, null, 'trialing', null, date('Y-m-d H:i:s', time() + 86400));
    assertTrue('operate override gates scale even while trialing',
        !hasScaleAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));
    setPlanOverrideForOwner($pdo, $ownerA, 'scale', $adminId, $adminEmail, 'trial check');
    assertTrue('scale override grants scale while trialing',
        hasScaleAccess($pdo, $practiceA1, 'planoverride-member-a@test.local'));

    // Overrides never leak across organizations.
    $setSubscription($ownerB, 'operate', 'active', 'sub_po_b', null);
    assertTrue('org A override does not affect org B',
        !hasScaleAccess($pdo, $practiceB1, 'planoverride-owner-b@test.local'));

    // Platform admins are unaffected by the customer's override either way.
    setPlanOverrideForOwner($pdo, $ownerA, 'operate', $adminId, $adminEmail, 'iso check');
    assertTrue('super user ignores operate override',
        hasScaleAccess($pdo, $practiceA1, $adminEmail));

    setPlanOverrideForOwner($pdo, $ownerA, null, $adminId, $adminEmail, '');

    // Unknown plan values fail closed - never widen entitlement.
    assertEquals('unknown override slug rejected by getter',
        null, getPlanOverrideForOwner($pdo, 0));
    $threw = false;
    try {
        setPlanOverrideForOwner($pdo, $ownerA, 'enterprise', $adminId, $adminEmail, 'bad');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrue('unknown plan rejected on write', $threw);

} finally {
    // Cleanup: overrides first, then fixtures (FK cascades clear
    // practice_users and subscriptions).
    foreach ($createdUserIds as $uid) {
        try { setPlanOverrideForOwner($pdo, $uid, null, $uid, $adminEmail, ''); } catch (Throwable $e) {}
    }
    if (!empty($createdPracticeIds)) {
        $pdo->exec("DELETE FROM practices WHERE id IN (" . implode(',', array_map('intval', $createdPracticeIds)) . ")");
    }
    if (!empty($createdUserIds)) {
        $pdo->exec("DELETE FROM practice_users WHERE user_id IN (" . implode(',', array_map('intval', $createdUserIds)) . ")");
        $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $createdUserIds)) . ")");
    }
    $appConfig['super_users'] = $previousSuperUsers;
    unset($_SESSION['db_user_id'], $_SESSION['current_practice_id']);
}

echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
