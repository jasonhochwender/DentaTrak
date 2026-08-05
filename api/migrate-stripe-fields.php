<?php
/**
 * Migration: Add Stripe subscription fields to practices table
 *             and create stripe_webhook_events table.
 *
 * Run once via CLI or browser (admin-gated). Safe to re-run — all
 * ALTER TABLE statements use IF NOT EXISTS / column-existence checks.
 *
 * Usage (CLI):
 *   php api/migrate-stripe-fields.php
 *
 * Do NOT run in production until Stripe integration is approved.
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
    // Restrict to admin role in any practice
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

$errors   = [];
$messages = [];

// ---------------------------------------------------------------------------
// Helper: check if a column already exists in a table
// ---------------------------------------------------------------------------
function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = :table
          AND COLUMN_NAME  = :column
        LIMIT 1
    ");
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (bool) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Helper: check if a table exists
// ---------------------------------------------------------------------------
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = :table
        LIMIT 1
    ");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// 1. Add Stripe subscription columns to the practices table
// ---------------------------------------------------------------------------
$stripeColumns = [
    'stripe_customer_id'      => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Stripe customer object ID (cus_...)'",
    'stripe_subscription_id'  => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Stripe subscription ID (sub_...)'",
    'stripe_price_id'         => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'Stripe Price ID driving the subscription (price_...)'",
    'subscription_plan'       => "VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Human-readable plan slug: operate | control'",
    'billing_interval'        => "ENUM('month','year') NULL DEFAULT NULL COMMENT 'Billing cadence selected by the practice'",
    'subscription_status'     => "VARCHAR(32)  NULL DEFAULT NULL COMMENT 'Stripe subscription status: trialing | active | past_due | canceled | unpaid'",
    'trial_ends_at'           => "DATETIME     NULL DEFAULT NULL COMMENT 'UTC datetime when the Stripe trial period ends'",
    'current_period_ends_at'  => "DATETIME     NULL DEFAULT NULL COMMENT 'UTC datetime of the current billing period end'",
    'cancel_at_period_end'    => "TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = subscription will cancel at period end, 0 = renewing'",
    'subscription_updated_at' => "DATETIME     NULL DEFAULT NULL COMMENT 'UTC datetime of last webhook update to subscription fields'",
    'stripe_event_created'    => "DATETIME     NULL DEFAULT NULL COMMENT 'Stripe event created timestamp of the last applied webhook event; used for stale-event ordering guard'",
];

echo "=== practices table: adding Stripe columns ===\n\n";

foreach ($stripeColumns as $column => $definition) {
    if (columnExists($pdo, 'practices', $column)) {
        $messages[] = "  SKIP  practices.{$column} — already exists";
        echo "  SKIP  practices.{$column} — already exists\n";
        continue;
    }
    try {
        $pdo->exec("ALTER TABLE practices ADD COLUMN {$column} {$definition}");
        $messages[] = "  ADD   practices.{$column}";
        echo "  ADD   practices.{$column}\n";
    } catch (PDOException $e) {
        $errors[] = "  ERROR practices.{$column}: " . $e->getMessage();
        echo "  ERROR practices.{$column}: " . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// 2. Add index on stripe_customer_id for webhook lookups (idempotent)
// ---------------------------------------------------------------------------
echo "\n=== practices table: adding indexes ===\n\n";

$indexCheck = $pdo->prepare("
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'practices'
      AND INDEX_NAME   = 'idx_practices_stripe_customer_id'
    LIMIT 1
");
$indexCheck->execute();
if (!$indexCheck->fetchColumn()) {
    try {
        $pdo->exec("
            ALTER TABLE practices
            ADD INDEX idx_practices_stripe_customer_id (stripe_customer_id)
        ");
        echo "  ADD   INDEX idx_practices_stripe_customer_id\n";
    } catch (PDOException $e) {
        $errors[] = "  ERROR adding index: " . $e->getMessage();
        echo "  ERROR adding index: " . $e->getMessage() . "\n";
    }
} else {
    echo "  SKIP  INDEX idx_practices_stripe_customer_id — already exists\n";
}

// ---------------------------------------------------------------------------
// 3. Create stripe_webhook_events table for idempotent event processing
// ---------------------------------------------------------------------------
echo "\n=== stripe_webhook_events table ===\n\n";

if (tableExists($pdo, 'stripe_webhook_events')) {
    echo "  SKIP  stripe_webhook_events — table already exists\n";
} else {
    try {
        $pdo->exec("
            CREATE TABLE stripe_webhook_events (
                id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                stripe_event_id   VARCHAR(255)     NOT NULL COMMENT 'Unique Stripe event ID (evt_...)',
                event_type        VARCHAR(128)     NOT NULL COMMENT 'e.g. customer.subscription.updated',
                processing_status    ENUM('pending','processed','failed','skipped')
                                     NOT NULL DEFAULT 'pending'
                                     COMMENT 'Current processing state of this event',
                stripe_event_created BIGINT           NULL DEFAULT NULL
                                     COMMENT 'Unix timestamp from event.created; stored for audit and ordering',
                processed_at         DATETIME         NULL DEFAULT NULL
                                     COMMENT 'UTC datetime when processing completed (success or permanent failure)',
                error_message        TEXT             NULL DEFAULT NULL
                                     COMMENT 'Sanitized error detail if processing_status = failed',
                created_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_stripe_event_id (stripe_event_id),
                KEY idx_swh_status_created (processing_status, created_at),
                KEY idx_swh_event_type (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Idempotency log for incoming Stripe webhook events'
        ");
        echo "  CREATE stripe_webhook_events\n";
    } catch (PDOException $e) {
        $errors[] = "  ERROR creating stripe_webhook_events: " . $e->getMessage();
        echo "  ERROR creating stripe_webhook_events: " . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// 4. Add stripe_event_created column to stripe_webhook_events if the table
//    already existed before this column was introduced.
// ---------------------------------------------------------------------------
echo "\n=== stripe_webhook_events table: adding stripe_event_created column ===\n\n";

if (tableExists($pdo, 'stripe_webhook_events')) {
    if (columnExists($pdo, 'stripe_webhook_events', 'stripe_event_created')) {
        echo "  SKIP  stripe_webhook_events.stripe_event_created — already exists\n";
    } else {
        try {
            $pdo->exec("
                ALTER TABLE stripe_webhook_events
                ADD COLUMN stripe_event_created BIGINT NULL DEFAULT NULL
                    COMMENT 'Unix timestamp from event.created; stored for audit and ordering'
                AFTER processing_status
            ");
            echo "  ADD   stripe_webhook_events.stripe_event_created\n";
        } catch (PDOException $e) {
            $errors[] = "  ERROR stripe_webhook_events.stripe_event_created: " . $e->getMessage();
            echo "  ERROR stripe_webhook_events.stripe_event_created: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "  SKIP  stripe_webhook_events does not exist yet (will be created with column in step 3)\n";
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n=== Migration complete ===\n";
if (empty($errors)) {
    echo "Status: OK — no errors.\n";
} else {
    echo "Status: COMPLETED WITH ERRORS\n";
    foreach ($errors as $err) {
        echo $err . "\n";
    }
}
