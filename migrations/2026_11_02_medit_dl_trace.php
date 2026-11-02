<?php
/**
 * Diagnostic: run the REAL downloadFile pipeline path for file row 5 and
 * echo the exact failure point + byte counts. No URLs/tokens/filenames
 * beyond this synthetic case.
 */
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/MeditConfig.php';
require_once __DIR__ . '/../api/integrations/medit/MeditClient.php';
require_once __DIR__ . '/../api/integrations/medit/MeditAuthService.php';
require_once __DIR__ . '/../api/integrations/medit/MeditScanConnector.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanIngestion.php';
require_once __DIR__ . '/../api/integrations/scanners/ScannerConnectorInterface.php';
require_once __DIR__ . '/../api/integrations/scanners/ScannerDownloadException.php';
require_once __DIR__ . '/../api/integrations/scanners/ScanFileStore.php';

global $pdo;
$conn = $pdo->query("SELECT id FROM integration_connections WHERE practice_id=32 AND provider='medit'")->fetch(PDO::FETCH_ASSOC);
$file = $pdo->query("SELECT * FROM scanner_scan_files WHERE id=5")->fetch(PDO::FETCH_ASSOC);
echo "remote_ref=" . $file['remote_ref'] . " ext_id=" . substr($file['external_file_id'], 0, 12) . "…\n";

$connector = new MeditScanConnector($pdo);
try {
    $res = $connector->downloadFile([
        'practice_id' => 32, 'connection_id' => (int) $conn['id'],
    ], $file['external_file_id']);
    echo "OK bytes=" . strlen($res['content'] ?? '') . " ct=" . ($res['content_type'] ?? '?') . "\n";
} catch (Throwable $e) {
    echo "FAIL " . get_class($e) . ": " . $e->getMessage() . "\n";
}
