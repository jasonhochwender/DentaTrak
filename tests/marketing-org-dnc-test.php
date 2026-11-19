<?php
/**
 * Organization-level Do Not Contact tests.
 *
 * Covers contact-level vs organization-level suppression, existing and
 * newly added/imported contacts, verified-domain rules (shared providers
 * are never suppressed), pending campaign recipients, the final send-time
 * gate, idempotency, audit trail, and deliberate removal.
 *
 * Runs against the LOCAL development database (port 3308). Synthetic
 * fixtures only (ORGDNCTEST names / orgdnctest emails), self-cleaning.
 * Gmail is faked - no mail can leave the test.
 *
 * Run: php tests/marketing-org-dnc-test.php
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
require_once $baseDir . '/api/marketing/CampaignSendSupport.php';
require_once $baseDir . '/api/marketing/CampaignSendRunner.php';
require_once $baseDir . '/api/marketing/MarketingMailer.php';
require_once $baseDir . '/api/marketing/MarketingSendingPolicy.php';

if (($appConfig['current_environment'] ?? '') !== 'development') {
    fwrite(STDERR, "REFUSING: not a development environment.\n");
    exit(1);
}
global $pdo;
require_once $baseDir . '/migrations/2026_11_29_marketing_bounce_holds.php';
require_once $baseDir . '/migrations/2026_11_30_marketing_org_dnc.php';
putenv('MARKETING_CRM_READS=true');

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
$cnt = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

function cleanupOrgDnc(): void {
    global $pdo;
    $m = "c.email_normalized LIKE '%orgdnctest%' OR c.full_name LIKE 'ORGDNCTEST%' OR o.name LIKE 'ORGDNCTEST%'";
    $pdo->exec("DELETE r FROM marketing_campaign_recipients r JOIN marketing_campaigns c ON c.id=r.campaign_id WHERE c.name LIKE 'ORGDNCTEST%'");
    $pdo->exec("DELETE FROM marketing_campaigns WHERE name LIKE 'ORGDNCTEST%'");
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id LEFT JOIN marketing_orgs o ON o.id=c.org_id WHERE $m");
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_orgs o ON o.id=e.org_id WHERE o.name LIKE 'ORGDNCTEST%'");
    $pdo->exec("UPDATE marketing_import_rows ir JOIN marketing_contacts c ON c.id=ir.contact_id LEFT JOIN marketing_orgs o ON o.id=c.org_id SET ir.contact_id=NULL, ir.org_id=NULL WHERE $m");
    $pdo->exec("UPDATE marketing_import_rows ir JOIN marketing_orgs o ON o.id=ir.org_id SET ir.org_id=NULL WHERE o.name LIKE 'ORGDNCTEST%'");
    $pdo->exec("DELETE c FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id=c.org_id WHERE $m");
    $pdo->exec("DELETE FROM marketing_org_suppressions WHERE org_ref LIKE '%orgdnctest%'");
    $pdo->exec("DELETE FROM marketing_orgs WHERE name LIKE 'ORGDNCTEST%'");
    $pdo->exec("DELETE q FROM marketing_sync_queue q JOIN marketing_suppressions s ON s.id=q.suppression_id WHERE s.email_normalized LIKE '%orgdnctest%'");
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%orgdnctest%'");
    $pdo->exec("DELETE FROM marketing_sends WHERE email_normalized LIKE '%orgdnctest%'");
}
cleanupOrgDnc();

try {
$admin = new ProspectCrmAdmin($pdo, 'orgdnctest-admin@example.com');
$svc = fn() => new SuppressionService($pdo);
$crm = fn() => new ProspectCrm($pdo);
$orgRow = fn(int $id) => $pdo->query("SELECT * FROM marketing_orgs WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
$contact = fn(int $id) => $pdo->query("SELECT * FROM marketing_contacts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);

$orgA = $admin->createOrg(['name' => 'ORGDNCTEST Alpha Dental', 'org_type' => 'dental practice',
    'domain' => 'orgdnctest-alpha.example', 'website' => 'https://www.orgdnctest-alpha.example/contact']);
$orgB = $admin->createOrg(['name' => 'ORGDNCTEST Beta Dental', 'org_type' => 'dental practice', 'domain' => 'orgdnctest-beta.example']);
$a1 = $admin->createContact(['full_name' => 'ORGDNCTEST Office', 'email' => 'office@orgdnctest-alpha.example', 'org_id' => $orgA['id'], 'status' => 'Not Contacted']);
$a2 = $admin->createContact(['full_name' => 'ORGDNCTEST Staff Gmail', 'email' => 'orgdnctest.staff@gmail.com', 'org_id' => $orgA['id'], 'status' => 'Not Contacted']);
$b1 = $admin->createContact(['full_name' => 'ORGDNCTEST Beta Person', 'email' => 'person@orgdnctest-beta.example', 'org_id' => $orgB['id'], 'status' => 'Not Contacted']);
$A = (int)$orgA['id']; $B = (int)$orgB['id'];

/* ---------- 1. contact-level only ---------- */
$svc()->suppress('office@orgdnctest-alpha.example', 'email_unsubscribe');
$p1 = $crm()->findByEmail('office@orgdnctest-alpha.example');
$p2 = $crm()->findByEmail('orgdnctest.staff@gmail.com');
ok('contact-level: suppressed contact scope is contact', ($p1['dnc_scope'] ?? '') === 'contact' && !in_array('org_do_not_contact', $p1['flags'], true), json_encode([$p1['dnc_scope'] ?? null, $p1['flags']]));
ok('contact-level: colleague is unaffected', ($p2['dnc_scope'] ?? '') === '' && !array_intersect(['suppressed', 'org_suppressed', 'do_not_contact'], $p2['eligibility']['initial']['flags'])
    && !empty($svc()->eligibility(['orgdnctest.staff@gmail.com'])[0]['eligible']), json_encode($p2['eligibility']['initial'] ?? null));
ok('contact-level: organization not marked', (int)$orgRow($A)['dnc'] === 0 && !$svc()->isOrgDnc($A));

/* ---------- 2. plan / verified domains ---------- */
$plan = $svc()->orgDncPlan($A);
ok('plan: verified domain from org record (www stripped, deduped)', $plan['domains'] === ['orgdnctest-alpha.example'], json_encode($plan['domains']));
$sk = array_column($plan['skipped_domains'], 'reason', 'domain');
ok('plan: gmail.com reported as shared, not suppressed', ($sk['gmail.com'] ?? '') === 'shared_provider', json_encode($sk));
ok('plan: contact count', $plan['contacts'] === 2);
ok('plan is read-only', (int)$orgRow($A)['dnc'] === 0 && $cnt("SELECT COUNT(*) FROM marketing_org_suppressions WHERE org_ref LIKE '%orgdnctest%'") === 0);
$threw = false; try { $svc()->suppressOrg('gmail.com', 'x'); } catch (RuntimeException $e) { $threw = true; }
ok('shared provider can never be domain-suppressed', $threw && !$svc()->isOrgSuppressed('gmail.com'));
ok('isSharedDomain', SuppressionService::isSharedDomain('Outlook.com') && !SuppressionService::isSharedDomain('culpdental.com'));
ok('cleanDomain', SuppressionService::cleanDomain('https://www.Example.com/x?y') === 'example.com' && SuppressionService::cleanDomain('not a domain') === '');

/* ---------- 3. pending recipient before marking ---------- */
$campId = 'odn-campaign-0001-0000-000000000001';
$pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status,created_by,recipient_count) VALUES (?, 'ORGDNCTEST Campaign','initial','approved','orgdnctest',2)")->execute([$campId]);
foreach ([['orgdnctest.staff@gmail.com', $a2['id']], ['person@orgdnctest-beta.example', $b1['id']]] as [$em, $cidX]) {
    $pdo->prepare("INSERT INTO marketing_campaign_recipients (campaign_id, prospect_email, contact_id, status, subject, body_html) VALUES (?,?,?,'approved','Hi','<p>x</p>')")->execute([$campId, $em, $cidX]);
}
ok('plan counts pending recipient', $svc()->orgDncPlan($A)['pending_recipients'] === 1);

/* ---------- 4. mark organization Do Not Contact ---------- */
$before = ['a1' => $contact((int)$a1['id']), 'a2' => $contact((int)$a2['id'])];
$r = $svc()->suppressOrganization($A, 'orgdnctest-admin@example.com', 'Requested removal from our contact list');
$o = $orgRow($A);
ok('org marked with reason, date, administrator', (int)$o['dnc'] === 1 && $o['dnc_reason'] === 'Requested removal from our contact list'
    && $o['dnc_by'] === 'orgdnctest-admin@example.com' && !empty($o['dnc_at']));
$dom = $pdo->query("SELECT * FROM marketing_org_suppressions WHERE org_ref='orgdnctest-alpha.example'")->fetch(PDO::FETCH_ASSOC);
ok('verified domain suppressed via existing domain store, linked to org', $dom && (int)$dom['org_id'] === $A
    && $dom['created_by'] === 'orgdnctest-admin@example.com' && $r['new_domains'] === ['orgdnctest-alpha.example']);
ok('gmail.com NOT suppressed', $cnt("SELECT COUNT(*) FROM marketing_org_suppressions WHERE org_ref='gmail.com'") === 0
    && !empty($svc()->eligibility(['someone.else.orgdnctest@gmail.com'])[0]['eligible']));
ok('contacts, status and contact-level dnc untouched', $contact((int)$a1['id']) == $before['a1'] && $contact((int)$a2['id']) == $before['a2']);
ok('audit event recorded', $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE org_id=$A AND event_type='org_dnc_set'") === 1);
$r2 = $svc()->suppressOrganization($A, 'someone-else@example.com', 'again');
ok('idempotent: no duplicate rows/events, original record kept', !empty($r2['already_dnc']) && $r2['new_domains'] === []
    && $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE org_id=$A AND event_type='org_dnc_set'") === 1
    && $orgRow($A)['dnc_by'] === 'orgdnctest-admin@example.com'
    && $cnt("SELECT COUNT(*) FROM marketing_org_suppressions WHERE org_ref='orgdnctest-alpha.example'") === 1);

/* ---------- 5. scope visibility + eligibility ---------- */
$p1 = $crm()->findByEmail('office@orgdnctest-alpha.example');
$p2 = $crm()->findByEmail('orgdnctest.staff@gmail.com');
$pb = $crm()->findByEmail('person@orgdnctest-beta.example');
ok('scope: contact-suppressed + org = both', ($p1['dnc_scope'] ?? '') === 'both');
ok('scope: gmail colleague = organization', ($p2['dnc_scope'] ?? '') === 'organization' && in_array('org_do_not_contact', $p2['flags'], true)
    && in_array('do_not_contact', $p2['flags'], true));
ok('gmail colleague ineligible for initial and follow-up', empty($p2['eligibility']['initial']['ok']) && empty($p2['eligibility']['followup']['ok'])
    && in_array('org_suppressed', $p2['eligibility']['initial']['flags'], true), json_encode($p2['eligibility']['initial']));
ok('other organization unaffected', ($pb['dnc_scope'] ?? '') === '' && !empty($pb['eligibility']['initial']['ok']));
$info = $svc()->orgBlockInfo('orgdnctest.staff@gmail.com');
ok('block info: via organization with reason', $info && $info['via'] === 'organization' && $info['org_id'] === $A && $info['reason'] === 'Requested removal from our contact list');
$info2 = $svc()->orgBlockInfo('anyone@orgdnctest-alpha.example');
ok('block info: via domain', $info2 && $info2['via'] === 'domain' && $info2['org_id'] === $A);

/* ---------- 6. future contacts + unknown addresses on the domain ---------- */
$ctx = mo_elig_ctx($pdo, $svc(), 'newhire@orgdnctest-alpha.example');
ok('unknown address on org domain blocked at send gate', !empty($ctx['org_suppressed']) && empty($svc()->eligibility(['newhire@orgdnctest-alpha.example'])[0]['eligible']));
$a3 = $admin->createContact(['full_name' => 'ORGDNCTEST Later Hire', 'email' => 'orgdnctest.later@outlook.com', 'org_id' => $A, 'status' => 'Not Contacted']);
$p3 = $crm()->findByEmail('orgdnctest.later@outlook.com');
ok('contact added later to the org is blocked', ($p3['dnc_scope'] ?? '') === 'organization' && empty($p3['eligibility']['initial']['ok'])
    && empty($svc()->eligibility(['orgdnctest.later@outlook.com'])[0]['eligible']));
ok('...without changing its own contact status', (int)$contact((int)$a3['id'])['dnc'] === 0);
$prev = $admin->previewImport("org,email,contact_name,status\nORGDNCTEST Alpha Dental,imported@orgdnctest-alpha.example,ORGDNCTEST Imported,Not Contacted\n");
$admin->commitImport($prev);
$pi = $crm()->findByEmail('imported@orgdnctest-alpha.example');
ok('imported contact for the org is ineligible', $pi !== null && empty($pi['eligibility']['initial']['ok']) && in_array('org_do_not_contact', $pi['flags'], true), json_encode($pi['flags'] ?? null));
$add = $admin->addToCampaign([(int)$a2['id'], (int)$b1['id']], null, 'ORGDNCTEST Add', 'initial', 50, true);
$addRows = []; foreach (($add['results'] ?? $add['rows'] ?? []) as $x) $addRows[strtolower((string)($x['email'] ?? ''))] = $x;
ok('campaign add excludes org member', isset($addRows['orgdnctest.staff@gmail.com'])
    && ($addRows['orgdnctest.staff@gmail.com']['status'] ?? '') === 'suppressed', json_encode($add));

/* ---------- 7. normal edits and imports never lift it ---------- */
$admin->updateOrg($A, ['name' => 'ORGDNCTEST Alpha Dental', 'status' => 'active', 'domain' => 'orgdnctest-alpha2.example']);
ok('org edit keeps Do Not Contact + domain suppression', (int)$orgRow($A)['dnc'] === 1 && $svc()->isOrgSuppressed('orgdnctest-alpha.example'));
$cv = $contact((int)$a2['id']);
$admin->updateContact((int)$a2['id'], ['status' => 'Contacted'], (int)$cv['version']);
ok('contact edit does not lift org block', empty($crm()->findByEmail('orgdnctest.staff@gmail.com')['eligibility']['followup']['ok']));

/* ---------- 8. final send-time gate ---------- */
$sendCalls = 0;
$httpFake = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$sendCalls) {
    if (strpos($url, 'oauth2.googleapis.com/token') !== false) return ['status' => 200, 'body' => '{"access_token":"fake-at"}'];
    if (strpos($url, '/messages/send') !== false) { $sendCalls++; return ['status' => 200, 'body' => '{"id":"gm-odn","threadId":"gt-odn"}']; }
    if (strpos($url, '/messages?') !== false) return ['status' => 200, 'body' => '{"messages":[]}'];
    return ['status' => 404, 'body' => '{}'];
};
$envFn = static fn(string $k) => ['MARKETING_SENDING' => 'true', 'MARKETING_SEND_ALLOWLIST' => '*',
    'GOOGLE_CLIENT_ID' => 'cid', 'GOOGLE_CLIENT_SECRET' => 'sec', 'GMAIL_REFRESH_TOKEN' => 'rtok'][$k] ?? null;
$mailer = new MarketingMailer($pdo, 'https://dentatrak.test', ['policy' => new MarketingSendingPolicy($envFn), 'http' => $httpFake, 'env' => $envFn, 'clock' => fn() => time()]);
$runner = new CampaignSendRunner($pdo, 'https://dentatrak.test', $mailer);
$pdo->exec("DELETE FROM marketing_campaign_recipients WHERE campaign_id='$campId' AND prospect_email='person@orgdnctest-beta.example'");
$camp = $pdo->query("SELECT * FROM marketing_campaigns WHERE id='$campId'")->fetch(PDO::FETCH_ASSOC);
$res = $runner->runBatch($camp, $runner->nextBatch($campId, 10, null, true));
$rr = $pdo->query("SELECT status, skip_reason FROM marketing_campaign_recipients WHERE campaign_id='$campId' AND prospect_email='orgdnctest.staff@gmail.com'")->fetch(PDO::FETCH_ASSOC);
ok('pending recipient stopped at send time, row preserved', $rr == ['status' => 'suppressed', 'skip_reason' => 'org_do_not_contact'], json_encode([$rr, $res]));
ok('nothing sent', $sendCalls === 0 && (int)($res['sent'] ?? 0) === 0);
$direct = $mailer->send(['to' => 'orgdnctest.staff@gmail.com', 'subject' => 'Hi', 'html' => '<p>x</p>', 'campaign' => 'orgdnctest-direct']);
ok('mailer refuses org member directly', ($direct['status'] ?? '') === 'skipped_suppressed' && $sendCalls === 0, json_encode($direct));

/* ---------- 9. domain shared with an unrelated organization ---------- */
$orgC = $admin->createOrg(['name' => 'ORGDNCTEST Gamma Group', 'org_type' => 'dso', 'domain' => 'orgdnctest-beta.example']);
$C = (int)$orgC['id'];
$admin->createContact(['full_name' => 'ORGDNCTEST Gamma Own', 'email' => 'orgdnctest.gamma@yahoo.com', 'org_id' => $C, 'status' => 'Not Contacted']);
$planC = $svc()->orgDncPlan($C);
$skC = array_column($planC['skipped_domains'], 'reason', 'domain');
ok('domain used by another organization is not suppressed', $planC['domains'] === [] && ($skC['orgdnctest-beta.example'] ?? '') === 'used_by_other_organization', json_encode($planC));
$svc()->suppressOrganization($C, 'orgdnctest-admin@example.com', 'test');
ok('org C contacts blocked; org B (same domain) untouched', empty($svc()->eligibility(['orgdnctest.gamma@yahoo.com'])[0]['eligible'])
    && !empty($svc()->eligibility(['person@orgdnctest-beta.example'])[0]['eligible']) && !$svc()->isOrgSuppressed('orgdnctest-beta.example'));

/* ---------- 10. deliberate removal ---------- */
// A domain suppression that predates the org action must survive removal.
$orgD = $admin->createOrg(['name' => 'ORGDNCTEST Delta Dental', 'org_type' => 'dental practice', 'domain' => 'orgdnctest-delta.example']);
$D = (int)$orgD['id'];
$svc()->suppressOrg('orgdnctest-delta.example', 'email_unsubscribe', 'earlier unsubscribe');
$svc()->suppressOrganization($D, 'orgdnctest-admin@example.com', 'test');
$err = fn(callable $f) => (function () use ($f) { try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } })();
ok('removal refused without matching name', $err(fn() => $svc()->unsuppressOrganization($A, 'admin@example.com', 'wrong name', 'reason')) !== '' && (int)$orgRow($A)['dnc'] === 1);
ok('removal refused without reason', $err(fn() => $svc()->unsuppressOrganization($A, 'admin@example.com', 'ORGDNCTEST Alpha Dental', ' ')) !== '' && (int)$orgRow($A)['dnc'] === 1);
$rm = $svc()->unsuppressOrganization($A, 'orgdnctest-admin@example.com', 'orgdnctest alpha dental', 'Customer asked to be contacted again');
ok('removal with name + reason lifts org flag and its domain', !empty($rm['was_dnc']) && (int)$orgRow($A)['dnc'] === 0
    && !$svc()->isOrgSuppressed('orgdnctest-alpha.example') && $rm['domains_removed'] === ['orgdnctest-alpha.example']);
ok('removal audited', $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE org_id=$A AND event_type='org_dnc_removed'") === 1);
ok('contact-level suppression survives removal', $svc()->isSuppressed('office@orgdnctest-alpha.example')
    && ($crm()->findByEmail('office@orgdnctest-alpha.example')['dnc_scope'] ?? '') === 'contact');
ok('colleague no longer org-blocked after removal', !$svc()->isOrgBlocked('orgdnctest.later@outlook.com'));
$svc()->unsuppressOrganization($D, 'orgdnctest-admin@example.com', 'ORGDNCTEST Delta Dental', 'test');
ok('pre-existing domain suppression is NOT lifted by org removal', $svc()->isOrgSuppressed('orgdnctest-delta.example') && (int)$orgRow($D)['dnc'] === 0);
$again = $svc()->unsuppressOrganization($A, 'orgdnctest-admin@example.com', 'ORGDNCTEST Alpha Dental', 'again');
ok('removal idempotent', empty($again['was_dnc']) && $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE org_id=$A AND event_type='org_dnc_removed'") === 1);

} finally {
    putenv('MARKETING_CRM_READS');
    cleanupOrgDnc();
}
echo "\nmarketing-org-dnc-test: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
