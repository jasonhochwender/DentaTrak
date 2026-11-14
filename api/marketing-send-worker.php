<?php
/**
 * Marketing campaign send worker.
 *
 * Drains admin-authorized campaigns (send_requested=1, send_paused=0)
 * in paced batches using the SAME CampaignSendRunner as the admin
 * endpoint - identical per-recipient gates, ledger idempotency,
 * suppression rechecks, and thread enforcement. Invoked by Cloud
 * Scheduler (~every minute) with the shared X-Queue-Worker-Token.
 *
 * Safety properties:
 * - Only campaigns an admin explicitly sent (send_requested) are touched.
 * - send_paused stops the worker from starting another batch.
 * - 'failed' recipients are NOT auto-retried (manual retry only);
 *   'uncertain' goes through ledger reconciliation, never blind resend.
 * - Content hash is re-verified before every campaign pass.
 * - A batch that makes zero progress (all blocked) stops the campaign.
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/queue-worker-auth.php';
require_once __DIR__ . '/marketing/CampaignSendSupport.php';
require_once __DIR__ . '/marketing/CampaignSendRunner.php';
require_once __DIR__ . '/marketing/CampaignContentHash.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
requireQueueWorkerToken();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database not available']);
    exit;
}
if (!isFeatureEnabled('MARKETING_OUTREACH')) {
    echo json_encode(['success' => true, 'skipped' => 'marketing_outreach_disabled']);
    exit;
}

$batchSize = mo_send_batch_size();
$paceMs = max(0, min(10000, (int)(getenv('MARKETING_SEND_PACE_MS') ?: 1000)));
$budgetSec = max(30, min(280, (int)(getenv('MARKETING_SEND_WORKER_BUDGET') ?: 240)));
$deadline = time() + $budgetSec;
$baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : '';
$actor = 'send-worker';

$out = ['campaigns' => [], 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'uncertain' => 0, 'blocked' => 0];

try {
    // schema guard: columns arrive via migrations/2026_11_22_*.php
    $hasCols = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_campaigns'
        AND COLUMN_NAME IN ('send_requested','send_paused')")->fetchColumn();
    if ((int)$hasCols < 2) {
        echo json_encode(['success' => true, 'skipped' => 'schema_not_migrated']);
        exit;
    }

    $sel = $pdo->query("SELECT * FROM marketing_campaigns
        WHERE send_requested=1 AND send_paused=0
          AND status IN ('approved','partial','sending')
        ORDER BY updated_at ASC LIMIT 3");
    $campaigns = $sel->fetchAll(PDO::FETCH_ASSOC);

    foreach ($campaigns as $camp) {
        $cid = (string)$camp['id'];
        if (time() >= $deadline) break;

        // Content must still match what was approved.
        if (!hash_equals((string)$camp['content_hash'], CampaignContentHash::compute($pdo, $cid))) {
            mo_audit($pdo, $actor, 'campaign_send_hash_mismatch', $cid, null);
            $pdo->prepare('UPDATE marketing_campaigns SET send_requested=0 WHERE id=?')->execute([$cid]);
            $out['campaigns'][] = ['id' => $cid, 'stopped' => 'content_hash_mismatch'];
            continue;
        }

        $mailer = new MarketingMailer($pdo, $baseUrl);
        if (!$mailer->policy()->enabled()) {
            // Global kill switch: leave campaigns queued; do not burn
            // attempts. Next tick retries once sending is re-enabled.
            $out['campaigns'][] = ['id' => $cid, 'skipped' => 'sending_disabled'];
            continue;
        }
        $runner = new CampaignSendRunner($pdo, $baseUrl, $mailer);
        $cTotals = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'uncertain' => 0, 'blocked' => 0];
        $progress = false;

        while (time() < $deadline) {
            // Re-read pause flag before EVERY batch: a paused campaign
            // must not start another batch until resumed.
            $pf = $pdo->prepare('SELECT send_paused FROM marketing_campaigns WHERE id=?');
            $pf->execute([$cid]);
            if ((int)$pf->fetchColumn() === 1) break;

            $camp = $pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?');
            $camp->execute([$cid]);
            $camp = $camp->fetch(PDO::FETCH_ASSOC);
            if (!$camp || !in_array($camp['status'], ['approved','partial','sending'], true)) break;

            $recips = $runner->nextBatch($cid, $batchSize, null, false);
            if (!$recips) break;
            $r = $runner->runBatch($camp, $recips, $paceMs * 1000);
            if (isset($r['error'])) {
                mo_audit($pdo, $actor, 'campaign_send_error', $cid, ['error' => $r['error']]);
                break;
            }
            foreach (['sent','skipped','failed','uncertain','blocked'] as $k) {
                $cTotals[$k] += $r[$k]; $out[$k] += $r[$k];
            }
            if ($r['sent'] + $r['skipped'] + $r['failed'] + $r['uncertain'] > 0) $progress = true;
            mo_rollup($pdo, $cid);
            // Zero-progress batch (everything blocked by policy/
            // allowlist) - stop instead of spinning every minute.
            if ($r['blocked'] === count($recips)) break;
        }

        // Drain check: nothing auto-sendable left -> release the request.
        $remaining = mo_sendable_remaining($pdo, $cid);
        // Every remaining recipient is delivery-blocked (allowlist/policy):
        // further ticks cannot make progress. Release the request - the
        // admin re-authorizes explicitly after fixing delivery config.
        $noProgress = ($cTotals['sent'] + $cTotals['skipped'] + $cTotals['failed'] + $cTotals['uncertain']) === 0
            && $cTotals['blocked'] > 0;
        if ($remaining === 0 || $noProgress) {
            $pdo->prepare('UPDATE marketing_campaigns SET send_requested=0 WHERE id=?')->execute([$cid]);
            if ($noProgress) {
                mo_audit($pdo, $actor, 'campaign_send_blocked_stop', $cid,
                    ['blocked' => $cTotals['blocked'], 'remaining' => $remaining]);
            }
        }
        mo_audit($pdo, $actor, 'campaign_send_worker', $cid, $cTotals + ['remaining' => $remaining]);
        $out['campaigns'][] = ['id' => $cid] + $cTotals + ['remaining' => $remaining, 'progress' => $progress];
    }

    http_response_code(200);
    echo json_encode(['success' => true] + $out);
} catch (Throwable $e) {
    error_log('[send-worker] fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'worker failed']);
}
