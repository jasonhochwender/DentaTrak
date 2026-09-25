<?php
/**
 * Ask DentaTrak - controlled read-only data tools.
 *
 * Every function here is a whitelisted, parameterized read over the cases the
 * CURRENT SESSION USER is authorized to see. Authorization is enforced by
 * INNER JOINing the authorized_case_ids temp table built by
 * ensureAuthorizedCaseIdsTempTable() - the same rule used by the Cases list
 * and Insights (all practice cases for regular members; assigned/label-matched
 * cases only for Assigned-Only users). No function accepts raw SQL, table
 * names, or practice/user overrides, so the model cannot widen scope.
 *
 * Read-only by construction: only SELECT statements exist here.
 */

require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/workflow-stages.php';

const ASK_TOOL_LIST_LIMIT = 15;

/**
 * The allowlisted tool names. The planner may only request these.
 */
function getAskDentatrakToolNames(): array {
    return [
        'count_cases',
        'list_cases',
        'aggregate_cases_by_status',
        'count_remakes',
        'get_case_summary',
        'get_reference_values',
    ];
}

/**
 * Execute a whitelisted tool. Returns a JSON-safe result array:
 * ['ok' => true, 'data' => ...] or ['ok' => false, 'error' => 'code'].
 * Unknown tools and malformed parameters are rejected before any query runs.
 */
function runAskDentatrakTool(string $tool, array $params, int $practiceId): array {
    global $pdo;

    if (!in_array($tool, getAskDentatrakToolNames(), true)) {
        return ['ok' => false, 'error' => 'unknown_tool'];
    }
    if (!$pdo || !$practiceId) {
        return ['ok' => false, 'error' => 'unavailable'];
    }

    try {
        // The temp table is per-connection and idempotent.
        ensureAuthorizedCaseIdsTempTable($practiceId);

        switch ($tool) {
            case 'count_cases':
                return ['ok' => true, 'data' => askToolCountCases($pdo, $practiceId, $params)];
            case 'list_cases':
                return ['ok' => true, 'data' => askToolListCases($pdo, $practiceId, $params)];
            case 'aggregate_cases_by_status':
                return ['ok' => true, 'data' => askToolAggregateByStatus($pdo, $practiceId)];
            case 'count_remakes':
                return ['ok' => true, 'data' => askToolCountRemakes($pdo, $practiceId, $params)];
            case 'get_case_summary':
                return ['ok' => true, 'data' => askToolGetCaseSummary($pdo, $practiceId, $params)];
            case 'get_reference_values':
                return ['ok' => true, 'data' => askToolGetReferenceValues($pdo, $practiceId)];
        }
    } catch (Throwable $e) {
        error_log('[ask-dentatrak] tool ' . $tool . ' failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'tool_error'];
    }

    return ['ok' => false, 'error' => 'unknown_tool'];
}

/**
 * Sanitize a string filter: bounded length, no control bytes. Stored as a
 * bound parameter, so this is belt-and-suspenders against junk input.
 */
function askToolStr($value, int $maxLen = 100): ?string {
    if (!is_string($value)) return null;
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value));
    if ($v === '') return null;
    return mb_substr($v, 0, $maxLen);
}

function askToolInt($value, int $min, int $max): ?int {
    if (is_bool($value)) return null;
    if (!is_numeric($value)) return null;
    $v = (int)$value;
    return ($v >= $min && $v <= $max) ? $v : null;
}

function askToolDate($value): ?string {
    // Sanitize without truncating: truncation could turn a longer crafted
    // string into a valid-looking date; reject anything that isn't exactly
    // an ISO date instead.
    $v = askToolStr($value, 30);
    if ($v === null || strlen($v) !== 10 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    // Reject impossible dates rather than passing them to SQL.
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

/**
 * Shared FROM/WHERE for case queries. Every statement joins the
 * authorized_case_ids temp table - the ONLY scope source.
 *
 * @return array{0:string,1:array} [whereSql, boundParams]
 */
function askToolCaseFilters(int $practiceId, array $params): array {
    global $pdo;
    $where = ['c.practice_id = :practice_id'];
    $bind = [':practice_id' => $practiceId];
    $today = date('Y-m-d');

    $terminal = getLastActiveWorkflowColumnId($practiceId);
    $doneStatuses = array_values(array_unique(array_filter([$terminal, 'Completed', 'Shipped'])));

    $includeArchived = !empty($params['include_archived']);
    if (!$includeArchived) {
        $where[] = 'c.archived = 0';
    }

    if (($status = askToolStr($params['status'] ?? null)) !== null) {
        $where[] = 'LOWER(TRIM(c.status)) = LOWER(:status)';
        $bind[':status'] = $status;
    }

    if (($caseType = askToolStr($params['case_type'] ?? null)) !== null) {
        $where[] = 'LOWER(TRIM(c.case_type)) = LOWER(:case_type)';
        $bind[':case_type'] = $caseType;
    }

    if (!empty($params['overdue'])) {
        $where[] = "c.due_date IS NOT NULL AND c.due_date != '' AND c.due_date < :today";
        $bind[':today'] = $today;
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':done' . $i; $bind[':done' . $i] = $s; }
        $where[] = 'c.status NOT IN (' . implode(',', $in) . ')';
    }

    // "Due tomorrow" style windows: due_on today|tomorrow, or explicit ranges.
    if (($dueOn = askToolStr($params['due_on'] ?? null, 10)) !== null) {
        if ($dueOn === 'today') {
            $where[] = "c.due_date = :due_on";
            $bind[':due_on'] = $today;
        } elseif ($dueOn === 'tomorrow') {
            $where[] = "c.due_date = :due_on";
            $bind[':due_on'] = date('Y-m-d', strtotime('+1 day'));
        }
    }

    if (($days = askToolInt($params['due_within_days'] ?? null, 0, 366)) !== null) {
        $where[] = "c.due_date IS NOT NULL AND c.due_date != '' AND c.due_date BETWEEN :due_from AND :due_to";
        $bind[':due_from'] = $today;
        $bind[':due_to'] = date('Y-m-d', strtotime('+' . $days . ' days'));
    }
    if (($from = askToolDate($params['due_from'] ?? null)) !== null) {
        $where[] = "c.due_date IS NOT NULL AND c.due_date >= :due_from_x";
        $bind[':due_from_x'] = $from;
    }
    if (($to = askToolDate($params['due_to'] ?? null)) !== null) {
        $where[] = "c.due_date IS NOT NULL AND c.due_date != '' AND c.due_date <= :due_to_x";
        $bind[':due_to_x'] = $to;
    }

    if (($days = askToolInt($params['created_within_days'] ?? null, 0, 366)) !== null) {
        $where[] = "STR_TO_DATE(LEFT(COALESCE(c.creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= :created_after";
        $bind[':created_after'] = date('Y-m-d', strtotime('-' . $days . ' days'));
    }

    if (($days = askToolInt($params['completed_within_days'] ?? null, 0, 366)) !== null) {
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':cdone' . $i; $bind[':cdone' . $i] = $s; }
        $where[] = 'c.status IN (' . implode(',', $in) . ')';
        $where[] = "STR_TO_DATE(LEFT(COALESCE(c.last_update_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= :completed_after";
        $bind[':completed_after'] = date('Y-m-d', strtotime('-' . $days . ' days'));
    }

    if (!empty($params['assigned_to_me'])) {
        $where[] = '(' . askToolAssignedToMePredicate($bind) . ')';
    }

    if (!empty($params['unassigned'])) {
        $where[] = "(c.assigned_to IS NULL OR c.assigned_to = '')";
    }

    // "Lab X" resolves to the practice's Lab-flagged assignment label(s).
    // assigned_to stores the label text for label assignments.
    if (($lab = askToolStr($params['lab'] ?? null)) !== null) {
        $labStmt = $pdo->prepare(
            "SELECT label FROM practice_assignment_labels
             WHERE practice_id = :p AND is_lab = 1
               AND LOWER(TRIM(label)) LIKE LOWER(:lab)"
        );
        $labStmt->execute([':p' => $practiceId, ':lab' => '%' . $lab . '%']);
        $labels = $labStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($labels) {
            $ors = [];
            foreach ($labels as $i => $l) {
                $ors[] = 'LOWER(TRIM(c.assigned_to)) = LOWER(TRIM(:lab' . $i . '))';
                $bind[':lab' . $i] = $l;
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        } else {
            // Unknown lab name -> guaranteed empty result, never broadened.
            $where[] = '1 = 0';
        }
    }

    if (($patient = askToolStr($params['patient_name'] ?? null)) !== null) {
        $where[] = "CONCAT_WS(' ', c.patient_first_name, c.patient_last_name) LIKE :patient";
        $bind[':patient'] = '%' . $patient . '%';
    }

    return [implode(' AND ', $where), $bind];
}

/**
 * The exact assigned-to-me predicate used by
 * ensureAuthorizedCaseIdsTempTable() for limited-visibility users: the
 * user's own email OR any assignment label they are a recipient of.
 * Mutates $bind to add the needed params.
 */
function askToolAssignedToMePredicate(array &$bind): string {
    $email = getCurrentUserEmail();
    $userId = $_SESSION['db_user_id'] ?? 0;
    $bind[':me_email'] = $email ?: '';
    $bind[':me_uid'] = (int)$userId;
    $bind[':me_practice'] = (int)($_SESSION['current_practice_id'] ?? 0);
    return "LOWER(TRIM(c.assigned_to)) = :me_email
        OR EXISTS (
            SELECT 1 FROM practice_assignment_labels l
            JOIN practice_assignment_label_recipients r ON r.label_id = l.id
            WHERE l.practice_id = :me_practice
              AND r.user_id = :me_uid
              AND LOWER(TRIM(l.label)) COLLATE utf8mb4_unicode_ci
                  = LOWER(TRIM(c.assigned_to)) COLLATE utf8mb4_unicode_ci
        )";
}

function askToolCountCases(PDO $pdo, int $practiceId, array $params): array {
    [$where, $bind] = askToolCaseFilters($practiceId, $params);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE $where"
    );
    $stmt->execute($bind);
    return ['count' => (int)$stmt->fetchColumn()];
}

function askToolListCases(PDO $pdo, int $practiceId, array $params): array {
    [$where, $bind] = askToolCaseFilters($practiceId, $params);
    $limit = askToolInt($params['limit'] ?? null, 1, ASK_TOOL_LIST_LIMIT) ?? ASK_TOOL_LIST_LIMIT;

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE $where"
    );
    $countStmt->execute($bind);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT c.case_id,
                TRIM(CONCAT_WS(' ', c.patient_first_name, c.patient_last_name)) AS patient_name,
                c.dentist_name, c.case_type, c.status, c.due_date,
                c.assigned_to, c.patient_appointment_date
         FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE $where
         ORDER BY (c.due_date IS NULL OR c.due_date = '') ASC, c.due_date ASC, c.case_id ASC
         LIMIT " . (int)$limit
    );
    $stmt->execute($bind);

    return [
        'total_matching' => $total,
        'returned' => min($total, $limit),
        'cases' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function askToolAggregateByStatus(PDO $pdo, int $practiceId): array {
    $stmt = $pdo->prepare(
        "SELECT c.status, COUNT(*) AS count FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE c.practice_id = :p AND c.archived = 0
         GROUP BY c.status"
    );
    $stmt->execute([':p' => $practiceId]);
    $byStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'by_status' => $byStatus,
        'total' => array_sum(array_map('intval', $byStatus)),
    ];
}

function askToolCountRemakes(PDO $pdo, int $practiceId, array $params): array {
    $days = askToolInt($params['within_days'] ?? null, 1, 366) ?? 30;
    $where = [
        'r.practice_id = :practice_id',
        'r.initiated_at >= :since',
    ];
    $bind = [
        ':practice_id' => $practiceId,
        ':since' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
    ];

    if (($attr = askToolStr($params['attribution'] ?? null, 50)) !== null) {
        if (function_exists('isValidRemakeAttribution') && !isValidRemakeAttribution($attr)) {
            return ['count' => 0, 'within_days' => $days, 'note' => 'invalid_attribution'];
        }
        $where[] = 'r.attribution = :attr';
        $bind[':attr'] = $attr;
    }
    if (($reason = askToolStr($params['reason_code'] ?? null, 50)) !== null) {
        if (function_exists('isValidRemakeReason') && !isValidRemakeReason($reason)) {
            return ['count' => 0, 'within_days' => $days, 'note' => 'invalid_reason_code'];
        }
        $where[] = 'r.reason_code = :reason';
        $bind[':reason'] = $reason;
    }

    $sql = "SELECT COUNT(*) FROM case_remake_events r
            INNER JOIN authorized_case_ids a ON a.case_id = r.case_id COLLATE utf8mb4_unicode_ci
            WHERE " . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $count = (int)$stmt->fetchColumn();

    // Small aggregate context so answers can mention top reasons without
    // exposing per-case detail.
    $byReason = $pdo->prepare(
        "SELECT r.reason_code, COUNT(*) AS count FROM case_remake_events r
         INNER JOIN authorized_case_ids a ON a.case_id = r.case_id COLLATE utf8mb4_unicode_ci
         WHERE " . implode(' AND ', $where) . " GROUP BY r.reason_code ORDER BY count DESC LIMIT 5"
    );
    $byReason->execute($bind);

    return [
        'count' => $count,
        'within_days' => $days,
        'by_reason' => $byReason->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

/**
 * Single-case summary. The case_id/patient lookup runs through the same
 * authorized temp table, so an ID for another practice - or a case outside
 * an Assigned-Only user's scope - simply does not exist to this tool.
 */
function askToolGetCaseSummary(PDO $pdo, int $practiceId, array $params) {
    $caseId = askToolStr($params['case_id'] ?? null, 64);
    $patient = askToolStr($params['patient_name'] ?? null);

    if ($caseId === null && $patient === null) {
        return ['error' => 'missing_lookup', 'matches' => []];
    }

    [$where, $bind] = askToolCaseFilters($practiceId, [
        'patient_name' => $patient,
        'include_archived' => true,
    ]);
    if ($caseId !== null) {
        $where .= ' AND c.case_id = :case_id';
        $bind[':case_id'] = $caseId;
    }

    $stmt = $pdo->prepare(
        "SELECT c.case_id,
                TRIM(CONCAT_WS(' ', c.patient_first_name, c.patient_last_name)) AS patient_name,
                c.dentist_name, c.case_type, c.material, c.status, c.due_date,
                c.assigned_to, c.creation_date, c.last_update_date,
                c.patient_appointment_date, c.archived, c.revision_count,
                c.reviewed_at, c.delivered_at
         FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE $where
         ORDER BY c.last_update_date DESC
         LIMIT 5"
    );
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        // Deliberately identical for "no such case" and "case you may not
        // see" - never reveal existence across scope boundaries.
        return ['error' => 'not_found', 'matches' => []];
    }

    foreach ($rows as &$row) {
        $rmStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM case_remake_events r
             INNER JOIN authorized_case_ids a ON a.case_id = r.case_id COLLATE utf8mb4_unicode_ci
             WHERE r.case_id = :cid AND r.practice_id = :p"
        );
        $rmStmt->execute([':cid' => $row['case_id'], ':p' => $practiceId]);
        $row['remake_count'] = (int)$rmStmt->fetchColumn();
        $row['reviewed'] = !empty($row['reviewed_at']);
        unset($row['reviewed_at']);
    }

    return ['matches' => $rows];
}

/**
 * Reference values for grounding model filters: current assignees, Lab
 * labels, case types and workflow statuses actually in use for this
 * practice. All scoped - nothing here crosses the authorized set.
 */
function askToolGetReferenceValues(PDO $pdo, int $practiceId): array {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT c.assigned_to FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE c.practice_id = :p AND c.archived = 0
           AND c.assigned_to IS NOT NULL AND c.assigned_to != ''
         ORDER BY c.assigned_to LIMIT 100"
    );
    $stmt->execute([':p' => $practiceId]);

    $labs = $pdo->prepare(
        "SELECT label FROM practice_assignment_labels
         WHERE practice_id = :p AND is_lab = 1 ORDER BY sort_order, label LIMIT 100"
    );
    $labs->execute([':p' => $practiceId]);

    $types = $pdo->prepare(
        "SELECT DISTINCT c.case_type FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE c.practice_id = :p AND c.archived = 0
           AND c.case_type IS NOT NULL AND c.case_type != ''
         ORDER BY c.case_type LIMIT 100"
    );
    $types->execute([':p' => $practiceId]);

    $statuses = getValidWorkflowStatusesForPractice($practiceId);
    $statusLabels = array_map(function ($s) use ($practiceId) {
        return resolveWorkflowStageLabelForPractice($s, $practiceId);
    }, $statuses);

    return [
        'assignees' => $stmt->fetchAll(PDO::FETCH_COLUMN),
        'lab_labels' => $labs->fetchAll(PDO::FETCH_COLUMN),
        'case_types' => $types->fetchAll(PDO::FETCH_COLUMN),
        'workflow_statuses' => array_combine($statuses, $statusLabels) ?: [],
    ];
}
