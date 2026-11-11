<?php
/**
 * Lab Insights - Case Aging & Bottlenecks tests.
 *
 * Static contract coverage for the dedicated aging section: the
 * current-state payload in get-lab-insights.php (distinct age
 * measurements, permission-scoped population, nullable timestamps),
 * the section markup, the JS renderer (buckets, stage distribution,
 * sortable details, case navigation), CSS, and locale coverage.
 *
 * The aging population intentionally reuses the Lab Case Health case
 * set (active, non-terminal, lab-associated, authorized) so totals
 * reconcile with that section; bucketing itself is client-side.
 *
 * Run: php tests/lab-aging-test.php
 */

if (PHP_SAPI !== 'cli') { exit; }

$pass = 0; $fail = 0;
function assertTrue($c, $m) {
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS: $m\n"; }
    else { $fail++; echo "FAIL: $m\n"; }
}

$gli = file_get_contents(__DIR__ . '/../api/get-lab-insights.php');

// ── Backend payload contract ──
assertTrue(strpos($gli, 'created_at, status_changed_at') !== false, 'workload query selects age timestamps');
assertTrue(strpos($gli, 'caseAllLabKeys') !== false, 'multi-lab case keys tracked');
assertTrue(strpos($gli, 'agingRisk') !== false, 'per-case risk captured for aging rows');
assertTrue(strpos($gli, 'stageTsFallback') !== false, 'stage-entry fallback from activity log');
assertTrue(strpos($gli, "event_type = 'status_changed'") !== false, 'stage fallback uses status_changed events');
assertTrue(strpos($gli, 'labSinceByCase') !== false, 'time-at-lab from open observed periods');
assertTrue(strpos($gli, "history_quality'] ?? '') !== 'observed'") !== false, 'lab-time uses observed periods only');
assertTrue(strpos($gli, "'aging' =>") !== false, 'response emits aging payload');
assertTrue(strpos($gli, 'ageDays') !== false && strpos($gli, 'stageAgeDays') !== false && strpos($gli, 'labDays') !== false,
    'distinct age measurements emitted');
assertTrue(strpos($gli, 'authorized_case_ids') !== false, 'aging population stays permission-scoped');
assertTrue(strpos($gli, 'labIsLate') !== false, 'overdue uses existing due-date rule');

// ── Markup contract ──
$main = file_get_contents(__DIR__ . '/../main.php');
assertTrue(strpos($main, 'id="liAgingSection"') !== false, 'main.php has aging section');
assertTrue(strpos($main, 'insights.aging.title') !== false, 'section uses aging title key');
assertTrue(strpos($main, 'id="liAgingLab"') !== false, 'section has lab scope select');
assertTrue(strpos($main, 'id="liAgingMeasure"') !== false, 'section has measure selector');
assertTrue(strpos($main, 'insights.aging.subtitle') !== false, 'section has supporting description');
// Aging sits in the perf layout before Lab Detail (near workload info).
$agingPos = strpos($main, 'id="liAgingSection"');
$detailPos = strpos($main, 'id="liLabDetail"');
assertTrue($agingPos !== false && $detailPos !== false && $agingPos < $detailPos, 'aging section positioned before Lab Detail');

// ── JS contract ──
$js = file_get_contents(__DIR__ . '/../js/lab-insights.js');
foreach (['renderAgingSection','liAgingSyncScope','liAgingPopulateScope','liAgingFilteredCases',
          'liAgingMeasureValue','liAgingRiskLabel','LI_AGING_BUCKETS','workflowStageOrder',
          'data-li-aging-case','openCaseById','liAgingStageSort','liAgingCaseSort'] as $needle) {
    assertTrue(strpos($js, $needle) !== false, "lab-insights.js contains $needle");
}
assertTrue(strpos($js, 'liAging = data.aging') !== false, 'aging payload stored on load');
assertTrue(strpos($js, 'renderAgingSection();') !== false, 'aging rendered in perf layout');
// Bucket boundaries: 0-7, 8-14, 15-30, 31+
assertTrue(strpos($js, 'max: 7') !== false && strpos($js, 'max: 14') !== false
    && strpos($js, 'max: 30') !== false && strpos($js, 'max: null') !== false,
    'aging buckets match spec boundaries');
// Overdue kept distinct from age: overdue suffix + separate daysLate sort.
assertTrue(strpos($js, 'bucket_overdue') !== false, 'buckets show overdue counts');
assertTrue(strpos($js, 'liAgingIsOverdue') !== false, 'overdue evaluated separately from age');
assertTrue(strpos($js, 'daysLate') !== false, 'days-late sort supported');
// Missing timestamps stay null, never zero.
assertTrue(strpos($js, 'stageAgeDays === null') !== false || strpos($js, 'stageAgeDays === null') !== false,
    'missing stage age renders N/A');

// ── CSS contract ──
$css = file_get_contents(__DIR__ . '/../css/analytics-pro.css');
assertTrue(strpos($css, '.li-aging-overdue') !== false, 'CSS has overdue styling');
assertTrue(strpos($css, '.li-quality-block-head') !== false, 'CSS has block head layout');

// ── Locale contract ──
foreach (['de-DE','en-US','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'] as $loc) {
    $lj = json_decode(file_get_contents(__DIR__ . '/../locales/' . $loc . '.json'), true);
    $a = $lj['insights']['aging'] ?? null;
    assertTrue(is_array($a) && isset($a['title'], $a['subtitle'], $a['measure_age'],
        $a['bucket_31'], $a['col_stage_age'], $a['days_late_n_one'], $a['days_late_n_other'],
        $a['view_all']), "locale $loc has insights.aging");
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
