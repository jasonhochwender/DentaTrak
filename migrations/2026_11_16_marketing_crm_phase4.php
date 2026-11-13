<?php
/**
 * Marketing Outreach CRM Phase 4: campaign wizard support.
 *
 *  - marketing_campaigns.template_id: optional per-campaign email
 *    template override (NULL = kind default, matching prior behavior).
 *  - marketing_campaigns.description: internal notes shown to admins.
 *
 * Additive and repeatable: every change is guarded by
 * information_schema checks.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

function mo4_has_col(PDO $pdo, string $table, string $col): bool {
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($table) . " AND COLUMN_NAME=" . $pdo->quote($col)
    )->fetchColumn() === 1;
}

if (!mo4_has_col($pdo, 'marketing_campaigns', 'template_id')) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN template_id INT UNSIGNED NULL AFTER content_hash');
    echo "marketing_campaigns.template_id added\n";
}

if (!mo4_has_col($pdo, 'marketing_campaigns', 'description')) {
    $pdo->exec('ALTER TABLE marketing_campaigns ADD COLUMN description TEXT NULL AFTER template_id');
    echo "marketing_campaigns.description added\n";
}

echo "phase4 migration complete\n";
