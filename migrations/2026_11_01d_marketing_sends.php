<?php
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
// send ledger: idempotency + campaign reporting
$pdo->exec("CREATE TABLE IF NOT EXISTS marketing_sends (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_ref VARCHAR(160) NOT NULL,
  email_normalized VARCHAR(320) NOT NULL,
  status ENUM('sent','skipped_suppressed','failed') NOT NULL,
  gmail_message_id VARCHAR(80) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_send_camp_email (campaign_ref, email_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// synthetic test cleanup
$pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized IN ('synthetic-e2e@devnull.invalid','oneclick-test@devnull.invalid')");
$c=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_sends'")->fetchColumn();
echo "marketing_sends: $c cols; synth=".($pdo->query("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized LIKE '%@devnull.invalid'")->fetchColumn())."\n";
