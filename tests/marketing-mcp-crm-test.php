<?php
/**
 * MCP prospect-CRM connector tests (service level, local dev DB 3308).
 * Self-cleaning MCPCRM fixtures. No email is sent; suppression tables are
 * only read. Exercises McpCrmTools directly (the same class server.php
 * dispatches to).
 */
require_once __DIR__ . '/../api/marketing/McpCrmTools.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';

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

$tag = 'MCPCRM' . bin2hex(random_bytes(3));
$actor = 'mcp-test@dentatrak.com';
$tools = new McpCrmTools($pdo, $actor);
$ids = ['orgs' => [], 'contacts' => []];

try {
    // -- create_organization -------------------------------------------------
    // approval gate: writes without confirm=true must be rejected
    $blocked = false;
    try { $tools->createOrganization(['name' => "$tag Dental Group", 'confirm' => false]); }
    catch (InvalidArgumentException $e) { $blocked = str_contains($e->getMessage(), 'confirm=true'); }
    $t('create_organization requires confirm', $blocked);
    // dry_run previews without writing
    $dry = $tools->createOrganization(['name' => "$tag DryRun Org", 'dry_run' => true]);
    $t('create_organization dry_run writes nothing', !empty($dry['dry_run'])
        && (int)$pdo->query("SELECT COUNT(*) FROM marketing_orgs WHERE name='$tag DryRun Org'")->fetchColumn() === 0);
    $org = $tools->createOrganization(['name' => "$tag Dental Group", 'org_type' => 'dso',
        'domain' => "$tag.example", 'location' => 'Testville', 'confirm' => true]);
    $orgId = $org['org_id']; $ids['orgs'][] = $orgId;
    $t('create_organization returns id', $orgId > 0);
    $dupOrg = $tools->createOrganization(['name' => "$tag Dental Group", 'confirm' => true]);
    $ids['orgs'][] = $dupOrg['org_id'];
    $t('duplicate org name warns not blocks', !empty($dupOrg['warnings']));

    // -- prepare_prospect (no writes) ----------------------------------------
    $before = (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE full_name LIKE '$tag%'")->fetchColumn();
    $prep = $tools->prepareProspect(['org_id' => $orgId, 'contact_name' => "$tag Alice",
        'email' => "alice@$tag.example", 'title' => 'Office Manager']);
    $t('prepare reports valid', $prep['valid'] === true);
    $after = (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE full_name LIKE '$tag%'")->fetchColumn();
    $t('prepare writes nothing', $before === $after);
    $badPrep = $tools->prepareProspect(['contact_name' => 'NoOrg']);
    $t('prepare flags missing org', $badPrep['valid'] === false);

    // -- create_prospect ------------------------------------------------------
    $c1 = $tools->createProspect(['org_id' => $orgId, 'contact_name' => "$tag Alice",
        'email' => "alice@$tag.example", 'title' => 'Office Manager', 'status' => 'Not Contacted',
        'confirm' => true]);
    $cid = $c1['contact_id']; $ids['contacts'][] = $cid;
    $t('create_prospect returns id', $cid > 0);
    $row = $pdo->query("SELECT source, version FROM marketing_contacts WHERE id=$cid")->fetch(PDO::FETCH_ASSOC);
    $t('source attribution recorded', ($row['source'] ?? '') === 'chatgpt_connector');

    // duplicate email -> warning, separate contact allowed
    $c2 = $tools->createProspect(['org_id' => $orgId, 'contact_name' => "$tag Alice2",
        'email' => "alice@$tag.example", 'confirm' => true]);
    $ids['contacts'][] = $c2['contact_id'];
    $t('shared email warns, still creates', $c2['contact_id'] > 0
        && count(array_filter($c2['warnings'], fn($w) => $w['code'] === 'email_in_use')) === 1);

    // -- search / get ----------------------------------------------------------
    $s = $tools->searchProspects(['q' => $tag, 'per' => 50]);
    $t('search finds created contacts', $s['total'] >= 2, "total={$s['total']}");
    $s2 = $tools->searchProspects(['q' => $tag, 'status' => 'Not Contacted']);
    $t('search status filter', $s2['total'] >= 2);
    // geographic filters
    $sg = $tools->searchProspects(['state' => 'GA', 'per' => 50]);
    $t('search state=GA returns GA prospects', $sg['total'] > 0
        && count(array_filter($sg['prospects'], fn($p) => ($p['state'] ?? '') === 'GA')) === count($sg['prospects']));
    $sf = $tools->searchProspects(['state' => 'Georgia']);
    $t('state name normalizes to abbreviation', $sf['total'] === $sg['total']);
    $sc = $tools->searchProspects(['city' => 'Jacksonville']);
    $t('search city filter', $sc['total'] > 0
        && count(array_filter($sc['prospects'], fn($p) => stripos($p['location'] ?? '', 'jacksonville') !== false)) === count($sc['prospects']));
    $det = $tools->getProspect(['contact_id' => $cid]);
    $t('get_prospect returns detail+org', ($det['prospect']['id'] ?? 0) === $cid
        && ($det['organization']['id'] ?? 0) === $orgId);
    $t('get_prospect includes events', isset($det['recent_events']) && is_array($det['recent_events']));

    // -- org reads --------------------------------------------------------------
    $og = $tools->getOrganization(['org_id' => $orgId]);
    $t('get_organization contact_count', (int)$og['contact_count'] >= 2, (string)$og['contact_count']);
    $oc = $tools->listOrganizationContacts(['org_id' => $orgId]);
    $t('list_organization_contacts', $oc['total'] >= 2);

    // -- duplicate check ---------------------------------------------------------
    $dc = $tools->checkProspectDuplicate(['email' => "alice@$tag.example", 'org_name' => "$tag Dental Group"]);
    $t('dup check finds email+org', count($dc['email_matches']) >= 2 && count($dc['org_matches']) >= 1);

    // -- update_prospect (optimistic concurrency) ---------------------------------
    $v = (int)$pdo->query("SELECT version FROM marketing_contacts WHERE id=$cid")->fetchColumn();
    // confirm gate on updates
    $noConfirm = false;
    try { $tools->updateProspect(['contact_id' => $cid, 'version' => $v, 'fields' => ['title' => 'X']]); }
    catch (InvalidArgumentException $e) { $noConfirm = str_contains($e->getMessage(), 'confirm=true'); }
    $t('update_prospect requires confirm', $noConfirm);
    $u = $tools->updateProspect(['contact_id' => $cid, 'version' => $v, 'confirm' => true,
        'fields' => ['title' => 'Practice Manager', 'priority' => '2 - Strong Priority', 'notes' => 'seed note']]);
    $t('update_prospect succeeds', in_array('title', $u['changed'] ?? [], true));
    // blank values must be ignored, never clear verified data
    $v2 = (int)$pdo->query("SELECT version FROM marketing_contacts WHERE id=$cid")->fetchColumn();
    $u2 = $tools->updateProspect(['contact_id' => $cid, 'version' => $v2, 'confirm' => true,
        'fields' => ['notes' => '', 'title' => '']]);
    $t('blank fields ignored not cleared', in_array('notes', $u2['ignored_blank_fields'] ?? [], true)
        && in_array('title', $u2['ignored_blank_fields'] ?? [], true)
        && $pdo->query("SELECT notes FROM marketing_contacts WHERE id=$cid")->fetchColumn() === 'seed note'
        && $pdo->query("SELECT title FROM marketing_contacts WHERE id=$cid")->fetchColumn() === 'Practice Manager');
    $stale = false;
    try { $tools->updateProspect(['contact_id' => $cid, 'version' => $v, 'confirm' => true, 'fields' => ['title' => 'X']]); }
    catch (InvalidArgumentException $e) { $stale = str_contains($e->getMessage(), 'version conflict'); }
    $t('stale version rejected', $stale);

    // -- update_organization -------------------------------------------------------
    $uo = $tools->updateOrganization(['org_id' => $orgId, 'confirm' => true, 'fields' => ['location' => 'Newville']]);
    $t('update_organization succeeds', in_array('location', $uo['changed'] ?? [], true));

    // -- research note ---------------------------------------------------------------
    $n = $tools->addProspectNote(['contact_id' => $cid, 'confirm' => true, 'text' => 'Site shows 3 locations; lab outreach angle.',
        'sources' => ['https://example.com/about'], 'researched_at' => '2026-11-13']);
    $t('add_prospect_note returns event id', $n['event_id'] > 0);
    $ev = $pdo->query("SELECT event_type, source, details FROM marketing_prospect_events WHERE id={$n['event_id']}")->fetch(PDO::FETCH_ASSOC);
    $t('note stored as research_note/mcp', $ev['event_type'] === 'research_note' && $ev['source'] === 'mcp');
    $det2 = json_decode($ev['details'], true);
    $t('note keeps sources', in_array('https://example.com/about', $det2['sources'] ?? [], true));

    // -- audit ---------------------------------------------------------------------
    $aud = (int)$pdo->query("SELECT COUNT(*) FROM marketing_audit_log WHERE actor_email='$actor' AND action LIKE 'mcp_%'")->fetchColumn();
    $t('mutations audit-logged', $aud >= 5, "audit rows=$aud");

    // -- suppression/DNC untouched ---------------------------------------------------
    $dnc = (int)$pdo->query("SELECT dnc FROM marketing_contacts WHERE id=$cid")->fetchColumn();
    $t('dnc remains 0 (no weakening)', $dnc === 0);
} finally {
    // Cleanup fixtures
    foreach ($ids['contacts'] as $id) {
        $pdo->exec("DELETE FROM marketing_prospect_events WHERE contact_id=$id");
        $pdo->exec("DELETE FROM marketing_contacts WHERE id=$id");
    }
    foreach ($ids['orgs'] as $id) {
        $pdo->exec("DELETE FROM marketing_prospect_events WHERE org_id=$id");
        $pdo->exec("DELETE FROM marketing_orgs WHERE id=$id");
    }
    $pdo->exec("DELETE FROM marketing_audit_log WHERE actor_email='$actor'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
