<?php
/**
 * Marketing Outreach: explicit acknowledgment for new-conversation
 * follow-ups.
 *
 *  - marketing_campaigns.new_thread_ack: set at approval time when the
 *    admin explicitly confirms that follow-up recipients without a
 *    verified original Gmail thread may start NEW conversations. The send
 *    path refuses to deliver unthreaded follow-ups without it.
 *
 * Additive and repeatable: guarded by information_schema checks.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

$has = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_campaigns' AND COLUMN_NAME='new_thread_ack'"
)->fetchColumn() === 1;

if (!$has) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN new_thread_ack TINYINT(1) NOT NULL DEFAULT 0 AFTER content_hash');
    echo "marketing_campaigns.new_thread_ack added\n";
} else {
    echo "marketing_campaigns.new_thread_ack already present\n";
}

echo "new-thread-ack migration complete\n";
