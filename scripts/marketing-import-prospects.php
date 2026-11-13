<?php
/**
 * CLI: import the DentaTrak Prospects Google Sheet into the marketing CRM
 * tables (marketing_orgs / marketing_contacts / marketing_prospect_events).
 *
 *   php scripts/marketing-import-prospects.php            # dry-run (default)
 *   php scripts/marketing-import-prospects.php --write    # persist
 *   php scripts/marketing-import-prospects.php --limit=50 # first N rows only
 *   php scripts/marketing-import-prospects.php --json=report.json
 *
 * Dry-run performs every write inside a transaction and rolls it back, so
 * the printed report is exactly what --write would produce. Safe to re-run:
 * contacts/orgs match on stable keys and events dedupe on content hash.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/McpSheetSync.php';
require_once __DIR__ . '/../api/marketing/ProspectSheetImporter.php';

global $pdo, $appConfig;

$write = in_array('--write', $argv, true);
$limit = null;
$reportPath = null;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1];
    if (preg_match('/^--json=(.+)$/', $a, $m)) $reportPath = $m[1];
}

$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo 'environment: ' . ($appConfig['current_environment'] ?? 'unknown') . "\n";
echo "database:    $db\n";
echo 'mode:        ' . ($write ? 'WRITE' : 'DRY-RUN (no changes persisted)') . "\n";

// Refuse to run against a database missing the CRM tables.
$have = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('marketing_orgs','marketing_contacts','marketing_prospect_events','marketing_import_runs','marketing_import_rows')")->fetchColumn();
if ($have !== 5) {
    fwrite(STDERR, "ERROR: CRM tables missing ($have/5). Run migrations/2026_11_13_marketing_crm.php first.\n");
    exit(1);
}

try {
    $sync = new McpSheetSync();
    $snapshot = $sync->sheet();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: could not load prospect sheet: ' . $e->getMessage() . "\n");
    exit(1);
}

$importer = new ProspectSheetImporter($pdo, $snapshot);
$stats = $importer->importSnapshot($snapshot, !$write, $limit);

echo "source_rows:        {$stats['source_rows']}\n";
echo "orgs_created:       {$stats['orgs_created']}\n";
echo "orgs_matched:       {$stats['orgs_matched']}\n";
echo "org_links_set:      {$stats['org_links_set']}\n";
echo "contacts_created:   {$stats['contacts_created']}\n";
echo "contacts_updated:   {$stats['contacts_updated']}\n";
echo "duplicate_rows:     {$stats['duplicate_rows']}\n";
echo "skipped_invalid:    {$stats['skipped_invalid']}\n";
echo "review_rows:        {$stats['review_rows']}\n";
echo "events_written:     {$stats['events_written']}\n";
echo "conflicts:          {$stats['conflict_count']}\n";
echo "dispositions:       " . json_encode($stats['dispositions']) . "\n";

if ($reportPath !== null) {
    file_put_contents($reportPath, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "report:             $reportPath\n";
}
if (!$write) echo "\nDry-run only - re-run with --write to persist.\n";
