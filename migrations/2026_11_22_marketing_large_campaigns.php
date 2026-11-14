<?php
/**
 * Large-campaign sending support.
 *
 * - marketing_campaigns.send_requested: admin-authorized send is active;
 *   the background worker drains remaining recipients in paced batches.
 * - marketing_campaigns.send_paused: pause gate - the worker must not
 *   start another batch while set.
 * - Compound index for the worker's campaign scan and paged recipient
 *   reads on large campaigns.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

function lc_has_col(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

if (!lc_has_col($pdo, 'marketing_campaigns', 'send_requested')) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN send_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER failed_count');
    echo "marketing_campaigns.send_requested added\n";
} else echo "send_requested exists\n";

if (!lc_has_col($pdo, 'marketing_campaigns', 'send_paused')) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN send_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER send_requested');
    echo "marketing_campaigns.send_paused added\n";
} else echo "send_paused exists\n";

$idx = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_campaigns' AND INDEX_NAME='ix_mc_send'")->fetchColumn();
if (!(int)$idx) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD KEY ix_mc_send (status, send_requested, send_paused)');
    echo "ix_mc_send added\n";
} else echo "ix_mc_send exists\n";

$ridx = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_campaign_recipients' AND INDEX_NAME='ix_mcr_campaign_status'")->fetchColumn();
if (!(int)$ridx) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD KEY ix_mcr_campaign_status (campaign_id, status)');
    echo "ix_mcr_campaign_status added\n";
} else echo "ix_mcr_campaign_status exists\n";

echo "done\n";
