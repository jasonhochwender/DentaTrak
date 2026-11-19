<?php
/**
 * Reconcile recorded Gmail delivery failures with the bounce policy.
 *
 * Applies to events whose handling has not been applied yet (history from
 * before automatic handling, or events whose handling failed):
 *   permanent + identified address -> suppress, mark CRM contact bounced,
 *                                     resolve the event
 *   temporary                      -> expiring hold anchored to the failure
 *                                     date (never a permanent suppression)
 *   ambiguous / unidentified       -> left in the review queue
 *
 * Safe to rerun: each event is processed once. Never sends email.
 *
 * Usage (Cloud Run job / CLI):
 *   php scripts/marketing-reconcile-bounces.php            # dry-run report
 *   php scripts/marketing-reconcile-bounces.php --apply
 *   php scripts/marketing-reconcile-bounces.php --apply --convert-legacy-holds
 *
 * --convert-legacy-holds: temporary failures used to be written to the
 * permanent suppression store (source 'bounce_temp_hold'). This replaces
 * each such row with an expiring hold anchored to when it was created.
 * Rows with any unsubscribe evidence are kept. Without the flag they are
 * only reported.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/ReplyTracker.php';
global $pdo;

$apply = in_array('--apply', $argv, true);
$convert = in_array('--convert-legacy-holds', $argv, true);
$mask = function (string $e): string {
    $p = strrpos($e, '@');
    return $p === false ? '***' : substr($e, 0, 2) . '***' . substr($e, $p);
};

$tracker = new ReplyTracker($pdo, fn() => ['status' => null, 'data' => []], 'cli:bounce-reconciliation');
$svc = new SuppressionService($pdo);
if (!$svc->holdsAvailable()) exit("marketing_email_holds missing - run migrations/2026_11_29_marketing_bounce_holds.php first\n");

echo 'mode: ' . ($apply ? 'APPLY' : 'dry-run') . "\n";
$before = $tracker->bounceState();
$r = $tracker->reconcileBounces($apply);
echo "delivery failures on record: {$before['delivery_failures_total']}\n";
echo "events reviewed this run: {$r['reviewed']}\n";
echo "  permanent bounces: {$r['permanent']}\n";
echo '    addresses ' . ($apply ? 'newly suppressed' : 'to suppress') . ": {$r['newly_suppressed']}\n";
echo "    already suppressed: {$r['already_suppressed']}\n";
echo "  temporary failures: {$r['temporary']}\n";
if ($apply) echo "    holds still active: {$r['holds_active']}  (escalated: {$r['escalated']})\n";
echo "  ambiguous -> manual review: {$r['ambiguous']}\n";
echo "  no CRM contact -> manual review: {$r['unmatched']}\n";
echo "  errors: {$r['errors']}\n";

// Legacy temporary failures stored as permanent suppressions.
$legacy = $pdo->query("SELECT id, email_normalized, created_at FROM marketing_suppressions WHERE source='bounce_temp_hold' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo 'legacy temporary-failure rows in the permanent store: ' . count($legacy) . "\n";
$converted = 0; $kept = 0;
foreach ($legacy as $row) {
    $email = (string)$row['email_normalized'];
    $ev = $pdo->prepare(
        "SELECT (SELECT COUNT(*) FROM marketing_contacts WHERE email_normalized=? AND unsubscribed_at IS NOT NULL)
              + (SELECT COUNT(*) FROM marketing_reply_events WHERE from_email=? AND classification='unsubscribe_request')");
    $ev->execute([$email, $email]);
    $unsub = (int)$ev->fetchColumn() > 0;
    echo '  ' . $mask($email) . ' since ' . $row['created_at'] . ($unsub ? ' [unsubscribe evidence: kept]' : '') . "\n";
    if ($unsub) { $kept++; continue; }
    if (!($apply && $convert)) continue;
    try {
        $pdo->beginTransaction();
        $svc->holdEmail($email, 'bounce_temporary', 604800, 'legacy bounce_temp_hold', strtotime($row['created_at'] . ' UTC') ?: null);
        $pdo->prepare('DELETE FROM marketing_sync_queue WHERE suppression_id=?')->execute([(int)$row['id']]);
        $pdo->prepare("DELETE FROM marketing_suppressions WHERE id=? AND source='bounce_temp_hold'")->execute([(int)$row['id']]);
        $pdo->prepare(
            "INSERT INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source)
             SELECT id, org_id, 'suppression_converted', ?, 'cli:bounce-reconciliation', 'reply_sync'
             FROM marketing_contacts WHERE email_normalized=?"
        )->execute([json_encode(['email' => $email, 'from' => 'bounce_temp_hold', 'to' => 'temporary hold',
            'suppressed_since' => $row['created_at']]), $email]);
        $pdo->commit();
        $converted++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo '    ERROR: ' . $e->getMessage() . "\n";
    }
}
if ($legacy) echo "  converted to expiring holds: $converted, kept: $kept" . ($apply && $convert ? '' : ' (pass --apply --convert-legacy-holds to convert)') . "\n";

$s = $tracker->bounceState();
echo "current state:\n";
echo "  unprocessed delivery failures: {$s['unprocessed']}\n";
echo "  bounce events awaiting manual review: {$s['needs_review']}\n";
echo "  bounce suppressions (automatic + manual): {$s['bounce_suppressions']}\n";
echo "  active temporary holds: {$s['active_holds']} (escalated: {$s['escalated_holds']})\n";
echo "done\n";
