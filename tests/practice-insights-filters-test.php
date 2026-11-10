<?php
// Static regression tests for Practice Insights filter consolidation:
// one global filter context (af_*), no redundant chart-level period
// selectors, correct current-state vs historical semantics.
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

$main = file_get_contents("$root/main.php");
$js = file_get_contents("$root/js/analytics-pro.js");
$api = file_get_contents("$root/api/get-analytics.php");

// ── 1. No chart-level period selectors remain in markup ────────────────
foreach (['apStatusPeriod','apVolumePeriod','apTypePeriod','apDurationPeriod','apCreatorPeriod','apTeamPeriod','apTeamFilter','apTeamActivityControls'] as $id) {
    ok(strpos($main, 'id="' . $id . '"') === false, "main.php still has #$id");
}
// The preserved analytical control: Active Workload / Creation Activity seg.
ok(strpos($main, 'apTeamViewWorkload') !== false && strpos($main, 'apTeamViewActivity') !== false, 'workload/activity segmented control preserved');

// ── 2. JS sends only the global af_* context ───────────────────────────
ok(strpos($js, 'team_period=') === false && strpos($js, 'volume_period=') === false
   && strpos($js, 'status_period=') === false && strpos($js, 'type_period=') === false
   && strpos($js, 'duration_period=') === false && strpos($js, 'creator_period=') === false
   && strpos($js, 'team_filter=') === false, 'analytics request carries no chart-level period params');
ok(strpos($js, 'buildAfQuery()') !== false, 'global af query still appended');
ok(strpos($js, 'ap_status_period') === false && strpos($js, 'ap_volume_period') === false
   && strpos($js, 'ap_team_period') === false && strpos($js, 'ap_creator_period') === false, 'obsolete localStorage keys removed');
ok(strpos($js, 'restoreSavedFilters') === false, 'restoreSavedFilters removed');

// ── 3. Global toolbar behavior (already required by spec items 1/2/8) ──
ok(strpos($js, "dates.hidden = afFilters.period !== 'custom'") !== false, 'custom dates hidden unless period=custom');
ok(strpos($js, "if (afFilters.period === 'custom')") !== false, 'custom dates only sent for custom period');
ok(strpos($js, 'afResetFilters') !== false && strpos($js, 'AF_DEFAULTS') !== false, 'reset restores defaults');
ok(strpos($main, 'id="afFiltersPanel"') !== false && strpos($main, 'id="afFiltersToggle"') !== false, 'secondary filters behind expandable panel');
ok(strpos($main, 'id="afCustomDates" hidden') !== false, 'date inputs initially hidden');

// ── 4. Backend: global period authoritative for historical charts ──────
// Status Distribution = current-state: active population + dimension
// filters only, no reporting-period date bound, no af_status collapse.
$sdPos = strpos($api, 'Case Status Distribution');
$sdBlock = substr($api, $sdPos, 1100);
ok(strpos($sdBlock, '$afDimSql') !== false && strpos($sdBlock, '$afScopeSql') === false, 'status distribution uses dimension-only scope');
ok(strpos($sdBlock, "status != '{\$GLOBALS['workflowTerminalStatus']}'") !== false, 'status distribution keeps active population');

// Historical charts consume $afScopeSql and no longer build local clauses.
ok(strpos($api, 'buildTimePeriodClause') === false, 'buildTimePeriodClause removed');
ok(strpos($api, 'typeClause') === false && strpos($api, 'durationClause') === false
   && strpos($api, 'creatorClause') === false && strpos($api, 'activityClause') === false, 'local period clauses removed');

// Monthly volume derives bucket count from the global period.
ok(strpos($api, 'afPeriodSpanMonths') !== false, 'span-months helper exists');
ok(strpos($api, 'afPeriodSpanMonths($afPeriod, $afFrom, $afTo)') !== false, 'volume months derived from af period');
ok(strpos($api, "if (\$afPeriod === '30d') return 3") !== false
   && strpos($api, "if (\$afPeriod === 'all') return 120") !== false, 'span mapping covers presets and all-time');

// afDimSql must NOT contain date or status restrictions (current-state).
$dimPos = strpos($api, '$afDimSql =');
$dimBlock = substr($api, $dimPos, 700);
ok(strpos($dimBlock, 'af_period') === false && strpos($dimBlock, 'creation_date') === false, 'afDimSql has no date restriction');
ok(strpos($dimBlock, 'af_status') === false, 'afDimSql excludes status filter');

// Historical queries still apply the full scope.
foreach (['creatorBreakdown', 'creationActivity', 'statusDurationData', 'monthlyVolume', 'caseTypeBreakdown'] as $needle) {
    $p = strpos($api, '$' . $needle);
    ok($p !== false, "payload var $needle exists");
}

// Dead chart-level GET params no longer read.
foreach (['duration_period', 'volume_period', 'status_period', 'type_period', 'creator_period'] as $p) {
    ok(strpos($api, "'" . $p . "'") === false, "get-analytics no longer reads $p");
}
// team_period/team_filter remain for the legacy teamPerformance payload.
ok(strpos($api, "'team_period'") !== false, 'team_period still accepted (legacy payload)');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
