<?php
/**
 * Campaign-scoped send authorization.
 *
 * - marketing_campaigns.send_authorized: an administrator explicitly
 *   authorized THIS campaign's recipients to receive mail while
 *   MARKETING_SENDING is on. It lifts only the recipient allowlist for
 *   sends whose campaign ref is this campaign; it never enables sending
 *   globally, never bypasses MARKETING_SENDING=false, suppression,
 *   eligibility, content-hash, or approval gates, and never applies to
 *   any other campaign.
 * - send_authorized_by / send_authorized_at: audit attribution.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

function sa_has_col(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

if (!sa_has_col($pdo, 'marketing_campaigns', 'send_authorized')) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN send_authorized TINYINT(1) NOT NULL DEFAULT 0 AFTER send_paused');
    echo "marketing_campaigns.send_authorized added\n";
} else echo "send_authorized exists\n";

if (!sa_has_col($pdo, 'marketing_campaigns', 'send_authorized_by')) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN send_authorized_by VARCHAR(190) NULL AFTER send_authorized');
    echo "send_authorized_by added\n";
} else echo "send_authorized_by exists\n";

if (!sa_has_col($pdo, 'marketing_campaigns', 'send_authorized_at')) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN send_authorized_at DATETIME NULL AFTER send_authorized_by');
    echo "send_authorized_at added\n";
} else echo "send_authorized_at exists\n";

echo "done\n";
