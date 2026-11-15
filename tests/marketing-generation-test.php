<?php
/**
 * Background campaign generation tests (local dev DB 3308).
 * Self-cleaning GEN fixtures. Template fallback is used (no AI key in
 * the injected appConfig) so generation is deterministic; no email is
 * sent and nothing is approved.
 *
 * Covers: request queueing/cancel, missing vs replace_unedited scopes,
 * edited-message protection, durable per-recipient failures with
 * bounded retries, drain completion, and campaign ready flip.
 */
require_once __DIR__ . '/../api/marketing/CampaignGenerator.php';
require_once __DIR__ . '/../api/marketing/ProspectCrmAdmin.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';

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

$tag = 'GEN' . bin2hex(random_bytes(3));
$actor = 'gen-test@dentatrak.com';
$admin = new ProspectCrmAdmin($pdo, $actor);
$campaignIds = []; $contactIds = []; $orgIds = [];

// No AI key -> deterministic template fallback; CRM reads on.
putenv('MARKETING_CRM_READS=true');
$appConfig = ['ai_provider' => 'gemini', 'gemini' => [], 'baseUrl' => 'https://dentatrak.test'];
$gen = new CampaignGenerator($pdo, $appConfig, 'https://dentatrak.test');

function gen_cleanup(PDO $pdo, array $campaignIds, array $contactIds, array $orgIds, string $tag): void {
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
    $pdo->exec("DELETE FROM marketing_audit_log WHERE actor_email='gen-test@dentatrak.com'");
}

$recip = function (string $cid) use ($pdo) {
    return $pdo->prepare('SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id');
};

try {
    $org = $admin->createOrg(['name' => "$tag Dental", 'org_type' => 'practice']);
    $orgId = $org['id']; $orgIds[] = $orgId;
    $mkContact = function (int $i) use ($admin, $orgId, $tag, &$contactIds) {
        $c = $admin->createContact(['org_id' => $orgId, 'full_name' => "$tag Person$i",
            'email' => "g$i@$tag.example", 'status' => 'New', 'source' => 'test']);
        $contactIds[] = $c['id'];
        return $c['id'];
    };

    // -- 1. Campaign with 3 recipients ------------------------------------
    $ids = [$mkContact(1), $mkContact(2), $mkContact(3)];
    $camp = $admin->createCampaign(['name' => "$tag Gen", 'kind' => 'initial'], 500);
    $cid = $camp['campaign_id']; $campaignIds[] = $cid;
    $admin->addToCampaign($ids, $cid, null, 'initial', 500);

    // -- 2. request() queues generation ------------------------------------
    $rq = $gen->request($cid, 'missing', $actor);
    $t('request queues generation', !empty($rq['success']) && !empty($rq['requested']), json_encode($rq));
    $row = $pdo->query("SELECT gen_requested, gen_scope FROM marketing_campaigns WHERE id=" . $pdo->quote($cid))->fetch(PDO::FETCH_ASSOC);
    $t('gen_requested/gen_scope persisted', (int)$row['gen_requested'] === 1 && $row['gen_scope'] === 'missing');
    $t('request is idempotent', !empty($gen->request($cid, 'missing', $actor)['success']));
    $auditN = (int)$pdo->query("SELECT COUNT(*) FROM marketing_audit_log WHERE campaign_id=" . $pdo->quote($cid) . " AND action='campaign_generate_requested'")->fetchColumn();
    $t('request is audit-logged', $auditN >= 1, "n=$auditN");

    // -- 3. runBatch 'missing' fills drafts only ----------------------------
    // Pre-seed: recipient 2 gets manual content + is_edited; recipient 3
    // gets auto content (ready, unedited) to prove 'missing' skips it.
    $rows = $recip($cid)->fetchAll(PDO::FETCH_ASSOC) ?? [];
    $stmt = $pdo->prepare('SELECT id FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id');
    $stmt->execute([$cid]); $rids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare("UPDATE marketing_campaign_recipients SET subject='Manual', body_html='<p>KEEP</p>', status='ready', is_edited=1 WHERE id=?")->execute([$rids[1]]);
    $pdo->prepare("UPDATE marketing_campaign_recipients SET subject='Auto', body_html='<p>OLD-AUTO</p>', status='ready' WHERE id=?")->execute([$rids[2]]);

    $b = $gen->runBatch($cid, 25, ['scope' => 'missing']);
    $t('missing batch processed only the draft', $b['processed'] === 1 && $b['generated'] === 1,
        json_encode(['p' => $b['processed'], 'g' => $b['generated']]));
    $check = $pdo->query("SELECT id,status,subject,is_edited FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $t('edited ready content preserved under missing',
        $check[1]['subject'] === 'Manual' && (int)$check[1]['is_edited'] === 1);
    $t('unedited ready content preserved under missing',
        $check[2]['subject'] === 'Auto' && $check[2]['status'] === 'ready');
    $t('draft got generated content',
        $check[0]['status'] === 'ready' && $check[0]['subject'] !== '' && strpos((string)$check[0]['subject'], 'Manual') === false);

    // -- 4. replace_unedited regenerates unedited ready only -----------------
    $b2 = $gen->runBatch($cid, 25, ['scope' => 'replace_unedited']);
    $t('replace_unedited processed 2 (draft-now-ready + unedited ready)',
        $b2['processed'] === 2, json_encode(['p' => $b2['processed']]));
    $check = $pdo->query("SELECT subject,is_edited FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $t('unedited ready was regenerated', $check[2]['subject'] !== 'Auto', $check[2]['subject']);
    $t('manually edited content still preserved', $check[1]['subject'] === 'Manual');

    // -- 5. Per-recipient regen with force -----------------------------------
    $b3 = $gen->runBatch($cid, 5, ['recipient_ids' => [$rids[1]], 'force' => true]);
    $t('forced single-recipient regen works', $b3['generated'] === 1, json_encode($b3));
    $check = $pdo->query("SELECT subject FROM marketing_campaign_recipients WHERE id=" . (int)$rids[1])->fetch(PDO::FETCH_ASSOC);
    $t('force overwrote manual edit explicitly', $check['subject'] !== 'Manual', $check['subject']);

    // -- 6. Durable failures + bounded retries --------------------------------
    $badCid = $admin->createCampaign(['name' => "$tag Bad", 'kind' => 'initial'], 500);
    $badCid = $badCid['campaign_id']; $campaignIds[] = $badCid;
    // Insert a recipient whose email has no CRM contact.
    $pdo->prepare("INSERT INTO marketing_campaign_recipients (campaign_id, prospect_email, prospect_org, status) VALUES (?,?,'Ghost Org','draft')")
        ->execute([$badCid, "ghost@$tag.example"]);
    $pdo->prepare("INSERT INTO marketing_campaign_recipients (campaign_id, contact_id, prospect_email, prospect_org, status) VALUES (?,?,?,'Good Org','draft')")
        ->execute([$badCid, $ids[0], "g1@$tag.example"]);

    for ($i = 0; $i < CampaignGenerator::MAX_ATTEMPTS; $i++) {
        $gen->runBatch($badCid, 10, ['scope' => 'missing']);
    }
    $ghost = $pdo->query("SELECT gen_attempts, gen_error, status FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($badCid) . " AND prospect_email LIKE 'ghost%'")->fetch(PDO::FETCH_ASSOC);
    $t('failure recorded durably', (int)$ghost['gen_attempts'] >= CampaignGenerator::MAX_ATTEMPTS
        && $ghost['gen_error'] === 'prospect_not_found' && $ghost['status'] === 'draft',
        json_encode($ghost));
    $t('exhausted failure drops out of actionable set',
        $gen->actionableCount($badCid, 'missing') === 0, 'pending=' . $gen->actionableCount($badCid, 'missing'));
    $t('failedCount reports exhausted failures', $gen->failedCount($badCid) === 1);
    // Good recipient generated despite ghost failing.
    $good = $pdo->query("SELECT status FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($badCid) . " AND prospect_email LIKE 'g1%'")->fetch(PDO::FETCH_ASSOC);
    $t('good recipient unaffected by neighbor failure', $good['status'] === 'ready');

    // -- 7. retry_failed resets attempts ---------------------------------------
    $rq2 = $gen->request($badCid, 'retry_failed', $actor);
    $t('retry_failed re-queues', !empty($rq2['success']), json_encode($rq2));
    $ghost = $pdo->query("SELECT gen_attempts, gen_error FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($badCid) . " AND prospect_email LIKE 'ghost%'")->fetch(PDO::FETCH_ASSOC);
    $t('retry_failed resets attempts', (int)$ghost['gen_attempts'] === 0 && $ghost['gen_error'] === null);

    // -- 8. drain() completes and releases the request --------------------------
    // Each recipient needs its own org: org-level already_contacted /
    // duplicate_org suppression is real product behavior.
    $mkContactOrg = function (int $i) use ($admin, $tag, &$contactIds, &$orgIds) {
        $o = $admin->createOrg(['name' => "$tag DrainOrg$i", 'org_type' => 'practice']);
        $orgIds[] = $o['id'];
        $c = $admin->createContact(['org_id' => $o['id'], 'full_name' => "$tag Drain$i",
            'email' => "d$i@$tag.example", 'status' => 'New', 'source' => 'test']);
        $contactIds[] = $c['id'];
        return $c['id'];
    };
    $drainCid = $admin->createCampaign(['name' => "$tag Drain", 'kind' => 'initial'], 500);
    $drainCid = $drainCid['campaign_id']; $campaignIds[] = $drainCid;
    $admin->addToCampaign([$mkContactOrg(10), $mkContactOrg(11)], $drainCid, null, 'initial', 500);
    $gen->request($drainCid, 'missing', $actor);
    $d = $gen->drain($drainCid, 60, 5, $actor);
    $t('drain generates all pending', $d['generated'] === 2 && $d['remaining'] === 0,
        json_encode(['g' => $d['generated'], 'r' => $d['remaining']]));
    $t('drain releases gen_requested', !$gen->isRequested($drainCid));
    $campRow = $pdo->query("SELECT status FROM marketing_campaigns WHERE id=" . $pdo->quote($drainCid))->fetch(PDO::FETCH_ASSOC);
    $t('campaign flips to ready when no drafts remain', $campRow['status'] === 'ready', $campRow['status']);

    // -- 9. cancel() stops a queued request --------------------------------------
    $canCid = $admin->createCampaign(['name' => "$tag Cancel", 'kind' => 'initial'], 500);
    $canCid = $canCid['campaign_id']; $campaignIds[] = $canCid;
    $admin->addToCampaign([$mkContactOrg(12)], $canCid, null, 'initial', 500);
    $gen->request($canCid, 'missing', $actor);
    $gen->cancel($canCid, $actor);
    $t('cancel releases gen_requested', !$gen->isRequested($canCid));
    $d2 = $gen->drain($canCid, 10, 5, $actor);
    $t('cancelled campaign is not drained', $d2['generated'] === 0, json_encode($d2));

    // -- 10. Nothing was approved or sent -----------------------------------------
    $appr = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE id IN (" . implode(',', array_map(fn($c) => $pdo->quote($c), $campaignIds)) . ") AND status IN ('approved','sending')")->fetchColumn();
    $t('no campaign approved/sending', $appr === 0, "n=$appr");
    $sent = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id IN (" . implode(',', array_map(fn($c) => $pdo->quote($c), $campaignIds)) . ") AND status='sent'")->fetchColumn();
    $t('no recipient sent', $sent === 0, "n=$sent");

} catch (Throwable $e) {
    $fail++;
    echo "FAIL exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    gen_cleanup($pdo, $campaignIds, $contactIds, $orgIds, $tag);
}
echo "generation-test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
