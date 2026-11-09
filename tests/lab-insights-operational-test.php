<?php
/**
 * Static regression test for the Lab Insights operational KPI summary +
 * Lab Case Health redesign.
 *
 * Covers:
 *  1. api/get-lab-insights.php emits an `operational` payload computed
 *     from the deduplicated current-workload case set, classified by the
 *     shared classifyCaseAttentionRisk() rules with the established
 *     precedence (late > appointment risk > due soon > on track) so every
 *     active lab case lands in exactly one bucket.
 *  2. The workload query selects the fields the classifier needs
 *     (patient_appointment_date, lab_ack_status) and practice display
 *     settings supply the thresholds.
 *  3. api/list-cases.php supports lab_only=true using the same live-lab
 *     identity rule (practice_users.is_lab emails UNION
 *     practice_assignment_labels.is_lab labels).
 *  4. main.php contains the four KPI cards + health bar markup reusing
 *     the shared .ap-kpi-card/.ap-health-card components, plus the hidden
 *     filterLabCases board filter.
 *  5. js/lab-insights.js renders the payload, navigates via the existing
 *     Case Board filters, hides the replaced perf summary strip, and
 *     handles the empty state.
 *  6. Board wiring: app.js sends lab_only, case-filter-sort.js persists
 *     filterLabCases, analytics-pro.js clears it on Practice Insights
 *     navigation so the scopes cannot leak into each other.
 *  7. All nine locales define insights.labkpi.* and the JSON stays valid.
 *
 * No database required.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$api   = file_get_contents(__DIR__ . '/../api/get-lab-insights.php');
$list  = file_get_contents(__DIR__ . '/../api/list-cases.php');
$js    = file_get_contents(__DIR__ . '/../js/lab-insights.js');
$app   = file_get_contents(__DIR__ . '/../js/app.js');
$ap    = file_get_contents(__DIR__ . '/../js/analytics-pro.js');
$cfs   = file_get_contents(__DIR__ . '/../js/case-filter-sort.js');
$main  = file_get_contents(__DIR__ . '/../main.php');
$css   = file_get_contents(__DIR__ . '/../css/lab-insights.css');

$files = ['api' => $api, 'list-cases' => $list, 'lab-insights.js' => $js,
          'app.js' => $app, 'analytics-pro.js' => $ap, 'case-filter-sort.js' => $cfs,
          'main.php' => $main, 'lab-insights.css' => $css];
foreach ($files as $name => $c) {
    if ($c === false) {
        fwrite(STDERR, "FAIL: cannot read {$name}\n");
        exit(1);
    }
}

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS: {$name}\n"; }
    else { $failed++; echo "FAIL: {$name}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; }
}

// --- 1. Backend payload ---------------------------------------------------
check('get-lab-insights requires case-attention.php',
    strpos($api, "require_once __DIR__ . '/case-attention.php'") !== false);
check('get-lab-insights requires practice-display-settings.php',
    strpos($api, "require_once __DIR__ . '/practice-display-settings.php'") !== false);
check('loads practice display settings for thresholds',
    strpos($api, 'getPracticeDisplaySettings($pdo, $practiceId)') !== false);
check('workload query selects patient_appointment_date',
    (bool) preg_match('/patient_appointment_date,\s*\n?\s*lab_ack_status/', $api));
check('dedupes active lab cases across labs',
    strpos($api, '$activeLabCasesById') !== false
    && strpos($api, "!isset(\$activeLabCasesById[\$row['case_id']])") !== false);
check('classifies via shared classifyCaseAttentionRisk',
    strpos($api, 'classifyCaseAttentionRisk(') !== false);
check('uses established precedence late > appt > dueSoon > onTrack',
    (bool) preg_match('/\$cls\[\'late\'\][\s\S]{0,200}\$cls\[\'apptRisk\'\][\s\S]{0,200}\$cls\[\'dueSoon\'\]/', $api));
check('emits operational payload',
    strpos($api, "'operational' => \$operational") !== false);
check('operational has unique-case KPIs + health buckets',
    strpos($api, "'activeLabCases'") !== false
    && strpos($api, "'overdueLabCases'") !== false
    && strpos($api, "'overduePct'") !== false
    && strpos($api, "'caseHealth'") !== false);
check('overduePct is null-safe (no zero denominator)',
    (bool) preg_match("/\\\$activeLabCaseCount > 0[\\s\\S]{0,120}: null/", $api));
check('early empty-state response includes operational=null',
    strpos($api, "'operational' => null") !== false);

// --- 2. list-cases lab_only ----------------------------------------------
check('list-cases supports lab_only param',
    strpos($list, "\$_GET['lab_only']") !== false);
check('lab_only uses is_lab user + label identities',
    strpos($list, 'pu.is_lab = 1') !== false
    && strpos($list, 'practice_assignment_labels') !== false
    && strpos($list, 'is_lab = 1') !== false);
check('lab_only matches assignedTo case-insensitively',
    (bool) preg_match("/mb_strtolower\\(trim\\(\\(string\\)\\(\\\$case\\['assignedTo'\\]/", $list));

// --- 3. Markup -------------------------------------------------------------
foreach (['liOps','liKpiGrid','liKpiActiveCases','liKpiOverdue','liKpiTurnaround',
          'liKpiOnTime','liHealthCard','liHealthBar','liHealthOnTrack','liHealthDueSoon',
          'liHealthApptRisk','liHealthOverdue','filterLabCases'] as $id) {
    check("main.php contains #{$id}", strpos($main, 'id="' . $id . '"') !== false);
}
check('KPI cards reuse shared ap-kpi-card component',
    substr_count($main, 'class="ap-kpi-card') >= 4);
check('health bar reuses shared ap-health-* components',
    strpos($main, 'ap-health-seg ap-health-on-track') !== false
    && strpos($main, 'ap-health-seg ap-health-due-soon') !== false
    && strpos($main, 'ap-health-seg ap-health-appt-risk') !== false
    && strpos($main, 'ap-health-seg ap-health-late') !== false);
check('health legend shows counts for all four categories',
    strpos($main, 'liHealthOnTrackCount') !== false
    && strpos($main, 'liHealthDueSoonCount') !== false
    && strpos($main, 'liHealthApptRiskCount') !== false
    && strpos($main, 'liHealthOverdueCount') !== false);

// --- 4. lab-insights.js ----------------------------------------------------
check('renderOperationalSummary exists',
    strpos($js, 'function renderOperationalSummary') !== false);
check('render() calls renderOperationalSummary',
    (bool) preg_match('/function render\(data\)[\s\S]{0,300}renderOperationalSummary\(data\)/', $js));
check('hides replaced perf summary + now strip',
    strpos($js, "getElementById('liPerfSummary')") !== false
    && strpos($js, "getElementById('liPerfNow')") !== false);
check('navigation sets lab scope + risk filters',
    strpos($js, "setCheck('filterLabCases', true)") !== false
    && strpos($js, "setCheck('filterLateCases', true)") !== false
    && strpos($js, "setCheck('filterDueSoon', true)") !== false
    && strpos($js, "setCheck('filterApptRisk', true)") !== false);
check('turnaround/on-time KPIs drill into Lab Detail',
    strpos($js, 'liNavigateToPerfDetail') !== false
    && strpos($js, "selectLab('practice')") !== false);
check('empty health state toggles ap-health-card--empty',
    strpos($js, 'ap-health-card--empty') !== false);
check('overdue KPI alert class only when overdue > 0',
    (bool) preg_match("/ap-kpi-card--alert-active', overdue > 0/", $js));
check('missing operational payload hides ops block',
    (bool) preg_match("/if \\(!o\\) \\{[\\s\\S]{0,200}ops\\.style\\.display = 'none'/", $js));

// --- 5. Board wiring ---------------------------------------------------------
check('app.js reads filterLabCases',
    strpos($app, "getElementById('filterLabCases')") !== false);
check('app.js sends lab_only param',
    strpos($app, "params.append('lab_only', 'true')") !== false);
check('app.js clears filterLabCases with other filters',
    (bool) preg_match("/filterLabCases\\) filterLabCases\\.checked = false/", $app));
check('case-filter-sort persists filterLabCases',
    substr_count($cfs, 'filterLabCases') >= 2);
check('analytics-pro clears lab scope on PI navigation',
    (bool) preg_match("/'filterAttentionOnly', 'filterLabCases'\\]/", $ap));

// --- 6. CSS ------------------------------------------------------------------
check('lab-insights.css styles .li-ops block',
    strpos($css, '.li-ops') !== false);

// --- 7. Locales ----------------------------------------------------------------
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
$requiredKeys = ['active_cases','active_sub','overdue_cases','require_attention',
                 'avg_turnaround','on_time_rate','case_health','selected_period'];
foreach ($locales as $loc) {
    $file = __DIR__ . '/../locales/' . $loc . '.json';
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    check("{$loc}.json is valid JSON", is_array($data));
    if (!is_array($data)) { continue; }
    $labkpi = $data['insights']['labkpi'] ?? null;
    check("{$loc} has insights.labkpi", is_array($labkpi));
    if (!is_array($labkpi)) { continue; }
    foreach ($requiredKeys as $k) {
        check("{$loc} labkpi.{$k} present", isset($labkpi[$k]) && $labkpi[$k] !== '');
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
