<?php
/**
 * Marketing Outreach administration tables.
 *
 * - marketing_templates: reusable outreach email templates (initial /
 *   follow-up). The branded footer + unsubscribe are applied at send
 *   time by MarketingFooter and are NOT stored in the body.
 * - marketing_campaigns: an outreach campaign prepared inside Platform
 *   Administration. Status moves draft -> ready -> approved -> sending ->
 *   completed|partial|failed. Approval binds a content hash so edited
 *   content cannot be sent under a stale approval.
 * - marketing_campaign_recipients: per-recipient individualized subject
 *   and HTML body (edits are isolated per recipient), send state, Gmail
 *   message/thread ids for follow-up threading, and skip reasons.
 * - marketing_audit_log: platform-admin action audit for campaign
 *   create/edit/approve/send events.
 *
 * Repeatable (CREATE TABLE IF NOT EXISTS). The Google Sheet remains the
 * prospect source of truth; these tables only hold campaign work product
 * and send results.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS marketing_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  kind ENUM('initial','followup') NOT NULL DEFAULT 'initial',
  subject_tpl VARCHAR(255) NOT NULL,
  body_tpl MEDIUMTEXT NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_mt_kind (kind, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_campaigns (
  id CHAR(36) NOT NULL PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  kind ENUM('initial','followup') NOT NULL DEFAULT 'initial',
  status ENUM('draft','ready','approved','sending','completed','partial','failed') NOT NULL DEFAULT 'draft',
  created_by VARCHAR(190) NULL,
  approved_by VARCHAR(190) NULL,
  approved_at DATETIME NULL,
  content_hash CHAR(64) NULL,
  recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_mc_status (status),
  KEY ix_mc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_campaign_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id CHAR(36) NOT NULL,
  sheet_row INT UNSIGNED NULL,
  prospect_email VARCHAR(320) NOT NULL,
  prospect_org VARCHAR(255) NULL,
  prospect_contact VARCHAR(255) NULL,
  subject TEXT NULL,
  body_html MEDIUMTEXT NULL,
  body_text MEDIUMTEXT NULL,
  status ENUM('draft','ready','approved','sending','sent','failed','skipped','suppressed') NOT NULL DEFAULT 'draft',
  skip_reason VARCHAR(190) NULL,
  is_edited TINYINT(1) NOT NULL DEFAULT 0,
  gmail_message_id VARCHAR(80) NULL,
  gmail_thread_id VARCHAR(80) NULL,
  error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mcr_campaign_email (campaign_id, prospect_email),
  KEY ix_mcr_status (status),
  KEY ix_mcr_sheet_row (sheet_row)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_email VARCHAR(190) NOT NULL,
  action VARCHAR(60) NOT NULL,
  campaign_id CHAR(36) NULL,
  detail MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mal_campaign (campaign_id),
  KEY ix_mal_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
foreach (['marketing_templates','marketing_campaigns','marketing_campaign_recipients','marketing_audit_log'] as $t) {
    $cols = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
    echo "$t: $cols columns\n";
}
echo "migration complete\n";
