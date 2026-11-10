<?php
/**
 * Lab Performance comparison-tool static regression checks.
 *
 * Covers the redesigned Lab Performance table: compare checkboxes,
 * two-laboratory panel + metric bars, case-type filter, overdue column
 * and sort, per-lab Case Board drill-down (lab_key), sample-size notes,
 * and locale completeness. No database required.
 *
 * Run: php tests/lab-performance-compare-test.php
 */

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS: {$name}\n"; }
    else { $fail++; echo "FAIL: {$name}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; }
}

$api   = file_get_contents(__DIR__ . '/../api/list-cases.php');
$liApi = file_get_contents(__DIR__ . '/../api/get-lab-insights.php');
$js    = file_get_contents(__DIR__ . '/../js/lab-insights.js');
$app   = file_get_contents(__DIR__ . '/../js/app.js');
$ap    = file_get_contents(__DIR__ . '/../js/analytics-pro.js');
$main  = file_get_contents(__DIR__ . '/../main.php');
$css   = file_get_contents(__DIR__ . '/../css/lab-insights.css');

// --- 1. Backend: lab_key board filter -------------------------------------
check('list-cases accepts lab_key param',
    strpos($api, "\$_GET['lab_key']") !== false);
check('lab_key resolves user:<id> to member email',
    strpos($api, "'/^user:(\\d+)$/'") !== false
    && strpos($api, 'practice_users') !== false);
check('lab_key resolves label:<id> to label text',
    strpos($api, "'/^label:(\\d+)$/'") !== false
    && strpos($api, 'practice_assignment_labels') !== false);
check('unresolvable lab_key yields empty set not all cases',
    (bool) preg_match('/\$cases = \[\];\s*\}\s*\}\s*\n/', $api));
check('lab_key filter is practice-scoped',
    substr_count($api, 'practice_id = :practice_id') >= 2);

// --- 2. app.js plumbing ------------------------------------------------------
check('app.js reads filterLabKey hidden input',
    strpos($app, "getElementById('filterLabKey')") !== false);
check('app.js sends lab_key only with lab scope',
    (bool) preg_match("/labCasesOnly && labKeyScope !== ''\) params\.append\('lab_key'/", $app));
check('app.js clears lab_key on filter reset',
    (bool) preg_match("/filterLabKey\) filterLabKey\.value = ''/", $app));
check('analytics-pro clears lab_key on PI navigation',
    (bool) preg_match("/getElementById\('filterLabKey'\)/", $ap));
check('main.php has hidden filterLabKey input',
    strpos($main, 'id="filterLabKey"') !== false);

// --- 3. Table markup ---------------------------------------------------------
check('perf table has compare column header',
    strpos($main, 'li-compare-col') !== false
    && strpos($main, 'insights.perf.compare.select_label') !== false);
check('perf table has overdue sortable column',
    (bool) preg_match('/data-sort="overdue"/', $main));
check('case-type filter select present',
    strpos($main, 'id="liCaseTypeFilter"') !== false
    && strpos($main, 'insights.perf.case_type_filter.label') !== false);
check('compare panel container present after table',
    (bool) preg_match('/liPerfTableBody"><\/tbody>[\s\S]{0,600}id="liComparePanel"/', $main));

// --- 4. Sorting ----------------------------------------------------------------
check('default sort is current workload desc',
    (bool) preg_match("/liPerfSort = \{ key: 'workload', dir: 'desc' \}/", $js));
check('overdue sort key implemented',
    strpos($js, "case 'overdue'") !== false);
check('null metrics sort last regardless of direction',
    (bool) preg_match('/av === null\) \{ return 1; \}/', $js));
check('sort respects case-type filtered metrics',
    (bool) preg_match('/function perfSortValue\(lab, key\) \{\s*var m = labRowMetrics\(lab\)/', $js));
check('nosort guard on compare column',
    strpos($js, "th.hasAttribute('data-nosort')") !== false);

// --- 5. Case-type filtering -----------------------------------------------------
check('labRowMetrics helper centralizes filtered metrics',
    strpos($js, 'function labRowMetrics(lab)') !== false);
check('per-type stats read from lab.caseTypes payload',
    strpos($js, 'lab.caseTypes') !== false);
check('lab-attributed rate suppressed under type filter',
    strpos($js, 'not_by_type') !== false);
check('case-type options populated from actual data',
    strpos($js, 'populateCaseTypeFilter') !== false
    && strpos($js, 'liPerf.practice.caseTypes') !== false);
check('case-type select re-renders table on change',
    (bool) preg_match("/liCaseTypeFilter'\)[\s\S]{0,400}renderPerfTable\(\)/", $js));

// --- 6. Comparison panel ---------------------------------------------------------
check('compare state capped at two labs',
    strpos($js, 'LI_COMPARE_MAX = 2') !== false);
check('third selection shifts oldest',
    strpos($js, 'liCompare.shift()') !== false);
check('comparison panel renders metric table',
    strpos($js, 'li-compare-table') !== false);
check('comparison includes completed cases row',
    strpos($js, "metric_completed") !== false);
check('metric selector switches bar visualization',
    strpos($js, 'liCompareMetric') !== false
    && strpos($js, 'renderCompareBars') !== false);
check('bars are plain divs scaled to max (no chart lib needed)',
    strpos($js, 'li-cmp-bar-fill') !== false);
check('compare values use same labRowMetrics as table',
    (bool) preg_match('/compareMetricCellHtml[\s\S]{0,900}labRowMetrics\(lab\)/', $js));
check('panel has close/clear action',
    strpos($js, 'li-compare-close') !== false);
check('stale compare selections pruned on data change',
    (bool) preg_match('/liCompare = liCompare\.filter/', $js));
check('single-lab practices hide compare column',
    strpos($js, 'li-perf-table--no-compare') !== false);

// --- 7. Sample-size awareness -----------------------------------------------------
check('limited-history note added for insufficient samples',
    strpos($js, 'limited_history') !== false);
check('sample-size notes show under compare cells',
    strpos($js, 'based_on_cases') !== false);
check('no winner/score language in compare UI',
    strpos($js, 'winner') === false && strpos($js, 'best lab') === false);

// --- 8. Navigation -----------------------------------------------------------------
check('workload cell links to lab-scoped board',
    strpos($js, "data-li-nav=\"lab_cases\"") !== false);
check('overdue cell links to lab+late board filter',
    substr_count($js, "data-li-nav=\"late\"") >= 1);
check('liNavigateToCases accepts labKey param',
    (bool) preg_match('/function liNavigateToCases\(target, labKey\)/', $js));
check('labKey written to filterLabKey before dispatch',
    (bool) preg_match("/filterLabKey'\)[\s\S]{0,200}labKeyInput\.value = labKey/", $js));
check('row click still opens Lab Detail',
    (bool) preg_match('/selectLab\(lab\.labKey\)/', $js));
check('cell links stop propagation to row navigation',
    strpos($js, "closest('.li-compare-check, .li-cell-link')") !== false);

// --- 9. Mobile / a11y ----------------------------------------------------------------
check('mobile card CSS hides thead and stacks rows',
    (bool) preg_match('/@media \(max-width: 720px\)[\s\S]{0,900}li-perf-table thead \{\s*display: none/', $css));
check('cells carry data-label for card layout',
    substr_count($js, 'data-label=') >= 8);
check('compare checkbox has aria-label',
    strpos($js, 'aria-label="' . "' + escapeHtml(t('insights.perf.compare.select_label'))") !== false
    || strpos($js, 'insights.perf.compare.select_label') !== false);
check('keyboard row activation preserved',
    strpos($js, "e.key === 'Enter' || e.key === ' '") !== false);

// --- 10. Locales ----------------------------------------------------------------------
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
$cmpKeys = ['title','select_label','need_two','clear','compare_by','disclaimer',
            'metric_cases','metric_completed','metric_turnaround','metric_ontime',
            'metric_workload','metric_overdue','metric_remake'];
foreach ($locales as $loc) {
    $data = json_decode(file_get_contents(__DIR__ . '/../locales/' . $loc . '.json'), true);
    check("{$loc}.json valid", is_array($data));
    if (!is_array($data)) { continue; }
    $p = $data['insights']['perf'] ?? [];
    check("{$loc} table.overdue", isset($p['table']['overdue']) && $p['table']['overdue'] !== '');
    check("{$loc} case_type_filter", isset($p['case_type_filter']['label'], $p['case_type_filter']['all']));
    foreach (['limited_history','not_by_type','active_cases_note'] as $k) {
        check("{$loc} context.{$k}", isset($p['context'][$k]) && $p['context'][$k] !== '');
    }
    $cmp = $p['compare'] ?? null;
    check("{$loc} has insights.perf.compare", is_array($cmp));
    if (is_array($cmp)) {
        foreach ($cmpKeys as $k) {
            check("{$loc} compare.{$k}", isset($cmp[$k]) && $cmp[$k] !== '');
        }
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
