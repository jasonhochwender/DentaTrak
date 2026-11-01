<?php
/**
 * Durable Sheets-sync worker for marketing suppressions.
 * Drains marketing_sync_queue with bounded exponential backoff; invoked by
 * the per-minute integration-event-worker pass or CLI:
 *   php api/marketing/sync-worker.php [--limit=10]
 */
require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/ProspectSheetSync.php';

$limit = 10;
foreach ($argv ?? [] as $a) if (preg_match('/--limit=(\d+)/', $a, $m)) $limit = (int) $m[1];
$pdo = $appConfig['db']();
$sync = new ProspectSheetSync();

$due = $pdo->query(
    "SELECT q.id, s.email_normalized FROM marketing_sync_queue q
     JOIN marketing_suppressions s ON s.id = q.suppression_id
     WHERE q.status='queued' AND q.next_attempt_at <= UTC_TIMESTAMP()
     ORDER BY q.id LIMIT " . (int) $limit
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($due as $job) {
    $pdo->prepare("UPDATE marketing_sync_queue SET status='running', attempts=attempts+1, updated_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([$job['id']]);
    try {
        $r = $sync->sync($job['email_normalized']);
        $pdo->prepare("UPDATE marketing_sync_queue SET status='done', updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$job['id']]);
        $pdo->prepare("UPDATE marketing_suppressions SET sync_status=?, synced_at=UTC_TIMESTAMP(), sync_attempts=sync_attempts+1 WHERE id=(SELECT suppression_id FROM marketing_sync_queue WHERE id=?)")
            ->execute([$r['matched_rows'] > 0 ? 'synced' : 'no_match', $job['id']]);
        echo "job {$job['id']}: {$job['email_normalized']} rows={$r['matched_rows']}\n";
    } catch (Throwable $e) {
        $fail = $pdo->prepare("SELECT attempts FROM marketing_sync_queue WHERE id=?");
        $fail->execute([$job['id']]);
        $attempts = (int) $fail->fetchColumn();
        $backoff = min(3600, 60 * (2 ** min($attempts, 6)));
        $status = $attempts >= 8 ? 'failed' : 'queued';
        $pdo->prepare("UPDATE marketing_sync_queue SET status=?, last_error=?, next_attempt_at=UTC_TIMESTAMP() + INTERVAL ? SECOND, updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$status, substr($e->getMessage(), 0, 480), $backoff, $job['id']]);
        echo "job {$job['id']} FAILED ({$attempts}): " . substr($e->getMessage(), 0, 120) . "\n";
    }
}
echo "worker pass complete\n";
