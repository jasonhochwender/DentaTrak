<?php
/**
 * FABG Pilot Member Billing Benefit
 *
 * Centralizes everything about the FABG pilot discount (25% off every paid
 * plan, indefinitely, while the subscription remains active) so checkout,
 * the billing portal, the customer portal, and the webhook all share one
 * source of truth.
 *
 * Pricing model
 * ─────────────
 * The ordinary annual Price already embeds "2 months free" (10x monthly),
 * so a percent-off coupon on it would stack — exactly what the FABG offer
 * forbids. The model therefore is:
 *
 *   Monthly : standard monthly Price + FABG25 promotion code (25%-off,
 *             duration=forever coupon)  → 75% of standard monthly.
 *   Annual  : dedicated FABG annual Price billed at the FULL 12x monthly
 *             rate + the same promotion code → 75% of twelve monthly
 *             payments (9x monthly). The FABG discount can never be
 *             applied to the pre-discounted annual Price.
 *
 * The promotion code is attached to the Checkout Session server-side via
 * `discounts` — customer-facing code entry (allow_promotion_codes) is never
 * enabled, so nothing can ever stack on top and non-FABG practices can
 * never claim the code.
 *
 * Activation
 * ──────────
 * The offer is active only when the environment supplies
 * STRIPE_FABG_PROMOTION_CODE_ID (plus the FABG annual Price IDs for annual
 * checkout) AND the promotion code verifies against Stripe: active, code
 * matches STRIPE_FABG_PROMOTION_CODE (default "FABG25"), backed by a 25%-off
 * coupon with duration=forever. Until then FABG checkout is blocked and the
 * Billing UI shows the benefit without any code or redemption controls.
 */

if (!function_exists('isFabgPilotPractice')) {

/**
 * Is the practice flagged as an FABG pilot participant?
 * practices.fabg_pilot is the same flag the admin settings card and the
 * FABG welcome flow use. Reads are SHOW COLUMNS-guarded so a pre-migration
 * schema never breaks billing endpoints.
 */
function isFabgPilotPractice(PDO $pdo, int $practiceId): bool {
    static $columnExists = null;
    if ($columnExists === null) {
        try {
            $check = $pdo->query("SHOW COLUMNS FROM practices LIKE 'fabg_pilot'");
            $columnExists = $check && $check->rowCount() > 0;
        } catch (PDOException $e) {
            $columnExists = false;
        }
    }
    if (!$columnExists) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT fabg_pilot FROM practices WHERE id = ? LIMIT 1");
    $stmt->execute([$practiceId]);
    return (int)$stmt->fetchColumn() === 1;
}

/**
 * Dedicated FABG annual Price ID for a plan (billed at 12x monthly), or
 * null when not configured for the current environment.
 */
function getFabgAnnualPriceId(string $plan, array $appConfig): ?string {
    $priceId = $appConfig['stripe']['fabg']['annual_prices'][$plan] ?? null;
    return !empty($priceId) ? $priceId : null;
}

/**
 * FABG annual Price ID for the Scale additional-practice add-on
 * (12x the monthly add-on), or null.
 */
function getFabgAdditionalAnnualPriceId(array $appConfig): ?string {
    $priceId = $appConfig['stripe']['fabg']['additional_annual_price'] ?? null;
    return !empty($priceId) ? $priceId : null;
}

/**
 * Is this Stripe Price ID one of the dedicated FABG annual Prices
 * (base plan or Scale add-on)? Used by the webhook to recognize FABG
 * subscriptions regardless of which Price currently drives them.
 */
function isFabgPriceId(?string $priceId, array $appConfig): bool {
    if (empty($priceId)) {
        return false;
    }
    $fabg = $appConfig['stripe']['fabg'] ?? [];
    foreach (($fabg['annual_prices'] ?? []) as $configuredId) {
        if (!empty($configuredId) && $configuredId === $priceId) {
            return true;
        }
    }
    $additional = $fabg['additional_annual_price'] ?? null;
    return !empty($additional) && $additional === $priceId;
}

/**
 * FABG display prices in cents, derived from the standard display_prices:
 *   month → 75% of standard monthly
 *   year  → 75% of twelve monthly payments (9x monthly)
 * Display only — the Stripe Price + coupon are authoritative for charges.
 *
 * @return array<string, array{month:int, year:int}>
 */
function getFabgDisplayPrices(array $appConfig): array {
    $prices = [];
    foreach (($appConfig['stripe']['display_prices'] ?? []) as $plan => $intervals) {
        $monthly = $intervals['month'] ?? null;
        if ($monthly === null) {
            continue;
        }
        $prices[$plan] = [
            'month' => (int)round($monthly * 0.75),
            'year'  => (int)round($monthly * 12 * 0.75),
        ];
    }
    return $prices;
}

/**
 * Verify the configured FABG promotion code against Stripe:
 *   - it exists and is active
 *   - its code matches the configured expected code (FABG25)
 *   - its coupon is percent_off=25 with duration=forever
 *
 * Returns:
 *   configured → a promotion code ID is set for this environment
 *   verified   → the checks above all passed against the live Stripe account
 *   code       → the customer-facing code (only when verified)
 *   reason     → machine-readable failure reason for logging/diagnostics
 *
 * A positive verification is session-cached for 15 minutes and a negative
 * one for 60 seconds so the Billing modal does not hit the Stripe API on
 * every open, while a just-created code still activates quickly.
 *
 * @return array{configured:bool, verified:bool, code:?string, reason:?string}
 */
function verifyFabgPromotionCode(array $stripeConfig): array {
    $fabg     = $stripeConfig['fabg'] ?? [];
    $promoId  = $fabg['promotion_code_id'] ?? null;
    $expected = $fabg['expected_code'] ?? 'FABG25';

    if (empty($promoId)) {
        return ['configured' => false, 'verified' => false, 'code' => null, 'reason' => 'not_configured'];
    }
    if (empty($stripeConfig['secret_key'])) {
        return ['configured' => true, 'verified' => false, 'code' => null, 'reason' => 'no_secret_key'];
    }

    $cached = $_SESSION['fabg_promo_verify'] ?? null;
    if (
        is_array($cached) &&
        ($cached['promotion_code_id'] ?? null) === $promoId &&
        ($cached['result'] ?? null) !== null &&
        ($cached['checked_at'] ?? 0) > time() - (!empty($cached['result']['verified']) ? 900 : 60)
    ) {
        return $cached['result'];
    }

    $result = ['configured' => true, 'verified' => false, 'code' => null, 'reason' => 'unverified'];
    try {
        \Stripe\Stripe::setApiKey($stripeConfig['secret_key']);
        $promo  = \Stripe\PromotionCode::retrieve($promoId, ['expand' => ['coupon']]);
        $coupon = $promo->coupon ?? null;
        $ok = !empty($promo->active)
            && $coupon !== null
            && (float)($coupon->percent_off ?? 0) === 25.0
            && ($coupon->duration ?? '') === 'forever'
            && strcasecmp((string)($promo->code ?? ''), $expected) === 0;
        if ($ok) {
            $result = ['configured' => true, 'verified' => true, 'code' => $promo->code, 'reason' => null];
        } else {
            $result['reason'] = 'invalid_attributes';
            error_log('[fabg] promotion code verification rejected attributes: ' . json_encode([
                'promotion_code_id' => $promoId,
                'active'            => $promo->active ?? null,
                'code'              => $promo->code ?? null,
                'percent_off'       => $coupon->percent_off ?? null,
                'duration'          => $coupon->duration ?? null,
            ]));
        }
    } catch (Throwable $e) {
        $result['reason'] = 'stripe_error';
        error_log('[fabg] promotion code verification failed: ' . $e->getMessage());
    }

    $_SESSION['fabg_promo_verify'] = [
        'promotion_code_id' => $promoId,
        'checked_at'        => time(),
        'result'            => $result,
    ];
    return $result;
}

/**
 * Whether the FABG offer can actually be used for the given plan/interval
 * right now. 'year' additionally requires the dedicated FABG annual Price
 * (and the add-on Price for Scale, enforced at checkout time when a
 * quantity is needed).
 */
function isFabgOfferReadyFor(array $offer, string $plan, string $interval): bool {
    if (empty($offer['verified'])) {
        return false;
    }
    if ($interval === 'year') {
        return !empty($offer['annual_prices'][$plan]);
    }
    return $interval === 'month';
}

/**
 * Composite offer state for the Billing modal: verification result plus
 * which FABG annual Prices exist, plus the FABG display prices.
 */
function getFabgOfferState(array $appConfig): array {
    $verify = verifyFabgPromotionCode($appConfig['stripe'] ?? []);
    return [
        'configured'      => $verify['configured'],
        'verified'        => $verify['verified'],
        'code'            => $verify['code'],
        'annual_prices'   => [
            'operate' => getFabgAnnualPriceId('operate', $appConfig) !== null,
            'control' => getFabgAnnualPriceId('control', $appConfig) !== null,
            'scale'   => getFabgAnnualPriceId('scale',   $appConfig) !== null,
        ],
        'display_prices'  => getFabgDisplayPrices($appConfig),
    ];
}

/**
 * Whether subscriptions.fabg_discount exists (migration-gated, static cache).
 */
function subscriptionsFabgColumnExists(PDO $pdo): bool {
    static $exists = null;
    if ($exists === null) {
        try {
            $check = $pdo->query("SHOW COLUMNS FROM subscriptions LIKE 'fabg_discount'");
            $exists = $check && $check->rowCount() > 0;
        } catch (PDOException $e) {
            $exists = false;
        }
    }
    return $exists;
}

/**
 * Does the subscription owner's current subscription carry the FABG
 * recurring discount? Persisted by the webhook from the subscription's
 * applied promotion code or its dedicated FABG annual Price.
 */
function subscriptionHasFabgDiscount(PDO $pdo, int $ownerUserId): bool {
    if (!subscriptionsFabgColumnExists($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT fabg_discount FROM subscriptions WHERE owner_user_id = ? LIMIT 1");
    $stmt->execute([$ownerUserId]);
    return (int)$stmt->fetchColumn() === 1;
}

} // !function_exists('isFabgPilotPractice')
