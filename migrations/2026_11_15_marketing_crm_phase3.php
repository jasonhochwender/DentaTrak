<?php
/**
 * Marketing Outreach CRM Phase 3: editing support.
 *
 *  - marketing_contacts.version: optimistic-concurrency counter for
 *    prospect edits (clients must echo the version they loaded).
 *  - marketing_campaign_recipients.contact_id: permanent CRM link so
 *    campaign membership never depends on an ambiguous email lookup.
 *    Nullable: sheet-era recipients keep NULL until Phase 4 cutover.
 *
 * Additive and repeatable: every change is guarded by
 * information_schema checks.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

function mo3_has_col(PDO $pdo, string $table, string $col): bool {
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $pdo->quote($table) . " AND COLUMN_NAME=" . $pdo->quote($col)
    )->fetchColumn() === 1;
}

if (!mo3_has_col($pdo, 'marketing_contacts', 'version')) {
    $pdo->exec('ALTER TABLE marketing_contacts ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at');
    echo "marketing_contacts.version added\n";
}

if (!mo3_has_col($pdo, 'marketing_campaign_recipients', 'contact_id')) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD COLUMN contact_id BIGINT UNSIGNED NULL AFTER campaign_id');
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD KEY ix_mcr_contact (contact_id)');
    echo "marketing_campaign_recipients.contact_id added\n";
}

echo "phase3 migration complete\n";
