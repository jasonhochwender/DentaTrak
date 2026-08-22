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
 *   - Cases Assigned / Completed / Avg. Turnaround / Late Delivery Rate /
 *     Revisions / Direct Transfers / trend are all filtered by the
 *     relevant timestamp for that metric (assignment period start/end for
 *     assigned+turnaround+late-delivery+transfers, case_activity_log.created_at
 *     for revisions).
 *   - Current Workload and Current Late Rate are ALWAYS computed from
 *     current state and are NEVER filtered by range - an assignment that
 *     began before the selected range must not disappear from "what a lab
 *     has right now".
 *
 * COLUMN DEFINITIONS:
 *   - Current Workload: cases currently assigned to this lab right now
 *     (cases_cache, not range-filtered).
 *   - Current Late Rate: of Current Workload, how many are currently past
 *     due (cases_cache, not range-filtered).
 *   - Cases Assigned: distinct cases assigned to this lab at any point
 *     during the selected range (any case_lab_assignment_periods row,
 *     open or closed, any history_quality - this INCLUDES in-progress
 *     assignments, so it is not a "completed work" count).
 *   - Completed: distinct cases with at least one reliably-measurable
 *     (history_quality='observed', has a real ended_at, positive
 *     duration) completed assignment period at this lab within the
 *     range - the same population Avg. Turnaround is computed from.
 *     Represents distinct CASES, not distinct periods (a case reassigned
 *     back to the same lab twice within the range still counts once).
 *   - Avg. Turnaround: average, per completed case, of that case's total
 *     observed time at this lab (summed if a case had more than one
 *     period at the same lab).
 *   - Late Delivery Rate: of the same Completed population, the
 *     percentage whose FINAL completed period ended after the case's due
 *     date. A case with more than one completed period at this lab
 *     (Delivered -> reopened -> Delivered again) is scored ONCE, from its
 *     final delivery only - an earlier late or on-time round never
 *     independently affects the result. The final period is chosen
 *     explicitly by latest ended_at (tie-broken by the period's own id),
 *     never by query/iteration order. Excludes any case whose due date
 *     was edited AFTER that final period ended (see dueDateChangedAfter
 *     below) - due_date is a single mutable field on cases_cache, not a
 *     point-in-time snapshot, so a case edited after its final completion
 *     cannot be reliably scored.
 *   - Direct Transfers: periods that ended via a direct lab-to-lab
 *     reassignment (end_reason='reassigned_to_lab'), scoped to the range.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/billing-bypass.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/encryption.php';

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
        SELECT id, case_id, assignee_type, user_id, label_id, assignee_display_name_snapshot,
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

    // Late Delivery Rate data-quality guard: cases_cache.due_date is a
    // single mutable field, not a point-in-time snapshot of "the due date
    // when this lab period ended". If a case's due date was edited AFTER a
    // completed period's ended_at, we cannot know what the due date was at
    // completion time, so that case must be excluded from Late Delivery
    // Rate (it still counts toward Cases Assigned / Completed / Avg.
    // Turnaround, which don't depend on due_date at all). Detected via the
    // existing case_updated activity log entries that already record which
    // fields changed on every edit (see update-case.php's $changedFields).
    $dueDateChangeTimestampsByCase = []; // case_id => [DateTimeImmutable, ...]
    if (!empty($caseIds)) {
        $chunks = array_chunk($caseIds, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT case_id, created_at, meta_json
                FROM case_activity_log
                WHERE event_type = 'case_updated' AND case_id IN ($placeholders)
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $meta = json_decode($row['meta_json'] ?? '', true);
                $changedFields = is_array($meta) ? ($meta['changed_fields'] ?? []) : [];
                if (is_array($changedFields) && in_array('dueDate', $changedFields, true)) {
                    try {
                        $dueDateChangeTimestampsByCase[$row['case_id']][] = new DateTimeImmutable($row['created_at']);
                    } catch (Exception $e) {
                        // Ignore unparseable timestamps rather than fail the whole request.
                    }
                }
            }
        }
    }

    /**
     * True if this case's due date was edited strictly after $after - i.e.
     * the current cases_cache.due_date cannot be trusted to represent what
     * the due date was at that point in time.
     */
    function dueDateChangedAfter($caseId, DateTimeImmutable $after, array $dueDateChangeTimestampsByCase) {
        foreach (($dueDateChangeTimestampsByCase[$caseId] ?? []) as $ts) {
            if ($ts > $after) {
                return true;
            }
        }
        return false;
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

    // SECURITY: Assigned Only (limited_visibility) users must see the same
    // case-level restriction here as everywhere else in the app - reuse the
    // single authoritative policy check (canUserAccessCase()) rather than
    // reimplementing it. For a non-limited-visibility user this is always
    // true (matching today's behavior exactly); for a limited-visibility
    // user it restricts current-state Lab Insights (workload counts, late
    // counts, and the drill-down rows/patient names below) to only cases
    // assigned to their own email - which will legitimately be zero/near-
    // zero for most such users, since lab-assigned cases are assigned to a
    // lab identity, not to individual staff. This is a deliberate, expected
    // consequence of Assigned Only, not a bug.
    $currentWorkloadCaseIds = [];   // labKey => [caseId => true]
    $currentWorkloadLateCount = []; // labKey => int
    $stmt = $pdo->prepare("
        SELECT case_id, status, due_date, archived, case_type, assigned_to,
               patient_first_name, patient_last_name
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
        $row['practice_id'] = $practiceId;
        if (!canUserAccessCase($row, $practiceId)) {
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
    $globalTurnaroundSeconds = 0;
    $globalTurnaroundCases = 0;

    foreach ($labs as $labKey => $labInfo) {
        $periods = $periodsByLab[$labKey] ?? [];

        $workloadCaseIds = $currentWorkloadCaseIds[$labKey] ?? [];
        $assignedCaseIds = [];       // within range - "Cases Assigned" (may be in-progress)
        $turnaroundByCase = [];      // case_id => summed observed seconds (this lab) - "Completed" population
        $revisionAttributedCases = []; // case_id => true (>=1 attributed revision, within range)
        $revisionCountTotal = 0;
        $directTransfersOut = 0;
        $lateDeliveryEligibleCases = []; // case_id => true (reliable due-date comparison possible)
        $lateDeliveryLateCases = [];     // case_id => true (subset of eligible, delivered late)
        $completedPeriodsForLateDelivery = []; // case_id => [ ['id' => int, 'endedAt' => DateTimeImmutable], ... ]

        foreach ($periods as $p) {
            $caseId = $p['case_id'];

            // Direct Transfers is historical like every other metric below -
            // scoped to the selected range via the same overlap check.
            if ($p['end_reason'] === 'reassigned_to_lab' && periodOverlapsRange($p, $rangeStart, $now)) {
                $directTransfersOut++;
            }

            // "Cases Assigned" - at least one period of any quality
            // (including still-open/in-progress), filtered to range by
            // whether the period overlaps the selected window. This is
            // intentionally NOT a "completed work" count - see Completed
            // below for that.
            if (periodOverlapsRange($p, $rangeStart, $now)) {
                $assignedCaseIds[$caseId] = true;
            }

            // Completed / Avg. Turnaround: observed periods that ended
            // because the case was delivered at this lab. Reassignments away
            // from the lab are real ends, but they are not "completed" lab
            // work for this lab. Backfilled/open periods excluded. Represents
            // distinct CASES (not periods) - if the same case had more than
            // one delivered period at this lab, their durations are summed.
            if ($p['history_quality'] === 'observed' && $p['ended_at'] !== null && $p['end_reason'] === 'delivered') {
                $started = new DateTimeImmutable($p['started_at']);
                $ended = new DateTimeImmutable($p['ended_at']);
                $seconds = $ended->getTimestamp() - $started->getTimestamp();
                if ($seconds > 0 && periodOverlapsRange($p, $rangeStart, $now)) {
                    $turnaroundByCase[$caseId] = ($turnaroundByCase[$caseId] ?? 0) + $seconds;

                    // Late Delivery Rate candidate: same completed population
                    // as turnaround. A case can have more than one completed
                    // period here (Delivered -> reopened -> Delivered again)
                    // - only the FINAL one (by ended_at, tie-broken by the
                    // period's own id) determines the case's late/on-time
                    // result below. Do not evaluate lateness per-period here;
                    // that would make the result depend on iteration/query
                    // order rather than being explicitly determined.
                    $completedPeriodsForLateDelivery[$caseId][] = [
                        'id' => (int)$p['id'],
                        'endedAt' => $ended,
                    ];
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

            if (periodOverlapsRange($p, $rangeStart, $now)) {
                $multiLabCaseKeys[$caseId][$labKey] = true;
            }
        }

        // Late Delivery Rate: resolve exactly one outcome per case, from
        // that case's FINAL completed period at this lab (product decision:
        // "based on the final delivery outcome for each case" - an earlier
        // late period must not count if the case was later re-delivered on
        // time, and vice versa). Selection is explicit (max ended_at, tied
        // broken by the period's own id) rather than relying on the order
        // periods were queried/iterated above.
        foreach ($completedPeriodsForLateDelivery as $caseId => $candidates) {
            usort($candidates, function ($a, $b) {
                if ($a['endedAt'] != $b['endedAt']) {
                    return $a['endedAt'] <=> $b['endedAt'];
                }
                return $a['id'] <=> $b['id'];
            });
            $final = end($candidates);

            // Reliability guard (unchanged from the original implementation):
            // cases_cache.due_date is a single mutable field, not a snapshot
            // at completion time. Excluded if there's no due date, or if the
            // due date was edited after THIS FINAL period ended - an earlier
            // due-date edit that happened before the final period ended is
            // still fine, since the current value would already reflect it.
            $caseRow = $casesById[$caseId] ?? null;
            if (!$caseRow || empty($caseRow['due_date'])) {
                continue;
            }
            if (dueDateChangedAfter($caseId, $final['endedAt'], $dueDateChangeTimestampsByCase)) {
                continue;
            }

            try {
                $dueDateOnly = new DateTimeImmutable(substr($caseRow['due_date'], 0, 10));
                $endedDateOnly = new DateTimeImmutable($final['endedAt']->format('Y-m-d'));
            } catch (Exception $e) {
                continue;
            }

            $lateDeliveryEligibleCases[$caseId] = true;
            if ($endedDateOnly > $dueDateOnly) {
                $lateDeliveryLateCases[$caseId] = true;
            }
        }

        $completedCaseCount = count($turnaroundByCase);
        $avgTurnaroundSeconds = $completedCaseCount > 0
            ? array_sum($turnaroundByCase) / $completedCaseCount
            : null;

        if ($completedCaseCount > 0) {
            $globalTurnaroundSeconds += array_sum($turnaroundByCase);
            $globalTurnaroundCases += $completedCaseCount;
        }

        $currentWorkloadCount = count($workloadCaseIds);
        $lateInWorkload = $currentWorkloadLateCount[$labKey] ?? 0;

        $assignedCount = count($assignedCaseIds);
        $revisionRate = $assignedCount > 0 ? (count($revisionAttributedCases) / $assignedCount) * 100 : null;
        $lateRate = $currentWorkloadCount > 0 ? ($lateInWorkload / $currentWorkloadCount) * 100 : null;

        $lateDeliverySampleSize = count($lateDeliveryEligibleCases);
        $lateDeliveryRate = $lateDeliverySampleSize > 0
            ? (count($lateDeliveryLateCases) / $lateDeliverySampleSize) * 100
            : null;

        $totalTransfers += $directTransfersOut;

        $labMetrics[$labKey] = [
            'labKey' => $labKey,
            'type' => $labInfo['type'],
            'entityId' => $labInfo['entityId'],
            'name' => $labInfo['isLive'] ? $labInfo['currentName'] : $labInfo['snapshotName'],
            'isLive' => $labInfo['isLive'],
            'currentWorkload' => $currentWorkloadCount,
            'casesAssigned' => $assignedCount,
            'completed' => $completedCaseCount,
            'avgTurnaroundDays' => $avgTurnaroundSeconds !== null ? round($avgTurnaroundSeconds / 86400, 1) : null,
            'turnaroundSampleSize' => $completedCaseCount,
            'lateCaseRate' => $lateRate !== null ? round($lateRate, 1) : null,
            'lateCaseCount' => $lateInWorkload,
            'lateDeliveryRate' => $lateDeliveryRate !== null ? round($lateDeliveryRate, 1) : null,
            'lateDeliverySampleSize' => $lateDeliverySampleSize,
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
    // Patient name is decrypted here via the same PIIEncryption mechanism
    // used by every other authenticated case screen (get-case.php,
    // list-cases.php, etc.) - no new PII access path. Every case reaching
    // this point has already passed canUserAccessCase() above, so Assigned
    // Only users never see a name for a case they would not otherwise be
    // permitted to see.
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

            $patientName = 'Unknown Patient';
            try {
                $decrypted = PIIEncryption::decryptCaseData([
                    'patientFirstName' => $caseRow['patient_first_name'] ?? null,
                    'patientLastName' => $caseRow['patient_last_name'] ?? null,
                ]);
                $fullName = trim(($decrypted['patientFirstName'] ?? '') . ' ' . ($decrypted['patientLastName'] ?? ''));
                if ($fullName !== '') {
                    $patientName = $fullName;
                }
            } catch (Exception $e) {
                // Fall back to the generic label rather than fail the whole request.
            }

            $currentWorkload[] = [
                'caseId' => $cid,
                'patientName' => $patientName,
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

    // Top 5 labs by cases assigned drive the trend lines; avoids a cluttered chart.
    $topLabKeys = array_keys(array_filter($labMetrics, function ($m) { return $m['casesAssigned'] > 0; }));
    usort($topLabKeys, function ($a, $b) use ($labMetrics) {
        return $labMetrics[$b]['casesAssigned'] - $labMetrics[$a]['casesAssigned'];
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
    $summary = [
        'activeLabs' => count(array_filter($labs, function ($l) { return $l['isLive']; })),
        'casesCurrentlyAtLabs' => array_sum(array_map(function ($m) { return $m['currentWorkload']; }, $labMetrics)),
        'avgTurnaroundDays' => $globalTurnaroundCases > 0 ? round(($globalTurnaroundSeconds / $globalTurnaroundCases) / 86400, 1) : null,
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
