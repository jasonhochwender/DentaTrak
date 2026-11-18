<?php
/**
 * One-time (and safely repeatable) reconciliation of DentaTrak practice
 * registrations into the Marketing CRM.
 *
 * For every practice row, PracticeRegistrationSync::syncPractice() links
 * an existing CRM org or creates one, marks contacts 'Signed Up', and
 * records events. Idempotent: the practice_id link + 'Signed Up' statuses
 * make reruns no-ops.
 *
 * Usage (Cloud Run job / CLI):
 *   php scripts/marketing-sync-registrations.php [--practice-id=N] [--dry-run]
 *
 * --dry-run reports what WOULD change without writing (matches are still
 * resolved read-only where possible; org/contact writes are skipped).
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';
require_once __DIR__ . '/../api/marketing/PracticeRegistrationSync.php';
global $pdo;

$onlyId = null; $dryRun = false; $verify = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--practice-id=(\d+)$/', $a, $m)) $onlyId = (int)$m[1];
    if ($a === '--dry-run') $dryRun = true;
    if ($a === '--verify') $verify = true;
}

$sync = new PracticeRegistrationSync($pdo, 'cli:registration-reconciliation');

$sql = 'SELECT id FROM practices' . ($onlyId ? ' WHERE id=' . $onlyId : ' ORDER BY id');
$ids = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
echo "practices to examine: " . count($ids) . ($dryRun ? " (dry-run)" : '') . "\n";

$stats = ['examined' => 0, 'linked' => 0, 'created' => 0, 'updated' => 0, 'none' => 0,
    'ambiguous' => 0, 'errors' => 0, 'contacts_updated' => 0, 'contacts_created' => 0];
$problems = [];

foreach ($ids as $pid) {
    $stats['examined']++;
    $st = $pdo->prepare('SELECT practice_name, legal_name FROM practices WHERE id=?');
    $st->execute([$pid]);
    $prow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $pname = (string)($prow['legal_name'] ?? '') !== '' ? (string)$prow['legal_name'] : (string)($prow['practice_name'] ?? '');
    if ($dryRun) {
        // Read-only preview: report the practice + whether an org is
        // already linked; full matching runs inside syncPractice only.
        echo "  #$pid $pname\n";
        continue;
    }
    $r = $sync->syncPractice($pid);
    if ($r['error'] !== null) {
        $stats['errors']++;
        $problems[] = "#$pid: {$r['error']}";
        continue;
    }
    $stats[$r['action']] = ($stats[$r['action']] ?? 0) + 1;
    if ($r['review']) $stats['ambiguous']++;
    $stats['contacts_updated'] += (int)$r['contacts_updated'];
    $stats['contacts_created'] += (int)$r['contacts_created'];
    if ($r['action'] !== 'none' || $r['review']) {
        echo "  #$pid $pname action={$r['action']} org={$r['org_id']}"
            . ($r['review'] ? ' REVIEW' : '')
            . " contacts+{$r['contacts_created']}/~{$r['contacts_updated']}\n";
    }
}

echo "\n=== practice reconciliation summary ===\n";
foreach ($stats as $k => $v) echo "  $k: $v\n";
foreach ($problems as $p) echo "  ERROR $p\n";

// ---- user pass: every DentaTrak account -> CRM contact ---------------
// syncUser() ensures the user's practice memberships are synced first,
// then upserts a contact keyed by user_id (org-linked 'Signed Up' or
// standalone 'Registered - Setup Incomplete'). Idempotent.
$ustats = ['users_examined' => 0, 'created' => 0, 'updated' => 0, 'none' => 0,
    'incomplete' => 0, 'errors' => 0, 'contacts_created' => 0, 'contacts_updated' => 0];
$uproblems = [];
$uids = array_map('intval', $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
echo "\nusers to examine: " . count($uids) . "\n";
foreach ($uids as $uid) {
    $ustats['users_examined']++;
    if ($dryRun) {
        $st = $pdo->prepare('SELECT email, first_name, last_name FROM users WHERE id=?');
        $st->execute([$uid]);
        $ur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        echo "  user#$uid " . trim(($ur['first_name'] ?? '') . ' ' . ($ur['last_name'] ?? ''))
            . ' <' . ($ur['email'] ?? '?') . ">\n";
        continue;
    }
    $r = $sync->syncUser($uid);
    if ($r['error'] !== null) {
        $ustats['errors']++;
        $uproblems[] = "user#$uid: {$r['error']}";
        continue;
    }
    $ustats[$r['action']] = ($ustats[$r['action']] ?? 0) + 1;
    if (!empty($r['incomplete'])) $ustats['incomplete']++;
    if ($r['action'] === 'created') $ustats['contacts_created']++;
    if ($r['action'] === 'updated') $ustats['contacts_updated']++;
}

echo "\n=== user reconciliation summary ===\n";
foreach ($ustats as $k => $v) echo "  $k: $v\n";
foreach ($uproblems as $p) echo "  ERROR $p\n";

if ($verify) {
    // Read-only post-state: every org linked to a practice, with contact
    // counts and review flags. Masked to org/practice identifiers only.
    echo "\n=== linked orgs ===\n";
    $rows = $pdo->query(
        "SELECT o.id AS org_id, o.practice_id, o.name AS org_name, o.needs_review,
                o.review_reason, o.registered_at,
                (SELECT COUNT(*) FROM marketing_contacts c WHERE c.org_id=o.id) AS contacts,
                (SELECT COUNT(*) FROM marketing_contacts c WHERE c.org_id=o.id
                  AND LOWER(c.status)='signed up') AS signed_up
           FROM marketing_orgs o
          WHERE o.practice_id IS NOT NULL
          ORDER BY o.practice_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  org={$r['org_id']} practice={$r['practice_id']} contacts={$r['contacts']}"
            . " signed_up={$r['signed_up']} registered_at={$r['registered_at']}"
            . " review=" . ((int)$r['needs_review'] ? 'yes' : 'no')
            . " " . substr((string)$r['org_name'], 0, 60) . "\n";
    }
    echo "  linked orgs total: " . count($rows) . "\n";

    // Registered users who have not completed practice setup.
    echo "\n=== setup-incomplete registrations ===\n";
    $inc = $pdo->query(
        "SELECT c.id, c.user_id, c.full_name, c.email, c.user_registered_at,
                c.created_at
           FROM marketing_contacts c
          WHERE LOWER(c.status) = 'registered - setup incomplete'
          ORDER BY c.user_registered_at"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inc as $r) {
        $em = (string)$r['email'];
        $at = strpos($em, '@');
        $masked = $at === false ? '***' : substr($em, 0, 1) . '***' . substr($em, $at);
        echo "  contact={$r['id']} user={$r['user_id']} registered_at={$r['user_registered_at']}"
            . " " . substr((string)$r['full_name'], 0, 40) . " <$masked>\n";
    }
    echo "  setup-incomplete total: " . count($inc) . "\n";
}
echo "done\n";
