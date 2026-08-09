<?php
/**
 * Create Stripe Checkout Session
 *
 * Accepts:  POST JSON { "plan": "operate"|"control", "interval": "month"|"year" }
 * Returns:  { "checkout_url": "https://checkout.stripe.com/..." }
 *        or { "portal_url": "..." }  when the practice already has an active subscription
 *        or { "error": "..." }
 *
 * Security contract:
 *   - Plan and interval are validated server-side.
 *   - Price ID is resolved from server config — never from the browser.
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

$allowedPlans     = ['operate', 'control'];
$allowedIntervals = ['month', 'year'];

if (!in_array($plan, $allowedPlans, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid plan. Must be operate or control']);
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

$priceId = $stripeConfig['prices'][$plan][$interval] ?? null;

if (empty($priceId)) {
    error_log("create-checkout-session: Price ID not configured for {$plan}/{$interval} in environment '{$stripeConfig['environment']}'");
    http_response_code(500);
    echo json_encode(['error' => 'Billing configuration error. Please contact support.']);
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

// ── Load practice row ────────────────────────────────────────────────────────
try {
    $practiceStmt = $pdo->prepare("
        SELECT
            stripe_customer_id,
            stripe_subscription_id,
            subscription_status,
            trial_ends_at,
            practice_name
        FROM practices
        WHERE id = ?
        LIMIT 1
    ");
    $practiceStmt->execute([$currentPracticeId]);
    $practice = $practiceStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('create-checkout-session: DB error loading practice: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}

if (!$practice) {
    http_response_code(404);
    echo json_encode(['error' => 'Practice not found']);
    exit;
}

// ── Guard: already has a manageable subscription ─────────────────────────────
// If the practice has an active/trialing/past_due Stripe subscription, redirect
// them to the Customer Portal rather than creating a duplicate subscription.
$manageableStatuses = ['active', 'trialing', 'past_due', 'unpaid'];
if (
    !empty($practice['stripe_subscription_id']) &&
    in_array($practice['subscription_status'], $manageableStatuses, true)
) {
    // Return a portal URL instead of starting a duplicate Checkout
    try {
        $portalUrl = createPortalSession($pdo, $currentPracticeId, $appConfig);
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
if (!empty($practice['trial_ends_at'])) {
    try {
        $trialEnd = new DateTimeImmutable($practice['trial_ends_at'], new DateTimeZone('UTC'));
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
    $stripeCustomerId = $practice['stripe_customer_id'] ?? null;

    if (empty($stripeCustomerId)) {
        $customer = \Stripe\Customer::create([
            'metadata' => [
                'dentatrak_practice_id' => (string)$currentPracticeId,
            ],
            'description' => $practice['practice_name'] ?? "Practice #{$currentPracticeId}",
        ]);
        $stripeCustomerId = $customer->id;

        // Persist Customer ID immediately so it survives abandoned checkouts
        $pdo->prepare("
            UPDATE practices SET stripe_customer_id = ? WHERE id = ?
        ")->execute([$stripeCustomerId, $currentPracticeId]);
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
            'dentatrak_practice_id' => (string)$currentPracticeId,
            'plan'                  => $plan,
            'interval'              => $interval,
        ],
        'subscription_data'     => [
            'metadata' => [
                'dentatrak_practice_id' => (string)$currentPracticeId,
                'plan'                  => $plan,
                'interval'              => $interval,
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
 * Build a Stripe Customer Portal session URL for the given practice.
 * Passes portal_configuration_id when configured so the correct portal
 * configuration (e.g. bpc_...) is used in both test and live environments.
 * Returns the URL string on success, throws on failure.
 */
function createPortalSession(PDO $pdo, int $practiceId, array $appConfig): string {
    $pStmt = $pdo->prepare("SELECT stripe_customer_id FROM practices WHERE id = ? LIMIT 1");
    $pStmt->execute([$practiceId]);
    $cid = $pStmt->fetchColumn();
    if (empty($cid)) {
        throw new RuntimeException('No Stripe customer found for this practice');
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
