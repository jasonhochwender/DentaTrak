<?php
/**
 * Organization-level Do Not Contact.
 *
 * marketing_orgs.dnc* records an administrator's decision that an entire
 * CRM organization must not receive marketing outreach, with reason, date
 * and actor. marketing_org_suppressions (the existing domain store) gains
 * org_id/created_by so a domain suppression can be traced to the
 * organization and administrator it came from.
 *
 * Additive and idempotent: safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$add = function (string $table, string $col, string $ddl) use ($pdo): void {
    $c = $pdo->query("SHOW COLUMNS FROM $table LIKE " . $pdo->quote($col));
    if ($c && $c->rowCount() > 0) { echo "$table.$col already present\n"; return; }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $col $ddl");
    echo "$table.$col added\n";
};
$add('marketing_orgs', 'dnc', "TINYINT(1) NOT NULL DEFAULT 0");
$add('marketing_orgs', 'dnc_at', "DATETIME NULL");
$add('marketing_orgs', 'dnc_by', "VARCHAR(190) NULL");
$add('marketing_orgs', 'dnc_reason', "VARCHAR(255) NULL");
$add('marketing_org_suppressions', 'org_id', "INT UNSIGNED NULL");
$add('marketing_org_suppressions', 'created_by', "VARCHAR(190) NULL");
try { $pdo->exec("ALTER TABLE marketing_orgs ADD KEY ix_mo_dnc (dnc)"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE marketing_org_suppressions ADD KEY ix_mos_org (org_id)"); } catch (Throwable $e) {}
echo "done\n";
