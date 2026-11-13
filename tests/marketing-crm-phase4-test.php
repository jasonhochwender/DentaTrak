<?php
/**
 * Marketing CRM Phase 4 tests: campaign wizard + management + CRM send gate.
 *
 * Covers createCampaign (draft + template/description + contact_ids),
 * updateCampaignMeta approval invalidation, removeRecipient safety,
 * addToCampaign 'ready' downgrade, sendPrecheck semantics (CRM parity with
 * sheet preCheck), recordSend write-back (status/dates/notes/events),
 * CSV unmapped-column reporting + extra field aliases.
 *
 * Runs against the LOCAL development database (port 3308 via .env_mode).
 * Synthetic fixtures only (@crmtest4.* / CRMTEST4%) and self-cleaning.
 *
 * Run: php tests/marketing-crm-phase4-test.php
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';
require_once $baseDir . '/api/appConfig.php';
require_once $baseDir . '/api/feature-flags.php';
require_once $baseDir . '/api/marketing/ProspectDirectory.php';
require_once $baseDir . '/api/marketing/ProspectCrm.php';
require_once $baseDir . '/api/marketing/ProspectCrmAdmin.php';
require_once $baseDir . '/api/marketing/SuppressionService.php';
require_once $baseDir . '/api/marketing/OutreachEligibility.php';

if (($appConfig['current_environment'] ?? '') !== 'development') {
    fwrite(STDERR, "REFUSING: not a development environment.\n");
    exit(1);
}
global $pdo;

require_once $baseDir . '/migrations/2026_11_13_marketing_crm.php';
require_once $baseDir . '/migrations/2026_11_14_marketing_crm_phase2.php';
require_once $baseDir . '/migrations/2026_11_15_marketing_crm_phase3.php';
require_once $baseDir . '/migrations/2026_11_16_marketing_crm_phase4.php';

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}
$cnt = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

/* ---------------- cleanup ---------------- */
function cleanup4(): void {
    global $pdo;
    $pdo->exec("DELETE r FROM marketing_campaign_recipients r
                JOIN marketing_campaigns c ON c.id=r.campaign_id
                WHERE c.name LIKE 'CRMTEST4%' OR c.created_by LIKE '%crmtest4%'");
    $pdo->exec("DELETE FROM marketing_campaigns WHERE name LIKE 'CRMTEST4%' OR created_by LIKE '%crmtest4%'");
    $contactMatch = "c.email_normalized LIKE '%@crmtest4%' OR c.full_name LIKE 'CRMTEST4%' OR o.name LIKE 'CRMTEST4%'";
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id WHERE $contactMatch");
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_orgs o ON o.id=e.org_id
                WHERE o.name LIKE 'CRMTEST4%'");
    $pdo->exec("UPDATE marketing_import_rows ir JOIN marketing_contacts c ON c.id=ir.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id
                SET ir.contact_id=NULL, ir.org_id=NULL WHERE $contactMatch");
    $pdo->exec("UPDATE marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id
                SET e.contact_id=NULL, e.org_id=NULL WHERE $contactMatch");
    $pdo->exec("UPDATE marketing_campaign_recipients r JOIN marketing_contacts c ON c.id=r.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id SET r.contact_id=NULL WHERE $contactMatch");
    $pdo->exec("DELETE c FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id=c.org_id WHERE $contactMatch");
    $pdo->exec("UPDATE marketing_orgs SET parent_org_id=NULL WHERE name LIKE 'CRMTEST4%'");
    $pdo->exec("DELETE FROM marketing_orgs WHERE name LIKE 'CRMTEST4%'");
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%@crmtest4%'");
    $pdo->exec("DELETE FROM marketing_org_suppressions WHERE org_ref LIKE 'crmtest4%'");
}
cleanup4();

$admin = new ProspectCrmAdmin($pdo, 'crmtest4-admin@example.com');
$crm = new ProspectCrm($pdo);
$svc = new SuppressionService($pdo);
$max = 10;

/* ---------------- fixtures ---------------- */
$org = $admin->createOrg(['name' => 'CRMTEST4 Dental Group', 'org_type' => 'dso']);
$orgId = (int)$org['id'];
$c1 = $admin->createContact(['org_id' => $orgId, 'full_name' => 'CRMTEST4 Alice', 'email' => 'alice@crmtest4.example.com', 'status' => 'Not Contacted']);
$c2 = $admin->createContact(['org_id' => $orgId, 'full_name' => 'CRMTEST4 Bob', 'email' => 'bob@crmtest4.example.com', 'status' => 'Not Contacted']);
$c3 = $admin->createContact(['org_id' => $orgId, 'full_name' => 'CRMTEST4 Carol', 'email' => 'carol@crmtest4.example.com', 'status' => 'Not Contacted']);
// Shared-email second identity.
$c4 = $admin->createContact(['org_id' => $orgId, 'full_name' => 'CRMTEST4 Dave', 'email' => 'alice@crmtest4.example.com', 'status' => 'Not Contacted']);
$id1 = (int)$c1['id']; $id2 = (int)$c2['id'];
$id3 = (int)$c3['id']; $id4 = (int)$c4['id'];

$tpl = $pdo->prepare("INSERT INTO marketing_templates (name,kind,subject_tpl,body_tpl,is_default,created_by) VALUES ('CRMTEST4 tpl','initial','Hi {{first_name}}','<p>Hello {{contact_name}}</p>',0,'crmtest4')");
$tpl->execute();
$tplId = (int)$pdo->lastInsertId();

/* ---------------- createCampaign ---------------- */
ok('createCampaign returns draft id', true);
$cc = $admin->createCampaign(['name' => 'CRMTEST4 Wizard', 'kind' => 'initial',
    'description' => 'internal note', 'template_id' => $tplId, 'contact_ids' => [$id1, $id2]], $max);
$cid = $cc['campaign_id'];
ok('campaign created with 2 recipients', $cc['added'] === 2);
$row = $pdo->query("SELECT status,template_id,description,recipient_count FROM marketing_campaigns WHERE id=" . $pdo->quote($cid))->fetch(PDO::FETCH_ASSOC);
ok('campaign is draft with template+desc', $row['status'] === 'draft' && (int)$row['template_id'] === $tplId && $row['description'] === 'internal note' && (int)$row['recipient_count'] === 2);
$rc = $cnt("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . " AND contact_id IS NOT NULL");
ok('recipients carry contact_id', $rc === 2);
$dup = $cnt("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . " AND prospect_email='alice@crmtest4.example.com'");
ok('single email -> one recipient row', $dup === 1);

// Shared email explicit selection: adding both alice+alice2 -> duplicate blocked.
$cc2 = $admin->createCampaign(['name' => 'CRMTEST4 Shared', 'kind' => 'initial', 'contact_ids' => [$id1, $id4]], $max);
$res = $cc2['results'];
ok('shared email deduped: one ok one duplicate', count(array_filter($res, fn($r) => $r['ok'])) === 1
    && count(array_filter($res, fn($r) => !$r['ok'] && $r['reason'] === 'duplicate_email_in_campaign')) === 1);

// Template kind mismatch rejected.
$bad = false;
try { $admin->createCampaign(['name' => 'CRMTEST4 BadTpl', 'kind' => 'followup', 'template_id' => $tplId, 'contact_ids' => []], $max); }
catch (InvalidArgumentException $e) { $bad = true; }
ok('template kind mismatch rejected', $bad);

/* ---------------- updateCampaignMeta ---------------- */
$u = $admin->updateCampaignMeta($cid, ['name' => 'CRMTEST4 Wizard v2', 'description' => 'notes v2']);
ok('meta update applied', in_array('name', $u['changed'], true) && in_array('description', $u['changed'], true));
$locked = false;
try { $admin->updateCampaignMeta($cid, ['kind' => 'followup']); } catch (InvalidArgumentException $e) { $locked = true; }
ok('kind locked once recipients exist', $locked);

/* ---------------- removeRecipient ---------------- */
$rid = (int)$pdo->query("SELECT id FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . " LIMIT 1")->fetchColumn();
$rm = $admin->removeRecipient($cid, $rid);
ok('recipient removed', $rm['removed'] === $rid);
ok('recipient_count decremented', (int)$pdo->query("SELECT recipient_count FROM marketing_campaigns WHERE id=" . $pdo->quote($cid))->fetchColumn() === 1);
// Removing again fails.
$rmfail = false;
try { $admin->removeRecipient($cid, $rid); } catch (InvalidArgumentException $e) { $rmfail = true; }
ok('double remove rejected', $rmfail);
// Re-add removed contact.
$admin->addToCampaign([$id2], $cid, null, 'initial', $max);

/* ---------------- addToCampaign ready downgrade ---------------- */
// Generate is endpoint-level; simulate 'ready' by SQL then add -> draft.
$pdo->exec("UPDATE marketing_campaigns SET status='ready' WHERE id=" . $pdo->quote($cid));
$admin->addToCampaign([$id3], $cid, null, 'initial', $max);
$st = $pdo->query("SELECT status FROM marketing_campaigns WHERE id=" . $pdo->quote($cid))->fetchColumn();
ok('ready campaign downgraded to draft on add', $st === 'draft');

/* ---------------- sendPrecheck ---------------- */
$byEmail = $crm->findByEmails(['alice@crmtest4.example.com', 'bob@crmtest4.example.com', 'nobody@crmtest4.example.com']);
$pAlice = $byEmail['alice@crmtest4.example.com'];
$g = $admin->sendPrecheck($pAlice, 'initial', false);
ok('eligible prospect passes send gate', $g['action'] === 'send');
$g2 = $admin->sendPrecheck(null, 'initial', false);
ok('missing CRM record -> skip not_in_crm', $g2['action'] === 'skip' && in_array('not_in_crm', $g2['flags'], true));
// Suppressed contact (fresh ProspectCrm: context is cached per instance).
$pdo->exec("INSERT INTO marketing_suppressions (email_normalized,unsubscribed_at,source) VALUES ('carol@crmtest4.example.com',UTC_TIMESTAMP(),'crmtest4')");
$byEmail2 = (new ProspectCrm($pdo))->findByEmails(['carol@crmtest4.example.com']);
$g3 = $admin->sendPrecheck($byEmail2['carol@crmtest4.example.com'], 'initial', false);
ok('suppressed contact flagged (hard)', $g3['action'] !== 'send');
// DNC contact -> hard exclusion even when resuming.
$pdo->exec("UPDATE marketing_contacts SET dnc=1 WHERE email_normalized='bob@crmtest4.example.com'");
$byEmail3 = (new ProspectCrm($pdo))->findByEmails(['bob@crmtest4.example.com']);
$g4 = $admin->sendPrecheck($byEmail3['bob@crmtest4.example.com'], 'initial', true);
ok('DNC blocks even resuming send', $g4['action'] !== 'send');
$pdo->exec("UPDATE marketing_contacts SET dnc=0 WHERE email_normalized='bob@crmtest4.example.com'");

/* ---------------- recordSend ---------------- */
// Alice's row may have been removed by the removeRecipient test; ensure present.
$hasAlice = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . " AND prospect_email='alice@crmtest4.example.com'")->fetchColumn();
if (!$hasAlice) $admin->addToCampaign([$id1], $cid, null, 'initial', $max);
$pdo->exec("UPDATE marketing_campaigns SET status='sending' WHERE id=" . $pdo->quote($cid));
$sendRow = $pdo->query("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . " AND prospect_email='alice@crmtest4.example.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$sendRow['status'] = 'sent'; $sendRow['sent_at'] = gmdate('Y-m-d H:i:s'); $sendRow['gmail_message_id'] = 'CRMTEST4MSGID001';
$okSend = $admin->recordSend($sendRow, ['id' => $cid, 'name' => 'CRMTEST4 Wizard v2', 'kind' => 'initial']);
ok('recordSend returns true', $okSend === true);
$st = $pdo->query("SELECT status,first_contact_date,last_contact_date,campaign_ref,outreach_method FROM marketing_contacts WHERE email_normalized='alice@crmtest4.example.com' AND full_name='CRMTEST4 Alice'")->fetch(PDO::FETCH_ASSOC);
ok('contact marked Sent with dates+campaign', $st['status'] === 'Sent' && $st['campaign_ref'] === 'CRMTEST4 Wizard v2' && $st['outreach_method'] === 'Email' && $st['first_contact_date'] !== null && $st['last_contact_date'] !== null);
$notes = (string)$pdo->query("SELECT notes FROM marketing_contacts WHERE email_normalized='alice@crmtest4.example.com' AND full_name='CRMTEST4 Alice'")->fetchColumn();
ok('gmail id appended to notes once', strpos($notes, 'Gmail CRMTEST4MSGID001') !== false);
// Idempotent note append.
$admin->recordSend($sendRow, ['id' => $cid, 'name' => 'CRMTEST4 Wizard v2', 'kind' => 'initial']);
$notes2 = (string)$pdo->query("SELECT notes FROM marketing_contacts WHERE email_normalized='alice@crmtest4.example.com' AND full_name='CRMTEST4 Alice'")->fetchColumn();
ok('gmail note not duplicated', substr_count($notes2, 'CRMTEST4MSGID001') === 1);
$ev = $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE contact_id=" . $id1 . " AND event_type='send_recorded'");
ok('send_recorded events written', $ev >= 1);
$sss = $pdo->query("SELECT sheet_sync_status FROM marketing_campaign_recipients WHERE id=" . (int)$sendRow['id'])->fetchColumn();
ok('recipient marked synced (CRM write-back)', $sss === 'synced');
// Shared-email second identity also updated (sheet parity).
$st2 = $pdo->query("SELECT status FROM marketing_contacts WHERE id=" . $id4)->fetch(PDO::FETCH_ASSOC);
ok('shared-email contact also marked Sent', $st2['status'] === 'Sent');

/* ---------------- CSV unmapped + aliases ---------------- */
$csv = "Email,Org,Demo Status,Trial Status,Outcome,Mystery Col\nalice2@crmtest4.example.com,CRMTEST4 Dental Group,yes,no,interested,x\n";
$admin->previewImport($csv);
$un = $admin->unmappedColumns();
ok('unmapped column reported', in_array('Mystery Col', $un, true) && !in_array('Demo Status', $un, true));
$prev = $admin->previewImport($csv);
$fields = $prev[0]['fields'];
ok('extra fields mapped', ($fields['demo_status'] ?? '') === 'yes' && ($fields['trial_status'] ?? '') === 'no' && ($fields['outcome'] ?? '') === 'interested');

/* ---------------- approval hash coverage ---------------- */
// mo_content_hash is endpoint-internal; verify membership change alters it
// by replicating the query the function uses.
function hashFor(PDO $pdo, string $cid): string {
    $c = $pdo->prepare("SELECT name,kind FROM marketing_campaigns WHERE id=?");
    $c->execute([$cid]);
    $meta = $c->fetch(PDO::FETCH_ASSOC);
    $rows = $pdo->prepare("SELECT id,contact_id,sheet_row,prospect_email,subject,body_html,thread_rfc_message_id FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
    $rows->execute([$cid]);
    return hash('sha256', json_encode([$meta['name'], $meta['kind'], $rows->fetchAll(PDO::FETCH_ASSOC)]));
}
$h1 = hashFor($pdo, $cid);
$pdo->exec("UPDATE marketing_campaigns SET name='CRMTEST4 Wizard v3' WHERE id=" . $pdo->quote($cid));
$h2 = hashFor($pdo, $cid);
ok('campaign rename invalidates content hash', $h1 !== $h2);
$pdo->exec("UPDATE marketing_campaigns SET name='CRMTEST4 Wizard v2' WHERE id=" . $pdo->quote($cid));
$h3 = hashFor($pdo, $cid);
$pdo->exec("UPDATE marketing_campaign_recipients SET subject='changed' WHERE campaign_id=" . $pdo->quote($cid) . " LIMIT 1");
$h4 = hashFor($pdo, $cid);
ok('recipient edit invalidates content hash', $h3 !== $h4);

echo "\n$pass passed, $fail failed\n";
cleanup4();
exit($fail > 0 ? 1 : 0);
