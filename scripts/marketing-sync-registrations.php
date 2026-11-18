<?php
/**
 * One-time (and safely repeatable) reconciliation of DentaTrak practice
 * registrations into the Marketing CRM.
 *
 * For every practice row, PracticeRegistrationSync::syncPractice() links
 * an existing CRM org or creates one, marks contacts 'Signed Up', and
 * records events. Idempotent: the practice_id link + 'Signed Up' statuses
 * make reruns no-ops.
 *
 * Usage (Cloud Run job / CLI):
 *   php scripts/marketing-sync-registrations.php [--practice-id=N] [--dry-run]
 *
 * --dry-run reports what WOULD change without writing (matches are still
 * resolved read-only where possible; org/contact writes are skipped).
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';
require_once __DIR__ . '/../api/marketing/PracticeRegistrationSync.php';
global $pdo;

$onlyId = null; $dryRun = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--practice-id=(\d+)$/', $a, $m)) $onlyId = (int)$m[1];
    if ($a === '--dry-run') $dryRun = true;
}

$sync = new PracticeRegistrationSync($pdo, 'cli:registration-reconciliation');

$sql = 'SELECT id FROM practices' . ($onlyId ? ' WHERE id=' . $onlyId : ' ORDER BY id');
$ids = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
echo "practices to examine: " . count($ids) . ($dryRun ? " (dry-run)" : '') . "\n";

$stats = ['examined' => 0, 'linked' => 0, 'created' => 0, 'updated' => 0, 'none' => 0,
    'ambiguous' => 0, 'errors' => 0, 'contacts_updated' => 0, 'contacts_created' => 0];
$problems = [];

foreach ($ids as $pid) {
    $stats['examined']++;
    if ($dryRun) {
        // Read-only preview: report the practice + whether an org is
        // already linked; full matching runs inside syncPractice only.
        $st = $pdo->prepare('SELECT practice_name, legal_name FROM practices WHERE id=?');
        $st->execute([$pid]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        echo "  #$pid " . ($p['legal_name'] ?? $p['practice_name'] ?? '?') . "\n";
        continue;
    }
    $r = $sync->syncPractice($pid);
    if ($r['error'] !== null) {
        $stats['errors']++;
        $problems[] = "#$pid: {$r['error']}";
        continue;
    }
    $stats[$r['action']] = ($stats[$r['action']] ?? 0) + 1;
    if ($r['review']) $stats['ambiguous']++;
    $stats['contacts_updated'] += (int)$r['contacts_updated'];
    $stats['contacts_created'] += (int)$r['contacts_created'];
    if ($r['action'] !== 'none' || $r['review']) {
        echo "  #$pid action={$r['action']} org={$r['org_id']}"
            . ($r['review'] ? ' REVIEW' : '')
            . " contacts+{$r['contacts_created']}/~{$r['contacts_updated']}\n";
    }
}

echo "\n=== reconciliation summary ===\n";
foreach ($stats as $k => $v) echo "  $k: $v\n";
foreach ($problems as $p) echo "  ERROR $p\n";
echo "done\n";
