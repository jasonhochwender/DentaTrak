<?php
/**
 * Stripe Webhook Event Guard
 *
 * Centralizes the decision of whether a verified Stripe webhook event should
 * be allowed to mutate DentaTrak billing/subscription state.
 *
 * This makes the same logic available to both the production webhook endpoint
 * and test helpers, without requiring test helpers to include the endpoint file.
 *
 * Safety invariants:
 *   - A live environment must not process test-mode (livemode === false) events.
 *   - A live environment must not process events verified with the test secret.
 *   - A test environment may process test-mode events.
 *
 * The guard intentionally does not reject test events; it returns false so the
 * caller can acknowledge them with 2xx and prevent Stripe delivery failures.
 */

/**
 * Determine whether a verified Stripe event should be allowed to mutate
 * billing/subscription state in the current environment.
 *
 * @param object  $event                Stripe Event or equivalent stdClass test fixture
 * @param bool    $verifiedWithTestSecret
 * @param array   $appConfig
 * @return bool
 */
function shouldProcessWebhookEvent($event, bool $verifiedWithTestSecret, array $appConfig): bool {
    $environment   = $appConfig['stripe']['environment'] ?? 'test';
    $isProduction  = $environment === 'live';
    $isTestEvent   = ($event->livemode === false);

    if (!$isProduction) {
        return true;
    }

    // In the live environment, never process test-mode events or anything
    // verified with the test signing secret, regardless of its livemode flag.
    if ($isTestEvent || $verifiedWithTestSecret) {
        return false;
    }

    return true;
}
