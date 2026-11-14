<?php
/**
 * Marketing Outreach: sender signature config + original-message date.
 *
 *  - marketing_settings: small key/value store for marketing-wide
 *    configuration. 'sender_signature' holds JSON {name,title,detail};
 *    MarketingSignature falls back to built-in defaults when absent.
 *  - marketing_campaign_recipients.thread_date: Date header of the
 *    verified original Gmail message, persisted by thread resolution so
 *    the UI can show when the conversation started.
 *
 * Additive and repeatable: guarded by information_schema checks.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

$pdo->exec("CREATE TABLE IF NOT EXISTS marketing_settings (
    sk VARCHAR(64) NOT NULL PRIMARY KEY,
    sval MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "marketing_settings ensured\n";

$has = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_campaign_recipients' AND COLUMN_NAME='thread_date'"
)->fetchColumn() === 1;

if (!$has) {
    $pdo->exec('ALTER TABLE marketing_campaign_recipients ADD COLUMN thread_date VARCHAR(40) NULL AFTER thread_subject');
    echo "marketing_campaign_recipients.thread_date added\n";
} else {
    echo "marketing_campaign_recipients.thread_date already present\n";
}

echo "signature migration complete\n";
