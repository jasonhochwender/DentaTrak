<?php
/**
 * Analytics API Endpoint
 * Returns analytics data for the dashboard
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/practice-display-settings.php';
require_once __DIR__ . '/workflow-stages.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context - NO FALLBACKS
$practiceId = requireValidPracticeContext();

// Practice-specific terminal (last) status. Stored in $GLOBALS so SQL
// strings can interpolate it consistently without hard-coding 'Delivered'.
$GLOBALS['workflowTerminalStatus'] = getLastActiveWorkflowColumnId($practiceId);

// SECURITY: Insights (can_view_analytics) gates access to analytics data.
// This was previously stored/toggleable in Settings but never enforced by
// any backend endpoint.
if (!canViewAnalytics($practiceId)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. You do not have permission to view analytics.'
    ]);
    exit;
}

// Load configuration
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/billing-bypass.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/at-risk-calculator.php';

// Plan entitlement: Practice Insights requires a Control-or-higher plan in
// addition to the per-user analytics permission above. hasControlAccess()
// already encodes active trials, founder/billing bypasses, and the
// BILLING_ENABLED=false bypass. This must run before any analytics query or
// visit recording so a denied request produces no data and no side effects.
$userId = $_SESSION['db_user_id'] ?? null;
$userEmail = '';
if ($userId) {
    $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $emailStmt->execute([$userId]);
    $userEmail = $emailStmt->fetchColumn() ?: '';
}
if (!hasControlAccess($pdo, $practiceId, $userEmail)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Practice Insights requires the Control plan',
        'error_code' => 'upgrade_required'
    ]);
    exit;
}

// Match the local calendar-day semantics used by the Kanban board
// (see js/app.js getCalendarDayDiff() and api/list-cases.php).
date_default_timezone_set('America/New_York');

function getCalendarDayDiff($dateString) {
    if (empty($dateString)) {
        return null;
    }
    try {
        $due = new DateTimeImmutable($dateString, new DateTimeZone(date_default_timezone_get()));
        $due = $due->setTime(0, 0, 0);
        $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
        $today = $today->setTime(0, 0, 0);
        $diffSeconds = $due->getTimestamp() - $today->getTimestamp();
        return (int) round($diffSeconds / 86400);
    } catch (Throwable $e) {
        return null;
    }
}

// Get time period filters
$userId = $_SESSION['db_user_id'];
$teamPeriod = $_GET['team_period'] ?? '12'; // Default: last 12 months
$teamFilter = $_GET['team_filter'] ?? 'both'; // Default: both users and labels
$durationPeriod = $_GET['duration_period'] ?? 'active'; // Default: active cases

// SECURITY: Use ONLY the validated practice ID - NO fallbacks to other practices
$volumePeriod = $_GET['volume_period'] ?? '13'; // Default: last 13 months
$completionTimeframe = $_GET['completion_timeframe'] ?? '12'; // Default: last 12 months for completion predictions
$statusPeriod = $_GET['status_period'] ?? 'active'; // Default: active cases
$typePeriod = $_GET['type_period'] ?? 'active'; // Default: active cases

// Helper function to build time period WHERE clause
function buildTimePeriodClause($period, $dateColumn = 'creation_date') {
    if ($period === 'active') {
        return "AND status != '{$GLOBALS['workflowTerminalStatus']}'";
    } elseif ($period === 'all') {
        return "";
    } else {
        $months = (int)$period;
        if ($months < 1) $months = 1;
        if ($months > 60) $months = 60;
        // Handle string dates by extracting first 10 chars (YYYY-MM-DD) and converting
        return "AND STR_TO_DATE(LEFT(COALESCE($dateColumn, CURRENT_DATE()), 10), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL $months MONTH)";
    }
}

try {
    // SECURITY: Build a per-request authorized case list. All `cases_cache`
    // queries below INNER JOIN to this temp table so every aggregate is scoped
    // by the same rule canUserAccessCase() uses (direct assignment + label
    // recipients). For non-limited users the table contains every practice case.
    ensureAuthorizedCaseIdsTempTable($practiceId);

    // ---- Global Insights filters (toolbar) ----
    // af_period: 30d|90d|12m|all|custom (+af_from/af_to). Dimensions:
    // af_case_type, af_status (active|completed|<internal status>),
    // af_assignee ('__unassigned' supported), af_carrier.
    // Applied ONLY to historical analytics queries below. Operational
    // metrics (caseFlow, attentionQueue, KPI cards) intentionally keep
    // their current-state definitions and are not period-filtered.
    $afPeriod = $_GET['af_period'] ?? '';
    $afFrom = $_GET['af_from'] ?? '';
    $afTo = $_GET['af_to'] ?? '';
    $afCaseType = trim((string)($_GET['af_case_type'] ?? ''));
    $afStatus = trim((string)($_GET['af_status'] ?? ''));
    $afAssignee = trim((string)($_GET['af_assignee'] ?? ''));
    $afCarrier = trim((string)($_GET['af_carrier'] ?? ''));

    $afScopeSql = '';
    $afScopeParams = [];
    $afDateCol = "STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')";
    if ($afPeriod === '30d') {
        $afScopeSql .= " AND $afDateCol >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)";
    } elseif ($afPeriod === '90d') {
        $afScopeSql .= " AND $afDateCol >= DATE_SUB(CURRENT_DATE(), INTERVAL 90 DAY)";
    } elseif ($afPeriod === '12m') {
        $afScopeSql .= " AND $afDateCol >= DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH)";
    } elseif ($afPeriod === 'custom') {
        $d1 = DateTime::createFromFormat('Y-m-d', $afFrom);
        $d2 = DateTime::createFromFormat('Y-m-d', $afTo);
        if ($d1 && $d2 && $d1 <= $d2) {
            $afScopeSql .= " AND $afDateCol BETWEEN :af_from AND :af_to";
            $afScopeParams['af_from'] = $afFrom;
            $afScopeParams['af_to'] = $afTo;
        }
        // Invalid/reversed ranges fall back to no period restriction.
    }
    // 'all' or unknown => no date restriction.
    if ($afCaseType !== '') {
        $afScopeSql .= " AND COALESCE(case_type, '') = :af_case_type";
        $afScopeParams['af_case_type'] = $afCaseType;
    }
    if ($afStatus === 'active') {
        $afScopeSql .= " AND status != '{$GLOBALS['workflowTerminalStatus']}'";
    } elseif ($afStatus === 'completed') {
        $afScopeSql .= " AND status = '{$GLOBALS['workflowTerminalStatus']}'";
    } elseif ($afStatus !== '') {
        $afScopeSql .= " AND status = :af_status";
        $afScopeParams['af_status'] = $afStatus;
    }
    if ($afAssignee === '__unassigned') {
        $afScopeSql .= " AND (assigned_to IS NULL OR assigned_to = '')";
    } elseif ($afAssignee !== '') {
        $afScopeSql .= " AND assigned_to = :af_assignee";
        $afScopeParams['af_assignee'] = $afAssignee;
    }
    if ($afCarrier !== '') {
        $afScopeSql .= " AND carrier = :af_carrier";
        $afScopeParams['af_carrier'] = $afCarrier;
    }

    // Distinct facet values for the toolbar dropdowns (practice-scoped,
    // authorized cases only). One cheap DISTINCT query per dimension.
    $afFacets = ['case_types' => [], 'assignees' => [], 'carriers' => []];
    try {
        $facetSql = "SELECT DISTINCT case_type FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci WHERE practice_id = :practice_id AND case_type IS NOT NULL AND case_type != '' ORDER BY case_type LIMIT 100";
        $stmt = $pdo->prepare($facetSql);
        $stmt->execute(['practice_id' => $practiceId]);
        $afFacets['case_types'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("SELECT DISTINCT assigned_to FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci WHERE practice_id = :practice_id AND assigned_to IS NOT NULL AND assigned_to != '' ORDER BY assigned_to LIMIT 100");
        $stmt->execute(['practice_id' => $practiceId]);
        $afFacets['assignees'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("SELECT DISTINCT carrier FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci WHERE practice_id = :practice_id AND carrier IS NOT NULL AND carrier != '' ORDER BY carrier LIMIT 100");
        $stmt->execute(['practice_id' => $practiceId]);
        $afFacets['carriers'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { /* facets are best-effort */ }

    // Core Metrics
    $metrics = [];
    
    // Load the PRACTICE-wide highlighting thresholds (practices row). The
    // Kanban resolves the same values via getPracticeDisplaySettings() in
    // list-cases.php, so Insights and the board always agree for every
    // member of the practice.
    $preferences = getPracticeDisplaySettings($pdo, (int)$practiceId);
    $highlightPastDue = (bool)$preferences['highlight_past_due'];
    $pastDueDays = (int)$preferences['past_due_days'];
    $highlightComingDue = (bool)$preferences['highlight_coming_due'];
    $comingDueDays = (int)$preferences['coming_due_days'];
    $highlightAppointmentRisk = (bool)$preferences['highlight_appointment_risk'];
    $appointmentRiskDays = (int)$preferences['appointment_risk_days'];

    // Total Active Cases - active, non-archived, non-delivered workflow cases
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE practice_id = :practice_id AND archived = 0 AND status != '{$GLOBALS['workflowTerminalStatus']}'
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $metrics['totalActiveCases'] = (int)$stmt->fetchColumn();

    // Initialize empty arrays for charts when no data exists
    $statusDistribution = [];
    $monthlyVolume = [];
    $caseTypeBreakdown = [];
    $teamPerformance = [];

    // Delivered This Month - cases that reached Delivered status this calendar month,
    // including any that have since been archived. Uses cases_cache.status_changed_at
    // as the primary delivery timestamp. If that is unavailable, falls back to the
    // most recent case_activity_log entry where event_type = 'status_changed' and
    // new_status = '{$GLOBALS['workflowTerminalStatus']}'. If neither exists, the case is not counted.
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.case_id) as total
        FROM cases_cache c INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
        LEFT JOIN (
            SELECT case_id, MAX(created_at) as delivered_at
            FROM case_activity_log
            WHERE event_type = 'status_changed'
              AND new_status = '{$GLOBALS['workflowTerminalStatus']}'
            GROUP BY case_id
        ) l ON l.case_id = c.case_id
        WHERE c.practice_id = :practice_id
          AND c.status = '{$GLOBALS['workflowTerminalStatus']}'
          AND MONTH(STR_TO_DATE(LEFT(COALESCE(c.status_changed_at, l.delivered_at), 10), '%Y-%m-%d')) = MONTH(CURRENT_DATE())
          AND YEAR(STR_TO_DATE(LEFT(COALESCE(c.status_changed_at, l.delivered_at), 10), '%Y-%m-%d')) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $metrics['deliveredThisMonth'] = (int)$stmt->fetchColumn();

    // Case Flow Status - classify active (non-archived, non-delivered) cases using
    // the same definitions and precedence as the Kanban board.
    $caseFlow = ['onTrack' => 0, 'dueSoon' => 0, 'appointmentRisk' => 0, 'late' => 0];
    $stmt = $pdo->prepare("
        SELECT case_id, due_date, patient_appointment_date, status,
               patient_first_name, patient_last_name, case_type,
               assigned_to, lab_ack_status, lab_promised_date
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE practice_id = :practice_id AND archived = 0 AND status != '{$GLOBALS['workflowTerminalStatus']}'
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $activeForFlow = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($activeForFlow as $case) {
        $status = $case['status'] ?? '';
        if ($status === $GLOBALS['workflowTerminalStatus']) {
            continue;
        }

        // 1. Late (highest precedence)
        if ($highlightPastDue) {
            $daysUntilDue = getCalendarDayDiff($case['due_date'] ?? '');
            if ($daysUntilDue !== null && $daysUntilDue <= -$pastDueDays) {
                $caseFlow['late']++;
                continue;
            }
        }

        // 2. Appointment Risk
        if ($highlightAppointmentRisk) {
            $apptDate = $case['patient_appointment_date'] ?? '';
            if (!empty($apptDate)) {
                $daysUntilAppt = getCalendarDayDiff($apptDate);
                if ($daysUntilAppt !== null && $daysUntilAppt <= $appointmentRiskDays) {
                    $caseFlow['appointmentRisk']++;
                    continue;
                }
            }
        }

        // 3. Due Soon
        if ($highlightComingDue) {
            $daysUntilDue = $daysUntilDue ?? getCalendarDayDiff($case['due_date'] ?? '');
            if ($daysUntilDue !== null && $daysUntilDue >= 0 && $daysUntilDue <= $comingDueDays) {
                $caseFlow['dueSoon']++;
                continue;
            }
        }

        // 4. On Track
        $caseFlow['onTrack']++;
    }

    $metrics['caseFlow'] = $caseFlow;

    // Attention Required queue - individual cases needing intervention,
    // classified by the shared api/case-attention.php rules so the queue
    // reconciles with the Case Health categories above. Categories may
    // overlap per case; `tier`/`primaryReason` carry the most urgent one.
    require_once __DIR__ . '/case-attention.php';
    $attentionItems = [];
    $attentionCounts = ['all' => 0, 'overdue' => 0, 'appt_risk' => 0, 'due_soon' => 0, 'awaiting_ack' => 0];
    foreach ($activeForFlow as $case) {
        $cls = classifyCaseAttentionRisk([
            'dueDate' => $case['due_date'] ?? null,
            'patientAppointmentDate' => $case['patient_appointment_date'] ?? null,
            'labAckStatus' => $case['lab_ack_status'] ?? null,
            'status' => $case['status'] ?? '',
        ], $preferences, $GLOBALS['workflowTerminalStatus']);
        if (!$cls['attention']) {
            continue;
        }
        $attentionCounts['all']++;
        if ($cls['late']) $attentionCounts['overdue']++;
        if ($cls['apptRisk']) $attentionCounts['appt_risk']++;
        if ($cls['dueSoon']) $attentionCounts['due_soon']++;
        if ($cls['awaitingAck']) $attentionCounts['awaiting_ack']++;
        $attentionItems[] = [
            'id' => $case['case_id'],
            'patientFirstName' => $case['patient_first_name'] ?? '',
            'patientLastName' => $case['patient_last_name'] ?? '',
            'caseType' => $case['case_type'] ?? '',
            'status' => $case['status'] ?? '',
            'assignedTo' => $case['assigned_to'] ?? null,
            'dueDate' => $case['due_date'] ?? null,
            'patientAppointmentDate' => $case['patient_appointment_date'] ?? null,
            'labPromisedDate' => $case['lab_promised_date'] ?? null,
            'reasons' => $cls['reasons'],
            'primaryReason' => $cls['primaryReason'],
            'tier' => $cls['tier'],
            'daysUntilDue' => $cls['daysUntilDue'],
            'daysUntilAppt' => $cls['daysUntilAppt'],
        ];
    }
    // Server-side priority order: tier asc, then most-imminent appointment,
    // then most-overdue, then soonest due date. Cap payload at 200 items so
    // client-side chip filters/sorts stay accurate for realistic practices;
    // counts above always reflect the full qualifying set and "View All"
    // covers anything beyond the cap via the board's attention_only filter.
    usort($attentionItems, function ($a, $b) {
        if ($a['tier'] !== $b['tier']) return $a['tier'] <=> $b['tier'];
        $aAppt = $a['daysUntilAppt'] === null ? PHP_INT_MAX : $a['daysUntilAppt'];
        $bAppt = $b['daysUntilAppt'] === null ? PHP_INT_MAX : $b['daysUntilAppt'];
        if ($aAppt !== $bAppt) return $aAppt <=> $bAppt;
        $aDue = $a['daysUntilDue'] === null ? PHP_INT_MAX : $a['daysUntilDue'];
        $bDue = $b['daysUntilDue'] === null ? PHP_INT_MAX : $b['daysUntilDue'];
        if ($aDue !== $bDue) return $aDue <=> $bDue;
        return strcmp((string)$a['id'], (string)$b['id']);
    });
    $attentionQueue = [
        'items' => array_slice($attentionItems, 0, 200),
        'total' => $attentionCounts['all'],
        'counts' => $attentionCounts,
    ];

    // Total Archived Cases - cases that are archived (archived = 1)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE practice_id = :practice_id AND archived = 1
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $metrics['totalArchivedCases'] = (int)$stmt->fetchColumn();
    
    // Cases This Month - based on creation_date (stored as string)
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND MONTH(STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')) = MONTH(CURRENT_DATE())
            AND YEAR(STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesThisMonth'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci");
        $metrics['casesThisMonth'] = (int)$stmt->fetchColumn();
    }
    
    // Average Case Duration - lifecycle days for terminal cases using the
    // same completion definition as the lifecycle metrics (status_changed_at,
    // falling back to last_update_date). Null when no qualifying cases exist.
    try {
        $stmt = $pdo->prepare("
            SELECT AVG(days) as avg_duration, COUNT(*) as n
            FROM (
                SELECT TIMESTAMPDIFF(SECOND, start_ts, end_ts) / 86400 AS days
                FROM (
                    SELECT
                        COALESCE(
                            STR_TO_DATE(LEFT(creation_date, 19), '%Y-%m-%d %H:%i:%s'),
                            STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')
                        ) AS start_ts,
                        COALESCE(
                            status_changed_at,
                            STR_TO_DATE(LEFT(last_update_date, 19), '%Y-%m-%d %H:%i:%s'),
                            STR_TO_DATE(LEFT(last_update_date, 10), '%Y-%m-%d')
                        ) AS end_ts
                    FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
                    WHERE practice_id = :practice_id
                    AND status = '{$GLOBALS['workflowTerminalStatus']}'
                    AND creation_date IS NOT NULL
                    $afScopeSql
                ) spans
                WHERE start_ts IS NOT NULL AND end_ts IS NOT NULL
            ) durations
            WHERE days >= 0
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId], $afScopeParams));
        $durRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['averageCaseDuration'] = ($durRow && (int)$durRow['n'] > 0)
            ? round((float)$durRow['avg_duration'], 1) : null;
    } catch (Exception $e) {
        $metrics['averageCaseDuration'] = null;
    }
    
    // Cases Past Due - based on due_date, excluding Delivered status and archived cases
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != '{$GLOBALS['workflowTerminalStatus']}'
            AND due_date IS NOT NULL
            AND due_date != ''
            AND STR_TO_DATE(LEFT(due_date, 10), '%Y-%m-%d') < CURRENT_DATE()
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesPastDue'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['casesPastDue'] = 0;
    }
    
    // Cases Due This Week - for Insights quick stats
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != '{$GLOBALS['workflowTerminalStatus']}'
            AND due_date IS NOT NULL
            AND due_date != ''
            AND STR_TO_DATE(LEFT(due_date, 10), '%Y-%m-%d') BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesDueThisWeek'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['casesDueThisWeek'] = 0;
    }
    
    // Unassigned Cases - for Insights quick stats
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != '{$GLOBALS['workflowTerminalStatus']}'
            AND (assigned_to IS NULL OR assigned_to = '')
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['unassignedCases'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['unassignedCases'] = 0;
    }
    
    // At Risk Cases - computed dynamically
    try {
        // Get active cases for At Risk calculation
        $stmt = $pdo->prepare("
            SELECT * FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != '{$GLOBALS['workflowTerminalStatus']}'
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $activeCasesForRisk = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $metrics['atRiskCases'] = countAtRiskCases($activeCasesForRisk, $pdo);
    } catch (Exception $e) {
        $metrics['atRiskCases'] = 0;
    }
    
    // Regression Metrics - cases with backward stage movements
    try {
        // Total cases with at least 1 regression (active, non-archived)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND archived = 0
            AND revision_count > 0
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesWithRegressions'] = (int)$stmt->fetchColumn();
        
        // Cases with multiple regressions (2+)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND archived = 0
            AND revision_count >= 2
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesWithMultipleRegressions'] = (int)$stmt->fetchColumn();
        
        // Total regression count across all active cases
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(revision_count), 0) as total
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND archived = 0
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['totalRegressions'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['casesWithRegressions'] = 0;
        $metrics['casesWithMultipleRegressions'] = 0;
        $metrics['totalRegressions'] = 0;
    }
    
    // Case Status Distribution - use time period filtering
    try {
        $statusClause = buildTimePeriodClause($statusPeriod);
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            $statusClause
            $afScopeSql
            GROUP BY status
            ORDER BY " . getWorkflowStageOrderCaseSqlForPractice($practiceId) . "
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId], $afScopeParams));
        $statusDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $statusDistribution = [];
    }
    
    // Monthly Case Volume - use creation_date (string) and requested period
    try {
        $months = (int)$volumePeriod;
        if ($months < 1) {
            $months = 1;
        }

        $sql = "
            SELECT 
                DATE_FORMAT(
                    STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d'),
                    '%Y-%m'
                ) as month,
                COUNT(*) as cases_created,
                SUM(CASE WHEN status = '{$GLOBALS['workflowTerminalStatus']}' THEN 1 ELSE 0 END) as cases_delivered
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL $months MONTH)
            $afScopeSql
            GROUP BY month
            ORDER BY month DESC
            LIMIT $months
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge(['practice_id' => $practiceId], $afScopeParams));
        $monthlyVolume = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $monthlyVolume = [];
    }
    
    // Case Type Breakdown - use time period filtering
    try {
        $typeClause = buildTimePeriodClause($typePeriod);
        
        // First try with practice filter
        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN case_type IS NULL OR case_type = '' THEN 'Unspecified'
                    ELSE case_type 
                END as case_type,
                COUNT(*) as count
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            $typeClause
            $afScopeSql
            GROUP BY case_type
            ORDER BY count DESC, case_type ASC
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId], $afScopeParams));
        $caseTypeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $caseTypeBreakdown = [];
    }
    
    // Team Performance - strict practice filter
    try {
        // Get list of practice user emails for filtering
        $practiceUserEmails = [];
        $userStmt = $pdo->prepare("
            SELECT DISTINCT u.email 
            FROM users u 
            JOIN practice_users pu ON u.id = pu.user_id 
            WHERE pu.practice_id = :practice_id
        ");
        $userStmt->execute(['practice_id' => $practiceId]);
        $practiceUserEmails = $userStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build the team filter condition
        $teamFilterCondition = "";
        if ($teamFilter === 'users' && !empty($practiceUserEmails)) {
            // Only show practice users (emails that match practice users)
            $placeholders = implode(',', array_fill(0, count($practiceUserEmails), '?'));
            $teamFilterCondition = " AND assigned_to IN ($placeholders)";
        } elseif ($teamFilter === 'labels' && !empty($practiceUserEmails)) {
            // Only show assignment labels (non-email values that don't match practice users)
            $placeholders = implode(',', array_fill(0, count($practiceUserEmails), '?'));
            $teamFilterCondition = " AND (assigned_to IS NULL OR assigned_to = '' OR assigned_to NOT IN ($placeholders))";
        }
        // 'both' = no additional filter
        
        $teamSql = "
            SELECT 
                CASE 
                    WHEN assigned_to IS NULL OR assigned_to = '' THEN 'Unassigned'
                    ELSE assigned_to 
                END as assignee,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                    assigned_to
                ) as assignee_name,
                COUNT(*) as cases_count,
                SUM(CASE WHEN status = '{$GLOBALS['workflowTerminalStatus']}' THEN 1 ELSE 0 END) as completed_cases
            FROM cases_cache c INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
            LEFT JOIN users u ON u.email = c.assigned_to
            WHERE c.practice_id = ?
        ";
        
        // Add time period filter if not "all" (based on creation_date)
        if ($teamPeriod !== 'all') {
            $months = (int)$teamPeriod;
            if ($months < 1) {
                $months = 1;
            }
            $teamSql .= " AND STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL $months MONTH)";
        }
        
        $teamSql .= $teamFilterCondition;
        
        $teamSql .= "
            GROUP BY assignee, assignee_name
            ORDER BY cases_count DESC
            LIMIT 25
        ";
        
        // Build parameters array
        $params = [$practiceId];
        if ($teamFilter === 'users' && !empty($practiceUserEmails)) {
            $params = array_merge($params, $practiceUserEmails);
        } elseif ($teamFilter === 'labels' && !empty($practiceUserEmails)) {
            $params = array_merge($params, $practiceUserEmails);
        }
        
        $stmt = $pdo->prepare($teamSql);
        $stmt->execute($params);
        $teamPerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $teamPerformance = [];
    }

    // Creator Breakdown - cases created by authenticated user (not provider-specific)
    $creatorBreakdown = [];
    try {
        $creatorPeriod = $_GET['creator_period'] ?? 'all';
        $creatorClause = buildTimePeriodClause($creatorPeriod);

        $stmt = $pdo->prepare("
            SELECT
                c.created_by_user_id as user_id,
                COALESCE(u.first_name, '') as first_name,
                COALESCE(u.last_name, '') as last_name,
                COUNT(*) as cases_count
            FROM cases_cache c INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
            LEFT JOIN users u ON u.id = c.created_by_user_id
            WHERE c.practice_id = :practice_id
            AND c.archived = 0
            $creatorClause
            $afScopeSql
            GROUP BY c.created_by_user_id, u.first_name, u.last_name
            ORDER BY cases_count DESC, first_name ASC, last_name ASC
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId], $afScopeParams));
        $creatorBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($creatorBreakdown as &$row) {
            $row['creator'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($row['creator'] === '') {
                $row['creator'] = 'Unknown user';
            }
            unset($row['first_name'], $row['last_name']);
        }
        unset($row);
    } catch (Exception $e) {
        $creatorBreakdown = [];
    }

    // Status Duration Analytics - NEW
    $statusDurationData = [];
    try {
        // Build time period clause for duration data
        $durationClause = buildTimePeriodClause($durationPeriod);
        
        // Calculate average time in each status for active cases
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as case_count,
                AVG(DATEDIFF(
                    CURRENT_DATE(),
                    STR_TO_DATE(LEFT(COALESCE(status_changed_at, creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')
                )) as avg_days_in_status,
                MIN(DATEDIFF(
                    CURRENT_DATE(),
                    STR_TO_DATE(LEFT(COALESCE(status_changed_at, creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')
                )) as min_days_in_status,
                MAX(DATEDIFF(
                    CURRENT_DATE(),
                    STR_TO_DATE(LEFT(COALESCE(status_changed_at, creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')
                )) as max_days_in_status
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND status != '{$GLOBALS['workflowTerminalStatus']}'
            AND archived = 0
            AND status_changed_at IS NOT NULL
            $durationClause
            $afScopeSql
            GROUP BY status
            ORDER BY " . getWorkflowStageOrderCaseSqlForPractice($practiceId) . "
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId], $afScopeParams));
        $statusDurationData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for frontend
        foreach ($statusDurationData as &$status) {
            $status['avg_days_in_status'] = round($status['avg_days_in_status'], 1);
            $status['min_days_in_status'] = (int)$status['min_days_in_status'];
            $status['max_days_in_status'] = (int)$status['max_days_in_status'];
        }
    } catch (Exception $e) {
        $statusDurationData = [];
    }
    
    // Case Lifecycle Analytics - lifecycle = creation -> entry into the
    // terminal workflow status. Completion timestamp prefers
    // status_changed_at (datetime, set on the terminal transition) and falls
    // back to last_update_date. Durations are computed in seconds so
    // sub-day lifecycles stay precise; rows with missing/negative durations
    // are excluded rather than coerced to zero.
    $lifecycleData = [
        'avg_total_days' => null,
        'min_total_days' => null,
        'max_total_days' => null,
        'delivered_cases' => 0
    ];
    try {
        $stmt = $pdo->prepare("
            SELECT
                AVG(days) AS avg_total_days,
                MIN(days) AS min_total_days,
                MAX(days) AS max_total_days,
                COUNT(*) AS delivered_cases
            FROM (
                SELECT TIMESTAMPDIFF(SECOND, start_ts, end_ts) / 86400 AS days
                FROM (
                    SELECT
                        COALESCE(
                            STR_TO_DATE(LEFT(c.creation_date, 19), '%Y-%m-%d %H:%i:%s'),
                            STR_TO_DATE(LEFT(c.creation_date, 10), '%Y-%m-%d')
                        ) AS start_ts,
                        COALESCE(
                            c.status_changed_at,
                            STR_TO_DATE(LEFT(c.last_update_date, 19), '%Y-%m-%d %H:%i:%s'),
                            STR_TO_DATE(LEFT(c.last_update_date, 10), '%Y-%m-%d')
                        ) AS end_ts
                    FROM cases_cache c INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
                    WHERE c.practice_id = :practice_id
                    AND c.status = '{$GLOBALS['workflowTerminalStatus']}'
                    AND c.creation_date IS NOT NULL
                    $afScopeSql
                ) spans
                WHERE start_ts IS NOT NULL AND end_ts IS NOT NULL
            ) durations
            WHERE days >= 0
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId], $afScopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['delivered_cases'] > 0) {
            $lifecycleData['avg_total_days'] = round((float)$row['avg_total_days'], 2);
            $lifecycleData['min_total_days'] = round((float)$row['min_total_days'], 2);
            $lifecycleData['max_total_days'] = round((float)$row['max_total_days'], 2);
            $lifecycleData['delivered_cases'] = (int)$row['delivered_cases'];
        }
    } catch (Exception $e) {
        // Keep null/unavailable state - never report a failed calculation as 0.
        error_log('Analytics lifecycle query failed: ' . $e->getMessage());
    }
    
    // Calculate actual advanced insights from case data
    $advancedInsights = [
        'caseFlow' => $caseFlow,
        'attentionQueue' => $attentionQueue,
        'completion' => [
            'avgDays' => $metrics['averageCaseDuration'] ?? 0,
            'onTrack' => $caseFlow['onTrack'],
            'atRisk' => $caseFlow['dueSoon'] + $caseFlow['appointmentRisk'],
            'delayed' => $caseFlow['late']
        ],
        'workload' => [
            'utilization' => $metrics['totalActiveCases'] > 0 ? min(100, round(($metrics['totalActiveCases'] / 20) * 100)) : 0,
            'topPerformer' => t('common.not_applicable'),
            'busiest' => t('common.not_applicable'),
            'capacity' => $metrics['totalActiveCases'] > 17 ? t('insights.advanced.near_capacity') : t('insights.advanced.optimal')
        ],
        'trends' => [
            'monthlyData' => [],
            'growthRate' => null,
            'peakMonth' => null,
            'nextPeak' => null,
            'currentYear' => date('Y')
        ]
    ];
    
    // Calculate year-over-year trends
    try {
        $currentYear = date('Y');
        $lastYear = $currentYear - 1;
        
        // Get current year data - strict practice filter
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d'), '%Y-%m') as month,
                COUNT(*) as count
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND YEAR(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')) = :currentYear
            $afScopeSql
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId, 'currentYear' => $currentYear], $afScopeParams));
        $currentYearData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get last year data - strict practice filter
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d'), '%Y-%m') as month,
                COUNT(*) as count
            FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
            WHERE practice_id = :practice_id
            AND YEAR(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')) = :lastYear
            $afScopeSql
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute(array_merge(['practice_id' => $practiceId, 'lastYear' => $lastYear], $afScopeParams));
        $lastYearData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate monthly comparison data. Totals are year-to-date (months
        // up to the current month only) so an incomplete current year is not
        // compared against a full prior year.
        $monthlyData = [];
        $currentYearTotal = 0;
        $lastYearTotal = 0;
        $currentMonthNum = (int)date('n');
        $maxCurrentMonthNum = null;
        $maxCurrentCount = 0;
        
        // Process all months for comparison
        for ($month = 1; $month <= 12; $month++) {
            $monthStr = sprintf('%04d-%02d', $currentYear, $month);
            $lastMonthStr = sprintf('%04d-%02d', $lastYear, $month);
            
            $currentCount = 0;
            $lastCount = 0;
            
            // Find current year count for this month
            foreach ($currentYearData as $row) {
                if ($row['month'] === $monthStr) {
                    $currentCount = (int)$row['count'];
                    if ($month <= $currentMonthNum) $currentYearTotal += $currentCount;
                    if ($currentCount > $maxCurrentCount) {
                        $maxCurrentCount = $currentCount;
                        $maxCurrentMonthNum = $month;
                    }
                    break;
                }
            }
            
            // Find last year count for this month
            foreach ($lastYearData as $row) {
                if ($row['month'] === $lastMonthStr) {
                    $lastCount = (int)$row['count'];
                    if ($month <= $currentMonthNum) $lastYearTotal += $lastCount;
                    break;
                }
            }
            
            if ($currentCount > 0 || $lastCount > 0) {
                $monthlyData[] = [
                    'month' => $month,
                    'currentYear' => $currentCount,
                    'lastYear' => $lastCount
                ];
            }
        }
        
        // Growth is only defined with a nonzero prior-year baseline. A zero
        // or absent baseline yields null (rendered N/A), never a misleading
        // 0% or an arbitrary large percentage.
        $growthRate = null;
        if ($lastYearTotal > 0) {
            $growthRate = round((($currentYearTotal - $lastYearTotal) / $lastYearTotal) * 100, 1);
        }
        
        // Find next peak month (simplified - use current year pattern)
        $nextPeakNum = null;
        if (!empty($currentYearData)) {
            $nextPeakNum = (int)((date('n') + 1) % 12 + 1);
        }

        $advancedInsights['trends'] = [
            'monthlyData' => $monthlyData,
            'growthRate' => $growthRate,
            'peakMonth' => $maxCurrentMonthNum,
            'nextPeak' => $nextPeakNum,
            'currentYear' => $currentYear
        ];
        
    } catch (Exception $e) {
        // Keep default empty values on error
    }
    
    // Case Flow Status already computed in $metrics['caseFlow'] and assigned
    // to $advancedInsights above; no additional calculation needed here.
    
    // Find top performer from team performance data
    if (!empty($teamPerformance)) {
        $topPerformer = $teamPerformance[0]['assignee'] ?? 'None';
        $busiestMember = $teamPerformance[0]['assignee'] ?? 'None';
        $advancedInsights['workload']['topPerformer'] = $topPerformer;
        $advancedInsights['workload']['busiest'] = $busiestMember;
    }
    
    // Format data for charts
    $chartData = [
        'statusDistribution' => $statusDistribution,
        'monthlyVolume' => array_reverse($monthlyVolume), // Show oldest to newest
        'caseTypeBreakdown' => $caseTypeBreakdown,
        'teamPerformance' => $teamPerformance,
        'creatorBreakdown' => $creatorBreakdown,
        'statusDuration' => $statusDurationData,
        'lifecycle' => $lifecycleData,
        'facets' => $afFacets
    ];
    
    // Record a Practice Insights visit only when the client marked this as a
    // screen-activation load (X-Insights-Visit header) AND the payload above
    // computed successfully - failed loads never reach this line. Refresh
    // buttons, filter changes, and settings-triggered refetches omit the
    // header, so only an actual screen visit advances the timestamp.
    if (($_SERVER['HTTP_X_INSIGHTS_VISIT'] ?? '') === '1') {
        recordInsightsVisit((int)($_SESSION['db_user_id'] ?? 0), $practiceId, 'practice');
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'metrics' => $metrics,
            'charts' => $chartData,
            'advancedInsights' => $advancedInsights
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving analytics data: ' . $e->getMessage()
    ]);
}
?>
