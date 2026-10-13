<?php
/**
 * Provision the FABG pilot billing objects in the configured Stripe
 * environment (test or live, whichever STRIPE_ENVIRONMENT/key selects).
 *
 * Idempotent: existing matching objects are reused, nothing duplicated.
 * Prints the environment variables to set — no secrets are printed.
 *
 * Usage:
 *   php scripts/provision-fabg-stripe.php            # test/dry check
 *   php scripts/provision-fabg-stripe.php --yes      # actually create
 *   php scripts/provision-fabg-stripe.php --live --yes   # live account
 *
 * Objects:
 *   1) Coupon        25% off, duration=forever        (id: fabg_member_25)
 *   2) PromotionCode code "FABG25" on that coupon
 *   3) Annual Prices billed at 12x monthly (undiscounted annual):
 *        Operate $2,988 · Control $5,988 · Scale $11,988 · Scale add-on $1,188
 *   4) Customer Portal config for FABG subscriptions (plan changes offer
 *      monthly + FABG annual Prices only — never the 10x annuals)
 *   5) Essentials-only Portal config fallback (payment method, invoices,
 *      cancellation; NO plan switching) used when #4 is not configured.
 */

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/appConfig.php';

$args    = $argv ?? [];
$doWrite = in_array('--yes', $args, true);
$isLive  = (strpos($appConfig['stripe']['secret_key'] ?? '', 'sk_live') === 0)
        || (($appConfig['stripe']['environment'] ?? '') === 'live');

echo "=== FABG Stripe provisioning ===\n";
echo 'Environment : ' . ($appConfig['stripe']['environment'] ?? 'unknown') . ($isLive ? ' (LIVE KEY)' : ' (test key)') . "\n";
echo 'Mode        : ' . ($doWrite ? 'WRITE' : 'DRY RUN — pass --yes to create') . "\n\n";

if ($isLive && !$doWrite) {
    echo "NOTE: live-mode objects would be listed/created here.\n\n";
}
if ($isLive && $doWrite && !in_array('--live', $args, true)) {
    fwrite(STDERR, "Refusing to write to a LIVE Stripe account without --live.\n");
    exit(1);
}

$sk = $appConfig['stripe']['secret_key'] ?? '';
if (empty($sk)) {
    fwrite(STDERR, "No Stripe secret key configured.\n");
    exit(1);
}
\Stripe\Stripe::setApiKey($sk);

$out = [];

// ── 1) Coupon: 25% off, forever ────────────────────────────────────────────
$couponId = 'fabg_member_25';
$coupon   = null;
try {
    $existing = \Stripe\Coupon::retrieve($couponId);
    if (($existing->percent_off ?? null) == 25.0 && ($existing->duration ?? '') === 'forever') {
        $coupon = $existing;
        echo "REUSE  coupon {$couponId} (25% off, forever)\n";
    } else {
        fwrite(STDERR, "WARN   coupon {$couponId} exists with different attributes — inspect before reuse\n");
    }
} catch (\Stripe\Exception\InvalidRequestException $e) {
    // not found → also scan for an equivalent coupon by attributes
    foreach (\Stripe\Coupon::all(['limit' => 100])->data as $c) {
        if (($c->percent_off ?? null) == 25.0 && ($c->duration ?? '') === 'forever' && ($c->valid ?? false)) {
            $coupon = $c;
            echo "REUSE  coupon {$c->id} (matching 25%/forever attributes)\n";
            break;
        }
    }
}
if ($coupon === null) {
    if ($doWrite) {
        $coupon = \Stripe\Coupon::create([
            'id'          => $couponId,
            'name'        => 'FABG Member Benefit — 25% off',
            'percent_off' => 25,
            'duration'    => 'forever',
            'metadata'    => ['dentatrak' => 'fabg_pilot'],
        ]);
        echo "CREATE coupon {$coupon->id} (25% off, forever)\n";
    } else {
        echo "WOULD  create coupon {$couponId} (25% off, forever)\n";
    }
}

// ── 2) Promotion code FABG25 ───────────────────────────────────────────────
$promo = null;
foreach (\Stripe\PromotionCode::all(['limit' => 100])->data as $p) {
    if (strcasecmp((string)$p->code, 'FABG25') === 0) {
        $promo = $p;
        break;
    }
}
if ($promo !== null) {
    echo "REUSE  promotion code {$promo->id} (code={$promo->code}, active=" . var_export($promo->active, true) . ")\n";
} elseif ($doWrite && $coupon !== null) {
    $promo = \Stripe\PromotionCode::create([
        'promotion' => ['type' => 'coupon', 'coupon' => $coupon->id],
        'code'      => 'FABG25',
        'active'    => true,
        'metadata'  => ['dentatrak' => 'fabg_pilot'],
    ]);
    echo "CREATE promotion code {$promo->id} (code=FABG25)\n";
} else {
    echo "WOULD  create promotion code FABG25 on coupon " . ($coupon ? $coupon->id : $couponId) . "\n";
}
$out['STRIPE_FABG_PROMOTION_CODE_ID'] = $promo ? $promo->id : null;

// ── 3) Dedicated FABG annual Prices (12x monthly, undiscounted annual) ──────
// Resolve each plan's product from its configured monthly Price so the FABG
// annual Prices live on the same products.
$planProducts = [];
foreach (['operate', 'control', 'scale'] as $plan) {
    $monthlyId = $appConfig['stripe']['prices'][$plan]['month'] ?? null;
    if (empty($monthlyId)) {
        fwrite(STDERR, "WARN   no monthly Price configured for {$plan} — skipping\n");
        continue;
    }
    $p = \Stripe\Price::retrieve($monthlyId);
    $planProducts[$plan] = is_string($p->product) ? $p->product : $p->product->id;
}
$scaleMonthly = $appConfig['stripe']['prices']['scale']['additional_month'] ?? null;
$addonProduct = $planProducts['scale'] ?? null;

$wanted = [
    'STRIPE_OPERATE_FABG_ANNUAL_PRICE_ID'           => ['plan' => 'operate', 'amount' => 298800],
    'STRIPE_CONTROL_FABG_ANNUAL_PRICE_ID'           => ['plan' => 'control', 'amount' => 598800],
    'STRIPE_SCALE_FABG_ANNUAL_PRICE_ID'             => ['plan' => 'scale',   'amount' => 1198800],
    'STRIPE_SCALE_FABG_ADDITIONAL_ANNUAL_PRICE_ID'  => ['plan' => 'scale',   'amount' => 118800, 'addon' => true],
];

foreach ($wanted as $envName => $spec) {
    $product = !empty($spec['addon']) ? $addonProduct : ($planProducts[$spec['plan']] ?? null);
    if ($product === null) {
        fwrite(STDERR, "WARN   {$envName}: product unresolved — skipped\n");
        continue;
    }
    $found = null;
    foreach (\Stripe\Price::all(['product' => $product, 'limit' => 100])->data as $price) {
        if (
            ($price->unit_amount ?? null) === $spec['amount']
            && ($price->currency ?? '') === 'usd'
            && (($price->recurring->interval ?? '') === 'year')
            && !empty($price->active)
        ) {
            $found = $price;
            break;
        }
    }
    if ($found !== null) {
        echo "REUSE  {$envName} = {$found->id} (\${$spec['amount']}/100 per year)\n";
    } elseif ($doWrite) {
        $found = \Stripe\Price::create([
            'product'     => $product,
            'unit_amount' => $spec['amount'],
            'currency'    => 'usd',
            'recurring'   => ['interval' => 'year'],
            'lookup_key'  => strtolower('fabg_' . $spec['plan'] . (!empty($spec['addon']) ? '_addon' : '') . '_annual'),
            'metadata'    => ['dentatrak' => 'fabg_annual'],
        ]);
        echo "CREATE {$envName} = {$found->id} (\${$spec['amount']}/100 per year)\n";
    } else {
        echo "WOULD  {$envName} = <new> (\${$spec['amount']}/100 per year on {$product})\n";
    }
    $out[$envName] = $found ? $found->id : null;
}

// ── 4/5) Customer Portal configurations ────────────────────────────────────
// Mirror the standard configuration's features; the FABG config restricts
// plan changes to monthly + FABG annual Prices only.
$sharedFeatures = [
    'customer_update' => [
        'allowed_updates' => ['name', 'email', 'address', 'phone'],
        'enabled'         => true,
    ],
    'invoice_history'        => ['enabled' => true],
    'payment_method_update'  => ['enabled' => true],
    'subscription_cancel'    => [
        'enabled'             => true,
        'mode'                => 'at_period_end',
        'proration_behavior'  => 'none',
        'cancellation_reason' => [
            'enabled' => true,
            'options' => ['too_expensive', 'switched_service', 'unused', 'other'],
        ],
    ],
    'subscription_pause' => ['enabled' => false],
];

$portalProducts = [];
foreach (['operate', 'control', 'scale'] as $plan) {
    $product = $planProducts[$plan] ?? null;
    $monthly = $appConfig['stripe']['prices'][$plan]['month'] ?? null;
    $fabgYr  = $out['STRIPE_' . strtoupper($plan) . '_FABG_ANNUAL_PRICE_ID'] ?? null;
    if ($product && $monthly && $fabgYr) {
        $portalProducts[] = ['product' => $product, 'prices' => [$monthly, $fabgYr]];
    }
}

$findPortalCfg = function (string $marker) {
    foreach (\Stripe\BillingPortal\Configuration::all(['limit' => 100])->data as $cfg) {
        $meta = $cfg->metadata ? $cfg->metadata->toArray() : [];
        if (($meta['dentatrak_fabg_portal'] ?? '') === $marker && !empty($cfg->active)) {
            return $cfg;
        }
    }
    return null;
};

// subscription_update.products is expandable-only — it never appears in the
// unexpanded response, so verify coverage with an explicit expand read.
$portalProductsMatch = function (string $cfgId, array $expected): bool {
    $cfg = \Stripe\BillingPortal\Configuration::retrieve(
        $cfgId,
        ['expand' => ['features.subscription_update.products']]
    );
    $stored = [];
    foreach (($cfg->features->subscription_update->products ?? []) as $p) {
        $prices = (array)$p->prices;
        sort($prices);
        $stored[is_string($p->product) ? $p->product : $p->product->id] = implode(',', $prices);
    }
    $want = [];
    foreach ($expected as $e) {
        $prices = $e['prices'];
        sort($prices);
        $want[$e['product']] = implode(',', $prices);
    }
    return $stored == $want;
};

// Full FABG portal config — only usable when all FABG annual prices exist.
$fullCfg = $findPortalCfg('full');
if ($fullCfg !== null && count($portalProducts) === 3 &&
    !$portalProductsMatch($fullCfg->id, $portalProducts)) {
    if ($doWrite) {
        $fullCfg = \Stripe\BillingPortal\Configuration::update($fullCfg->id, [
            'features' => ['subscription_update' => [
                'enabled'                 => true,
                'default_allowed_updates' => ['price'],
                'proration_behavior'      => 'create_prorations',
                'products'                => $portalProducts,
            ]],
        ]);
        echo "REPAIR STRIPE_FABG_PORTAL_CONFIGURATION_ID = {$fullCfg->id} (products resynced)\n";
    } else {
        echo "WOULD  repair {$fullCfg->id} — stored updateable products differ\n";
    }
}
if ($fullCfg !== null) {
    echo "REUSE  STRIPE_FABG_PORTAL_CONFIGURATION_ID = {$fullCfg->id}\n";
} elseif (count($portalProducts) === 3) {
    if ($doWrite) {
        $fullCfg = \Stripe\BillingPortal\Configuration::create([
            'features' => $sharedFeatures + [
                'subscription_update' => [
                    'enabled'                  => true,
                    'default_allowed_updates'  => ['price'],
                    'proration_behavior'       => 'create_prorations',
                    'billing_cycle_anchor'     => 'unchanged',
                    'trial_update_behavior'    => 'continue_trial',
                    'products'                 => $portalProducts,
                ],
            ],
            'business_profile' => ['headline' => 'Manage your DentaTrak subscription'],
            'metadata'         => ['dentatrak_fabg_portal' => 'full'],
        ]);
        echo "CREATE STRIPE_FABG_PORTAL_CONFIGURATION_ID = {$fullCfg->id} (monthly + FABG annual only)\n";
    } else {
        echo "WOULD  STRIPE_FABG_PORTAL_CONFIGURATION_ID = <new> (products: monthly + FABG annual prices)\n";
    }
} else {
    fwrite(STDERR, "WARN   skipping full portal config — FABG annual prices not all resolved\n");
}
$out['STRIPE_FABG_PORTAL_CONFIGURATION_ID'] = $fullCfg ? $fullCfg->id : null;

// Essentials-only fallback — used when the full config is not set, so FABG
// subscribers keep payment-method updates, invoices, and cancellation
// WITHOUT any plan switching (which could stack the coupon).
$fallbackCfg = $findPortalCfg('essentials');
if ($fallbackCfg !== null) {
    echo "REUSE  STRIPE_FABG_PORTAL_FALLBACK_CONFIGURATION_ID = {$fallbackCfg->id}\n";
} elseif ($doWrite) {
    $fallbackCfg = \Stripe\BillingPortal\Configuration::create([
        'features' => $sharedFeatures + [
            'subscription_update' => ['enabled' => false],
        ],
        'business_profile' => ['headline' => 'Manage your DentaTrak subscription'],
        'metadata'         => ['dentatrak_fabg_portal' => 'essentials'],
    ]);
    echo "CREATE STRIPE_FABG_PORTAL_FALLBACK_CONFIGURATION_ID = {$fallbackCfg->id} (no plan switching)\n";
} else {
    echo "WOULD  STRIPE_FABG_PORTAL_FALLBACK_CONFIGURATION_ID = <new> (essentials only)\n";
}
$out['STRIPE_FABG_PORTAL_FALLBACK_CONFIGURATION_ID'] = $fallbackCfg ? $fallbackCfg->id : null;

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=== Set these environment variables ===\n\n";
echo "STRIPE_FABG_PROMOTION_CODE=FABG25\n";
foreach ($out as $k => $v) {
    echo $k . '=' . ($v ?: '<NOT CREATED>') . "\n";
}
echo "\n" . ($doWrite ? 'Done.' : 'Dry run complete — rerun with --yes to create.') . "\n";
