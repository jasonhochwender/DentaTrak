<?php
// List Organization Cases API endpoint - consolidated "All practices" view
//
// Returns ACTIVE cases across exactly the practices the session user is
// authorized to access within the current organization (subscription
// owner's account), resolved live on every request through
// api/organization-access.php. Requires the Scale entitlement; any lost
// entitlement or revoked practice access narrows/denies the response.
//
// "All practices" is a view scope only: there is no synthetic practice_id.
// Every returned case carries its own practiceId/practiceName so all
// downstream actions resolve the case's actual practice server-side.
//
// The dataset for one organization stays within the existing per-practice
// pattern: one scoped query (never one query per case), PHP-side
// filtering/PII decryption (same as list-cases.php because PII columns
// are encrypted), then server-side sort + pagination - the full set is
// never shipped to the browser.

require_once __DIR__ . '/session.php';      // Centralized session handling
header('Content-Type: application/json');

// Do not show errors in the browser for this endpoint
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/organization-access.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/at-risk-calculator.php';
require_once __DIR__ . '/case-types.php';
require_once __DIR__ . '/i18n.php';

// Match the local calendar-day semantics used by list-cases.php
date_default_timezone_set('America/New_York');

// SECURITY: Require valid practice context - the session practice anchors
// which organization the consolidated scope refers to.
$anchorPracticeId = requireValidPracticeContext();

// The consolidated view requires the session flag AND the live Scale
// entitlement (covers billing-disabled, bypass, trial and grace states).
if (!orgCaseViewEntitled($pdo)) {
    unset($_SESSION['org_case_view']);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'ORG_VIEW_UNAVAILABLE',
        'message' => 'The consolidated multi-practice view is not available.'
    ]);
    exit;
}

$authorizedPractices = getOrgCaseViewPractices($pdo);
if (count($authorizedPractices) < 2) {
    // Scope collapsed to fewer than two practices - fall back to the
    // single-practice experience rather than rendering a degraded view.
    unset($_SESSION['org_case_view']);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'ORG_VIEW_UNAVAILABLE',
        'message' => 'The consolidated view requires access to more than one practice.'
    ]);
    exit;
}

$practiceIds = [];
$practiceNames = [];
$limitedPractices = [];      // practice_id => true when user has limited visibility there
$canEditPractices = [];      // practice_id => bool can_edit_cases
$memberPractices = [];       // practice_id => true when user holds a membership
foreach ($authorizedPractices as $ap) {
    $pid = (int)$ap['id'];
    $practiceIds[] = $pid;
    $practiceNames[$pid] = (string)($ap['practice_name'] ?? '');
    if (!empty($ap['limited_visibility'])) {
        $limitedPractices[$pid] = true;
    }
    $canEditPractices[$pid] = !empty($ap['can_edit_cases']);
    if (!empty($ap['is_member'])) {
        $memberPractices[$pid] = true;
    }
}

// Per-practice terminal workflow stage + resolved stage labels. Cases from
// different practices may use different custom columns, so urgency rules
// and status display resolve against each case's OWN practice.
$terminalStageByPractice = [];
$stageLabelsByPractice = [];
$reviewTrackingPractices = [];
foreach ($practiceIds as $pid) {
    try {
        $terminalStageByPractice[$pid] = getLastActiveWorkflowColumnId($pid);
    } catch (Throwable $e) {
        $terminalStageByPractice[$pid] = 'Delivered';
    }
    try {
        $stageLabelsByPractice[$pid] = getResolvedWorkflowStageLabelsForPractice($pid);
    } catch (Throwable $e) {
        $stageLabelsByPractice[$pid] = [];
    }
    if (isCaseReviewTrackingEnabled($pid)) {
        $reviewTrackingPractices[$pid] = true;
    }
}

// Current user's email for limited-visibility matching.
$currentUserEmail = getCurrentUserEmail() ?? '';
$currentUserId = (int)($_SESSION['db_user_id'] ?? 0);

// Assignment labels the user is a recipient of, per limited-visibility
// practice (mirrors canUserAccessCase's label-based access so a case the
// user may open is never hidden from the consolidated list).
$userLabelsByPractice = [];
if (!empty($limitedPractices) && $currentUserId) {
    try {
        $limitedIds = array_keys($limitedPractices);
        $placeholders = implode(',', array_fill(0, count($limitedIds), '?'));
        $labelStmt = $pdo->prepare("
            SELECT l.practice_id, LOWER(TRIM(l.label)) AS label
            FROM practice_assignment_labels l
            JOIN practice_assignment_label_recipients r ON r.label_id = l.id
            WHERE r.user_id = ?
              AND l.practice_id IN ({$placeholders})
        ");
        $labelStmt->execute(array_merge([$currentUserId], $limitedIds));
        foreach ($labelStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userLabelsByPractice[(int)$row['practice_id']][(string)$row['label']] = true;
        }
    } catch (PDOException $e) {
        error_log('[list-org-cases] label recipient lookup failed: ' . $e->getMessage());
    }
}

function getCalendarDayDiffOrg($dueDateString) {
    if (empty($dueDateString)) {
        return null;
    }
    try {
        $datePart = $dueDateString;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dueDateString, $m)) {
            $datePart = $m[1];
        }
        $due = new DateTimeImmutable($datePart, new DateTimeZone(date_default_timezone_get()));
        $due = $due->setTime(0, 0, 0);
        $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
        $today = $today->setTime(0, 0, 0);
        return (int)round(($due->getTimestamp() - $today->getTimestamp()) / 86400);
    } catch (Throwable $e) {
        return null;
    }
}

try {
    $cases = getAllCasesFromCache($practiceIds);

    // Active view only - archived cases stay in the archived modal.
    $cases = array_values(array_filter($cases, function ($case) {
        return empty($case['archived']);
    }));

    // Per-practice limited visibility: a limited member only sees cases
    // assigned to them (directly or through a mapped assignment label) in
    // THAT practice; their visibility in other practices is unaffected.
    if (!empty($limitedPractices) && $currentUserEmail !== '') {
        $cases = array_values(array_filter($cases, function ($case) use ($limitedPractices, $userLabelsByPractice, $currentUserEmail) {
            $pid = (int)($case['practiceId'] ?? 0);
            if (!isset($limitedPractices[$pid])) {
                return true;
            }
            $assignedTo = strtolower(trim((string)($case['assignedTo'] ?? '')));
            if ($assignedTo === $currentUserEmail) {
                return true;
            }
            return $assignedTo !== '' && isset($userLabelsByPractice[$pid][$assignedTo]);
        }));
    } elseif (!empty($limitedPractices)) {
        // No resolvable email: limited-visibility practices show nothing.
        $cases = array_values(array_filter($cases, function ($case) use ($limitedPractices) {
            return !isset($limitedPractices[(int)($case['practiceId'] ?? 0)]);
        }));
    }

    // ---- Filters (mirroring list-cases.php semantics) ----

    // Practice filter: a real authorized practice id, never a synthetic.
    $filterPractice = $_GET['practice'] ?? '';
    if ($filterPractice !== '') {
        $filterPracticeId = filter_var($filterPractice, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($filterPracticeId === false || !isset($practiceNames[(int)$filterPracticeId])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid practice filter']);
            exit;
        }
        $filterPracticeId = (int)$filterPracticeId;
        $cases = array_values(array_filter($cases, function ($case) use ($filterPracticeId) {
            return (int)($case['practiceId'] ?? 0) === $filterPracticeId;
        }));
    }

    $searchTerm = $_GET['search'] ?? '';
    if ($searchTerm !== '') {
        $cases = PIIEncryption::filterCasesBySearch($cases, $searchTerm);
        $cases = array_values($cases);
    }

    $filterCarrier = $_GET['carrier'] ?? '';
    if ($filterCarrier !== '') {
        $cases = array_values(array_filter($cases, function ($case) use ($filterCarrier) {
            return ($case['carrier'] ?? '') === $filterCarrier;
        }));
    }

    $filterCaseType = $_GET['case_type'] ?? '';
    if ($filterCaseType !== '') {
        $filterCaseTypeSlug = normalizeCaseType($filterCaseType);
        $cases = array_values(array_filter($cases, function ($case) use ($filterCaseTypeSlug) {
            return normalizeCaseType((string)($case['caseType'] ?? '')) === $filterCaseTypeSlug;
        }));
    }

    // Assigned-to filter values are practice-scoped composites
    // ("<practiceId>|<raw assignee>") so identically named assignees or
    // labels in different practices never mix. Anything else is ignored.
    $filterAssignedTo = $_GET['assigned_to'] ?? '';
    if ($filterAssignedTo !== '' && preg_match('/^([1-9][0-9]*)\|(.+)$/D', $filterAssignedTo, $am)) {
        $assigneePracticeId = (int)$am[1];
        $assigneeValue = $am[2];
        if (isset($practiceNames[$assigneePracticeId])) {
            $cases = array_values(array_filter($cases, function ($case) use ($assigneePracticeId, $assigneeValue) {
                return (int)($case['practiceId'] ?? 0) === $assigneePracticeId
                    && ($case['assignedTo'] ?? '') === $assigneeValue;
            }));
        }
    }

    // Review-status filter applies only to cases in practices where Case
    // Review Tracking is enabled; practices with the feature off do not
    // track review state and are excluded from review-filtered results.
    $filterReviewStatus = $_GET['review_status'] ?? '';
    if (in_array($filterReviewStatus, ['needs_review', 'reviewed'], true)) {
        $cases = array_values(array_filter($cases, function ($case) use ($filterReviewStatus, $reviewTrackingPractices) {
            $pid = (int)($case['practiceId'] ?? 0);
            if (!isset($reviewTrackingPractices[$pid])) {
                return false;
            }
            return ($case['reviewStatus'] ?? 'needs_review') === $filterReviewStatus;
        }));
    }

    // User due-date preferences (same source as list-cases.php).
    $pastDueDays = 1;
    $comingDueDays = 5;
    $appointmentRiskDays = 3;
    $highlightPastDue = false;
    $highlightComingDue = false;
    $highlightAppointmentRisk = false;
    try {
        $stmt = $pdo->prepare("SELECT highlight_past_due, past_due_days, highlight_coming_due, coming_due_days, highlight_appointment_risk, appointment_risk_days FROM user_preferences WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $currentUserId]);
        $duePrefs = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($duePrefs) {
            $highlightPastDue = (bool)(int)($duePrefs['highlight_past_due'] ?? 0);
            $pastDueDays = (int)($duePrefs['past_due_days'] ?? 1);
            $highlightComingDue = (bool)(int)($duePrefs['highlight_coming_due'] ?? 0);
            $comingDueDays = (int)($duePrefs['coming_due_days'] ?? 5);
            $highlightAppointmentRisk = (bool)(int)($duePrefs['highlight_appointment_risk'] ?? 1);
            $appointmentRiskDays = (int)($duePrefs['appointment_risk_days'] ?? 3);
        }
    } catch (Throwable $e) {
        // defaults above
    }

    $isTerminal = function ($case) use ($terminalStageByPractice) {
        $pid = (int)($case['practiceId'] ?? 0);
        $terminal = $terminalStageByPractice[$pid] ?? 'Delivered';
        return isset($case['status']) && $case['status'] === $terminal;
    };
    $isOverdue = function ($case) use ($pastDueDays, $isTerminal) {
        if ($isTerminal($case)) return false;
        $d = getCalendarDayDiffOrg($case['dueDate'] ?? '');
        return $d !== null && $d <= -$pastDueDays;
    };
    $isDueSoon = function ($case) use ($comingDueDays, $isTerminal) {
        if ($isTerminal($case)) return false;
        $d = getCalendarDayDiffOrg($case['dueDate'] ?? '');
        return $d !== null && $d >= 0 && $d <= $comingDueDays;
    };

    // Summary counts respect the authorized scope AND the currently
    // active (non-urgency) filters. "Awaiting action" is intentionally not
    // reported: no established definition exists in the codebase.
    $overdueCount = count(array_filter($cases, $isOverdue));
    $dueSoonCount = count(array_filter($cases, $isDueSoon));

    // Urgency quick-filters (same rules as list-cases.php).
    // active_only excludes terminal-column cases - used by Insights
    // drill-downs so the list matches the workload metric's definition
    // exactly. Not exposed as a filter-bar control.
    if (($_GET['active_only'] ?? '') === 'true') {
        $cases = array_values(array_filter($cases, function ($case) use ($isTerminal) {
            return !$isTerminal($case);
        }));
    }
    if (($_GET['late_only'] ?? '') === 'true') {
        $cases = array_values(array_filter($cases, $isOverdue));
    }
    if (($_GET['due_soon'] ?? '') === 'true') {
        $cases = array_values(array_filter($cases, $isDueSoon));
    }
    if (($_GET['appt_risk_only'] ?? '') === 'true') {
        $cases = array_values(array_filter($cases, function ($case) use ($highlightPastDue, $pastDueDays, $highlightAppointmentRisk, $appointmentRiskDays, $isTerminal) {
            if ($isTerminal($case)) return false;
            if ($highlightPastDue) {
                $dd = getCalendarDayDiffOrg($case['dueDate'] ?? '');
                if ($dd !== null && $dd <= -$pastDueDays) return false;
            }
            if (!$highlightAppointmentRisk) return false;
            $apptDate = $case['patientAppointmentDate'] ?? '';
            if (empty($apptDate)) return false;
            $d = getCalendarDayDiffOrg($apptDate);
            if ($d !== null && $d <= $appointmentRiskDays) return true;
            // Lab acknowledgment: lab-promised return date after the patient
            // appointment is an additional risk signal; a missing promised
            // date never implies safety.
            $promised = $case['labPromisedDate'] ?? '';
            return !empty($promised) && strtotime($promised) > strtotime($apptDate);
        }));
    }

    // Lab acknowledgment quick-filters (per-practice setting; rows only
    // carry a status where the feature is enabled).
    if (($_GET['ack_awaiting_only'] ?? '') === 'true') {
        $cases = array_values(array_filter($cases, function ($case) {
            return ($case['labAckStatus'] ?? null) === 'awaiting';
        }));
    }
    if (($_GET['ack_needs_info_only'] ?? '') === 'true') {
        $cases = array_values(array_filter($cases, function ($case) {
            return ($case['labAckStatus'] ?? null) === 'needs_info';
        }));
    }

    // At Risk per practice (terminal stage differs per practice).
    $atRiskStatuses = [];
    $byPractice = [];
    foreach ($cases as $case) {
        $byPractice[(int)($case['practiceId'] ?? 0)][] = $case;
    }
    foreach ($byPractice as $pid => $group) {
        $groupResults = batchCalculateAtRiskStatus($group, $pdo, $terminalStageByPractice[$pid] ?? 'Delivered');
        foreach ($groupResults as $cid => $r) {
            $atRiskStatuses[$cid] = $r;
        }
    }
    foreach ($cases as &$case) {
        $cid = $case['id'] ?? null;
        $case['atRisk'] = ($cid && isset($atRiskStatuses[$cid])) ? $atRiskStatuses[$cid] : ['isAtRisk' => false, 'reasons' => []];
    }
    unset($case);
    if (($_GET['at_risk_only'] ?? '') === 'true') {
        $cases = array_values(array_filter($cases, function ($case) {
            return !empty($case['atRisk']['isAtRisk']);
        }));
    }

    // ---- Sort (server-side; encrypted PII cannot sort in SQL) ----
    $sortField = $_GET['sort'] ?? 'updated';
    $sortDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
    $sortable = ['practice', 'patient', 'type', 'status', 'assigned', 'due', 'appointment', 'dentist', 'updated', 'review'];
    if (!in_array($sortField, $sortable, true)) {
        $sortField = 'updated';
    }

    $dateValue = function ($v) {
        if (!$v) return null;
        $n = str_replace(' ', 'T', (string)$v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $n)) $n .= 'T00:00:00Z';
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $n)) $n .= 'Z';
        $t = strtotime($n);
        return $t === false ? null : $t;
    };
    $assignedDisplay = function ($v) {
        $vals = is_array($v) ? $v : [$v];
        $out = [];
        foreach ($vals as $x) {
            $x = trim((string)$x);
            if ($x === '') continue;
            $out[] = strpos($x, '@') !== false ? explode('@', $x)[0] : $x;
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return implode(', ', $out);
    };
    $sortValue = function ($case) use ($sortField, $practiceNames, $stageLabelsByPractice, $dateValue, $assignedDisplay) {
        switch ($sortField) {
            case 'practice':    return mb_strtolower($practiceNames[(int)($case['practiceId'] ?? 0)] ?? '');
            case 'patient':     return mb_strtolower(trim(($case['patientLastName'] ?? '') . ' ' . ($case['patientFirstName'] ?? '')));
            case 'type':        return mb_strtolower(getCaseTypeDisplayLabel((string)($case['caseType'] ?? '')));
            case 'status':      $pid = (int)($case['practiceId'] ?? 0); return mb_strtolower($stageLabelsByPractice[$pid][$case['status'] ?? ''] ?? ($case['status'] ?? ''));
            case 'assigned':    return mb_strtolower($assignedDisplay($case['assignedTo'] ?? ''));
            case 'due':         return $dateValue($case['dueDate'] ?? '');
            case 'appointment': return $dateValue($case['patientAppointmentDate'] ?? '');
            case 'dentist':     return mb_strtolower($case['dentistName'] ?? '');
            case 'review':      return ($case['reviewStatus'] ?? 'needs_review') === 'reviewed' ? 'b' : 'a';
            default:            return $dateValue($case['lastUpdateDate'] ?? '');
        }
    };
    usort($cases, function ($a, $b) use ($sortValue, $sortDir) {
        $av = $sortValue($a);
        $bv = $sortValue($b);
        $aEmpty = ($av === null || $av === '');
        $bEmpty = ($bv === null || $bv === '');
        if ($aEmpty !== $bEmpty) return $aEmpty ? 1 : -1;
        if ($aEmpty) return strcmp((string)$a['id'], (string)$b['id']);
        $cmp = is_int($av) && is_int($bv) ? ($av <=> $bv) : strcmp((string)$av, (string)$bv);
        if ($cmp === 0) $cmp = strcmp((string)$a['id'], (string)$b['id']);
        return $sortDir === 'asc' ? $cmp : -$cmp;
    });

    // ---- Pagination (server-side) ----
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = (int)($_GET['pageSize'] ?? 25);
    $pageSize = in_array($pageSize, [10, 25, 50, 100], true) ? $pageSize : 25;
    $total = count($cases);
    $totalPages = max(1, (int)ceil($total / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $cases = array_slice($cases, ($page - 1) * $pageSize, $pageSize);

    // Attach per-case organization context the UI needs for actions and
    // display. userCanEdit mirrors each case's own practice permission.
    foreach ($cases as &$case) {
        $pid = (int)($case['practiceId'] ?? 0);
        $case['practiceName'] = $practiceNames[$pid] ?? '';
        $case['statusLabel'] = $stageLabelsByPractice[$pid][$case['status'] ?? ''] ?? ($case['status'] ?? '');
        $case['userCanEdit'] = !empty($canEditPractices[$pid]);
        $case['userIsMember'] = isset($memberPractices[$pid]);
        $case['reviewTracking'] = isset($reviewTrackingPractices[$pid]);
    }
    unset($case);

    // ---- Assignee filter options ----
    // Real assignable values per authorized practice (member emails +
    // assignment labels) plus any assigned_to value observed on a visible
    // case. Options are scoped per practice so duplicate names never mix.
    $assigneeValuesByPractice = [];
    try {
        $idPlaceholders = implode(',', array_fill(0, count($practiceIds), '?'));
        $userStmt = $pdo->prepare("
            SELECT pu.practice_id, u.email
            FROM practice_users pu
            JOIN users u ON u.id = pu.user_id
            JOIN practices p ON p.id = pu.practice_id
            WHERE pu.practice_id IN ({$idPlaceholders})
              AND u.is_active = 1
              AND (p.is_active = 1 OR p.is_active IS NULL)
              AND IFNULL(pu.is_lab, 0) = 0
        ");
        $userStmt->execute($practiceIds);
        foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assigneeValuesByPractice[(int)$row['practice_id']]['users'][] = (string)$row['email'];
        }
    } catch (PDOException $e) {
        error_log('[list-org-cases] practice users lookup failed: ' . $e->getMessage());
    }
    try {
        $labelStmt = $pdo->prepare("
            SELECT practice_id, label
            FROM practice_assignment_labels
            WHERE practice_id IN (" . implode(',', array_fill(0, count($practiceIds), '?')) . ")
        ");
        $labelStmt->execute($practiceIds);
        foreach ($labelStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assigneeValuesByPractice[(int)$row['practice_id']]['labels'][] = (string)$row['label'];
        }
    } catch (PDOException $e) {
        // Table may not exist - labels simply unavailable
    }

    $assigneeOptions = [];
    foreach ($authorizedPractices as $ap) {
        $pid = (int)$ap['id'];
        $values = [];
        foreach (($assigneeValuesByPractice[$pid]['users'] ?? []) as $email) {
            $values[$email] = 'user';
        }
        foreach (($assigneeValuesByPractice[$pid]['labels'] ?? []) as $label) {
            $values[$label] = 'label';
        }
        $options = [];
        foreach ($values as $v => $kind) {
            $options[] = ['value' => $v, 'label' => $v, 'kind' => $kind];
        }
        usort($options, function ($a, $b) { return strcasecmp($a['value'], $b['value']); });
        $assigneeOptions[] = [
            'practiceId' => $pid,
            'practiceName' => $practiceNames[$pid] ?? '',
            'options' => $options
        ];
    }

    $practicesOut = [];
    foreach ($authorizedPractices as $ap) {
        $pid = (int)$ap['id'];
        $practicesOut[] = [
            'id' => $pid,
            'name' => $practiceNames[$pid] ?? '',
            'canEdit' => !empty($canEditPractices[$pid]),
            'isMember' => isset($memberPractices[$pid])
        ];
    }

    echo json_encode([
        'success' => true,
        'scope' => 'organization',
        'anchorPracticeId' => $anchorPracticeId,
        'practices' => $practicesOut,
        'assigneeOptions' => $assigneeOptions,
        'reviewTrackingAny' => !empty($reviewTrackingPractices),
        'cases' => $cases,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => $totalPages,
        'counts' => [
            'overdue' => $overdueCount,
            'dueSoon' => $dueSoonCount,
            'dueSoonDays' => $comingDueDays,
            'overdueDays' => $pastDueDays
        ]
    ]);
} catch (Throwable $e) {
    error_log('Error in list-org-cases.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving cases'
    ]);
}
