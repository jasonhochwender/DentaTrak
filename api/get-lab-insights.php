<?php
/**
 * Lab Insights API
 *
 * Server-side, authoritative metrics for the Lab Insights v1 view. Computes
 * everything from `case_lab_assignment_periods` (never from
 * cases_cache.assigned_to directly) plus `cases_cache` and
 * `case_activity_log` for the current state / revision events those
 * periods point at.
 *
 * ACCESS CONTROL (all server-side, in this order):
 *   1. Valid session + active practice membership (requireValidPracticeContext)
 *   2. SHOW_LAB_INSIGHTS feature flag must be enabled
 *   3. can_view_analytics permission (same permission Practice Insights uses)
 *   4. hasControlAccess() - practice-level Control entitlement (never
 *      users.billing_tier)
 * Every query below is scoped to the validated active practice_id.
 *
 * DATE RANGE SEMANTICS (see also js/lab-insights.js):
 *   - `range` query param: 'all' | 3 | 6 | 12 | 24 (months). Default 12.
 *   - Cases handled / turnaround / revisions / trend are filtered by the
 *     relevant timestamp for that metric (assignment period start/end for
 *     handled+turnaround, case_activity_log.created_at for revisions).
 *   - Current workload and its late-count are ALWAYS computed from current
 *     state and are NEVER filtered by range - an assignment that began
 *     before the selected range must not disappear from "what a lab has
 *     right now".
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/billing-bypass.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/lab-assignment-history.php';

header('Content-Type: application/json');

// ── 1. Practice context + membership ────────────────────────────────────────
$practiceId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

// ── 2. Feature flag ──────────────────────────────────────────────────────────
if (!isFeatureEnabled('SHOW_LAB_INSIGHTS')) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

// ── 3. Analytics permission (independent of plan) ───────────────────────────
if (!canViewAnalytics($practiceId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. You do not have permission to view analytics.']);
    exit;
}

// ── 4. Control entitlement (authoritative, practice-level) ──────────────────
$userEmail = '';
if ($userId) {
    $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $emailStmt->execute([$userId]);
    $userEmail = (string)($emailStmt->fetchColumn() ?: '');
}

if (!hasControlAccess($pdo, $practiceId, $userEmail)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Lab Insights requires the Control plan', 'error_code' => 'upgrade_required']);
    exit;
}

ensureLabAssignmentHistoryTable();
ensureLabDesignationColumns();

// ── Date range ────────────────────────────────────────────────────────────
$rangeParam = $_GET['range'] ?? '12';
$rangeStart = null; // null = no lower bound ('all')
if ($rangeParam !== 'all') {
    $months = (int)$rangeParam;
    if ($months < 1) { $months = 1; }
    if ($months > 60) { $months = 60; }
    $rangeStart = (new DateTimeImmutable('now'))->modify("-{$months} months");
}
$now = new DateTimeImmutable('now');

// Authoritative terminal-status definition, taken directly from the app's
// own workflow stage order (api/update-case-status.php's $workflowStageOrder,
// where 'Delivered' is the final/highest stage) and matching the same check
// already used throughout api/get-analytics.php ("status != 'Delivered'").
// DentaTrak's case workflow is a fixed, non-configurable set of stages (no
// per-practice custom statuses), so 'Delivered' is the only terminal status
// today. This is expressed as a list (not a single literal) so a future
// additional terminal stage only requires updating this one array - every
// current-workload computation below (summary cards, lab table, and the
// workload drill-down) reads from this single source.
$TERMINAL_CASE_STATUSES = ['Delivered'];

function labIsLate($caseRow, DateTimeImmutable $now, array $terminalStatuses) {
    if (!$caseRow) { return false; }
    if ((int)$caseRow['archived'] === 1) { return false; }
    if (in_array($caseRow['status'], $terminalStatuses, true)) { return false; }
    if (empty($caseRow['due_date'])) { return false; }
    try {
        $due = new DateTimeImmutable(substr($caseRow['due_date'], 0, 10));
    } catch (Exception $e) {
        return false;
    }
    return $due < $now->modify('midnight');
}

try {
    // ── Lab identity universe: currently-designated labs UNION any identity
    // that appears in this practice's assignment history (so removed/renamed
    // labs still show up with their historical snapshot, per spec section 8). ─
    $labs = []; // key => ['type','entityId','currentName','snapshotName','isLive']

    $stmt = $pdo->prepare("
        SELECT pu.user_id AS entity_id, u.email AS current_name
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id AND pu.is_lab = 1
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = 'user:' . $row['entity_id'];
        $labs[$key] = [
            'type' => 'user', 'entityId' => (int)$row['entity_id'],
            'currentName' => $row['current_name'], 'snapshotName' => $row['current_name'], 'isLive' => true,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id AS entity_id, label AS current_name
        FROM practice_assignment_labels
        WHERE practice_id = :practice_id AND is_lab = 1
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = 'label:' . $row['entity_id'];
        $labs[$key] = [
            'type' => 'label', 'entityId' => (int)$row['entity_id'],
            'currentName' => $row['current_name'], 'snapshotName' => $row['current_name'], 'isLive' => true,
        ];
    }

    $hasLiveLabs = count($labs) > 0;

    // All assignment periods this practice has ever recorded for a lab
    // identity (is_lab_snapshot=1). One query, no per-lab N+1.
    $stmt = $pdo->prepare("
        SELECT case_id, assignee_type, user_id, label_id, assignee_display_name_snapshot,
               started_at, ended_at, end_reason, history_quality
        FROM case_lab_assignment_periods
        WHERE practice_id = :practice_id AND is_lab_snapshot = 1
        ORDER BY case_id ASC, started_at ASC
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $allPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasAnyHistory = count($allPeriods) > 0;

    // Add any historical-only identities (no longer live) using their
    // immutable snapshot name, so removed/renamed labs keep their row.
    foreach ($allPeriods as $p) {
        $key = $p['assignee_type'] . ':' . ($p['assignee_type'] === 'user' ? $p['user_id'] : $p['label_id']);
        if (!isset($labs[$key])) {
            $labs[$key] = [
                'type' => $p['assignee_type'],
                'entityId' => (int)($p['assignee_type'] === 'user' ? $p['user_id'] : $p['label_id']),
                'currentName' => $p['assignee_display_name_snapshot'],
                'snapshotName' => $p['assignee_display_name_snapshot'],
                'isLive' => false,
            ];
        }
    }

    if (!$hasLiveLabs && !$hasAnyHistory) {
        // Empty state: no labs configured, no history at all.
        echo json_encode([
            'success' => true,
            'hasLabs' => false,
            'hasHistory' => false,
            'onlyBackfilled' => false,
            'range' => $rangeParam,
            'summary' => null,
            'labs' => [],
            'currentWorkload' => [],
            'trend' => null,
        ]);
        exit;
    }

    // Group periods by case, and by lab identity.
    $periodsByCase = [];   // case_id => [period, ...] (ordered by started_at)
    $periodsByLab = [];    // labKey => [period, ...]
    $caseIds = [];
    foreach ($allPeriods as $p) {
        $labKey = $p['assignee_type'] . ':' . ($p['assignee_type'] === 'user' ? $p['user_id'] : $p['label_id']);
        $p['_labKey'] = $labKey;
        $periodsByCase[$p['case_id']][] = $p;
        $periodsByLab[$labKey][] = $p;
        $caseIds[$p['case_id']] = true;
    }
    $caseIds = array_keys($caseIds);

    $onlyBackfilled = $hasAnyHistory && !array_filter($allPeriods, function ($p) {
        return $p['history_quality'] === 'observed';
    });

    // Batched case lookup (status/due_date/archived) - no PII fields selected.
    $casesById = [];
    if (!empty($caseIds)) {
        $chunks = array_chunk($caseIds, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT case_id, status, due_date, archived, case_type
                FROM cases_cache
                WHERE practice_id = ? AND case_id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$practiceId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $casesById[$row['case_id']] = $row;
            }
        }
    }

    // Batched revision events for the same case set.
    $revisionsByCase = []; // case_id => [ ['created_at' => ...], ... ]
    if (!empty($caseIds)) {
        $chunks = array_chunk($caseIds, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT case_id, created_at
                FROM case_activity_log
                WHERE event_type = 'case_regression' AND case_id IN ($placeholders)
                ORDER BY case_id ASC, created_at ASC
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $revisionsByCase[$row['case_id']][] = $row['created_at'];
            }
        }
    }

    // Current-state workload is authoritative from cases_cache. A case is
    // "currently at a lab" when it is active (not archived, not terminal)
    // and its assigned_to resolves (case-insensitive, trimmed) to a
    // currently live lab name. This makes current workload independent of
    // whether an open case_lab_assignment_periods row exists.
    $liveLabByName = [];
    foreach ($labs as $labKey => $labInfo) {
        if (!$labInfo['isLive']) {
            continue;
        }
        $nameKey = mb_strtolower(trim($labInfo['currentName']));
        $liveLabByName[$nameKey] = $labKey;
    }

    $currentWorkloadCaseIds = [];   // labKey => [caseId => true]
    $currentWorkloadLateCount = []; // labKey => int
    $stmt = $pdo->prepare("
        SELECT case_id, status, due_date, archived, case_type, assigned_to
        FROM cases_cache
        WHERE practice_id = :practice_id AND archived = 0
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array($row['status'], $TERMINAL_CASE_STATUSES, true)) {
            continue;
        }
        if (empty($row['assigned_to'])) {
            continue;
        }
        $nameKey = mb_strtolower(trim($row['assigned_to']));
        $labKey = $liveLabByName[$nameKey] ?? null;
        if (!$labKey) {
            continue;
        }
        $currentWorkloadCaseIds[$labKey][$row['case_id']] = true;
        $casesById[$row['case_id']] = $row;
        if (labIsLate($row, $now, $TERMINAL_CASE_STATUSES)) {
            $currentWorkloadLateCount[$labKey] = ($currentWorkloadLateCount[$labKey] ?? 0) + 1;
        }
    }

    function periodOverlapsRange($period, ?DateTimeImmutable $rangeStart, DateTimeImmutable $now) {
        if ($rangeStart === null) { return true; }
        $started = new DateTimeImmutable($period['started_at']);
        $ended = $period['ended_at'] ? new DateTimeImmutable($period['ended_at']) : $now;
        return $ended >= $rangeStart && $started <= $now;
    }

    // ── Per-lab aggregation ──────────────────────────────────────────────
    $labMetrics = [];
    $multiLabCaseKeys = []; // case_id => set of lab keys (practice-wide)
    $totalTransfers = 0;

    foreach ($labs as $labKey => $labInfo) {
        $periods = $periodsByLab[$labKey] ?? [];

        $workloadCaseIds = $currentWorkloadCaseIds[$labKey] ?? [];
        $handledCaseIds = [];        // within range
        $handledCaseIdsAllTime = []; // for revision-rate denominator (any time), matches "handled" semantics
        $turnaroundByCase = [];      // case_id => summed observed seconds (this lab)
        $revisionAttributedCases = []; // case_id => true (>=1 attributed revision, within range)
        $revisionCountTotal = 0;
        $directTransfersOut = 0;

        foreach ($periods as $p) {
            $caseId = $p['case_id'];

            if ($p['end_reason'] === 'reassigned_to_lab') {
                $directTransfersOut++;
            }

            // "Handled" - at least one period of any quality, filtered to range
            // by whether the period overlaps the selected window.
            if (periodOverlapsRange($p, $rangeStart, $now)) {
                $handledCaseIds[$caseId] = true;
            }
            $handledCaseIdsAllTime[$caseId] = true;

            // Turnaround: observed periods only, must have a real end, and a
            // strictly positive duration. Backfilled/open periods excluded.
            if ($p['history_quality'] === 'observed' && $p['ended_at'] !== null) {
                $started = new DateTimeImmutable($p['started_at']);
                $ended = new DateTimeImmutable($p['ended_at']);
                $seconds = $ended->getTimestamp() - $started->getTimestamp();
                if ($seconds > 0 && periodOverlapsRange($p, $rangeStart, $now)) {
                    $turnaroundByCase[$caseId] = ($turnaroundByCase[$caseId] ?? 0) + $seconds;
                }
            }

            // Revision attribution: only from reliable (observed) periods.
            if ($p['history_quality'] === 'observed') {
                $periodStart = new DateTimeImmutable($p['started_at']);
                $periodEnd = $p['ended_at'] ? new DateTimeImmutable($p['ended_at']) : null;
                foreach (($revisionsByCase[$caseId] ?? []) as $revisionTs) {
                    $rev = new DateTimeImmutable($revisionTs);
                    $inWindow = $periodEnd
                        ? ($rev >= $periodStart && $rev < $periodEnd)
                        : ($rev >= $periodStart);
                    if ($inWindow && ($rangeStart === null || $rev >= $rangeStart)) {
                        $revisionCountTotal++;
                        $revisionAttributedCases[$caseId] = true;
                    }
                }
            }

            $multiLabCaseKeys[$caseId][$labKey] = true;
        }

        $turnaroundCaseCount = count($turnaroundByCase);
        $avgTurnaroundSeconds = $turnaroundCaseCount > 0
            ? array_sum($turnaroundByCase) / $turnaroundCaseCount
            : null;

        $currentWorkloadCount = count($workloadCaseIds);
        $lateInWorkload = $currentWorkloadLateCount[$labKey] ?? 0;

        $handledCount = count($handledCaseIds);
        $revisionRate = $handledCount > 0 ? (count($revisionAttributedCases) / $handledCount) * 100 : null;
        $lateRate = $currentWorkloadCount > 0 ? ($lateInWorkload / $currentWorkloadCount) * 100 : null;

        $totalTransfers += $directTransfersOut;

        $labMetrics[$labKey] = [
            'labKey' => $labKey,
            'type' => $labInfo['type'],
            'entityId' => $labInfo['entityId'],
            'name' => $labInfo['isLive'] ? $labInfo['currentName'] : $labInfo['snapshotName'],
            'isLive' => $labInfo['isLive'],
            'currentWorkload' => $currentWorkloadCount,
            'casesHandled' => $handledCount,
            'avgTurnaroundDays' => $avgTurnaroundSeconds !== null ? round($avgTurnaroundSeconds / 86400, 1) : null,
            'turnaroundSampleSize' => $turnaroundCaseCount,
            'lateCaseRate' => $lateRate !== null ? round($lateRate, 1) : null,
            'lateCaseCount' => $lateInWorkload,
            'revisionCount' => $revisionCountTotal,
            'revisionRate' => $revisionRate !== null ? round($revisionRate, 1) : null,
            'directTransfersOut' => $directTransfersOut,
            'currentWorkloadCaseIds' => array_keys($workloadCaseIds),
        ];
    }

    // Multi-lab case count (practice-wide, any time - identity, not time-boxed).
    $multiLabCaseCount = 0;
    foreach ($multiLabCaseKeys as $caseId => $labKeySet) {
        if (count($labKeySet) > 1) {
            $multiLabCaseCount++;
        }
    }

    // ── Current workload table (flat list across all labs) ──────────────
    $currentWorkload = [];
    foreach ($labMetrics as $labKey => $m) {
        foreach ($m['currentWorkloadCaseIds'] as $cid) {
            $caseRow = $casesById[$cid] ?? null;
            if (!$caseRow) { continue; }
            $daysLate = null;
            if (labIsLate($caseRow, $now, $TERMINAL_CASE_STATUSES)) {
                $due = new DateTimeImmutable(substr($caseRow['due_date'], 0, 10));
                $daysLate = $now->modify('midnight')->diff($due)->days;
            }
            $currentWorkload[] = [
                'caseId' => $cid,
                'caseType' => $caseRow['case_type'],
                'status' => $caseRow['status'],
                'dueDate' => $caseRow['due_date'] ?: null,
                'daysLate' => $daysLate,
                'lab' => $m['name'],
                'labKey' => $labKey,
            ];
        }
    }

    // ── Trend: cases handled per lab per month, over the selected range
    // (or last 12 months if 'all', to keep the chart readable). ──────────
    $trendMonths = $rangeStart !== null ? (int)$rangeParam : 12;
    $trendMonths = max(1, min(24, $trendMonths));
    $trendStart = $now->modify("-{$trendMonths} months")->modify('first day of this month midnight');

    $monthBuckets = [];
    $cursor = $trendStart;
    for ($i = 0; $i <= $trendMonths; $i++) {
        $monthBuckets[$cursor->format('Y-m')] = 0;
        $cursor = $cursor->modify('+1 month');
    }

    // Top 5 labs by cases handled drive the trend lines; avoids a cluttered chart.
    $topLabKeys = array_keys(array_filter($labMetrics, function ($m) { return $m['casesHandled'] > 0; }));
    usort($topLabKeys, function ($a, $b) use ($labMetrics) {
        return $labMetrics[$b]['casesHandled'] - $labMetrics[$a]['casesHandled'];
    });
    $topLabKeys = array_slice($topLabKeys, 0, 5);

    $trendSeries = [];
    foreach ($topLabKeys as $labKey) {
        $bucketCounts = $monthBuckets;
        $seenCaseMonth = [];
        foreach ($periodsByLab[$labKey] as $p) {
            $started = new DateTimeImmutable($p['started_at']);
            $monthKey = $started->format('Y-m');
            if (!array_key_exists($monthKey, $bucketCounts)) { continue; }
            $dedupeKey = $p['case_id'] . '|' . $monthKey;
            if (isset($seenCaseMonth[$dedupeKey])) { continue; }
            $seenCaseMonth[$dedupeKey] = true;
            $bucketCounts[$monthKey]++;
        }
        $trendSeries[] = [
            'label' => $labMetrics[$labKey]['name'],
            'data' => array_values($bucketCounts),
        ];
    }

    $trend = empty($trendSeries) ? null : [
        'labels' => array_keys($monthBuckets),
        'series' => $trendSeries,
    ];

    // ── Summary cards ────────────────────────────────────────────────────
    $turnaroundValues = array_values(array_filter(array_map(function ($m) {
        return $m['avgTurnaroundDays'];
    }, $labMetrics), function ($v) { return $v !== null; }));

    $summary = [
        'activeLabs' => count(array_filter($labs, function ($l) { return $l['isLive']; })),
        'casesCurrentlyAtLabs' => array_sum(array_map(function ($m) { return $m['currentWorkload']; }, $labMetrics)),
        'avgTurnaroundDays' => count($turnaroundValues) > 0 ? round(array_sum($turnaroundValues) / count($turnaroundValues), 1) : null,
        'lateCasesAtLabs' => array_sum(array_map(function ($m) { return $m['lateCaseCount']; }, $labMetrics)),
        'totalRevisions' => array_sum(array_map(function ($m) { return $m['revisionCount']; }, $labMetrics)),
        'directLabTransfers' => $totalTransfers,
        'multiLabCases' => $multiLabCaseCount,
    ];

    echo json_encode([
        'success' => true,
        'hasLabs' => $hasLiveLabs,
        'hasHistory' => $hasAnyHistory,
        'onlyBackfilled' => (bool)$onlyBackfilled,
        'range' => $rangeParam,
        'summary' => $summary,
        'labs' => array_values(array_map(function ($m) {
            unset($m['currentWorkloadCaseIds']);
            return $m;
        }, $labMetrics)),
        'currentWorkload' => $currentWorkload,
        'trend' => $trend,
    ]);

} catch (PDOException $e) {
    error_log('[get-lab-insights] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
