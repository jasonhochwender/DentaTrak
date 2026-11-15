<?php
/**
 * DSO campaign separation - production data operation.
 *
 * Moves the specified CRM contacts out of the two original practice
 * campaigns so they live only in the new DSO/group campaigns. The new
 * campaigns already exist with saved drafts; this script never creates,
 * approves, authorizes, or sends anything.
 *
 * Modes:
 *   --verify            Read-only report + invariant checks. Writes nothing.
 *   --apply             Precondition checks, then transactional removal via
 *                       ProspectCrmAdmin::removeRecipientsByContact (the same
 *                       service the admin API uses), plus audit-log rows.
 *   --threads [id]      Re-run Gmail conversation matching for unresolved
 *                       follow-up recipients (default: the DSO follow-up
 *                       campaign). Never sends mail.
 *
 * Run inside Cloud Run (job): php scripts/marketing-campaign-separate.php --verify
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';
require_once __DIR__ . '/../api/marketing/ProspectCrm.php';
require_once __DIR__ . '/../api/marketing/ProspectCrmAdmin.php';
require_once __DIR__ . '/../api/marketing/MarketingMailer.php';
require_once __DIR__ . '/../api/marketing/OutreachEligibility.php';
require_once __DIR__ . '/../api/marketing/OutreachThreadResolver.php';
require_once __DIR__ . '/../api/marketing/CampaignSendSupport.php';
global $pdo, $appConfig;

/* ---------------- Operation constants ---------------- */

const SEP_CAMPAIGNS = [
    'dso_followup'  => 'bb4de308-9d4a-4d28-8b76-afeedc652a41',
    'dso_initial'   => '6df6a3be-07d0-4a0d-9304-3a9b9d6d4cf2',
    'orig_followup' => '39a54ddc-026d-4943-a6b2-0d0f5a882789',
    'orig_initial'  => '37793332-035a-41e6-a127-d281f7cdd022',
];

// All contacts leaving the original follow-up campaign. 1574 (Aspen Dental)
// and 1590 (The Smilist) are held for review and must NOT be in the DSO
// campaign - they are removed from the original only.
const SEP_FOLLOWUP_CONTACT_IDS = [
    1563, 1565, 1569, 1571, 1574, 1589, 1590, 1597, 1598, 1599,
    1608, 1610, 1611, 1612, 1647, 1652, 1653, 1655, 1656, 1657,
    1658, 1659, 1661, 1662,
];
const SEP_HELD_CONTACT_IDS = [1574, 1590];
const SEP_DSO_FOLLOWUP_CONTACT_IDS = [
    1563, 1565, 1569, 1571, 1589, 1597, 1598, 1599,
    1608, 1610, 1611, 1612, 1647, 1652, 1653, 1655, 1656, 1657,
    1658, 1659, 1661, 1662,
];
const SEP_INITIAL_CONTACT_IDS = [1778, 1811];

const SEP_ACTOR = 'cli:campaign-separation';
const SEP_THREAD_BATCH = 30;
const SEP_THREAD_MAX_PASSES = 20;

$mode = $argv[1] ?? '--verify';
if ($mode === '--threads') {
    $cid = $argv[2] ?? SEP_CAMPAIGNS['dso_followup'];
    sep_threads($pdo, $cid);
    exit(0);
}
if ($mode !== '--verify' && $mode !== '--apply') {
    echo "usage: php scripts/marketing-campaign-separate.php [--verify|--apply|--threads [campaign_id]]\n";
    exit(1);
}

/* ---------------- Shared helpers ---------------- */

function sep_campaign(PDO $pdo, string $id): ?array {
    $st = $pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function sep_counts(PDO $pdo, string $campaignId): array {
    $st = $pdo->prepare('SELECT status, COUNT(*) c FROM marketing_campaign_recipients WHERE campaign_id=? GROUP BY status');
    $st->execute([$campaignId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $out[$k] = (int)$v;
    return $out;
}

function sep_contact_ids(PDO $pdo, string $campaignId): array {
    $st = $pdo->prepare('SELECT DISTINCT contact_id FROM marketing_campaign_recipients WHERE campaign_id=? AND contact_id IS NOT NULL');
    $st->execute([$campaignId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function sep_missing_drafts(PDO $pdo, string $campaignId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=? AND (subject IS NULL OR subject='' OR body_html IS NULL OR body_html='')");
    $st->execute([$campaignId]);
    return (int)$st->fetchColumn();
}

function sep_flag_state(PDO $pdo, string $campaignId): array {
    $c = sep_campaign($pdo, $campaignId);
    return $c ? [
        'status' => $c['status'],
        'send_authorized' => $c['send_authorized'] ?? null,
        'send_requested' => $c['send_requested'] ?? null,
        'send_paused' => $c['send_paused'] ?? null,
        'approved_by' => $c['approved_by'] ?? null,
    ] : [];
}

/* ---------------- Verify ---------------- */

function sep_verify(PDO $pdo, bool $postApply = false): array {
    $report = ['campaigns' => [], 'checks' => [], 'all_campaigns' => [], 'post_apply' => $postApply];
    $problems = [];

    // All campaigns overview (identifies the completed 10-recipient one).
    foreach ($pdo->query('SELECT id,name,kind,status,recipient_count,send_authorized,send_requested,send_paused,approved_by,created_at FROM marketing_campaigns ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $report['all_campaigns'][] = $c;
    }

    foreach (SEP_CAMPAIGNS as $key => $cid) {
        $c = sep_campaign($pdo, $cid);
        if (!$c) { $report['campaigns'][$key] = ['id' => $cid, 'error' => 'not found']; $problems[] = "$key: campaign not found"; continue; }
        $actual = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($cid))->fetchColumn();
        $report['campaigns'][$key] = [
            'id' => $cid, 'name' => $c['name'], 'kind' => $c['kind'], 'status' => $c['status'],
            'recipient_count_col' => (int)$c['recipient_count'], 'actual_rows' => $actual,
            'status_breakdown' => sep_counts($pdo, $cid),
            'missing_drafts' => sep_missing_drafts($pdo, $cid),
            'flags' => sep_flag_state($pdo, $cid),
        ];
        if ((int)$c['recipient_count'] !== $actual) $problems[] = "$key: recipient_count column ($c[recipient_count]) != actual rows ($actual)";
    }

    $dsoFu = sep_contact_ids($pdo, SEP_CAMPAIGNS['dso_followup']);
    $dsoIn = sep_contact_ids($pdo, SEP_CAMPAIGNS['dso_initial']);
    $origFu = sep_contact_ids($pdo, SEP_CAMPAIGNS['orig_followup']);
    $origIn = sep_contact_ids($pdo, SEP_CAMPAIGNS['orig_initial']);

    sort($dsoFu); sort($dsoIn); sort($origFu); sort($origIn);

    // Expected DSO follow-up membership: the 22 transferred contacts.
    $expectedDsoFu = SEP_DSO_FOLLOWUP_CONTACT_IDS; sort($expectedDsoFu);
    $report['checks']['dso_followup_membership'] = [
        'expected' => count($expectedDsoFu), 'actual' => count($dsoFu),
        'missing_expected' => array_values(array_diff($expectedDsoFu, $dsoFu)),
        'unexpected_extra' => array_values(array_diff($dsoFu, $expectedDsoFu)),
    ];
    if ($report['checks']['dso_followup_membership']['missing_expected'] || $report['checks']['dso_followup_membership']['unexpected_extra']) {
        $problems[] = 'dso_followup membership does not match the expected 22 contacts';
    }
    foreach (SEP_HELD_CONTACT_IDS as $h) {
        if (in_array($h, $dsoFu, true)) $problems[] = "held contact $h present in DSO follow-up campaign";
    }

    $expectedDsoIn = SEP_INITIAL_CONTACT_IDS; sort($expectedDsoIn);
    $report['checks']['dso_initial_membership'] = [
        'expected' => count($expectedDsoIn), 'actual' => count($dsoIn),
        'missing_expected' => array_values(array_diff($expectedDsoIn, $dsoIn)),
        'unexpected_extra' => array_values(array_diff($dsoIn, $expectedDsoIn)),
    ];
    if ($report['checks']['dso_initial_membership']['missing_expected'] || $report['checks']['dso_initial_membership']['unexpected_extra']) {
        $problems[] = 'dso_initial membership does not match contacts 1778,1811';
    }

    // Original campaigns must currently contain every listed contact.
    $report['checks']['orig_followup_listed_present'] = [
        'expected' => count(SEP_FOLLOWUP_CONTACT_IDS),
        'present' => count(array_intersect(SEP_FOLLOWUP_CONTACT_IDS, $origFu)),
        'missing' => array_values(array_diff(SEP_FOLLOWUP_CONTACT_IDS, $origFu)),
    ];
    $report['checks']['orig_initial_listed_present'] = [
        'expected' => count(SEP_INITIAL_CONTACT_IDS),
        'present' => count(array_intersect(SEP_INITIAL_CONTACT_IDS, $origIn)),
        'missing' => array_values(array_diff(SEP_INITIAL_CONTACT_IDS, $origIn)),
    ];
    if (!$postApply) {
        if ($report['checks']['orig_followup_listed_present']['missing']) $problems[] = 'orig_followup missing listed contacts';
        if ($report['checks']['orig_initial_listed_present']['missing']) $problems[] = 'orig_initial missing listed contacts';
    }

    // Membership overlap between an original campaign and its DSO pair.
    // Pre-apply the transferred contacts are expected to sit in BOTH
    // campaigns (that is what the separation removes); post-apply any
    // overlap is a hard failure.
    $report['checks']['followup_overlap'] = array_values(array_intersect($origFu, $dsoFu));
    $report['checks']['initial_overlap'] = array_values(array_intersect($origIn, $dsoIn));
    sort($report['checks']['followup_overlap']);
    sort($report['checks']['initial_overlap']);
    $expectedFuOverlap = SEP_DSO_FOLLOWUP_CONTACT_IDS; sort($expectedFuOverlap);
    $expectedInOverlap = SEP_INITIAL_CONTACT_IDS; sort($expectedInOverlap);
    if (!$postApply) {
        if ($report['checks']['followup_overlap'] !== $expectedFuOverlap) {
            $problems[] = 'pre-apply follow-up overlap does not equal the expected 22 transferred contacts';
        }
        if ($report['checks']['initial_overlap'] !== $expectedInOverlap) {
            $problems[] = 'pre-apply intro overlap does not equal contacts 1778,1811';
        }
    } else {
        if ($report['checks']['followup_overlap'] !== [] || $report['checks']['initial_overlap'] !== []) {
            $problems[] = 'contact overlap remains between original and DSO campaigns';
        }
        $fuRows = sep_campaign_count($pdo, SEP_CAMPAIGNS['orig_followup']);
        $inRows = sep_campaign_count($pdo, SEP_CAMPAIGNS['orig_initial']);
        $report['checks']['final_counts'] = [
            'orig_followup' => $fuRows, 'orig_initial' => $inRows,
            'dso_followup' => sep_campaign_count($pdo, SEP_CAMPAIGNS['dso_followup']),
            'dso_initial' => sep_campaign_count($pdo, SEP_CAMPAIGNS['dso_initial']),
        ];
        if ($fuRows !== 246) $problems[] = "orig_followup has $fuRows rows, expected 246";
        if ($inRows !== 99) $problems[] = "orig_initial has $inRows rows, expected 99";
        if ($report['checks']['final_counts']['dso_followup'] !== 22) $problems[] = 'dso_followup row count != 22';
        if ($report['checks']['final_counts']['dso_initial'] !== 2) $problems[] = 'dso_initial row count != 2';
        // Listed contacts must be gone from the originals.
        $stillFu = array_values(array_intersect(SEP_FOLLOWUP_CONTACT_IDS, $origFu));
        $stillIn = array_values(array_intersect(SEP_INITIAL_CONTACT_IDS, $origIn));
        if ($stillFu || $stillIn) $problems[] = 'listed contacts still present in original campaigns';
    }

    // Expected post-removal counts.
    $report['checks']['expected_final_counts'] = [
        'orig_followup' => 'actual_rows - ' . count(SEP_FOLLOWUP_CONTACT_IDS),
        'orig_initial' => 'actual_rows - ' . count(SEP_INITIAL_CONTACT_IDS),
    ];

    $report['problems'] = $problems;
    $report['ok'] = $problems === [];
    return $report;
}

/* ---------------- Apply ---------------- */

function sep_apply(PDO $pdo): int {
    $report = sep_verify($pdo);
    echo "== pre-apply verify ==\n";
    foreach ($report['campaigns'] as $k => $c) {
        echo "  $k: " . ($c['name'] ?? '?') . " status={$c['status']} rows={$c['actual_rows']} missing_drafts={$c['missing_drafts']}\n";
    }
    if (!$report['ok']) {
        echo "PREFLIGHT FAILED - refusing to mutate:\n";
        foreach ($report['problems'] as $p) echo "  - $p\n";
        return 1;
    }

    $admin = new ProspectCrmAdmin($pdo, SEP_ACTOR);

    // Remove the 24 follow-up contacts from the original campaign.
    echo "\nRemoving " . count(SEP_FOLLOWUP_CONTACT_IDS) . " contacts from orig_followup...\n";
    $r1 = $admin->removeRecipientsByContact(SEP_CAMPAIGNS['orig_followup'], SEP_FOLLOWUP_CONTACT_IDS);
    echo "  removed={$r1['removed_count']} missing=" . json_encode($r1['missing_contact_ids']) . "\n";
    if ($r1['removed_count'] !== count(SEP_FOLLOWUP_CONTACT_IDS)) {
        echo "ABORT: expected " . count(SEP_FOLLOWUP_CONTACT_IDS) . " removals, got {$r1['removed_count']}\n";
        return 1;
    }

    echo "Removing " . count(SEP_INITIAL_CONTACT_IDS) . " contacts from orig_initial...\n";
    $r2 = $admin->removeRecipientsByContact(SEP_CAMPAIGNS['orig_initial'], SEP_INITIAL_CONTACT_IDS);
    echo "  removed={$r2['removed_count']} missing=" . json_encode($r2['missing_contact_ids']) . "\n";
    if ($r2['removed_count'] !== count(SEP_INITIAL_CONTACT_IDS)) {
        echo "ABORT: expected " . count(SEP_INITIAL_CONTACT_IDS) . " removals, got {$r2['removed_count']}\n";
        return 1;
    }

    // Audit rows for the bulk operation (service writes per-contact
    // prospect events; the campaign-level audit mirrors the admin API).
    $audit = $pdo->prepare('INSERT INTO marketing_audit_log (actor_email,action,campaign_id,detail) VALUES (?,?,?,?)');
    $audit->execute([SEP_ACTOR, 'campaign_recipients_remove', SEP_CAMPAIGNS['orig_followup'], json_encode([
        'requested' => count(SEP_FOLLOWUP_CONTACT_IDS), 'removed' => $r1['removed_count'],
        'missing_contact_ids' => $r1['missing_contact_ids'],
        'contact_ids' => SEP_FOLLOWUP_CONTACT_IDS,
        'operation' => 'dso_campaign_separation',
    ])]);
    $audit->execute([SEP_ACTOR, 'campaign_recipients_remove', SEP_CAMPAIGNS['orig_initial'], json_encode([
        'requested' => count(SEP_INITIAL_CONTACT_IDS), 'removed' => $r2['removed_count'],
        'missing_contact_ids' => $r2['missing_contact_ids'],
        'contact_ids' => SEP_INITIAL_CONTACT_IDS,
        'operation' => 'dso_campaign_separation',
    ])]);

    echo "\n== post-apply verify ==\n";
    $after = sep_verify($pdo, true);
    foreach (['orig_followup', 'orig_initial', 'dso_followup', 'dso_initial'] as $k) {
        $c = $after['campaigns'][$k] ?? null;
        if ($c) echo "  $k: rows={$c['actual_rows']} status={$c['status']} recipient_count_col={$c['recipient_count_col']} breakdown=" . json_encode($c['status_breakdown']) . "\n";
    }
    echo "overlap followup=" . json_encode($after['checks']['followup_overlap'])
        . " initial=" . json_encode($after['checks']['initial_overlap']) . "\n";
    echo "post-apply problems: " . ($after['problems'] === [] ? 'none' : json_encode($after['problems'])) . "\n";
    // Post-apply, originals no longer contain the listed contacts - the
    // 'listed_present' checks will now report missing, which is expected.
    // Recompute the meaningful postconditions directly.
    $fuRows = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote(SEP_CAMPAIGNS['orig_followup']))->fetchColumn();
    $inRows = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote(SEP_CAMPAIGNS['orig_initial']))->fetchColumn();
    echo "FINAL orig_followup rows=$fuRows (expect 246 if was 270) | orig_initial rows=$inRows (expect 99 if was 101)\n";
    echo "dso_followup rows=" . sep_campaign_count($pdo, SEP_CAMPAIGNS['dso_followup'])
        . " dso_initial rows=" . sep_campaign_count($pdo, SEP_CAMPAIGNS['dso_initial']) . "\n";
    return 0;
}

function sep_campaign_count(PDO $pdo, string $cid): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=?');
    $st->execute([$cid]);
    return (int)$st->fetchColumn();
}

/* ---------------- Thread recheck ---------------- */

function sep_threads(PDO $pdo, string $campaignId): void {
    global $appConfig;
    $camp = sep_campaign($pdo, $campaignId);
    if (!$camp) { echo "campaign not found\n"; exit(1); }
    if ($camp['kind'] !== 'followup') { echo "threading applies to follow-up campaigns only\n"; exit(1); }
    if (!in_array($camp['status'], ['draft', 'ready'], true)) { echo "campaign locked (status={$camp['status']})\n"; exit(1); }

    $crm = new ProspectCrm($pdo);
    $available = $crm->available();
    $mailer = new MarketingMailer($pdo, (string)($appConfig['baseUrl'] ?? ''));
    if (!$mailer->accessToken()) { echo "Gmail access token unavailable - cannot resolve threads\n"; exit(1); }

    $pendWhere = "campaign_id=? AND status IN ('draft','ready') AND (thread_status IS NULL OR thread_status<>'resolved')";
    $pendCount = function () use ($pdo, $pendWhere, $campaignId): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE $pendWhere");
        $st->execute([$campaignId]);
        return (int)$st->fetchColumn();
    };
    $totalPending = $pendCount();
    echo "campaign={$camp['name']} pending_thread_checks=$totalPending\n";

    for ($pass = 0; $pass < SEP_THREAD_MAX_PASSES; $pass++) {
        $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE $pendWhere ORDER BY id LIMIT " . SEP_THREAD_BATCH);
        $rs->execute([$campaignId]);
        $rows = $rs->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;

        // Notes per email for thread-id hints (same as resolve_threads action).
        $notesByEmail = [];
        if ($available) {
            $em = $pdo->prepare("SELECT prospect_email FROM marketing_campaign_recipients WHERE campaign_id=? AND status IN ('draft','ready')");
            $em->execute([$campaignId]);
            foreach ($crm->findByEmails($em->fetchAll(PDO::FETCH_COLUMN)) as $e => $pp) {
                $notesByEmail[$e] = (string)($pp['fields']['notes'] ?? '');
            }
        }

        $counts = [];
        foreach ($rows as $r) {
            $notes = $notesByEmail[SuppressionService::normalizeEmail((string)$r['prospect_email'])] ?? '';
            $resolver = new OutreachThreadResolver($pdo, [$mailer, 'gmailGet']);
            $t = $resolver->resolve((string)$r['prospect_email'],
                OutreachEligibility::gmailIdsFromNotes($notes), mo_ref($campaignId));
            $ok = $t['status'] === 'resolved';
            $sql = 'UPDATE marketing_campaign_recipients SET thread_status=?, thread_reason=?, thread_source=?,
                thread_subject=?, thread_date=?, thread_rfc_message_id=?, thread_references=?, gmail_thread_id=?';
            $args = [$t['status'], $t['reason'] !== '' ? substr($t['reason'], 0, 80) : null, $ok ? $t['source'] : null,
                $ok ? substr($t['subject'], 0, 500) : null, $ok ? substr((string)($t['date'] ?? ''), 0, 40) : null,
                $ok ? $t['rfc_message_id'] : null, $ok ? $t['references'] : null, $ok ? $t['thread_id'] : null];
            if ($ok) { $sql .= ', subject=?'; $args[] = substr($t['subject'], 0, 500); }
            $args[] = $r['id'];
            $pdo->prepare($sql . ' WHERE id=?')->execute($args);
            $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
        }
        echo "pass " . ($pass + 1) . ": " . json_encode($counts) . "\n";
    }

    $left = $pendCount();
    $resolved = (int)$pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($campaignId) . " AND thread_status='resolved'")->fetchColumn();
    echo "done: resolved=$resolved remaining_unresolved=$left\n";
}

/* ---------------- Run ---------------- */

if ($mode === '--verify') {
    $report = sep_verify($pdo);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($report['ok'] ? 0 : 1);
}
exit(sep_apply($pdo));
