<?php
/**
 * Marketing Outreach CRM Phase 2: promote high-value sheet columns to
 * first-class contact columns and index the fields Phase 2 reads filter on.
 *
 * Promoted from extra_fields (values backfilled from the Phase 1 import):
 *   demo_status, trial_status, outcome, objection_reason,
 *   unsubscribed_at, unsubscribe_source, contact_notes
 *
 * practice_type is intentionally NOT a contact column: the sheet's
 * "Practice Type" specialty was already captured per-organization in
 * marketing_orgs.org_type by the Phase 1 importer.
 *
 * Additive and repeatable: every ALTER is guarded by information_schema
 * checks so the migration is safe to re-run.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

$existing = [];
foreach ($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
    $existing[$c] = true;
}
$order = ['demo_status','trial_status','outcome','objection_reason','unsubscribed_at','unsubscribe_source','contact_notes'];
$defs = [
    'demo_status'        => 'VARCHAR(60) NULL',
    'trial_status'       => 'VARCHAR(60) NULL',
    'outcome'            => 'VARCHAR(120) NULL',
    'objection_reason'   => 'VARCHAR(255) NULL',
    'unsubscribed_at'    => 'DATE NULL',
    'unsubscribe_source' => 'VARCHAR(60) NULL',
    'contact_notes'      => 'MEDIUMTEXT NULL',
];
foreach ($order as $col) {
    if (isset($existing[$col])) {
        // Widen columns created by an earlier revision of this migration.
        if ($col === 'outcome') {
            $len = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts' AND COLUMN_NAME='outcome'")->fetchColumn();
            if ($len !== false && (int)$len < 120) {
                $pdo->exec("ALTER TABLE marketing_contacts MODIFY COLUMN outcome VARCHAR(120) NULL");
                echo "marketing_contacts.outcome widened to 120\n";
            }
        }
        continue;
    }
    $pdo->exec("ALTER TABLE marketing_contacts ADD COLUMN $col {$defs[$col]}");
    echo "marketing_contacts.$col added\n";
}

// Backfill promoted columns from the Phase 1 extra_fields archive.
// JSON_EXTRACT keys are the original sheet headers.
$backfill = [
    'demo_status'        => 'Demo Status',
    'trial_status'       => 'Trial Status',
    'outcome'            => 'Outcome',
    'objection_reason'   => 'Objection/Lost Reason',
    'unsubscribe_source' => 'Unsubscribe Source',
    'contact_notes'      => 'Contact Notes',
];
foreach ($backfill as $col => $header) {
    $key = '$."' . str_replace('"', '', $header) . '"';
    // Only fill rows not already populated (idempotent).
    $pdo->exec("UPDATE marketing_contacts SET $col = JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$key'))
                WHERE extra_fields IS NOT NULL
                  AND JSON_EXTRACT(extra_fields, '$key') IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$key')) <> ''
                  AND ($col IS NULL OR $col = '')");
    $n = $pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE $col IS NOT NULL AND $col <> ''")->fetchColumn();
    echo "marketing_contacts.$col backfilled: $n rows\n";
}

// unsubscribed_at is a DATE: only ISO-shaped values are promoted; anything
// else stays NULL (the raw value remains in extra_fields). Non-ISO sheet
// dates are parsed by the importer on subsequent runs.
$pdo->exec("UPDATE marketing_contacts SET unsubscribed_at = STR_TO_DATE(LEFT(JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$.\"Unsubscribed At\"')), 10), '%Y-%m-%d')
            WHERE unsubscribed_at IS NULL
              AND extra_fields IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(extra_fields, '$.\"Unsubscribed At\"')) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'");
$n = $pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE unsubscribed_at IS NOT NULL")->fetchColumn();
echo "marketing_contacts.unsubscribed_at backfilled: $n rows\n";

// Org-type filter support for Phase 2 reads.
$hasOt = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_orgs' AND INDEX_NAME='ix_mo_orgtype'")->fetchColumn();
if (!$hasOt) {
    $pdo->exec("ALTER TABLE marketing_orgs ADD KEY ix_mo_orgtype (org_type)");
    echo "marketing_orgs.ix_mo_orgtype added\n";
}

foreach (['marketing_contacts','marketing_orgs'] as $t) {
    $cols = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
    echo "$t: $cols columns\n";
}
