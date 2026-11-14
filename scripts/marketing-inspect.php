<?php
/**
 * Read-only inspection of marketing campaigns for production verification.
 * Prints aggregate counts and per-recipient thread state with masked
 * emails. Writes nothing.
 *
 * Run inside Cloud Run (job): php scripts/marketing-inspect.php [campaign_id]
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/MarketingMailer.php';
global $pdo, $appConfig;

// --gmail-probe: report whether the Gmail read path works in this
// environment (status codes only - no tokens, no message content).
if (in_array('--gmail-probe', $argv, true)) {
    $m = new MarketingMailer($pdo, (string)($appConfig['baseUrl'] ?? ''));
    $at = $m->accessToken();
    echo 'access_token: ' . ($at ? 'obtained' : 'FAILED') . "\n";
    if ($at) {
        $r = $m->gmailGet('/messages', ['q' => 'in:sent', 'maxResults' => 1]);
        echo 'messages.list status: ' . var_export($r['status'], true)
            . ' results=' . count($r['data']['messages'] ?? []) . "\n";
        if ($r['status'] !== 200) {
            echo 'error: ' . substr(json_encode($r['data']['error'] ?? $r['data']), 0, 300) . "\n";
        }
    }
    exit(0);
}

$mask = static function (?string $email): string {
    $email = (string)$email;
    $at = strpos($email, '@');
    if ($at === false) return '***';
    return substr($email, 0, 1) . '***' . substr($email, $at);
};

// --resolve-check: run OutreachThreadResolver for the newest follow-up
// campaign's draft/ready recipients and print outcomes. READ-ONLY: no
// thread_status writes, no sends, masked emails only.
if (in_array('--resolve-check', $argv, true)) {
    require_once __DIR__ . '/../api/marketing/ProspectCrm.php';
    require_once __DIR__ . '/../api/marketing/OutreachThreadResolver.php';
    require_once __DIR__ . '/../api/marketing/OutreachEligibility.php';
    $cid = $argv[2] ?? null;
    if ($cid) {
        $cs = $pdo->prepare("SELECT id FROM marketing_campaigns WHERE id=? AND kind='followup'");
        $cs->execute([$cid]);
    } else {
        $cs = $pdo->query("SELECT id FROM marketing_campaigns WHERE kind='followup' ORDER BY created_at DESC LIMIT 1");
    }
    $campId = $cs->fetchColumn();
    if (!$campId) { echo "no follow-up campaign found\n"; exit(0); }
    echo "campaign: $campId\n";
    $crm = new ProspectCrm($pdo);
    $mailer = new MarketingMailer($pdo, (string)($appConfig['baseUrl'] ?? ''));
    $resolver = new OutreachThreadResolver($pdo, [$mailer, 'gmailGet']);
    $rs = $pdo->prepare("SELECT id, prospect_email, status FROM marketing_campaign_recipients
        WHERE campaign_id=? AND status IN ('draft','ready') ORDER BY id");
    $rs->execute([$campId]);
    $recips = $rs->fetchAll(PDO::FETCH_ASSOC);
    $byEmail = $crm->findByEmails(array_map(static fn($r) => (string)$r['prospect_email'], $recips));
    $counts = [];
    foreach ($recips as $r) {
        $email = strtolower(trim((string)$r['prospect_email']));
        $notes = (string)($byEmail[$email]['fields']['notes'] ?? '');
        $t = $resolver->resolve($email, OutreachEligibility::gmailIdsFromNotes($notes), 'mo:' . $campId);
        $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
        printf("  r%-4s %-22s -> %-12s reason=%-28s source=%-12s ev=%-10s date=%s thread=%s labels=%s\n",
            $r['id'], $mask($email), $t['status'], $t['reason'] ?: '-', $t['source'] ?: '-',
            $t['evidence'] ?? '-', substr((string)($t['date'] ?? ''), 0, 22),
            !empty($t['thread_id']) ? 'yes' : 'no', $t['labels'] ?? '-');
    }
    echo 'summary: ' . json_encode($counts) . "\n";
    exit(0);
}

$cid = $argv[1] ?? null;
$camps = $cid
    ? $pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?') : null;
if ($cid) { $camps->execute([$cid]); $rows = [$camps->fetch(PDO::FETCH_ASSOC) ?: null]; }
else { $rows = $pdo->query('SELECT * FROM marketing_campaigns ORDER BY created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC); }

foreach ($rows as $c) {
    if (!$c) { echo "campaign $cid not found\n"; continue; }
    echo "== campaign {$c['id']} ==\n";
    echo "  name={$c['name']} kind={$c['kind']} status={$c['status']} new_thread_ack=" . (int)($c['new_thread_ack'] ?? -1)
        . " approved_by=" . ($c['approved_by'] ?? 'null') . " hash=" . substr((string)$c['content_hash'], 0, 12) . "...\n";
    $rs = $pdo->prepare("SELECT status, COUNT(*) n FROM marketing_campaign_recipients WHERE campaign_id=? GROUP BY status");
    $rs->execute([$c['id']]);
    echo "  recipients: " . json_encode($rs->fetchAll(PDO::FETCH_KEY_PAIR)) . "\n";
    $ts = $pdo->prepare("SELECT thread_status, COUNT(*) n FROM marketing_campaign_recipients WHERE campaign_id=? GROUP BY thread_status");
    $ts->execute([$c['id']]);
    echo "  thread_status: " . json_encode($ts->fetchAll(PDO::FETCH_KEY_PAIR)) . "\n";

    $rr = $pdo->prepare("SELECT id, contact_id, prospect_email, prospect_contact, status, thread_status, thread_reason, thread_source,
        gmail_message_id, gmail_thread_id, is_edited, sent_at
        FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
    $rr->execute([$c['id']]);
    foreach ($rr->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Prior-send evidence for this email (counts only, no content).
        $ev = $pdo->prepare("SELECT COUNT(*) FROM marketing_sends WHERE email_normalized=? AND status='sent'");
        $ev->execute([strtolower(trim((string)$r['prospect_email']))]);
        $priorSends = (int)$ev->fetchColumn();
        $cr = $pdo->prepare("SELECT COUNT(*) FROM marketing_campaign_recipients r2 JOIN marketing_campaigns c2 ON c2.id=r2.campaign_id
            WHERE r2.prospect_email=? AND r2.status='sent' AND c2.id<>?");
        $cr->execute([$r['prospect_email'], $c['id']]);
        $priorCampaignSends = (int)$cr->fetchColumn();
        printf("  r%-4s cid=%-6s %-22s st=%-10s thread=%-12s reason=%-32s src=%-14s sent_ids=%s prior_sends=%d prior_camp=%d edited=%d\n",
            $r['id'], $r['contact_id'] ?? '-', $mask($r['prospect_email']), $r['status'],
            $r['thread_status'] ?? 'NULL', $r['thread_reason'] ?? '-', $r['thread_source'] ?? '-',
            $r['gmail_message_id'] ? 'y' : 'n', $priorSends, $priorCampaignSends, (int)$r['is_edited']);
    }
}
echo "done\n";
