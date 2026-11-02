<?php
/** Verify: serialize scan row 3 through the real ScanInbox path (synthetic case only). */
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanInbox.php';
global $pdo;
$row = $pdo->query("SELECT * FROM scanner_incoming_scans WHERE id=3")->fetch(PDO::FETCH_ASSOC);
$m = new ReflectionMethod('ScanInbox', 'serializeScan');
$m->setAccessible(true);
$out = $m->invoke(null, $pdo, $row, null, true);
echo json_encode([
    'status' => $out['status'],
    'patientFirstName' => $out['patientFirstName'],
    'patientLastName' => $out['patientLastName'],
    'patientDob' => $out['patientDob'],
    'externalPatientRef' => $out['externalPatientRef'],
    'instructions' => $out['instructions'],
    'orderItems' => $out['orderItems'],
    'attachableFiles' => $out['attachableFiles'],
    'files' => array_map(function ($f) {
        return ['id' => $f['id'], 'status' => $f['status'], 'attachedCaseId' => $f['attachedCaseId']];
    }, $out['files']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
