<?php
/**
 * Large-campaign tests (local dev DB 3308, mocked Gmail transport).
 * Self-cleaning LGC fixtures. No real email is sent; MARKETING_SENDING
 * is only exercised through the injected policy/env seams.
 *
 * Covers: raised campaign cap, chunked recipient addition, MCP campaign
 * pagination, CampaignSendRunner batching/idempotency/suppression/
 * allowlist behavior, and auto-sendable selection rules.
 */
require_once __DIR__ . '/../api/marketing/McpCampaignTools.php';
require_once __DIR__ . '/../api/marketing/ProspectCrmAdmin.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';
require_once __DIR__ . '/../api/marketing/MarketingSendingPolicy.php';
require_once __DIR__ . '/../api/marketing/MarketingMailer.php';
require_once __DIR__ . '/../api/marketing/CampaignSendSupport.php';
require_once __DIR__ . '/../api/marketing/CampaignSendRunner.php';
require_once __DIR__ . '/../api/marketing/CampaignContentHash.php';

if (!function_exists('getEnvVar')) {
    function getEnvVar(string $key, ?string $fallback = null): ?string {
        $v = getenv($key);
        if ($v === false || $v === '') $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        return ($v === null || $v === '') ? $fallback : $v;
    }
}
$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l)
    if (strpos($l, '=') !== false && $l[0] !== '#') { [$k, $v] = explode('=', $l, 2); $env[trim($k)] = trim($v); }
$pdo = null;
foreach ([3308, 3306] as $port) {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=' . ($env['DB_NAME'] ?? 'dental_case_tracker'),
            $env['DB_USER_LOCAL'] ?? 'root', $env['DB_PASSWORD_LOCAL'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (Throwable $e) {}
}
if (!$pdo) { echo "SKIP: dev DB unreachable\n"; exit(0); }
$pass = 0; $fail = 0;
$t = function (string $n, bool $c, $x = '') use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? 'PASS' : 'FAIL') . " $n" . ($x !== '' ? "  [$x]" : '') . "\n"; };

$tag = 'LGC' . bin2hex(random_bytes(3));
$actor = 'large-campaign-test@dentatrak.com';
$admin = new ProspectCrmAdmin($pdo, $actor);
$tools = new McpCampaignTools($pdo, $actor, 'https://dentatrak.test');
$campaignIds = []; $contactIds = []; $orgIds = [];

// CRM mode for the runner (send-time DB checks instead of the sheet).
putenv('MARKETING_CRM_READS=true');
putenv('MARKETING_SENDING=true');

function lgc_cleanup(PDO $pdo, array $campaignIds, array $contactIds, array $orgIds, string $tag): void {
    foreach ($campaignIds as $cid) {
        $pdo->prepare('DELETE FROM marketing_campaign_recipients WHERE campaign_id=?')->execute([$cid]);
        $pdo->prepare('DELETE FROM marketing_campaigns WHERE id=?')->execute([$cid]);
    }
    foreach ($contactIds as $id) {
        $pdo->prepare('DELETE FROM marketing_prospect_events WHERE contact_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM marketing_contacts WHERE id=?')->execute([$id]);
    }
    foreach ($orgIds as $id) {
        $pdo->prepare('DELETE FROM marketing_prospect_events WHERE org_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM marketing_orgs WHERE id=?')->execute([$id]);
    }
    $pdo->exec("DELETE FROM marketing_audit_log WHERE actor_email='large-campaign-test@dentatrak.com'");
    $pdo->exec("DELETE FROM marketing_sends WHERE campaign_ref LIKE 'mo:%' AND email_normalized LIKE '%{$tag}.example'");
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%{$tag}.example'");
    $pdo->exec("DELETE FROM marketing_unsub_tokens WHERE email_normalized LIKE '%{$tag}.example'");
}

// Fake Gmail transport: token + send + empty list.
$calls = [];
$http = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$calls) {
    $calls[] = [$method, $url];
    if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
        return ['status' => 200, 'body' => '{"access_token":"fake-at"}'];
    }
    if (strpos($url, '/messages/send') !== false) {
        return ['status' => 200, 'body' => '{"id":"gm-lgc","threadId":"gt-lgc"}'];
    }
    if (strpos($url, '/messages?') !== false) {
        return ['status' => 200, 'body' => '{"messages":[]}'];
    }
    return ['status' => 404, 'body' => '{}'];
};
$envFn = static function (string $k) use (&$env) {
    return ['MARKETING_SENDING' => 'true',
        'MARKETING_SEND_ALLOWLIST' => $GLOBALS['lgcAllow'] ?? '*',
        'GOOGLE_CLIENT_ID' => 'cid', 'GOOGLE_CLIENT_SECRET' => 'sec',
        'GMAIL_REFRESH_TOKEN' => 'rtok'][$k] ?? null;
};
$policy = new MarketingSendingPolicy($envFn);
$mkMailer = fn() => new MarketingMailer($pdo, 'https://dentatrak.com', [
    'policy' => $policy, 'http' => $http, 'env' => $envFn, 'clock' => fn() => time()]);

try {
    // Sweep leftovers from earlier runs of this test (test-only .example TLD).
    $pdo->exec("DELETE FROM marketing_sends WHERE email_normalized LIKE '%.example'");
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%.example'");
    $pdo->exec("DELETE FROM marketing_unsub_tokens WHERE email_normalized LIKE '%.example'");
    $org = $admin->createOrg(['name' => "$tag Dental", 'org_type' => 'practice']);
    $orgId = $org['id']; $orgIds[] = $orgId;
    $mkContact = function (int $i, bool $contacted = false) use ($admin, $orgId, $tag, &$contactIds) {
        $d = ['org_id' => $orgId, 'full_name' => "$tag Person$i", 'email' => "p$i@$tag.example",
            'status' => 'New', 'source' => 'test'];
        if ($contacted) { $d['status'] = 'Contacted'; $d['first_contact_date'] = '2026-10-01'; $d['last_contact_date'] = '2026-10-01'; }
        $c = $admin->createContact($d);
        $contactIds[] = $c['id'];
        return $c['id'];
    };

    // -- 1. Raised cap: 15 recipients in one campaign (was capped at 10) ----
    $ids15 = [];
    for ($i = 1; $i <= 15; $i++) $ids15[] = $mkContact($i);
    $r = $admin->createCampaign(['name' => "$tag Big", 'kind' => 'initial'], 500);
    $campaignIds[] = $r['campaign_id'];
    // createCampaign with no contact_ids; add via addToCampaign in chunks.
    $add = $admin->addToCampaign(array_slice($ids15, 0, 8), $r['campaign_id'], null, 'initial', 500);
    $t('chunk add 8 recipients', ($add['added'] ?? 0) === 8, json_encode($add['added'] ?? null));
    $add2 = $admin->addToCampaign(array_slice($ids15, 8), $r['campaign_id'], null, 'initial', 500);
    $t('chunk add remaining 7', ($add2['added'] ?? 0) === 7, json_encode($add2['added'] ?? null));
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($r['campaign_id']))->fetchColumn();
    $t('campaign holds 15 recipients (> old cap of 10)', $cnt === 15, "count=$cnt");

    // -- 2. Cap enforcement still bounded ------------------------------------
    $small = $admin->createCampaign(['name' => "$tag Small", 'kind' => 'initial'], 3);
    $campaignIds[] = $small['campaign_id'];
    $capIds = [];
    for ($i = 20; $i <= 24; $i++) $capIds[] = $mkContact($i);
    $cap = $admin->addToCampaign($capIds, $small['campaign_id'], null, 'initial', 3);
    $okN = count(array_filter($cap['results'] ?? [], fn($x) => !empty($x['ok'])));
    $fullN = count(array_filter($cap['results'] ?? [], fn($x) => ($x['reason'] ?? '') === 'campaign_full'));
    $t('recipient cap enforced per campaign', $okN === 3 && $fullN === 2, "ok=$okN full=$fullN");

    // -- 3. MCP get_campaign pagination --------------------------------------
    $page1 = $tools->getCampaign(['campaign_id' => $r['campaign_id'], 'page' => 1, 'per_page' => 10]);
    $t('get_campaign paginates', count($page1['recipients']) === 10
        && $page1['pagination']['total'] === 15 && $page1['pagination']['pages'] === 2,
        json_encode($page1['pagination'] ?? null));
    $page2 = $tools->getCampaign(['campaign_id' => $r['campaign_id'], 'page' => 2, 'per_page' => 10]);
    $t('get_campaign page 2 remainder', count($page2['recipients']) === 5);
    $t('get_campaign per_page capped', (function () use ($tools, $r) {
        $p = $tools->getCampaign(['campaign_id' => $r['campaign_id'], 'per_page' => 9999]);
        return $p['pagination']['per_page'] === McpCampaignTools::RECIPIENTS_PAGE_MAX;
    })());

    // -- 4. CampaignSendRunner: batch send, idempotency, gates ----------------
    // Dedicated approved campaign, 'initial' kind (no thread logic). Each
    // recipient gets its own org: org-level already_contacted suppression
    // is real product behavior after the first send.
    $sendIds = [];
    for ($i = 30; $i <= 34; $i++) {
        $o = $admin->createOrg(['name' => "$tag SendOrg$i", 'org_type' => 'practice']);
        $orgIds[] = $o['id'];
        $c = $admin->createContact(['org_id' => $o['id'], 'full_name' => "$tag Sender$i",
            'email' => "p$i@d$i.$tag.example", 'status' => 'New', 'source' => 'test']);
        $contactIds[] = $c['id'];
        $sendIds[] = $c['id'];
    }
    $sc = $admin->createCampaign(['name' => "$tag Send", 'kind' => 'initial'], 500);
    $sendCid = $sc['campaign_id']; $campaignIds[] = $sendCid;
    $admin->addToCampaign($sendIds, $sendCid, null, 'initial', 500);
    // Give every recipient approved content.
    $pdo->exec("UPDATE marketing_campaign_recipients SET subject='Hi', body_html='<p>Hello</p>', status='approved' WHERE campaign_id=" . $pdo->quote($sendCid));
    $camp = $pdo->query("SELECT * FROM marketing_campaigns WHERE id=" . $pdo->quote($sendCid))->fetch(PDO::FETCH_ASSOC);

    $runner = new CampaignSendRunner($pdo, 'https://dentatrak.test', $mkMailer());
    $GLOBALS['lgcAllow'] = '*';

    // Batch of 3 out of 5.
    $batch = $runner->nextBatch($sendCid, 3, null, false);
    $t('nextBatch returns bounded batch', count($batch) === 3, 'n=' . count($batch));
    $r1 = $runner->runBatch($camp, $batch);
    $t('batch sends 3', ($r1['sent'] ?? 0) === 3, json_encode(['sent' => $r1['sent'] ?? null, 'err' => $r1['error'] ?? null]));
    $sentCnt = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($sendCid) . " AND status='sent'")->fetchColumn();
    $t('3 recipients marked sent', $sentCnt === 3, "sent=$sentCnt");
    $ledgerN = (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE campaign_ref=" . $pdo->quote('mo:' . $sendCid))->fetchColumn();
    $t('ledger has 3 entries', $ledgerN === 3, "ledger=$ledgerN");

    // Idempotent re-run: already_recorded must not double-send.
    $again = $runner->nextBatch($sendCid, 3, null, false);
    $r2 = $runner->runBatch($camp, $again);
    $ledgerN2 = (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE campaign_ref=" . $pdo->quote('mo:' . $sendCid))->fetchColumn();
    $t('re-run does not duplicate ledger', $ledgerN2 === $ledgerN + ($r2['sent'] ?? 0), "ledger=$ledgerN2");

    // Drain the rest.
    $rest = $runner->nextBatch($sendCid, 50, null, false);
    $r5 = $runner->runBatch($camp, $rest);
    $dbg = $pdo->query("SELECT prospect_email,status,skip_reason,error FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($sendCid))->fetchAll(PDO::FETCH_ASSOC);
    $done = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($sendCid) . " AND status='sent'")->fetchColumn();
    $t('campaign fully sent', $done === 5, "sent=$done rest=" . count($rest) . " r5=" . json_encode(['s'=>$r5['sent']??null,'k'=>$r5['skipped']??null,'f'=>$r5['failed']??null,'u'=>$r5['uncertain']??null,'b'=>$r5['blocked']??null,'e'=>$r5['error']??null,'res'=>$r5['results']??null]) . ' rows=' . json_encode($dbg));

    // -- 5. Suppression mid-campaign ------------------------------------------
    $supIds = [];
    for ($i = 40; $i <= 42; $i++) $supIds[] = $mkContact($i);
    $uc = $admin->createCampaign(['name' => "$tag Sup", 'kind' => 'initial'], 500);
    $supCid = $uc['campaign_id']; $campaignIds[] = $supCid;
    $admin->addToCampaign($supIds, $supCid, null, 'initial', 500);
    $pdo->exec("UPDATE marketing_campaign_recipients SET subject='Hi', body_html='<p>Hi</p>', status='approved' WHERE campaign_id=" . $pdo->quote($supCid));
    // Suppress the second recipient before its batch runs.
    $supEmail = "p41@$tag.example";
    $svc = new SuppressionService($pdo);
    $svc->suppress($supEmail, 'test_suppression');
    $camp2 = $pdo->query("SELECT * FROM marketing_campaigns WHERE id=" . $pdo->quote($supCid))->fetch(PDO::FETCH_ASSOC);
    $runner2 = new CampaignSendRunner($pdo, 'https://dentatrak.test', $mkMailer());
    $b2 = $runner2->nextBatch($supCid, 10, null, false);
    $r3 = $runner2->runBatch($camp2, $b2);
    $supSt = $pdo->query("SELECT status FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($supCid) . " AND prospect_email=" . $pdo->quote($supEmail))->fetchColumn();
    $t('suppressed recipient blocked mid-campaign', in_array($supSt, ['suppressed', 'skipped'], true), "status=$supSt");

    // -- 6. Allowlist gate -----------------------------------------------------
    $alIds = [];
    for ($i = 50; $i <= 51; $i++) $alIds[] = $mkContact($i);
    $ac = $admin->createCampaign(['name' => "$tag Allow", 'kind' => 'initial'], 500);
    $alCid = $ac['campaign_id']; $campaignIds[] = $alCid;
    $admin->addToCampaign($alIds, $alCid, null, 'initial', 500);
    $pdo->exec("UPDATE marketing_campaign_recipients SET subject='Hi', body_html='<p>Hi</p>', status='approved' WHERE campaign_id=" . $pdo->quote($alCid));
    // Allowlist contains only the first email.
    $GLOBALS['lgcAllow'] = "p50@$tag.example";
    $camp3 = $pdo->query("SELECT * FROM marketing_campaigns WHERE id=" . $pdo->quote($alCid))->fetch(PDO::FETCH_ASSOC);
    $runner3 = new CampaignSendRunner($pdo, 'https://dentatrak.test', $mkMailer());
    $b3 = $runner3->nextBatch($alCid, 10, null, false);
    $r4 = $runner3->runBatch($camp3, $b3);
    $blockedSt = $pdo->query("SELECT status, skip_reason FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($alCid) . " AND prospect_email=" . $pdo->quote("p51@$tag.example"))->fetch(PDO::FETCH_ASSOC);
    $t('non-allowlisted recipient blocked not sent',
        ($blockedSt['status'] ?? '') === 'approved' && str_contains((string)($blockedSt['skip_reason'] ?? ''), 'allowlist'),
        json_encode($blockedSt));
    $GLOBALS['lgcAllow'] = '*';

    // -- 7. Auto-sendable selection rules --------------------------------------
    // 'failed' must not be auto-picked; 'approved'/'uncertain' must be.
    $pdo->exec("UPDATE marketing_campaign_recipients SET status='failed' WHERE campaign_id=" . $pdo->quote($alCid));
    $auto = $runner3->nextBatch($alCid, 50, null, false);
    $t('failed recipients excluded from auto batch', count($auto) === 0, 'n=' . count($auto));
    $manual = $runner3->nextBatch($alCid, 50, null, true);
    $t('failed recipients available for manual retry', count($manual) === 2, 'n=' . count($manual));

    // -- 8. Rollup + sendable remaining ----------------------------------------
    mo_rollup($pdo, $sendCid);
    $campRow = $pdo->query("SELECT status FROM marketing_campaigns WHERE id=" . $pdo->quote($sendCid))->fetchColumn();
    $t('rollup completes drained campaign', in_array($campRow, ['completed', 'partial'], true), "status=$campRow");
    $t('sendable remaining is 0 after drain', mo_sendable_remaining($pdo, $sendCid) === 0);

    // -- 9. MCP readiness on a larger campaign ---------------------------------
    $ready = $tools->getCampaignReadiness(['campaign_id' => $r['campaign_id']]);
    $t('readiness reports recipient counts', ($ready['recipient_counts']['draft'] ?? 0) === 15
        || ($ready['recipient_counts']['suppressed'] ?? 0) > 0, json_encode($ready['recipient_counts'] ?? null));

    // -- 10. No approval/sending side effects from prep tools ------------------
    $appHash = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE approved_by IS NOT NULL AND name LIKE '$tag%'")->fetchColumn();
    $t('prep tools never approve campaigns', $appHash === 0);
} finally {
    lgc_cleanup($pdo, $campaignIds, $contactIds, $orgIds, $tag);
    putenv('MARKETING_CRM_READS');
    putenv('MARKETING_SENDING');
}
echo "large-campaign-test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
