<?php
/**
 * Backfill external_parent_id (PatNum) on existing 'case' mappings.
 *
 * Cases imported before the patient-launch feature stamped external_parent_id
 * have no patient back-link on their mapping. New inbound events self-heal
 * them, but cases that never sync again would stay invisible to patient
 * launches. This script repairs them in one pass per connection:
 *
 *   pages GET /labcases -> for each row, stamps the case mapping's
 *   external_parent_id with PatNum (never overwrites an existing link).
 *
 * PHI-safe: only LabCaseNum/PatNum integers are read; nothing is logged
 * beyond connection ids and counts. Runs against the existing adapter so
 * throttling (ApiReadAll ~1 req/5s) is expected for large histories.
 *
 * Usage:
 *   php scripts/backfill-od-case-patient-links.php [--connection=N] [--dry-run]
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/integrations/register-adapters.php';
require_once __DIR__ . '/../api/integrations/IntegrationManager.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "No database connection available\n");
    exit(1);
}

$options = getopt('', ['connection:', 'dry-run']);
$onlyConnection = isset($options['connection']) ? (int)$options['connection'] : 0;
$dryRun = isset($options['dry-run']);

$sql = "SELECT * FROM integration_connections WHERE provider = 'open_dental'";
$params = [];
if ($onlyConnection > 0) {
    $sql .= " AND id = :id";
    $params[':id'] = $onlyConnection;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$connections = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($connections)) {
    echo "No matching Open Dental connections.\n";
    exit(0);
}

$totalStamped = 0;
$totalSkipped = 0;

foreach ($connections as $connection) {
    $connectionId = (int)$connection['id'];
    echo "Connection {$connectionId} (practice {$connection['practice_id']}): ";

    try {
        $adapter = IntegrationManager::getAdapter($connection);
    } catch (Throwable $e) {
        echo "adapter unavailable ({$e->getMessage()}) - skipped\n";
        continue;
    }
    if (!method_exists($adapter, 'listLabCases')) {
        echo "adapter cannot list lab cases - skipped\n";
        continue;
    }

    $credentials = IntegrationManager::getConnectionCredentials($pdo, $connectionId);
    $offset = 0;
    $stamped = 0;
    $skipped = 0;

    try {
        do {
            $page = $adapter->listLabCases($credentials, [], $offset);
            foreach ($page as $row) {
                $labCaseNum = (string)($row['LabCaseNum'] ?? '');
                $patNum = (string)($row['PatNum'] ?? '');
                if ($labCaseNum === '' || $patNum === '') {
                    continue;
                }
                if ($dryRun) {
                    $skipped++;
                    continue;
                }
                $upd = $pdo->prepare("
                    UPDATE integration_entity_mappings
                    SET external_parent_id = :pat
                    WHERE connection_id = :conn
                      AND entity_type = 'case'
                      AND external_id = :ext
                      AND external_parent_id IS NULL
                ");
                $upd->execute([
                    ':pat'  => $patNum,
                    ':conn' => $connectionId,
                    ':ext'  => $labCaseNum,
                ]);
                if ($upd->rowCount() > 0) {
                    $stamped++;
                } else {
                    $skipped++;
                }
            }
            $offset += count($page);
            // Page full -> more rows may exist; a short page ends the scan.
        } while (count($page) >= 100);
    } catch (Throwable $e) {
        echo "stopped after {$offset} rows ({$e->getMessage()}); stamped={$stamped}\n";
        $totalStamped += $stamped;
        $totalSkipped += $skipped;
        continue;
    }

    echo ($dryRun ? 'dry-run ' : '') . "stamped={$stamped}, already_linked_or_unmapped={$skipped}\n";
    $totalStamped += $stamped;
    $totalSkipped += $skipped;
}

echo ($dryRun ? 'DRY RUN - ' : '') . "Done. stamped={$totalStamped}, skipped={$totalSkipped}\n";
