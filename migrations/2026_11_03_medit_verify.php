<?php
/** Medit verification probe: connection scopes + scan patient fields. Read-only. */
require_once '/var/www/html/api/appConfig.php';
global $pdo;

$conn = $pdo->query("SELECT id, status, sync_enabled,
    JSON_UNQUOTE(JSON_EXTRACT(config_json,'$.token_scope')) AS scope,
    LENGTH(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config_json,'$.access_token')),'')) AS at_len,
    LENGTH(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config_json,'$.refresh_token')),'')) AS rt_len,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config_json,'$.token_expires_at')),'') AS at_exp,
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config_json,'$.group_uuid')),'') AS grp
    FROM integration_connections WHERE vendor='medit'")->fetchAll(PDO::FETCH_ASSOC);
echo "CONN:" . json_encode($conn) . "\n";

$scans = $pdo->query("SELECT id, external_scan_id, status, patient_name, patient_dob, patient_gender,
    file_recheck_attempts, LENGTH(COALESCE(order_items,'')) AS oi_len
    FROM scanner_incoming_scans WHERE provider='medit'")->fetchAll(PDO::FETCH_ASSOC);
echo "SCANS:" . json_encode($scans) . "\n";

$files = $pdo->query("SELECT f.id, f.scan_id, f.status, f.file_name, f.attached_case_id
    FROM scanner_scan_files f JOIN scanner_incoming_scans s ON s.id=f.scan_id WHERE s.provider='medit'")->fetchAll(PDO::FETCH_ASSOC);
echo "FILES:" . json_encode($files) . "\n";
