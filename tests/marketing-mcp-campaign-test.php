<?php
/**
 * MCP campaign-preparation tool tests (service level, local dev DB 3308).
 * Self-cleaning MCPGC fixtures. No email is sent; MARKETING_SENDING is
 * never touched. Exercises McpCampaignTools directly - the same class
 * api/mcp/server.php dispatches to.
 */
require_once __DIR__ . '/../api/marketing/McpCampaignTools.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';
require_once __DIR__ . '/../api/marketing/ProspectCrmAdmin.php';

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

$tag = 'MCPGC' . bin2hex(random_bytes(3));
$actor = 'mcp-campaign-test@dentatrak.com';
$tools = new McpCampaignTools($pdo, $actor, 'https://dentatrak.test');
$admin = new ProspectCrmAdmin($pdo, $actor);
$campaignIds = []; $contactIds = []; $orgIds = [];

function gc_cleanup(PDO $pdo, array $campaignIds, array $contactIds, array $orgIds): void {
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
    $pdo->exec("DELETE FROM marketing_audit_log WHERE actor_email='mcp-campaign-test@dentatrak.com'");
}

try {
    // -- fixtures: org + 3 contacts ------------------------------------------
    $org = $admin->createOrg(['name' => "$tag Dental Group", 'org_type' => 'practice']);
    $orgId = $org['id']; $orgIds[] = $orgId;
    $mk = function (string $name, string $email) use ($admin, $orgId, &$contactIds) {
        // Prior-outreach dates make these valid follow-up candidates.
        $c = $admin->createContact(['org_id' => $orgId, 'full_name' => $name, 'email' => $email,
            'status' => 'Contacted', 'source' => 'test',
            'first_contact_date' => '2026-10-01', 'last_contact_date' => '2026-10-01']);
        $contactIds[] = $c['id'];
        return $c['id'];
    };
    $c1 = $mk("$tag Alice", "alice@$tag.example");
    $c2 = $mk("$tag Bob", "bob@$tag.example");
    $c3 = $mk("$tag Cara", "cara@$tag.example");
    $missingId = 99999999;

    // -- confirm gates ---------------------------------------------------------
    $blocked = false;
    try { $tools->createCampaign(['name' => "$tag Camp", 'confirm' => false]); }
    catch (InvalidArgumentException $e) { $blocked = str_contains($e->getMessage(), 'confirm=true'); }
    $t('create_campaign requires confirm', $blocked);
    $blocked = false;
    try { $tools->saveCampaignMessages(['campaign_id' => 'x', 'messages' => [['subject' => 's', 'body_html' => 'b']]]); }
    catch (InvalidArgumentException $e) { $blocked = str_contains($e->getMessage(), 'confirm=true') || str_contains($e->getMessage(), 'campaign not editable') || str_contains($e->getMessage(), 'campaign not found'); }
    $t('save_campaign_messages gated', $blocked);

    // -- create_campaign dry_run + real + dedupe -------------------------------
    $dry = $tools->createCampaign(['name' => "$tag Dry", 'kind' => 'followup', 'dry_run' => true]);
    $t('create_campaign dry_run writes nothing', !empty($dry['dry_run'])
        && (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE name='$tag Dry'")->fetchColumn() === 0);

    $camp = $tools->createCampaign(['name' => "$tag Followup A", 'kind' => 'followup',
        'description' => 'pilot batch', 'confirm' => true]);
    $cid = $camp['campaign_id']; $campaignIds[] = $cid;
    $t('create_campaign returns draft id', $cid !== '' && ($camp['status'] ?? '') === 'draft');
    $crow = $pdo->query("SELECT status,kind FROM marketing_campaigns WHERE id=" . $pdo->quote($cid))->fetch(PDO::FETCH_ASSOC);
    $t('campaign row is draft/followup', $crow['status'] === 'draft' && $crow['kind'] === 'followup');

    $dup = $tools->createCampaign(['name' => "$tag Followup A", 'kind' => 'followup', 'confirm' => true]);
    $t('identical retry returns existing draft', ($dup['existing'] ?? false) === true && $dup['campaign_id'] === $cid);
    $t('no duplicate campaign created', (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE name='$tag Followup A'")->fetchColumn() === 1);

    // -- list_campaigns ---------------------------------------------------------
    $lc = $tools->listCampaigns(['kind' => 'followup']);
    $t('list_campaigns finds new draft', count(array_filter($lc['campaigns'], fn($c) => $c['id'] === $cid)) === 1);
    $badStatus = false;
    try { $tools->listCampaigns(['status' => 'bogus']); } catch (InvalidArgumentException $e) { $badStatus = true; }
    $t('list_campaigns validates status filter', $badStatus);

    // -- add_campaign_recipients -------------------------------------------------
    $addDry = $tools->addCampaignRecipients(['campaign_id' => $cid, 'contact_ids' => [$c1, $c2, $missingId], 'dry_run' => true]);
    $t('add recipients dry_run writes nothing', ($addDry['dry_run'] ?? false) === true
        && (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid))->fetchColumn() === 0);

    $add = $tools->addCampaignRecipients(['campaign_id' => $cid, 'contact_ids' => [$c1, $c2, $c3, $missingId], 'confirm' => true]);
    $t('add recipients returns per-contact results', count($add['results']) === 4);
    $t('missing contact reported not_found', count(array_filter($add['results'], fn($r) => ($r['reason'] ?? '') === 'not_found')) === 1);
    $rc = $pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid))->fetchColumn();
    $t('3 recipients added', (int)$rc === 3, (string)$rc);

    // duplicate add is refused per-contact
    $add2 = $tools->addCampaignRecipients(['campaign_id' => $cid, 'contact_ids' => [$c1], 'confirm' => true]);
    $t('duplicate email refused', ($add2['results'][0]['ok'] ?? true) === false
        && ($add2['results'][0]['reason'] ?? '') === 'duplicate_email_in_campaign');

    // suppression enforcement: suppress c2's email, fresh campaign add must mark suppressed
    (new SuppressionService($pdo))->suppress("bob@$tag.example", 'test_fixture', 'mcpgc');
    $camp2 = $tools->createCampaign(['name' => "$tag Followup B", 'kind' => 'followup', 'confirm' => true]);
    $campaignIds[] = $camp2['campaign_id'];
    $addS = $tools->addCampaignRecipients(['campaign_id' => $camp2['campaign_id'], 'contact_ids' => [$c2], 'confirm' => true]);
    $t('suppressed contact recorded suppressed not added', ($addS['results'][0]['status'] ?? '') === 'suppressed');
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized='bob@$tag.example'"); // cleanup

    // -- save_campaign_messages ---------------------------------------------------
    $rrows = $pdo->query("SELECT id, contact_id, prospect_email FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $rid1 = (int)$rrows[0]['id']; $rid2 = (int)$rrows[1]['id']; $rid3 = (int)$rrows[2]['id'];
    $cc2 = (int)$rrows[1]['contact_id']; $cc3 = (int)$rrows[2]['contact_id'];

    $msgs = [
        ['recipient_id' => $rid1, 'subject' => 'Following up - case tracking', 'body_html' => '<p>Hi Alice, following up on our note.</p>'],
        ['contact_id' => $cc2, 'subject' => 'Quick question', 'body_html' => '<p>Hi Bob</p><script>alert(1)</script>'],
        ['contact_id' => $cc3, 'subject' => 'Checking in', 'body_html' => '<p>Hi Cara</p>'],
        ['recipient_id' => 999999, 'subject' => 'x', 'body_html' => 'y'],
    ];
    $sv = $tools->saveCampaignMessages(['campaign_id' => $cid, 'messages' => $msgs, 'confirm' => true]);
    $t('save messages reports saved count', ($sv['saved'] ?? 0) === 3, (string)($sv['saved'] ?? -1));
    $badRow = $pdo->query("SELECT subject, body_html, is_edited, status FROM marketing_campaign_recipients WHERE id=$rid2")->fetch(PDO::FETCH_ASSOC);
    $t('script stripped from saved body', strpos($badRow['body_html'], '<script') === false && $badRow['is_edited'] == 1);
    $t('missing recipient reported', count(array_filter($sv['results'], fn($r) => ($r['reason'] ?? '') === 'recipient_not_in_campaign')) === 1);

    // campaign flips draft -> ready once all recipients have content
    $cs = $pdo->query("SELECT status FROM marketing_campaigns WHERE id=" . $pdo->quote($cid))->fetchColumn();
    $t('campaign becomes ready after all drafts saved', $cs === 'ready', (string)$cs);

    // editing after approval is refused: approve via direct status then try save
    $pdo->prepare("UPDATE marketing_campaigns SET status='approved', approved_by='test', approved_at=UTC_TIMESTAMP(), content_hash='x' WHERE id=?")->execute([$cid]);
    $locked = false;
    try { $tools->saveCampaignMessages(['campaign_id' => $cid, 'messages' => [['recipient_id' => $rid1, 'subject' => 's', 'body_html' => 'b']], 'confirm' => true]); }
    catch (InvalidArgumentException $e) { $locked = str_contains($e->getMessage(), 'not editable'); }
    $t('approved campaign refuses message edits', $locked);
    $pdo->prepare("UPDATE marketing_campaigns SET status='ready', approved_by=NULL, approved_at=NULL, content_hash=NULL WHERE id=?")->execute([$cid]);

    // sent recipient refused
    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='sent' WHERE id=?")->execute([$rid3]);
    $sv2 = $tools->saveCampaignMessages(['campaign_id' => $cid, 'messages' => [['recipient_id' => $rid3, 'subject' => 's', 'body_html' => 'b']], 'confirm' => true]);
    $t('sent recipient refused', ($sv2['results'][0]['reason'] ?? '') === 'recipient_locked_sent');
    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='ready' WHERE id=?")->execute([$rid3]);

    // -- preview_campaign_message ---------------------------------------------------
    $pv = $tools->previewCampaignMessage(['campaign_id' => $cid, 'recipient_id' => $rid1]);
    $t('preview returns assembled parts', ($pv['to_email'] ?? '') !== '' && isset($pv['html'], $pv['text'], $pv['thread']));
    $t('preview marks unsub placeholder', ($pv['unsub_placeholder'] ?? false) === true);
    $t('preview html includes footer', strpos((string)$pv['html'], 'unsubscribe') !== false);
    $pvBad = false;
    try { $tools->previewCampaignMessage(['campaign_id' => $cid, 'recipient_id' => 999999]); }
    catch (InvalidArgumentException $e) { $pvBad = true; }
    $t('preview unknown recipient fails', $pvBad);

    // -- get_campaign / readiness -----------------------------------------------------
    $gc = $tools->getCampaign(['campaign_id' => $cid]);
    $t('get_campaign returns recipients', count($gc['recipients'] ?? []) === 3);
    $t('get_campaign exposes sending summary only', isset($gc['sending']['mode']) && !isset($gc['sending']['allowlist']));

    $ready = $tools->getCampaignReadiness(['campaign_id' => $cid]);
    $t('readiness reports status+counts', ($ready['status'] ?? '') === 'ready' && ($ready['recipient_counts']['ready'] ?? 0) === 3);
    $t('readiness ready_for_admin_review', ($ready['ready_for_admin_review'] ?? false) === true);
    $t('readiness flags thread_unresolved for followups', count(array_filter($ready['warnings'] ?? [], fn($i) => ($i['code'] ?? '') === 'thread_unresolved')) === 3);

    // readiness on suppressed campaign shows blocker info
    $ready2 = $tools->getCampaignReadiness(['campaign_id' => $camp2['campaign_id']]);
    $t('readiness lists suppressed recipient', count(array_filter($ready2['warnings'] ?? [], fn($i) => ($i['code'] ?? '') === 'recipient_suppressed')) === 1);

    // audit rows written for mutations
    $aud = (int)$pdo->query("SELECT COUNT(*) FROM marketing_audit_log WHERE campaign_id IN (" . implode(',', array_map(fn($c) => $pdo->quote($c), $campaignIds)) . ") AND actor_email='$actor'")->fetchColumn();
    $t('mutations audit-logged', $aud >= 3, (string)$aud);

    // no sends: these tools must never write marketing_sends
    $sc = $pdo->query("SELECT COUNT(*) FROM marketing_sends s JOIN marketing_campaign_recipients r ON r.campaign_id IN (" . implode(',', array_map(fn($c) => $pdo->quote($c), $campaignIds)) . ") WHERE s.campaign_ref = r.prospect_email OR s.campaign_ref IN (SELECT id FROM marketing_campaigns WHERE id IN (" . implode(',', array_map(fn($c) => $pdo->quote($c), $campaignIds)) . "))")->fetchColumn();
    $t('no ledger writes from MCP tools', (int)$sc === 0, (string)$sc);

    echo "\n$pass passed, $fail failed\n";
} finally {
    gc_cleanup($pdo, $campaignIds, $contactIds, $orgIds);
}
exit($fail > 0 ? 1 : 0);
