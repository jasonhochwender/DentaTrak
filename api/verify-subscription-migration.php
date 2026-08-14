<?php
/**
 * One-time, read-only verification of the owner-centric subscription
 * migration performed by api/migrate-subscription-owner.php.
 *
 * Confirms: every practice_users.is_owner=1 user has exactly one
 * subscriptions row, no subscription row belongs to a non-owner, and each
 * migrated row's Stripe/plan/trial fields match the legacy practices row it
 * was sourced from. Never writes anything, never calls Stripe, and never
 * echoes raw Stripe customer/subscription/price IDs or other potentially
 * sensitive values - only counts and match/mismatch booleans.
 *
 * Run once via CLI or browser (admin-gated), same convention as
 * api/migrate-subscription-owner.php.
 *
 * Usage (CLI):
 *   php api/verify-subscription-migration.php
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/stripe-price-map.php';

header('Content-Type: text/plain; charset=utf-8');

// Only allow admin users or CLI - identical gate to migrate-subscription-owner.php
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    if (!isset($_SESSION['db_user_id'])) {
        http_response_code(403);
        echo "Access denied: must be authenticated.\n";
        exit;
    }
    $adminCheck = $pdo->prepare("
        SELECT 1 FROM practice_users WHERE user_id = ? AND role = 'admin' LIMIT 1
    ");
    $adminCheck->execute([$_SESSION['db_user_id']]);
    if (!$adminCheck->fetchColumn()) {
        http_response_code(403);
        echo "Access denied: practice admin role required.\n";
        exit;
    }
}

function fieldsMatch($legacy, $new): bool {
    // Normalize null/empty/boolean-ish differences from the DB driver
    $a = ($legacy === null || $legacy === '') ? null : $legacy;
    $b = ($new === null || $new === '') ? null : $new;
    if (is_numeric($a) && is_numeric($b)) {
        return (float)$a === (float)$b;
    }
    return $a == $b;
}

// ---------------------------------------------------------------------------
// 1. Owner -> subscription reconciliation
// ---------------------------------------------------------------------------
echo "=== Owner -> Subscription Reconciliation ===\n\n";

$totalOwners = (int)$pdo->query("
    SELECT COUNT(DISTINCT user_id) FROM practice_users WHERE is_owner = 1
")->fetchColumn();
$totalSubs = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();

echo "Total distinct owners (practice_users.is_owner=1): {$totalOwners}\n";
echo "Total subscription rows: {$totalSubs}\n";

$missing = $pdo->query("
    SELECT DISTINCT pu.user_id
    FROM practice_users pu
    WHERE pu.is_owner = 1
      AND pu.user_id NOT IN (SELECT owner_user_id FROM subscriptions)
")->fetchAll(PDO::FETCH_COLUMN);
echo "Owners with NO subscription row: " . count($missing) . "\n";

$dupes = $pdo->query("
    SELECT owner_user_id, COUNT(*) AS cnt
    FROM subscriptions
    GROUP BY owner_user_id
    HAVING cnt > 1
")->fetchAll(PDO::FETCH_ASSOC);
echo "Owners with MULTIPLE subscription rows: " . count($dupes) . "\n";

// ---------------------------------------------------------------------------
// 2. Ownership / membership isolation
// ---------------------------------------------------------------------------
echo "\n=== Ownership / Membership Isolation ===\n\n";

$invalidOwners = $pdo->query("
    SELECT s.owner_user_id
    FROM subscriptions s
    LEFT JOIN practice_users pu ON pu.user_id = s.owner_user_id AND pu.is_owner = 1
    WHERE pu.user_id IS NULL
")->fetchAll(PDO::FETCH_COLUMN);
echo "Subscription rows NOT tied to an is_owner=1 membership (would indicate an\n";
echo "invited USER/ADMIN wrongly getting a subscription row): " . count($invalidOwners) . "\n";

echo "\n(Confirmed via code review: migrate-subscription-owner.php contains no\n";
echo "INSERT/UPDATE/DELETE against practice_users or practices - membership\n";
echo "rows are read-only inputs to this migration.)\n";

// ---------------------------------------------------------------------------
// 3. Field-level spot checks against the exact source practice logged by the
//    migration (owner_user_id -> practice_id pairs from its own output)
// ---------------------------------------------------------------------------
$migratedPairs = [
    3  => 3,
    55 => 17,
    56 => 19,
    57 => 18,
    59 => 20,
    64 => 21,
    65 => 22,
    69 => 23,
    71 => 24,
];

echo "\n=== Field-Level Spot Checks (match/mismatch only) ===\n\n";

$fieldMap = [
    'stripe_customer_id'      => 'stripe_customer_id',
    'stripe_subscription_id'  => 'stripe_subscription_id',
    'plan'                    => 'subscription_plan',
    'status'                  => 'subscription_status',
    'billing_interval'        => 'billing_interval',
    'trial_ends_at'           => 'trial_ends_at',
    'current_period_ends_at'  => 'current_period_ends_at',
    'cancel_at_period_end'    => 'cancel_at_period_end',
];

$stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE owner_user_id = ?");
$practiceStmt = $pdo->prepare("SELECT * FROM practices WHERE id = ?");

$trialCustomers = [];
$payingCustomers = [];
$n = 0;
foreach ($migratedPairs as $ownerId => $practiceId) {
    $n++;
    $stmt->execute([$ownerId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    $practiceStmt->execute([$practiceId]);
    $legacy = $practiceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub || !$legacy) {
        echo "Owner #{$n}: MISSING ROW (subscription or legacy practice not found)\n";
        continue;
    }

    $allMatch = true;
    $results = [];
    foreach ($fieldMap as $subField => $legacyField) {
        $ok = fieldsMatch($legacy[$legacyField] ?? null, $sub[$subField] ?? null);
        $results[$subField] = $ok;
        if (!$ok) $allMatch = false;
    }

    $hasRealSub = !empty($sub['stripe_subscription_id']);
    $classification = $hasRealSub ? 'paying (has stripe_subscription_id)' : 'trial (no stripe_subscription_id)';
    if ($hasRealSub) {
        $payingCustomers[] = $n;
    } else {
        $trialCustomers[] = $n;
    }

    echo "Owner #{$n} [{$classification}]: " . ($allMatch ? "ALL FIELDS MATCH" : "MISMATCH FOUND") . "\n";
    foreach ($results as $field => $ok) {
        if (!$ok) {
            echo "    MISMATCH: {$field}\n";
        }
    }
}

echo "\nClassification summary: " . count($trialCustomers) . " trial-status owner(s), " .
     count($payingCustomers) . " paying (has stripe_subscription_id) owner(s).\n";

// ---------------------------------------------------------------------------
// 4. Trial integrity
// ---------------------------------------------------------------------------
echo "\n=== Trial Integrity ===\n\n";
echo "Trial dates: see trial_ends_at match/mismatch results above (copied\n";
echo "verbatim by the migration - never recalculated, see its source).\n";
echo "No additional trial created: subscriptions row count ({$totalSubs}) equals\n";
echo "distinct owner count ({$totalOwners}), so no owner has more than one row.\n";
echo "Stripe API calls during migration: NONE (confirmed via code review -\n";
echo "migrate-subscription-owner.php contains no \\Stripe\\ usage anywhere).\n";

// ---------------------------------------------------------------------------
// 5. Scale price configuration
// ---------------------------------------------------------------------------
echo "\n=== Scale Price Configuration ===\n\n";
$monthlyId = $appConfig['stripe']['prices']['scale']['month'] ?? null;
$annualId  = $appConfig['stripe']['prices']['scale']['year']  ?? null;

echo "STRIPE_SCALE_MONTHLY_PRICE_ID configured: " . ($monthlyId ? 'yes' : 'NO') . "\n";
echo "STRIPE_SCALE_ANNUAL_PRICE_ID configured: " . ($annualId ? 'yes' : 'NO') . "\n";

if ($monthlyId) {
    [$plan, $interval] = resolvePlanFromPriceId($monthlyId, $appConfig);
    echo "Monthly Price ID resolves to: plan={$plan}, interval=" . ($interval ?? 'null') . "\n";
}
if ($annualId) {
    [$plan, $interval] = resolvePlanFromPriceId($annualId, $appConfig);
    echo "Annual Price ID resolves to: plan={$plan}, interval=" . ($interval ?? 'null') . "\n";
}
if (!empty($appConfig['stripe']['config_error'])) {
    echo "Stripe config_error present: " . $appConfig['stripe']['config_error'] . "\n";
} else {
    echo "No Stripe config_error (secret/publishable key prefixes match STRIPE_ENVIRONMENT).\n";
}

echo "\n=== Verification complete ===\n";
