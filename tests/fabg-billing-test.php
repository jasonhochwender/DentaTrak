<?php
/**
 * Source-level regression checks for the FABG pilot billing benefit.
 *
 * Requirements verified here:
 * - 25% recurring discount via the FABG25 promotion code (25%-off-forever
 *   coupon), applied server-side through Checkout `discounts` only - never
 *   via customer-facing code entry (allow_promotion_codes stays absent).
 * - FABG monthly = standard monthly Price + coupon (75% of monthly).
 * - FABG annual = dedicated 12x-monthly Price + coupon (75% of twelve
 *   payments); the 10x-monthly "two months free" Price can never combine.
 * - Activation requires verified Stripe configuration; until then FABG
 *   checkout is blocked server-side and the UI shows no code or controls.
 * - Eligibility derives from practices.fabg_pilot server-side only.
 * - subscriptions.fabg_discount persisted by the webhook drives the FABG
 *   portal configuration and the discounted renewal display.
 * - All user-facing strings are translated in the nine locales.
 *
 * Run: php tests/fabg-billing-test.php
 */

$pass = 0;
$fail = 0;

function check($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

$root = dirname(__DIR__);
$appConfigSrc = file_get_contents($root . '/api/appConfig.php');
$fabg         = file_get_contents($root . '/api/fabg-billing.php');
$priceMap     = file_get_contents($root . '/api/stripe-price-map.php');
$checkout     = file_get_contents($root . '/api/create-checkout-session.php');
$portal       = file_get_contents($root . '/api/create-portal-session.php');
$webhook      = file_get_contents($root . '/api/stripe-webhook.php');
$billingApi   = file_get_contents($root . '/api/billing-portal.php');
$billingJs    = file_get_contents($root . '/js/billing-portal.js');
$migrate      = file_get_contents($root . '/api/migrate-subscription-owner.php');
$css          = file_get_contents($root . '/css/billing-portal.css');
$helpers      = file_get_contents($root . '/api/test-helpers.php');

// ── Pure-function behavior (fabg-billing.php is side-effect free except
//    verifyFabgPromotionCode's Stripe call, which is not exercised here) ──
require_once $root . '/api/fabg-billing.php';

$mockConfig = [
    'stripe' => [
        'display_prices' => [
            'operate' => ['month' => 24900, 'year' => 249000],
            'control' => ['month' => 49900, 'year' => 499000],
            'scale'   => ['month' => 99900, 'year' => 999000],
        ],
        'fabg' => [
            'promotion_code_id'      => null,
            'expected_code'          => 'FABG25',
            'portal_configuration_id' => null,
            'annual_prices'          => ['operate' => 'price_fabg_op_yr', 'control' => null, 'scale' => 'price_fabg_sc_yr'],
            'additional_annual_price' => 'price_fabg_addon_yr',
        ],
    ],
];

// ── Pricing math ────────────────────────────────────────────────────────────
$fabgPrices = getFabgDisplayPrices($mockConfig);
check($fabgPrices['operate']['month'] === 18675, 'FABG operate monthly = 75% of $249 ($186.75)');
check($fabgPrices['operate']['year'] === 224100, 'FABG operate annual = 75% of 12x$249 ($2,241)');
check($fabgPrices['control']['month'] === 37425, 'FABG control monthly = 75% of $499 ($374.25)');
check($fabgPrices['control']['year'] === 449100, 'FABG control annual = 75% of 12x$499 ($4,491)');
check($fabgPrices['scale']['month'] === 74925, 'FABG scale monthly = 75% of $999 ($749.25)');
check($fabgPrices['scale']['year'] === 899100, 'FABG scale annual = 75% of 12x$999 ($8,991)');
check($fabgPrices['operate']['year'] === $fabgPrices['operate']['month'] * 12, 'FABG annual = 12x FABG monthly (no extra annual stacking)');

check(getFabgAnnualPriceId('operate', $mockConfig) === 'price_fabg_op_yr', 'getFabgAnnualPriceId resolves configured price');
check(getFabgAnnualPriceId('control', $mockConfig) === null, 'getFabgAnnualPriceId fails closed when unconfigured');
check(getFabgAdditionalAnnualPriceId($mockConfig) === 'price_fabg_addon_yr', 'FABG add-on annual price resolves');
check(isFabgPriceId('price_fabg_op_yr', $mockConfig) === true, 'isFabgPriceId detects FABG plan annual price');
check(isFabgPriceId('price_fabg_addon_yr', $mockConfig) === true, 'isFabgPriceId detects FABG add-on annual price');
check(isFabgPriceId('price_standard_yr', $mockConfig) === false, 'isFabgPriceId rejects standard price');
check(isFabgPriceId(null, $mockConfig) === false, 'isFabgPriceId rejects null');

$notConfigured = verifyFabgPromotionCode(['fabg' => ['promotion_code_id' => null], 'secret_key' => 'sk_test_x']);
check($notConfigured['configured'] === false && $notConfigured['verified'] === false, 'unconfigured offer reports configured=false, verified=false');

// Product coverage: every configured price id is collected for the
// applies_to check so a product-restricted coupon cannot silently exclude
// a paid plan.
$allIds = fabgConfiguredPriceIds([
    'prices' => [
        'operate' => ['month' => 'price_op_m', 'year' => 'price_op_y'],
        'scale'   => ['month' => 'price_sc_m', 'additional_month' => 'price_add_m', 'additional_year' => 'price_add_y'],
    ],
    'fabg' => [
        'annual_prices'           => ['operate' => 'price_fabg_op_yr'],
        'additional_annual_price' => 'price_fabg_addon_yr',
    ],
]);
sort($allIds);
check($allIds === ['price_add_m', 'price_add_y', 'price_fabg_addon_yr', 'price_fabg_op_yr', 'price_op_m', 'price_op_y', 'price_sc_m'],
    'fabgConfiguredPriceIds collects standard + add-on + FABG prices');
$helperSrc = file_get_contents($root . '/api/fabg-billing.php');
check(strpos($helperSrc, 'applies_to') !== false && strpos($helperSrc, 'restricted_products') !== false,
    'verification checks coupon applies_to product coverage');

check(isFabgOfferReadyFor(['verified' => false], 'operate', 'month') === false, 'offer not ready when promo unverified');
check(isFabgOfferReadyFor(['verified' => true, 'annual_prices' => ['operate' => true]], 'operate', 'year') === true, 'annual ready only with FABG annual price');
check(isFabgOfferReadyFor(['verified' => true, 'annual_prices' => ['operate' => false]], 'operate', 'year') === false, 'annual not ready without FABG annual price');
check(isFabgOfferReadyFor(['verified' => true, 'annual_prices' => []], 'operate', 'month') === true, 'monthly ready once promo verified');

// ── Config wiring ───────────────────────────────────────────────────────────
check(strpos($appConfigSrc, "'fabg'") !== false, 'appConfig defines stripe.fabg block');
check(strpos($appConfigSrc, 'STRIPE_FABG_PROMOTION_CODE_ID') !== false, 'promo code id from environment');
check(strpos($appConfigSrc, 'STRIPE_FABG_PORTAL_CONFIGURATION_ID') !== false, 'FABG portal config id from environment');
check(strpos($appConfigSrc, 'STRIPE_OPERATE_FABG_ANNUAL_PRICE_ID') !== false, 'operate FABG annual price env');
check(strpos($appConfigSrc, 'STRIPE_CONTROL_FABG_ANNUAL_PRICE_ID') !== false, 'control FABG annual price env');
check(strpos($appConfigSrc, 'STRIPE_SCALE_FABG_ANNUAL_PRICE_ID') !== false, 'scale FABG annual price env');
check(strpos($appConfigSrc, 'STRIPE_SCALE_FABG_ADDITIONAL_ANNUAL_PRICE_ID') !== false, 'scale add-on FABG annual price env');
check(strpos($appConfigSrc, 'FABG25') !== false, 'expected code defaults to FABG25');

// ── Price map: FABG annual prices reverse-map to (plan, year) ───────────────
check(strpos($priceMap, "fabg']['annual_prices']") !== false || strpos($priceMap, "fabg']['annual_prices") !== false,
    'price map scans stripe.fabg.annual_prices');
check(strpos($priceMap, "return [\$plan, 'year'];") !== false, 'FABG annual price resolves to (plan, year) interval');
check(strpos($priceMap, 'getFabgAdditionalAnnualPriceId') !== false, 'FABG add-on excluded from plan resolution');
check(strpos($priceMap, "require_once __DIR__ . '/fabg-billing.php'") !== false, 'price map loads fabg-billing helpers');

// ── Checkout: server-side enforcement + non-stacking ────────────────────────
check(strpos($checkout, 'isFabgPilotPractice') !== false, 'checkout resolves FABG eligibility server-side');
check(strpos($checkout, 'verifyFabgPromotionCode') !== false, 'checkout verifies the promotion code');
check(strpos($checkout, "getFabgAnnualPriceId(\$plan") !== false, 'checkout uses dedicated FABG annual price');
check(strpos($checkout, 'getFabgAdditionalAnnualPriceId') !== false, 'checkout uses FABG add-on price for scale annual');
check(strpos($checkout, "'discounts'") !== false && strpos($checkout, "'promotion_code' => \$fabgPromotionCodeId") !== false,
    'checkout attaches the promotion code via discounts param');
check(strpos($checkout, 'allow_promotion_codes') === false, 'checkout never enables customer-facing promo code entry');
check(strpos($checkout, 'fabg_offer_unavailable') !== false, 'checkout fails closed with fabg_offer_unavailable');
check(strpos($checkout, "trial_end") !== false, 'existing trial_end carry-through preserved');
check(strpos($checkout, 'subscriptionHasFabgDiscount') !== false, 'duplicate-sub portal redirect uses FABG portal config');

// The FABG block must precede the generic price resolution so pilot
// practices cannot fall through to an undiscounted checkout.
check(strpos($checkout, '$isFabgPilot') < strpos($checkout, "getStripePriceId(\$plan"),
    'FABG offer check runs before standard price resolution');

// ── Customer portal: FABG configuration for discounted subscriptions ────────
check(strpos($portal, 'subscriptionHasFabgDiscount') !== false, 'portal session checks fabg_discount');
check(strpos($portal, "fabg']['portal_configuration_id']") !== false, 'portal prefers STRIPE_FABG_PORTAL_CONFIGURATION_ID for FABG subs');
check(strpos($portal, 'portal_fallback_configuration_id') !== false, 'portal falls back to essentials-only config (no plan switching)');
check(strpos($portal, '?? $portalConfigId') === false, 'portal must NOT fall back to standard config for FABG subs (would stack coupon on 10x annual)');
check(strpos($portal, 'fabg_portal_unavailable') !== false, 'portal blocks FABG subs only when neither config exists');
check(strpos($checkout, 'fabg_portal_not_configured') !== false, 'checkout portal redirect enforces FABG config too');
check(strpos($checkout, 'portal_fallback_configuration_id') !== false, 'checkout portal redirect honors essentials fallback');
check(strpos($checkout, 'fabg_portal_unavailable') !== false, 'checkout portal redirect returns the localized FABG error');
check(strpos($appConfigSrc, 'STRIPE_FABG_PORTAL_FALLBACK_CONFIGURATION_ID') !== false, 'fallback portal config env var wired');

// ── Webhook: fabg_discount persistence ──────────────────────────────────────
check(strpos($webhook, '$fabgDiscount') !== false, 'webhook computes FABG discount state');
check(strpos($webhook, 'promotion_code') !== false, 'webhook inspects applied promotion codes');
check(strpos($webhook, 'isFabgPriceId') !== false, 'webhook detects dedicated FABG prices');
check(strpos($webhook, 'fabg_discount           = :fabg') !== false || strpos($webhook, 'fabg_discount') !== false,
    'webhook writes fabg_discount');
check(strpos($webhook, 'subscriptionsFabgColumnExists') !== false, 'webhook guards pre-migration schema');
// Clover API shape: subscription.discounts in event payloads are thin di_
// IDs with no promotion_code — the webhook must expand them server-side and
// leave the column untouched when expansion fails (fail-safe, not fail-open).
check(strpos($webhook, "'expand' => ['discounts']") !== false,
    'webhook expands thin di_ discount IDs to resolve promotion codes');
check(strpos($webhook, '$fabgDiscount = null') !== false && strpos($webhook, '$fabgDiscount !== null') !== false,
    'webhook preserves persisted fabg_discount when determination is indeterminate');
check(preg_match('/catch.*discount expansion failed|error_log.*expansion failed/s', $webhook) === 1,
    'webhook logs and swallows discount-expansion failures');

// ── Migration ───────────────────────────────────────────────────────────────
check(strpos($migrate, 'fabg_discount') !== false, 'migrate script adds fabg_discount');
check(strpos($migrate, "SHOW COLUMNS FROM subscriptions LIKE 'fabg_discount'") !== false,
    'migration is idempotent (SHOW COLUMNS guard)');

// ── Billing portal API ──────────────────────────────────────────────────────
check(strpos($billingApi, 'isFabgPilotPractice') !== false, 'billing-portal resolves pilot flag server-side');
check(strpos($billingApi, 'getFabgOfferState') !== false, 'billing-portal returns offer state');
check(strpos($billingApi, "fabg['code'] = null") !== false, 'code hidden from response until verified');
check(strpos($billingApi, 'subscriptionHasFabgDiscount') !== false, 'subscription payload reports fabg_discount');

// ── Billing modal UI ────────────────────────────────────────────────────────
check(strpos($billingJs, 'renderFabgPanel') !== false, 'FABG benefit panel rendered');
check(strpos($billingJs, 'billing.fabg.panel_body') !== false, 'panel uses required wording key');
check(strpos($billingJs, 'bp-fabg-copy') !== false && strpos($billingJs, 'navigator.clipboard') !== false,
    'copy button + clipboard handler present');
check(strpos($billingJs, 'fabg.display_prices') !== false, 'plan cards/sub card use FABG display prices');
check(strpos($billingJs, 'bp-plan-savings--fabg') !== false, 'member-price chip replaces annual_savings for FABG');
check(strpos($billingJs, "interval === 'year' && !fabg") !== false,
    '"Save 2 months" suppressed for FABG practices');
check(strpos($billingJs, 'checkoutPaused') !== false && strpos($billingJs, 'checkout_paused') !== false,
    'plan buttons disabled while offer unverified');
check(strpos($billingJs, 'trialDaysLabel') !== false, 'panel shows actual remaining trial time');
check(strpos($billingJs, 'sub.fabg_discount') !== false, 'subscription card honors fabg_discount flag');

// ── CSS ─────────────────────────────────────────────────────────────────────
check(strpos($css, '.bp-fabg-panel') !== false, 'FABG panel styles present');
check(strpos($css, '.bp-fabg-code') !== false, 'FABG code box styles present');
check(strpos($css, '.bp-fabg-badge') !== false, 'FABG badge styles present');
check(strpos($css, '[data-theme="dark"] .bp-fabg-panel') !== false, 'dark theme FABG styles present');

// ── Test helper fixture ─────────────────────────────────────────────────────
check(strpos($helpers, "case 'set_fabg_pilot'") !== false, 'set_fabg_pilot fixture action registered');
check(strpos($helpers, 'handleSetFabgPilot') !== false, 'set_fabg_pilot handler present');

// ── Locales: all keys in all nine languages ─────────────────────────────────
$localeFiles = ['en-US', 'de-DE', 'es-ES', 'fr-CA', 'fr-FR', 'it-IT', 'ko-KR', 'pt-BR', 'ru-RU'];
$panelKeys = ['panel_title', 'panel_body', 'code_label', 'copy', 'copied', 'code_instructions',
              'pending', 'checkout_paused', 'member_price', 'badge'];

foreach ($localeFiles as $locale) {
    $json = json_decode(file_get_contents($root . '/locales/' . $locale . '.json'), true);
    check(is_array($json), "{$locale} parses");

    $missing = [];
    foreach ($panelKeys as $k) {
        if (empty($json['billing']['fabg'][$k])) { $missing[] = "billing.fabg.{$k}"; }
    }
    if (empty($json['billing']['errors']['fabg_offer_unavailable'])) { $missing[] = 'billing.errors.fabg_offer_unavailable'; }
    if (empty($json['billing']['errors']['fabg_portal_unavailable'])) { $missing[] = 'billing.errors.fabg_portal_unavailable'; }
    check($missing === [], "{$locale} has all FABG billing keys" . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));

    $desc = $json['admin_practices']['fabg_card_desc'] ?? '';
    check(stripos($desc, 'attribution only') === false,
        "{$locale} fabg_card_desc no longer claims billing is unaffected");
}

// en-US panel body must carry the exact required wording.
$enUs = json_decode(file_get_contents($root . '/locales/en-US.json'), true);
check(
    strpos($enUs['billing']['fabg']['panel_body'],
        'Save 25% on any paid plan after your complimentary 90-day trial') !== false
    && strpos($enUs['billing']['fabg']['panel_body'],
        'Cannot be combined with annual billing savings or other discounts') !== false,
    'en-US panel body carries the required benefit wording'
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
