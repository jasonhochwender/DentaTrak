<?php
// Static regression tests for the adaptive Performance Trends visualization.
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

$js = file_get_contents("$root/js/lab-insights.js");
$css = file_get_contents("$root/css/lab-insights.css");
$api = file_get_contents("$root/api/lab-performance-metrics.php");

// ── Section rename + description ────────────────────────────────────────
ok(strpos($js, 'trend_title') !== false, 'Performance Trends title key used');
ok(strpos($js, 'trend_desc') !== false, 'trend description key used');

// ── Metric selector: 3+ metrics, completed volume is bar ────────────────
ok(strpos($js, 'trend_completed_volume') !== false, 'completed-volume option');
ok(strpos($js, "value=\"completedCases\"") !== false, 'completedCases metric wired');
ok(strpos($js, "value=\"onTimePct\"") !== false, 'on-time metric kept');
ok(strpos($js, "value=\"turnaroundDays\"") !== false, 'turnaround default kept');
ok(strpos($js, "value=\"remakeRatePct\"") !== false, 'remake-rate metric kept');
ok(strpos($js, "kind: 'bar'") !== false, 'bar kind for volume');
ok(strpos($js, "kind: 'line'") !== false, 'line kind for rates/days');

// ── Adaptive states ─────────────────────────────────────────────────────
ok(strpos($js, 'li-trend-empty') !== false, 'empty state markup');
ok(strpos($js, 'li-trend-single') !== false, 'single-observation summary');
ok(strpos($js, 'trend_empty_title') !== false && strpos($js, 'trend_empty_body') !== false, 'empty-state i18n');
ok(strpos($js, 'trend_single_note') !== false, 'single-obs insufficiency note');
ok(strpos($js, 'obs === 0') !== false && strpos($js, 'obs === 1') !== false, 'adaptive branching on obs count');
ok(strpos($js, 'hasPositive') !== false, 'all-zero volume treated as empty');

// ── Missing vs zero distinction ─────────────────────────────────────────
ok(strpos($js, 'spanGaps: false') !== false, 'no misleading gap-spanning lines');
ok(strpos($js, 'isEmptyVal') !== false, 'null-vs-zero helper');
ok(strpos($js, 'max: isPct ? 100') !== false, 'on-time fixed 0-100 scale');
ok(strpos($js, 'min: isPct ? 0') !== false, 'on-time min 0');

// ── Granularity: week/month/quarter ─────────────────────────────────────
ok(strpos($js, 'liTrendGranularity') !== false, 'granularity state');
ok(strpos($js, 'gran_week') !== false && strpos($js, 'gran_month') !== false && strpos($js, 'gran_quarter') !== false, 'granularity options i18n');
ok(strpos($js, 'liTrendAutoGranularity') !== false, 'auto granularity resolution');
ok(strpos($js, 'trends.week') !== false, 'weekly series consumed');
ok(strpos($js, "'quarter'") !== false, 'quarterly aggregation');
ok(strpos($api, "'week'") !== false && strpos($api, 'labPerfBucketKey') !== false, 'backend emits week buckets');
ok(strpos($api, 'o-\\WW') !== false || strpos($api, "o-\\\\WW") !== false || strpos($api, 'o-') !== false, 'ISO week key format');
ok(strpos($api, 'onTimeOn') !== false && strpos($api, 'onTimeEligible') !== false, 'on-time counts retained for tooltips');
ok(strpos($api, '400 * 86400') !== false, 'week buckets capped to meaningful spans');

// ── Summary + previous-period comparison ────────────────────────────────
ok(strpos($js, 'liTrendSummary') !== false, 'compact summary element');
ok(strpos($js, 'trend_summary_label') !== false, 'summary period label');
ok(strpos($js, 'trend_based_on') !== false, 'sample-size context in summary');
ok(strpos($js, 'previousPeriod') !== false, 'previous-period benchmark used');
ok(strpos($js, 'trend_vs_prev') !== false, 'prev-period delta display');

// ── Tooltips with sample context ────────────────────────────────────────
ok(strpos($js, 'tooltipText') !== false, 'rich tooltip builder');
ok(strpos($js, 'trend_cases_n') !== false, 'turnaround tooltip sample size');
ok(strpos($js, 'onTimeOn') !== false || strpos($js, 'aux.on') !== false, 'on-time tooltip numerator');
ok(strpos($js, 'trend_events_n') !== false, 'remake tooltip event count');

// ── Chart sizing ────────────────────────────────────────────────────────
ok(strpos($css, '.li-trend-chart') !== false, 'trend chart css');
ok(strpos($css, 'height: 240px') !== false || strpos($css, 'height:240px') !== false, 'compact ~240px chart height');
ok(strpos($css, 'li-trend-chart--compact') !== false && strpos($css, 'li-trend-chart--summary') !== false, 'compact/summary height overrides');
ok(strpos($css, 'li-trend-empty') !== false && strpos($css, 'li-trend-single') !== false, 'state styles');

// ── Accessibility + preserved behavior ──────────────────────────────────
ok(strpos($js, 'setChartAriaLabel') !== false, 'chart aria label preserved');
ok(strpos($js, 'liTrendMetricSelect') !== false, 'metric selector preserved');
ok(strpos($js, 'liTrendGranularitySelect') !== false, 'granularity selector');
ok(strpos($js, 'renderTrendSummary') !== false, 'summary rendered before chart');

// ── i18n keys across all locales ────────────────────────────────────────
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
$required = ['trend_title','trend_desc','trend_granularity','gran_week','gran_month','gran_quarter',
  'trend_completed_volume','trend_summary_label','trend_vs_prev','trend_empty_title','trend_empty_body',
  'trend_single_label','trend_single_note',
  'trend_based_on_one','trend_based_on_other','trend_cases_n_one','trend_cases_n_other',
  'trend_events_n_one','trend_events_n_other'];
foreach ($locales as $loc) {
    $d = json_decode(file_get_contents("$root/locales/$loc.json"), true);
    $det = $d['insights']['perf']['detail'] ?? [];
    foreach ($required as $k) {
        ok(isset($det[$k]) && $det[$k] !== '', "$loc detail.$k");
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
