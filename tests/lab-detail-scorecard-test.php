<?php
// Static regression tests for the Lab Detail performance scorecard.
// Asserts structure, data wiring, navigation, i18n keys, and CSS without a DB.
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

$js = file_get_contents("$root/js/lab-insights.js");
$css = file_get_contents("$root/css/lab-insights.css");
$api = file_get_contents("$root/api/get-lab-insights.php");
$main = file_get_contents("$root/main.php");

// ── Structure: three logical groups ────────────────────────────────────
ok(strpos($js, 'li-scorecard') !== false, 'scorecard container rendered');
ok(strpos($js, "group_delivery") !== false, 'Delivery Performance group');
ok(strpos($js, "group_workload") !== false, 'Current Workload group');
ok(strpos($js, "group_quality") !== false, 'Quality & Reliability group');
ok(substr_count($js, 'li-scorecard-group-title') >= 3, 'three group titles');
ok(strpos($js, 'li-scorecard-grid') !== false, 'scorecard grid class');

// ── Profile header: lab name primary heading + workload summary ────────
ok(strpos($js, "d.name || t('insights.perf.detail.title')") !== false, 'lab name is primary heading');
ok(strpos($js, "isPractice ? '' : (d.isLive") !== false, 'removed-lab suffix preserved in subtitle');
ok(strpos($js, "'insights.perf.detail.based_on_range'") !== false, 'reporting-period context in subtitle');
ok(strpos($js, 'liRangeSelect') !== false, 'subtitle reads live range selector');
ok(strpos($js, "liSelectedLab === 'practice'") !== false, 'practice scope still supported');

// ── Delivery Performance metrics preserved ──────────────────────────────
foreach (['detail.completed_cases', 'summary.avg_turnaround', 'detail.median_turnaround',
          'summary.on_time', 'detail.avg_days_late'] as $k) {
    ok(strpos($js, "'insights.perf.$k'") !== false, "delivery metric: $k");
}
ok(strpos($js, 'tip_completed') !== false && strpos($js, 'tip_turnaround') !== false, 'delivery tooltips');
ok(strpos($js, 'tip_ontime') !== false, 'on-time tooltip');

// ── Current Workload group ──────────────────────────────────────────────
ok(strpos($js, 'active_associated') !== false, 'active-associated label (not "at lab")');
ok(strpos($js, 'scorecardStat') !== false, 'scorecardStat helper');
ok(strpos($js, "navTarget: 'lab_cases'") !== false, 'active cases -> board nav');
ok(strpos($js, "navTarget: 'late'") !== false, 'overdue -> board nav');
ok(strpos($js, "navTarget: 'appt_risk'") !== false, 'appt risk -> board nav');
ok(strpos($js, "navTarget: 'awaiting_ack'") !== false, 'awaiting ack -> board nav');
ok(strpos($js, 'li-cell-link-late') !== false, 'overdue restrained red accent');
// Overdue must reuse workload.late (same definition as comparison table)
ok(strpos($js, 'scorecardOverdue') !== false && strpos($js, 'wl.late') !== false, 'overdue reconciles with workload.late');
// Per-lab attention counts from API
ok(strpos($js, 'liAttnCountsByLab') !== false, 'per-lab attention counts stored');
ok(strpos($js, 'countsByLab') !== false, 'countsByLab consumed');

// ── Quality & Reliability group ─────────────────────────────────────────
ok(strpos($js, 'lab_remake_rate') !== false, 'lab-attributed remake rate preserved');
ok(strpos($js, 'workflow_regressions') !== false, 'workflow regressions metric');
ok(strpos($js, 'revisionCount') !== false && strpos($js, 'revisionRate') !== false, 'revision fields used');
ok(strpos($js, 'liDetailRemakes') !== false, 'remake detail anchor');
ok(strpos($js, 'liRevisionsSection') !== false, 'regressions anchor');
ok(strpos($js, 'remake_details_link') !== false && strpos($js, 'regressions_link') !== false, 'quality detail links');
ok(strpos($js, 'rateDenominator') !== false && strpos($js, 'rateSufficient') !== false, 'remake denominator/sufficiency preserved');

// ── Navigation wiring ───────────────────────────────────────────────────
ok(strpos($js, "getElementById('liLabDetailBody')") !== false, 'detail body click delegation bound');
ok(substr_count($js, 'li-cell-link') >= 4, 'cell-link handler(s) present');

// ── Backend payload ─────────────────────────────────────────────────────
ok(strpos($api, 'attentionByLab') !== false, 'api computes per-lab attention counts');
ok(strpos($api, "'countsByLab'") !== false, 'api emits countsByLab');
ok(strpos($api, "'appt_risk' => 0") !== false, 'counts include appt_risk');
ok(strpos($api, "'awaiting_ack' => 0") !== false, 'counts include awaiting_ack');
ok(strpos($api, "'due_soon' => 0") !== false, 'counts include due_soon');
ok(strpos($api, 'currentWorkloadLateCount') !== false, 'workload late count preserved');

// ── CSS: responsive grid ────────────────────────────────────────────────
ok(strpos($css, '.li-scorecard-grid') !== false, 'scorecard grid css');
ok(strpos($css, 'grid-template-columns: repeat(4') !== false, 'desktop 4-col grid');
ok(substr_count($css, 'li-scorecard-grid') >= 4, 'responsive breakpoints present');
ok(strpos($css, '.li-scorecard-links') !== false, 'quality links styled');

// ── Supporting sections preserved ───────────────────────────────────────
ok(strpos($js, 'liPerfTrendChart') !== false, 'trend chart preserved');
ok(strpos($js, 'liTrendMetricSelect') !== false, 'trend metric selector preserved');
ok(strpos($js, 'li-detail-block') !== false, 'detail blocks preserved');
ok(strpos($js, 'renderWorkloadDrilldown') !== false, 'workload drill-down preserved');
ok(strpos($js, 'liWorkloadByLab') !== false, 'workload rows preserved');

// ── i18n keys in all 9 locales ──────────────────────────────────────────
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
$required = ['group_delivery','group_workload','group_quality','active_associated',
  'based_on_range','workflow_regressions','remake_details_link','regressions_link',
  'tip_completed','tip_turnaround','tip_median','tip_ontime','tip_days_late',
  'tip_workload','tip_overdue','tip_appt_risk','tip_awaiting_ack',
  'tip_remake','tip_lab_attr','tip_regressions'];
foreach ($locales as $loc) {
    $d = json_decode(file_get_contents("$root/locales/$loc.json"), true);
    $det = $d['insights']['perf']['detail'] ?? [];
    foreach ($required as $k) {
        ok(isset($det[$k]) && $det[$k] !== '', "$loc detail.$k");
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
