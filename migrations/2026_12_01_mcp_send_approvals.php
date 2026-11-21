<?php
/**
 * MCP send_batch human-approval table + rate_limit_counters.
 *
 * marketing_send_approvals: two-phase human approval for MCP batch sends.
 * An MCP token can only create a 'pending' row; approval requires an
 * interactive admin session + CSRF via api/marketing-admin.php. Approval
 * is bound to batch_id + content_hash, single-use, and time-limited.
 *
 * rate_limit_counters: moved here from runtime lazy creation in
 * api/rate-limit.php so schema changes happen only at deploy time.
 *
 * Idempotent and additive - safe to re-run.
 */
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
$ddl = [
  "CREATE TABLE IF NOT EXISTS marketing_send_approvals (
     id CHAR(36) PRIMARY KEY,
     batch_id CHAR(36) NOT NULL,
     content_hash CHAR(64) NOT NULL,
     recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
     eligible_count INT UNSIGNED NOT NULL DEFAULT 0,
     requested_by_user_id INT UNSIGNED NOT NULL,
     requested_by_email VARCHAR(255) NOT NULL,
     status VARCHAR(20) NOT NULL DEFAULT 'pending',
     approved_by_user_id INT UNSIGNED NULL,
     approved_by_email VARCHAR(255) NULL,
     approved_at DATETIME NULL,
     consumed_at DATETIME NULL,
     denied_by_email VARCHAR(255) NULL,
     denied_at DATETIME NULL,
     expires_at DATETIME NOT NULL,
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     INDEX idx_batch (batch_id),
     INDEX idx_status_expiry (status, expires_at))",
  "CREATE TABLE IF NOT EXISTS rate_limit_counters (
     bucket VARCHAR(160) NOT NULL,
     window_id BIGINT UNSIGNED NOT NULL,
     hits INT UNSIGNED NOT NULL DEFAULT 0,
     PRIMARY KEY (bucket, window_id)) ENGINE=InnoDB",
];
foreach ($ddl as $sql) { $pdo->exec($sql); }
echo "mcp send approvals + rate limit counters ready\n";
