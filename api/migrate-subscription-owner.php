<?php
/**
 * Migration: Create the `subscriptions` table and backfill account-level
 * subscription/trial state from the legacy per-practice Stripe columns
 * that live on `practices`.
 *
 * Background: subscriptions used to be modeled per-practice (see
 * migrate-stripe-fields.php). They are now modeled per subscription OWNER
 * (practice_users.is_owner = 1) so that every practice a user owns shares
 * one plan, one trial, and one Stripe customer/subscription. This script
 * performs the one-time backfill; it does NOT remove or modify the legacy
 * `practices` Stripe columns, which remain in place (deprecated) until the
 * new model is verified.
 *
 * Run once via CLI or browser (admin-gated). Safe to re-run - the table
 * creation is idempotent and the backfill skips any owner who already has
 * a subscriptions row.
 *
 * Usage (CLI):
 *   php api/migrate-subscription-owner.php
 *
 * Ambiguity handling: if an owner already owns MULTIPLE practices and more
 * than one of them has distinct, non-null Stripe/subscription state, this
 * script cannot safely determine which one is authoritative - it reports
 * the conflict and skips that owner rather than guessing. Every other
 * owner is migrated normally. Re-run after resolving the conflict manually.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';

header('Content-Type: text/plain; charset=utf-8');

// Only allow admin users or CLI
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

$errors    = [];
$ambiguous = [];
$migrated  = 0;
$skipped   = 0;

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
        LIMIT 1
    ");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// 1. Create the subscriptions table
// ---------------------------------------------------------------------------
echo "=== subscriptions table ===\n\n";

if (tableExists($pdo, 'subscriptions')) {
    echo "  SKIP  subscriptions — table already exists\n";
} else {
    try {
        $pdo->exec("
            CREATE TABLE subscriptions (
                id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner_user_id            INT UNSIGNED NOT NULL
                                         COMMENT 'FK to users.id - the subscription owner/account. Exactly one row per owner.',
                stripe_customer_id       VARCHAR(255) NULL DEFAULT NULL COMMENT 'Stripe customer object ID (cus_...)',
                stripe_subscription_id   VARCHAR(255) NULL DEFAULT NULL COMMENT 'Stripe subscription ID (sub_...)',
                stripe_price_id          VARCHAR(255) NULL DEFAULT NULL COMMENT 'Stripe Price ID driving the subscription (price_...)',
                plan                     VARCHAR(64)  NULL DEFAULT NULL COMMENT 'operate | control | scale',
                billing_interval         ENUM('month','year') NULL DEFAULT NULL,
                status                   VARCHAR(32)  NULL DEFAULT NULL
                                         COMMENT 'trialing | active | past_due | unpaid | canceled | incomplete | incomplete_expired',
                trial_ends_at            DATETIME NULL DEFAULT NULL
                                         COMMENT 'UTC datetime the account-level DentaTrak trial ends. Set once, never reset - shared by every practice this owner creates.',
                current_period_ends_at   DATETIME NULL DEFAULT NULL,
                cancel_at_period_end     TINYINT(1) NOT NULL DEFAULT 0,
                subscription_updated_at  DATETIME NULL DEFAULT NULL,
                stripe_event_created     DATETIME NULL DEFAULT NULL
                                         COMMENT 'Stripe event.created timestamp of the last applied webhook event; stale-event ordering guard',
                created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_subscriptions_owner_user_id (owner_user_id),
                KEY idx_subscriptions_stripe_customer_id (stripe_customer_id),
                CONSTRAINT fk_subscriptions_owner_user FOREIGN KEY (owner_user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='One row per subscription owner/account. Practices owned (practice_users.is_owner=1) by this user share this single plan, trial, and billing cycle.'
        ");
        echo "  CREATE subscriptions\n";
    } catch (PDOException $e) {
        $errors[] = "  ERROR creating subscriptions: " . $e->getMessage();
        echo "  ERROR creating subscriptions: " . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// 2. Backfill: one subscriptions row per subscription owner
// ---------------------------------------------------------------------------
echo "\n=== Backfilling owner subscriptions from legacy practices columns ===\n\n";

$owners = $pdo->query("SELECT DISTINCT user_id FROM practice_users WHERE is_owner = 1")
              ->fetchAll(PDO::FETCH_COLUMN);

foreach ($owners as $ownerUserId) {
    $ownerUserId = (int)$ownerUserId;

    // Already migrated?
    $existsStmt = $pdo->prepare("SELECT 1 FROM subscriptions WHERE owner_user_id = :uid LIMIT 1");
    $existsStmt->execute(['uid' => $ownerUserId]);
    if ($existsStmt->fetchColumn()) {
        $skipped++;
        continue;
    }

    // All practices owned by this user
    $stmt = $pdo->prepare("
        SELECT p.id, p.stripe_customer_id, p.stripe_subscription_id, p.stripe_price_id,
               p.subscription_plan, p.billing_interval, p.subscription_status,
               p.trial_ends_at, p.current_period_ends_at, p.cancel_at_period_end,
               p.subscription_updated_at, p.stripe_event_created
        FROM practices p
        JOIN practice_users pu ON pu.practice_id = p.id
        WHERE pu.user_id = :uid AND pu.is_owner = 1
        ORDER BY p.id ASC
    ");
    $stmt->execute(['uid' => $ownerUserId]);
    $ownedPractices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ownedPractices)) {
        continue; // Should not happen given the DISTINCT query above
    }

    // Candidates: practices with any real subscription-identifying data.
    $candidates = array_values(array_filter($ownedPractices, function ($p) {
        return !empty($p['stripe_customer_id']) || !empty($p['stripe_subscription_id'])
            || !empty($p['subscription_status']) || !empty($p['trial_ends_at']);
    }));

    $source = null;

    if (count($candidates) <= 1) {
        // 0 or 1 candidate - unambiguous. Fall back to the first owned
        // practice (all-null legacy columns) if there were no candidates.
        $source = $candidates[0] ?? $ownedPractices[0];
    } else {
        // Multiple candidates - only safe if they all agree on the fields
        // that identify a distinct real-world subscription.
        $first = $candidates[0];
        $conflict = false;
        foreach ($candidates as $c) {
            if (
                $c['stripe_customer_id']     !== $first['stripe_customer_id'] ||
                $c['stripe_subscription_id'] !== $first['stripe_subscription_id'] ||
                $c['subscription_status']    !== $first['subscription_status'] ||
                $c['trial_ends_at']          !== $first['trial_ends_at']
            ) {
                $conflict = true;
                break;
            }
        }

        if ($conflict) {
            $practiceIds = implode(', ', array_column($candidates, 'id'));
            $ambiguous[] = "owner_user_id={$ownerUserId}: conflicting subscription data across practice IDs [{$practiceIds}] — SKIPPED, resolve manually";
            continue;
        }

        $source = $first;
    }

    try {
        $pdo->prepare("
            INSERT INTO subscriptions (
                owner_user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id,
                plan, billing_interval, status, trial_ends_at, current_period_ends_at,
                cancel_at_period_end, subscription_updated_at, stripe_event_created
            ) VALUES (
                :owner_user_id, :stripe_customer_id, :stripe_subscription_id, :stripe_price_id,
                :plan, :billing_interval, :status, :trial_ends_at, :current_period_ends_at,
                :cancel_at_period_end, :subscription_updated_at, :stripe_event_created
            )
        ")->execute([
            'owner_user_id'           => $ownerUserId,
            'stripe_customer_id'      => $source['stripe_customer_id'],
            'stripe_subscription_id'  => $source['stripe_subscription_id'],
            'stripe_price_id'         => $source['stripe_price_id'],
            'plan'                    => $source['subscription_plan'],
            'billing_interval'        => $source['billing_interval'],
            'status'                  => $source['subscription_status'],
            'trial_ends_at'           => $source['trial_ends_at'],
            'current_period_ends_at'  => $source['current_period_ends_at'],
            'cancel_at_period_end'    => $source['cancel_at_period_end'] ?? 0,
            'subscription_updated_at' => $source['subscription_updated_at'],
            'stripe_event_created'    => $source['stripe_event_created'],
        ]);
        $migrated++;
        echo "  MIGRATED owner_user_id={$ownerUserId} <- practice_id={$source['id']}\n";
    } catch (PDOException $e) {
        $errors[] = "  ERROR migrating owner_user_id={$ownerUserId}: " . $e->getMessage();
        echo "  ERROR migrating owner_user_id={$ownerUserId}: " . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n=== Migration complete ===\n";
echo "Migrated: {$migrated}\n";
echo "Already migrated (skipped): {$skipped}\n";

if (!empty($ambiguous)) {
    echo "\nAMBIGUOUS — NOT migrated, needs manual review:\n";
    foreach ($ambiguous as $a) {
        echo "  " . $a . "\n";
    }
}

if (empty($errors)) {
    echo "\nStatus: OK — no errors.\n";
} else {
    echo "\nStatus: COMPLETED WITH ERRORS\n";
    foreach ($errors as $err) {
        echo $err . "\n";
    }
}
