<?php
/** Adds patient_gender (encrypted, like patient_dob) to scanner_incoming_scans. */
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;
$cols = $pdo->query("SHOW COLUMNS FROM scanner_incoming_scans LIKE 'patient_gender'")->fetchAll(PDO::FETCH_COLUMN);
if (!$cols) {
    $pdo->exec("ALTER TABLE scanner_incoming_scans ADD COLUMN patient_gender TEXT NULL DEFAULT NULL AFTER patient_dob");
    echo "added patient_gender\n";
} else {
    echo "patient_gender already exists\n";
}
