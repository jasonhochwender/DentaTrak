<?php
// Marketing reply tracking: detected inbound Gmail replies + sync state.
// Additive and repeatable. Checkpoint lives in marketing_settings.
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$pdo->exec("CREATE TABLE IF NOT EXISTS marketing_reply_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gmail_message_id VARCHAR(80) NOT NULL,
  gmail_thread_id VARCHAR(80) NULL,
  contact_id BIGINT UNSIGNED NULL,
  campaign_id CHAR(36) NULL,
  campaign_recipient_id BIGINT UNSIGNED NULL,
  matched_via VARCHAR(30) NOT NULL DEFAULT 'none',
  classification VARCHAR(30) NOT NULL,
  review_status VARCHAR(20) NOT NULL DEFAULT 'new',
  ambiguous TINYINT(1) NOT NULL DEFAULT 0,
  from_email VARCHAR(320) NULL,
  from_name VARCHAR(190) NULL,
  subject VARCHAR(500) NULL,
  snippet VARCHAR(600) NULL,
  gmail_internal_date DATETIME NULL,
  gmail_url VARCHAR(300) NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by VARCHAR(190) NULL,
  UNIQUE KEY ux_mre_msg (gmail_message_id),
  KEY ix_mre_review (review_status, classification),
  KEY ix_mre_contact (contact_id),
  KEY ix_mre_campaign (campaign_id),
  KEY ix_mre_thread (gmail_thread_id),
  KEY ix_mre_class_date (classification, gmail_internal_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

foreach (['reply_sync_history_id','reply_sync_last_success','reply_sync_last_error','reply_sync_last_run'] as $k) {
    $pdo->prepare('INSERT IGNORE INTO marketing_settings (sk,sval) VALUES (?,NULL)')->execute([$k]);
}
echo "reply tracking schema ok\n";
