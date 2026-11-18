<?php
/**
 * Marketing CRM <-> DentaTrak USER registration link.
 *
 * Adds marketing_contacts.user_id (the numeric users.id) and
 * marketing_contacts.user_registered_at so a CRM contact can be durably
 * tied to a real DentaTrak user account - including users who registered
 * but have not created or joined a practice yet (org_id stays NULL;
 * marketing_contacts.org_id is already nullable).
 *
 * Additive and idempotent: safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$tbl = $pdo->query("SHOW TABLES LIKE 'marketing_contacts'");
if (!$tbl || $tbl->rowCount() === 0) {
    echo "marketing_contacts table missing - run the marketing CRM migrations first\n";
    exit(1);
}

$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM marketing_contacts")->fetchAll(PDO::FETCH_COLUMN) as $c) {
    $cols[$c] = true;
}

if (!isset($cols['user_id'])) {
    $pdo->exec("ALTER TABLE marketing_contacts
        ADD COLUMN user_id INT UNSIGNED NULL AFTER org_id,
        ADD KEY ix_mc_user (user_id)");
    echo "user_id column + index added\n";
} else {
    echo "user_id column already present\n";
}

if (!isset($cols['user_registered_at'])) {
    $pdo->exec("ALTER TABLE marketing_contacts
        ADD COLUMN user_registered_at DATE NULL AFTER source");
    echo "user_registered_at column added\n";
} else {
    echo "user_registered_at column already present\n";
}
echo "done\n";
