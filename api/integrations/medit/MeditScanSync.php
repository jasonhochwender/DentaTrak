<?php
/**
 * MeditScanSync
 *
 * Bounded worker that discovers Medit Link cases for every connected
 * medit integration and feeds them through ScanIngestion (dedup ->
 * file rows -> verified download -> storage). Two intake paths:
 *
 *   1. WINDOWED POLLING (creation-time): cases/search filters on
 *      dateCreated only, so the poll finds NEW cases. Cursor persisted
 *      in integration_connections.config_json.scan_poll - same semantics
 *      as DsCoreScanSync (overlap re-list, per-page commit, resumable
 *      page position via the documented page index).
 *
 *   2. WEBHOOK DRAIN (update-time): medit_webhook_events rows recorded by
 *      api/medit-webhook.php are resolved here - each case_uuid is
 *      re-fetched directly (covers UPDATES to cases created before the
 *      polling window, which creation-time polling can never see), then
 *      marked processed. Events without a resolvable case force the
 *      window poll immediately and are acknowledged once it completes.
 *
 * CONCURRENCY: MySQL GET_LOCK(medit_scan_sync_{connectionId}, 0) -
 * non-blocking, held for the whole run.
 *
 * RESPECTS: disconnects (status re-read each page; a disabled or
 * reconnect_required connection aborts immediately), feature gates
 * (MEDIT_CONNECT + SCANNER_INBOX), practice allowlist
 * (MEDIT_ALLOWED_PRACTICES), environment binding, rate limits (429
 * persists rate_limited_until honoring Retry-After), and malformed
 * responses (thrown - never silently "zero scans").
 *
 * Configuration (env overridable):
 *   MEDIT_SCAN_INTERVAL_SEC   min seconds between polls   (default 300)
 *   MEDIT_SCAN_OVERLAP_SEC    trailing window overlap     (default 300)
 *   MEDIT_SCAN_LOOKBACK_SEC   first-run window depth      (default 86400)
 *   MEDIT_SCAN_MAX_PAGES      list pages per run          (default 10)
 *   MEDIT_SCAN_MAX_SCANS      scans ingested per run      (default 500)
 *   MEDIT_WEBHOOK_BATCH       events drained per run      (default 25)
 */

require_once __DIR__ . '/../../appConfig.php';
require_once __DIR__ . '/../../feature-flags.php';
require_once __DIR__ . '/../scanners/ScanIngestion.php';
require_once __DIR__ . '/../MeditConfig.php';
require_once __DIR__ . '/MeditScanConnector.php';
require_once __DIR__ . '/MeditClient.php';
require_once __DIR__ . '/MeditAuthService.php';
require_once __DIR__ . '/../IntegrationManager.php';

class MeditScanSync {

    /** @var callable|null transport seam (forwarded to the connector). */
    private $transport;
    /** @var callable|null client factory seam for tests. */
    private $clientFactory;

    public function __construct(?callable $transport = null, ?callable $clientFactory = null) {
        $this->transport = $transport;
        $this->clientFactory = $clientFactory;
    }

    private static function cfg(string $env, int $default, int $min, int $max): int {
        $raw = getenv($env);
        if ($raw === false || !is_numeric($raw)) {
            $raw = $_ENV[$env] ?? $_SERVER[$env] ?? null;
        }
        return is_numeric($raw) ? max($min, min($max, (int)$raw)) : $default;
    }

    /**
     * Poll every active, sync-enabled medit connection. Returns a
     * PHI-free summary (counts and error categories only).
     */
    public function processDue(PDO $pdo): array {
        if (!isFeatureEnabled('MEDIT_CONNECT') || !isFeatureEnabled('SCANNER_INBOX')) {
            return ['skipped' => true, 'reason' => 'feature_disabled'];
        }

        $interval = self::cfg('MEDIT_SCAN_INTERVAL_SEC', 300, 30, 86400);
        $stmt = $pdo->prepare("
            SELECT * FROM integration_connections
            WHERE provider = :p AND status = 'active' AND sync_enabled = 1
        ");
        $stmt->execute([':p' => MeditAuthService::PROVIDER]);

        $totals = ['connections' => 0, 'polled' => 0, 'scans' => 0, 'pages' => 0,
                   'webhooks' => 0, 'skipped' => 0, 'restricted' => 0, 'locked' => 0,
                   'errors' => 0, 'rate_limited' => 0];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $connection) {
            $totals['connections']++;
            // Practice allowlist (MEDIT_ALLOWED_PRACTICES): only
            // explicitly designated practices may ever call Medit.
            // An empty list means zero connections qualify.
            if (!MeditConfig::isPracticeAllowed((int)$connection['practice_id'])) {
                $totals['restricted']++;
                continue;
            }
            // Environment separation: a connection authorized under a
            // different environment is never polled - its tokens and
            // cursor belong to the other endpoint set entirely.
            if (!MeditConfig::connectionMatchesEnvironment($connection)) {
                $totals['restricted']++;
                continue;
            }
            $state = self::pollState($connection);

            // Respect the rate-limit hint across runs.
            if (($state['rate_limited_until'] ?? null) !== null
                && strtotime($state['rate_limited_until']) > time()) {
                $totals['rate_limited']++;
                continue;
            }
            // A pending webhook event pulls the poll forward - new cases
            // should appear quickly, not after the quiet-period interval.
            $hasPending = $this->hasPendingEvents($pdo, (int)$connection['id']);
            $lastRun = isset($state['last_run_at']) ? strtotime($state['last_run_at']) : 0;
            if (!$hasPending && $lastRun !== false && $lastRun > 0 && (time() - $lastRun) < $interval) {
                $totals['skipped']++;
                continue;
            }

            $result = $this->pollConnection($pdo, $connection, $state);
            $totals['polled'] += !empty($result['polled']) ? 1 : 0;
            $totals['scans'] += $result['scans'] ?? 0;
            $totals['pages'] += $result['pages'] ?? 0;
            $totals['webhooks'] += $result['webhooks'] ?? 0;
            $totals['locked'] += !empty($result['locked']) ? 1 : 0;
            $totals['errors'] += !empty($result['error']) ? 1 : 0;
            $totals['rate_limited'] += !empty($result['rate_limited']) ? 1 : 0;
        }
        return $totals;
    }

    /** True when the connection has unprocessed webhook events. */
    private function hasPendingEvents(PDO $pdo, int $connectionId): bool {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM medit_webhook_events WHERE connection_id = :id AND status = 'received' LIMIT 1");
            $stmt->execute([':id' => $connectionId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false; // table may not exist yet - polling still works
        }
    }

    // ----------------------------------------------------------------------
    // Per-connection run
    // ----------------------------------------------------------------------

    private function pollConnection(PDO $pdo, array $connection, array $state): array {
        $connectionId = (int)$connection['id'];
        $practiceId = (int)$connection['practice_id'];

        // Non-blocking cross-instance lock: a losing instance simply
        // skips - this connection is already being synced elsewhere.
        $lockName = 'medit_scan_sync_' . $connectionId;
        $lockStmt = $pdo->prepare("SELECT GET_LOCK(:name, 0)");
        $lockStmt->execute([':name' => $lockName]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            return ['locked' => true];
        }

        try {
            // Re-read under the lock: the connection may have been
            // disconnected or marked reconnect_required between listing
            // and lock acquisition.
            $fresh = IntegrationManager::findConnection($pdo, $connectionId);
            if (!$fresh || ($fresh['status'] ?? '') !== 'active' || empty($fresh['sync_enabled'])) {
                return ['polled' => false, 'aborted' => 'inactive'];
            }

            $overlap = self::cfg('MEDIT_SCAN_OVERLAP_SEC', 300, 60, 86400);
            $lookback = self::cfg('MEDIT_SCAN_LOOKBACK_SEC', 86400, 300, 30 * 86400);
            $maxPages = self::cfg('MEDIT_SCAN_MAX_PAGES', 10, 1, 50);
            $maxScans = self::cfg('MEDIT_SCAN_MAX_SCANS', 500, 1, 10000);
            $eventBatch = self::cfg('MEDIT_WEBHOOK_BATCH', 25, 1, 100);

            // Bounded reconciliation: cases/search can only filter on
            // dateCreated, so updates to older cases are invisible to
            // incremental polling. Once per resweep interval the window
            // is widened to the full lookback depth - every case created
            // within it is re-listed and re-ingested (dedup + revision
            // signatures make this cheap and correct), catching missed
            // webhooks AND updates. Cases older than the lookback depth
            // remain webhook-only - a documented limitation.
            $resweepSec = self::cfg('MEDIT_SCAN_RESWEEP_SEC', 86400, 3600, 7 * 86400);
            $lastResweep = isset($state['last_resweep_at']) ? strtotime($state['last_resweep_at']) : 0;
            $doResweep = ($lastResweep === false || (time() - $lastResweep) >= $resweepSec);

            $cursor = isset($state['cursor']) && $state['cursor'] !== '' ? strtotime($state['cursor']) : null;
            $windowStart = ($doResweep || $cursor === null)
                ? gmdate('Y-m-d\TH:i:s\Z', time() - $lookback)
                : gmdate('Y-m-d\TH:i:s\Z', $cursor - $overlap);
            // When resuming a stored page position, the stored window
            // bounds are reused verbatim so the page index stays valid.
            $pageToken = isset($state['page_token']) && $state['page_token'] !== '' ? (string)$state['page_token'] : null;
            if ($pageToken !== null && isset($state['window_start'], $state['window_end'])) {
                $windowStart = (string)$state['window_start'];
                $windowEnd = (string)$state['window_end'];
            } else {
                $windowEnd = gmdate('Y-m-d\TH:i:s\Z');
                $pageToken = null;
            }

            $connector = new MeditScanConnector($pdo, null, $this->transport, $this->clientFactory);

            $scansIngested = 0;
            $pagesFetched = 0;
            $webhooksProcessed = 0;
            $halted = false;
            $haltReason = null;

            $ingestOne = function (array $payload) use ($pdo, $connector, $practiceId, $connectionId) {
                ScanIngestion::ingestScan($pdo, [
                    'practice_id'    => $practiceId,
                    'connection_id'  => $connectionId,
                    'source_account' => null,
                    'is_synthetic'   => false,
                ], $connector, $payload);
            };

            $ingestPage = function (array $pagePayloads, ?string $nextToken) use (
                $pdo, $connectionId, &$scansIngested, &$pagesFetched,
                $maxScans, $windowStart, $windowEnd, &$halted, &$haltReason, $ingestOne) {

                // Respect disconnects between pages.
                $statusRow = $pdo->prepare("SELECT status, sync_enabled FROM integration_connections WHERE id = :id");
                $statusRow->execute([':id' => $connectionId]);
                $status = $statusRow->fetch(PDO::FETCH_ASSOC);
                if (!$status || $status['status'] !== 'active' || empty($status['sync_enabled'])) {
                    $halted = true;
                    $haltReason = 'disconnected';
                    return false;
                }

                $pagesFetched++;
                foreach ($pagePayloads as $payload) {
                    if ($scansIngested >= $maxScans) {
                        $halted = true;
                        $haltReason = 'scan_cap';
                        return false;
                    }
                    try {
                        $ingestOne($payload);
                        $scansIngested++;
                    } catch (Throwable $e) {
                        // A single bad payload must not abort the page or
                        // be marked consumed - stop the page so the cursor
                        // stays put and the next run retries it.
                        $halted = true;
                        $haltReason = 'ingest_error';
                        error_log('[medit-scan] ingest failed for connection ' . $connectionId . ': ' . $e->getMessage());
                        return false;
                    }
                }

                // Page fully processed: commit progress now so an
                // interrupted run resumes after this page, never before.
                self::savePollState($pdo, $connectionId, [
                    'cursor'       => null,
                    'page_token'   => $nextToken,
                    'window_start' => $windowStart,
                    'window_end'   => $windowEnd,
                    'last_run_at'  => gmdate('Y-m-d H:i:s'),
                ]);
                return true;
            };

            try {
                $connector->fetchScans([
                    'practice_id'    => $practiceId,
                    'connection_id'  => $connectionId,
                    'window_start'   => $windowStart,
                    'window_end'     => $windowEnd,
                    'page_token'     => $pageToken,
                    'page_limit'     => $maxPages,
                    'on_page'        => $ingestPage,
                ]);
            } catch (Throwable $e) {
                return $this->recordFailure($pdo, $connectionId, $e, $state, $scansIngested, $pagesFetched);
            }

            if ($halted) {
                return ['polled' => true, 'scans' => $scansIngested, 'pages' => $pagesFetched,
                        'halted' => $haltReason, 'error' => $haltReason === 'ingest_error'];
            }

            // If the connector stopped because of a disconnect mid-run the
            // window must NOT close - the re-list is retried next poll.
            $post = IntegrationManager::findConnection($pdo, $connectionId);
            if (!$post || ($post['status'] ?? '') !== 'active' || empty($post['sync_enabled'])) {
                return ['polled' => true, 'scans' => $scansIngested, 'pages' => $pagesFetched, 'aborted' => 'disconnected'];
            }

            // Window complete: advance the cursor to its end.
            self::savePollState($pdo, $connectionId, [
                'cursor'          => $windowEnd,
                'page_token'      => null,
                'window_start'    => null,
                'window_end'      => null,
                'last_run_at'     => gmdate('Y-m-d H:i:s'),
                'last_success_at' => gmdate('Y-m-d H:i:s'),
                'last_resweep_at' => $doResweep ? gmdate('Y-m-d H:i:s') : ($state['last_resweep_at'] ?? gmdate('Y-m-d H:i:s')),
                'last_error'      => null,
                'rate_limited_until' => null,
            ]);
            $pdo->prepare("UPDATE integration_connections SET last_sync_at = NOW(), last_success_at = NOW(), last_error = NULL WHERE id = :id")
                ->execute([':id' => $connectionId]);

            // Webhook drain: re-fetch each notified case directly. This is
            // the reconciliation path for UPDATES to cases whose
            // dateCreated predates the polling window - the creation
            // filter can never see them again. Durable and idempotent:
            // ingest dedups on (external_scan_id, file revision).
            $webhooksProcessed = $this->drainWebhookEvents(
                $pdo, $connectionId, $practiceId, $connector, $ingestOne, $eventBatch);

            return ['polled' => true, 'scans' => $scansIngested, 'pages' => $pagesFetched,
                    'webhooks' => $webhooksProcessed];
        } finally {
            $release = $pdo->prepare("SELECT RELEASE_LOCK(:name)");
            $release->execute([':name' => $lockName]);
        }
    }

    /**
     * Process pending medit_webhook_events for this connection. Each
     * event with a case_uuid triggers a direct case fetch + re-ingest;
     * events without one are acknowledged (the window poll above already
     // covers discovery). Bounded by $batch; failures leave the event
     * received for the next run (attempts capped).
     */
    private function drainWebhookEvents(PDO $pdo, int $connectionId, int $practiceId, MeditScanConnector $connector, callable $ingestOne, int $batch): int {
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM medit_webhook_events
                WHERE connection_id = :id AND status = 'received' AND attempts < 5
                ORDER BY id ASC LIMIT " . (int)$batch);
            $stmt->execute([':id' => $connectionId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0; // table may not exist yet
        }

        $processed = 0;
        foreach ($events as $event) {
            $eventId = (int)$event['id'];
            // Atomic claim: a racing instance loses the conditional
            // UPDATE and moves on - events are processed at most once.
            $claim = $pdo->prepare("
                UPDATE medit_webhook_events SET status = 'processing', attempts = attempts + 1
                WHERE id = :id AND status = 'received'
            ");
            $claim->execute([':id' => $eventId]);
            if ($claim->rowCount() === 0) {
                continue;
            }

            try {
                $caseUuid = isset($event['case_uuid']) ? trim((string)$event['case_uuid']) : '';
                if ($caseUuid !== '') {
                    foreach ($connector->fetchScans([
                        'practice_id'   => $practiceId,
                        'connection_id' => $connectionId,
                        'case_uuids'    => [$caseUuid],
                    ]) as $payload) {
                        $ingestOne($payload);
                    }
                }
                $pdo->prepare("UPDATE medit_webhook_events SET status = 'processed', processed_at = NOW() WHERE id = :id")
                    ->execute([':id' => $eventId]);
                $processed++;
            } catch (Throwable $e) {
                // Not-found cases still count as resolved - the case was
                // deleted or belongs to another group; retrying cannot
                // help. Everything else returns to 'received'.
                $category = 'failed';
                if ($e instanceof MeditApiException && in_array($e->category, ['not_found', 'gone', 'forbidden'], true)) {
                    $category = 'permanent';
                }
                if ($category === 'permanent') {
                    $pdo->prepare("UPDATE medit_webhook_events SET status = 'processed', processed_at = NOW() WHERE id = :id")
                        ->execute([':id' => $eventId]);
                    $processed++;
                } else {
                    $pdo->prepare("UPDATE medit_webhook_events SET status = 'received' WHERE id = :id")
                        ->execute([':id' => $eventId]);
                    error_log('[medit-scan] webhook event ' . $eventId . ' failed: ' . $e->getMessage());
                }
            }
        }
        return $processed;
    }

    /**
     * Classify a run failure without losing committed page progress.
     * Rate limits persist their Retry-After hint; forbidden is recorded
     * as a permanent-looking error.
     */
    private function recordFailure(PDO $pdo, int $connectionId, Throwable $e, array $state, int $scans, int $pages): array {
        $category = 'sync_failed';
        $rateLimited = false;

        if ($e instanceof MeditApiException) {
            $category = $e->category;
            if ($e->category === 'rate_limited') {
                $rateLimited = true;
                $until = gmdate('Y-m-d H:i:s', time() + ($e->retryAfter ?? 60));
                self::savePollState($pdo, $connectionId, [
                    'rate_limited_until' => $until,
                    'last_run_at'        => gmdate('Y-m-d H:i:s'),
                    'last_error'         => 'rate_limited',
                ]);
                $pdo->prepare("UPDATE integration_connections SET last_sync_at = NOW(), last_error = 'Medit rate limit reached; will retry automatically.' WHERE id = :id")
                    ->execute([':id' => $connectionId]);
                return ['polled' => true, 'rate_limited' => true, 'scans' => $scans, 'pages' => $pages];
            }
        } elseif ($e instanceof MeditAuthException) {
            $category = 'auth_' . $e->category;
        } elseif ($e instanceof ScannerDownloadException) {
            $category = $e->getErrorCode();
        }

        $patch = [
            'last_run_at' => gmdate('Y-m-d H:i:s'),
            'last_error'  => substr($category, 0, 64),
        ];
        if ($category === 'http_400') {
            // Most plausible cause: stored page index beyond the result
            // set. Drop it so the window restarts cleanly instead of
            // failing identically every run.
            $patch['page_token'] = null;
            $patch['window_start'] = null;
            $patch['window_end'] = null;
        }
        self::savePollState($pdo, $connectionId, $patch);
        $pdo->prepare("UPDATE integration_connections SET last_sync_at = NOW(), last_error = :err WHERE id = :id")
            ->execute([':err' => 'Medit scan sync failed (' . $category . ').', ':id' => $connectionId]);
        error_log('[medit-scan] sync failed for connection ' . $connectionId . ': ' . $category);

        return ['polled' => true, 'error' => true, 'category' => $category,
                'scans' => $scans, 'pages' => $pages];
    }

    // ----------------------------------------------------------------------
    // Cursor state (config_json.scan_poll)
    // ----------------------------------------------------------------------

    private static function pollState(array $connection): array {
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        if (!is_array($config) || !isset($config['scan_poll']) || !is_array($config['scan_poll'])) {
            return [];
        }
        return $config['scan_poll'];
    }

    /** Merge keys into config_json.scan_poll (never touches other keys). */
    private static function savePollState(PDO $pdo, int $connectionId, array $patch): void {
        $stmt = $pdo->prepare("SELECT config_json FROM integration_connections WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $connectionId]);
        $config = json_decode((string)$stmt->fetchColumn(), true);
        if (!is_array($config)) {
            $config = [];
        }
        $poll = isset($config['scan_poll']) && is_array($config['scan_poll']) ? $config['scan_poll'] : [];
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($poll[$k]);
            } else {
                $poll[$k] = $v;
            }
        }
        $config['scan_poll'] = $poll;
        $pdo->prepare("UPDATE integration_connections SET config_json = :cfg WHERE id = :id")
            ->execute([':cfg' => json_encode($config), ':id' => $connectionId]);
    }
}
