<?php
/**
 * CLI: reconcile the Prospects Google Sheet against the marketing CRM
 * tables after an import run.
 *
 *   php scripts/marketing-reconcile-prospects.php           # summary report
 *   php scripts/marketing-reconcile-prospects.php --detail  # + record-level discrepancies (local review only)
 *   php scripts/marketing-reconcile-prospects.php --json=report.json
 *
 * Read-only: issues SELECTs only. Record-level detail is intended for
 * authorized local review and must not be pasted into deployment logs.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/McpSheetSync.php';
require_once __DIR__ . '/../api/marketing/ProspectReconciler.php';

global $pdo, $appConfig;

$detail = in_array('--detail', $argv, true);
$reportPath = null;
foreach ($argv as $a) if (preg_match('/^--json=(.+)$/', $a, $m)) $reportPath = $m[1];

echo 'environment: ' . ($appConfig['current_environment'] ?? 'unknown') . "\n";

try {
    $snapshot = (new McpSheetSync())->sheet();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: could not load prospect sheet: ' . $e->getMessage() . "\n");
    exit(1);
}

$rec = new ProspectReconciler($pdo, $snapshot);
$rep = $rec->report();

if (isset($rep['error'])) { fwrite(STDERR, 'ERROR: ' . $rep['error'] . "\n"); exit(1); }

echo "source_rows:                    {$rep['source_rows']}\n";
echo "latest_run:                     #{$rep['latest_run']['id']} ({$rep['latest_run']['mode']}, {$rep['latest_run']['started_at']})\n";
echo "all_source_rows_accounted:      " . ($rep['all_source_rows_accounted'] ? 'YES' : 'NO (' . count($rep['unaccounted_source_rows']) . ' missing)') . "\n";
echo "dispositions:                   " . json_encode($rep['dispositions']) . "\n";
echo "orgs_total / contacts_total:    {$rep['orgs_total']} / {$rep['contacts_total']}\n";
echo "contacts_needs_review / dnc:    {$rep['contacts_needs_review']} / {$rep['contacts_dnc']}\n";
echo "contacts_without_sheet_row:     {$rep['contacts_without_sheet_row']}\n";
echo "suppression_inconsistencies:    email=" . $rep['suppression_inconsistencies']['email_suppressed_but_not_dnc']
   . " org=" . $rep['suppression_inconsistencies']['org_suppressed_but_not_dnc'] . "\n";
echo "status_distribution:            " . json_encode($rep['status_distribution']) . "\n";
echo "followup_distribution:          " . json_encode($rep['followup_distribution']) . "\n";
echo "dso_links / orphan_parents:     {$rep['dso_links']} / {$rep['orgs_orphan_parent']}\n";
echo "conflicts (latest run):         {$rep['conflict_count']}\n";
echo "fields_not_migrated:            " . ($rep['fields_not_migrated'] ? implode(', ', $rep['fields_not_migrated']) : '(none)') . "\n";
if (!empty($rep['unaccounted_source_rows'])) echo "unaccounted sheet_rows:         " . implode(',', array_slice($rep['unaccounted_source_rows'], 0, 50)) . "\n";
if (($rep['import_rows_beyond_snapshot'] ?? 0) > 0) echo "note: {$rep['import_rows_beyond_snapshot']} import rows reference sheet rows no longer present\n";

if ($detail) {
    $rows = $rec->detailRows();
    echo "\n-- record-level discrepancies (local review only) --\n";
    foreach ($rows as $r) {
        echo "row {$r['sheet_row']}: {$r['disposition']}" . ($r['reason'] ? " ({$r['reason']})" : '')
           . " contact#{$r['contact_id']}" . ($r['needs_review'] ? " REVIEW:{$r['review_reason']}" : '') . "\n";
    }
    echo count($rows) . " rows listed (cap 500)\n";
}

if ($reportPath !== null) {
    file_put_contents($reportPath, json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "report: $reportPath\n";
}
