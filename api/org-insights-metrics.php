<?php
/**
 * Organization Insights metrics service.
 *
 * Pure PHP + PDO: no session, headers, or output - callers own access
 * control. Consumed by api/get-org-insights.php and by
 * tests/org-insights-test.php.
 *
 * ── POPULATION RULES ───────────────────────────────────────────────────────
 *  - Scope: caller supplies the already-authorized practice list (from
 *    organization-access.php). Every query joins the per-request
 *    authorized_case_ids temp table (rebuilt per practice by
 *    ensureAuthorizedCaseIdsTempTable) so limited-visibility members only
 *    ever contribute the cases canUserAccessCase() would show them.
 *  - Workflow terminal ("completed") status is resolved PER PRACTICE via
 *    getLastActiveWorkflowColumnId() because custom workflow columns differ.
 *  - Archived cases stay in historical metrics - archiving is housekeeping,
 *    not cancellation. "Active" (current workload) excludes them.
 *  - Reopened cases: a case must CURRENTLY be in the terminal column to
 *    count as completed; a delivered-then-reopened case is active work, not
 *    a completion (same rule as get-analytics.php deliveredThisMonth).
 *  - No PHI is selected: only dates, statuses, case ids, and counts.
 *
 * ── METRIC DEFINITIONS ─────────────────────────────────────────────────────
 *  CURRENT WORKLOAD ("As of now" - never range-filtered):
 *   - active:        archived = 0 AND status != practice terminal column.
 *   - overdue:       active AND due date is at least $pastDueDays calendar
 *                    days in the past (viewer's past-due threshold - the
 *                    same definition the consolidated case list's "Late
 *                    cases" filter uses, so drill-down counts agree).
 *   - activeNoDue:   active cases with no due date (never overdue).
 *  HISTORICAL (range-filtered; the controlling date is named per metric):
 *   - created:       LEFT(creation_date,10) within [start, end] inclusive.
 *   - completed:     status = terminal AND deliveredAt in range, where
 *                    deliveredAt = COALESCE(cases_cache.status_changed_at,
 *                    latest case_activity_log status_changed -> terminal).
 *                    Missing timestamps mean the case never counts.
 *   - onTime rate:   of completed cases with a RELIABLE due date - due date
 *                    present AND not edited after delivery (case_updated
 *                    activity with changed_fields containing 'dueDate',
 *                    the same guard lab-performance-metrics.php uses).
 *                    delivered date <= due date = on time. Cases with no
 *                    due date or a post-delivery due-date edit are excluded
 *                    from the denominator and reported, never guessed.
 *   - labTurnaround: lab assignment-period sent->received days from
 *                    computeLabPerformanceMetrics(); only 'observed'
 *                    periods closed by 'delivered' qualify. This is lab
 *                    time at bench - NOT creation-to-completion.
 *   - labOnTime:     lab-perf on-time (final delivered period vs the
 *                    due_date snapshot captured when the lab engagement
 *                    opened; guarded current due date otherwise).
 *
 * ── RANGE RULES ────────────────────────────────────────────────────────────
 *  - Historical sections take [rangeStart, rangeEndInclusive]; a null
 *    rangeStart means unbounded below ('all time'). rangeEndInclusive is
 *    compared on calendar days in $opts['timezone'].
 *  - Trend buckets are calendar months in that timezone, capped at 24.
 *
 * ── LAB IDENTITY ───────────────────────────────────────────────────────────
 *  - 'user' labs carry a verified shared identity (users.id is global): the
 *    same lab account working in several authorized practices is merged
 *    into one row. 'label' labs are practice-scoped records with no shared
 *    identity, so rows stay (practice, label) pairs labelled by practice.
 *    Labs are never merged by display-name match alone.
 */

require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/lab-performance-metrics.php';
require_once __DIR__ . '/lab-performance-metrics.php';

const ORG_INSIGHTS_TZ = 'America/New_York';

/** Calendar-day difference (due minus today) like getCalendarDayDiff in get-analytics.php. */
function orgInsightsDayDiff($dateString, DateTimeImmutable $today) {
    if (empty($dateString)) {
        return null;
    }
    try {
        $due = new DateTimeImmutable(substr((string)$dateString, 0, 10), new DateTimeZone(ORG_INSIGHTS_TZ));
        return (int)$today->diff($due)->format('%r%a');
    } catch (Throwable $e) {
        return null;
    }
}

/** Parse 'YYYY-MM-DD' (or longer datetime; first 10 chars) to a tz-aware date, null on failure. */
function orgInsightsDate($value, ?DateTimeImmutable $fallback = null) {
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    try {
        return new DateTimeImmutable(substr($raw, 0, 10), new DateTimeZone(ORG_INSIGHTS_TZ));
    } catch (Throwable $e) {
        return $fallback;
    }
}

function orgInsightsInRange(?DateTimeImmutable $day, ?DateTimeImmutable $start, DateTimeImmutable $end) {
    if (!$day) {
        return false;
    }
    if ($start !== null && $day < $start) {
        return false;
    }
    return $day <= $end;
}

/**
 * @param PDO $pdo
 * @param array $practices  [['id'=>int,'name'=>string], ...] already authorized
 * @param array $opts [
 *   'rangeStart'  => ?DateTimeImmutable  null = unbounded below
 *   'rangeEnd'    => DateTimeImmutable   inclusive end day (tz-aware)
 *   'now'         => DateTimeImmutable   defaults to 'now' in ORG_INSIGHTS_TZ
 *   'pastDueDays' => int                 viewer's past-due threshold (default 1)
 *   'includeLabs' => bool                run the lab-performance aggregation
 * ]
 */
function computeOrgInsights(PDO $pdo, array $practices, array $opts) {
    $tz = new DateTimeZone(ORG_INSIGHTS_TZ);
    $now = $opts['now'] ?? new DateTimeImmutable('now', $tz);
    $today = $now->setTime(0, 0, 0);
    $rangeStart = $opts['rangeStart'] ?? null;
    $rangeEnd = ($opts['rangeEnd'] ?? $now)->setTime(0, 0, 0);
    $pastDueDays = max(0, (int)($opts['pastDueDays'] ?? 1));
    $includeLabs = !empty($opts['includeLabs']);

    $byPractice = [];
    $labRowsMerged = [];   // user labs merged by verified shared user id
    $labRowsScoped = [];   // label labs kept per (practice, label)

    $trendMonths = [];
    if ($rangeStart !== null) {
        $cursor = $rangeStart->modify('first day of this month')->setTime(0, 0, 0);
        $limit = $rangeEnd->modify('first day of this month');
        while ($cursor <= $limit && count($trendMonths) < 24) {
            $trendMonths[$cursor->format('Y-m')] = true;
            $cursor = $cursor->modify('+1 month');
        }
    }

    foreach ($practices as $p) {
        $pid = (int)$p['id'];
        $terminal = getLastActiveWorkflowColumnId($pid);

        // Rebuild the per-request authorized-case set for THIS practice so a
        // limited-visibility member only aggregates what they can open.
        ensureAuthorizedCaseIdsTempTable($pid);
        $authJoin = "INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci";

        // ── Current workload (as of now) ────────────────────────────────
        $stmt = $pdo->prepare("
            SELECT c.due_date
            FROM cases_cache c $authJoin
            WHERE c.practice_id = :pid AND c.archived = 0 AND c.status != :terminal
        ");
        $stmt->execute(['pid' => $pid, 'terminal' => $terminal]);
        $active = 0; $overdue = 0; $noDue = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $active++;
            $diff = orgInsightsDayDiff($row['due_date'], $today);
            if ($diff === null) {
                $noDue++;
            } elseif ($diff <= -$pastDueDays) {
                $overdue++;
            }
        }

        // ── Historical volume + completions ─────────────────────────────
        // One scan per practice: creation month buckets + delivered set.
        $stmt = $pdo->prepare("
            SELECT c.case_id, c.creation_date, c.status, c.status_changed_at,
                   c.due_date, l.delivered_at
            FROM cases_cache c $authJoin
            LEFT JOIN (
                SELECT case_id, MAX(created_at) AS delivered_at
                FROM case_activity_log
                WHERE event_type = 'status_changed' AND new_status = :terminal
                GROUP BY case_id
            ) l ON l.case_id = c.case_id
            WHERE c.practice_id = :pid
        ");
        $stmt->execute(['pid' => $pid, 'terminal' => $terminal]);

        $created = 0;
        $completedRows = [];   // case_id => delivered DateTimeImmutable
        $dueByCase = [];
        $trend = array_fill_keys(array_keys($trendMonths), [
            'created' => 0, 'completed' => 0, 'onTimeN' => 0, 'onTime' => 0,
        ]);
        $trendByMonth = function (DateTimeImmutable $day) { return $day->format('Y-m'); };

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $createdDay = orgInsightsDate($row['creation_date']);
            if (orgInsightsInRange($createdDay, $rangeStart, $rangeEnd)) {
                $created++;
                if (isset($trend[$trendByMonth($createdDay)])) {
                    $trend[$trendByMonth($createdDay)]['created']++;
                }
            }
            if ($row['status'] !== $terminal) {
                continue;
            }
            $delivered = orgInsightsDate($row['status_changed_at'] ?? '')
                ?? orgInsightsDate($row['delivered_at'] ?? '');
            if ($delivered && orgInsightsInRange($delivered, $rangeStart, $rangeEnd)) {
                $completedRows[$row['case_id']] = $delivered;
                $dueByCase[$row['case_id']] = $row['due_date'];
            }
        }

        // ── On-time completions (reliable due dates only) ───────────────
        $dueEdits = !empty($completedRows)
            ? labPerfFetchDueDateEdits($pdo, array_keys($completedRows))
            : [];
        $completed = count($completedRows);
        $onTime = 0; $eligible = 0; $exclNoDue = 0; $exclEdited = 0;
        foreach ($completedRows as $caseId => $deliveredDay) {
            $monthKey = $trendByMonth($deliveredDay);
            if (isset($trend[$monthKey])) {
                $trend[$monthKey]['completed']++;
            }
            $dueDay = orgInsightsDate($dueByCase[$caseId] ?? '');
            if (!$dueDay) {
                $exclNoDue++;
                continue;
            }
            // Due date edited after the delivery -> current value is not the
            // deadline the case was actually held to; excluded, not guessed.
            if (labPerfDueDateChangedAfter($caseId, $deliveredDay, $dueEdits)) {
                $exclEdited++;
                continue;
            }
            $eligible++;
            if (isset($trend[$monthKey])) {
                $trend[$monthKey]['onTimeN']++;
                if ($deliveredDay <= $dueDay) {
                    $trend[$monthKey]['onTime']++;
                }
            }
            if ($deliveredDay <= $dueDay) {
                $onTime++;
            }
        }

        // ── Lab performance (reuse the established service) ─────────────
        $labPerf = null;
        if ($includeLabs) {
            try {
                $labPerf = computeLabPerformanceMetrics($pdo, $pid, $rangeStart, $now);
                foreach (($labPerf['labs'] ?? []) as $lab) {
                    $key = $lab['type'] === 'user' ? 'user:' . $lab['entityId'] : 'label:' . $pid . ':' . $lab['entityId'];
                    $entry = [
                        'key'        => $key,
                        'type'       => $lab['type'],
                        'name'       => (string)($lab['name'] ?? ''),
                        'practiceId' => $pid,
                        'practices'  => [$p['name']],
                        'volume'     => (int)($lab['volume']['uniqueCases'] ?? 0),
                        'completed'  => (int)($lab['completed']['uniqueCases'] ?? 0),
                        'turnSum'    => null,   // weighted sums accumulated below
                        'turnN'      => 0,
                        'turnMedian' => $lab['turnaround']['medianDays'] ?? null,
                        'onTime'     => (int)($lab['onTime']['onTime'] ?? 0),
                        'onTimeN'    => (int)($lab['onTime']['n'] ?? 0),
                    ];
                    if (($lab['turnaround']['avgDays'] ?? null) !== null && ($lab['turnaround']['n'] ?? 0) > 0) {
                        $entry['turnSum'] = $lab['turnaround']['avgDays'] * $lab['turnaround']['n'];
                        $entry['turnN']   = (int)$lab['turnaround']['n'];
                    }
                    if ($lab['type'] === 'user') {
                        // Same verified user account across practices merges.
                        if (isset($labRowsMerged[$key])) {
                            $m = &$labRowsMerged[$key];
                            $m['volume'] += $entry['volume'];
                            $m['completed'] += $entry['completed'];
                            $m['onTime'] += $entry['onTime'];
                            $m['onTimeN'] += $entry['onTimeN'];
                            if ($entry['turnSum'] !== null) {
                                $m['turnSum'] = ($m['turnSum'] ?? 0) + $entry['turnSum'];
                                $m['turnN'] += $entry['turnN'];
                            }
                            if (!in_array($p['name'], $m['practices'], true)) {
                                $m['practices'][] = $p['name'];
                            }
                        } else {
                            $labRowsMerged[$key] = $entry;
                        }
                        unset($m);
                    } else {
                        $labRowsScoped[$key] = $entry;
                    }
                }
            } catch (Throwable $e) {
                error_log('[org-insights] lab metrics failed for practice ' . $pid . ': ' . $e->getMessage());
                $labPerf = null;
            }
        }

        $byPractice[] = [
            'practiceId'   => $pid,
            'practiceName' => (string)$p['name'],
            'workload' => [
                'active'          => $active,
                'overdue'         => $overdue,
                'overdueRatePct'  => $active > 0 ? round($overdue / $active * 100, 1) : null,
                'activeNoDueDate' => $noDue,
            ],
            'history' => [
                'created'   => $created,
                'completed' => $completed,
                'onTime'    => [
                    'onTime'               => $onTime,
                    'eligible'             => $eligible,
                    'pct'                  => $eligible > 0 ? round($onTime / $eligible * 100, 1) : null,
                    'excludedNoDueDate'    => $exclNoDue,
                    'excludedDueEdited'    => $exclEdited,
                ],
                'labTurnaround' => $labPerf ? ($labPerf['practice']['turnaround'] ?? null) : null,
            ],
            '_trend' => $trend,
        ];
    }

    // ── Totals + pooled trend ───────────────────────────────────────────
    $totals = [
        'workload' => ['active' => 0, 'overdue' => 0, 'activeNoDueDate' => 0],
        'history'  => ['created' => 0, 'completed' => 0, 'onTime' => 0, 'onTimeEligible' => 0,
                       'excludedNoDueDate' => 0, 'excludedDueEdited' => 0],
        'labTurnSum' => 0.0, 'labTurnN' => 0,
    ];
    $trendOut = [];
    foreach ($byPractice as &$pr) {
        $t = $pr['_trend'];
        unset($pr['_trend']);
        foreach ($t as $month => $v) {
            if (!isset($trendOut[$month])) {
                $trendOut[$month] = ['created' => 0, 'completed' => 0, 'onTimeN' => 0, 'onTime' => 0];
            }
            $trendOut[$month]['created'] += $v['created'];
            $trendOut[$month]['completed'] += $v['completed'];
            $trendOut[$month]['onTimeN'] += $v['onTimeN'];
            $trendOut[$month]['onTime'] += $v['onTime'];
        }
        $totals['workload']['active'] += $pr['workload']['active'];
        $totals['workload']['overdue'] += $pr['workload']['overdue'];
        $totals['workload']['activeNoDueDate'] += $pr['workload']['activeNoDueDate'];
        $totals['history']['created'] += $pr['history']['created'];
        $totals['history']['completed'] += $pr['history']['completed'];
        $totals['history']['onTime'] += $pr['history']['onTime']['onTime'];
        $totals['history']['onTimeEligible'] += $pr['history']['onTime']['eligible'];
        $totals['history']['excludedNoDueDate'] += $pr['history']['onTime']['excludedNoDueDate'];
        $totals['history']['excludedDueEdited'] += $pr['history']['onTime']['excludedDueEdited'];
        $lt = $pr['history']['labTurnaround'];
        if ($lt && ($lt['n'] ?? 0) > 0 && ($lt['avgDays'] ?? null) !== null) {
            $totals['labTurnSum'] += $lt['avgDays'] * $lt['n'];
            $totals['labTurnN'] += $lt['n'];
        }
    }
    unset($pr);

    $trendSeries = ['months' => [], 'created' => [], 'completed' => [], 'onTimePct' => []];
    ksort($trendOut);
    foreach ($trendOut as $month => $v) {
        $trendSeries['months'][] = $month;
        $trendSeries['created'][] = $v['created'];
        $trendSeries['completed'][] = $v['completed'];
        $trendSeries['onTimePct'][] = $v['onTimeN'] > 0 ? round($v['onTime'] / $v['onTimeN'] * 100, 1) : null;
    }

    $totals['workload']['overdueRatePct'] = $totals['workload']['active'] > 0
        ? round($totals['workload']['overdue'] / $totals['workload']['active'] * 100, 1) : null;
    $totals['history']['onTimePct'] = $totals['history']['onTimeEligible'] > 0
        ? round($totals['history']['onTime'] / $totals['history']['onTimeEligible'] * 100, 1) : null;
    $totals['history']['labTurnaroundAvgDays'] = $totals['labTurnN'] > 0
        ? round($totals['labTurnSum'] / $totals['labTurnN'], 1) : null;

    // ── Lab table rows ──────────────────────────────────────────────────
    $labRows = array_merge(array_values($labRowsMerged), array_values($labRowsScoped));
    foreach ($labRows as &$lr) {
        $lr['avgTurnaroundDays'] = $lr['turnN'] > 0 ? round($lr['turnSum'] / $lr['turnN'], 1) : null;
        $lr['onTimePct'] = $lr['onTimeN'] > 0 ? round($lr['onTime'] / $lr['onTimeN'] * 100, 1) : null;
        $lr['sufficient'] = $lr['volume'] >= LAB_PERF_MIN_SAMPLE;
        unset($lr['turnSum'], $lr['turnMedian']);
        sort($lr['practices']);
    }
    unset($lr);
    usort($labRows, function ($a, $b) { return $b['volume'] <=> $a['volume']; });

    return [
        'practices' => $byPractice,
        'totals'    => $totals,
        'labs'      => ['available' => $includeLabs, 'minSample' => LAB_PERF_MIN_SAMPLE, 'rows' => $labRows],
        'trend'     => $trendSeries,
        'meta'      => [
            'timezone' => ORG_INSIGHTS_TZ,
            'definitions' => [
                'active'        => 'Cases not archived and not in the practice\'s final workflow column, as of now.',
                'overdue'       => 'Active cases whose due date is at least the viewer\'s past-due threshold in the past (same rule as the Late cases filter).',
                'created'       => 'Cases whose creation date falls inside the reporting period.',
                'completed'     => 'Cases currently in the final workflow column whose completion timestamp falls inside the reporting period (status_changed_at, falling back to the latest recorded transition to the final column). Reopened cases do not count until they reach the final column again. Archived cases still count.',
                'onTime'        => 'Completed cases delivered on or before their due date, out of completions with a reliable due date (due date present and not edited after delivery).',
                'labTurnaround' => 'Average days a case spent at the lab, from observed lab engagements that ended by delivery. Not the same as total case age.',
                'labOnTime'     => 'Lab engagements completed on or before the due date captured when the engagement started.',
            ],
        ],
    ];
}
