<?php
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanInbox.php';
global $pdo;
$row = $pdo->query("SELECT * FROM scanner_incoming_scans WHERE id=3")->fetch(PDO::FETCH_ASSOC);
$m = new ReflectionMethod('ScanInbox', 'serializeScan');
$m->setAccessible(true);
$out = $m->invoke(null, $pdo, $row, null, true);
echo json_encode(['status'=>$out['status'],'instructions'=>$out['instructions'],'orderItems'=>$out['orderItems'],'attachableFiles'=>$out['attachableFiles'],'files'=>array_map(function($f){return ['id'=>$f['id'],'status'=>$f['status']];},$out['files'])], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
