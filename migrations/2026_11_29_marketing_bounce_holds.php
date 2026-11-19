<?php
/**
 * Temporary email sending holds for ambiguous/temporary bounce handling.
 *
 * marketing_email_holds stores NON-permanent delivery-failure holds:
 * the address is blocked from marketing sends until hold_until expires,
 * but it is never written to the permanent marketing_suppressions store.
 * fail_count tracks repeated temporary failures; status 'escalated'
 * marks holds that need human review.
 *
 * Additive and idempotent: safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$tbl = $pdo->query("SHOW TABLES LIKE 'marketing_email_holds'");
if (!$tbl || $tbl->rowCount() === 0) {
    $pdo->exec("CREATE TABLE marketing_email_holds (
        email_normalized VARCHAR(320) NOT NULL PRIMARY KEY,
        source VARCHAR(60) NOT NULL,
        reason VARCHAR(200) NULL,
        fail_count INT NOT NULL DEFAULT 1,
        first_seen_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        hold_until DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY ix_meh_hold (status, hold_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "marketing_email_holds created\n";
} else {
    echo "marketing_email_holds already present\n";
}
// Marks delivery-failure events whose suppression/hold handling has been
// applied, so sync and reconciliation never process an event twice.
$col = $pdo->query("SHOW COLUMNS FROM marketing_reply_events LIKE 'bounce_processed_at'");
if (!$col || $col->rowCount() === 0) {
    $pdo->exec("ALTER TABLE marketing_reply_events ADD COLUMN bounce_processed_at DATETIME NULL AFTER action_taken");
    echo "marketing_reply_events.bounce_processed_at added\n";
} else {
    echo "marketing_reply_events.bounce_processed_at already present\n";
}
echo "done\n";
