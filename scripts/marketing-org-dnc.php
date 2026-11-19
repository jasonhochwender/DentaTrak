<?php
/**
 * Organization-level Do Not Contact for one CRM organization.
 *
 * Same code path as the admin action (SuppressionService::suppressOrganization):
 * blocks every current and future contact of the organization and
 * suppresses its verified domain(s). Dry-run by default. Never sends email.
 *
 * Usage (Cloud Run job / CLI):
 *   php scripts/marketing-org-dnc.php --org-id=N [--expect-domain=example.com]
 *       [--apply --reason="..." --actor=admin@example.com] [--probe=someone@example.com]
 *
 * --expect-domain aborts unless that domain is among the organization's
 *   verified domains (guards against acting on the wrong organization).
 * --probe checks send eligibility for an address that is not in the CRM.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';
require_once __DIR__ . '/../api/marketing/MarketingMailer.php';
require_once __DIR__ . '/../api/marketing/CampaignSendSupport.php';
global $pdo;

$opt = ['org-id' => 0, 'expect-domain' => '', 'reason' => '', 'actor' => 'cli:org-dnc', 'probe' => []];
$apply = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') { $apply = true; continue; }
    if (preg_match('/^--(org-id|expect-domain|reason|actor|probe)=(.*)$/s', $a, $m)) {
        if ($m[1] === 'probe') $opt['probe'][] = strtolower(trim($m[2])); else $opt[$m[1]] = trim($m[2]);
    }
}
$orgId = (int)$opt['org-id'];
if ($orgId <= 0) exit("--org-id required\n");
$mask = function (string $e): string {
    $p = strrpos($e, '@');
    return $p === false ? '***' : substr($e, 0, 2) . '***' . substr($e, $p);
};

$svc = new SuppressionService($pdo);
$plan = $svc->orgDncPlan($orgId);
if ($plan === null) exit("organization $orgId not found\n");
echo 'mode: ' . ($apply ? 'APPLY' : 'dry-run') . "\n";
echo "organization: #{$plan['org']['id']} {$plan['org']['name']}\n";
echo 'currently Do Not Contact: ' . ($plan['org']['dnc'] ? "yes (since {$plan['org']['dnc_at']} by {$plan['org']['dnc_by']})" : 'no') . "\n";
echo 'verified domain(s): ' . ($plan['domains'] ? implode(', ', $plan['domains']) : '(none)') . "\n";
foreach ($plan['skipped_domains'] as $s) echo "  not suppressed as a domain: {$s['domain']} ({$s['reason']})\n";
echo "contacts: {$plan['contacts']}\n";
echo "pending campaign recipients: {$plan['pending_recipients']}\n";

if ($opt['expect-domain'] !== '' && !in_array(SuppressionService::cleanDomain($opt['expect-domain']), $plan['domains'], true)) {
    exit("ABORT: expected domain {$opt['expect-domain']} is not a verified domain of this organization\n");
}
if ($apply) {
    if ($opt['reason'] === '') exit("--reason required with --apply\n");
    $r = $svc->suppressOrganization($orgId, $opt['actor'], $opt['reason']);
    echo 'applied: ' . ($r['already_dnc'] ? 'organization was already Do Not Contact' : 'organization marked Do Not Contact') . "\n";
    echo '  domains newly suppressed: ' . ($r['new_domains'] ? implode(', ', $r['new_domains']) : '(none)') . "\n";
    $svc = new SuppressionService($pdo);
}

echo "verification:\n";
$after = $svc->orgDncPlan($orgId);
echo '  organization Do Not Contact: ' . ($after['org']['dnc'] ? 'yes' : 'no')
    . ($after['org']['dnc'] ? " (reason: {$after['org']['dnc_reason']}; {$after['org']['dnc_at']}; by {$after['org']['dnc_by']})" : '') . "\n";
foreach ($after['suppressed_domains'] as $d) echo "  domain suppressed: {$d['org_ref']} (source {$d['source']}, {$d['created_at']})\n";
$c = $pdo->prepare('SELECT id, email_normalized, status, dnc FROM marketing_contacts WHERE org_id=? ORDER BY id');
$c->execute([$orgId]);
foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $ct) {
    $e = (string)$ct['email_normalized'];
    $elig = $e !== '' ? $svc->eligibility([$e])[0]['eligible'] : false;
    echo "  contact #{$ct['id']} " . $mask($e) . " status='{$ct['status']}' contact_dnc={$ct['dnc']}"
        . ' address_suppressed=' . ($e !== '' && $svc->isSuppressed($e) ? 'yes' : 'no')
        . ' org_blocked=' . ($e !== '' && $svc->isOrgBlocked($e) ? 'yes' : 'no')
        . ' sendable=' . ($elig ? 'YES' : 'no') . "\n";
}
foreach ($opt['probe'] as $p) {
    $ctx = mo_elig_ctx($pdo, $svc, $p);
    echo "  probe $p: org_blocked=" . ($ctx['org_suppressed'] ? 'yes' : 'no')
        . ' sendable=' . ($svc->eligibility([$p])[0]['eligible'] ? 'YES' : 'no') . "\n";
}
// Unsent campaign recipients tied to this organization and whether the
// send-time gate would stop each one.
$emails = array_values(array_filter(array_map('strval', $pdo->query("SELECT email_normalized FROM marketing_contacts WHERE org_id=$orgId")->fetchAll(PDO::FETCH_COLUMN))));
$where = []; $args = [];
if ($emails) { $where[] = 'r.prospect_email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')'; $args = $emails; }
foreach ($after['suppressed_domains'] as $d) { $where[] = "SUBSTRING_INDEX(r.prospect_email, '@', -1) = ?"; $args[] = $d['org_ref']; }
if ($where) {
    $q = $pdo->prepare("SELECT r.id, r.prospect_email, r.status, c.name, c.status AS camp_status
        FROM marketing_campaign_recipients r JOIN marketing_campaigns c ON c.id = r.campaign_id
        WHERE r.status <> 'sent' AND (" . implode(' OR ', $where) . ') ORDER BY r.id');
    $q->execute($args);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    echo '  unsent campaign recipients for this organization: ' . count($rows) . "\n";
    foreach ($rows as $r) {
        $ctx = mo_elig_ctx($pdo, $svc, SuppressionService::normalizeEmail((string)$r['prospect_email']));
        echo "    recipient #{$r['id']} " . $mask((string)$r['prospect_email']) . " [{$r['status']}] in \"{$r['name']}\" ({$r['camp_status']}): send gate "
            . (($ctx['suppressed'] || $ctx['org_suppressed']) ? 'BLOCKS' : 'would ALLOW') . "\n";
    }
}
echo "done\n";
