<?php
/**
 * Lab Insights - Quality & Revisions tests.
 *
 * Covers the dedicated quality payload: remake event/case/open-resolved
 * accounting, reason & attribution breakdowns, unlinked events, workflow
 * regression events vs unique cases, transition breakdowns, period-window
 * lab attribution, monthly trends, per-lab/per-case-type scopes, and the
 * static contract of the API wiring, markup, JS renderer, CSS, and locales.
 *
 * buildLabQualityInsights() is a pure function - no database required.
 *
 * Run: php tests/lab-quality-test.php
 */

if (PHP_SAPI !== 'cli') { exit; }

require_once __DIR__ . '/../api/lab-quality.php';

$pass = 0; $fail = 0;
function assertTrue($c, $m) {
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS: $m\n"; }
    else { $fail++; echo "FAIL: $m\n"; }
}

$NOW = new DateTimeImmutable('2026-06-15 12:00:00');
$RANGE = new DateTimeImmutable('2026-01-01 00:00:00');

function perfFixture() {
    return [
        'practice' => [
            'volume' => ['uniqueCases' => 20],
            'openRemakes' => 1,
            'remakes' => [
                'total' => 4, 'casesWithRemakes' => 3, 'remakeRatePct' => 15.0,
                'rateDenominator' => 20, 'rateSufficient' => true,
                'multiRemakeCases' => 1, 'labAttributedCases' => 2,
                'labAttributedRatePct' => 10.0,
            ],
        ],
        'labs' => [[
            'labKey' => 'lab:1', 'name' => 'Lab A',
            'volume' => ['uniqueCases' => 12],
            'remakes' => [
                'total' => 3, 'casesWithRemakes' => 2, 'remakeRatePct' => 16.7,
                'rateDenominator' => 12, 'rateSufficient' => true,
                'multiRemakeCases' => 1, 'labAttributedCases' => 2,
                'labAttributedRatePct' => 16.7, 'openRemakes' => 1,
            ],
        ]],
    ];
}

function periodsFixture() {
    return [
        'lab:1' => [
            ['id' => 11, 'case_id' => 'C1', 'started_at' => '2026-02-01 00:00:00', 'ended_at' => '2026-03-01 00:00:00'],
            ['id' => 12, 'case_id' => 'C2', 'started_at' => '2026-02-10 00:00:00', 'ended_at' => null],
            ['id' => 13, 'case_id' => 'C3', 'started_at' => '2026-04-01 00:00:00', 'ended_at' => '2026-05-01 00:00:00'],
        ],
    ];
}

function casesFixture() {
    return [
        'C1' => ['case_type' => 'Crown'],
        'C2' => ['case_type' => 'Crown'],
        'C3' => ['case_type' => 'Denture'],
        'C4' => ['case_type' => 'Crown'],
    ];
}

function remakesFixture() {
    return [
        // C1: two events (multi-remake case), one open one resolved, lab:1 via period 11
        ['case_id' => 'C1', 'remake_number' => 1, 'reason_code' => 'fit_issue', 'attribution' => 'lab_related',
         'initiated_at' => '2026-02-15 10:00:00', 'completed_at' => '2026-02-20 10:00:00', 'lab_period_id' => 11],
        ['case_id' => 'C1', 'remake_number' => 2, 'reason_code' => 'shade_color', 'attribution' => 'practice_related',
         'initiated_at' => '2026-02-25 10:00:00', 'completed_at' => null, 'lab_period_id' => 11],
        // C3: unattributed lab (no period link), lab_related attribution, resolved
        ['case_id' => 'C3', 'remake_number' => 1, 'reason_code' => 'other', 'attribution' => 'lab_related',
         'initiated_at' => '2026-04-10 10:00:00', 'completed_at' => '2026-04-15 10:00:00', 'lab_period_id' => null],
        // C4: outside range
        ['case_id' => 'C4', 'remake_number' => 1, 'reason_code' => 'fit_issue', 'attribution' => 'unclear',
         'initiated_at' => '2025-06-01 10:00:00', 'completed_at' => null, 'lab_period_id' => 99],
    ];
}

function regressionsFixture() {
    return [
        'C1' => [
            ['at' => '2026-02-05 09:00:00', 'from' => 'designed', 'to' => 'originated'],
            ['at' => '2026-02-18 09:00:00', 'from' => 'designed', 'to' => 'originated'],
            ['at' => '2025-12-01 09:00:00', 'from' => 'manufactured', 'to' => 'designed'], // outside range
        ],
        'C2' => [
            ['at' => '2026-03-01 09:00:00', 'from' => 'manufactured', 'to' => 'designed'],
        ],
        'C4' => [
            ['at' => '2026-02-20 09:00:00', 'from' => 'designed', 'to' => 'originated'], // no period window match
        ],
    ];
}

$labNames = ['lab:1' => 'Lab A'];
$q = buildLabQualityInsights(
    perfFixture(), periodsFixture(), regressionsFixture(), remakesFixture(),
    casesFixture(), $labNames, $RANGE, $NOW
);

// ── Summary ──
assertTrue($q['summary']['remakeRatePct'] === 15.0, 'summary reuses perf remake rate');
assertTrue($q['summary']['remakeDenominator'] === 20, 'summary denominator from perf');
assertTrue($q['summary']['openRemakes'] === 1, 'summary open remakes from perf');
assertTrue($q['summary']['regressionEvents'] === 4, 'summary counts in-range regression events (3+1)');
assertTrue($q['summary']['regressionCases'] === 3, 'summary counts distinct regression cases');

// ── Remakes ──
$rm = $q['remakes'];
assertTrue($rm['events'] === 3, 'remake events exclude out-of-range event');
assertTrue($rm['cases'] === 2, 'remake cases = distinct cases (C1, C3)');
assertTrue($rm['open'] === 1, 'open remake = completed_at NULL');
assertTrue($rm['resolved'] === 2, 'resolved remakes counted');
assertTrue($rm['multiRemakeCases'] === 1, 'multi-remake case C1 detected');
assertTrue($rm['reasons']['fit_issue'] === 1 && $rm['reasons']['other'] === 1, 'reason breakdown');
assertTrue($rm['attributions']['lab_related'] === 2 && $rm['attributions']['practice_related'] === 1, 'attribution breakdown');
assertTrue($rm['unlinked'] === 1, 'unlinked remake (no lab_period_id) counted');
assertTrue(count($rm['list']) === 3, 'remake list contains in-range events only');
$rmC1 = null;
foreach ($rm['list'] as $r) { if ($r['caseId'] === 'C1' && $r['remakeNumber'] === 1) { $rmC1 = $r; } }
assertTrue($rmC1 && $rmC1['labKey'] === 'lab:1' && $rmC1['labName'] === 'Lab A', 'remake resolves lab via lab_period_id');
assertTrue($rmC1 && $rmC1['open'] === false, 'completed remake not open');
$rmC3 = null;
foreach ($rm['list'] as $r) { if ($r['caseId'] === 'C3') { $rmC3 = $r; } }
assertTrue($rmC3 && $rmC3['labKey'] === null, 'remake without period link stays unlinked');

// ── Regressions ──
$rg = $q['regressions'];
assertTrue($rg['events'] === 4, 'regression events in range');
assertTrue($rg['cases'] === 3, 'regression unique cases');
$transCounts = [];
foreach ($rg['transitions'] as $tr) { $transCounts[$tr['from'] . '>' . $tr['to']] = $tr['count']; }
assertTrue(($transCounts['designed>originated'] ?? 0) === 3, 'designed->originated counted 3x');
assertTrue(($transCounts['manufactured>designed'] ?? 0) === 1, 'manufactured->designed counted 1x');
$regC1 = null;
foreach ($rg['list'] as $r) { if ($r['caseId'] === 'C1') { $regC1 = $r; } }
assertTrue($regC1 && in_array('lab:1', $regC1['labKeys'], true), 'regression inside lab:1 period window attributed');
$regC4 = null;
foreach ($rg['list'] as $r) { if ($r['caseId'] === 'C4') { $regC4 = $r; } }
assertTrue($regC4 && count($regC4['labKeys']) === 0, 'regression outside all windows has no lab attribution');

// ── Trends ──
assertTrue(($q['trends']['remakeCases']['2026-02'] ?? 0) === 1, 'trend: Feb remake cases = 1 (C1)');
assertTrue(($q['trends']['remakeCases']['2026-04'] ?? 0) === 1, 'trend: Apr remake cases = 1 (C3)');
assertTrue(($q['trends']['labAttrCases']['2026-02'] ?? 0) === 1, 'trend: Feb lab-attributed remake cases');
assertTrue(($q['trends']['labAttrCases']['2026-04'] ?? 0) === 1, 'trend: Apr lab-attributed remake cases (attr is lab_related even unlinked)');
assertTrue(($q['trends']['regressionEvents']['2026-02'] ?? 0) === 3, 'trend: Feb regression events = 3');

// ── Scopes ──
assertTrue(isset($q['byLab']['lab:1']), 'byLab includes lab:1');
assertTrue($q['byLab']['lab:1']['remakeEvents'] === 3, 'byLab remake events from perf payload');
assertTrue($q['byLab']['lab:1']['regressionEvents'] === 3, 'byLab regression events via period windows');
assertTrue($q['byLab']['lab:1']['regressionCases'] === 2, 'byLab regression cases C1+C2');
assertTrue(isset($q['byCaseType']['Crown']) && $q['byCaseType']['Crown']['remakeEvents'] === 2, 'byCaseType Crown remake events');
assertTrue($q['byCaseType']['Denture']['regressionEvents'] === 0, 'byCaseType Denture has no regressions');

// ── Empty practice ──
$qEmpty = buildLabQualityInsights(
    ['practice' => ['volume' => ['uniqueCases' => 0], 'openRemakes' => 0,
                   'remakes' => ['total'=>0,'casesWithRemakes'=>0,'remakeRatePct'=>null,'rateDenominator'=>0,'rateSufficient'=>false,'multiRemakeCases'=>0,'labAttributedCases'=>0,'labAttributedRatePct'=>null]],
     'labs' => []],
    [], [], [], [], [], null, $NOW
);
assertTrue($qEmpty['summary']['regressionEvents'] === 0 && $qEmpty['remakes']['events'] === 0, 'empty practice yields zeroes not errors');
assertTrue($qEmpty['summary']['remakeRatePct'] === null, 'zero denominator -> null rate');

// ── Static contracts ──
$gli = file_get_contents(__DIR__ . '/../api/get-lab-insights.php');
assertTrue(strpos($gli, 'buildLabQualityInsights') !== false, 'get-lab-insights calls quality builder');
assertTrue(strpos($gli, "'quality' =>") !== false, 'get-lab-insights emits quality key');
assertTrue(strpos($gli, 'old_status, new_status') !== false, 'regression query selects transition statuses');
assertTrue(strpos($gli, 'INNER JOIN authorized_case_ids') !== false, 'remake fetch is permission-scoped');
assertTrue(strpos($gli, 'ensureAuthorizedCaseIdsTempTable') !== false, 'authorized temp table ensured');

$main = file_get_contents(__DIR__ . '/../main.php');
assertTrue(strpos($main, 'id="liQualitySection"') !== false, 'main.php has Quality & Revisions section');
assertTrue(strpos($main, 'insights.quality.title') !== false, 'section uses quality title key');
assertTrue(strpos($main, 'liQualityLab') !== false, 'section has lab scope select');
assertTrue(strpos($main, 'liRevisionsSection') === false, 'old revisions section removed');

$js = file_get_contents(__DIR__ . '/../js/lab-insights.js');
foreach (['renderQualitySection','liQualitySyncScope','liQualityScopeData','liQualityRenderTrend','liQualityRenderEvents','data-li-quality-case','openCaseById'] as $needle) {
    assertTrue(strpos($js, $needle) !== false, "lab-insights.js contains $needle");
}
assertTrue(strpos($js, 'renderRevisionsSection') === false, 'old revisions renderer removed');
assertTrue(strpos($js, '#liQualitySection') !== false, 'scorecard links to quality section');

$css = file_get_contents(__DIR__ . '/../css/analytics-pro.css');
assertTrue(strpos($css, '.li-quality-summary') !== false, 'CSS has quality summary grid');
assertTrue(strpos($css, '.li-quality-bar') !== false, 'CSS has breakdown bars');
assertTrue(strpos($css, '@media (max-width: 900px)') !== false, 'CSS has responsive breakpoint');

foreach (['de-DE','en-US','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'] as $loc) {
    $lj = json_decode(file_get_contents(__DIR__ . '/../locales/' . $loc . '.json'), true);
    $q = $lj['insights']['quality'] ?? null;
    assertTrue(is_array($q) && isset($q['title'], $q['subtitle'], $q['cases_n_one'], $q['cases_n_other'], $q['trend_remakeCases']), "locale $loc has insights.quality");
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
