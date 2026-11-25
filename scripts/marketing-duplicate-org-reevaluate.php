<?php
/**
 * Re-evaluate records affected by the shared-mailbox-provider duplicate_org
 * defect: public email domains (gmail.com, outlook.com, yahoo.com, ...) were
 * treated as organization identifiers, so unrelated practices sharing a
 * provider were flagged duplicate_org.
 *
 * What it does (all deterministic, no sending, no approvals):
 *
 *  1. CONTACT REPORT - recomputes duplicate detection under the fixed rules
 *     and reports every contact whose old (shared-domain) flag was a false
 *     positive. Nothing is written here: flags are computed on read, so the
 *     fix itself already un-flags them. This section exists for validation
 *     and for the confirmed contact-id list.
 *
 *  2. RECIPIENT RESTORATION - finds marketing_campaign_recipients rows
 *     parked as 'suppressed' or 'skipped' whose skip_reason mentioned
 *     duplicate_org, re-evaluates them with the CURRENT eligibility rules,
 *     and restores only the rows that are now fully eligible:
 *        suppressed -> draft,  skipped -> draft
 *     (draft is deliberately conservative: the normal generate/approve
 *     workflow decides what happens next; nothing is sent or approved
 *     here). skip_reason and defect-related error text are cleared.
 *     Rows that are still ineligible are left untouched and reported with
 *     their current flags - a real DNC/suppression/hold is never lifted.
 *
 *  3. Writes one marketing_audit_log row per campaign summarizing the
 *     restoration (action 'dedup_reevaluate').
 *
 * Dry-run by default; pass --apply to write.
 *
 * Usage:
 *   php scripts/marketing-duplicate-org-reevaluate.php            # dry run
 *   php scripts/marketing-duplicate-org-reevaluate.php --apply
 *   php scripts/marketing-duplicate-org-reevaluate.php --contacts=1741,1802   # flag audit only
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/ProspectCrm.php';
global $pdo;

$apply = in_array('--apply', $argv, true);
$onlyContacts = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--contacts=([0-9,]+)$/', $a, $m)) {
        $onlyContacts = array_filter(array_map('intval', explode(',', $m[1])));
    }
}
$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "== duplicate_org shared-provider re-evaluation ($mode) ==\n\n";

$crm = new ProspectCrm($pdo);

/* ---------------------------------------------------------------- 1. contacts */

echo "-- contacts -------------------------------------------------\n";

// Old (defective) rule replayed for reporting: a contact was false-positive
// when a SHARED-provider domain match was the only thing flagging it.
$all = $pdo->query(
    "SELECT c.id, c.email_normalized, c.status, c.dnc, c.org_id,
            po.name AS parent_name
       FROM marketing_contacts c
       LEFT JOIN marketing_orgs o  ON o.id  = c.org_id
       LEFT JOIN marketing_orgs po ON po.id = o.parent_org_id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($onlyContacts !== null) {
    $want = array_fill_keys($onlyContacts, true);
    $all = array_values(array_filter($all, static fn($r) => isset($want[(int)$r['id']])));
}

$contactedStatuses = ['sent' => true, 'contacted' => true, 'replied' => true];
$oldDomains = [];      // every domain of a contacted contact (old behavior)
$oldParents = [];
foreach ($all as $r) {
    if (!isset($contactedStatuses[strtolower((string)$r['status'])])) continue;
    $e = strtolower(trim((string)$r['email_normalized']));
    $at = strrpos($e, '@');
    if ($at !== false) $oldDomains[substr($e, $at + 1)] = true;
    $p = strtolower(trim((string)($r['parent_name'] ?? '')));
    if ($p !== '') $oldParents[$p] = true;
}

$emails = [];
foreach ($all as $r) {
    $e = strtolower(trim((string)$r['email_normalized']));
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[$e] = true;
}
$annotated = $emails ? $crm->findByEmails(array_keys($emails)) : [];

$falsePos = []; $audit = [];
foreach ($all as $r) {
    $id = (int)$r['id'];
    $e = strtolower(trim((string)$r['email_normalized']));
    $at = strrpos($e, '@');
    $domain = $at === false ? '' : substr($e, $at + 1);
    // What the OLD code would have said:
    $oldDup = !isset($contactedStatuses[strtolower((string)$r['status'])])
        && (($domain !== '' && isset($oldDomains[$domain]))
            || (($p = strtolower(trim((string)($r['parent_name'] ?? '')))) !== '' && isset($oldParents[$p])));
    // What the FIXED code says:
    $prospect = $annotated[$e] ?? null;
    $newDup = $prospect !== null
        && in_array('duplicate_org', $prospect['eligibility']['initial']['flags'] ?? [], true);

    if ($oldDup && !$newDup && SuppressionService::isSharedDomain($domain)) {
        $flags = $prospect ? ($prospect['eligibility']['initial']['flags'] ?? []) : ['not_in_crm'];
        $falsePos[] = [$id, $e, $domain, implode(',', $flags)];
    }
    if ($onlyContacts !== null) {
        $audit[] = sprintf('  #%d %-34s old_dup=%s new_flags=[%s]', $id, $e,
            $oldDup ? 'Y' : '-',
            $prospect ? implode(',', $prospect['eligibility']['initial']['flags'] ?? []) : 'not_in_crm');
    }
}
if ($onlyContacts !== null) {
    echo implode("\n", $audit), "\n";
}
echo "contacts examined : " . count($all) . "\n";
echo "false-positive duplicate_org (shared provider): " . count($falsePos) . "\n";
foreach ($falsePos as [$id, $e, $d, $f]) {
    echo "  #$id $e  ($d)  current flags: " . ($f !== '' ? $f : 'none - eligible') . "\n";
}

/* --------------------------------------------------------------- 2. recipients */

echo "\n-- campaign recipients --------------------------------------\n";

$st = $pdo->query(
    "SELECT r.id, r.campaign_id, r.contact_id, r.prospect_email, r.status,
            r.skip_reason, r.error, c.kind, c.status AS campaign_status, c.name AS campaign_name
       FROM marketing_campaign_recipients r
       JOIN marketing_campaigns c ON c.id = r.campaign_id
      WHERE r.status IN ('suppressed','skipped')
        AND LOWER(COALESCE(r.skip_reason,'')) LIKE '%duplicate_org%'
      ORDER BY r.campaign_id, r.id"
);
$recips = $st->fetchAll(PDO::FETCH_ASSOC);
echo "recipients suppressed/skipped with duplicate_org in skip_reason: " . count($recips) . "\n";

$emails = [];
foreach ($recips as $r) {
    $e = strtolower(trim((string)$r['prospect_email']));
    if ($e !== '') $emails[$e] = true;
}
$annotated = $emails ? $crm->findByEmails(array_keys($emails)) : [];

$restore = []; $stillBlocked = [];
foreach ($recips as $r) {
    $e = strtolower(trim((string)$r['prospect_email']));
    $kind = $r['kind'] === 'followup' ? 'followup' : 'initial';
    $p = $annotated[$e] ?? null;
    $elig = $p ? ($p['eligibility'][$kind] ?? null) : null;
    if ($p !== null && $elig !== null && !empty($elig['ok'])
        && !in_array('do_not_contact', $p['flags'] ?? [], true)
        && !in_array('missing_contact', $p['flags'] ?? [], true)) {
        $restore[] = $r;
    } else {
        $stillBlocked[] = [$r, $elig['flags'] ?? ['not_in_crm']];
    }
}

echo "restorable now (eligible, no hard flags): " . count($restore) . "\n";
echo "still blocked (legitimate exclusions):   " . count($stillBlocked) . "\n";
foreach ($stillBlocked as [$r, $flags]) {
    echo "  keep #{$r['id']} {$r['prospect_email']} ({$r['status']}) -> " . implode(',', (array)$flags) . "\n";
}

if ($restore) {
    if ($apply) {
        $upd = $pdo->prepare(
            "UPDATE marketing_campaign_recipients
                SET status='draft', skip_reason=NULL,
                    error=IF(error IN ('crm_precheck','sheet_precheck','bounce_hold'), NULL, error)
              WHERE id=? AND status IN ('suppressed','skipped')"
        );
        $auditIns = $pdo->prepare(
            'INSERT INTO marketing_audit_log (actor_email, action, campaign_id, detail) VALUES (?,?,?,?)'
        );
        $byCampaign = [];
        foreach ($restore as $r) {
            $upd->execute([(int)$r['id']]);
            if ($upd->rowCount() > 0) {
                $byCampaign[$r['campaign_id']]['count'] = ($byCampaign[$r['campaign_id']]['count'] ?? 0) + 1;
                $byCampaign[$r['campaign_id']]['name'] = (string)$r['campaign_name'];
                $byCampaign[$r['campaign_id']]['ids'][] = (int)$r['id'];
            }
        }
        foreach ($byCampaign as $cid => $info) {
            $auditIns->execute(['system@dentatrak', 'dedup_reevaluate', $cid, json_encode([
                'defect' => 'duplicate_org_shared_provider',
                'restored' => $info['count'],
                'recipient_ids' => $info['ids'],
                'restored_to' => 'draft',
            ])]);
            echo "  applied: campaign {$info['name']} ($cid) restored {$info['count']} -> draft\n";
        }
        echo "restored total: " . array_sum(array_column($byCampaign, 'count')) . "\n";
    } else {
        foreach ($restore as $r) {
            echo "  would restore #{$r['id']} {$r['prospect_email']} {$r['status']}->draft"
                . " (campaign {$r['campaign_name']}, kind={$r['kind']}, was: {$r['skip_reason']})\n";
        }
        echo "(dry run - pass --apply to write)\n";
    }
}

echo "\nDone. No sends triggered; suppressed/hold/DNC rows untouched.\n";
