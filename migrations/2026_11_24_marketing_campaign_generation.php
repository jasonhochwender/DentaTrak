<?php
/**
 * Background campaign message generation.
 *
 * - marketing_campaigns.gen_requested / gen_scope: an administrator
 *   queued THIS campaign for background draft generation; the send
 *   worker drains it in bounded batches. Generation never approves,
 *   authorizes, or sends.
 * - marketing_campaign_recipients.gen_attempts / gen_error: durable
 *   per-recipient generation outcome so failures are visible, bounded
 *   (CampaignGenerator::MAX_ATTEMPTS), and retryable without
 *   regenerating completed messages.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

function cg_has_col(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}
function cg_has_idx(PDO $pdo, string $table, string $idx): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $st->execute([$table, $idx]);
    return (int)$st->fetchColumn() > 0;
}

if (!cg_has_col($pdo, 'marketing_campaigns', 'gen_requested')) {
    $pdo->exec("ALTER TABLE marketing_campaigns ADD COLUMN gen_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER send_authorized_at");
    echo "gen_requested added\n";
} else echo "gen_requested exists\n";

if (!cg_has_col($pdo, 'marketing_campaigns', 'gen_scope')) {
    $pdo->exec("ALTER TABLE marketing_campaigns ADD COLUMN gen_scope VARCHAR(20) NOT NULL DEFAULT 'missing' AFTER gen_requested");
    echo "gen_scope added\n";
} else echo "gen_scope exists\n";

if (!cg_has_col($pdo, 'marketing_campaign_recipients', 'gen_attempts')) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD COLUMN gen_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_edited');
    echo "gen_attempts added\n";
} else echo "gen_attempts exists\n";

if (!cg_has_col($pdo, 'marketing_campaign_recipients', 'gen_error')) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD COLUMN gen_error VARCHAR(255) NULL AFTER gen_attempts');
    echo "gen_error added\n";
} else echo "gen_error exists\n";

if (!cg_has_idx($pdo, 'marketing_campaign_recipients', 'ix_mcr_gen')) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD KEY ix_mcr_gen (campaign_id, status, gen_attempts)');
    echo "ix_mcr_gen added\n";
} else echo "ix_mcr_gen exists\n";

echo "done\n";
