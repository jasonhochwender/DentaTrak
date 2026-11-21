<?php
/**
 * MCP OAuth refresh-token rotation support.
 *
 * Adds token-family tracking so a replayed (already-rotated) refresh
 * token can revoke its whole family, plus rotated_at for leeway-based
 * race handling. family_id backfills to each token's own id, so every
 * pre-existing refresh token becomes the root of its own family and
 * keeps working - no reauthorization required.
 *
 * Idempotent and additive.
 */
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$hasCol = function (string $table, string $col) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
};

if (!$hasCol('mcp_refresh_tokens', 'family_id')) {
    $pdo->exec("ALTER TABLE mcp_refresh_tokens ADD COLUMN family_id CHAR(36) NULL AFTER client_id, ADD INDEX idx_family (family_id)");
}
if (!$hasCol('mcp_refresh_tokens', 'rotated_at')) {
    $pdo->exec("ALTER TABLE mcp_refresh_tokens ADD COLUMN rotated_at DATETIME NULL AFTER rotated_to");
}
if (!$hasCol('mcp_access_tokens', 'family_id')) {
    $pdo->exec("ALTER TABLE mcp_access_tokens ADD COLUMN family_id CHAR(36) NULL AFTER client_id, ADD INDEX idx_family (family_id)");
}
// Every legacy token roots its own family; rotated children inherit it.
$pdo->exec("UPDATE mcp_refresh_tokens SET family_id=id WHERE family_id IS NULL");
echo "mcp token rotation columns ready\n";
