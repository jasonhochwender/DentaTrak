<?php
/**
 * Subscription Access Helper
 *
 * Single authoritative source for evaluating what a practice can do
 * based on its current subscription state. All access rules live here.
 *
 * Usage:
 *   require_once __DIR__ . '/subscription-access.php';
 *   $access = getSubscriptionAccess($practiceRow);
 *
 * $practiceRow must contain at minimum:
 *   subscription_status, trial_ends_at, stripe_subscription_id
 *
 * Returns an array — see getSubscriptionAccess() docblock.
 */

/**
 * Evaluate practice-level subscription access.
 *
 * @param array $practice  Row from the practices table (Stripe columns required).
 * @return array {
 *   string  $status          Canonical status: trialing|active|past_due|unpaid|
 *                            canceled|incomplete|incomplete_expired|trial_expired|none
 *   bool    $full_access     True when the practice has unrestricted write access.
 *   bool    $read_only       True when the practice can view data but not create/edit.
 *   bool    $locked_out      True when the practice cannot access the app at all
 *                            (incomplete_expired or unknown state with no trial).
 *   bool    $show_billing_warning  True for past_due — show a persistent banner.
 *   bool    $show_trial_banner     True while trialing and not yet expired.
 *   bool    $trial_expired         True when the DentaTrak trial has ended with no paid sub.
 *   int|null $trial_days_remaining Days left in DentaTrak trial, or null if not trialing.
 *   string|null $trial_ends_at    ISO-8601 UTC trial end, or null.
 *   string|null $current_period_ends_at  ISO-8601 UTC next billing date, or null.
 *   string  $access_message  Human-readable reason shown to non-admin users.
 * }
 */
function getSubscriptionAccess(array $practice): array {
    // ── Billing feature gate ──────────────────────────────────────────────────
    // When BILLING_ENABLED is false (the production default while billing is not
    // yet configured) all practices get unconditional full access.  No trial
    // expiration, no read-only mode, and no Stripe columns are read.
    $billingEnabledRaw = getenv('BILLING_ENABLED');
    if ($billingEnabledRaw === false) {
        $billingEnabledRaw = $_ENV['BILLING_ENABLED'] ?? '';
    }
    if (!filter_var($billingEnabledRaw, FILTER_VALIDATE_BOOLEAN)) {
        return [
            'status'                  => 'active',
            'full_access'             => true,
            'read_only'               => false,
            'locked_out'              => false,
            'show_billing_warning'    => false,
            'show_trial_banner'       => false,
            'trial_expired'           => false,
            'trial_days_remaining'    => null,
            'trial_ends_at'           => null,
            'current_period_ends_at'  => null,
            'access_message'          => '',
        ];
    }

    $stripeStatus  = $practice['subscription_status'] ?? null;
    $trialEndsAt   = $practice['trial_ends_at']        ?? null;
    $hasSub        = !empty($practice['stripe_subscription_id']);

    // ── Compute DentaTrak trial state ────────────────────────────────────────
    $trialDaysRemaining = null;
    $trialExpired       = false;

    if ($trialEndsAt) {
        try {
            $end = new DateTimeImmutable($trialEndsAt, new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now',        new DateTimeZone('UTC'));
            $diff = $now->diff($end);
            if ($end > $now) {
                // diff->days counts full days; round up so "today" shows 1 not 0
                $trialDaysRemaining = $diff->days + ($diff->h > 0 || $diff->i > 0 || $diff->s > 0 ? 1 : 0);
                $trialDaysRemaining = max(0, $trialDaysRemaining);
            } else {
                $trialDaysRemaining = 0;
                $trialExpired       = true;
            }
        } catch (Exception $e) {
            // Malformed date — treat as expired
            $trialDaysRemaining = 0;
            $trialExpired       = true;
        }
    }

    // ── Determine canonical status ───────────────────────────────────────────
    // Priority: live Stripe subscription status > DentaTrak trial > no subscription
    if ($hasSub && $stripeStatus) {
        $status = $stripeStatus; // active | trialing | past_due | unpaid | canceled |
                                 // incomplete | incomplete_expired
    } elseif (!$hasSub && $trialEndsAt && !$trialExpired) {
        $status = 'trialing';
    } elseif (!$hasSub && ($trialExpired || !$trialEndsAt)) {
        $status = $trialExpired ? 'trial_expired' : 'none';
    } else {
        $status = $stripeStatus ?? 'none';
    }

    // ── Map status to access flags ───────────────────────────────────────────
    // Status          | full | read-only | locked | billing-warn | trial-banner
    // trialing        |  ✓   |           |        |              |      ✓
    // active          |  ✓   |           |        |              |
    // past_due        |  ✓   |           |        |      ✓       |
    // unpaid          |      |     ✓     |        |              |
    // canceled        |      |     ✓     |        |              |
    // trial_expired   |      |     ✓     |        |              |
    // none            |      |     ✓     |        |              |
    // incomplete      |      |     ✓     |        |              |
    // incomplete_exp. |      |           |   ✓    |              |

    $fullAccess          = false;
    $readOnly            = false;
    $lockedOut           = false;
    $showBillingWarning  = false;
    $showTrialBanner     = false;
    $accessMessage       = '';

    switch ($status) {
        case 'trialing':
            $fullAccess      = true;
            $showTrialBanner = true;
            $accessMessage   = 'Your free trial is active.';
            break;

        case 'active':
            $fullAccess    = true;
            $accessMessage = 'Your subscription is active.';
            break;

        case 'past_due':
            $fullAccess         = true;
            $showBillingWarning = true;
            $accessMessage      = 'Your payment is past due. Please update your payment method to avoid interruption.';
            break;

        case 'unpaid':
            $readOnly      = true;
            $accessMessage = 'Your account is in read-only mode due to an unpaid invoice. Please contact your administrator.';
            break;

        case 'canceled':
            $readOnly      = true;
            $accessMessage = 'Your subscription has been canceled. Your data is preserved. Please update your billing information or choose a plan to restore access.';
            break;

        case 'trial_expired':
            $readOnly      = true;
            $accessMessage = 'Your free trial has ended. Please choose a plan to continue creating and editing cases.';
            break;

        case 'none':
            $readOnly      = true;
            $accessMessage = 'No active subscription found. Please contact your administrator.';
            break;

        case 'incomplete':
            $readOnly      = true;
            $accessMessage = 'Your subscription setup is incomplete. Please finish checkout.';
            break;

        case 'incomplete_expired':
            $lockedOut     = true;
            $accessMessage = 'Your previous checkout session expired. A new checkout session is required.';
            break;

        default:
            $readOnly      = true;
            $accessMessage = 'Subscription status unknown. Please contact support.';
            break;
    }

    return [
        'status'                  => $status,
        'full_access'             => $fullAccess,
        'read_only'               => $readOnly,
        'locked_out'              => $lockedOut,
        'show_billing_warning'    => $showBillingWarning,
        'show_trial_banner'       => $showTrialBanner,
        'trial_expired'           => $trialExpired,
        'trial_days_remaining'    => $trialDaysRemaining,
        'trial_ends_at'           => $trialEndsAt,
        'current_period_ends_at'  => $practice['current_period_ends_at'] ?? null,
        'access_message'          => $accessMessage,
    ];
}

/**
 * Load the practice row for the current session and return its subscription access.
 * Returns null if the practice cannot be found.
 *
 * @param PDO $pdo
 * @param int $practiceId
 * @return array|null
 */
function getPracticeSubscriptionAccess(PDO $pdo, int $practiceId): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT
                subscription_status,
                trial_ends_at,
                current_period_ends_at,
                stripe_subscription_id,
                stripe_customer_id,
                cancel_at_period_end,
                subscription_plan,
                billing_interval
            FROM practices
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$practiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return getSubscriptionAccess($row);
    } catch (PDOException $e) {
        // Stripe columns may not exist yet — fail open (treat as trialing)
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            error_log('[subscription-access] Stripe columns missing — run migrate-stripe-fields.php');
            return getSubscriptionAccess([]);
        }
        throw $e;
    }
}
