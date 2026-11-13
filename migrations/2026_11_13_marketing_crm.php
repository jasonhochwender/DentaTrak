<?php
/**
 * Marketing Outreach CRM foundation tables (Phase 1).
 *
 * - marketing_orgs: organizations (dental practices, DSOs/groups) with a
 *   self-referencing parent for DSO -> practice relationships.
 * - marketing_contacts: people at those organizations. Identity is the
 *   permanent surrogate key; email_normalized is indexed for dedupe but
 *   intentionally NOT unique - the source sheet contains shared and
 *   missing addresses that must be reviewable, not silently merged.
 * - marketing_prospect_events: append-only activity (imports, notes,
 *   calls, meetings, status changes, connector updates). dedupe_key makes
 *   event writes idempotent across repeated imports.
 * - marketing_import_runs / marketing_import_rows: every source sheet row
 *   gets an explicit disposition so reconciliation can account for 100%
 *   of source records.
 *
 * Additive and repeatable (CREATE TABLE IF NOT EXISTS). Nothing here
 * touches the existing campaign/send/suppression pipeline; those tables
 * keep their email_normalized references until a later, approved cutover
 * adds contact_id foreign keys.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS marketing_orgs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  parent_org_id INT UNSIGNED NULL,
  org_type VARCHAR(60) NULL,
  website VARCHAR(500) NULL,
  domain VARCHAR(255) NULL,
  location VARCHAR(255) NULL,
  phone VARCHAR(60) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'active',
  source VARCHAR(60) NOT NULL DEFAULT 'sheet_import',
  tags JSON NULL,
  sheet_row INT UNSIGNED NULL,
  needs_review TINYINT(1) NOT NULL DEFAULT 0,
  review_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_mo_parent (parent_org_id),
  KEY ix_mo_normname (normalized_name),
  KEY ix_mo_domain (domain),
  KEY ix_mo_sheet_row (sheet_row),
  CONSTRAINT fk_mo_parent FOREIGN KEY (parent_org_id) REFERENCES marketing_orgs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_contacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  first_name VARCHAR(120) NULL,
  last_name VARCHAR(120) NULL,
  full_name VARCHAR(255) NULL,
  email VARCHAR(320) NULL,
  email_normalized VARCHAR(320) NULL,
  title VARCHAR(120) NULL,
  phone VARCHAR(60) NULL,
  status VARCHAR(60) NOT NULL DEFAULT 'new',
  priority VARCHAR(40) NULL,
  fit VARCHAR(40) NULL,
  owner_email VARCHAR(190) NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'sheet_import',
  tags JSON NULL,
  notes MEDIUMTEXT NULL,
  dnc TINYINT(1) NOT NULL DEFAULT 0,
  first_contact_date DATE NULL,
  last_contact_date DATE NULL,
  followup_date DATE NULL,
  response VARCHAR(255) NULL,
  campaign_ref VARCHAR(160) NULL,
  outreach_method VARCHAR(60) NULL,
  sheet_row INT UNSIGNED NULL,
  needs_review TINYINT(1) NOT NULL DEFAULT 0,
  review_reason VARCHAR(255) NULL,
  extra_fields JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_mc_org (org_id),
  KEY ix_mc_email_norm (email_normalized(191)),
  KEY ix_mc_status (status),
  KEY ix_mc_priority (priority),
  KEY ix_mc_owner (owner_email),
  KEY ix_mc_sheet_row (sheet_row),
  KEY ix_mc_followup (followup_date),
  KEY ix_mc_name (full_name),
  CONSTRAINT fk_mc_org FOREIGN KEY (org_id) REFERENCES marketing_orgs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_prospect_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NULL,
  org_id INT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  details MEDIUMTEXT NULL,
  actor VARCHAR(190) NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'sheet_import',
  dedupe_key VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_mpe_dedupe (dedupe_key),
  KEY ix_mpe_contact (contact_id, created_at),
  KEY ix_mpe_org (org_id),
  KEY ix_mpe_type (event_type),
  CONSTRAINT fk_mpe_contact FOREIGN KEY (contact_id) REFERENCES marketing_contacts (id),
  CONSTRAINT fk_mpe_org FOREIGN KEY (org_id) REFERENCES marketing_orgs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_import_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mode VARCHAR(10) NOT NULL DEFAULT 'dry_run',
  source VARCHAR(60) NOT NULL DEFAULT 'google_sheet',
  stats_json MEDIUMTEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY ix_mir_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_import_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  sheet_row INT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  org_id INT UNSIGNED NULL,
  disposition VARCHAR(30) NOT NULL,
  reason VARCHAR(190) NULL,
  field_hash CHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_mir_run_row (run_id, sheet_row),
  KEY ix_mir_contact (contact_id),
  KEY ix_mir_disposition (disposition),
  CONSTRAINT fk_mir_run FOREIGN KEY (run_id) REFERENCES marketing_import_runs (id),
  CONSTRAINT fk_mir_contact FOREIGN KEY (contact_id) REFERENCES marketing_contacts (id),
  CONSTRAINT fk_mir_org FOREIGN KEY (org_id) REFERENCES marketing_orgs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
// Additive column for sheet headers outside the canonical field map
// (research metadata, unsubscribe audit columns, etc.) - guarded so the
// migration stays repeatable on databases where the table pre-exists.
$hasExtra = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts' AND COLUMN_NAME='extra_fields'")->fetchColumn();
if (!$hasExtra) {
    $pdo->exec("ALTER TABLE marketing_contacts ADD COLUMN extra_fields JSON NULL AFTER review_reason");
}
// Response status values in the source sheet can exceed 120 chars.
$respType = $pdo->query("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts' AND COLUMN_NAME='response'")->fetch(PDO::FETCH_ASSOC);
if ($respType && ($respType['DATA_TYPE'] !== 'varchar' || (int)$respType['CHARACTER_MAXIMUM_LENGTH'] < 255)) {
    $pdo->exec("ALTER TABLE marketing_contacts MODIFY COLUMN response VARCHAR(255) NULL");
}
foreach (['marketing_orgs','marketing_contacts','marketing_prospect_events','marketing_import_runs','marketing_import_rows'] as $t) {
    $cols = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
    $fks  = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND CONSTRAINT_TYPE='FOREIGN KEY'")->fetchColumn();
    echo "$t: $cols columns, $fks fks\n";
}
