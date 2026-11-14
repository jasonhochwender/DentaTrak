<?php
// Reply tracking v2: bounce subtype, unsubscribe confidence, action taken.
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$add = function (string $table, string $col, string $ddl) use ($pdo) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    if (!(int)$s->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
};
$add('marketing_reply_events', 'bounce_kind', "VARCHAR(20) NULL AFTER gmail_url");
$add('marketing_reply_events', 'unsubscribe_confidence', "VARCHAR(20) NULL AFTER bounce_kind");
$add('marketing_reply_events', 'failed_recipient', "VARCHAR(320) NULL AFTER unsubscribe_confidence");
$add('marketing_reply_events', 'action_taken', "VARCHAR(40) NULL AFTER failed_recipient");
try { $pdo->exec("ALTER TABLE marketing_reply_events ADD KEY ix_mre_bounce (bounce_kind)"); } catch (Throwable $e) {}
echo "reply suppression schema ok\n";
