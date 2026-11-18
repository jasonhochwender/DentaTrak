<?php
/**
 * Campaign send via Cloud Run job - CLI equivalent of the admin `send`
 * action in api/marketing-admin.php, for controlled production sends that
 * cannot go through an interactive admin session.
 *
 * Modes:
 *   --inspect <campaign_id>              Read-only campaign/recipient state.
 *   --send <campaign_id> [id,id,...]     Send one bounded batch. Recipient
 *                                        ids optional; omit to send the
 *                                        normal sendable set. All send
 *                                        gates apply unchanged: MARKETING_
 *                                        SENDING, allowlist/campaign auth,
 *                                        content hash, suppression,
 *                                        eligibility, ledger idempotency.
 *
 * Run inside Cloud Run (job):
 *   php scripts/marketing-campaign-send.php --inspect <id>
 *   php scripts/marketing-campaign-send.php --send <id> 123,456
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';
require_once __DIR__ . '/../api/marketing/ProspectCrm.php';
require_once __DIR__ . '/../api/marketing/ProspectCrmAdmin.php';
require_once __DIR__ . '/../api/marketing/MarketingMailer.php';
require_once __DIR__ . '/../api/marketing/MarketingSendingPolicy.php';
require_once __DIR__ . '/../api/marketing/CampaignContentHash.php';
require_once __DIR__ . '/../api/marketing/CampaignSendSupport.php';
require_once __DIR__ . '/../api/marketing/CampaignSendRunner.php';
global $pdo, $appConfig;

$ACTOR = 'cli:campaign-send';
$mode = $argv[1] ?? '';
$cid = $argv[2] ?? '';
if ($cid === '' || !in_array($mode, ['--inspect', '--send'], true)) {
    echo "usage: php scripts/marketing-campaign-send.php --inspect|--send <campaign_id> [recipient_ids_csv]\n";
    exit(1);
}

$camp = null;
$st = $pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?');
$st->execute([$cid]);
$camp = $st->fetch(PDO::FETCH_ASSOC);
if (!$camp) { echo "campaign not found\n"; exit(1); }

if ($mode === '--inspect') {
    echo "campaign: {$camp['name']} ({$camp['id']})\n";
    echo "  kind={$camp['kind']} status={$camp['status']} recipients={$camp['recipient_count']}\n";
    echo "  approved_by=" . ($camp['approved_by'] ?? 'null') . " approved_at=" . ($camp['approved_at'] ?? 'null') . "\n";
    echo "  send_authorized=" . (int)($camp['send_authorized'] ?? 0)
        . " by=" . ($camp['send_authorized_by'] ?? 'null')
        . " at=" . ($camp['send_authorized_at'] ?? 'null') . "\n";
    echo "  send_requested=" . (int)($camp['send_requested'] ?? 0)
        . " send_paused=" . (int)($camp['send_paused'] ?? 0) . "\n";
    $stored = (string)($camp['content_hash'] ?? '');
    $computed = CampaignContentHash::compute($pdo, $cid);
    echo '  content_hash: ' . (hash_equals($stored, $computed) ? 'MATCH' : 'MISMATCH') . "\n";
    $mailer = new MarketingMailer($pdo, (string)($appConfig['baseUrl'] ?? ''));
    echo '  sending policy: ' . json_encode($mailer->policy()->summary()) . "\n";
    $rs = $pdo->prepare("SELECT id, contact_id, prospect_email, status, skip_reason, error,
            gmail_message_id, gmail_thread_id, sent_at, thread_status
        FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
    $rs->execute([$cid]);
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  recip #{$r['id']} contact={$r['contact_id']} {$r['prospect_email']} status={$r['status']}"
            . ($r['skip_reason'] ? " skip={$r['skip_reason']}" : '')
            . ($r['error'] ? " err={$r['error']}" : '')
            . ($r['gmail_message_id'] ? " gmail_msg={$r['gmail_message_id']}" : '')
            . ($r['sent_at'] ? " sent_at={$r['sent_at']}" : '') . "\n";
    }
    exit(0);
}

/* ---------------- send ---------------- */

$mailer = new MarketingMailer($pdo, (string)($appConfig['baseUrl'] ?? ''));
if (!$mailer->policy()->enabled()) { echo "sending_disabled\n"; exit(1); }
if (!in_array($camp['status'], ['approved', 'partial', 'sending'], true)) {
    echo "campaign not approved (status={$camp['status']})\n"; exit(1);
}
if (!hash_equals((string)$camp['content_hash'], CampaignContentHash::compute($pdo, $cid))) {
    echo "content changed since approval - re-approve required\n"; exit(1);
}

// Atomic claim - identical to the admin endpoint.
$claim = $pdo->prepare("UPDATE marketing_campaigns SET status='sending', send_requested=1, send_paused=0, updated_at=NOW() WHERE id=? AND (
    status IN ('approved','partial')
    OR (status='sending' AND updated_at < NOW() - INTERVAL " . (int)MarketingMailer::STALE_RUNNING_SECONDS . " SECOND))");
$claim->execute([$cid]);
if ($claim->rowCount() !== 1) { echo "send already in progress\n"; exit(1); }

$recipIds = [];
if (isset($argv[3]) && trim((string)$argv[3]) !== '') {
    foreach (explode(',', (string)$argv[3]) as $x) {
        $rid = (int)trim($x);
        if ($rid > 0) $recipIds[$rid] = true;
    }
    $recipIds = array_keys($recipIds);
}
$batchSize = mo_send_batch_size();
if (count($recipIds) > $batchSize) { echo "too many recipients (max $batchSize)\n"; exit(1); }

$runner = new CampaignSendRunner($pdo, (string)($appConfig['baseUrl'] ?? ''), $mailer);
$recips = $runner->nextBatch($cid, $batchSize, $recipIds ?: null, true);
echo "batch: " . count($recips) . " recipient(s)\n";
$r = $runner->runBatch($camp, $recips);
if (isset($r['error'])) {
    mo_rollup($pdo, $cid);
    echo "ERROR: {$r['error']}\n"; exit(1);
}

$roll = mo_rollup($pdo, $cid);
if (mo_sendable_remaining($pdo, $cid) === 0) {
    $pdo->prepare('UPDATE marketing_campaigns SET send_requested=0 WHERE id=?')->execute([$cid]);
}
mo_audit($pdo, $ACTOR, 'campaign_send', $cid, [
    'sent' => $r['sent'], 'skipped' => $r['skipped'], 'failed' => $r['failed'],
    'uncertain' => $r['uncertain'], 'blocked' => $r['blocked'], 'sheet_failed' => $r['sheet_failed'],
    'mode' => $mailer->policy()->mode(), 'recipient_ids' => $recipIds ?: null,
    'send_requested' => mo_sendable_remaining($pdo, $cid) > 0,
]);

echo "RESULT sent={$r['sent']} skipped={$r['skipped']} failed={$r['failed']} uncertain={$r['uncertain']} blocked={$r['blocked']} sheet_failed={$r['sheet_failed']}\n";
foreach ($r['results'] as $x) {
    echo "  {$x['email']}: {$x['status']}" . (isset($x['reason']) ? " ({$x['reason']})" : '')
        . (isset($x['error']) ? " err={$x['error']}" : '')
        . (isset($x['message_id']) ? " msg={$x['message_id']}" : '') . "\n";
}
echo "campaign_status={$roll['status']} send_requested=" . (mo_sendable_remaining($pdo, $cid) > 0 ? 1 : 0)
    . " remaining=" . mo_sendable_remaining($pdo, $cid) . "\n";
exit(0);
