<?php
/**
 * Marketing send hardening. Run AFTER 2026_11_03_marketing_admin.php.
 *
 * marketing_sends (existing send ledger; rows are preserved):
 *   + idempotency_key     durable per-send key, sha256(campaign "\n" email),
 *                         UNIQUE; backfilled for existing rows
 *   + attempts            provider attempts made (0 = row predates this
 *                         migration and cannot be reconciled by send id)
 *   + attempt_started_at  when the current attempt claimed the row
 *   + gmail_thread_id     thread of the delivered message
 *   + last_error          last failure / reconciliation note
 *   ~ status              adds 'uncertain' (provider outcome unknown)
 *
 * marketing_campaign_recipients:
 *   ~ status              adds 'uncertain'
 *   + thread_status / thread_reason / thread_source / thread_subject /
 *     thread_rfc_message_id / thread_references
 *                         original-conversation resolution for follow-ups
 *   + sheet_sync_status / sheet_sync_attempts / sheet_sync_error /
 *     sheet_synced_at     durable Google Sheet write-back state
 *
 * Additive and repeatable: every step checks information_schema first. No
 * existing column is dropped or narrowed, and no row is deleted.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

$hasTable = function (string $t) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$t]);
    return (int)$s->fetchColumn() > 0;
};
$hasColumn = function (string $t, string $c) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$t, $c]);
    return (int)$s->fetchColumn() > 0;
};
$hasIndex = function (string $t, string $i) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $s->execute([$t, $i]);
    return (int)$s->fetchColumn() > 0;
};
$columnType = function (string $t, string $c) use ($pdo): string {
    $s = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$t, $c]);
    return (string)$s->fetchColumn();
};
$addColumn = function (string $t, string $c, string $ddl) use ($pdo, $hasColumn): void {
    if ($hasColumn($t, $c)) { echo "  = $t.$c present\n"; return; }
    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $ddl");
    echo "  + $t.$c added\n";
};

foreach (['marketing_sends', 'marketing_campaign_recipients'] as $required) {
    if (!$hasTable($required)) {
        fwrite(STDERR, "ABORT: $required does not exist. Run the earlier marketing migrations first.\n");
        exit(1);
    }
}

echo "marketing_sends\n";
$before = (int)$pdo->query('SELECT COUNT(*) FROM marketing_sends')->fetchColumn();
$addColumn('marketing_sends', 'idempotency_key', 'CHAR(64) NULL');
$addColumn('marketing_sends', 'attempts', 'INT UNSIGNED NOT NULL DEFAULT 0');
$addColumn('marketing_sends', 'attempt_started_at', 'DATETIME NULL');
$addColumn('marketing_sends', 'gmail_thread_id', 'VARCHAR(80) NULL');
$addColumn('marketing_sends', 'last_error', 'VARCHAR(255) NULL');
if (strpos($columnType('marketing_sends', 'status'), "'uncertain'") === false) {
    $pdo->exec("ALTER TABLE marketing_sends MODIFY status ENUM('running','sent','skipped_suppressed','failed','uncertain') NOT NULL");
    echo "  ~ marketing_sends.status now allows 'uncertain'\n";
} else {
    echo "  = marketing_sends.status already allows 'uncertain'\n";
}
$filled = $pdo->exec("UPDATE marketing_sends
    SET idempotency_key = SHA2(CONCAT(campaign_ref, CHAR(10), email_normalized), 256)
    WHERE idempotency_key IS NULL");
echo "  ~ idempotency_key backfilled on $filled row(s)\n";
if (!$hasIndex('marketing_sends', 'ux_send_idempotency')) {
    $pdo->exec('ALTER TABLE marketing_sends ADD UNIQUE KEY ux_send_idempotency (idempotency_key)');
    echo "  + unique index ux_send_idempotency added\n";
} else {
    echo "  = unique index ux_send_idempotency present\n";
}
$after = (int)$pdo->query('SELECT COUNT(*) FROM marketing_sends')->fetchColumn();
echo "  rows before/after: $before/$after\n";
if ($before !== $after) { fwrite(STDERR, "ABORT: marketing_sends row count changed.\n"); exit(1); }

echo "marketing_campaign_recipients\n";
if (strpos($columnType('marketing_campaign_recipients', 'status'), "'uncertain'") === false) {
    $pdo->exec("ALTER TABLE marketing_campaign_recipients MODIFY status
        ENUM('draft','ready','approved','sending','sent','failed','skipped','suppressed','uncertain') NOT NULL DEFAULT 'draft'");
    echo "  ~ status now allows 'uncertain'\n";
} else {
    echo "  = status already allows 'uncertain'\n";
}
$addColumn('marketing_campaign_recipients', 'thread_status', 'VARCHAR(20) NULL');
$addColumn('marketing_campaign_recipients', 'thread_reason', 'VARCHAR(80) NULL');
$addColumn('marketing_campaign_recipients', 'thread_source', 'VARCHAR(30) NULL');
$addColumn('marketing_campaign_recipients', 'thread_subject', 'VARCHAR(500) NULL');
$addColumn('marketing_campaign_recipients', 'thread_rfc_message_id', 'VARCHAR(320) NULL');
$addColumn('marketing_campaign_recipients', 'thread_references', 'TEXT NULL');
$addColumn('marketing_campaign_recipients', 'sheet_sync_status', "ENUM('none','pending','synced','failed') NOT NULL DEFAULT 'none'");
$addColumn('marketing_campaign_recipients', 'sheet_sync_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0');
$addColumn('marketing_campaign_recipients', 'sheet_sync_error', 'VARCHAR(255) NULL');
$addColumn('marketing_campaign_recipients', 'sheet_synced_at', 'DATETIME NULL');
if (!$hasIndex('marketing_campaign_recipients', 'ix_mcr_sheet_sync')) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD KEY ix_mcr_sheet_sync (sheet_sync_status)');
    echo "  + index ix_mcr_sheet_sync added\n";
} else {
    echo "  = index ix_mcr_sheet_sync present\n";
}
if (!$hasIndex('marketing_campaign_recipients', 'ix_mcr_email')) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD KEY ix_mcr_email (prospect_email(191))');
    echo "  + index ix_mcr_email added\n";
} else {
    echo "  = index ix_mcr_email present\n";
}

echo "migration complete\n";
