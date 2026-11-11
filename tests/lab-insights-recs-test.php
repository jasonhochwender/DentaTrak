<?php
/**
 * Lab Insights & Recommendations tests.
 *
 * Covers the actionable-insight layer added on top of the deterministic
 * rule engine: current-state insight types fed by the attention
 * classification, kind/action/context/rec_key decoration, thresholds,
 * rollup vs per-lab emission, and the static contract of the lifecycle
 * endpoint + merged payload in get-lab-insights.php.
 *
 * Run: php tests/lab-insights-recs-test.php
 */

if (PHP_SAPI !== 'cli') { exit; }

require_once __DIR__ . '/../api/lab-recommendations.php';

$pass = 0; $fail = 0;
function assertTrue($c, $m) {
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS: $m\n"; }
    else { $fail++; echo "FAIL: $m\n"; }
}

const MIN = 5;

function makeLab2(array $o = []) {
    return array_replace_recursive([
        'labKey' => 'lab:1', 'type' => 'lab', 'entityId' => 1, 'name' => 'Lab A', 'isLive' => true,
        'volume' => ['uniqueCases' => 20, 'engagements' => 20],
        'completed' => ['uniqueCases' => 18, 'engagements' => 18],
        'turnaround' => ['avgDays' => 8.0, 'medianDays' => 7.5, 'n' => 18, 'sufficient' => true],
        'onTime' => ['onTime' => 16, 'late' => 2, 'n' => 18, 'pct' => 88.9,
                     'dueDateCoveragePct' => 90.0, 'sufficient' => true],
        'workload' => ['openCases' => 5, 'openPeriods' => 5, 'currentlyAssigned' => 5,
                       'late' => 0, 'lateRatePct' => 0, 'dueDateCoveragePct' => 100],
        'caseTypes' => [],
        'remakes' => ['total' => 0, 'casesWithRemakes' => 0, 'remakeRatePct' => 0.0,
                      'rateDenominator' => 20, 'rateSufficient' => true,
                      'multiRemakeCases' => 0, 'multiRemakeRatePct' => null,
                      'labAttributedCases' => 0, 'labAttributedRatePct' => 0.0,
                      'avgDurationDays' => null, 'durationN' => 0,
                      'reasons' => [], 'attributions' => [], 'unlinked' => 0],
        'coverage' => ['dueDatePct' => 90, 'caseTypePct' => 95,
                       'turnaroundPct' => 90, 'remakeLinkagePct' => null],
        'benchmarks' => [
            'vsPractice' => [
                'avgTurnaroundDays' => ['lab' => 8.0, 'practice' => 8.0, 'delta' => 0.0, 'sufficient' => true],
                'onTimePct' => ['lab' => 88.9, 'practice' => 88.9, 'delta' => 0.0, 'sufficient' => true],
                'remakeRatePct' => ['lab' => 0.0, 'practice' => 0.0, 'delta' => 0.0, 'sufficient' => true],
                'labAttrRemakeRatePct' => ['lab' => 0.0, 'practice' => 0.0, 'delta' => 0.0, 'sufficient' => true],
            ],
            'previousPeriod' => [
                'volumeUniqueCases' => ['current' => 20, 'previous' => 20, 'delta' => 0],
                'avgTurnaroundDays' => ['current' => 8.0, 'previous' => 8.0, 'delta' => 0.0],
                'onTimePct' => ['current' => 88.9, 'previous' => 88.9, 'delta' => 0.0],
                'remakeRatePct' => ['current' => 0.0, 'previous' => 0.0, 'delta' => 0.0],
            ],
            'caseTypes' => [],
        ],
    ], $o);
}

function makePerf2(array $labs) {
    return [
        'range' => ['start' => '2025-01-01', 'end' => '2025-07-01', 'unbounded' => false],
        'population' => ['uniqueLabCases' => 40, 'totalPeriods' => 40, 'demoCases' => 0, 'openRemakes' => 0],
        'practice' => ['volume' => ['uniqueCases' => 40], 'remakes' => ['total' => 0]],
        'labs' => $labs,
        'trends' => ['bucket' => 'month', 'months' => [], 'series' => []],
        'meta' => ['minSampleSize' => MIN],
    ];
}

function findT(array $r, $type) {
    foreach ($r['items'] as $it) { if ($it['type'] === $type) { return $it; } }
    return null;
}
function hasT(array $r, $type) {
    return findT($r, $type) !== null;
}

// ── 1. Decoration contract on every item ────────────────────────────────
$r = buildLabRecommendations(makePerf2([makeLab2()]));
$ok = true;
foreach ($r['items'] as $it) {
    foreach (['kind', 'action', 'context', 'rec_key'] as $f) {
        if (!array_key_exists($f, $it)) { $ok = false; }
    }
    if (strpos($it['rec_key'], 'lab|') !== 0) { $ok = false; }
}
assertTrue($ok, 'every item carries kind/action/context/rec_key with lab| prefix');

// ── 2. kind classification ──────────────────────────────────────────────
$obs = findT($r, 'no_remakes_practice');
assertTrue($obs && $obs['kind'] === 'observation', 'no-remakes practice rollup is an observation');
$att = buildLabRecommendations(makePerf2([makeLab2([
    'workload' => ['openCases' => 9, 'late' => 4],
])]));
$late = findT($att, 'late_workload_lab');
assertTrue($late && $late['kind'] === 'insight' && $late['severity'] === 'attention',
    'late workload is an actionable insight');

// ── 3. Action targets ───────────────────────────────────────────────────
assertTrue($late['action']['type'] === 'cases' && $late['action']['filter'] === 'late'
    && $late['action']['labKey'] === 'lab:1', 'late workload action drills to late lab cases');
assertTrue($obs['action']['type'] === 'none', 'positive observation has no forced action');
$turn = buildLabRecommendations(makePerf2([makeLab2([
    'benchmarks' => ['previousPeriod' => [
        'avgTurnaroundDays' => ['current' => 10.1, 'previous' => 8.2, 'delta' => 1.9]],
    ],
])]));
$tr = findT($turn, 'turnaround_slower_period');
assertTrue($tr && $tr['action']['type'] === 'perf' && $tr['action']['labKey'] === 'lab:1',
    'metric insight action opens lab performance detail');
assertTrue($tr['context'] === 'period' && $late['context'] === 'current',
    'period comparisons labeled period; workload labeled current');

// ── 4. Current-state insight: appointment risk ──────────────────────────
$ctx = ['attentionByLab' => ['lab:1' => ['appt_risk' => 2, 'awaiting_ack' => 0, 'due_soon' => 0]],
        'labNames' => ['lab:1' => 'Lab A']];
$r = buildLabRecommendations(makePerf2([makeLab2()]), $ctx);
$ap = findT($r, 'insight_appt_risk');
assertTrue($ap && $ap['scope'] === 'lab' && $ap['labKey'] === 'lab:1'
    && $ap['params']['count'] === 2 && $ap['severity'] === 'attention'
    && $ap['context'] === 'current', 'single-lab appt risk surfaces as lab-scoped current insight');
assertTrue($ap['action']['filter'] === 'appt_risk', 'appt risk action targets the appt_risk board filter');

// Two labs affected -> one practice rollup, no per-lab duplicates.
$ctx2 = ['attentionByLab' => [
    'lab:1' => ['appt_risk' => 1, 'awaiting_ack' => 0, 'due_soon' => 0],
    'lab:2' => ['appt_risk' => 2, 'awaiting_ack' => 0, 'due_soon' => 0]],
    'labNames' => ['lab:1' => 'Lab A', 'lab:2' => 'Lab B']];
$two = [makeLab2(), makeLab2(['labKey' => 'lab:2', 'entityId' => 2, 'name' => 'Lab B'])];
$r = buildLabRecommendations(makePerf2($two), $ctx2);
assertTrue(hasT($r, 'insight_appt_risk_practice') && !hasT($r, 'insight_appt_risk'),
    'two affected labs roll up into one practice insight');
$pr = findT($r, 'insight_appt_risk_practice');
assertTrue($pr && $pr['params']['count'] === 3 && $pr['params']['labs'] === 2
    && $pr['action']['labKey'] === null, 'practice rollup carries totals and unscoped action');

// ── 5. Thresholds ───────────────────────────────────────────────────────
// awaiting_ack requires >=2 cases at one lab.
$ctx1 = ['attentionByLab' => ['lab:1' => ['appt_risk' => 0, 'awaiting_ack' => 1, 'due_soon' => 0]],
         'labNames' => ['lab:1' => 'Lab A']];
$r = buildLabRecommendations(makePerf2([makeLab2()]), $ctx1);
assertTrue(!hasT($r, 'insight_awaiting_ack'), 'single awaiting-ack case below threshold');
$ctx1['attentionByLab']['lab:1']['awaiting_ack'] = 2;
$r = buildLabRecommendations(makePerf2([makeLab2()]), $ctx1);
assertTrue(hasT($r, 'insight_awaiting_ack'), 'repeated awaiting-ack surfaces');
$ack = findT($r, 'insight_awaiting_ack');
assertTrue($ack['severity'] === 'watch' && $ack['action']['filter'] === 'awaiting_ack',
    'awaiting-ack is watch severity with ack filter action');

// due_soon requires >=2.
$ctx1['attentionByLab']['lab:1'] = ['appt_risk' => 0, 'awaiting_ack' => 0, 'due_soon' => 1];
$r = buildLabRecommendations(makePerf2([makeLab2()]), $ctx1);
assertTrue(!hasT($r, 'insight_due_soon'), 'single due-soon case below threshold');
$ctx1['attentionByLab']['lab:1']['due_soon'] = 3;
$r = buildLabRecommendations(makePerf2([makeLab2()]), $ctx1);
assertTrue(hasT($r, 'insight_due_soon'), 'repeated due-soon surfaces');

// No $current arg -> no current-state insights (backward compatible).
$r = buildLabRecommendations(makePerf2([makeLab2()]));
assertTrue(!hasT($r, 'insight_appt_risk') && !hasT($r, 'insight_awaiting_ack')
    && !hasT($r, 'insight_due_soon'), 'no attention context -> no current-state insights');

// ── 6. labNames fallback ────────────────────────────────────────────────
$ctx3 = ['attentionByLab' => ['lab:9' => ['appt_risk' => 1, 'awaiting_ack' => 0, 'due_soon' => 0]],
         'labNames' => []];
$r = buildLabRecommendations(makePerf2([makeLab2()]), $ctx3);
$ap = findT($r, 'insight_appt_risk');
assertTrue($ap && $ap['labName'] === 'lab:9', 'missing lab name falls back to labKey');

// ── 7. Static contract: endpoint + payload wiring ───────────────────────
$ep = file_get_contents(__DIR__ . '/../api/lab-recommendation-state.php');
assertTrue(strpos($ep, "REQUEST_METHOD'] !== 'POST'") !== false, 'state endpoint is POST-only');
assertTrue(strpos($ep, 'requireCsrfToken()') !== false, 'state endpoint requires CSRF');
assertTrue(strpos($ep, 'canViewAnalytics') !== false, 'state endpoint requires analytics permission');
assertTrue(strpos($ep, "strpos(\$recKey, 'lab|')") !== false, 'state endpoint only accepts lab| keys');
assertTrue(strpos($ep, 'aiRecSetState') !== false, 'state endpoint persists via shared store');

$gi = file_get_contents(__DIR__ . '/../api/get-lab-insights.php');
assertTrue(strpos($gi, 'attentionByLab') !== false && strpos($gi, 'buildLabRecommendations($performance, [') !== false,
    'get-lab-insights feeds attention context into recommendation builder');
assertTrue(strpos($gi, 'aiRecMerge(') !== false, 'get-lab-insights merges lifecycle states');
assertTrue(strpos($gi, "'status'") !== false && strpos($gi, "'computed_at'") !== false,
    'recommendations payload carries status + computed_at envelope');

// ── 8. i18n coverage for new types + labrecs chrome ────────────────────
$types = [];
preg_match_all("/labRec\\('([a-z_]+)'/", file_get_contents(__DIR__ . '/../api/lab-recommendations.php'), $tm);
$types = array_unique($tm[1]);
// Mirrors LI_RECS_PLURAL_TYPES in js/lab-insights.js - count-based
// templates need one/other/few/many forms; everything else is singular.
$pluralTypes = ['multi_remake', 'late_workload_lab', 'late_workload_practice',
    'insight_appt_risk', 'insight_appt_risk_practice',
    'insight_awaiting_ack', 'insight_awaiting_ack_practice',
    'insight_due_soon', 'insight_due_soon_practice'];
$needMsg = [];
foreach ($types as $ty) {
    if (in_array($ty, $pluralTypes, true)) {
        foreach (['one','other','few','many'] as $f) { $needMsg[] = $ty . '_' . $f; }
    } else {
        $needMsg[] = $ty;
    }
}
$needHead = $types;

$chrome = ['title','subtitle','scope_label','scope_all','state_active','state_all',
    'summary_active_one','summary_active_other','summary_labs_one','summary_labs_other',
    'observed','why_label','context_current','context_period','action_cases_late',
    'action_cases_awaiting_ack','action_perf','positive_title','empty_active',
    'unavailable','insufficient','hide_details','update_failed','detail_meta',
    'metric_current','metric_count','why'];

foreach (glob(__DIR__ . '/../locales/*.json') as $f) {
    $loc = basename($f);
    $j = json_decode((string)@file_get_contents($f), true);
    $missing = [];
    foreach ($needMsg as $k) {
        if (!isset($j['insights']['recs']['msg'][$k])) { $missing[] = 'msg.' . $k; }
    }
    foreach ($needHead as $k) {
        if (!isset($j['insights']['recs']['heading'][$k])) { $missing[] = 'heading.' . $k; }
    }
    foreach ($chrome as $k) {
        if (!isset($j['insights']['labrecs'][$k])) { $missing[] = 'labrecs.' . $k; }
    }
    // why explanations for every emitted type
    foreach ($types as $ty) {
        if (!isset($j['insights']['labrecs']['why'][$ty])) { $missing[] = 'why.' . $ty; }
    }
    assertTrue(empty($missing), "$loc lab-recs i18n complete" . (empty($missing) ? '' : ' missing: ' . implode(',', array_slice($missing,0,5))));
}

// ── 9. Frontend wiring ──────────────────────────────────────────────────
$js = file_get_contents(__DIR__ . '/../js/lab-insights.js');
foreach (['lab-recommendation-state.php', 'liRecSetState', 'renderRecCard',
          'liRecSyncScope', 'recFilteredItems', 'liRecsPayload'] as $needle) {
    assertTrue(strpos($js, $needle) !== false, "lab-insights.js contains $needle");
}
$main = file_get_contents(__DIR__ . '/../main.php');
foreach (['liRecScope', 'liRecStateChips', 'liRecsSummary', 'liRecsPositive',
          'insights.labrecs.title'] as $needle) {
    assertTrue(strpos($main, $needle) !== false, "main.php contains $needle");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
