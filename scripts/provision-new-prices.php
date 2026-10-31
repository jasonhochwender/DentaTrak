<?php
/**
 * Provision the revised subscription Prices in the configured Stripe
 * environment (test via .env, or live when STRIPE_SECRET_KEY/STRIPE_ENVIRONMENT
 * are overridden on the command line).
 *
 * Idempotent: an active Price on the same product with the same
 * amount+interval is reused instead of duplicated.
 * Prints ONLY the env-var -> new Price ID lines (no secrets).
 *
 * Usage:
 *   php scripts/provision-new-prices.php            # dry run
 *   php scripts/provision-new-prices.php --yes      # create (test env)
 *   STRIPE_SECRET_KEY=... STRIPE_ENVIRONMENT=live php scripts/provision-new-prices.php --yes --live
 */

// Minimal env+SDK load: provisioning needs no database/bootstrap.
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) require_once $autoloadPath;
$ENV = [];
foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l); if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
    [$k, $v] = explode('=', $l, 2); $ENV[trim($k)] = trim($v, " \t\"'");
}
$ev = fn(string $k) => getenv($k) ?: ($ENV[$k] ?? null);

$args    = $argv ?? [];
$doWrite = in_array('--yes', $args, true);
$sk      = $ev('STRIPE_SECRET_KEY') ?? '';
$isLive  = (strpos($sk, 'sk_live') === 0) || ($ev('STRIPE_ENVIRONMENT') === 'live');

echo "=== New plan-price provisioning ===\n";
echo 'Environment : ' . ($ev('STRIPE_ENVIRONMENT') ?? 'unknown') . ($isLive ? ' (LIVE KEY)' : ' (test key)') . "\n";
echo 'Mode        : ' . ($doWrite ? 'WRITE' : 'DRY RUN - pass --yes to create') . "\n\n";

if (empty($sk)) { fwrite(STDERR, "No Stripe secret key configured.\n"); exit(1); }
if ($isLive && $doWrite && !in_array('--live', $args, true)) { fwrite(STDERR, "Refusing live writes without --live.\n"); exit(1); }

\Stripe\Stripe::setApiKey($sk);

// env var => [amount cents, interval, source of the existing (product-bearing) price]
$targets = [
    'STRIPE_OPERATE_MONTHLY_PRICE_ID'      => [9900,   'month', $ev('STRIPE_OPERATE_MONTHLY_PRICE_ID')],
    'STRIPE_OPERATE_ANNUAL_PRICE_ID'       => [99000,  'year',  $ev('STRIPE_OPERATE_ANNUAL_PRICE_ID')],
    'STRIPE_CONTROL_MONTHLY_PRICE_ID'      => [29900,  'month', $ev('STRIPE_CONTROL_MONTHLY_PRICE_ID')],
    'STRIPE_CONTROL_ANNUAL_PRICE_ID'       => [299000, 'year',  $ev('STRIPE_CONTROL_ANNUAL_PRICE_ID')],
    'STRIPE_SCALE_MONTHLY_PRICE_ID'        => [79900,  'month', $ev('STRIPE_SCALE_MONTHLY_PRICE_ID')],
    'STRIPE_SCALE_ANNUAL_PRICE_ID'         => [799000, 'year',  $ev('STRIPE_SCALE_ANNUAL_PRICE_ID')],
    // FABG dedicated annuals bill at 12x the NEW monthly rate so the
    // FABG25 coupon lands at 75% of twelve months without stacking.
    'STRIPE_OPERATE_FABG_ANNUAL_PRICE_ID'  => [118800, 'year',  $ev('STRIPE_OPERATE_FABG_ANNUAL_PRICE_ID') ?: $ev('STRIPE_OPERATE_ANNUAL_PRICE_ID')],
    'STRIPE_CONTROL_FABG_ANNUAL_PRICE_ID'  => [358800, 'year',  $ev('STRIPE_CONTROL_FABG_ANNUAL_PRICE_ID') ?: $ev('STRIPE_CONTROL_ANNUAL_PRICE_ID')],
    'STRIPE_SCALE_FABG_ANNUAL_PRICE_ID'    => [958800, 'year',  $ev('STRIPE_SCALE_FABG_ANNUAL_PRICE_ID')   ?: $ev('STRIPE_SCALE_ANNUAL_PRICE_ID')],
];

$out = [];
foreach ($targets as $envVar => [$amount, $interval, $oldPriceId]) {
    if (empty($oldPriceId)) { echo "SKIP $envVar - no existing price to derive product from\n"; continue; }
    $old     = \Stripe\Price::retrieve($oldPriceId);
    $product = $old->product;
    // Reuse if an active price already matches this exact amount+interval.
    $reuse = null;
    foreach (\Stripe\Price::all(['product' => $product, 'active' => true, 'type' => 'recurring', 'limit' => 100]) as $p) {
        if (($p->unit_amount ?? 0) === $amount && (($p->recurring->interval ?? '') === $interval)) { $reuse = $p; break; }
    }
    if ($reuse) { echo "REUSE $envVar = {$reuse->id}\n"; $out[$envVar] = $reuse->id; continue; }
    if (!$doWrite) { echo "WOULD-CREATE $envVar on {$product}: {$amount}c/{$interval}\n"; continue; }
    $new = \Stripe\Price::create([
        'currency'    => $old->currency ?? 'usd',
        'unit_amount' => $amount,
        'recurring'   => ['interval' => $interval],
        'product'     => $product,
        'nickname'    => (($old->nickname ?: 'plan') . ' (Oct 2026 repricing)'),
        'metadata'    => ['dentatrak_pricing_version' => '2026-10'],
    ]);
    echo "CREATE $envVar = {$new->id}\n"; $out[$envVar] = $new->id;
}

echo "\n--- env values ---\n";
foreach ($out as $k => $v) echo "$k=$v\n";
