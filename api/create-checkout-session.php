<?php
/**
 * Create Stripe Checkout Session
 *
 * Accepts:  POST JSON { "plan": "operate"|"control"|"scale", "interval": "month"|"year" }
 * Returns:  { "checkout_url": "https://checkout.stripe.com/..." }
 *        or { "portal_url": "..." }  when the owner already has an active subscription
 *        or { "error": "..." }
 *
 * A subscription belongs to the CURRENT practice's subscription OWNER (see
 * api/subscription-owner.php), not to the practice itself — every practice
 * that owner has created shares this one Stripe customer/subscription.
 *
 * Security contract:
 *   - Plan and interval are validated server-side against the canonical plan
 *     list (api/plan-entitlements.php).
 *   - Price ID is resolved from server config for the RUNNING environment via
 *     api/stripe-price-map.php — never from the browser, and never from
 *     another environment (a plan with no Price ID configured here fails
 *     closed rather than falling back).
 *   - Stripe Customer ID is always read from the DB, never from the browser.
 *   - trial_end is the existing DentaTrak trial_ends_at, never a new 90-day window.
 *   - CSRF token required.
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
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/subscription-owner.php';
require_once __DIR__ . '/plan-entitlements.php';
require_once __DIR__ . '/stripe-price-map.php';

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireCsrfToken();

// Validates session, membership in the practice, and returns the trusted practice ID
$currentPracticeId = requireValidPracticeContext();
$userId            = $_SESSION['db_user_id'];

// ── Permission: admin or owner only ─────────────────────────────────────────
if (!isPracticeAdmin($currentPracticeId) && !isPracticeOwner($currentPracticeId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Billing management requires practice administrator or owner role']);
    exit;
}

// ── Bypass users must not reach Stripe ──────────────────────────────────────
$userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
if ($userRow && isBillingBypassEmail($userRow['email'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Billing management is not applicable to this account']);
    exit;
}

// ── Parse and validate request body ─────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$plan     = $body['plan']     ?? '';
$interval = $body['interval'] ?? '';

// Canonical plan list comes from plan-entitlements.php so a new tier is
// purchasable as soon as it is defined there and given Price IDs.
$allowedPlans     = getKnownPlans();
$allowedIntervals = ['month', 'year'];

if (!in_array($plan, $allowedPlans, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid plan. Must be one of: ' . implode(', ', $allowedPlans)]);
    exit;
}
if (!in_array($interval, $allowedIntervals, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid interval. Must be month or year']);
    exit;
}

// ── Resolve Price ID from server config ──────────────────────────────────────
$stripeConfig = $appConfig['stripe'] ?? [];

// Fail loudly if STRIPE_ENVIRONMENT is missing or keys don't match the declared environment
if (!empty($stripeConfig['config_error'])) {
    error_log('create-checkout-session: Stripe config error: ' . $stripeConfig['config_error']);
    http_response_code(500);
    echo json_encode(['error' => 'Payment system configuration error. Please contact support.']);
    exit;
}

// Resolve against the CURRENT environment's configured Price IDs only.
// A known plan with no Price ID configured for this environment (e.g. Scale
// in production before its live Price IDs are created) is a controlled,
// expected outcome: fail closed with a clear message. Never substitute
// another environment's or another plan's Price ID.
$priceId = getStripePriceId($plan, $interval, $appConfig);

if ($priceId === null) {
    error_log("create-checkout-session: no Price ID configured for {$plan}/{$interval} in Stripe environment '" .
              ($stripeConfig['environment'] ?? 'unknown') . "' - set STRIPE_" . strtoupper($plan) . '_' .
              ($interval === 'month' ? 'MONTHLY' : 'ANNUAL') . '_PRICE_ID for this environment');
    http_response_code(503);
    echo json_encode([
        'error'      => 'The ' . getPlanDisplayName($plan) . ' plan is not available for purchase yet. Please contact support.',
        'error_code' => 'plan_not_available',
    ]);
    exit;
}

// Validate required Stripe credentials
$secretKey = $stripeConfig['secret_key'] ?? null;
if (empty($secretKey)) {
    error_log('create-checkout-session: STRIPE_SECRET_KEY not configured');
    http_response_code(500);
    echo json_encode(['error' => 'Payment system not configured. Please contact support.']);
    exit;
}

// ── Resolve the subscription OWNER for the current practice ──────────────────
$ownerUserId = getSubscriptionOwnerUserId($pdo, $currentPracticeId);
if ($ownerUserId === null) {
    error_log("create-checkout-session: no subscription owner found for practice {$currentPracticeId}");
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}

try {
    $practiceNameStmt = $pdo->prepare("SELECT practice_name FROM practices WHERE id = ? LIMIT 1");
    $practiceNameStmt->execute([$currentPracticeId]);
    $practiceName = $practiceNameStmt->fetchColumn() ?: "Practice #{$currentPracticeId}";

    $subscription = getOrCreateSubscriptionForOwner($pdo, $ownerUserId);
} catch (PDOException $e) {
    error_log('create-checkout-session: DB error loading owner subscription: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}

// ── Guard: already has a manageable subscription ─────────────────────────────
// If the owner has an active/trialing/past_due Stripe subscription, redirect
// them to the Customer Portal rather than creating a duplicate subscription.
$manageableStatuses = ['active', 'trialing', 'past_due', 'unpaid'];
if (
    !empty($subscription['stripe_subscription_id']) &&
    in_array($subscription['status'], $manageableStatuses, true)
) {
    // Return a portal URL instead of starting a duplicate Checkout
    try {
        $portalUrl = createPortalSession($pdo, $ownerUserId, $appConfig);
        echo json_encode(['portal_url' => $portalUrl]);
    } catch (Exception $e) {
        error_log('create-checkout-session: portal redirect failed: ' . $e->getMessage());
        http_response_code(502);
        echo json_encode(['error' => 'Payment system error. Please try again or contact support.']);
    }
    exit;
}

// ── Determine trial_end for Stripe ───────────────────────────────────────────
// Use the existing DentaTrak trial_ends_at — never create a new trial.
$stripeTrialEnd = null;
if (!empty($subscription['trial_ends_at'])) {
    try {
        $trialEnd = new DateTimeImmutable($subscription['trial_ends_at'], new DateTimeZone('UTC'));
        $now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($trialEnd > $now) {
            $stripeTrialEnd = $trialEnd->getTimestamp();
        }
        // If trial already expired: $stripeTrialEnd stays null → no Stripe trial
    } catch (Exception $e) {
        // Malformed date — no trial
    }
}

// ── Initialize Stripe SDK ────────────────────────────────────────────────────
\Stripe\Stripe::setApiKey($secretKey);
\Stripe\Stripe::setAppInfo('DentaTrak', '1.0.0', 'https://dentatrak.com');

$baseUrl = rtrim($appConfig['app_base_url'] ?? 'https://dentatrak.com', '/');

try {
    // ── Create or reuse Stripe Customer ──────────────────────────────────────
    // The Stripe Customer belongs to the subscription OWNER, not the practice
    // currently being viewed — reused across every practice that owner creates.
    $stripeCustomerId = $subscription['stripe_customer_id'] ?? null;

    if (empty($stripeCustomerId)) {
        $customer = \Stripe\Customer::create([
            'metadata' => [
                'dentatrak_owner_user_id' => (string)$ownerUserId,
            ],
            'description' => $practiceName,
        ]);
        $stripeCustomerId = $customer->id;

        // Persist Customer ID immediately so it survives abandoned checkouts
        $pdo->prepare("
            UPDATE subscriptions SET stripe_customer_id = ? WHERE owner_user_id = ?
        ")->execute([$stripeCustomerId, $ownerUserId]);
    }

    // ── Build Checkout Session params ─────────────────────────────────────────
    $sessionParams = [
        'customer'              => $stripeCustomerId,
        'mode'                  => 'subscription',
        'payment_method_collection' => 'always',
        'line_items'            => [[
            'price'    => $priceId,
            'quantity' => 1,
        ]],
        'success_url'           => $baseUrl . '/main.php?checkout=success',
        'cancel_url'            => $baseUrl . '/main.php?checkout=canceled',
        'metadata'              => [
            'dentatrak_owner_user_id' => (string)$ownerUserId,
            'plan'                    => $plan,
            'interval'                => $interval,
        ],
        'subscription_data'     => [
            'metadata' => [
                'dentatrak_owner_user_id' => (string)$ownerUserId,
                'plan'                    => $plan,
                'interval'                => $interval,
            ],
        ],
    ];

    // Carry the DentaTrak trial end into Stripe only when still in the future
    if ($stripeTrialEnd !== null) {
        $sessionParams['subscription_data']['trial_end'] = $stripeTrialEnd;
    }

    $checkoutSession = \Stripe\Checkout\Session::create($sessionParams);

    echo json_encode(['checkout_url' => $checkoutSession->url]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('create-checkout-session: Stripe API error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'Payment system error. Please try again or contact support.']);
} catch (Exception $e) {
    error_log('create-checkout-session: Unexpected error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Build a Stripe Customer Portal session URL for the given subscription
 * OWNER. Passes portal_configuration_id when configured so the correct
 * portal configuration (e.g. bpc_...) is used in both test and live
 * environments. Returns the URL string on success, throws on failure.
 */
function createPortalSession(PDO $pdo, int $ownerUserId, array $appConfig): string {
    $sStmt = $pdo->prepare("SELECT stripe_customer_id FROM subscriptions WHERE owner_user_id = ? LIMIT 1");
    $sStmt->execute([$ownerUserId]);
    $cid = $sStmt->fetchColumn();
    if (empty($cid)) {
        throw new RuntimeException('No Stripe customer found for this subscription owner');
    }
    $baseUrl = rtrim($appConfig['app_base_url'] ?? 'https://dentatrak.com', '/');
    $params  = [
        'customer'   => $cid,
        'return_url' => $baseUrl . '/main.php',
    ];
    $portalConfigId = $appConfig['stripe']['portal_configuration_id'] ?? null;
    if (!empty($portalConfigId)) {
        $params['configuration'] = $portalConfigId;
    }
    $portal = \Stripe\BillingPortal\Session::create($params);
    return $portal->url;
}
