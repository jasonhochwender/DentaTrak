<?php
/**
 * Adds file_recheck_at/file_recheck_attempts to scanner_incoming_scans so
 * the sync can re-fetch case detail for scans still awaiting usable
 * files (files attached after initial ingestion are invisible to the
 * dateCreated-cursor poll). Idempotent.
 */
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
$cols = $pdo->query("SHOW COLUMNS FROM scanner_incoming_scans LIKE 'file_recheck%'")->fetchAll(PDO::FETCH_COLUMN);
if (count($cols) === 0) {
    $pdo->exec("ALTER TABLE scanner_incoming_scans
        ADD COLUMN file_recheck_at DATETIME NULL DEFAULT NULL,
        ADD COLUMN file_recheck_attempts INT NOT NULL DEFAULT 0");
    echo "added file_recheck columns\n";
} else {
    echo "file_recheck columns already exist\n";
}
