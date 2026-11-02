<?php
/** Adds order_items (JSON, encrypted like instructions) to scanner_incoming_scans. */
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
$cols = $pdo->query("SHOW COLUMNS FROM scanner_incoming_scans LIKE 'order_items'")->fetchAll(PDO::FETCH_COLUMN);
if (!$cols) {
    $pdo->exec("ALTER TABLE scanner_incoming_scans ADD COLUMN order_items TEXT NULL DEFAULT NULL AFTER instructions");
    echo "added order_items\n";
} else {
    echo "order_items already exists\n";
}
