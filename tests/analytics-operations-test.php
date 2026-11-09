<?php
/**
 * Static regression test for the Practice Insights Operations tab defects:
 *
 * 1. Monthly Case Volume (and the sibling Backlog/Lifecycle charts) use
 *    Chart.js responsive + maintainAspectRatio:false inside
 *    .ap-chart-container, which only declares min-height. Without a
 *    definite container height the canvas grows the container, which
 *    retriggers Chart.js resize - an unbounded feedback loop, worst when
 *    a hidden tab becomes visible. The renders must pin an explicit pixel
 *    height (and mark the container so the empty-state helper preserves it).
 *
 * 2. Chart notes (apVolumeNote, apLifecycleNote) must live in the card
 *    footer (.ap-chart-card), not inside the fixed-height chart container.
 *
 * 3. Team Workload "Creation Activity" must use creator attribution
 *    (created_by_user_id / charts.creationActivity), NOT the assignee-grouped
 *    teamPerformance data. The API must emit creationActivity scoped by
 *    practice + authorized_case_ids + af filters, and the frontend must
 *    destroy the previous chart instance before re-creating it (mode
 *    switches re-render without a reload).
 *
 * 4. The assignee-type filter (apTeamFilter) does not apply to creator
 *    attribution and must be hidden.
 *
 * 5. Empty vs. error states must be distinguishable: a missing
 *    creationActivity field is a contract failure (load_error), a valid
 *    empty array is "No cases match the selected filters." (no_cases_match).
 *
 * No database required.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$api = file_get_contents(__DIR__ . '/../api/get-analytics.php');
$js  = file_get_contents(__DIR__ . '/../js/analytics-pro.js');
$main = file_get_contents(__DIR__ . '/../main.php');
$css = file_get_contents(__DIR__ . '/../css/analytics-pro.css');

if ($api === false || $js === false || $main === false || $css === false) {
    fwrite(STDERR, "FAIL: cannot read source files\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

// --- 1. Explicit heights for the fixed-height charts ---------------------
foreach ([
    'renderVolumeChart' => 'apVolumeChart',
    'renderBacklogChart' => 'apBacklogChart',
    'renderLifecycleChart' => 'apLifecycleChart',
] as $fn => $canvasId) {
    // Locate the function body (up to the next top-level function).
    $start = strpos($js, 'function ' . $fn);
    check("{$fn} exists", $start !== false);
    if ($start === false) continue;
    $next = strpos($js, "\n  function ", $start + 10);
    $body = substr($js, $start, $next === false ? strlen($js) - $start : $next - $start);
    check("{$fn} pins an explicit container height",
        (bool) preg_match('/style\.height\s*=\s*\'\d+px\'/', $body));
    check("{$fn} marks container apFixedHeight",
        strpos($body, 'apFixedHeight') !== false);
}

// --- 1b. Central contract: .ap-chart-container must declare a definite
// height so responsive + maintainAspectRatio:false charts cannot grow it.
check('analytics-pro.css gives .ap-chart-container a definite height',
    (bool) preg_match('/\.ap-chart-container\s*\{[^}]*height\s*:\s*\d+px/s', $css));
check('scroll-wrapped chart containers opt out via height:auto',
    (bool) preg_match('/\.ap-chart-scroll\s+\.ap-chart-container\s*\{[^}]*height\s*:\s*auto/s', $css));

// --- 2. Notes live in the card footer, not the chart container ------------
foreach (['apVolumeNote', 'apLifecycleNote'] as $noteId) {
    $pos = strpos($js, "'" . $noteId . "'");
    check("{$noteId} referenced", $pos !== false);
    // The creation block must append to .ap-chart-card, not canvas.parentElement.
    $window = substr($js, max(0, $pos - 800), 1600);
    check("{$noteId} appended to .ap-chart-card",
        strpos($window, "closest('.ap-chart-card')") !== false
        && strpos($window, 'parentElement.appendChild') === false);
}

// --- 3. Creation Activity uses creator-grouped data -----------------------
check('API emits charts.creationActivity',
    strpos($api, "'creationActivity' => \$creationActivity") !== false);

$caStart = strpos($api, '$creationActivity = [];');
check('creationActivity query exists', $caStart !== false);
if ($caStart !== false) {
    $caBlock = substr($api, $caStart, 4000);
    check('creationActivity groups by created_by_user_id',
        strpos($caBlock, 'created_by_user_id') !== false);
    check('creationActivity joins authorized_case_ids',
        strpos($caBlock, 'authorized_case_ids') !== false);
    check('creationActivity is practice-scoped',
        strpos($caBlock, 'c.practice_id = :practice_id') !== false);
    check('creationActivity excludes archived cases',
        strpos($caBlock, 'c.archived = 0') !== false);
    check('creationActivity applies global filters',
        strpos($caBlock, '$afScopeSql') !== false);
    check('creationActivity period uses team_period',
        strpos($caBlock, 'buildTimePeriodClause($teamPeriod)') !== false);
    check('creationActivity does NOT group by assignee',
        strpos($caBlock, 'assigned_to') === false
        || strpos($caBlock, 'GROUP BY') !== false && strpos(substr($caBlock, strpos($caBlock, 'GROUP BY'), 200), 'assigned_to') === false);
}

check('frontend reads charts.creationActivity',
    strpos($js, 'charts.creationActivity') !== false);
check('frontend no longer feeds teamPerformance to the activity chart',
    strpos($js, 'renderTeamChart(charts.teamPerformance') === false
    && strpos($js, 'apLastTeamData = charts.teamPerformance') === false);

// renderTeamChart must destroy the previous instance before re-creating.
$rtcStart = strpos($js, 'function renderTeamChart');
check('renderTeamChart exists', $rtcStart !== false);
if ($rtcStart !== false) {
    $rtcBody = substr($js, $rtcStart, 6000);
    check('renderTeamChart destroys prior chart instance',
        strpos($rtcBody, "destroyChart('apTeamChart')") !== false);
    check('renderTeamChart aggregates item.creator',
        strpos($rtcBody, 'item.creator') !== false);
    check('renderTeamChart distinguishes missing field (load_error)',
        strpos($rtcBody, 'insights.charts.load_error') !== false
        || strpos($rtcBody, 'insights.error.analytics_data') !== false);
    check('renderTeamChart uses no_cases_match empty state',
        strpos($rtcBody, 'insights.charts.no_cases_match') !== false);
    check('renderTeamChart does not navigate by assignee',
        strpos($rtcBody, "navigateToFilteredCasesParam('assigned_to'") === false);
}

// --- 4. Assignee-type filter hidden ----------------------------------------
check('apTeamFilter is hidden in markup',
    (bool) preg_match('/id="apTeamFilter"[^>]*hidden|hidden[^>]*id="apTeamFilter"/s', $main)
    || (bool) preg_match('/<select[^>]*id="apTeamFilter"[^>]*hidden/s', $main));

// --- 5. Locale keys present in all 9 locales --------------------------------
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
foreach ($locales as $loc) {
    $raw = @file_get_contents(__DIR__ . "/../locales/$loc.json");
    $d = $raw !== false ? json_decode($raw, true) : null;
    $charts = $d['insights']['charts'] ?? [];
    check("$loc has no_cases_match + load_error",
        isset($charts['no_cases_match'], $charts['load_error'])
        && $charts['no_cases_match'] !== '' && $charts['load_error'] !== '');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
