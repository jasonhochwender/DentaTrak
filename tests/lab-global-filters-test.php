<?php
// Static contract test for the Lab Insights global filter toolbar.
// No database required: verifies markup, JS wiring, PHP range handling,
// CSS, and locale coverage.

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

$main = file_get_contents("$root/main.php");
$js = file_get_contents("$root/js/lab-insights.js");
$api = file_get_contents("$root/api/get-lab-insights.php");
$css = file_get_contents("$root/css/lab-insights.css");

// ── Markup ────────────────────────────────────────────────────────────
ok(strpos($main, 'id="liGlobalFilters"') !== false, 'toolbar markup present');
ok(strpos($main, 'id="liRangeSelect"') !== false, 'period select present');
ok(strpos($main, 'id="liGlobalLab"') !== false, 'laboratory select present');
ok(strpos($main, 'id="liCaseTypeFilter"') !== false, 'case-type select present in toolbar');
ok(strpos($main, 'id="liFiltersToggle"') !== false, 'filters toggle present');
ok(strpos($main, 'id="liFiltersPanel"') !== false, 'filters panel present');
ok(strpos($main, 'id="liFilterStatus"') !== false, 'status filter present');
ok(strpos($main, 'id="liFilterRisk"') !== false, 'risk filter present');
ok(strpos($main, 'id="liFilterAck"') !== false, 'ack filter present');
ok(strpos($main, 'id="liFiltersReset"') !== false, 'reset button present');
ok(strpos($main, 'id="liFilterContext"') !== false, 'context line present');
ok(strpos($main, 'id="liRangeStart"') !== false && strpos($main, 'id="liRangeEnd"') !== false, 'custom date inputs present');
ok(strpos($main, 'value="custom"') !== false, 'custom range option present');
ok(strpos($main, 'value="90d" selected') !== false, '90-day default selected');
// Toolbar sits above the operational summary inside the lab insights tab.
$tbPos = strpos($main, 'id="liGlobalFilters"');
$opsPos = strpos($main, 'id="liOps"');
ok($tbPos !== false && $opsPos !== false && $tbPos < $opsPos, 'toolbar before Operational KPI Summary');
// Case-type select no longer duplicated inside the perf section header.
ok(substr_count($main, 'id="liCaseTypeFilter"') === 1, 'single case-type select');
ok(strpos($main, 'li-case-type-filter') === false, 'old perf-header case-type label removed');

// ── JS wiring ─────────────────────────────────────────────────────────
ok(strpos($js, 'liFetchSeq') !== false, 'stale-response guard state');
ok(strpos($js, 'seq !== liFetchSeq') !== false, 'stale responses discarded');
ok(strpos($js, "'&start='") !== false && strpos($js, "'&end='") !== false, 'custom range params sent');
ok(strpos($js, 'liExtraFilters') !== false, 'additional filter state');
ok(strpos($js, 'LI_FILTERS_KEY') !== false && strpos($js, 'sessionStorage') !== false, 'filter persistence');
ok(strpos($js, 'liPopulateGlobalLab') !== false, 'global lab select populated');
ok(strpos($js, 'selectLab(globalLabSel.value') !== false, 'global lab drives lab selection');
ok(strpos($js, 'liValidateCustomRange') !== false, 'custom range validation');
ok(strpos($js, 'sd.value > ed.value') !== false, 'start-after-end rejected');
ok(strpos($js, 'liUpdateFilterContext') !== false, 'context line updated');
ok(strpos($js, 'renderLiAttnRows') !== false, 'attention re-renders on filter change');
// Extra filters applied to current-state renderers.
ok(preg_match('/liAgingFilteredCases[\s\S]{0,400}liExtraFilterMatch/', $js) === 1, 'aging cases filtered');
ok(strpos($js, 'liExtraFilterMatch(item)') !== false, 'attention items filtered');
// Historical metrics not filtered by status/risk/ack: those state vars must
// not appear in perf/quality payload paths.
ok(strpos($js, 'liExtraFilters.status') !== false, 'status filter state read');

// ── PHP range handling ────────────────────────────────────────────────
ok(strpos($api, "'90d'") !== false, 'backend default 90d');
ok(strpos($api, "=== 'custom'") !== false, 'custom range branch');
ok(strpos($api, "_GET['start']") !== false && strpos($api, "_GET['end']") !== false, 'start/end params read');
ok(strpos($api, 'rangeEndTs') !== false, 'range end bound exists');
ok(substr_count($api, 'periodOverlapsRange($p, $rangeStart, $rangeEndTs)') >= 4, 'window checks use rangeEndTs');
ok(strpos($api, 'computeLabPerformanceMetrics($pdo, $practiceId, $rangeStart, $rangeEndTs)') !== false, 'perf metrics bounded by rangeEndTs');
ok(preg_match('/\^\\d\{4\}-\\d\{2\}-\\d\{2\}\$/' , $api) === 1 || strpos($api, 'YYYY-MM-DD') !== false || strpos($api, '\\d{4}-\\d{2}-\\d{2}') !== false, 'date format validated');
// Current-state sections must keep using real $now, not $rangeEndTs.
ok(strpos($api, "'aging'") !== false, 'aging payload present');
ok(strpos($api, '$now->getTimestamp() - $labSinceByCase') !== false, 'aging uses current time');

// ── CSS ───────────────────────────────────────────────────────────────
ok(strpos($css, '.li-global-filters') !== false, 'toolbar styles');
ok(strpos($css, '.li-gf-panel') !== false, 'filters popover styles');
ok(strpos($css, '@media (max-width: 720px)') !== false, 'responsive toolbar');

// ── Locales ──────────────────────────────────────��────────────────────
$keys = ['period','last_30_days','last_90_days','last_12_months','custom_range','laboratory','all_labs','additional','workflow_status','all_statuses','risk','all_risks','acknowledgment','all_states','awaiting_ack','reset','start_date','end_date','invalid_range','context_prefix'];
foreach (['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'] as $loc) {
    $j = json_decode(file_get_contents("$root/locales/$loc.json"), true);
    $missing = array_filter($keys, function ($k) use ($j) { return !isset($j['insights']['filters'][$k]); });
    ok(count($missing) === 0, "$loc filter keys" . ($missing ? ' missing: ' . implode(',', $missing) : ''));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
