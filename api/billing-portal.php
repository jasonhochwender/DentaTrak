<?php
/**
 * Billing Portal API
 *
 * Returns authoritative subscription state for the Billing modal, resolved
 * from the CURRENT practice's subscription OWNER (see
 * api/subscription-owner.php). A subscription belongs to the owner
 * account, not to any single practice — every practice that owner has
 * created shares this same plan/trial/billing cycle.
 * Read-only — all mutations go through create-checkout-session.php,
 * create-portal-session.php, and stripe-webhook.php.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/billing-gate.php';
requireBillingEnabled();

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/billing-bypass.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/subscription-owner.php';
require_once __DIR__ . '/plan-entitlements.php';
require_once __DIR__ . '/fabg-billing.php';

try {
    $currentPracticeId = requireValidPracticeContext();
    $userId            = $_SESSION['db_user_id'];

    // ── Authorization ────────────────────────────────────────────────────────
    $isAdmin       = isPracticeAdmin($currentPracticeId);
    $isOwner       = isPracticeOwner($currentPracticeId);
    $canManage     = $isAdmin || $isOwner;

    // SECURITY: Billing is an admin-only surface. Reject non-admins/non-owners
    // server-side so this endpoint can't be called directly to read subscription
    // state, even though the client also hides the Billing modal for them.
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['error' => t('billing.errors.admin_only_view')]);
        exit;
    }

    // Owners and administrators must accept the current Terms before viewing
    // or managing billing.
    requireCurrentTermsAcceptedForApi((int)$userId);

    // External collaborators such as labs may not view or manage billing.
    requireNotLabCollaborator($currentPracticeId, t('billing.errors.external_collaborator_billing'));

    // ── Bypass check (partners / internal users) ─────────────────────────────
    $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userRow  = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        http_response_code(404);
        echo json_encode(['error' => t('billing.errors.user_not_found')]);
        exit;
    }
    $isBypassUser = isBillingBypassEmail($userRow['email']);

    // ── Load the OWNER's subscription/trial fields ───────────────────────────
    // A subscription belongs to the subscription owner (practice_users.is_owner
    // = 1), not to the current practice — resolve the owner first, then read
    // their single shared subscriptions row.
    $ownerRow        = null;
    $hasSubscription = false;
    $subscription    = null;

    $ownerUserId = getSubscriptionOwnerUserId($pdo, $currentPracticeId);

    try {
        $ownerRow = $ownerUserId !== null ? getSubscriptionForOwner($pdo, $ownerUserId) : null;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            error_log('billing-portal.php: subscriptions table missing — run api/migrate-subscription-owner.php');
            $ownerRow = null;
        } else {
            throw $e;
        }
    }

    // Map subscriptions table columns onto the field names getSubscriptionAccess()
    // expects (historically named after the deprecated per-practice columns).
    $mappedRow = $ownerRow ? [
        'stripe_customer_id'      => $ownerRow['stripe_customer_id'],
        'stripe_subscription_id'  => $ownerRow['stripe_subscription_id'],
        'stripe_price_id'         => $ownerRow['stripe_price_id'],
        'subscription_plan'       => $ownerRow['plan'],
        'billing_interval'        => $ownerRow['billing_interval'],
        'subscription_status'     => $ownerRow['status'],
        'trial_ends_at'           => $ownerRow['trial_ends_at'],
        'current_period_ends_at'  => $ownerRow['current_period_ends_at'],
        'cancel_at_period_end'    => $ownerRow['cancel_at_period_end'],
        'subscription_updated_at' => $ownerRow['subscription_updated_at'],
    ] : [];

    if (!empty($mappedRow['stripe_subscription_id'])) {
        $hasSubscription = true;
        $subscription    = [
            'plan'                   => $mappedRow['subscription_plan'],
            'billing_interval'       => $mappedRow['billing_interval'],
            'status'                 => $mappedRow['subscription_status'],
            'trial_ends_at'          => $mappedRow['trial_ends_at'],
            'current_period_ends_at' => $mappedRow['current_period_ends_at'],
            'cancel_at_period_end'   => (bool)($mappedRow['cancel_at_period_end'] ?? false),
            'has_stripe_customer'    => !empty($mappedRow['stripe_customer_id']),
            'fabg_discount'          => $ownerUserId !== null && subscriptionHasFabgDiscount($pdo, $ownerUserId),
        ];
        // Never expose stripe_customer_id or stripe_subscription_id to the browser
    }

    // ── Evaluate access state ────────────────────────────────────────────────
    $access = getSubscriptionAccess($mappedRow);

    // ── Organization practice usage ──────────────────────────────────────────
    // The subscription is purchased by the organization (the owner's account),
    // so usage counts every practice the owner owns — not just the session
    // practice. `included` is the base-price allowance (Scale: 5) and
    // `additional` is the count billed as per-practice add-ons; `max` is null
    // for uncapped plans so the UI never implies five is a hard limit.
    $practiceUsage = null;
    if ($hasSubscription && $ownerUserId !== null) {
        $planSlug = (string)($subscription['plan'] ?? '');
        if (isKnownPlan($planSlug)) {
            $owned = getOwnedPracticeCount($pdo, $ownerUserId);
            $practiceUsage = [
                'plan'       => $planSlug,
                'owned'      => $owned,
                'included'   => getIncludedPracticeCount($planSlug),
                'additional' => max(0, $owned - getIncludedPracticeCount($planSlug)),
                'max'        => getMaxOwnedPractices($planSlug),
            ];
        }
    }

    // ── Display prices from config (cents) ───────────────────────────────────
    $displayPrices = $appConfig['stripe']['display_prices'] ?? [];

    // ── FABG pilot benefit ───────────────────────────────────────────────────
    // Eligibility is server-side only (the practice's fabg_pilot flag — the
    // client can never claim it). The offer state reports whether the
    // FABG25 promotion code is configured AND verified against Stripe; the
    // customer-facing code is only exposed once verified so the UI can
    // never imply the discount is redeemable before it actually is.
    $fabg = null;
    if (isFabgPilotPractice($pdo, $currentPracticeId)) {
        $fabg          = getFabgOfferState($appConfig);
        $fabg['pilot'] = true;
        if (empty($fabg['verified'])) {
            $fabg['code'] = null;
        }
    }

    // ── Build response ───────────────────────────────────────────────────────
    // Bypass users always get full access and no billing UI
    if ($isBypassUser) {
        echo json_encode([
            'hide_billing_ui'      => true,
            'is_admin'             => $isAdmin,
            'can_manage_billing'   => false,
            'has_subscription'     => false,
            'subscription'         => null,
            'access'               => [
                'status'               => 'active',
                'full_access'          => true,
                'read_only'            => false,
                'locked_out'           => false,
                'show_billing_warning' => false,
                'show_trial_banner'    => false,
                'trial_expired'        => false,
                'trial_days_remaining' => null,
                'trial_ends_at'        => null,
                'current_period_ends_at' => null,
                'access_message'       => '',
            ],
            'display_prices'       => [],
            'fabg'                 => null,
            'practice_usage'       => null,
        ]);
        exit;
    }

    echo json_encode([
        'hide_billing_ui'      => false,
        'is_admin'             => $isAdmin,
        'can_manage_billing'   => $canManage,
        'has_subscription'     => $hasSubscription,
        'subscription'         => $subscription,
        'access'               => $access,
        'display_prices'       => $displayPrices,
        'fabg'                 => $fabg,
        'practice_usage'       => $practiceUsage,
    ]);

} catch (PDOException $e) {
    error_log('billing-portal.php PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => t('billing.errors.internal')]);
}
