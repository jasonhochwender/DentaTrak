<?php
/**
 * Backfill extra_fields.State for Hunter-imported CRM contacts that are
 * invisible to state-filtered ProspectCrm::search() (which filters on
 * extra_fields.State).
 *
 * Two modes:
 *
 *  1. Fixed batch (default): ids 1886-1945 with the verified per-batch
 *     state map. Contact 1904 (Statesboro Family Dental) is an explicit
 *     exception: its org location is only "Statesboro" but the practice
 *     is in Georgia.
 *
 *  2. Derive mode: --derive=START-END assigns each contact the state
 *     parsed from its organization's recorded location (trailing
 *     ", ST" segment, validated as a real US state/DC). Contacts whose
 *     location has no verifiable state suffix are reported for manual
 *     review and left untouched.
 *
 * Safety (both modes):
 *  - Dry-run by default; pass --apply to write.
 *  - Existing non-empty State values are never overwritten; the JSON
 *    merge preserves every other extra_fields key.
 *  - Idempotent and safe to rerun; apply re-reads each row and re-checks
 *    State before writing.
 *  - Sends no email and touches no campaign data.
 *
 * Usage (Cloud Run job / CLI):
 *   php scripts/marketing-crm-state-backfill.php [--apply]
 *   php scripts/marketing-crm-state-backfill.php --derive=1946-1996 [--apply]
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/ProspectCrm.php';
require_once __DIR__ . '/../api/marketing/ProspectCrmAdmin.php';
global $pdo;

$apply = in_array('--apply', $argv, true);
$derive = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--derive=(\d+)-(\d+)$/', $a, $m)) $derive = [(int)$m[1], (int)$m[2]];
}

$toWrite = []; $skipped = []; $conflicts = []; $manual = []; $targetStates = [];

if ($derive !== null) {
    // ---- derive mode: state comes from the org's recorded location ----
    [$lo, $hi] = $derive;
    $st = $pdo->prepare(
        "SELECT c.id, c.org_id, c.extra_fields, o.location, o.name AS org_name
         FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id = c.org_id
         WHERE c.id BETWEEN ? AND ? ORDER BY c.id"
    );
    $st->execute([$lo, $hi]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $expectedCount = $hi - $lo + 1;

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $ex = json_decode((string)($r['extra_fields'] ?? ''), true);
        $ex = is_array($ex) ? $ex : [];
        $existing = trim((string)($ex['State'] ?? ''));
        if ($existing !== '') {
            $skipped[] = [$id, 'already_has_state', $existing];
            $norm = ProspectCrmAdmin::usStateAbbrev($existing);
            if ($norm !== null) $targetStates[$norm] = true;
            continue;
        }

        $want = ProspectCrmAdmin::usStateFromLocation((string)($r['location'] ?? ''));
        if ($want === null) {
            // No verifiable state in the recorded location: manual review.
            $manual[] = [$id, $r['org_name'], $r['location']];
            continue;
        }
        $ex['State'] = $want;
        $toWrite[] = [$id, $want, $r['org_name'], $r['location']];
        $targetStates[$want] = true;
    }
    $expected = array_fill_keys(range($lo, $hi), true);
} else {
    // ---- fixed batch: verified per-range state map ----
    $expected = [];
    foreach (range(1886, 1906) as $id) $expected[$id] = 'GA';
    foreach (range(1907, 1918) as $id) $expected[$id] = 'NC';
    foreach (range(1919, 1935) as $id) $expected[$id] = 'SC';
    foreach (range(1936, 1945) as $id) $expected[$id] = 'TN';
    // Location lacks a state suffix but the practice is in Georgia.
    $locationExceptions = [1904 => 'GA'];

    $in = implode(',', array_fill(0, count($expected), '?'));
    $st = $pdo->prepare(
        "SELECT c.id, c.org_id, c.extra_fields, o.location, o.name AS org_name
         FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id = c.org_id
         WHERE c.id IN ($in) ORDER BY c.id"
    );
    $st->execute(array_keys($expected));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $want = $expected[$id];
        $targetStates[$want] = true;
        $ex = json_decode((string)($r['extra_fields'] ?? ''), true);
        $ex = is_array($ex) ? $ex : [];
        $existing = trim((string)($ex['State'] ?? ''));
        if ($existing !== '') { $skipped[] = [$id, 'already_has_state', $existing]; continue; }

        // Corroborate against the org's recorded location: a trailing
        // ", ST" segment must equal the expected state. Bare city names
        // (no state suffix) are only accepted for explicit exceptions.
        $loc = trim((string)($r['location'] ?? ''));
        $pos = strrpos($loc, ',');
        $locState = $pos === false ? null : ProspectCrm::normalizeUsState(trim(substr($loc, $pos + 1)));
        $ok = ($locState !== null && $locState === $want)
            || (isset($locationExceptions[$id]) && $locationExceptions[$id] === $want);
        if (!$ok) { $conflicts[] = [$id, $want, $loc]; continue; }

        $ex['State'] = $want;
        $toWrite[] = [$id, $want, $r['org_name'], $loc];
    }
    $expectedCount = count($expected);
}

echo 'mode: ' . ($apply ? 'APPLY' : 'dry-run') . ($derive ? " derive={$derive[0]}-{$derive[1]}" : ' fixed-batch') . "\n";
echo 'contacts in range found: ' . count($rows) . ' / ' . $expectedCount . "\n";
echo 'to update: ' . count($toWrite) . "\n";
echo 'skipped (existing State): ' . count($skipped) . "\n";
echo 'conflicts (location mismatch): ' . count($conflicts) . "\n";
echo 'manual review (no verifiable state): ' . count($manual) . "\n";
foreach ($conflicts as $c) echo "  CONFLICT #{$c[0]} want={$c[1]} location={$c[2]}\n";
foreach ($manual as $m) echo "  REVIEW #{$m[0]} org={$m[1]} location={$m[2]}\n";
$byState = [];
foreach ($toWrite as $w) $byState[$w[1]] = ($byState[$w[1]] ?? 0) + 1;
foreach ($byState as $s => $n) echo "  state $s: $n\n";
foreach (array_slice($toWrite, 0, 10) as $w) echo "  set #{$w[0]} State={$w[1]} ({$w[2]}, {$w[3]})\n";
if (count($toWrite) > 10) echo '  ... and ' . (count($toWrite) - 10) . " more\n";

if ($apply && $toWrite) {
    $upd = $pdo->prepare('UPDATE marketing_contacts SET extra_fields=?, updated_at=UTC_TIMESTAMP() WHERE id=?');
    // The State re-check below makes reruns and concurrent edits safe:
    // a row that gained a State since the scan is skipped, and the JSON
    // merge already preserved every other extra_fields key.
    $n = 0;
    $sel = $pdo->prepare('SELECT extra_fields FROM marketing_contacts WHERE id=?');
    foreach ($toWrite as $w) {
        $sel->execute([$w[0]]);
        $cur = $sel->fetchColumn();
        $curArr = json_decode((string)$cur, true);
        $curArr = is_array($curArr) ? $curArr : [];
        if (trim((string)($curArr['State'] ?? '')) !== '') continue; // re-check
        // Re-merge against the freshest extra_fields so concurrent edits
        // to other keys are preserved.
        $curArr['State'] = $w[1];
        $upd->execute([json_encode($curArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $w[0]]);
        $n += $upd->rowCount();
    }
    echo "updated: $n contact(s)\n";
}

// Post-run verification: state-filtered search must see the contacts.
if ($apply) {
    $crm = new ProspectCrm($pdo);
    // Fresh per-contact State values after the write.
    $stateById = [];
    if ($derive !== null) {
        [$lo, $hi] = $derive;
        $vs = $pdo->prepare("SELECT id, extra_fields FROM marketing_contacts WHERE id BETWEEN ? AND ?");
        $vs->execute([$lo, $hi]);
        foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $vr) {
            $exx = json_decode((string)($vr['extra_fields'] ?? ''), true);
            $stateById[(int)$vr['id']] = is_array($exx) ? trim((string)($exx['State'] ?? '')) : '';
        }
    }
    $wanted = $derive ? array_keys($targetStates) : ['GA', 'NC', 'SC', 'TN'];
    foreach ($wanted as $stt) {
        $r = $crm->search(['state' => $stt, 'per' => 100, 'page' => 1]);
        $found = 0;
        foreach ($r['rows'] as $p) {
            $id = (int)($p['id'] ?? 0);
            $hit = $derive
                ? (($stateById[$id] ?? '') === $stt)
                : (isset($expected[$id]) && $expected[$id] === $stt);
            if ($hit) $found++;
        }
        echo "search state=$stt total={$r['total']} target_ids_found=$found\n";
    }
}
echo "done\n";
