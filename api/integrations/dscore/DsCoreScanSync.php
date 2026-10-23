<?php
/**
 * DsCoreScanSync
 *
 * Bounded polling worker that discovers DS Core digital impressions for
 * every connected ds_core integration and feeds them through
 * ScanIngestion (dedup -> file rows -> verified download -> storage).
 * Polling is required: the PMS spec registers webhooks for DICOM and
 * order/referral events but has NO digital-impression event - nothing is
 * invented here.
 *
 * WINDOWED POLLING (overlapping, cursor persisted in
 * integration_connections.config_json.scan_poll - no schema change):
 *   cursor        RFC3339 high-water mark = last fully-completed window end
 *   page_token    resume position inside the CURRENT window
 *   window_start  start of the in-flight window (reused when resuming a
 *                 page token, so the filter matches the stored token)
 *   window_end    end of the in-flight window
 *   rate_limited_until / last_error / last_run_at bookkeeping fields
 *
 *   window = [cursor - OVERLAP, now] - the overlap re-lists the window's
 *   trailing edge every run so a scan created during the previous poll is
 *   never missed by clock skew. Re-listing is cheap: dedup_key makes
 *   re-ingestion a no-op.
 *
 * PROGRESS SEMANTICS (persist only after successful processing):
 *   Each page's scans are ingested first; only then is page_token
 *   committed. A failure inside a page leaves the cursor where it was -
 *   the next poll re-lists that page and dedup absorbs the re-delivery,
 *   so partial failures can never silently skip scans. cursor only
 *   advances to window_end when the final page completes.
 *
 * CONCURRENCY: MySQL GET_LOCK(dscore_scan_sync_{connectionId}, 0) -
 * non-blocking, held for the whole run, so two Cloud Run instances can
 * never overlap a sync for the same connection.
 *
 * RESPECTS: disconnects (status re-read each page; a disabled or
 * reconnect_required connection aborts immediately), feature gates
 * (DS_CORE_CONNECT + SCANNER_INBOX), authorization failures (403 marks
 * last_error and skips - refreshing cannot help per the auth manual),
 * and rate limits (429 persists rate_limited_until honoring Retry-After).
 *
 * Configuration (env overridable):
 *   DS_CORE_SCAN_INTERVAL_SEC   min seconds between polls   (default 300)
 *   DS_CORE_SCAN_OVERLAP_SEC    trailing window overlap     (default 300)
 *   DS_CORE_SCAN_LOOKBACK_SEC   first-run window depth      (default 86400)
 *   DS_CORE_SCAN_MAX_PAGES      list pages per run          (default 10)
 *   DS_CORE_SCAN_MAX_SCANS      scans ingested per run      (default 500)
 */

require_once __DIR__ . '/../../appConfig.php';
require_once __DIR__ . '/../../feature-flags.php';
require_once __DIR__ . '/../scanners/ScanIngestion.php';
require_once __DIR__ . '/DsCoreScanConnector.php';
require_once __DIR__ . '/DsCoreClient.php';
require_once __DIR__ . '/DsCoreAuthService.php';
require_once __DIR__ . '/../IntegrationManager.php';

class DsCoreScanSync {

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
     * Poll every active, sync-enabled ds_core connection. Returns a
     * PHI-free summary (counts and error categories only).
     */
    public function processDue(PDO $pdo): array {
        if (!isFeatureEnabled('DS_CORE_CONNECT') || !isFeatureEnabled('SCANNER_INBOX')) {
            return ['skipped' => true, 'reason' => 'feature_disabled'];
        }

        $interval = self::cfg('DS_CORE_SCAN_INTERVAL_SEC', 300, 30, 86400);
        $stmt = $pdo->prepare("
            SELECT * FROM integration_connections
            WHERE provider = :p AND status = 'active' AND sync_enabled = 1
        ");
        $stmt->execute([':p' => DsCoreAuthService::PROVIDER]);

        $totals = ['connections' => 0, 'polled' => 0, 'scans' => 0, 'pages' => 0,
                   'skipped' => 0, 'locked' => 0, 'errors' => 0, 'rate_limited' => 0];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $connection) {
            $totals['connections']++;
            $state = self::pollState($connection);

            // Respect the rate-limit hint across runs.
            if (($state['rate_limited_until'] ?? null) !== null
                && strtotime($state['rate_limited_until']) > time()) {
                $totals['rate_limited']++;
                continue;
            }
            $lastRun = isset($state['last_run_at']) ? strtotime($state['last_run_at']) : 0;
            if ($lastRun !== false && $lastRun > 0 && (time() - $lastRun) < $interval) {
                $totals['skipped']++;
                continue;
            }

            $result = $this->pollConnection($pdo, $connection, $state);
            $totals['polled'] += !empty($result['polled']) ? 1 : 0;
            $totals['scans'] += $result['scans'] ?? 0;
            $totals['pages'] += $result['pages'] ?? 0;
            $totals['locked'] += !empty($result['locked']) ? 1 : 0;
            $totals['errors'] += !empty($result['error']) ? 1 : 0;
            $totals['rate_limited'] += !empty($result['rate_limited']) ? 1 : 0;
        }
        return $totals;
    }

    // ----------------------------------------------------------------------
    // Per-connection run
    // ----------------------------------------------------------------------

    private function pollConnection(PDO $pdo, array $connection, array $state): array {
        $connectionId = (int)$connection['id'];
        $practiceId = (int)$connection['practice_id'];

        // Non-blocking cross-instance lock: a losing instance simply
        // skips - this connection is already being synced elsewhere.
        $lockName = 'dscore_scan_sync_' . $connectionId;
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

            $overlap = self::cfg('DS_CORE_SCAN_OVERLAP_SEC', 300, 60, 86400);
            $lookback = self::cfg('DS_CORE_SCAN_LOOKBACK_SEC', 86400, 300, 30 * 86400);
            $maxPages = self::cfg('DS_CORE_SCAN_MAX_PAGES', 10, 1, 50);
            $maxScans = self::cfg('DS_CORE_SCAN_MAX_SCANS', 500, 1, 10000);

            $cursor = isset($state['cursor']) && $state['cursor'] !== '' ? strtotime($state['cursor']) : null;
            $windowStart = $cursor !== null
                ? gmdate('Y-m-d\TH:i:s\Z', $cursor - $overlap)
                : gmdate('Y-m-d\TH:i:s\Z', time() - $lookback);
            // When resuming a stored page token, the stored window bounds
            // are reused verbatim so the server-side cursor stays valid.
            $pageToken = isset($state['page_token']) && $state['page_token'] !== '' ? (string)$state['page_token'] : null;
            if ($pageToken !== null && isset($state['window_start'], $state['window_end'])) {
                $windowStart = (string)$state['window_start'];
                $windowEnd = (string)$state['window_end'];
            } else {
                $windowEnd = gmdate('Y-m-d\TH:i:s\Z');
                // A page token without its stored window is unusable -
                // restart the window fresh rather than wedge on it.
                $pageToken = null;
            }

            $connector = new DsCoreScanConnector($pdo, null, $this->transport, $this->clientFactory);

            $scansIngested = 0;
            $pagesFetched = 0;
            $halted = false;
            $haltReason = null;

            $ingestPage = function (array $pagePayloads, ?string $nextToken) use (
                $pdo, $connector, $connectionId, $practiceId, &$scansIngested, &$pagesFetched,
                $maxScans, $windowStart, $windowEnd, &$halted, &$haltReason) {

                // Respect disconnects between pages: a connection disabled
                // while a run is in flight gets no further processing and
                // keeps its cursor where the last commit left it.
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
                        ScanIngestion::ingestScan($pdo, [
                            'practice_id'    => $practiceId,
                            'connection_id'  => $connectionId,
                            'source_account' => null,
                            'is_synthetic'   => false,
                        ], $connector, $payload);
                        $scansIngested++;
                    } catch (Throwable $e) {
                        // A single bad payload must not abort the page or
                        // be marked consumed - stop the page so the cursor
                        // stays put and the next run retries it.
                        $halted = true;
                        $haltReason = 'ingest_error';
                        error_log('[dscore-scan] ingest failed for connection ' . $connectionId . ': ' . $e->getMessage());
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
                // Scan cap / ingest error: progress committed per page is
                // kept; the window stays open (cursor not advanced) so the
                // remainder is re-polled next run.
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
                'last_error'      => null,
                'rate_limited_until' => null,
            ]);
            $pdo->prepare("UPDATE integration_connections SET last_sync_at = NOW(), last_success_at = NOW(), last_error = NULL WHERE id = :id")
                ->execute([':id' => $connectionId]);

            return ['polled' => true, 'scans' => $scansIngested, 'pages' => $pagesFetched];
        } finally {
            $release = $pdo->prepare("SELECT RELEASE_LOCK(:name)");
            $release->execute([':name' => $lockName]);
        }
    }

    /**
     * Classify a run failure without losing committed page progress.
     * Rate limits persist their Retry-After hint; 403 is recorded as a
     * permanent-looking error (auth manual: refreshing cannot help).
     */
    private function recordFailure(PDO $pdo, int $connectionId, Throwable $e, array $state, int $scans, int $pages): array {
        $category = 'sync_failed';
        $rateLimited = false;

        if ($e instanceof DsCoreApiException) {
            $category = $e->category;
            if ($e->category === 'rate_limited') {
                $rateLimited = true;
                $until = gmdate('Y-m-d H:i:s', time() + ($e->retryAfter ?? 60));
                self::savePollState($pdo, $connectionId, [
                    'rate_limited_until' => $until,
                    'last_run_at'        => gmdate('Y-m-d H:i:s'),
                    'last_error'         => 'rate_limited',
                ]);
                $pdo->prepare("UPDATE integration_connections SET last_sync_at = NOW(), last_error = 'DS Core rate limit reached; will retry automatically.' WHERE id = :id")
                    ->execute([':id' => $connectionId]);
                return ['polled' => true, 'rate_limited' => true, 'scans' => $scans, 'pages' => $pages];
            }
        } elseif ($e instanceof DsCoreAuthException) {
            $category = 'auth_' . $e->category;
        } elseif ($e instanceof ScannerDownloadException) {
            $category = $e->getErrorCode();
        }

        $patch = [
            'last_run_at' => gmdate('Y-m-d H:i:s'),
            'last_error'  => substr($category, 0, 64),
        ];
        if ($category === 'http_400') {
            // Most plausible cause with a stored token: the page token
            // expired server-side. Drop it so the window restarts cleanly
            // instead of failing identically every run.
            $patch['page_token'] = null;
            $patch['window_start'] = null;
            $patch['window_end'] = null;
        }
        self::savePollState($pdo, $connectionId, $patch);
        $pdo->prepare("UPDATE integration_connections SET last_sync_at = NOW(), last_error = :err WHERE id = :id")
            ->execute([':err' => 'DS Core scan sync failed (' . $category . ').', ':id' => $connectionId]);
        error_log('[dscore-scan] sync failed for connection ' . $connectionId . ': ' . $category);

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
