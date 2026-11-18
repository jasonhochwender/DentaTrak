<?php
/**
 * Practice registration -> Marketing CRM synchronization tests.
 *
 * Covers: new practice sync, existing-prospect match, multi-contact orgs,
 * different-email registrations, similar-name ambiguity, campaign
 * exclusion, re-import safety, idempotent reruns, and send-time gating.
 *
 * Runs against the LOCAL development database (port 3308 via .env_mode).
 * Synthetic fixtures only (@regtest.* / REGTEST%) and self-cleaning.
 *
 * Run: php tests/marketing-registration-sync-test.php
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
require_once $baseDir . '/api/marketing/PracticeRegistrationSync.php';
require_once $baseDir . '/api/marketing/CampaignSendSupport.php';
require_once $baseDir . '/api/marketing/CampaignSendRunner.php';
require_once $baseDir . '/api/marketing/MarketingMailer.php';
require_once $baseDir . '/api/marketing/MarketingSendingPolicy.php';

if (($appConfig['current_environment'] ?? '') !== 'development') {
    fwrite(STDERR, "REFUSING: not a development environment.\n");
    exit(1);
}
$port = (int)($appConfig['db_port'] ?? 0);
if ($port !== 3308) { fwrite(STDERR, "REFUSING: expected dev DB port 3308, got $port\n"); exit(1); }
global $pdo;
putenv('MARKETING_CRM_READS=true');

require_once $baseDir . '/migrations/2026_11_13_marketing_crm.php';
require_once $baseDir . '/migrations/2026_11_14_marketing_crm_phase2.php';
require_once $baseDir . '/migrations/2026_11_15_marketing_crm_phase3.php';
require_once $baseDir . '/migrations/2026_11_27_marketing_practice_registration.php';
require_once $baseDir . '/migrations/2026_11_28_marketing_user_registration.php';

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}

/* ---------------- cleanup ---------------- */
$practiceIds = []; $userIds = [];
function cleanupReg(PDO $pdo, array $practiceIds, array $userIds): void {
    foreach ($practiceIds as $pid) {
        $pdo->prepare('DELETE FROM marketing_prospect_events WHERE org_id IN (SELECT id FROM marketing_orgs WHERE practice_id=?) OR contact_id IN (SELECT id FROM marketing_contacts WHERE org_id IN (SELECT id FROM marketing_orgs WHERE practice_id=?))')
            ->execute([$pid, $pid]);
    }
    // Contacts/orgs created by the sync carry source='practice_registration'
    // or REGTEST names/emails.
    $pdo->exec("DELETE e FROM marketing_prospect_events e
                JOIN marketing_contacts c ON c.id=e.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id
                WHERE c.source='practice_registration' OR c.email_normalized LIKE '%@regtest%'
                   OR o.name LIKE 'REGTEST%' OR o.source='practice_registration'");
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_orgs o ON o.id=e.org_id
                WHERE o.source='practice_registration' OR o.name LIKE 'REGTEST%'");
    $pdo->exec("UPDATE marketing_import_rows ir JOIN marketing_contacts c ON c.id=ir.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id
                SET ir.contact_id=NULL, ir.org_id=NULL
                WHERE c.email_normalized LIKE '%@regtest%' OR o.name LIKE 'REGTEST%' OR o.source='practice_registration'");
    $pdo->exec("UPDATE marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id
                SET e.contact_id=NULL, e.org_id=NULL
                WHERE c.email_normalized LIKE '%@regtest%' OR o.name LIKE 'REGTEST%' OR o.source='practice_registration'");
    $pdo->exec("UPDATE marketing_campaign_recipients r JOIN marketing_contacts c ON c.id=r.contact_id
                LEFT JOIN marketing_orgs o ON o.id=c.org_id SET r.contact_id=NULL
                WHERE c.email_normalized LIKE '%@regtest%' OR o.name LIKE 'REGTEST%' OR o.source='practice_registration'");
    $pdo->exec("DELETE c FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id=c.org_id
                WHERE c.email_normalized LIKE '%@regtest%' OR c.full_name LIKE 'REGTEST%' OR o.name LIKE 'REGTEST%' OR o.source='practice_registration'");
    $pdo->exec("UPDATE marketing_orgs SET parent_org_id=NULL WHERE name LIKE 'REGTEST%' OR source='practice_registration'");
    $pdo->exec("DELETE FROM marketing_orgs WHERE name LIKE 'REGTEST%' OR source='practice_registration'");
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%@regtest%'");
    $pdo->exec("DELETE FROM marketing_org_suppressions WHERE org_ref LIKE 'regtest%'");
    foreach ($practiceIds as $pid) {
        $pdo->prepare('DELETE FROM practice_users WHERE practice_id=?')->execute([$pid]);
        $pdo->prepare('DELETE FROM practices WHERE id=?')->execute([$pid]);
    }
    foreach ($userIds as $uid) {
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
    }
}
cleanupReg($pdo, [], []);

$sync = new PracticeRegistrationSync($pdo, 'regtest');
$admin = new ProspectCrmAdmin($pdo, 'regtest-admin@example.com');
$crm = new ProspectCrm($pdo);

/* ---------------- fixture helpers ---------------- */
function mkUser(PDO $pdo, string $email, string $first, string $last, array &$userIds): int {
    $pdo->prepare("INSERT INTO users (email, first_name, last_name, password_hash, auth_method, email_verified, created_at)
                   VALUES (?,?,?, 'x', 'email', 1, UTC_TIMESTAMP())")
        ->execute([$email, $first, $last]);
    $id = (int)$pdo->lastInsertId();
    $userIds[] = $id;
    return $id;
}
function mkPractice(PDO $pdo, string $name, string $addr, int $ownerUid, ?string $orgType, array &$practiceIds, array &$userIds): int {
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, legal_name, display_name, practice_address,
                    organization_type, baa_accepted, created_by, created_at)
                   VALUES (?,?,?,?,?,?,1,?, UTC_TIMESTAMP())")
        ->execute([bin2hex(random_bytes(8)), $name, $name, $name, $addr, $orgType, $ownerUid]);
    $pid = (int)$pdo->lastInsertId();
    $practiceIds[] = $pid;
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (?,?,'admin',1)")
        ->execute([$pid, $ownerUid]);
    return $pid;
}

try {
    /* ============ 1+2: brand-new practice, no CRM presence ============ */
    $u1 = mkUser($pdo, 'owner1@regtest-dental.example', 'Alice', 'Owner', $userIds);
    $p1 = mkPractice($pdo, 'REGTEST Fresh Dental', '100 Main St, Springfield, IL', $u1, 'dental_practice', $practiceIds, $userIds);
    $r1 = $sync->syncPractice($p1);
    ok('new practice creates org', $r1['action'] === 'created' && $r1['org_id'] > 0, json_encode($r1));
    $org1 = $pdo->query("SELECT * FROM marketing_orgs WHERE id=" . (int)$r1['org_id'])->fetch(PDO::FETCH_ASSOC);
    ok('org linked to practice_id', (int)$org1['practice_id'] === $p1);
    ok('org registered_at set', !empty($org1['registered_at']));
    ok('org not flagged for review', (int)$org1['needs_review'] === 0);
    $c1 = $pdo->query("SELECT * FROM marketing_contacts WHERE org_id=" . (int)$r1['org_id'])->fetch(PDO::FETCH_ASSOC);
    ok('owner contact created as Signed Up', $c1 && $c1['email_normalized'] === 'owner1@regtest-dental.example'
        && strtolower($c1['status']) === 'signed up' && $c1['source'] === 'practice_registration');
    ok('registration event recorded', $cnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM marketing_prospect_events WHERE org_id=" . (int)$r1['org_id']
        . " AND event_type='practice_registered'")->fetchColumn() >= 1);

    /* idempotent rerun */
    $r1b = $sync->syncPractice($p1);
    $orgsAfter = (int)$pdo->query("SELECT COUNT(*) FROM marketing_orgs WHERE practice_id=$p1")->fetchColumn();
    $contactsAfter = (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE org_id=" . (int)$r1['org_id'])->fetchColumn();
    ok('rerun is a no-op (no dup org/contact)', $orgsAfter === 1 && $contactsAfter === 1
        && in_array($r1b['action'], ['linked', 'updated', 'none'], true), json_encode($r1b));

    /* ============ 3: existing prospect org, multiple contacts ============ */
    // Pre-existing org + two contacts; owner email matches contact B.
    $org3 = $admin->createOrg(['name' => 'REGTEST Multi Dental', 'location' => 'Tulsa, OK']);
    $oid3 = (int)$org3['id'];
    $admin->createContact(['org_id' => $oid3, 'full_name' => 'REGTEST Person A', 'email' => 'persona@regtest-multi.example', 'status' => 'Not Contacted']);
    $admin->createContact(['org_id' => $oid3, 'full_name' => 'REGTEST Person B', 'email' => 'owner3@regtest-multi.example', 'status' => 'Sent']);
    $u3 = mkUser($pdo, 'owner3@regtest-multi.example', 'Bob', 'Owner', $userIds);
    $p3 = mkPractice($pdo, 'REGTEST Multi Dental', 'Tulsa, OK', $u3, 'dental_practice', $practiceIds, $userIds);
    $r3 = $sync->syncPractice($p3);
    $orgRow3 = $pdo->query("SELECT * FROM marketing_orgs WHERE id=$oid3")->fetch(PDO::FETCH_ASSOC);
    ok('existing org matched via owner email', $r3['org_id'] === $oid3 && (int)$orgRow3['practice_id'] === $p3, json_encode($r3));
    $statuses3 = $pdo->query("SELECT status FROM marketing_contacts WHERE org_id=$oid3 ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN);
    ok('all org contacts marked Signed Up', count(array_filter($statuses3, fn($s) => strtolower($s) === 'signed up')) === 2, json_encode($statuses3));
    $notesKept = (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE org_id=$oid3")->fetchColumn();
    ok('no contacts created when org already has owner', $notesKept === 2);

    /* ============ 4: registered practice, different email ============ */
    // Org exists by NAME+location; registering admin uses a new email.
    $org4 = $admin->createOrg(['name' => 'REGTEST Lakeview Dental', 'location' => 'Magee, MS']);
    $oid4 = (int)$org4['id'];
    $admin->createContact(['org_id' => $oid4, 'full_name' => 'REGTEST Old Contact', 'email' => 'oldcontact@regtest-lake.example', 'status' => 'Not Contacted']);
    $u4 = mkUser($pdo, 'newadmin@regtest-lake.example', 'Cara', 'New', $userIds);
    $p4 = mkPractice($pdo, 'REGTEST Lakeview Dental', 'Magee, Mississippi', $u4, 'dental_practice', $practiceIds, $userIds);
    $r4 = $sync->syncPractice($p4);
    $orgRow4 = $pdo->query("SELECT * FROM marketing_orgs WHERE id=$oid4")->fetch(PDO::FETCH_ASSOC);
    ok('name+location match links org despite different email', $r4['org_id'] === $oid4 && (int)$orgRow4['practice_id'] === $p4, json_encode($r4));
    $newContact4 = $pdo->query("SELECT * FROM marketing_contacts WHERE org_id=$oid4 AND email_normalized='newadmin@regtest-lake.example'")->fetch(PDO::FETCH_ASSOC);
    ok('new admin contact added to matched org', $newContact4 && strtolower($newContact4['status']) === 'signed up');

    /* ============ 5: similar names, different practices ============ */
    // 'REGTEST Summit Dental' exists in CRM for Tulsa; a DIFFERENT practice
    // 'REGTEST Summit Dental' registers in Boise with a different owner
    // email -> name matches but location conflicts -> flagged new org.
    $org5 = $admin->createOrg(['name' => 'REGTEST Summit Dental', 'location' => 'Tulsa, OK']);
    $oid5 = (int)$org5['id'];
    $admin->createContact(['org_id' => $oid5, 'full_name' => 'REGTEST Summit Person', 'email' => 'summit@regtest-summit.example', 'status' => 'Not Contacted']);
    $u5 = mkUser($pdo, 'boiseowner@regtest-boise.example', 'Dan', 'Boise', $userIds);
    $p5 = mkPractice($pdo, 'REGTEST Summit Dental', 'Boise, Idaho', $u5, 'dental_practice', $practiceIds, $userIds);
    $r5 = $sync->syncPractice($p5);
    $orgRow5old = $pdo->query("SELECT * FROM marketing_orgs WHERE id=$oid5")->fetch(PDO::FETCH_ASSOC);
    ok('conflicting location does NOT merge orgs', $orgRow5old['practice_id'] === null, json_encode($orgRow5old));
    $newOrg5 = $pdo->query("SELECT * FROM marketing_orgs WHERE practice_id=$p5")->fetch(PDO::FETCH_ASSOC);
    ok('ambiguous match creates flagged new org', $newOrg5 && (int)$newOrg5['needs_review'] === 1
        && $r5['review'] === true, json_encode($r5));

    /* ============ 6: registered practice inside a pending campaign ============ */
    $stC = $pdo->prepare('SELECT id FROM marketing_contacts WHERE org_id=' . (int)$r1['org_id'] . ' LIMIT 1');
    $stC->execute(); $cid1 = (int)$stC->fetchColumn();
    $camp = $admin->createCampaign(['name' => 'REGTEST Intro', 'kind' => 'initial'], 50);
    $campId = $camp['campaign_id'];
    $add = $admin->addToCampaign([$cid1], $campId, null, 'initial', 50);
    $res1 = $add['results'][0] ?? [];
    ok('registered contact excluded from initial campaign', ($res1['status'] ?? '') === 'suppressed'
        && str_contains((string)($res1['reason'] ?? ''), 'already_registered'), json_encode($res1));

    // A NON-registered prospect in the same campaign still adds fine.
    $uX = mkUser($pdo, 'plain@regtest-plain.example', 'Pat', 'Plain', $userIds);
    $orgX = $admin->createOrg(['name' => 'REGTEST Plain Dental']);
    $cX = $admin->createContact(['org_id' => (int)$orgX['id'], 'full_name' => 'REGTEST Plain Person',
        'email' => 'plainperson@regtest-plain.example', 'status' => 'Not Contacted']);
    $add2 = $admin->addToCampaign([(int)$cX['id']], $campId, null, 'initial', 50);
    $resX = $add2['results'][0] ?? [];
    ok('unregistered prospect still eligible', ($resX['status'] ?? 'draft') !== 'suppressed'
        || !str_contains((string)($resX['reason'] ?? ''), 'already_registered'), json_encode($resX));

    // Send-time gate: force the registered recipient into 'approved' and
    // run the batch -> must skip with already_registered, row preserved.
    $pdo->exec("UPDATE marketing_campaign_recipients SET status='approved', subject='Hi', body_html='<p>x</p>'
                WHERE campaign_id=" . $pdo->quote($campId) . " AND contact_id=$cid1");
    $campRow = $pdo->query("SELECT * FROM marketing_campaigns WHERE id=" . $pdo->quote($campId))->fetch(PDO::FETCH_ASSOC);
    // Fake Gmail transport + sending policy (same seams as the
    // large-campaign suite): no real mail can leave the test.
    $httpFake = function (string $method, string $url, array $headers, ?string $body, int $timeout) {
        if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
            return ['status' => 200, 'body' => '{"access_token":"fake-at"}'];
        }
        if (strpos($url, '/messages/send') !== false) {
            return ['status' => 200, 'body' => '{"id":"gm-reg","threadId":"gt-reg"}'];
        }
        if (strpos($url, '/messages?') !== false) {
            return ['status' => 200, 'body' => '{"messages":[]}'];
        }
        return ['status' => 404, 'body' => '{}'];
    };
    $envFn = static fn(string $k) => ['MARKETING_SENDING' => 'true',
        'MARKETING_SEND_ALLOWLIST' => '*',
        'GOOGLE_CLIENT_ID' => 'cid', 'GOOGLE_CLIENT_SECRET' => 'sec',
        'GMAIL_REFRESH_TOKEN' => 'rtok'][$k] ?? null;
    $mailer = new MarketingMailer($pdo, 'https://dentatrak.test', [
        'policy' => new MarketingSendingPolicy($envFn), 'http' => $httpFake,
        'env' => $envFn, 'clock' => fn() => time()]);
    $runner = new CampaignSendRunner($pdo, 'https://dentatrak.test', $mailer);
    $batch = $runner->nextBatch($campId, 10, null, true);
    $rres = $runner->runBatch($campRow, $batch);
    $recipRow = $pdo->query("SELECT status, skip_reason FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($campId) . " AND contact_id=$cid1")->fetch(PDO::FETCH_ASSOC);
    ok('send-time gate skips registered recipient', ($recipRow['status'] ?? '') === 'skipped'
        && ($recipRow['skip_reason'] ?? '') === 'already_registered', json_encode([$recipRow, $rres['results'] ?? null]));

    /* ============ 7: re-import of registered practice ============ */
    // CSV import of the same org name + a new email -> links to registered org.
    $prevRows = $admin->previewImport("org,email,contact_name,status\nREGTEST Lakeview Dental,newperson@regtest-lake.example,REGTEST New Person,Not Contacted\n");
    $prow = $prevRows[0] ?? null;
    ok('import preview marks row new', $prow && ($prow['disposition'] ?? '') === 'new', json_encode($prow));
    $imp = $admin->commitImport($prevRows);
    $imported = $pdo->query("SELECT c.org_id, o.practice_id FROM marketing_contacts c
        LEFT JOIN marketing_orgs o ON o.id=c.org_id
        WHERE c.email_normalized='newperson@regtest-lake.example'")->fetch(PDO::FETCH_ASSOC);
    ok('imported contact lands on registered org', $imported && (int)$imported['practice_id'] === $p4,
        json_encode([$imp, $imported]));
    $eligImp = $crm->findByEmail('newperson@regtest-lake.example');
    ok('imported contact ineligible for initial', empty($eligImp['eligibility']['initial']['ok'])
        && in_array('already_registered', $eligImp['eligibility']['initial']['flags'] ?? [], true),
        json_encode($eligImp['eligibility']['initial'] ?? null));

    /* ============ 8: reconciliation rerun safety ============ */
    $before = (int)$pdo->query("SELECT COUNT(*) FROM marketing_orgs WHERE practice_id IN ($p1,$p3,$p4,$p5)")->fetchColumn();
    foreach ([$p1, $p3, $p4, $p5] as $pid) $sync->syncPractice($pid);
    $after = (int)$pdo->query("SELECT COUNT(*) FROM marketing_orgs WHERE practice_id IN ($p1,$p3,$p4,$p5)")->fetchColumn();
    ok('reconciliation rerun creates no duplicate orgs', $before === $after, "before=$before after=$after");

    /* ============ 9: registered practice stays eligible for follow-ups ============ */
    $fu = $admin->addToCampaign([$cid1], null, 'REGTEST Followup', 'followup', 50);
    // followup requires prior outreach; may be excluded for no_prior_outreach
    // but must NOT be excluded for already_registered.
    $fuRes = null;
    foreach (($fu['results'] ?? []) as $x) if ((int)($x['id'] ?? 0) === $cid1) $fuRes = $x;
    ok('registered contact not blocked from follow-up by registration',
        $fuRes === null || !str_contains((string)($fuRes['reason'] ?? ''), 'already_registered'),
        json_encode($fuRes));

    /* registeredEmails helper */
    $map = PracticeRegistrationSync::registeredEmails($pdo, ['owner1@regtest-dental.example', 'plainperson@regtest-plain.example']);
    ok('registeredEmails detects linked + Signed Up', isset($map['owner1@regtest-dental.example'])
        && !isset($map['plainperson@regtest-plain.example']), json_encode($map));

    /* bucket = registered */
    $b1 = $crm->findByEmail('owner1@regtest-dental.example');
    ok('registered contact bucket is registered', ($b1['bucket'] ?? '') === 'registered', json_encode($b1['bucket'] ?? null));
    ok('record exposes registered flag', !empty($b1['registered']));

    /* ============ 8: user registers, no practice yet ============ */
    $uS = mkUser($pdo, 'sydnee@regtest-user.example', 'Sydnee', 'Brewer', $userIds);
    $rS = $sync->syncUser($uS);
    ok('user without practice creates contact', $rS['action'] === 'created' && $rS['contact_id'] > 0, json_encode($rS));
    $cS = $pdo->query("SELECT * FROM marketing_contacts WHERE id=" . (int)$rS['contact_id'])->fetch(PDO::FETCH_ASSOC);
    ok('contact has no org (no placeholder)', $cS && $cS['org_id'] === null);
    ok('contact linked to user_id', $cS && (int)$cS['user_id'] === $uS);
    ok('status is Registered - Setup Incomplete', $cS
        && strtolower($cS['status']) === 'registered - setup incomplete', $cS['status'] ?? '');
    ok('registration date recorded', $cS && !empty($cS['user_registered_at']));
    ok('source is user_registration', $cS && $cS['source'] === 'user_registration');
    ok('syncUser rerun is a no-op', ($rS2 = $sync->syncUser($uS))['action'] === 'none'
        && (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE user_id=$uS")->fetchColumn() === 1,
        json_encode($rS2));

    /* ============ 9: same user later creates a practice ============ */
    $pS = mkPractice($pdo, 'REGTEST Brewer Dental', '55 Oak Ave, Magee, MS', $uS, 'dental_practice', $practiceIds, $userIds);
    $sync->syncPractice($pS);           // accept-baa hook order
    $rS3 = $sync->syncUser($uS);
    $cS3 = $pdo->query("SELECT * FROM marketing_contacts WHERE user_id=$uS")->fetch(PDO::FETCH_ASSOC);
    $orgS = (int)$pdo->query("SELECT id FROM marketing_orgs WHERE practice_id=$pS")->fetchColumn();
    ok('contact linked to practice org', $cS3 && (int)$cS3['org_id'] === $orgS, json_encode($cS3));
    ok('status upgraded to Signed Up', $cS3 && strtolower($cS3['status']) === 'signed up');
    ok('original registration date preserved', $cS3 && $cS3['user_registered_at'] === $cS['user_registered_at']);
    ok('still exactly one contact for user', (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE user_id=$uS")->fetchColumn() === 1);

    /* ============ 10: user joins an existing practice (member) ============ */
    $uM = mkUser($pdo, 'member@regtest-user.example', 'Mel', 'Member', $userIds);
    $sync->syncUser($uM);               // registered first, no practice
    // joins pS (created above) as a non-owner member
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (?,?,'user',0)")
        ->execute([$pS, $uM]);
    $rM = $sync->syncUser($uM);
    $cM = $pdo->query("SELECT * FROM marketing_contacts WHERE user_id=$uM")->fetch(PDO::FETCH_ASSOC);
    ok('joining practice links contact to org', $cM && (int)$cM['org_id'] === $orgS
        && strtolower($cM['status']) === 'signed up', json_encode($rM));
    ok('member contact is not duplicated', (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE user_id=$uM")->fetchColumn() === 1);

    /* ============ 11: CRM contact exists before user registers ============ */
    // Prospect contact already at an org; user registers with same email.
    $orgP = $admin->createOrg(['name' => 'REGTEST Preexisting Dental']);
    $oidP = (int)$orgP['id'];
    $admin->createContact(['org_id' => $oidP, 'full_name' => 'REGTEST Pre Person',
        'email' => 'preuser@regtest-pre.example', 'status' => 'Not Contacted', 'notes' => 'keep me']);
    $uP = mkUser($pdo, 'preuser@regtest-pre.example', 'Pat', 'Pre', $userIds);
    $rP = $sync->syncUser($uP);
    $cP = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='preuser@regtest-pre.example'")->fetch(PDO::FETCH_ASSOC);
    ok('existing contact stamped with user_id', $cP && (int)$cP['user_id'] === $uP, json_encode($rP));
    ok('no duplicate contact created', (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE email_normalized='preuser@regtest-pre.example'")->fetchColumn() === 1);
    ok('existing notes preserved', $cP && str_contains((string)$cP['notes'], 'keep me'));
    ok('pre-existing contact now registered/incomplete', $cP
        && in_array(strtolower($cP['status']), ['registered - setup incomplete', 'signed up'], true), $cP['status']);

    /* ============ 12: multi-practice user keeps one identity ============ */
    $uX2 = mkUser($pdo, 'multi@regtest-user.example', 'Mo', 'Multi', $userIds);
    $pX1 = mkPractice($pdo, 'REGTEST Multi One Dental', '1 First St, Tulsa, OK', $uX2, 'dental_practice', $practiceIds, $userIds);
    $pX2 = mkPractice($pdo, 'REGTEST Multi Two Dental', '2 Second St, Tulsa, OK', $uX2, 'dental_practice', $practiceIds, $userIds);
    // second practice: same owner -> member row needed for org link; add owner of pX2 = uX2 already via mkPractice.
    $rX = $sync->syncUser($uX2);
    $orgX1 = (int)$pdo->query("SELECT id FROM marketing_orgs WHERE practice_id=$pX1")->fetchColumn();
    $orgX2 = (int)$pdo->query("SELECT id FROM marketing_orgs WHERE practice_id=$pX2")->fetchColumn();
    $cX2 = $pdo->query("SELECT * FROM marketing_contacts WHERE user_id=$uX2")->fetch(PDO::FETCH_ASSOC);
    ok('multi-practice user has one contact', (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE user_id=$uX2")->fetchColumn() === 1);
    ok('contact org is a real practice org', $cX2 && in_array((int)$cX2['org_id'], [$orgX1, $orgX2], true), json_encode($cX2));
    ok('multi-practice contact is Signed Up', $cX2 && strtolower($cX2['status']) === 'signed up');

    /* ============ 13: registered-no-practice user blocked from outreach ============ */
    // Fresh user, never joined a practice -> standalone incomplete contact.
    $uZ = mkUser($pdo, 'lone@regtest-user.example', 'Lee', 'Lone', $userIds);
    $sync->syncUser($uZ);
    $regMap = PracticeRegistrationSync::registeredEmails($pdo, ['lone@regtest-user.example']);
    ok('registeredEmails covers user-only registration', isset($regMap['lone@regtest-user.example']));
    $cZ = $pdo->query("SELECT * FROM marketing_contacts WHERE user_id=$uZ")->fetch(PDO::FETCH_ASSOC);
    // Prospect view: registered flag + bucket + setup_incomplete flag.
    // Fresh instance: ProspectCrm caches its annotation context.
    $recZ = (new ProspectCrm($pdo))->findByEmail('lone@regtest-user.example');
    ok('CRM record exposes registered flag (no practice)', $recZ && !empty($recZ['registered']));
    ok('CRM record bucket is registered', $recZ && ($recZ['bucket'] ?? '') === 'registered', json_encode($recZ['bucket'] ?? null));
    ok('CRM record has setup_incomplete flag', $recZ && in_array('setup_incomplete', $recZ['flags'] ?? [], true), json_encode($recZ['flags'] ?? null));
    // Campaign add for initial -> suppressed already_registered.
    $camp2 = $admin->createCampaign(['name' => 'REGTEST Intro2', 'kind' => 'initial'], 50);
    $addZ = $admin->addToCampaign([(int)$cZ['id']], $camp2['campaign_id'], null, 'initial', 50);
    $resZ = $addZ['results'][0] ?? [];
    ok('practice-less registrant excluded from initial campaign', ($resZ['status'] ?? '') === 'suppressed'
        && str_contains((string)($resZ['reason'] ?? ''), 'already_registered'), json_encode($resZ));

} finally {
    cleanupReg($pdo, $practiceIds, $userIds);
    // campaigns created during tests
    $pdo->exec("DELETE r FROM marketing_campaign_recipients r JOIN marketing_campaigns c ON c.id=r.campaign_id WHERE c.name LIKE 'REGTEST%'");
    $pdo->exec("DELETE FROM marketing_campaigns WHERE name LIKE 'REGTEST%'");
    $pdo->exec("DELETE FROM marketing_prospect_events WHERE actor='regtest'");
    putenv('MARKETING_CRM_READS');
}
echo "registration-sync-test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
