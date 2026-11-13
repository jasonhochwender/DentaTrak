<?php
/**
 * Marketing CRM Phase 1 tests: schema, importer, reconciler.
 *
 * Runs against the LOCAL development database (port 3308 via .env_mode).
 * Uses synthetic fixtures only (@crmtest.invalid / CRMTEST orgs) and
 * cleans up after itself. The migration is idempotent and included here
 * so the suite is self-contained.
 *
 * Run: php tests/marketing-crm-import-test.php
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';
require_once $baseDir . '/api/appConfig.php';
require_once $baseDir . '/api/marketing/McpSheetSync.php';
require_once $baseDir . '/api/marketing/ProspectSheetImporter.php';
require_once $baseDir . '/api/marketing/ProspectReconciler.php';
require_once $baseDir . '/api/marketing/SuppressionService.php';

if (($appConfig['current_environment'] ?? '') !== 'development') {
    fwrite(STDERR, "REFUSING: not a development environment.\n");
    exit(1);
}
global $pdo;

// Ensure schema exists (idempotent migration).
require_once $baseDir . '/migrations/2026_11_13_marketing_crm.php';

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}

/* ---------------- synthetic sheet fixture ---------------- */

$HEADER = ['Public Email', 'Practice Name', 'Parent Organization', 'Contact Name',
    'First Name', 'Last Name', 'Title', 'Practice Type', 'Fit', 'Priority',
    'Outreach Status', 'Last Contact Date', 'First Contact Date', 'Follow-up Date',
    'Response Status', 'Campaign', 'Outreach Notes', 'Outreach Method',
    'Do Not Contact', 'Location', 'Website', 'Phone',
    'Unsubscribed At', 'Unsubscribe Source', 'Hunter Confidence', 'Email Type'];

// col index helper: row values align to $HEADER positions
function srow(array $vals): array {
    global $HEADER;
    $map = array_change_key_case(array_flip($HEADER));
    $out = array_fill(0, count($HEADER), '');
    foreach ($vals as $k => $v) $out[$map[strtolower($k)]] = $v;
    return $out;
}

$snapshot = ['header' => $HEADER, 'rows' => [
    // 2: clean import - DSO parent present
    srow(['Public Email' => 'owner@crmtest-alpha.com', 'Practice Name' => 'CRMTEST Alpha Dental',
        'Parent Organization' => 'CRMTEST DSO Group', 'Contact Name' => 'Ada Owner',
        'Title' => 'Owner', 'Practice Type' => 'dental practice', 'Fit' => 'A', 'Priority' => 'high',
        'Outreach Status' => 'New', 'Follow-up Date' => '11/20/2026', 'Location' => 'Austin, TX',
        'Website' => 'https://www.crmtest-alpha.com', 'Phone' => '555-0100',
        'Outreach Notes' => 'met at conference', 'Hunter Confidence' => 'Verified',
        'Email Type' => 'work']),
    // 3: same org, second contact
    srow(['Public Email' => 'front@crmtest-alpha.com', 'Practice Name' => 'crmtest alpha dental',
        'Parent Organization' => 'CRMTEST DSO Group', 'Contact Name' => 'Bob Front',
        'Outreach Status' => 'Contacted', 'Last Contact Date' => '2026-10-01',
        'First Contact Date' => '10/01/2026', 'Follow-up Date' => '2026-11-01']),
    // 4: duplicate email of row 2 -> duplicate disposition
    srow(['Public Email' => 'OWNER@crmtest-alpha.com', 'Practice Name' => 'CRMTEST Alpha Dental',
        'Contact Name' => 'Ada Owner', 'Outreach Status' => 'Sent']),
    // 5: missing email -> review
    srow(['Practice Name' => 'CRMTEST NoEmail Org', 'Contact Name' => 'CRMTEST NoEmail Person',
        'Outreach Status' => 'New']),
    // 6: DNC via sheet flag
    srow(['Public Email' => 'dnc@crmtest-beta.com', 'Practice Name' => 'CRMTEST Beta Dental',
        'Contact Name' => 'Dana DNC', 'Do Not Contact' => 'yes', 'Outreach Status' => 'Do Not Contact']),
    // 7: suppressed email (DB suppression) -> dnc must be set
    srow(['Public Email' => 'suppressed@crmtest-gamma.com', 'Practice Name' => 'CRMTEST Gamma Dental',
        'Contact Name' => 'Sam Supp', 'Outreach Status' => 'New']),
    // 8: shared email across a second org -> org mismatch review on matched contact
    srow(['Public Email' => 'shared@crmtest-delta.com', 'Practice Name' => 'CRMTEST Delta One',
        'Contact Name' => 'Shared Person', 'Outreach Status' => 'New']),
    // 9: same email, different org name
    srow(['Public Email' => 'shared@crmtest-delta.com', 'Practice Name' => 'CRMTEST Delta Two',
        'Contact Name' => 'Shared Person', 'Outreach Status' => 'New']),
    // 10: empty row
    srow([]),
    // 11: serial-date follow-up
    srow(['Public Email' => 'serial@crmtest-epsilon.com', 'Practice Name' => 'CRMTEST Epsilon',
        'Contact Name' => 'Eve Serial', 'Follow-up Date' => '46250']), // 2026-08-15-ish serial
    // 12: unsubscribed via audit columns -> dnc=1
    srow(['Public Email' => 'unsub@crmtest-zeta.com', 'Practice Name' => 'CRMTEST Zeta Dental',
        'Contact Name' => 'Uma Unsub', 'Outreach Status' => 'Unsubscribed',
        'Unsubscribed At' => '2026-10-20', 'Unsubscribe Source' => 'email_link']),
    // 13: same org name as rows 2-4 but different domain -> distinct org, flagged
    srow(['Public Email' => 'west@crmtest-alpha-west.com', 'Practice Name' => 'CRMTEST Alpha Dental',
        'Contact Name' => 'Wes West', 'Website' => 'https://crmtest-alpha-west.com',
        'Outreach Status' => 'New']),
    // 14: org-only row - no email, no contact name (identity-less contact)
    srow(['Practice Name' => 'CRMTEST Gamma Dental', 'Outreach Status' => 'Sent']),
]];

/* ---------------- cleanup helpers ---------------- */

$baselineRun = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM marketing_import_runs')->fetchColumn();

function cleanup(): void {
    global $pdo, $baselineRun;
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id
                WHERE c.email_normalized LIKE '%@crmtest%' OR c.full_name LIKE 'CRMTEST%'");
    $pdo->exec("DELETE ir FROM marketing_import_rows ir JOIN marketing_import_runs r ON r.id=ir.run_id WHERE r.id>$baselineRun");
    $pdo->exec("DELETE FROM marketing_import_runs WHERE id>$baselineRun");
    // Sever FK references from any older import bookkeeping before deleting fixtures.
    $pdo->exec("UPDATE marketing_import_rows SET contact_id=NULL, org_id=NULL WHERE contact_id IN
                (SELECT id FROM marketing_contacts WHERE email_normalized LIKE '%@crmtest%' OR full_name LIKE 'CRMTEST%')");
    $pdo->exec("UPDATE marketing_prospect_events SET contact_id=NULL, org_id=NULL WHERE contact_id IN
                (SELECT id FROM marketing_contacts WHERE email_normalized LIKE '%@crmtest%' OR full_name LIKE 'CRMTEST%')");
    $pdo->exec("DELETE FROM marketing_contacts WHERE email_normalized LIKE '%@crmtest%' OR full_name LIKE 'CRMTEST%'");
    // Identity-less fixture contacts (no email, no name) are not caught by
    // the patterns above - sever their FK references, then remove anything
    // still attached to a fixture org.
    $pdo->exec("UPDATE marketing_import_rows ir JOIN marketing_contacts c ON c.id=ir.contact_id
                JOIN marketing_orgs o ON o.id=c.org_id
                SET ir.contact_id=NULL, ir.org_id=NULL WHERE o.name LIKE 'CRMTEST%'");
    $pdo->exec("UPDATE marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id
                JOIN marketing_orgs o ON o.id=c.org_id
                SET e.contact_id=NULL, e.org_id=NULL WHERE o.name LIKE 'CRMTEST%'");
    $pdo->exec("DELETE c FROM marketing_contacts c JOIN marketing_orgs o ON o.id=c.org_id WHERE o.name LIKE 'CRMTEST%'");
    $pdo->exec("UPDATE marketing_orgs SET parent_org_id=NULL WHERE name LIKE 'CRMTEST%'");
    $pdo->exec("UPDATE marketing_contacts SET org_id=NULL WHERE org_id IN (SELECT id FROM marketing_orgs WHERE name LIKE 'CRMTEST%')");
    $pdo->exec("UPDATE marketing_import_rows SET org_id=NULL WHERE org_id IN (SELECT id FROM marketing_orgs WHERE name LIKE 'CRMTEST%')");
    $pdo->exec("UPDATE marketing_prospect_events SET org_id=NULL WHERE org_id IN (SELECT id FROM marketing_orgs WHERE name LIKE 'CRMTEST%')");
    $pdo->exec("DELETE FROM marketing_orgs WHERE name LIKE 'CRMTEST%'");
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%@crmtest%'");
    $pdo->exec("DELETE FROM marketing_org_suppressions WHERE org_ref LIKE 'crmtest%'");
}
cleanup();

$svc = new SuppressionService($pdo);
$svc->suppress('suppressed@crmtest-gamma.com', 'test');
$svc->suppressOrg('crmtest-orgref.com', 'test');

$importer = new ProspectSheetImporter($pdo, $snapshot);

/* ---------------- 1. schema ---------------- */
foreach (['marketing_orgs','marketing_contacts','marketing_prospect_events','marketing_import_runs','marketing_import_rows'] as $t) {
    ok("table $t exists", (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn() === 1);
}
ok('contacts.email_normalized is NOT unique', (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts' AND COLUMN_NAME='email_normalized' AND NON_UNIQUE=0"
)->fetchColumn() === 0);
ok('contacts has followup_date column', (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts' AND COLUMN_NAME='followup_date'"
)->fetchColumn() === 1);

/* ---------------- 2. dry-run ---------------- */
$dry = $importer->importSnapshot($snapshot, true);
ok('dry-run reports 13 source rows', $dry['source_rows'] === 13);
ok('dry-run persists nothing', (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE email_normalized LIKE '%@crmtest%'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM marketing_import_runs WHERE id>$baselineRun")->fetchColumn() === 0);

/* ---------------- 3. write import ---------------- */
$w = $importer->importSnapshot($snapshot, false);
ok('write import ran', ($w['run_id'] ?? 0) > 0);
ok('dispositions sum to source rows', array_sum($w['dispositions']) === 13);
ok('duplicate rows detected', ($w['dispositions']['duplicate'] ?? 0) === 2); // owner@ row4 + shared@ row9
ok('empty row skipped', ($w['dispositions']['skipped_invalid'] ?? 0) === 1);
ok('review rows flagged', ($w['review_rows'] ?? 0) >= 2); // missing email + shared-email org mismatch

$cnt = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
// distinct contacts: alpha-owner, alpha-front, noemail, beta-dnc, gamma-supp, delta-shared, epsilon, zeta-unsub, alpha-west, gamma-orgonly = 10
ok('contacts created', $cnt("SELECT COUNT(*) FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id=c.org_id WHERE c.email_normalized LIKE '%@crmtest%' OR c.full_name LIKE 'CRMTEST%' OR o.name LIKE 'CRMTEST%'") === 10);
// same org name + different domain must NOT merge: two CRMTEST Alpha Dental orgs
$alphaOrgs = $pdo->query("SELECT COUNT(*) FROM marketing_orgs WHERE normalized_name='crmtest alpha dental'")->fetchColumn();
ok('same-name different-domain orgs kept separate', (int)$alphaOrgs === 2);
$westOrg = $pdo->query("SELECT * FROM marketing_orgs WHERE domain='crmtest-alpha-west.com'")->fetch(PDO::FETCH_ASSOC);
ok('ambiguous org flagged for review', $westOrg && (int)$westOrg['needs_review'] === 1 && $westOrg['review_reason'] === 'duplicate_org_name');
ok('orgs created incl. DSO parent', $cnt("SELECT COUNT(*) FROM marketing_orgs WHERE name LIKE 'CRMTEST%'") >= 6);

$alpha = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='owner@crmtest-alpha.com'")->fetch(PDO::FETCH_ASSOC);
ok('alpha contact fields', $alpha && $alpha['full_name'] === 'Ada Owner' && $alpha['title'] === 'Owner'
    && $alpha['first_contact_date'] === null && $alpha['followup_date'] === '2026-11-20'
    && $alpha['org_id'] !== null);
$org = $pdo->query("SELECT * FROM marketing_orgs WHERE id=" . (int)$alpha['org_id'])->fetch(PDO::FETCH_ASSOC);
ok('org website/domain parsed', $org && $org['domain'] === 'crmtest-alpha.com');
$parent = $pdo->query("SELECT parent_org_id FROM marketing_orgs WHERE id=" . (int)$alpha['org_id'])->fetchColumn();
ok('DSO parent linked', $parent && (int)$pdo->query("SELECT COUNT(*) FROM marketing_orgs WHERE id=" . (int)$parent . " AND name='CRMTEST DSO Group'")->fetchColumn() === 1);

$beta = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='dnc@crmtest-beta.com'")->fetch(PDO::FETCH_ASSOC);
ok('sheet DNC flag -> dnc=1', $beta && (int)$beta['dnc'] === 1);
$gamma = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='suppressed@crmtest-gamma.com'")->fetch(PDO::FETCH_ASSOC);
ok('suppressed email -> dnc=1 (strengthened)', $gamma && (int)$gamma['dnc'] === 1);
$delta = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='shared@crmtest-delta.com'")->fetch(PDO::FETCH_ASSOC);
ok('shared email merged to one contact', $delta && (int)$pdo->query("SELECT COUNT(*) FROM marketing_contacts WHERE email_normalized='shared@crmtest-delta.com'")->fetchColumn() === 1);
ok('shared-email org mismatch flagged', $delta && (int)$delta['needs_review'] === 1 && str_contains($delta['review_reason'] ?? '', 'email_org_mismatch'));
$noemail = $pdo->query("SELECT * FROM marketing_contacts WHERE full_name='CRMTEST NoEmail Person'")->fetch(PDO::FETCH_ASSOC);
ok('missing email -> needs_review', $noemail && (int)$noemail['needs_review'] === 1);
$serial = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='serial@crmtest-epsilon.com'")->fetch(PDO::FETCH_ASSOC);
ok('serial date parsed', $serial && $serial['followup_date'] !== null && $serial['followup_date'] < '2026-12-01');
$zeta = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='unsub@crmtest-zeta.com'")->fetch(PDO::FETCH_ASSOC);
ok('unsubscribed audit columns -> dnc=1', $zeta && (int)$zeta['dnc'] === 1);
$alphaExtra = json_decode((string)$alpha['extra_fields'], true);
ok('unmapped headers preserved in extra_fields', is_array($alphaExtra) && ($alphaExtra['Hunter Confidence'] ?? '') === 'Verified' && ($alphaExtra['Email Type'] ?? '') === 'work');

/* ---------------- 4. idempotent re-import ---------------- */
$eventsBefore = $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE dedupe_key LIKE 'sheet_import:%'");
$crmBefore = $cnt("SELECT COUNT(*) FROM marketing_contacts c JOIN marketing_orgs o ON o.id=c.org_id WHERE o.name LIKE 'CRMTEST%'");
$w2 = $importer->importSnapshot($snapshot, false);
$eventsAfter = $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE dedupe_key LIKE 'sheet_import:%'");
$crmAfter = $cnt("SELECT COUNT(*) FROM marketing_contacts c JOIN marketing_orgs o ON o.id=c.org_id WHERE o.name LIKE 'CRMTEST%'");
ok('re-import creates no duplicate contacts', $cnt("SELECT COUNT(*) FROM marketing_contacts WHERE email_normalized='owner@crmtest-alpha.com'") === 1);
ok('re-import creates no identity-less duplicates', $crmAfter === $crmBefore);
ok('re-import writes no duplicate events', $eventsAfter === $eventsBefore);
ok('re-import dispositions still matched', ($w2['dispositions']['matched'] ?? 0) >= 6);

/* ---------------- 5. non-destructive merge ---------------- */
// Manually enrich a contact, re-import with blank/conflicting source values.
$pdo->prepare("UPDATE marketing_contacts SET title='Chief Executive Officer', notes='manual note' WHERE email_normalized='front@crmtest-alpha.com'")->execute();
$frontBefore = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='front@crmtest-alpha.com'")->fetch(PDO::FETCH_ASSOC);
// source row 3 has no Title and no Notes -> must be preserved
$imp3 = new ProspectSheetImporter($pdo, $snapshot);
$imp3->importSnapshot($snapshot, false);
$frontAfter = $pdo->query("SELECT * FROM marketing_contacts WHERE email_normalized='front@crmtest-alpha.com'")->fetch(PDO::FETCH_ASSOC);
ok('blank import does not overwrite title', $frontAfter['title'] === 'Chief Executive Officer');
ok('blank import does not overwrite notes', $frontAfter['notes'] === 'manual note');

/* ---------------- 6. reconciliation ---------------- */
$rec = new ProspectReconciler($pdo, $snapshot);
$rep = $rec->report();
ok('reconcile: all source rows accounted', $rep['all_source_rows_accounted'] === true);
ok('reconcile: dispositions sum', array_sum($rep['dispositions']) === 13);
ok('reconcile: suppression consistent', $rep['suppression_inconsistencies']['email_suppressed_but_not_dnc'] === 0
    && $rep['suppression_inconsistencies']['org_suppressed_but_not_dnc'] === 0);
ok('reconcile: DSO link counted', $rep['dso_links'] >= 1);
ok('reconcile: status distribution present', isset($rep['status_distribution']['New']) || isset($rep['status_distribution']['Contacted']));

/* ---------------- cleanup ---------------- */
cleanup();
ok('cleanup removed test contacts', $cnt("SELECT COUNT(*) FROM marketing_contacts WHERE email_normalized LIKE '%@crmtest%' OR full_name LIKE 'CRMTEST%'") === 0);
ok('cleanup removed test orgs', $cnt("SELECT COUNT(*) FROM marketing_orgs WHERE name LIKE 'CRMTEST%'") === 0);
// suppressions are rows in marketing_suppressions - remove via direct delete (test-only addresses)
$pdo->exec("DELETE FROM marketing_sync_queue WHERE suppression_id NOT IN (SELECT id FROM marketing_suppressions)");

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
