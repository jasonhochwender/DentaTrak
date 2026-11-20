<?php
/**
 * Backfill extra_fields.State for the 60 Hunter-imported CRM contacts
 * (ids 1886-1945, imported 2026-11-19). ProspectCrm::search() filters
 * state via extra_fields.State, so these contacts were invisible to
 * state-filtered searches.
 *
 * Safety:
 *  - Dry-run by default; pass --apply to write.
 *  - Only touches the exact id range; every row is re-validated against
 *    its organization's recorded location (trailing ", ST" must match
 *    the expected state). Contact 1904 (Statesboro Family Dental) is an
 *    explicit exception: its org location is only "Statesboro" but the
 *    practice is in Georgia.
 *  - Idempotent: contacts that already carry a non-empty State are
 *    skipped; the JSON merge preserves every other extra_fields key.
 *  - Sends no email and touches no campaign data.
 *
 * Usage (Cloud Run job / CLI):
 *   php scripts/marketing-crm-state-backfill.php [--apply]
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/ProspectCrm.php';
require_once __DIR__ . '/../api/marketing/ProspectCrmAdmin.php';
global $pdo;

$apply = in_array('--apply', $argv, true);

// Expected state per the verified import batches.
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

$toWrite = []; $skipped = []; $conflicts = [];
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $want = $expected[$id];
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
    $toWrite[] = [$id, $want, json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

echo 'mode: ' . ($apply ? 'APPLY' : 'dry-run') . "\n";
echo 'contacts in range found: ' . count($rows) . ' / ' . count($expected) . "\n";
echo 'to update: ' . count($toWrite) . "\n";
echo 'skipped (existing State): ' . count($skipped) . "\n";
echo 'conflicts (location mismatch): ' . count($conflicts) . "\n";
foreach ($conflicts as $c) echo "  CONFLICT #{$c[0]} want={$c[1]} location={$c[2]}\n";
foreach (array_slice($toWrite, 0, 10) as $w) echo "  set #{$w[0]} State={$w[1]}\n";
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
    foreach (['GA', 'NC', 'SC', 'TN'] as $stt) {
        $r = $crm->search(['state' => $stt, 'per' => 100, 'page' => 1]);
        $ids = [];
        foreach ($r['rows'] as $p) { $id = (int)($p['id'] ?? 0); if (isset($expected[$id]) && $expected[$id] === $stt) $ids[] = $id; }
        echo "search state=$stt total={$r['total']} target_ids_found=" . count($ids) . "\n";
    }
}
echo "done\n";
