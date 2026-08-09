<?php
/**
 * Billing Portal API
 *
 * Returns authoritative practice-level subscription state for the Billing modal.
 * Read-only — all mutations go through create-checkout-session.php,
 * create-portal-session.php, and stripe-webhook.php.
 *
 * The trial period is stored on the practice row (trial_ends_at), not on the user.
 * One trial belongs to the practice, set at creation time in accept-baa.php.
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

try {
    $currentPracticeId = requireValidPracticeContext();
    $userId            = $_SESSION['db_user_id'];

    // ── Authorization ────────────────────────────────────────────────────────
    $isAdmin       = isPracticeAdmin($currentPracticeId);
    $isOwner       = isPracticeOwner($currentPracticeId);
    $canManage     = $isAdmin || $isOwner;

    // ── Bypass check (partners / internal users) ─────────────────────────────
    $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userRow  = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    $isBypassUser = isBillingBypassEmail($userRow['email']);

    // ── Load practice Stripe + trial fields ──────────────────────────────────
    $practiceRow     = null;
    $hasSubscription = false;
    $subscription    = null;

    try {
        $pStmt = $pdo->prepare("
            SELECT
                stripe_customer_id,
                stripe_subscription_id,
                stripe_price_id,
                subscription_plan,
                billing_interval,
                subscription_status,
                trial_ends_at,
                current_period_ends_at,
                cancel_at_period_end,
                subscription_updated_at
            FROM practices
            WHERE id = ?
            LIMIT 1
        ");
        $pStmt->execute([$currentPracticeId]);
        $practiceRow = $pStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            error_log('billing-portal.php: Stripe columns missing — run api/migrate-stripe-fields.php');
            $practiceRow = [];
        } else {
            throw $e;
        }
    }

    $practiceRow = $practiceRow ?: [];

    if (!empty($practiceRow['stripe_subscription_id'])) {
        $hasSubscription = true;
        $subscription    = [
            'plan'                   => $practiceRow['subscription_plan'],
            'billing_interval'       => $practiceRow['billing_interval'],
            'status'                 => $practiceRow['subscription_status'],
            'trial_ends_at'          => $practiceRow['trial_ends_at'],
            'current_period_ends_at' => $practiceRow['current_period_ends_at'],
            'cancel_at_period_end'   => (bool)($practiceRow['cancel_at_period_end'] ?? false),
            'has_stripe_customer'    => !empty($practiceRow['stripe_customer_id']),
        ];
        // Never expose stripe_customer_id or stripe_subscription_id to the browser
    }

    // ── Evaluate access state ────────────────────────────────────────────────
    $access = getSubscriptionAccess($practiceRow);

    // ── Display prices from config (cents) ───────────────────────────────────
    $displayPrices = $appConfig['stripe']['display_prices'] ?? [];

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
    ]);

} catch (PDOException $e) {
    error_log('billing-portal.php PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
