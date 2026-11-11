<?php
/**
 * Lab Quality & Revisions payload builder.
 *
 * Assembles the dedicated Quality & Revisions section data for Lab Insights
 * from data get-lab-insights.php already authorized and fetched:
 *
 *   - Remakes come ONLY from case_remake_events (explicit user-recorded
 *     events). A remake is never inferred from workflow regressions.
 *   - Workflow regressions come ONLY from case_activity_log
 *     event_type='case_regression' (recorded backward transitions).
 *     A regression is never treated as a remake and never attributed to a
 *     lab as "fault" - lab linkage is association only.
 *   - Rates/denominators reuse the lab-performance-metrics payload so the
 *     Quality section reconciles exactly with Lab Performance / Lab Detail.
 *
 * All inputs are already practice-scoped and permission-filtered by the
 * caller; this function performs no additional authorization.
 *
 * @param array $performance   computeLabPerformanceMetrics() output
 * @param array $periodsByLab  labKey => [ period rows ] (get-lab-insights)
 * @param array $regressionsByCase case_id => [ ['at'=>..,'from'=>..,'to'=>..], ... ]
 * @param array $remakeRows    case_remake_events rows (authorized cases only)
 * @param array $casesById     case_id => cases_cache row (case_type etc.)
 * @param array $labNames      labKey => display name
 * @param DateTimeImmutable|null $rangeStart
 * @param DateTimeImmutable $now
 */
function buildLabQualityInsights(array $performance, array $periodsByLab,
        array $regressionsByCase, array $remakeRows, array $casesById,
        array $labNames, $rangeStart, DateTimeImmutable $now) {

    $rangeStartTs = $rangeStart ? $rangeStart->getTimestamp() : null;
    $nowTs = $now->getTimestamp();
    $inRange = function ($ts) use ($rangeStartTs, $nowTs) {
        return $ts !== null && $ts <= $nowTs && ($rangeStartTs === null || $ts >= $rangeStartTs);
    };

    // ── Index periods: id => labKey, case_id => [labKey => [period windows]] ──
    $periodIdToLabKey = [];
    $casePeriods = []; // case_id => [ ['labKey'=>..,'start'=>ts,'end'=>ts|null], ... ]
    foreach ($periodsByLab as $labKey => $periods) {
        foreach ((array)$periods as $p) {
            if (isset($p['id'])) { $periodIdToLabKey[(int)$p['id']] = $labKey; }
            $cid = $p['case_id'] ?? null;
            if (!$cid) { continue; }
            $casePeriods[$cid][] = [
                'labKey' => $labKey,
                'start'  => strtotime((string)($p['started_at'] ?? '')) ?: null,
                'end'    => !empty($p['ended_at']) ? (strtotime((string)$p['ended_at']) ?: null) : null,
            ];
        }
    }

    $caseTypeOf = function ($caseId) use ($casesById) {
        $row = $casesById[$caseId] ?? null;
        $t = $row ? trim((string)($row['case_type'] ?? '')) : '';
        return $t !== '' ? $t : 'Unknown';
    };

    // Regression -> lab attribution: the lab whose observed period window
    // contains the event timestamp. Events outside every window stay
    // practice-level only (never force-attributed).
    $regressionsInRange = []; // flat list
    $regByLab = [];          // labKey => ['events'=>n,'cases'=>[]]
    $regTransitions = [];    // "from|to" => count (practice)
    $regTransitionsByLab = []; // labKey => same
    $regMonthly = [];        // 'Y-m' => events
    $regCaseCounts = [];     // case_id => events in range
    foreach ($regressionsByCase as $caseId => $events) {
        foreach ((array)$events as $ev) {
            $ts = strtotime((string)($ev['at'] ?? ''));
            if ($ts === false || !$inRange($ts)) { continue; }
            $from = $ev['from'] ?? null;
            $to = $ev['to'] ?? null;
            $matched = [];
            foreach (($casePeriods[$caseId] ?? []) as $per) {
                $inside = ($per['start'] === null || $ts >= $per['start'])
                    && ($per['end'] === null || $ts < $per['end']);
                if ($inside) { $matched[$per['labKey']] = true; }
            }
            $regressionsInRange[] = [
                'caseId' => $caseId,
                'at'     => gmdate('Y-m-d H:i:s', $ts),
                'ts'     => $ts,
                'from'   => $from,
                'to'     => $to,
                'caseType' => $caseTypeOf($caseId),
                'labKeys' => array_keys($matched),
            ];
            $regCaseCounts[$caseId] = ($regCaseCounts[$caseId] ?? 0) + 1;
            $mk = ($from === null || $from === '') ? '' : $from;
            $tk = ($to === null || $to === '') ? '' : $to;
            $tkey = $mk . '|' . $tk;
            $regTransitions[$tkey] = ($regTransitions[$tkey] ?? 0) + 1;
            $regMonthly[gmdate('Y-m', $ts)] = ($regMonthly[gmdate('Y-m', $ts)] ?? 0) + 1;

            foreach (array_keys($matched) as $lk) {
                if (!isset($regByLab[$lk])) { $regByLab[$lk] = ['events' => 0, 'cases' => []]; }
                $regByLab[$lk]['events']++;
                $regByLab[$lk]['cases'][$caseId] = true;
                if (!isset($regTransitionsByLab[$lk])) { $regTransitionsByLab[$lk] = []; }
                $regTransitionsByLab[$lk][$tkey] = ($regTransitionsByLab[$lk][$tkey] ?? 0) + 1;
            }
        }
    }
    usort($regressionsInRange, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    foreach ($regressionsInRange as &$re) { unset($re['ts']); }
    unset($re);

    // ── Remakes: initiated_at in range; open = completed_at IS NULL now ──
    $remakesInRange = [];
    $rmByLab = [];       // labKey => ['events','cases','labAttrCases','open']
    $rmReasons = []; $rmAttrs = []; $rmUnlinked = 0;
    $rmMonthlyCases = []; $rmMonthlyAttr = [];
    $rmCaseMonths = [];  // case_id|Y-m => true (distinct cases per month)
    $rmAttrCaseMonths = [];
    $rmTotals = ['events' => 0, 'cases' => [], 'open' => 0, 'resolved' => 0, 'multiCases' => 0];
    $rmCountsByCase = [];
    foreach ($remakeRows as $r) {
        $ts = strtotime((string)($r['initiated_at'] ?? ''));
        if ($ts === false || !$inRange($ts)) { continue; }
        $caseId = $r['case_id'];
        $labKey = !empty($r['lab_period_id']) ? ($periodIdToLabKey[(int)$r['lab_period_id']] ?? null) : null;
        $open = ($r['completed_at'] === null || $r['completed_at'] === '');
        $reason = (string)($r['reason_code'] ?? '');
        $attr = (string)($r['attribution'] ?? '');
        $month = gmdate('Y-m', $ts);

        $rmTotals['events']++;
        $rmTotals['cases'][$caseId] = true;
        $rmCountsByCase[$caseId] = ($rmCountsByCase[$caseId] ?? 0) + 1;
        if ($open) { $rmTotals['open']++; } else { $rmTotals['resolved']++; }
        $rmReasons[$reason] = ($rmReasons[$reason] ?? 0) + 1;
        $rmAttrs[$attr] = ($rmAttrs[$attr] ?? 0) + 1;
        if ($labKey === null) { $rmUnlinked++; }
        $rmCaseMonths[$caseId . '|' . $month] = true;
        if ($attr === 'lab_related') { $rmAttrCaseMonths[$caseId . '|' . $month] = true; }

        if ($labKey !== null) {
            if (!isset($rmByLab[$labKey])) { $rmByLab[$labKey] = ['events'=>0,'cases'=>[],'labAttrCases'=>[],'open'=>0]; }
            $rmByLab[$labKey]['events']++;
            $rmByLab[$labKey]['cases'][$caseId] = true;
            if ($attr === 'lab_related') { $rmByLab[$labKey]['labAttrCases'][$caseId] = true; }
            if ($open) { $rmByLab[$labKey]['open']++; }
        }

        $remakesInRange[] = [
            'caseId' => $caseId,
            'labKey' => $labKey,
            'labName' => $labKey !== null ? ($labNames[$labKey] ?? $labKey) : null,
            'caseType' => $caseTypeOf($caseId),
            'reason' => $reason,
            'attribution' => $attr,
            'initiatedAt' => gmdate('Y-m-d H:i:s', $ts),
            'open' => $open,
            'remakeNumber' => (int)($r['remake_number'] ?? 0),
        ];
    }
    $rmTotals['multiCases'] = count(array_filter($rmCountsByCase, function ($c) { return $c >= 2; }));
    usort($remakesInRange, function ($a, $b) { return strcmp($b['initiatedAt'], $a['initiatedAt']); });
    foreach (array_slice($remakesInRange, 0, 100) as $i => &$rr) { $rr['sort'] = $i; }
    unset($rr);

    // Monthly trend buckets (distinct cases / events per month)
    foreach ($rmCaseMonths as $k => $_) {
        [$cid, $m] = explode('|', $k, 2);
        $rmMonthlyCases[$m] = ($rmMonthlyCases[$m] ?? 0) + 1;
    }
    foreach ($rmAttrCaseMonths as $k => $_) {
        [, $m] = explode('|', $k, 2);
        $rmMonthlyAttr[$m] = ($rmMonthlyAttr[$m] ?? 0) + 1;
    }
    ksort($rmMonthlyCases); ksort($rmMonthlyAttr); ksort($regMonthly);

    arsort($rmReasons); arsort($rmAttrs); arsort($regTransitions);
    $topTransitions = [];
    foreach (array_slice($regTransitions, 0, 8, true) as $k => $n) {
        [$f, $tt] = explode('|', $k, 2);
        $topTransitions[] = ['from' => $f === '' ? null : $f, 'to' => $tt === '' ? null : $tt, 'count' => $n];
    }

    // ── Practice + per-lab summary blocks ──
    $practiceRemakes = $performance['practice']['remakes'] ?? [];
    $practiceVolume = (int)($performance['practice']['volume']['uniqueCases'] ?? 0);
    $practiceOpen = (int)($performance['practice']['openRemakes'] ?? $rmTotals['open']);

    $summary = [
        'remakeRatePct'        => $practiceRemakes['remakeRatePct'] ?? null,
        'remakeCases'          => (int)($practiceRemakes['casesWithRemakes'] ?? count($rmTotals['cases'])),
        'remakeDenominator'    => (int)($practiceRemakes['rateDenominator'] ?? $practiceVolume),
        'remakeSufficient'     => (bool)($practiceRemakes['rateSufficient'] ?? false),
        'labAttrRatePct'       => $practiceRemakes['labAttributedRatePct'] ?? null,
        'labAttrCases'         => (int)($practiceRemakes['labAttributedCases'] ?? 0),
        'openRemakes'          => $practiceOpen,
        'regressionEvents'     => count($regressionsInRange),
        'regressionCases'      => count($regCaseCounts),
    ];

    // Per-lab aggregates: remakes from perf payload (same definitions),
    // regressions from the window-attribution pass above.
    $byLab = [];
    foreach (($performance['labs'] ?? []) as $lk => $labPerf) {
        $lk = $labPerf['labKey'] ?? $lk;
        $lr = $labPerf['remakes'] ?? [];
        $rb = $rmByLab[$lk] ?? ['events'=>0,'cases'=>[],'labAttrCases'=>[],'open'=>0];
        // Prefer perf-service counts (authoritative); fall back to local.
        $reg = $regByLab[$lk] ?? ['events'=>0,'cases'=>[]];
        $byLab[$lk] = [
            'name'              => $labPerf['name'] ?? ($labNames[$lk] ?? $lk),
            'remakeEvents'      => (int)($lr['total'] ?? $rb['events']),
            'remakeCases'       => (int)($lr['casesWithRemakes'] ?? count($rb['cases'])),
            'labAttrCases'      => (int)($lr['labAttributedCases'] ?? count($rb['labAttrCases'])),
            'openRemakes'       => (int)($labPerf['openRemakes'] ?? ($lr['openRemakes'] ?? $rb['open'])),
            'regressionEvents'  => (int)$reg['events'],
            'regressionCases'   => count($reg['cases']),
        ];
    }
    // Labs with regression/remake activity but no perf entry (e.g. current
    // cases, no period history) still surface.
    foreach ($rmByLab as $lk => $rb) {
        if (!isset($byLab[$lk])) {
            $byLab[$lk] = [
                'name' => $labNames[$lk] ?? $lk,
                'remakeEvents' => $rb['events'], 'remakeCases' => count($rb['cases']),
                'labAttrCases' => count($rb['labAttrCases']), 'openRemakes' => $rb['open'],
                'regressionEvents' => 0, 'regressionCases' => 0,
            ];
        }
    }
    foreach ($regByLab as $lk => $reg) {
        if (!isset($byLab[$lk])) {
            $byLab[$lk] = [
                'name' => $labNames[$lk] ?? $lk,
                'remakeEvents' => 0, 'remakeCases' => 0, 'labAttrCases' => 0,
                'openRemakes' => 0,
                'regressionEvents' => $reg['events'], 'regressionCases' => count($reg['cases']),
            ];
        }
    }

    // Per-case-type practice aggregates (for the case-type filter scope).
    $byType = [];
    foreach ($remakesInRange as $r) {
        $ct = $r['caseType'];
        if (!isset($byType[$ct])) { $byType[$ct] = ['remakeEvents'=>0,'remakeCases'=>[],'regressionEvents'=>0,'regressionCases'=>[]]; }
        $byType[$ct]['remakeEvents']++;
        $byType[$ct]['remakeCases'][$r['caseId']] = true;
    }
    foreach ($regressionsInRange as $r) {
        $ct = $r['caseType'];
        if (!isset($byType[$ct])) { $byType[$ct] = ['remakeEvents'=>0,'remakeCases'=>[],'regressionEvents'=>0,'regressionCases'=>[]]; }
        $byType[$ct]['regressionEvents']++;
        $byType[$ct]['regressionCases'][$r['caseId']] = true;
    }
    foreach ($byType as $ct => &$bt) {
        $bt['remakeCases'] = count($bt['remakeCases']);
        $bt['regressionCases'] = count($bt['regressionCases']);
    }
    unset($bt);

    return [
        'computed_at' => gmdate('Y-m-d H:i:s'),
        'summary'     => $summary,
        'remakes'     => [
            'events'          => $rmTotals['events'],
            'cases'           => count($rmTotals['cases']),
            'open'            => $rmTotals['open'],
            'resolved'        => $rmTotals['resolved'],
            'multiRemakeCases'=> $rmTotals['multiCases'],
            'reasons'         => $rmReasons,
            'attributions'    => $rmAttrs,
            'unlinked'        => $rmUnlinked,
            'list'            => array_slice($remakesInRange, 0, 100),
        ],
        'regressions' => [
            'events'      => count($regressionsInRange),
            'cases'       => count($regCaseCounts),
            'transitions' => $topTransitions,
            'list'        => array_slice($regressionsInRange, 0, 100),
        ],
        'trends' => [
            'remakeCases'       => $rmMonthlyCases,
            'labAttrCases'      => $rmMonthlyAttr,
            'regressionEvents'  => $regMonthly,
        ],
        'byLab'   => $byLab,
        'byCaseType' => $byType,
    ];
}
