<?php
/**
 * Plan Entitlements
 *
 * Single, centralized definition of what each DentaTrak plan allows in
 * terms of owned-practice count. This is the ONLY place practice-count
 * limits are defined - every other file (UI, API endpoints) must call into
 * these helpers rather than hardcoding numbers like `$count >= 2`.
 *
 * "Owned" practices are practices where practice_users.is_owner = 1 for the
 * subscription owner in question (see api/subscription-owner.php). Invited
 * memberships (admin, user, or Assigned Only in someone else's practice)
 * never count toward this limit and never grant its entitlement - a user's
 * ability to create their OWN practices comes only from their own
 * subscription/account, never from a practice they were merely invited to.
 */

require_once __DIR__ . '/subscription-owner.php';

/**
 * Maximum owned practices per plan. This array's keys are also the
 * canonical list of DentaTrak plans - see getKnownPlans().
 *
 * Stripe Price IDs for each plan are configured per environment via env
 * vars and mapped in api/stripe-price-map.php; entitlement (here) and
 * pricing (there) are deliberately separate concerns.
 */
const PLAN_MAX_PRACTICES = [
    'operate' => 1,
    'control' => 2,
    'scale'   => 50,
];

/**
 * Plan ordering, lowest to highest. Used by planMeetsTier() so capability
 * checks can ask "is this plan at least Control?" instead of enumerating
 * plans (`$plan === 'control' || $plan === 'scale'`) at each call site -
 * that pattern silently locks out every future higher tier.
 */
const PLAN_TIER_RANK = [
    'operate' => 1,
    'control' => 2,
    'scale'   => 3,
];

const PLAN_DISPLAY_NAMES = [
    'operate' => 'Operate',
    'control' => 'Control',
    'scale'   => 'Scale',
];

/**
 * Plan a given plan should upgrade to when it hits its practice limit, or
 * null when there is nowhere to upgrade to (Scale - contact support
 * instead).
 */
const PLAN_UPGRADE_TARGET = [
    'operate' => 'control',
    'control' => 'scale',
    'scale'   => null,
];

function getMaxOwnedPractices(string $plan): int {
    return PLAN_MAX_PRACTICES[$plan] ?? PLAN_MAX_PRACTICES['operate'];
}

/**
 * Canonical list of DentaTrak plans, lowest tier first.
 *
 * @return string[]
 */
function getKnownPlans(): array {
    return array_keys(PLAN_MAX_PRACTICES);
}

function isKnownPlan(string $plan): bool {
    return isset(PLAN_MAX_PRACTICES[$plan]);
}

/**
 * Does $plan include everything $minimumPlan includes?
 *
 * The plans are strictly cumulative (Scale = everything in Control =
 * everything in Operate, plus more practices), so a single rank comparison
 * answers every "does this plan have Control-level features?" question.
 * Feature checks must call this rather than comparing plan strings, so
 * adding a future tier above Scale does not require touching every check.
 *
 * Unknown plans never satisfy a tier requirement (fail closed).
 */
function planMeetsTier(?string $plan, string $minimumPlan): bool {
    $planRank    = PLAN_TIER_RANK[$plan ?? ''] ?? 0;
    $requiredRank = PLAN_TIER_RANK[$minimumPlan] ?? PHP_INT_MAX;
    return $planRank >= $requiredRank;
}

function getPlanDisplayName(string $plan): string {
    return PLAN_DISPLAY_NAMES[$plan] ?? ucfirst($plan);
}

/**
 * Count of practices owned (is_owner = 1) by this user. Practices the user
 * is merely invited to (admin, user, or Assigned Only) are never included -
 * see Example A/B/C in the practice-entitlement product spec.
 */
function getOwnedPracticeCount(PDO $pdo, int $ownerUserId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM practice_users WHERE user_id = :owner_user_id AND is_owner = 1
    ");
    $stmt->execute(['owner_user_id' => $ownerUserId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Resolve the plan that should govern an owner's practice-count limit.
 *
 * - Bypass accounts (see billing-bypass.php) are treated as Control -
 *   matches the existing bypass convention used everywhere else in the app.
 * - An owner with no recognized paid plan yet (still on the DentaTrak
 *   trial, has never completed Stripe Checkout) defaults to Operate - the
 *   lowest tier - so every trial account gets exactly one free practice.
 */
function resolveEffectivePlan(?string $storedPlan, bool $isBypass): string {
    if ($isBypass) {
        return 'control';
    }
    if (!empty($storedPlan) && isset(PLAN_MAX_PRACTICES[$storedPlan])) {
        return $storedPlan;
    }
    return 'operate';
}

/**
 * Authoritative answer to "can this owner create one more owned practice?"
 * The server remains the single source of truth - this is called both by
 * the page-load gate (baa-acceptance.php, for UX) and, mandatorily, by the
 * practice-creation endpoint itself (accept-baa.php) so a direct API call
 * cannot bypass the limit.
 *
 * When BILLING_ENABLED is false, this matches the rest of the app's
 * billing-gate convention: no limit is enforced.
 *
 * Callers must require billing-bypass.php beforehand for the bypass check
 * to take effect; if it is not loaded, $ownerEmail is simply ignored.
 *
 * @return array{
 *   allowed: bool,
 *   plan: string,
 *   plan_name: string,
 *   max_practices: int|null,
 *   current_count: int,
 *   upgrade_target: string|null
 * } max_practices is null when billing is disabled (unlimited).
 */
function evaluatePracticeCreationEntitlement(PDO $pdo, int $ownerUserId, string $ownerEmail = ''): array {
    $billingEnabledRaw = getenv('BILLING_ENABLED');
    if ($billingEnabledRaw === false) {
        $billingEnabledRaw = $_ENV['BILLING_ENABLED'] ?? '';
    }
    $billingEnabled = filter_var($billingEnabledRaw, FILTER_VALIDATE_BOOLEAN);

    $currentCount = getOwnedPracticeCount($pdo, $ownerUserId);

    if (!$billingEnabled) {
        return [
            'allowed'        => true,
            'plan'           => 'control',
            'plan_name'      => getPlanDisplayName('control'),
            'max_practices'  => null,
            'current_count'  => $currentCount,
            'upgrade_target' => null,
        ];
    }

    $isBypass = ($ownerEmail !== '' && function_exists('isBillingBypassEmail') && isBillingBypassEmail($ownerEmail));
    $subscription = getSubscriptionForOwner($pdo, $ownerUserId);
    $plan         = resolveEffectivePlan($subscription['plan'] ?? null, $isBypass);
    $maxPractices = getMaxOwnedPractices($plan);

    return [
        'allowed'        => $currentCount < $maxPractices,
        'plan'           => $plan,
        'plan_name'      => getPlanDisplayName($plan),
        'max_practices'  => $maxPractices,
        'current_count'  => $currentCount,
        'upgrade_target' => PLAN_UPGRADE_TARGET[$plan] ?? null,
    ];
}
