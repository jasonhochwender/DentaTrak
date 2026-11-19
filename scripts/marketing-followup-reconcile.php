<?php
/**
 * Reconcile stale scheduled follow-up dates in the Marketing CRM.
 *
 * A contact whose follow-up_date is still set after a confirmed
 * follow-up send keeps showing as Overdue forever. This script finds
 * contacts with a past/current followup_date for which a CONFIRMED
 * follow-up campaign send exists on or after that date, and clears the
 * date so the contact moves to Awaiting Response. A followup_date LATER
 * than the newest confirmed follow-up send is an explicitly scheduled
 * next follow-up and is preserved. Last Contact Date alone is never
 * treated as evidence that a follow-up occurred.
 *
 * Evidence: marketing_campaign_recipients rows with status='sent' on
 * kind='followup' campaigns (confirmed sends only - never draft,
 * approved, failed or uncertain rows).
 *
 * Dry-run by default; pass --apply to write. Idempotent and safe to
 * rerun. Sends no email and touches no campaign recipient rows.
 *
 * Usage (Cloud Run job / CLI):
 *   php scripts/marketing-followup-reconcile.php [--apply]
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$apply = in_array('--apply', $argv, true);

// Newest confirmed follow-up send per normalized recipient email.
$sent = $pdo->query(
    "SELECT LOWER(TRIM(r.prospect_email)) email, MAX(LEFT(r.sent_at,10)) last_fu_send
     FROM marketing_campaign_recipients r
     JOIN marketing_campaigns c ON c.id = r.campaign_id
     WHERE c.kind='followup' AND r.status='sent' AND r.sent_at IS NOT NULL
     GROUP BY LOWER(TRIM(r.prospect_email))"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Contacts with a scheduled follow-up date that is not in the future
// relative to a confirmed send. followup_date is a DATE column.
$stale = $pdo->query(
    "SELECT id, email_normalized, followup_date, last_contact_date, status
     FROM marketing_contacts
     WHERE followup_date IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$clear = []; $keepFuture = []; $genuinelyOverdue = [];
foreach ($stale as $c) {
    $email = strtolower(trim((string)$c['email_normalized']));
    $fu = (string)$c['followup_date'];
    $lastSend = $sent[$email] ?? null;
    if ($lastSend !== null && $fu <= $lastSend) {
        $clear[] = $c;
    } elseif ($lastSend !== null && $fu > $lastSend) {
        // Scheduled after the newest confirmed follow-up send.
        $keepFuture[] = $c;
    } else {
        // No confirmed follow-up send evidence: leave untouched, even if
        // the date is past (genuinely overdue or awaiting real outreach).
        $genuinelyOverdue[] = $c;
    }
}

echo 'mode: ' . ($apply ? 'APPLY' : 'dry-run') . "\n";
echo 'contacts with followup_date set: ' . count($stale) . "\n";
echo 'stale (confirmed follow-up send on/after date -> clear): ' . count($clear) . "\n";
echo 'preserved (newer follow-up explicitly scheduled): ' . count($keepFuture) . "\n";
echo 'untouched (no confirmed follow-up send evidence): ' . count($genuinelyOverdue) . "\n";
foreach (array_slice($clear, 0, 25) as $c) {
    $email = (string)$c['email_normalized'];
    $at = strrpos($email, '@');
    $mask = $at === false ? '***' : substr($email, 0, 2) . '***' . substr($email, $at);
    echo "  clear #{$c['id']} {$mask} followup_date={$c['followup_date']} last_contact={$c['last_contact_date']} status={$c['status']}\n";
}
if (count($clear) > 25) echo '  ... and ' . (count($clear) - 25) . " more\n";

if ($apply && $clear) {
    // Re-verify inside the write: only rows whose date still matches the
    // value we evaluated are cleared.
    $upd = $pdo->prepare(
        "UPDATE marketing_contacts SET followup_date=NULL, updated_at=UTC_TIMESTAMP()
         WHERE id=? AND followup_date=?"
    );
    $n = 0;
    foreach ($clear as $c) {
        $upd->execute([(int)$c['id'], (string)$c['followup_date']]);
        $n += $upd->rowCount();
    }
    echo "cleared: $n followup_date value(s)\n";
}
echo "done\n";
