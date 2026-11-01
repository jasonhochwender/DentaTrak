<?php
/**
 * Authoritative organization-level marketing suppression.
 * org_ref is a stable normalized identifier — the prospect org's email
 * domain (e.g. "sagedentalnj.com"), NOT the display name; unrelated orgs
 * on different domains (e.g. mysagedental.com) are never matched.
 * Repeatable (CREATE TABLE IF NOT EXISTS). Existing email suppressions
 * are untouched.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS marketing_org_suppressions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_ref VARCHAR(253) NOT NULL,
  source VARCHAR(60) NOT NULL DEFAULT 'email_unsubscribe',
  reason VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_org_ref (org_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
$cols = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_org_suppressions'")->fetchColumn();
echo "marketing_org_suppressions: $cols columns\nmigration complete\n";
