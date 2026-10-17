<?php
/**
 * Scan File Retry Worker (CLI)
 *
 * Re-attempts pending/failed (under the attempt cap) scan file downloads.
 * Mirrors the local invocation convention of process-events.php:
 *
 *   php api/integrations/process-scans.php [--practice=N] [--limit=25]
 *
 * FLAG/ENV ENFORCEMENT: refuses to run unless SCANNER_INBOX is enabled, and
 * only resolves connectors that are legal in this environment (today: the
 * dev-only simulated connector; real vendors resolve once their adapters
 * exist). In production it can be run, but with the flag off it exits
 * immediately - the flag check is in the worker, not just the UI.
 *
 * Connector resolution per vendor:
 *   'simulated' -> SimulatedScannerConnector (only when isAvailable()).
 *   others      -> no adapter yet; scans are skipped with a logged note.
 */

require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/scanners/ScanIngestion.php';
require_once __DIR__ . '/scanners/SimulatedScannerConnector.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CLI only']);
    exit;
}

if (!isFeatureEnabled('SCANNER_INBOX')) {
    fwrite(STDOUT, json_encode(['success' => false, 'skipped' => true, 'reason' => 'feature_disabled']) . PHP_EOL);
    exit(0);
}

global $pdo;
if (!$pdo) {
    fwrite(STDERR, json_encode(['success' => false, 'message' => 'db unavailable']) . PHP_EOL);
    exit(1);
}

$opts = getopt('', ['practice::', 'limit::']);
$practiceFilter = isset($opts['practice']) ? (int)$opts['practice'] : 0;
$limit = isset($opts['limit']) ? max(1, min(200, (int)$opts['limit'])) : 25;

$sql = "SELECT * FROM scanner_incoming_scans
        WHERE status IN (:received, :processing, :failed)";
$params = [
    ':received'   => ScanIngestion::SCAN_RECEIVED,
    ':processing' => ScanIngestion::SCAN_PROCESSING,
    ':failed'     => ScanIngestion::SCAN_FAILED,
];
if ($practiceFilter > 0) {
    $sql .= " AND practice_id = :pid";
    $params[':pid'] = $practiceFilter;
}
$sql .= " ORDER BY updated_at ASC LIMIT " . (int)$limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$scans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$skipped = 0;
$connectors = [];

foreach ($scans as $scan) {
    $vendor = strtolower((string)$scan['vendor']);

    if (!array_key_exists($vendor, $connectors)) {
        $connectors[$vendor] = null;
        if ($vendor === SimulatedScannerConnector::VENDOR && SimulatedScannerConnector::isAvailable()) {
            try {
                $connectors[$vendor] = new SimulatedScannerConnector();
            } catch (Throwable $e) {
                $connectors[$vendor] = null;
            }
        }
    }
    $connector = $connectors[$vendor];
    if (!$connector) {
        $skipped++;
        continue;
    }

    ScanIngestion::processPendingFiles($pdo, $scan, $connector, [
        'practice_id'    => (int)$scan['practice_id'],
        'connection_id'  => $scan['connection_id'],
        'source_account' => $scan['source_account'],
        'is_synthetic'   => !empty($scan['is_synthetic']),
    ]);
    ScanIngestion::refreshScanStatus($pdo, (int)$scan['id']);
    $processed++;
}

fwrite(STDOUT, json_encode([
    'success'   => true,
    'processed' => $processed,
    'skipped'   => $skipped,
]) . PHP_EOL);
exit(0);
