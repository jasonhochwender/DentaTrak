<?php
/**
 * Create Stripe Customer Portal Session
 *
 * Accepts:  POST (no body required — practice resolved from session)
 * Returns:  { "portal_url": "https://billing.stripe.com/..." }
 *        or { "error": "..." }
 *
 * Security contract:
 *   - User must be authenticated and a practice admin or owner.
 *   - Stripe Customer ID is read from the DB — never from the browser.
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

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => t('billing.errors.authentication_required')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => t('billing.errors.method_not_allowed')]);
    exit;
}

requireCsrfToken();

// Validates session, membership in the practice, and returns the trusted practice ID
$currentPracticeId = requireValidPracticeContext();
$userId            = $_SESSION['db_user_id'];

// ── Permission: admin or owner only ─────────────────────────────────────────
if (!isPracticeAdmin($currentPracticeId) && !isPracticeOwner($currentPracticeId)) {
    http_response_code(403);
    echo json_encode(['error' => t('billing.errors.billing_requires_admin_or_owner')]);
    exit;
}

// ── Bypass users do not use Stripe ──────────────────────────────────────────
$userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
if ($userRow && isBillingBypassEmail($userRow['email'])) {
    http_response_code(403);
    echo json_encode(['error' => t('billing.errors.billing_not_applicable')]);
    exit;
}

// ── Validate Stripe config ───────────────────────────────────────────────────
$stripeConfig = $appConfig['stripe'] ?? [];

if (!empty($stripeConfig['config_error'])) {
    error_log('create-portal-session: Stripe config error: ' . $stripeConfig['config_error']);
    http_response_code(500);
    echo json_encode(['error' => t('billing.errors.payment_system_config')]);
    exit;
}

$secretKey = $stripeConfig['secret_key'] ?? null;
if (empty($secretKey)) {
    error_log('create-portal-session: STRIPE_SECRET_KEY not configured');
    http_response_code(500);
    echo json_encode(['error' => t('billing.errors.payment_system_not_configured')]);
    exit;
}

// ── Load Stripe Customer ID from the subscription OWNER's row ───────────────
// A subscription belongs to the subscription owner (practice_users.is_owner
// = 1), not to the current practice — resolve the owner first.
try {
    $ownerUserId = getSubscriptionOwnerUserId($pdo, $currentPracticeId);
    $stripeCustomerId = null;
    if ($ownerUserId !== null) {
        $stmt = $pdo->prepare("
            SELECT stripe_customer_id FROM subscriptions WHERE owner_user_id = ? LIMIT 1
        ");
        $stmt->execute([$ownerUserId]);
        $stripeCustomerId = $stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('create-portal-session: DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => t('billing.errors.internal')]);
    exit;
}

if (empty($stripeCustomerId)) {
    http_response_code(400);
    echo json_encode([
        'error' => t('billing.errors.no_billing_account'),
        'error_code' => 'no_customer',
    ]);
    exit;
}

// ── Create portal session ────────────────────────────────────────────────────
\Stripe\Stripe::setApiKey($secretKey);
\Stripe\Stripe::setAppInfo('DentaTrak', '1.0.0', 'https://dentatrak.com');

$baseUrl = rtrim($appConfig['app_base_url'] ?? 'https://dentatrak.com', '/');

try {
    $portalParams = [
        'customer'   => $stripeCustomerId,
        'return_url' => $baseUrl . '/main.php',
    ];
    $portalConfigId = $stripeConfig['portal_configuration_id'] ?? null;
    if (!empty($portalConfigId)) {
        $portalParams['configuration'] = $portalConfigId;
    }

    $stripeLocale = function_exists('getStripeLocale') ? getStripeLocale(getResolvedLocale()) : null;
    if (!empty($stripeLocale)) {
        $portalParams['locale'] = $stripeLocale;
    }

    $portalSession = \Stripe\BillingPortal\Session::create($portalParams);

    echo json_encode(['portal_url' => $portalSession->url]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('create-portal-session: Stripe API error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['error' => t('billing.errors.payment_system_error')]);
} catch (Exception $e) {
    error_log('create-portal-session: Unexpected error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => t('billing.errors.internal')]);
}
