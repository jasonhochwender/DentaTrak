<?php
/**
 * Marketing CRM <-> DentaTrak practice registration link.
 *
 * Adds marketing_orgs.practice_id (the numeric practices.id) and
 * marketing_orgs.registered_at so a CRM organization can be durably tied
 * to a real DentaTrak registration. Registered organizations are excluded
 * from introductory prospecting and shown as 'registered' in the CRM.
 *
 * Additive and idempotent: safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$tbl = $pdo->query("SHOW TABLES LIKE 'marketing_orgs'");
if (!$tbl || $tbl->rowCount() === 0) {
    echo "marketing_orgs table missing - run the marketing CRM migrations first\n";
    exit(1);
}

$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM marketing_orgs")->fetchAll(PDO::FETCH_COLUMN) as $c) {
    $cols[$c] = true;
}

if (!isset($cols['practice_id'])) {
    $pdo->exec("ALTER TABLE marketing_orgs
        ADD COLUMN practice_id INT UNSIGNED NULL AFTER parent_org_id,
        ADD KEY ix_mo_practice (practice_id)");
    echo "practice_id column + index added\n";
} else {
    echo "practice_id column already present\n";
}

if (!isset($cols['registered_at'])) {
    $pdo->exec("ALTER TABLE marketing_orgs
        ADD COLUMN registered_at DATE NULL AFTER practice_id");
    echo "registered_at column added\n";
} else {
    echo "registered_at column already present\n";
}
echo "done\n";
