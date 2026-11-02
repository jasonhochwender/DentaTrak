<?php
/**
 * One-off: targeted reprocess of Medit case 80e02fc7-... for practice 32.
 * The dateCreated-cursor poll never revisits already-seen cases, so files
 * attached after initial ingestion are invisible. Fetches the live case
 * detail through the practice-bound staging connection and re-ingests;
 * ScanIngestion dedups on dedup_key and INSERT IGNOREs new file rows
 * into the existing scan row.
 */
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/MeditConfig.php';
require_once __DIR__ . '/../api/integrations/medit/MeditClient.php';
require_once __DIR__ . '/../api/integrations/medit/MeditAuthService.php';
require_once __DIR__ . '/../api/integrations/medit/MeditScanConnector.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';

global $pdo;
$CASE = '80e02fc7-bb63-46dd-98f4-f0bc803d2e2a';

$conn = $pdo->query("SELECT * FROM integration_connections WHERE practice_id=32 AND provider='medit'")->fetch(PDO::FETCH_ASSOC);
if (!$conn) { echo "no medit connection\n"; exit(1); }
$connId = (int) $conn['id'];

$client = new MeditClient($pdo, $connId);
$detail = $client->getCase($CASE);
$files = $detail['files'] ?? [];
echo "api files[]: " . count($files) . "\n";
foreach ($files as $f) {
    echo "  " . ($f['fileType'] ?? '?') . " | " . ($f['name'] ?? '?') . " | " . ($f['size'] ?? '?') . "B\n";
}

$connector = new MeditScanConnector($pdo);
$m = new ReflectionMethod($connector, 'normalizeCase');
$m->setAccessible(true);
$payload = $m->invoke($connector, $detail, null);
echo "normalized files: " . count($payload['files'] ?? []) . "\n";

ScanIngestion::ingestScan($pdo, [
    'practice_id' => 32, 'connection_id' => $connId,
    'source_account' => null, 'is_synthetic' => false,
], $connector, $payload);

$rows = $pdo->query("SELECT id,file_name,status,declared_size FROM scanner_scan_files WHERE scan_id=(SELECT id FROM scanner_incoming_scans WHERE external_scan_id='$CASE')")->fetchAll(PDO::FETCH_ASSOC);
echo "stored file rows: " . count($rows) . "\n";
print_r($rows);
