<?php
/**
 * Admin Subscription Helpers
 *
 * Shared functions for normalizing and displaying DentaTrak subscription and
 * trial data in the Practice Administration / Dev Tools screen. Kept separate
 * from the API endpoint so the rendering logic can be unit-tested without the
 * full request lifecycle.
 *
 * This is the single source of truth for the admin subscription display model;
 * it reads from the authoritative `subscriptions` table (one row per owner) and
 * produces the display payload used by both the practice list and the
 * Subscription detail tab.
 */

require_once __DIR__ . '/plan-entitlements.php';

/**
 * Add a number of calendar months to a UTC date.
 *
 * - The result is the same day of the target month when possible.
 * - If the source day is the last day of the month, the result is the last day
 *   of the target month (handles month-end correctly).
 * - If the target month is shorter than the source day, the result is the last
 *   day of the target month (handles February and short months).
 * - The time-of-day component is preserved.
 *
 * @param DateTimeImmutable $date   Source UTC date.
 * @param int               $months Number of calendar months to add (may be negative).
 * @return DateTimeImmutable
 */
function addCalendarMonths(DateTimeImmutable $date, int $months): DateTimeImmutable {
    $day = (int)$date->format('d');
    $month = (int)$date->format('n') + $months;
    $year = (int)$date->format('Y');

    $year += (int)floor(($month - 1) / 12);
    $month = (($month - 1) % 12);
    if ($month < 0) {
        $month += 12;
    }
    $month += 1;

    $firstOfTarget = new DateTimeImmutable("{$year}-{$month}-01", new DateTimeZone('UTC'));
    $lastDayOfTarget = (int)$firstOfTarget->modify('+1 month -1 day')->format('d');

    // If the source date was the last day of its month, use the last day of the target.
    $sourceYearMonth = $date->format('Y-m');
    $sourceFirst = new DateTimeImmutable("{$sourceYearMonth}-01", new DateTimeZone('UTC'));
    $sourceLastDay = (int)$sourceFirst->modify('+1 month -1 day')->format('d');
    if ($day === $sourceLastDay || $day > $lastDayOfTarget) {
        $day = $lastDayOfTarget;
    }

    return (new DateTimeImmutable("{$year}-{$month}-{$day} {$date->format('H:i:s')}", new DateTimeZone('UTC')));
}

/**
 * Build a normalized, display-friendly subscription info array for admin use.
 *
 * The $sub array should contain the columns from the owner's `subscriptions`
 * row. If a legacy `practices.*` fallback is present, the `practice_*` prefixed
 * keys are used only when the authoritative subscription value is missing.
 *
 * @param array|null $sub      subscriptions row (or null)
 * @param array|null $owner    owner user row (or null)
 * @param int        $ownedCount number of practices this owner has
 * @return array
 */
function buildSubscriptionInfo(?array $sub, ?array $owner, int $ownedCount): array {
    $ownerId = $owner ? (int)($owner['owner_user_id'] ?? $owner['id'] ?? null) : null;
    $ownerEmail = $owner ? ($owner['owner_email'] ?? $owner['email'] ?? null) : null;
    $ownerName = $owner ? trim(($owner['owner_first_name'] ?? $owner['first_name'] ?? '') . ' ' . ($owner['owner_last_name'] ?? $owner['last_name'] ?? '')) : null;

    // Prefer the authoritative subscriptions.* values, with legacy practices.* as a fallback.
    $status = $sub['status'] ?? $sub['practice_subscription_status'] ?? null;
    $trialEndsAt = $sub['trial_ends_at'] ?? $sub['practice_trial_ends_at'] ?? null;
    $plan = $sub['plan'] ?? $sub['practice_plan'] ?? null;
    $billingInterval = $sub['billing_interval'] ?? $sub['practice_billing_interval'] ?? null;
    $currentPeriodEndsAt = $sub['current_period_ends_at'] ?? $sub['practice_current_period_ends_at'] ?? null;
    $stripeCustomerId = $sub['stripe_customer_id'] ?? $sub['practice_stripe_customer_id'] ?? null;
    $stripeSubscriptionId = $sub['stripe_subscription_id'] ?? $sub['practice_stripe_subscription_id'] ?? null;
    $cancelAtPeriodEnd = $sub['cancel_at_period_end'] ?? $sub['practice_cancel_at_period_end'] ?? false;
    $subscriptionUpdatedAt = $sub['subscription_updated_at'] ?? $sub['practice_subscription_updated_at'] ?? null;

    $hasRecord = !empty($sub) && (
        !empty($status)
        || !empty($trialEndsAt)
        || !empty($stripeCustomerId)
        || !empty($stripeSubscriptionId)
        || !empty($plan)
    );

    if (!$hasRecord) {
        return [
            'has_subscription' => false,
            'plan' => null,
            'plan_display' => '—',
            'status' => 'no_subscription',
            'status_display' => 'No Subscription',
            'is_trialing' => false,
            'trial_status' => 'Not on Trial',
            'trial_ends_at' => null,
            'trial_days_remaining' => null,
            'trial_display' => '',
            'trial_time_remaining' => '',
            'trial_line' => 'No Subscription',
            'trial_class' => '',
            'owner_user_id' => $ownerId,
            'owner_email' => $ownerEmail,
            'owner_name' => $ownerName,
            'owned_practice_count' => $ownedCount,
            'max_practices' => null,
            'capacity_display' => '—',
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'current_period_ends_at' => null,
            'billing_interval' => null,
            'cancel_at_period_end' => false,
            'subscription_updated_at' => null,
        ];
    }

    $planDisplay = !empty($plan) ? getPlanDisplayName($plan) : '—';
    $maxPractices = !empty($plan) ? getMaxOwnedPractices($plan) : null;
    if ($maxPractices === null) {
        $capacityDisplay = 'Practices: ' . $ownedCount;
    } else {
        $capacityDisplay = 'Practices: ' . $ownedCount . ' of ' . $maxPractices;
    }

    // Calendar-day comparison in UTC avoids off-by-one errors from time-of-day skew.
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0, 0, 0);
    $trialEndDate = null;
    $trialDays = null;
    if (!empty($trialEndsAt)) {
        try {
            $trialEndDate = (new DateTimeImmutable($trialEndsAt, new DateTimeZone('UTC')))->setTime(0, 0, 0);
            $trialDays = (int)$today->diff($trialEndDate)->format('%r%a');
        } catch (Throwable $e) {
            $trialEndDate = null;
            $trialDays = null;
        }
    }

    $effectiveStatus = $status;
    $isTrialing = false;
    $trialStatus = 'Not on Trial';

    if ($effectiveStatus === 'trialing') {
        if ($trialEndDate !== null && $trialDays < 0) {
            // The row still says trialing, but the calendar end date has passed.
            $effectiveStatus = 'trial_expired';
            $trialStatus = 'Expired';
        } else {
            $isTrialing = true;
            $trialStatus = 'Active';
        }
    } elseif (!empty($effectiveStatus) && $effectiveStatus !== 'no_subscription') {
        if (!empty($trialEndsAt)) {
            // A Stripe subscription ID means the account converted from trial to paid.
            // If there is no Stripe subscription but a trial end date exists, the trial
            // expired without conversion.
            $trialStatus = !empty($stripeSubscriptionId) ? 'Converted' : 'Expired';
        } else {
            $trialStatus = 'Not on Trial';
        }
    } elseif (empty($effectiveStatus) && !empty($trialEndsAt)) {
        // No explicit subscription status but a trial end date exists (legacy row
        // or owner-level row created before status was stamped). Derive from the
        // trial end date and whether a paid Stripe subscription exists.
        if (!empty($stripeSubscriptionId)) {
            $effectiveStatus = 'active';
            $trialStatus = 'Converted';
        } elseif ($trialEndDate !== null && $trialDays >= 0) {
            $effectiveStatus = 'trialing';
            $isTrialing = true;
            $trialStatus = 'Active';
        } else {
            $effectiveStatus = 'trial_expired';
            $trialStatus = 'Expired';
        }
    }

    $statusDisplayMap = [
        'active' => 'Active',
        'trialing' => 'Trial',
        'past_due' => 'Past Due',
        'unpaid' => 'Unpaid',
        'canceled' => 'Canceled',
        'incomplete' => 'Incomplete',
        'incomplete_expired' => 'Incomplete Expired',
        'trial_expired' => 'Trial Expired',
    ];
    $statusDisplay = $statusDisplayMap[$effectiveStatus] ?? ucfirst(str_replace('_', ' ', $effectiveStatus));

    $trialDisplay = '';
    $trialLine = '';
    $trialClass = '';

    if ($isTrialing && $trialDays !== null) {
        if ($trialDays > 1) {
            $trialDisplay = $trialDays . ' days left';
            $trialLine = 'Trial · ' . $trialDisplay;
        } elseif ($trialDays === 1) {
            $trialDisplay = '1 day left';
            $trialLine = 'Trial · 1 day left';
        } else { // 0
            $trialDisplay = 'Ends today';
            $trialLine = 'Trial · Ends today';
        }

        $trialClass = ($trialDays <= 14) ? 'trial-urgent' : 'trial-normal';
    } elseif ($effectiveStatus === 'trial_expired') {
        $trialDisplay = 'Trial expired';
        $trialLine = 'Trial expired';
        $trialClass = 'trial-expired';
    }

    return [
        'has_subscription' => true,
        'plan' => $plan,
        'plan_display' => $planDisplay,
        'status' => $effectiveStatus,
        'status_display' => $statusDisplay,
        'is_trialing' => $isTrialing,
        'trial_status' => $trialStatus,
        'trial_ends_at' => $trialEndsAt,
        'trial_days_remaining' => $trialDays,
        'trial_display' => $trialDisplay,
        'trial_time_remaining' => $trialDisplay,
        'trial_line' => $trialLine,
        'trial_class' => $trialClass,
        'owner_user_id' => $ownerId,
        'owner_email' => $ownerEmail,
        'owner_name' => $ownerName,
        'owned_practice_count' => $ownedCount,
        'max_practices' => $maxPractices,
        'capacity_display' => $capacityDisplay,
        'stripe_customer_id' => $stripeCustomerId,
        'stripe_subscription_id' => $stripeSubscriptionId,
        'current_period_ends_at' => $currentPeriodEndsAt,
        'billing_interval' => $billingInterval,
        'cancel_at_period_end' => !empty($cancelAtPeriodEnd),
        'subscription_updated_at' => $subscriptionUpdatedAt,
    ];
}
