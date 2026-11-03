<?php
/** MCP (ChatGPT connector) auth + marketing batch tables. Idempotent. */
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
$ddl = [
  "CREATE TABLE IF NOT EXISTS mcp_auth_codes (
     id CHAR(36) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, client_id VARCHAR(64) NOT NULL,
     redirect_uri TEXT NOT NULL, code_hash CHAR(64) NOT NULL UNIQUE, pkce_challenge VARCHAR(128) NOT NULL,
     pkce_method VARCHAR(8) NOT NULL DEFAULT 'S256', expires_at DATETIME NOT NULL,
     consumed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     INDEX (expires_at))",
  "CREATE TABLE IF NOT EXISTS mcp_access_tokens (
     id CHAR(36) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, client_id VARCHAR(64) NOT NULL,
     token_hash CHAR(64) NOT NULL UNIQUE, scope VARCHAR(128) NOT NULL DEFAULT 'marketing',
     expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     INDEX (user_id), INDEX (expires_at))",
  "CREATE TABLE IF NOT EXISTS mcp_refresh_tokens (
     id CHAR(36) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, client_id VARCHAR(64) NOT NULL,
     token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL,
     revoked_at DATETIME NULL, rotated_to CHAR(36) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     INDEX (user_id))",
  "CREATE TABLE IF NOT EXISTS marketing_batches (
     id CHAR(36) PRIMARY KEY, user_id INT UNSIGNED NOT NULL, campaign VARCHAR(120) NOT NULL,
     subject TEXT NOT NULL, html MEDIUMTEXT NOT NULL, content_hash CHAR(64) NOT NULL,
     status VARCHAR(20) NOT NULL DEFAULT 'preview',
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
  "CREATE TABLE IF NOT EXISTS marketing_batch_recipients (
     id BIGINT AUTO_INCREMENT PRIMARY KEY, batch_id CHAR(36) NOT NULL,
     email VARCHAR(320) NOT NULL, eligible TINYINT NOT NULL, eligible_reason VARCHAR(80) NULL,
     status VARCHAR(20) NOT NULL DEFAULT 'pending', gmail_message_id VARCHAR(64) NULL,
     error VARCHAR(160) NULL, sheet_synced_at DATETIME NULL,
     UNIQUE KEY uq_batch_email (batch_id, email))",
];
foreach ($ddl as $sql) { $pdo->exec($sql); }
echo "mcp tables ready\n";
