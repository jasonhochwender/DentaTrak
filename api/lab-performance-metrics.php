<?php
/**
 * Lab Performance Metrics service.
 *
 * Reusable analytics layer for Lab Performance Intelligence. Consumed today by
 * api/get-lab-insights.php (exposed as the `performance` block); intended to be
 * reused later by Practice Insights and Smart Recommendations. Pure PHP + PDO:
 * no session, headers, or output — callers own access control.
 *
 * ── POPULATION RULES ───────────────────────────────────────────────────────
 *  - Practice scope: every query filters case_lab_assignment_periods /
 *    case_remake_events / cases_cache by the caller-supplied practice_id, and
 *    joins authorized_case_ids when available (same policy as canUserAccessCase).
 *  - Lab identity: (assignee_type, user_id|label_id) key, identical to
 *    get-lab-insights.php. Historical-only identities keep their immutable
 *    display-name snapshot.
 *  - Periods: only is_lab_snapshot=1 rows count as lab engagements.
 *  - Demo data: included (it is the practice's own data) but counted in
 *    population.demoCases so consumers can disclose it.
 *  - Archived cases: kept — they are legitimate history. Only current-state
 *    metrics (workload) require the case to be live (not archived, not in the
 *    practice's terminal workflow column).
 *  - Cases transferred between labs: each lab's period(s) are that lab's own
 *    engagement; a case can appear in multiple labs' volume.
 *  - Repeated assignments to the SAME lab: counted once in uniqueCases, once
 *    per period in engagements; turnaround sums a case's periods at that lab.
 *
 * ── DATE-RANGE RULES (per-metric business date, not one timestamp) ─────────
 *  - Volume:                period started_at within [start, end)
 *  - Completed / turnaround / on-time: period ended_at within [start, end)
 *  - Remake counts/rates:   remake initiated_at within [start, end)
 *  - Remake duration:       remake completed_at within [start, end)
 *  - Open remakes / current workload: CURRENT STATE, never range-filtered
 *    (an engagement started before the range is still the lab's work now).
 *  - previousPeriod benchmarks: the same-length window immediately before
 *    [start, end). Not produced for an unbounded ('all') range.
 *
 * ── HONESTY RULES ──────────────────────────────────────────────────────────
 *  - Turnaround uses only history_quality='observed' periods that ended via
 *    'delivered' with positive duration. backfilled_unknown_start, archive
 *    closes, deletion closes, and open periods never enter turnaround.
 *  - On-time uses due_date_snapshot (point-in-time value at period start).
 *    Where the snapshot is NULL (pre-snapshot history or no due date set), the
 *    current cases_cache.due_date is used ONLY if it was not edited after the
 *    case's final completed period ended (same guard as legacy Lab Insights).
 *    A missing due date is never classified as on-time or late — it is
 *    excluded from the denominator and surfaced via coverage.
 *  - Case type uses case_type_snapshot first; NULL snapshots fall back to the
 *    current cases_cache.case_type (flagged via caseTypeCoverage), otherwise
 *    'Unknown'. Historical rows are never rewritten.
 *  - Remake metrics come ONLY from case_remake_events — case_regression
 *    events and cases_cache.revisions are deliberately untouched (the legacy
 *    "Revisions" metric remains a regression-event count, not remakes).
 *  - remake_rate = unique cases with >=1 remake / unique lab cases. A case
 *    with 3 remakes counts once in the numerator.
 *  - lab_attributed_remake_rate counts only attribution='lab_related' — it is
 *    NOT labelled an overall lab-quality failure rate.
 *  - Every rate/average ships with n and a `sufficient` flag
 *    (n >= LAB_PERF_MIN_SAMPLE) so consumers never over-read tiny samples.
 */

require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/remakes.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/practice-security.php';

const LAB_PERF_MIN_SAMPLE = 5;

// ── Fetch helpers (each is one bounded query — no N+1) ──────────────────────

/**
 * Lab identity universe: live lab designations UNION historical-only
 * identities seen in assignment history. Same rule as get-lab-insights.php.
 */
function labPerfLabUniverse($pdo, $practiceId) {
    $labs = [];

    $stmt = $pdo->prepare("
        SELECT pu.user_id AS entity_id, u.email AS current_name
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id AND pu.is_lab = 1
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $labs['user:' . $row['entity_id']] = [
            'type' => 'user', 'entityId' => (int)$row['entity_id'],
            'name' => $row['current_name'], 'isLive' => true,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id AS entity_id, label AS current_name
        FROM practice_assignment_labels
        WHERE practice_id = :practice_id AND is_lab = 1
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $labs['label:' . $row['entity_id']] = [
            'type' => 'label', 'entityId' => (int)$row['entity_id'],
            'name' => $row['current_name'], 'isLive' => true,
        ];
    }

    return $labs;
}

function labPerfLabKey(array $period) {
    return $period['assignee_type'] . ':' . ($period['assignee_type'] === 'user' ? $period['user_id'] : $period['label_id']);
}

/**
 * All lab assignment periods for the practice (open + closed, any quality),
 * permission-scoped through authorized_case_ids (same policy as
 * canUserAccessCase; ensureAuthorizedCaseIdsTempTable handles the
 * non-limited/no-session case by admitting all practice cases).
 */
function labPerfFetchPeriods($pdo, $practiceId) {
    $authorizedJoin = labPerfAuthorizedJoin($pdo, $practiceId);
    $stmt = $pdo->prepare("
        SELECT p.id, p.case_id, p.assignee_type, p.user_id, p.label_id,
               p.assignee_display_name_snapshot, p.started_at, p.ended_at,
               p.end_reason, p.history_quality,
               p.case_type_snapshot, p.due_date_snapshot
        FROM case_lab_assignment_periods p
        $authorizedJoin
        WHERE p.practice_id = :practice_id AND p.is_lab_snapshot = 1
        ORDER BY p.case_id ASC, p.started_at ASC, p.id ASC
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Builds the authorized_case_ids temp table for this connection (idempotent;
 * request-time callers may already have built it) and returns the JOIN
 * fragment used by period queries.
 */
function labPerfAuthorizedJoin($pdo, $practiceId) {
    if (function_exists('ensureAuthorizedCaseIdsTempTable')) {
        ensureAuthorizedCaseIdsTempTable($practiceId);
    }
    return "INNER JOIN authorized_case_ids a ON a.case_id = p.case_id COLLATE utf8mb4_unicode_ci";
}

/**
 * Case rows needed for metric joins (no PII fields selected).
 */
function labPerfFetchCases($pdo, $practiceId, array $caseIds) {
    $casesById = [];
    foreach (array_chunk(array_values($caseIds), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("
            SELECT case_id, status, due_date, archived, case_type, assigned_to,
                   demo_generation_run_id
            FROM cases_cache
            WHERE practice_id = ? AND case_id IN ($ph)
        ");
        $stmt->execute(array_merge([$practiceId], $chunk));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $casesById[$row['case_id']] = $row;
        }
    }
    return $casesById;
}

/**
 * All structured remake events for the practice.
 */
function labPerfFetchRemakes($pdo, $practiceId) {
    $stmt = $pdo->prepare("
        SELECT id, case_id, remake_number, reason_code, attribution, notes,
               initiated_at, completed_at, lab_period_id, created_by_user_id
        FROM case_remake_events
        WHERE practice_id = :practice_id
        ORDER BY case_id ASC, remake_number ASC
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Map case_id => [DateTimeImmutable, ...] of every case_updated event whose
 * changed_fields included dueDate — the same mutable-due-date reliability
 * guard the legacy Lab Insights late-delivery metric uses.
 */
function labPerfFetchDueDateEdits($pdo, array $caseIds) {
    $map = [];
    foreach (array_chunk(array_values($caseIds), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("
            SELECT case_id, created_at, meta_json
            FROM case_activity_log
            WHERE event_type = 'case_updated' AND case_id IN ($ph)
        ");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode($row['meta_json'] ?? '', true);
            $changed = is_array($meta) ? ($meta['changed_fields'] ?? []) : [];
            if (is_array($changed) && in_array('dueDate', $changed, true)) {
                try { $map[$row['case_id']][] = new DateTimeImmutable($row['created_at']); } catch (Exception $e) {}
            }
        }
    }
    return $map;
}

// ── Resolution helpers ──────────────────────────────────────────────────────

/** Case type for a period: snapshot first, current value as flagged fallback, else 'Unknown'. */
function labPerfCaseType(array $period, ?array $caseRow) {
    $snap = trim((string)($period['case_type_snapshot'] ?? ''));
    if ($snap !== '') { return $snap; }
    $current = $caseRow ? trim((string)($caseRow['case_type'] ?? '')) : '';
    return $current !== '' ? $current : 'Unknown';
}

function labPerfMedian(array $values) {
    if (empty($values)) { return null; }
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

function labPerfInWindow($ts, ?DateTimeImmutable $winStart, DateTimeImmutable $winEnd) {
    if (!$ts) { return false; }
    try { $d = new DateTimeImmutable($ts); } catch (Exception $e) { return false; }
    return ($winStart === null || $d >= $winStart) && $d < $winEnd;
}

function labPerfDueDateChangedAfter($caseId, DateTimeImmutable $after, array $editMap) {
    foreach (($editMap[$caseId] ?? []) as $ts) {
        if ($ts > $after) { return true; }
    }
    return false;
}

/** seconds -> rounded days (1 decimal) */
function labPerfDays($seconds) {
    return round($seconds / 86400, 1);
}

function labPerfPct($numerator, $denominator) {
    return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : null;
}

// ── Core aggregation ────────────────────────────────────────────────────────

/**
 * Aggregate one analysis window over already-fetched context. Returns
 * ['labs' => [labKey => metrics], 'practice' => pooled metrics].
 * $ctx keys: periods, periodsById, casesById, remakes, labs, dueEdits,
 * terminalStatuses, now.
 */
function labPerfAggregateWindow(array $ctx, ?DateTimeImmutable $winStart, DateTimeImmutable $winEnd) {
    $now = $ctx['now'];
    $init = function () {
        return [
            'volumeCaseIds' => [], 'volumeEngagements' => 0,
            'completedByCase' => [],          // case_id => ['seconds'=>sum,'periods'=>n]
            'completedEngagements' => 0,
            'finalDelivered' => [],           // case_id => period row (latest)
            'typeStats' => [],                // caseType => buckets
            'endedInWindow' => 0,             // coverage denominator for turnaround
            'inWindowPeriods' => 0,           // coverage denominator for case type
            'inWindowKnownType' => 0,
        ];
    };
    $agg = [];     // labKey => buckets
    $practice = $init();

    foreach ($ctx['periods'] as $p) {
        $labKey = labPerfLabKey($p);
        if (!isset($agg[$labKey])) { $agg[$labKey] = $init(); }
        $caseId = $p['case_id'];
        $caseRow = $ctx['casesById'][$caseId] ?? null;
        $caseType = labPerfCaseType($p, $caseRow);

        // ── Volume: period STARTED inside the window ──
        if (labPerfInWindow($p['started_at'], $winStart, $winEnd)) {
            $agg[$labKey]['volumeCaseIds'][$caseId] = true;
            $agg[$labKey]['volumeEngagements']++;
            $practice['volumeCaseIds'][$caseId] = true;
            $practice['volumeEngagements']++;
            $agg[$labKey]['inWindowPeriods']++;
            $practice['inWindowPeriods']++;
            if ($caseType !== 'Unknown') {
                $agg[$labKey]['inWindowKnownType']++;
                $practice['inWindowKnownType']++;
            }
            if (!isset($agg[$labKey]['typeStats'][$caseType])) { $agg[$labKey]['typeStats'][$caseType] = $init(); }
            $agg[$labKey]['typeStats'][$caseType]['volumeCaseIds'][$caseId] = true;
            $agg[$labKey]['typeStats'][$caseType]['volumeEngagements']++;
            if (!isset($practice['typeStats'][$caseType])) { $practice['typeStats'][$caseType] = $init(); }
            $practice['typeStats'][$caseType]['volumeCaseIds'][$caseId] = true;
            $practice['typeStats'][$caseType]['volumeEngagements']++;
        }

        // ── Completed / turnaround population: observed, delivered, positive
        // duration, ENDED inside the window ──
        if ($p['ended_at'] !== null && labPerfInWindow($p['ended_at'], $winStart, $winEnd)) {
            $agg[$labKey]['endedInWindow']++;
            $practice['endedInWindow']++;
        }
        $isCompleted = $p['history_quality'] === 'observed'
            && $p['ended_at'] !== null
            && $p['end_reason'] === 'delivered'
            && labPerfInWindow($p['ended_at'], $winStart, $winEnd);
        if ($isCompleted) {
            $seconds = strtotime($p['ended_at']) - strtotime($p['started_at']);
            if ($seconds > 0) {
                if (!isset($agg[$labKey]['completedByCase'][$caseId])) {
                    $agg[$labKey]['completedByCase'][$caseId] = ['seconds' => 0, 'periods' => 0];
                }
                $agg[$labKey]['completedByCase'][$caseId]['seconds'] += $seconds;
                $agg[$labKey]['completedByCase'][$caseId]['periods']++;
                $agg[$labKey]['completedEngagements']++;

                if (!isset($practice['completedByCase'][$caseId])) {
                    $practice['completedByCase'][$caseId] = ['seconds' => 0, 'periods' => 0];
                }
                $practice['completedByCase'][$caseId]['seconds'] += $seconds;
                $practice['completedByCase'][$caseId]['periods']++;
                $practice['completedEngagements']++;

                // Final delivered period per case (latest ended_at, tie-break id).
                $cur = $agg[$labKey]['finalDelivered'][$caseId] ?? null;
                if (!$cur || strcmp($p['ended_at'], $cur['ended_at']) > 0
                    || ($p['ended_at'] === $cur['ended_at'] && (int)$p['id'] > (int)$cur['id'])) {
                    $agg[$labKey]['finalDelivered'][$caseId] = $p;
                }
                $curP = $practice['finalDelivered'][$caseId] ?? null;
                if (!$curP || strcmp($p['ended_at'], $curP['ended_at']) > 0
                    || ($p['ended_at'] === $curP['ended_at'] && (int)$p['id'] > (int)$curP['id'])) {
                    $practice['finalDelivered'][$caseId] = $p;
                }

                // Per-type completed buckets (type resolved from THIS period).
                foreach ([&$agg[$labKey], &$practice] as &$scope) {
                    if (!isset($scope['typeStats'][$caseType])) { $scope['typeStats'][$caseType] = $init(); }
                    if (!isset($scope['typeStats'][$caseType]['completedByCase'][$caseId])) {
                        $scope['typeStats'][$caseType]['completedByCase'][$caseId] = ['seconds' => 0, 'periods' => 0];
                    }
                    $scope['typeStats'][$caseType]['completedByCase'][$caseId]['seconds'] += $seconds;
                    $scope['typeStats'][$caseType]['completedByCase'][$caseId]['periods']++;
                    $scope['typeStats'][$caseType]['completedEngagements']++;
                    $curT = $scope['typeStats'][$caseType]['finalDelivered'][$caseId] ?? null;
                    if (!$curT || strcmp($p['ended_at'], $curT['ended_at']) > 0
                        || ($p['ended_at'] === $curT['ended_at'] && (int)$p['id'] > (int)$curT['id'])) {
                        $scope['typeStats'][$caseType]['finalDelivered'][$caseId] = $p;
                    }
                }
                unset($scope);
            }
        }
    }

    // ── Remakes: initiated_at in window; duration: completed_at in window ──
    $remakeAgg = [];   // labKey => buckets
    $remakePractice = [
        'total' => 0, 'cases' => [], 'counts' => [], 'labAttrCases' => [],
        'reasons' => [], 'attributions' => [], 'durations' => [], 'unlinked' => 0,
        'byType' => [], // caseType => ['events'=>n,'cases'=>[]]
    ];
    foreach ($ctx['remakes'] as $r) {
        $period = !empty($r['lab_period_id']) ? ($ctx['periodsById'][$r['lab_period_id']] ?? null) : null;
        $labKey = $period ? labPerfLabKey($period) : null;
        $caseId = $r['case_id'];
        $caseRow = $ctx['casesById'][$caseId] ?? null;
        $rType = $period ? labPerfCaseType($period, $caseRow)
                         : ($caseRow && trim((string)$caseRow['case_type']) !== '' ? trim($caseRow['case_type']) : 'Unknown');

        if (labPerfInWindow($r['initiated_at'], $winStart, $winEnd)) {
            $buckets = [];
            if ($labKey !== null) {
                if (!isset($remakeAgg[$labKey])) {
                    $remakeAgg[$labKey] = ['total'=>0,'cases'=>[],'counts'=>[],'labAttrCases'=>[],'reasons'=>[],'attributions'=>[],'durations'=>[],'unlinked'=>0,'byType'=>[]];
                }
                $buckets[] = &$remakeAgg[$labKey];
            }
            $buckets[] = &$remakePractice;
            foreach ($buckets as &$b) {
                $b['total']++;
                $b['cases'][$caseId] = true;
                $b['counts'][$caseId] = ($b['counts'][$caseId] ?? 0) + 1;
                if ($r['attribution'] === 'lab_related') { $b['labAttrCases'][$caseId] = true; }
                $b['reasons'][$r['reason_code']] = ($b['reasons'][$r['reason_code']] ?? 0) + 1;
                $b['attributions'][$r['attribution']] = ($b['attributions'][$r['attribution']] ?? 0) + 1;
                if ($labKey === null) { $b['unlinked']++; }
                if (!isset($b['byType'][$rType])) { $b['byType'][$rType] = ['events'=>0,'cases'=>[]]; }
                $b['byType'][$rType]['events']++;
                $b['byType'][$rType]['cases'][$caseId] = true;
            }
            unset($b, $buckets);
        }

        // Duration uses completed_at in window (open remakes never included).
        if ($r['completed_at'] !== null && labPerfInWindow($r['completed_at'], $winStart, $winEnd)) {
            $dur = strtotime($r['completed_at']) - strtotime($r['initiated_at']);
            if ($dur >= 0) {
                if ($labKey !== null) {
                    if (!isset($remakeAgg[$labKey])) {
                        $remakeAgg[$labKey] = ['total'=>0,'cases'=>[],'counts'=>[],'labAttrCases'=>[],'reasons'=>[],'attributions'=>[],'durations'=>[],'unlinked'=>0,'byType'=>[]];
                    }
                    $remakeAgg[$labKey]['durations'][] = $dur;
                }
                $remakePractice['durations'][] = $dur;
            }
        }
    }

    // ── Emit per-lab + practice metrics from the buckets ──
    $emit = function ($b, array $ctx) use ($winStart, $winEnd) {
        $volumeCases = count($b['volumeCaseIds']);
        $completedCases = count($b['completedByCase']);
        $durations = array_map(function ($c) { return $c['seconds']; }, $b['completedByCase']);

        // On-time: final delivered period per case; due date = snapshot, else
        // guarded current value. Missing/unreliable due date => excluded.
        $onTime = 0; $late = 0; $daysLateVals = []; $eligible = 0;
        foreach ($b['finalDelivered'] as $caseId => $fp) {
            $dueRaw = null;
            $snap = trim((string)($fp['due_date_snapshot'] ?? ''));
            if ($snap !== '') {
                $dueRaw = $snap;
            } else {
                $caseRow = $ctx['casesById'][$caseId] ?? null;
                $ended = new DateTimeImmutable($fp['ended_at']);
                if ($caseRow && !empty($caseRow['due_date'])
                    && !labPerfDueDateChangedAfter($caseId, $ended, $ctx['dueEdits'])) {
                    $dueRaw = $caseRow['due_date'];
                }
            }
            if ($dueRaw === null) { continue; }
            try {
                $due = new DateTimeImmutable(substr($dueRaw, 0, 10));
                $endedDate = new DateTimeImmutable(substr($fp['ended_at'], 0, 10));
            } catch (Exception $e) { continue; }
            $eligible++;
            if ($endedDate > $due) {
                $late++;
                $daysLateVals[] = $endedDate->diff($due)->days;
            } else {
                $onTime++;
            }
        }

        $out = [
            'volume' => ['uniqueCases' => $volumeCases, 'engagements' => $b['volumeEngagements']],
            'completed' => ['uniqueCases' => $completedCases, 'engagements' => $b['completedEngagements']],
            'turnaround' => [
                'avgDays' => $completedCases > 0 ? labPerfDays(array_sum($durations) / $completedCases) : null,
                'medianDays' => $completedCases > 0 ? labPerfDays(labPerfMedian($durations)) : null,
                'n' => $completedCases,
                'sufficient' => $completedCases >= LAB_PERF_MIN_SAMPLE,
            ],
            'onTime' => [
                'onTime' => $onTime, 'late' => $late, 'n' => $eligible,
                'pct' => labPerfPct($onTime, $eligible),
                'dueDateCoveragePct' => labPerfPct($eligible, $completedCases),
                'sufficient' => $eligible >= LAB_PERF_MIN_SAMPLE,
            ],
            'daysLate' => [
                'avgDays' => !empty($daysLateVals) ? round(array_sum($daysLateVals) / count($daysLateVals), 1) : null,
                'n' => count($daysLateVals),
            ],
            '_finalDelivered' => $b['finalDelivered'],
            '_typeStats' => $b['typeStats'],
            '_inWindowPeriods' => $b['inWindowPeriods'],
            '_inWindowKnownType' => $b['inWindowKnownType'],
            '_endedInWindow' => $b['endedInWindow'],
            '_volumeCaseIds' => $b['volumeCaseIds'],
        ];
        return $out;
    };

    $labs = [];
    foreach ($agg as $labKey => $b) {
        $labs[$labKey] = $emit($b, $ctx);
        $rb = $remakeAgg[$labKey] ?? null;
        $labs[$labKey]['remakes'] = labPerfEmitRemakes($rb, count($b['volumeCaseIds']));
    }
    $practiceOut = $emit($practice, $ctx);
    $practiceOut['remakes'] = labPerfEmitRemakes($remakePractice, count($practice['volumeCaseIds']));

    return ['labs' => $labs, 'practice' => $practiceOut];
}

/**
 * Shape remake buckets into the public metric block.
 * $volumeCases = denominator (unique lab cases in window).
 */
function labPerfEmitRemakes(?array $rb, $volumeCases) {
    if (!$rb) {
        $rb = ['total'=>0,'cases'=>[],'counts'=>[],'labAttrCases'=>[],'reasons'=>[],'attributions'=>[],'durations'=>[],'unlinked'=>0,'byType'=>[]];
    }
    $remadeCases = count($rb['cases']);
    $multiRemakeCases = count(array_filter($rb['counts'], function ($c) { return $c >= 2; }));
    $labAttrCases = count($rb['labAttrCases']);

    $reasons = $rb['reasons']; arsort($reasons);
    $attributions = $rb['attributions']; arsort($attributions);

    return [
        'total' => $rb['total'],
        'casesWithRemakes' => $remadeCases,
        'remakeRatePct' => labPerfPct($remadeCases, $volumeCases),
        'rateDenominator' => $volumeCases,
        'rateSufficient' => $volumeCases >= LAB_PERF_MIN_SAMPLE,
        'multiRemakeCases' => $multiRemakeCases,
        'multiRemakeRatePct' => labPerfPct($multiRemakeCases, $remadeCases),
        'labAttributedCases' => $labAttrCases,
        'labAttributedRatePct' => labPerfPct($labAttrCases, $volumeCases),
        'avgDurationDays' => !empty($rb['durations']) ? labPerfDays(array_sum($rb['durations']) / count($rb['durations'])) : null,
        'durationN' => count($rb['durations']),
        'reasons' => $reasons,
        'attributions' => $attributions,
        'unlinked' => $rb['unlinked'],
        '_byType' => $rb['byType'],
        '_counts' => $rb['counts'],
    ];
}

// ── Workload, coverage, case-type detail, trends, benchmarks ───────────────

/**
 * Current-state metrics (never range-filtered):
 *  - openPeriods/openCases: open lab periods whose case is live.
 *  - currentlyAssigned: active cases whose assigned_to resolves to a live lab
 *    name (the legacy workload rule, kept for the existing UI).
 *  - late: open-period cases currently past due on their current due_date.
 */
function labPerfWorkload(array $ctx, array $labs) {
    $now = $ctx['now'];
    $today = $now->modify('midnight');
    $perLab = [];
    foreach (array_keys($labs) as $k) {
        $perLab[$k] = ['openPeriods'=>0,'openCases'=>[],'late'=>0,'withDueDate'=>0,'currentlyAssigned'=>0];
    }

    foreach ($ctx['periods'] as $p) {
        if ($p['ended_at'] !== null) { continue; }
        $labKey = labPerfLabKey($p);
        if (!isset($perLab[$labKey])) {
            $perLab[$labKey] = ['openPeriods'=>0,'openCases'=>[],'late'=>0,'withDueDate'=>0,'currentlyAssigned'=>0];
        }
        $caseRow = $ctx['casesById'][$p['case_id']] ?? null;
        if (!$caseRow || (int)$caseRow['archived'] === 1
            || in_array($caseRow['status'], $ctx['terminalStatuses'], true)) {
            continue;
        }
        $perLab[$labKey]['openPeriods']++;
        $perLab[$labKey]['openCases'][$p['case_id']] = true;
        if (!empty($caseRow['due_date'])) {
            try {
                $due = new DateTimeImmutable(substr($caseRow['due_date'], 0, 10));
                $perLab[$labKey]['withDueDate']++;
                if ($due < $today) { $perLab[$labKey]['late']++; }
            } catch (Exception $e) {}
        }
    }

    // assigned_to -> live lab name (legacy workload definition).
    $liveNameToKey = [];
    foreach ($labs as $k => $info) {
        if ($info['isLive']) { $liveNameToKey[mb_strtolower(trim($info['name']))] = $k; }
    }
    foreach ($ctx['casesById'] as $caseRow) {
        if ((int)($caseRow['archived'] ?? 0) === 1) { continue; }
        if (in_array($caseRow['status'], $ctx['terminalStatuses'], true)) { continue; }
        if (empty($caseRow['assigned_to'])) { continue; }
        $k = $liveNameToKey[mb_strtolower(trim($caseRow['assigned_to']))] ?? null;
        if ($k === null) { continue; }
        if (!isset($perLab[$k])) {
            $perLab[$k] = ['openPeriods'=>0,'openCases'=>[],'late'=>0,'withDueDate'=>0,'currentlyAssigned'=>0];
        }
        $perLab[$k]['currentlyAssigned']++;
    }

    $out = [];
    foreach ($perLab as $k => $w) {
        $openCases = count($w['openCases']);
        $out[$k] = [
            'openCases' => $openCases,
            'openPeriods' => $w['openPeriods'],
            'currentlyAssigned' => $w['currentlyAssigned'],
            'late' => $w['late'],
            'lateRatePct' => labPerfPct($w['late'], $w['withDueDate']),
            'dueDateCoveragePct' => labPerfPct($w['withDueDate'], $openCases),
        ];
    }
    return $out;
}

/** Per-case-type detail for one lab's aggregated buckets. */
function labPerfCaseTypeDetail(array $typeStats, array $remakeByType, array $ctx) {
    $out = [];
    foreach ($typeStats as $type => $b) {
        $completedCases = count($b['completedByCase']);
        $durations = array_map(function ($c) { return $c['seconds']; }, $b['completedByCase']);

        $onTime = 0; $late = 0; $daysLateVals = []; $eligible = 0;
        foreach ($b['finalDelivered'] as $caseId => $fp) {
            $dueRaw = null;
            $snap = trim((string)($fp['due_date_snapshot'] ?? ''));
            if ($snap !== '') {
                $dueRaw = $snap;
            } else {
                $caseRow = $ctx['casesById'][$caseId] ?? null;
                $ended = new DateTimeImmutable($fp['ended_at']);
                if ($caseRow && !empty($caseRow['due_date'])
                    && !labPerfDueDateChangedAfter($caseId, $ended, $ctx['dueEdits'])) {
                    $dueRaw = $caseRow['due_date'];
                }
            }
            if ($dueRaw === null) { continue; }
            try {
                $due = new DateTimeImmutable(substr($dueRaw, 0, 10));
                $endedDate = new DateTimeImmutable(substr($fp['ended_at'], 0, 10));
            } catch (Exception $e) { continue; }
            $eligible++;
            if ($endedDate > $due) { $late++; $daysLateVals[] = $endedDate->diff($due)->days; }
            else { $onTime++; }
        }

        $rt = $remakeByType[$type] ?? ['events'=>0,'cases'=>[]];
        $volumeCases = count($b['volumeCaseIds']);
        $out[$type] = [
            'uniqueCases' => $volumeCases,
            'engagements' => $b['volumeEngagements'],
            'completedCases' => $completedCases,
            'avgTurnaroundDays' => $completedCases > 0 ? labPerfDays(array_sum($durations) / $completedCases) : null,
            'medianTurnaroundDays' => $completedCases > 0 ? labPerfDays(labPerfMedian($durations)) : null,
            'turnaroundN' => $completedCases,
            'onTimePct' => labPerfPct($onTime, $eligible),
            'onTimeN' => $eligible,
            'avgDaysLate' => !empty($daysLateVals) ? round(array_sum($daysLateVals)/count($daysLateVals), 1) : null,
            'remakes' => $rt['events'],
            'casesWithRemakes' => count($rt['cases']),
            'remakeRatePct' => labPerfPct(count($rt['cases']), $volumeCases),
            'sufficient' => $volumeCases >= LAB_PERF_MIN_SAMPLE,
        ];
    }
    // Remake-only types (no periods in window for that type) still surface.
    foreach ($remakeByType as $type => $rt) {
        if (!isset($out[$type])) {
            $out[$type] = [
                'uniqueCases'=>0,'engagements'=>0,'completedCases'=>0,
                'avgTurnaroundDays'=>null,'medianTurnaroundDays'=>null,'turnaroundN'=>0,
                'onTimePct'=>null,'onTimeN'=>0,'avgDaysLate'=>null,
                'remakes'=>$rt['events'],'casesWithRemakes'=>count($rt['cases']),
                'remakeRatePct'=>null,'sufficient'=>false,
            ];
        }
    }
    return $out;
}

/**
 * Monthly trend buckets for the window (raw data, not formatted text).
 * Per month: volume (periods started), completed/turnaround/on-time (periods
 * ended), remake events/rates (initiated).
 */
function labPerfTrends(array $ctx, array $labKeys, DateTimeImmutable $trendStart, DateTimeImmutable $winEnd) {
    $months = [];
    $cursor = $trendStart->modify('first day of this month midnight');
    while ($cursor <= $winEnd) {
        $months[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }

    $blank = function () use ($months) {
        return [
            'volumeUniqueCases' => array_fill_keys($months, 0),
            'volumeEngagements' => array_fill_keys($months, 0),
            'completedCases' => array_fill_keys($months, 0),
            'turnaroundDays' => array_fill_keys($months, null),
            'onTimePct' => array_fill_keys($months, null),
            'remakeEvents' => array_fill_keys($months, 0),
            'remakeRatePct' => array_fill_keys($months, null),
            'labAttrRemakeRatePct' => array_fill_keys($months, null),
            '_turnDurations' => array_fill_keys($months, null), // [month][caseId]=sec
            '_onTime' => array_fill_keys($months, null),        // [month]=>['on'=>n,'late'=>n]
            '_remadeCases' => array_fill_keys($months, null),
            '_labAttrCases' => array_fill_keys($months, null),
        ];
    };
    $series = ['practice' => $blank()];
    foreach ($labKeys as $k) { $series[$k] = $blank(); }

    foreach ($ctx['periods'] as $p) {
        $labKey = labPerfLabKey($p);
        if (!isset($series[$labKey])) { $series[$labKey] = $blank(); }
        $caseId = $p['case_id'];

        $sm = substr($p['started_at'], 0, 7);
        if (isset($series['practice']['volumeUniqueCases'][$sm])
            && new DateTimeImmutable($p['started_at']) >= $trendStart) {
            foreach ([$labKey, 'practice'] as $scope) {
                $series[$scope]['volumeEngagements'][$sm]++;
                $series[$scope]['_vol'][$sm][$caseId] = true;
            }
        }

        if ($p['history_quality'] === 'observed' && $p['ended_at'] !== null
            && $p['end_reason'] === 'delivered') {
            $em = substr($p['ended_at'], 0, 7);
            if (isset($series['practice']['completedCases'][$em])
                && new DateTimeImmutable($p['ended_at']) >= $trendStart) {
                $sec = strtotime($p['ended_at']) - strtotime($p['started_at']);
                if ($sec > 0) {
                    foreach ([$labKey, 'practice'] as $scope) {
                        $series[$scope]['_turnDurations'][$em][$caseId] =
                            ($series[$scope]['_turnDurations'][$em][$caseId] ?? 0) + $sec;
                    }
                    // On-time for the month is resolved after all periods are
                    // seen — record candidates, pick final per case below.
                    foreach ([$labKey, 'practice'] as $scope) {
                        $series[$scope]['_finalByMonth'][$em][$caseId][] = $p;
                    }
                }
            }
        }
    }

    foreach ($ctx['remakes'] as $r) {
        $im = substr($r['initiated_at'], 0, 7);
        if (!isset($series['practice']['remakeEvents'][$im])
            || new DateTimeImmutable($r['initiated_at']) < $trendStart) { continue; }
        $period = !empty($r['lab_period_id']) ? ($ctx['periodsById'][$r['lab_period_id']] ?? null) : null;
        $scopes = $period ? [labPerfLabKey($period), 'practice'] : ['practice'];
        foreach ($scopes as $scope) {
            if (!isset($series[$scope])) { continue; }
            $series[$scope]['remakeEvents'][$im]++;
            $series[$scope]['_remadeCases'][$im][$r['case_id']] = true;
            if ($r['attribution'] === 'lab_related') {
                $series[$scope]['_labAttrCases'][$im][$r['case_id']] = true;
            }
        }
    }

    // Finalize per-month aggregates.
    foreach ($series as $scope => &$s) {
        foreach ($months as $m) {
            $volCases = isset($s['_vol'][$m]) ? count($s['_vol'][$m]) : 0;
            $s['volumeUniqueCases'][$m] = $volCases;

            $durs = $s['_turnDurations'][$m] ?? [];
            $s['completedCases'][$m] = count($durs);
            $s['turnaroundDays'][$m] = !empty($durs)
                ? labPerfDays(array_sum($durs) / count($durs)) : null;

            // On-time: final delivered period per case within that month.
            $on = 0; $late = 0; $eligible = 0;
            foreach (($s['_finalByMonth'][$m] ?? []) as $caseId => $cands) {
                usort($cands, function ($a, $b) {
                    $c = strcmp($a['ended_at'], $b['ended_at']);
                    return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
                });
                $fp = end($cands);
                $dueRaw = trim((string)($fp['due_date_snapshot'] ?? ''));
                if ($dueRaw === '') {
                    $caseRow = $ctx['casesById'][$caseId] ?? null;
                    $ended = new DateTimeImmutable($fp['ended_at']);
                    if ($caseRow && !empty($caseRow['due_date'])
                        && !labPerfDueDateChangedAfter($caseId, $ended, $ctx['dueEdits'])) {
                        $dueRaw = $caseRow['due_date'];
                    }
                }
                if ($dueRaw === '') { continue; }
                try {
                    $due = new DateTimeImmutable(substr($dueRaw, 0, 10));
                    $endedDate = new DateTimeImmutable(substr($fp['ended_at'], 0, 10));
                } catch (Exception $e) { continue; }
                $eligible++;
                if ($endedDate > $due) { $late++; } else { $on++; }
            }
            $s['onTimePct'][$m] = labPerfPct($on, $eligible);

            $remade = isset($s['_remadeCases'][$m]) ? count($s['_remadeCases'][$m]) : 0;
            $labAttr = isset($s['_labAttrCases'][$m]) ? count($s['_labAttrCases'][$m]) : 0;
            $s['remakeRatePct'][$m] = labPerfPct($remade, $volCases);
            $s['labAttrRemakeRatePct'][$m] = labPerfPct($labAttr, $volCases);
        }
        unset($s['_turnDurations'], $s['_onTime'], $s['_remadeCases'],
              $s['_labAttrCases'], $s['_vol'], $s['_finalByMonth']);
    }
    unset($s);

    return ['bucket' => 'month', 'months' => $months, 'series' => $series];
}

// ── Public entry point ──────────────────────────────────────────────────────

/**
 * Compute the full Lab Performance metrics payload for a practice.
 *
 * @param PDO $pdo
 * @param int $practiceId  caller-validated practice scope
 * @param DateTimeImmutable|null $rangeStart  null = unbounded ('all')
 * @param DateTimeImmutable $now
 * @return array structured metrics (see file header for definitions)
 */
function computeLabPerformanceMetrics($pdo, $practiceId, ?DateTimeImmutable $rangeStart, DateTimeImmutable $now) {
    ensureLabAssignmentHistoryTable();
    if (function_exists('ensurePeriodSnapshotColumns')) { ensurePeriodSnapshotColumns(); }
    ensureCaseRemakeEventsTable();

    $winEnd = $now;

    $labs = labPerfLabUniverse($pdo, $practiceId);
    $periods = labPerfFetchPeriods($pdo, $practiceId);

    // Historical-only lab identities (removed/renamed labs keep their row).
    foreach ($periods as $p) {
        $key = labPerfLabKey($p);
        if (!isset($labs[$key])) {
            $labs[$key] = [
                'type' => $p['assignee_type'],
                'entityId' => (int)($p['assignee_type'] === 'user' ? $p['user_id'] : $p['label_id']),
                'name' => $p['assignee_display_name_snapshot'],
                'isLive' => false,
            ];
        }
    }

    $caseIds = [];
    foreach ($periods as $p) { $caseIds[$p['case_id']] = true; }

    $remakes = labPerfFetchRemakes($pdo, $practiceId);
    foreach ($remakes as $r) { $caseIds[$r['case_id']] = true; }

    // Include active cases for the assigned_to workload view.
    $stmt = $pdo->prepare("
        SELECT case_id, status, due_date, archived, case_type, assigned_to,
               demo_generation_run_id
        FROM cases_cache WHERE practice_id = :practice_id AND archived = 0
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $activeCases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activeCases as $row) { $caseIds[$row['case_id']] = true; }

    $casesById = labPerfFetchCases($pdo, $practiceId, array_keys($caseIds));
    foreach ($activeCases as $row) { $casesById[$row['case_id']] = $row; }

    $periodsById = [];
    foreach ($periods as $p) { $periodsById[$p['id']] = $p; }

    $ctx = [
        'periods' => $periods,
        'periodsById' => $periodsById,
        'casesById' => $casesById,
        'remakes' => $remakes,
        'labs' => $labs,
        'dueEdits' => labPerfFetchDueDateEdits($pdo, array_keys($caseIds)),
        'terminalStatuses' => [getLastActiveWorkflowColumnId($practiceId)],
        'now' => $now,
    ];

    // ── Current window ──
    $current = labPerfAggregateWindow($ctx, $rangeStart, $winEnd);

    // ── Previous comparable window (same length immediately before) ──
    $previous = null;
    if ($rangeStart !== null) {
        $windowSeconds = $winEnd->getTimestamp() - $rangeStart->getTimestamp();
        $prevEnd = $rangeStart;
        $prevStart = $rangeStart->modify('-' . $windowSeconds . ' seconds');
        $previous = labPerfAggregateWindow($ctx, $prevStart, $prevEnd);
    }

    // ── Workload + open remakes (current state, never range-filtered) ──
    $workload = labPerfWorkload($ctx, $labs);
    $openRemakesByLab = [];
    $openRemakesTotal = 0;
    foreach ($remakes as $r) {
        if ($r['completed_at'] !== null) { continue; }
        $openRemakesTotal++;
        $p = !empty($r['lab_period_id']) ? ($periodsById[$r['lab_period_id']] ?? null) : null;
        if ($p) {
            $k = labPerfLabKey($p);
            $openRemakesByLab[$k] = ($openRemakesByLab[$k] ?? 0) + 1;
        }
    }

    // ── Compose per-lab output ──
    $labOut = [];
    $practiceMetrics = $current['practice'];
    foreach ($labs as $labKey => $info) {
        $m = $current['labs'][$labKey] ?? null;
        $rm = $m ? $m['remakes'] : labPerfEmitRemakes(null, 0);
        $wl = $workload[$labKey] ?? ['openCases'=>0,'openPeriods'=>0,'currentlyAssigned'=>0,'late'=>0,'lateRatePct'=>null,'dueDateCoveragePct'=>null];

        $volumeCases = $m ? $m['volume']['uniqueCases'] : 0;
        $completed = $m ? $m['completed']['uniqueCases'] : 0;
        $endedInWindow = $m ? $m['_endedInWindow'] : 0;
        $inWindowPeriods = $m ? $m['_inWindowPeriods'] : 0;
        $inWindowKnownType = $m ? $m['_inWindowKnownType'] : 0;

        $typeDetail = $m ? labPerfCaseTypeDetail($m['_typeStats'], $rm['_byType'], $ctx) : [];

        // Benchmarks vs pooled practice (both sides need min sample).
        $vsPractice = [];
        if ($m) {
            $pairs = [
                'avgTurnaroundDays' => [$m['turnaround']['avgDays'], $practiceMetrics['turnaround']['avgDays'],
                                        $m['turnaround']['n'], $practiceMetrics['turnaround']['n'], 'lower'],
                'onTimePct' => [$m['onTime']['pct'], $practiceMetrics['onTime']['pct'],
                                $m['onTime']['n'], $practiceMetrics['onTime']['n'], 'higher'],
                'remakeRatePct' => [$rm['remakeRatePct'], $practiceMetrics['remakes']['remakeRatePct'],
                                    $rm['rateDenominator'], $practiceMetrics['remakes']['rateDenominator'], 'lower'],
                'labAttrRemakeRatePct' => [$rm['labAttributedRatePct'], $practiceMetrics['remakes']['labAttributedRatePct'],
                                          $rm['rateDenominator'], $practiceMetrics['remakes']['rateDenominator'], 'lower'],
            ];
            foreach ($pairs as $metric => $cfg) {
                [$labV, $pracV, $labN, $pracN] = $cfg;
                $sufficient = $labV !== null && $pracV !== null
                    && $labN >= LAB_PERF_MIN_SAMPLE && $pracN >= LAB_PERF_MIN_SAMPLE;
                $vsPractice[$metric] = [
                    'lab' => $labV, 'practice' => $pracV,
                    'delta' => $sufficient ? round($labV - $pracV, 1) : null,
                    'sufficient' => $sufficient,
                ];
            }
        }

        // Benchmarks vs previous same-length window.
        $prevBench = null;
        if ($previous !== null) {
            $pm = $previous['labs'][$labKey] ?? null;
            $pr = $pm ? $pm['remakes'] : labPerfEmitRemakes(null, 0);
            $prevBench = [
                'volumeUniqueCases' => [
                    'current' => $volumeCases,
                    'previous' => $pm ? $pm['volume']['uniqueCases'] : 0,
                    'delta' => $volumeCases - ($pm ? $pm['volume']['uniqueCases'] : 0),
                ],
                'avgTurnaroundDays' => [
                    'current' => $m ? $m['turnaround']['avgDays'] : null,
                    'previous' => $pm ? $pm['turnaround']['avgDays'] : null,
                    'delta' => ($m && $pm && $m['turnaround']['avgDays'] !== null && $pm['turnaround']['avgDays'] !== null)
                        ? round($m['turnaround']['avgDays'] - $pm['turnaround']['avgDays'], 1) : null,
                ],
                'onTimePct' => [
                    'current' => $m ? $m['onTime']['pct'] : null,
                    'previous' => $pm ? $pm['onTime']['pct'] : null,
                    'delta' => ($m && $pm && $m['onTime']['pct'] !== null && $pm['onTime']['pct'] !== null)
                        ? round($m['onTime']['pct'] - $pm['onTime']['pct'], 1) : null,
                ],
                'remakeRatePct' => [
                    'current' => $rm['remakeRatePct'],
                    'previous' => $pr['remakeRatePct'],
                    'delta' => ($rm['remakeRatePct'] !== null && $pr['remakeRatePct'] !== null)
                        ? round($rm['remakeRatePct'] - $pr['remakeRatePct'], 1) : null,
                ],
            ];
        }

        // Case-type benchmarks: this lab vs the same case type pooled across
        // OTHER labs (min sample on both sides).
        $caseTypeBenchmarks = [];
        if ($m) {
            foreach ($typeDetail as $type => $td) {
                $otherCompleted = 0; $otherSeconds = 0; $otherOn = 0; $otherEligible = 0;
                foreach ($current['labs'] as $otherKey => $om) {
                    if ($otherKey === $labKey) { continue; }
                    $od = labPerfCaseTypeDetail($om['_typeStats'], $om['remakes']['_byType'], $ctx);
                    if (!isset($od[$type])) { continue; }
                    $otherCompleted += $od[$type]['turnaroundN'];
                    // Recompute pooled turnaround from raw buckets.
                    foreach (($om['_typeStats'][$type]['completedByCase'] ?? []) as $cid => $cc) {
                        $otherSeconds += $cc['seconds'];
                    }
                    if ($od[$type]['onTimeN'] > 0 && $od[$type]['onTimePct'] !== null) {
                        // pooled on-time needs raw counts; derive from pct*n
                        $otherEligible += $od[$type]['onTimeN'];
                        $otherOn += round($od[$type]['onTimePct'] * $od[$type]['onTimeN'] / 100);
                    }
                }
                $otherAvg = $otherCompleted > 0 ? labPerfDays($otherSeconds / $otherCompleted) : null;
                $otherOnTimePct = labPerfPct($otherOn, $otherEligible);
                $bench = [];
                if ($td['turnaroundN'] >= LAB_PERF_MIN_SAMPLE && $otherCompleted >= LAB_PERF_MIN_SAMPLE
                    && $td['avgTurnaroundDays'] !== null && $otherAvg !== null) {
                    $bench['avgTurnaroundDays'] = [
                        'lab' => $td['avgTurnaroundDays'], 'otherLabs' => $otherAvg,
                        'delta' => round($td['avgTurnaroundDays'] - $otherAvg, 1),
                    ];
                }
                if ($td['onTimeN'] >= LAB_PERF_MIN_SAMPLE && $otherEligible >= LAB_PERF_MIN_SAMPLE
                    && $td['onTimePct'] !== null && $otherOnTimePct !== null) {
                    $bench['onTimePct'] = [
                        'lab' => $td['onTimePct'], 'otherLabs' => $otherOnTimePct,
                        'delta' => round($td['onTimePct'] - $otherOnTimePct, 1),
                    ];
                }
                if (!empty($bench)) { $caseTypeBenchmarks[$type] = $bench; }
            }
        }

        $labOut[] = [
            'labKey' => $labKey,
            'type' => $info['type'],
            'entityId' => $info['entityId'],
            'name' => $info['name'],
            'isLive' => $info['isLive'],
            'volume' => $m ? $m['volume'] : ['uniqueCases'=>0,'engagements'=>0],
            'completed' => $m ? $m['completed'] : ['uniqueCases'=>0,'engagements'=>0],
            'turnaround' => $m ? $m['turnaround'] : ['avgDays'=>null,'medianDays'=>null,'n'=>0,'sufficient'=>false],
            'onTime' => $m ? $m['onTime'] : ['onTime'=>0,'late'=>0,'n'=>0,'pct'=>null,'dueDateCoveragePct'=>null,'sufficient'=>false],
            'daysLate' => $m ? $m['daysLate'] : ['avgDays'=>null,'n'=>0],
            'workload' => $wl,
            'caseTypes' => $typeDetail,
            'remakes' => array_merge(
                array_diff_key($rm, ['_byType'=>1,'_counts'=>1]),
                ['openRemakes' => ($openRemakesByLab[$labKey] ?? 0)]
            ),
            'coverage' => [
                'dueDatePct' => labPerfPct($m ? $m['onTime']['n'] : 0, $completed),
                'caseTypePct' => labPerfPct($inWindowKnownType, $inWindowPeriods),
                'turnaroundPct' => labPerfPct($m ? $m['completed']['engagements'] : 0, $endedInWindow),
                'remakeLinkagePct' => labPerfPct($rm['total'] - $rm['unlinked'], $rm['total']),
            ],
            'benchmarks' => [
                'vsPractice' => $vsPractice,
                'previousPeriod' => $prevBench,
                'caseTypes' => $caseTypeBenchmarks,
            ],
        ];
    }

    // ── Practice summary ──
    $practiceRemakes = $practiceMetrics['remakes'];
    $practiceTypeDetail = labPerfCaseTypeDetail($practiceMetrics['_typeStats'], $practiceRemakes['_byType'], $ctx);
    $practiceSummary = [
        'volume' => $practiceMetrics['volume'],
        'labsUsed' => count(array_filter($labOut, function ($l) { return $l['volume']['uniqueCases'] > 0; })),
        'completed' => $practiceMetrics['completed'],
        'turnaround' => $practiceMetrics['turnaround'],
        'onTime' => $practiceMetrics['onTime'],
        'daysLate' => $practiceMetrics['daysLate'],
        'remakes' => array_merge(
            array_diff_key($practiceRemakes, ['_byType'=>1,'_counts'=>1]),
            ['openRemakes' => $openRemakesTotal]
        ),
        'openRemakes' => $openRemakesTotal,
        'caseTypes' => $practiceTypeDetail,
        'topReasons' => array_slice($practiceRemakes['reasons'], 0, 5, true),
        'topAttributions' => array_slice($practiceRemakes['attributions'], 0, 6, true),
    ];

    // ── Trends: monthly buckets over the range (cap 24, min observed span) ──
    $trendStart = $rangeStart !== null
        ? $rangeStart
        : $now->modify('-12 months');
    $trends = labPerfTrends($ctx, array_keys($labs), $trendStart, $winEnd);

    // ── Population + coverage meta ──
    $demoCases = count(array_filter($casesById, function ($c) {
        return !empty($c['demo_generation_run_id']);
    }));
    $population = [
        'uniqueLabCases' => $practiceMetrics['volume']['uniqueCases'],
        'totalPeriods' => count($periods),
        'demoCases' => $demoCases,
        'openRemakes' => $practiceSummary['openRemakes'],
    ];

    return [
        'range' => [
            'start' => $rangeStart ? $rangeStart->format('Y-m-d') : null,
            'end' => $winEnd->format('Y-m-d'),
            'unbounded' => $rangeStart === null,
        ],
        'population' => $population,
        'practice' => $practiceSummary,
        'labs' => array_values($labOut),
        'trends' => $trends,
        'meta' => [
            'minSampleSize' => LAB_PERF_MIN_SAMPLE,
            'definitions' => [
                'volume' => 'Periods started within range; uniqueCases dedupes repeated assignments of the same case.',
                'completed' => 'Observed periods ended via delivered within range with positive duration.',
                'turnaround' => 'Per-case summed observed delivered time at this lab; backfilled/archive/deletion closes excluded.',
                'onTime' => 'Final delivered period per case vs due_date_snapshot (fallback: guarded current due_date). Missing due date excluded.',
                'remakeRate' => 'Unique cases with >=1 remake initiated in range / unique lab cases in range.',
                'labAttrRemakeRate' => 'Unique cases with >=1 lab_related remake / unique lab cases. Remakes are NOT lab failures by default.',
                'workload' => 'Current state, never range-filtered. openCases from open periods; currentlyAssigned from assigned_to (legacy rule).',
            ],
        ],
    ];
}
