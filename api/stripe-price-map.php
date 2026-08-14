<?php
/**
 * Stripe Price ID <-> Plan Mapping
 *
 * THE authoritative, bidirectional mapping between Stripe Price IDs and
 * DentaTrak's internal (plan, billing interval) pair. Every place that needs
 * to answer "which plan is this Stripe Price?" or "which Stripe Price should
 * this plan use?" must go through this file so there is exactly one source
 * of truth.
 *
 * Consumers:
 *   - api/create-checkout-session.php  (plan/interval -> Price ID)
 *   - api/stripe-webhook.php           (Price ID -> plan/interval)
 *
 * The map itself lives in appConfig.php's `stripe.prices` array, which is
 * populated exclusively from environment variables:
 *
 *   STRIPE_OPERATE_MONTHLY_PRICE_ID / STRIPE_OPERATE_ANNUAL_PRICE_ID
 *   STRIPE_CONTROL_MONTHLY_PRICE_ID / STRIPE_CONTROL_ANNUAL_PRICE_ID
 *   STRIPE_SCALE_MONTHLY_PRICE_ID   / STRIPE_SCALE_ANNUAL_PRICE_ID
 *
 * Environment safety: because Price IDs are read only from the environment,
 * a test Price ID can never be selected in production unless someone
 * explicitly sets it as a production environment variable. A plan whose
 * Price IDs are not configured for the running environment resolves to null
 * here, and callers must fail closed (see getStripePriceId()'s contract) —
 * there is deliberately NO fallback to another environment's IDs.
 *
 * Internal conventions (do not change without migrating stored data):
 *   plan     : 'operate' | 'control' | 'scale'
 *   interval : 'month' | 'year'      (stored in subscriptions.billing_interval)
 */

/**
 * Map a Stripe Price ID to [plan, interval] using the server-side config.
 *
 * Returns ['unknown', null] for unrecognized/missing Price IDs so callers
 * can persist an explicit 'unknown' rather than silently guessing a plan.
 * Never trusts plan/interval supplied by the client or by Stripe metadata —
 * only the configured Price IDs are authoritative.
 *
 * @return array{0: string, 1: string|null}
 */
function resolvePlanFromPriceId(?string $priceId, array $appConfig): array {
    if (empty($priceId)) {
        return ['unknown', null];
    }

    $prices = $appConfig['stripe']['prices'] ?? [];
    foreach ($prices as $plan => $intervals) {
        foreach ($intervals as $interval => $configuredId) {
            if (!empty($configuredId) && $configuredId === $priceId) {
                return [$plan, $interval];
            }
        }
    }

    error_log('[stripe-price-map] Unrecognized Price ID: ' . substr($priceId, 0, 30));
    return ['unknown', null];
}

/**
 * Resolve the Stripe Price ID configured for a plan/interval in the CURRENT
 * environment, or null when it is not configured.
 *
 * Null is a controlled, expected outcome (e.g. Scale in production before
 * its live Price IDs are added) and callers MUST fail closed with a clear
 * error rather than substituting another plan's or another environment's
 * Price ID.
 */
function getStripePriceId(string $plan, string $interval, array $appConfig): ?string {
    $priceId = $appConfig['stripe']['prices'][$plan][$interval] ?? null;
    return !empty($priceId) ? $priceId : null;
}

/**
 * Plans that have at least one Price ID configured in the current
 * environment, i.e. those that can actually be purchased right now.
 * Used for diagnostics and tests; checkout validates the specific
 * plan/interval pair via getStripePriceId().
 *
 * @return string[]
 */
function getConfiguredStripePlans(array $appConfig): array {
    $configured = [];
    foreach (($appConfig['stripe']['prices'] ?? []) as $plan => $intervals) {
        foreach ($intervals as $priceId) {
            if (!empty($priceId)) {
                $configured[] = $plan;
                break;
            }
        }
    }
    return $configured;
}
