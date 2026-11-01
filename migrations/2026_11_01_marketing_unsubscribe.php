<?php
/**
 * Marketing suppression + unsubscribe tokens + durable Sheets sync queue.
 * Repeatable (CREATE TABLE IF NOT EXISTS). Ported from SiteRecord's model:
 * suppression is DentaTrak-authoritative; sheet sync is an async outbox.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS marketing_suppressions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_normalized VARCHAR(320) NOT NULL,
  unsubscribed_at DATETIME NOT NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'email_unsubscribe',
  campaign_ref VARCHAR(160) NULL,
  sync_status ENUM('pending','synced','no_match','failed','disabled') NOT NULL DEFAULT 'pending',
  sync_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sync_last_error VARCHAR(500) NULL,
  synced_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_supp_email (email_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_unsub_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  email_normalized VARCHAR(320) NOT NULL,
  campaign_ref VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  UNIQUE KEY ux_utok_hash (token_hash),
  KEY ix_utok_email (email_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_sync_queue (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  suppression_id INT UNSIGNED NOT NULL,
  status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY ix_syncq_due (status, next_attempt_at),
  KEY ix_syncq_supp (suppression_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
foreach (['marketing_suppressions', 'marketing_unsub_tokens', 'marketing_sync_queue'] as $t) {
    $cols = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
    echo "$t: $cols columns\n";
}
echo "migration complete\n";
