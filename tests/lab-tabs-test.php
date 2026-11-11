<?php
// Static contract test for the Lab Insights three-tab reorganization.
// No database: verifies markup structure, panel assignment, JS tab logic,
// CSS, and locale coverage.

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }
function tpos($hay, $needle) { $p = strpos($hay, $needle); return $p === false ? PHP_INT_MAX : $p; }

$main = file_get_contents("$root/main.php");
$js = file_get_contents("$root/js/lab-insights.js");
$css = file_get_contents("$root/css/lab-insights.css");

// ── Tab bar ───────────────────────────────────────────────────────────
ok(strpos($main, 'id="liTabBar"') !== false, 'tab bar present');
ok(strpos($main, 'data-li-tab="overview"') !== false, 'overview tab');
ok(strpos($main, 'data-li-tab="performance"') !== false, 'performance tab');
ok(strpos($main, 'data-li-tab="quality"') !== false, 'quality tab');
ok(strpos($main, 'role="tablist"') !== false && strpos($main, 'role="tab"') !== false, 'tab semantics');
ok(strpos($main, "t('insights.tabs.overview')") !== false, 'tab labels localized');

// ── Panels ────────────────────────────────────────────────────────────
ok(strpos($main, 'id="liPanelOverview"') !== false, 'overview panel');
ok(strpos($main, 'id="liPanelPerformance"') !== false, 'performance panel');
ok(strpos($main, 'id="liPanelQuality"') !== false, 'quality panel');

// Section → panel assignment by document order.
$pTab = tpos($main, 'id="liTabBar"');
$pOvr = tpos($main, 'id="liPanelOverview"');
$pOps = tpos($main, 'id="liOps"');
$pAttn = tpos($main, 'id="liAttentionSection"');
$pRecs = tpos($main, 'id="liRecs"');
$pWork = tpos($main, 'id="liWorkloadSummary"');
$pLegacy = tpos($main, 'id="liLegacy"');
$pPerf = tpos($main, 'id="liPanelPerformance"');
$pPerfTbl = tpos($main, 'id="liPerfTable"');
$pAging = tpos($main, 'id="liAgingSection"');
$pDetail = tpos($main, 'id="liLabDetail"');
$pQual = tpos($main, 'id="liPanelQuality"');
$pQuality = tpos($main, 'id="liQualitySection"');

ok($pTab < $pOvr && $pOvr < $pOps, 'tab bar before overview panel before ops');
ok($pOps < $pAttn && $pAttn < $pRecs && $pRecs < $pWork, 'overview order: ops, attention, recs, workload');
ok($pWork < $pLegacy, 'overview closes before legacy layout');
ok($pLegacy < $pPerf && $pPerf < $pPerfTbl, 'legacy outside panels, perf panel wraps table');
ok($pPerfTbl < $pAging && $pAging < $pDetail, 'perf panel order: table, aging, detail');
ok($pDetail < $pQual && $pQual < $pQuality, 'quality panel wraps quality section');
// liRecs must no longer live inside liPerf.
ok(strpos(substr($main, $pPerf, $pQual - $pPerf), 'id="liRecs"') === false, 'recs moved out of perf panel');
ok(strpos(substr($main, $pPerf, $pQual - $pPerf), 'id="liQualitySection"') === false, 'quality moved out of perf panel');

// Global filter toolbar still above tabs.
ok(tpos($main, 'id="liGlobalFilters"') < $pTab, 'filter toolbar above tabs');

// ── JS tab logic ──────────────────────────────────────────────────────
ok(strpos($js, 'LI_TABS') !== false && strpos($js, "'overview', 'performance', 'quality'") !== false, 'tab list');
ok(strpos($js, 'function liSetTab') !== false, 'liSetTab exists');
ok(strpos($js, 'data-li-panel') !== false, 'panel toggling');
ok(strpos($js, 'aria-selected') !== false, 'aria-selected managed');
ok(strpos($js, 'LI_TAB_KEY') !== false && strpos($js, 'sessionStorage') !== false, 'tab persistence');
ok(strpos($js, 'li-tab-') !== false && strpos($js, 'location.hash') !== false, 'hash deep-linking');
ok(strpos($js, 'hashchange') !== false, 'back/forward support');
ok(strpos($js, 'liRenderWorkloadSummary') !== false, 'workload summary renderer');
ok(strpos($js, 'liWorkloadViewPerf') !== false, 'overview→performance link');
ok(strpos($js, 'legacyTabBar') !== false && strpos($js, 'noPerfTabBar') !== false, 'tab bar hidden in legacy mode');

// ── CSS ───────────────────────────────────────────────────────────────
ok(strpos($css, '.li-tab-panel[hidden]') !== false, 'panel hidden rule');
ok(strpos($css, '.li-tab-bar') !== false, 'tab bar spacing');

// ── Locales ───────────────────────────────────────────────────────────
$tabKeys = ['aria_label','overview','performance','quality'];
$workKeys = ['title','subtitle','view_performance','col_active'];
foreach (['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'] as $loc) {
    $j = json_decode(file_get_contents("$root/locales/$loc.json"), true);
    $missT = array_filter($tabKeys, function ($k) use ($j) { return !isset($j['insights']['tabs'][$k]); });
    $missW = array_filter($workKeys, function ($k) use ($j) { return !isset($j['insights']['workload_summary'][$k]); });
    ok(count($missT) === 0 && count($missW) === 0, "$loc tab/workload keys" . ($missT || $missW ? ' missing' : ''));
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
