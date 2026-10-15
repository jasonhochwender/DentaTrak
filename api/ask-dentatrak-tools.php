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
require_once __DIR__ . '/encryption.php';

const ASK_TOOL_LIST_LIMIT = 15;

/**
 * Calendar-day timezone for case due dates and operational reporting.
 * The rest of the app (Kanban board, api/list-cases.php,
 * api/get-analytics.php, api/get-archived-cases.php) anchors "today" and
 * overdue/due-day math to America/New_York; Ask DentaTrak must resolve
 * the same intended timezone rather than silently inheriting whatever
 * the dev or production host's clock happens to be. Every "today",
 * relative window, and date bound below goes through these helpers.
 */
const ASK_TOOL_TIMEZONE = 'America/New_York';

function askToolNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone(ASK_TOOL_TIMEZONE));
}

function askToolToday(): string {
    return askToolNow()->format('Y-m-d');
}

/** YYYY-MM-DD shifted by a relative modifier ("+1 day", "-30 days"). */
function askToolDayShift(string $modifier, ?string $baseDate = null): string {
    $d = $baseDate === null
        ? askToolNow()
        : new DateTimeImmutable($baseDate, new DateTimeZone(ASK_TOOL_TIMEZONE));
    return $d->modify($modifier)->format('Y-m-d');
}

/** Signed whole-day difference between two YYYY-MM-DD dates (b - a). */
function askToolDayDiff(string $fromDate, string $toDate): int {
    $tz = new DateTimeZone(ASK_TOOL_TIMEZONE);
    $diff = (new DateTimeImmutable($fromDate, $tz))->diff(new DateTimeImmutable($toDate, $tz));
    return ($diff->invert ? -1 : 1) * (int)$diff->days;
}

/**
 * Validate self-scope parameters against the words actually exchanged in
 * the conversation. "my cases" / "my open cases" name the user's whole
 * authorized population ("my" = the cases they can see) - they do NOT
 * mean cases assigned to them. assigned_to_me (or an assignee/creator
 * value naming the asking user) is honored only when the user explicitly
 * asked about their own assignments/creations, or a prior turn clearly
 * established that scope for a follow-up.
 *
 * Returns [validatedParams, scopeNoteOrNull]. A note describes what was
 * removed so the answer can disclose the population actually used.
 */
function askToolValidateScopeParams(array $params, string $question, array $history): array {
    $selfAssign = !empty($params['assigned_to_me']);
    $selfCreator = false;
    foreach (['assignee' => 'assign', 'creator' => 'creator'] as $k => $kind) {
        $v = $params[$k] ?? null;
        if (is_string($v) && askToolValueNamesSelf($v)) {
            if ($kind === 'assign') $selfAssign = true; else $selfCreator = true;
        }
    }
    if (!$selfAssign && !$selfCreator) {
        return [$params, null];
    }

    $removed = [];
    if ($selfAssign && !askToolConversationHasSelfScope($question, $history, 'assign')) {
        if (!empty($params['assigned_to_me'])) {
            unset($params['assigned_to_me']);
            $removed[] = 'assigned_to_me';
        }
        if (isset($params['assignee']) && is_string($params['assignee']) && askToolValueNamesSelf($params['assignee'])) {
            unset($params['assignee']);
            $removed[] = 'assignee';
        }
    }
    if ($selfCreator && !askToolConversationHasSelfScope($question, $history, 'creator')) {
        if (isset($params['creator']) && is_string($params['creator']) && askToolValueNamesSelf($params['creator'])) {
            unset($params['creator']);
            $removed[] = 'creator';
        }
    }
    if (!$removed) {
        return [$params, null];
    }
    return [$params, 'A self-scope filter (' . implode(', ', $removed)
        . ') was removed because the user did not explicitly ask for cases assigned to or created by themselves; '
        . 'the results cover all cases the user is authorized to see.'];
}

/**
 * True when a filter value would select the asking user themselves: it
 * matches (or uniquely narrows to) the session user's email or name.
 */
function askToolValueNamesSelf(string $value): bool {
    $value = mb_strtolower(trim($value));
    if (mb_strlen($value) < 3) return false;
    $me = [
        mb_strtolower(trim((string)($_SESSION['user_email'] ?? ''))),
        mb_strtolower(trim((string)($_SESSION['user_name'] ?? ''))),
    ];
    foreach ($me as $m) {
        if (mb_strlen($m) < 3) continue;
        if ($m === $value || mb_strpos($m, $value) !== false || mb_strpos($value, $m) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Does the conversation explicitly establish a self-scope? Requires BOTH
 * a scope stem (assignment for 'assign', creation for 'creator') and a
 * first-person marker in the same USER message - bare "my cases" lacks a
 * scope stem and therefore does not qualify. The user literally typing
 * their own email/name also counts. Prior assistant turns that disclosed
 * a self-assigned population ("cases assigned to you") establish it for
 * follow-ups like "show me those".
 */
function askToolConversationHasSelfScope(string $question, array $history, string $kind): bool {
    $selfRx = '/(?:\bme\b|\bmy\b|\bmine\b|\bmyself\b|\bi\b|\bi\'m\b|\bich\b|\bmir\b|\bmich\b|'
        . '\bmein(?:e|en|em|er|es)?\b|\byo\b|\bmis?\b|\bmoi\b|\bmon\b|\bma\b|\bmes\b|\bje\b)/iu';
    $assignRx = '/(?:\bassign\w*\b|\bworkload\b|\bqueue\b|\bhandl\w*\b|work(?:ing)? on\b|'
        . 'zugewiesen\w*|zuweis\w*|zugeteil\w*|asign\w*|attribu\w*)/iu';
    $creatorRx = '/(?:\bcreat\w*\b|\benter\w+\b|\bmade\b|\bsubmit\w*\b|\bregist\w*\b|'
        . 'erstell\w*|angeleg\w*|eingetragen\w*|cread\w*|cre[ée]\w*|saisi\w*)/iu';
    $scopeRx = $kind === 'creator' ? $creatorRx : $assignRx;

    $userTexts = [$question];
    foreach ($history as $m) {
        if (($m['role'] ?? '') === 'user') $userTexts[] = (string)$m['content'];
    }
    foreach ($userTexts as $t) {
        if ($t === '') continue;
        // Explicit self-reference by literal email/name.
        $email = mb_strtolower(trim((string)($_SESSION['user_email'] ?? '')));
        $name = mb_strtolower(trim((string)($_SESSION['user_name'] ?? '')));
        $lt = mb_strtolower($t);
        if ((mb_strlen($email) >= 3 && mb_strpos($lt, $email) !== false)
            || (mb_strlen($name) >= 3 && mb_strpos($lt, $name) !== false)) {
            return true;
        }
        if (preg_match($scopeRx, $t) && preg_match($selfRx, $t)) {
            return true;
        }
    }

    // Assistant answers that told the user the population was theirs.
    $establishedRx = $kind === 'creator'
        ? '/created by you|you created|von dir erstellt|creados? por ti|créés? par vous/iu'
        : '/assigned to you|your assigned|cases you\'?re assigned|your workload|'
            . 'dir zugewiesen|deine zugewiesenen|te asign|vous sont assign|vos assign/iu';
    foreach ($history as $m) {
        if (($m['role'] ?? '') === 'assistant'
            && preg_match($establishedRx, (string)$m['content'])) {
            return true;
        }
    }
    return false;
}

/**
 * The allowlisted tool names. The planner may only request these.
 */
function getAskDentatrakToolNames(): array {
    return [
        'count_cases',
        'list_cases',
        'aggregate_cases_by_status',
        'aggregate_cases',
        'case_metrics',
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
            case 'aggregate_cases':
                return ['ok' => true, 'data' => askToolAggregateCases($pdo, $practiceId, $params)];
            case 'case_metrics':
                return ['ok' => true, 'data' => askToolCaseMetrics($pdo, $practiceId, $params)];
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
 * Map a failure at the endpoint boundary to [HTTP status, user message].
 * Known provider failures (the AI_* message codes thrown by ai-client.php)
 * keep the established 200 contract so the panel renders the localized
 * reason. Anything else - TypeError and other unexpected server faults -
 * is a 500 with a generic localized retry message; internal details go
 * to the server log and never reach the client.
 */
function askDentatrakErrorResponse(Throwable $e): array {
    $key = match ($e->getMessage()) {
        'AI_QUOTA_EXCEEDED'      => 'insights.errors.ai_quota',
        'AI_MODEL_UNAVAILABLE'   => 'insights.errors.ai_model_unavailable',
        'AI_INVALID_REQUEST',
        'AI_AUTH_ERROR'          => 'insights.errors.ai_config',
        'AI_SERVICE_UNAVAILABLE' => 'insights.errors.ai_unavailable',
        default                  => null,
    };
    if ($key !== null) {
        return [200, t($key)];
    }
    return [500, t('ask_dentatrak.errors.generic')];
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

/**
 * Echo the recognized filters a caller actually passed, so results can
 * disclose their population ("of cases assigned to you") and a narrowed
 * zero result is attributable to the filter, not to the practice having
 * no data.
 */
function askToolAppliedFilters(array $params): array {
    static $known = [
        'status', 'case_type', 'overdue', 'open_only', 'missing_due_date',
        'due_on', 'due_within_days', 'due_from', 'due_to',
        'created_within_days', 'created_from', 'created_to',
        'updated_within_days', 'not_updated_within_days', 'status_unchanged_for_days',
        'completed_within_days', 'completed_from', 'completed_to',
        'assigned_to_me', 'assignee', 'creator', 'unassigned', 'lab',
        'patient_name', 'include_archived',
    ];
    $applied = [];
    foreach ($known as $k) {
        if (array_key_exists($k, $params) && $params[$k] !== null && $params[$k] !== '' && $params[$k] !== false) {
            $applied[$k] = $params[$k];
        }
    }
    return $applied;
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
    $today = askToolToday();

    $terminal = getLastActiveWorkflowColumnId($practiceId);
    $doneStatuses = array_values(array_unique(array_filter([$terminal, 'Completed', 'Shipped'])));

    // Completion-window queries are historical: a case completed in range
    // stays "completed in range" even if it was later archived, so those
    // filters imply archived inclusion regardless of the flag.
    $includeArchived = !empty($params['include_archived'])
        || !empty($params['completed_within_days'])
        || askToolDate($params['completed_from'] ?? null) !== null
        || askToolDate($params['completed_to'] ?? null) !== null;
    if (!$includeArchived) {
        $where[] = 'c.archived = 0';
    }

    if (($status = askToolStr($params['status'] ?? null)) !== null) {
        // Accept either the stored column id or its display label (a
        // renamed stage like "QA Passed" resolves to id "Delivered").
        $resolved = getResolvedWorkflowStageLabelsForPractice($practiceId);
        foreach ($resolved as $stageId => $stageLabel) {
            if (mb_strtolower(trim((string)$stageLabel)) === mb_strtolower($status)) {
                $status = $stageId;
                break;
            }
        }
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

    // Open (not-yet-done) cases only - "open cases" phrasing without
    // implying a specific status.
    if (!empty($params['open_only'])) {
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':open_done' . $i; $bind[':open_done' . $i] = $s; }
        $where[] = 'c.status NOT IN (' . implode(',', $in) . ')';
    }

    // "Which cases are missing due dates" is an actionable workload
    // question: it means OPEN cases. A done case's missing due date is
    // history, not work. An explicit status/completion filter overrides.
    if (!empty($params['missing_due_date'])) {
        $where[] = "(c.due_date IS NULL OR c.due_date = '')";
        if (empty($params['status']) && empty($params['completed_within_days'])
            && askToolDate($params['completed_from'] ?? null) === null
            && askToolDate($params['completed_to'] ?? null) === null) {
            $in = [];
            foreach ($doneStatuses as $i => $s) { $in[] = ':mdd_done' . $i; $bind[':mdd_done' . $i] = $s; }
            $where[] = 'c.status NOT IN (' . implode(',', $in) . ')';
        }
    }

    // "Due tomorrow" style windows: due_on today|tomorrow, or explicit ranges.
    // Due-date questions are workload questions: they default to OPEN cases
    // (a completed case's due date is history, not work). An explicit
    // status/completion filter overrides the default.
    $dueOpenDefault = empty($params['status']) && empty($params['completed_within_days'])
        && askToolDate($params['completed_from'] ?? null) === null
        && askToolDate($params['completed_to'] ?? null) === null;
    if (($dueOn = askToolStr($params['due_on'] ?? null, 10)) !== null) {
        if ($dueOn === 'today') {
            $where[] = "c.due_date = :due_on";
            $bind[':due_on'] = $today;
        } elseif ($dueOn === 'tomorrow') {
            $where[] = "c.due_date = :due_on";
            $bind[':due_on'] = askToolDayShift('+1 day');
        }
    }

    if (($days = askToolInt($params['due_within_days'] ?? null, 0, 366)) !== null) {
        $where[] = "c.due_date IS NOT NULL AND c.due_date != '' AND c.due_date BETWEEN :due_from AND :due_to";
        $bind[':due_from'] = $today;
        $bind[':due_to'] = askToolDayShift('+' . $days . ' days');
    }
    if (($from = askToolDate($params['due_from'] ?? null)) !== null) {
        $where[] = "c.due_date IS NOT NULL AND c.due_date >= :due_from_x";
        $bind[':due_from_x'] = $from;
    }
    if (($to = askToolDate($params['due_to'] ?? null)) !== null) {
        $where[] = "c.due_date IS NOT NULL AND c.due_date != '' AND c.due_date <= :due_to_x";
        $bind[':due_to_x'] = $to;
    }

    $hasDueFilter = ($dueOn === 'today' || $dueOn === 'tomorrow')
        || $days !== null || $from !== null || $to !== null;
    if ($hasDueFilter && $dueOpenDefault) {
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':du_done' . $i; $bind[':du_done' . $i] = $s; }
        $where[] = 'c.status NOT IN (' . implode(',', $in) . ')';
    }

    if (($days = askToolInt($params['created_within_days'] ?? null, 0, 366)) !== null) {
        $where[] = "STR_TO_DATE(LEFT(COALESCE(c.creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= :created_after";
        $bind[':created_after'] = askToolDayShift('-' . $days . ' days');
    }
    if (($from = askToolDate($params['created_from'] ?? null)) !== null) {
        $where[] = "STR_TO_DATE(LEFT(COALESCE(c.creation_date, ''), 10), '%Y-%m-%d') >= :created_from";
        $bind[':created_from'] = $from;
    }
    if (($to = askToolDate($params['created_to'] ?? null)) !== null) {
        $where[] = "STR_TO_DATE(LEFT(COALESCE(c.creation_date, ''), 10), '%Y-%m-%d') <= :created_to";
        $bind[':created_to'] = $to;
    }

    if (($days = askToolInt($params['updated_within_days'] ?? null, 0, 3660)) !== null) {
        $where[] = "STR_TO_DATE(LEFT(COALESCE(c.last_update_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= :updated_after";
        $bind[':updated_after'] = askToolDayShift('-' . $days . ' days');
    }
    // Stale cases: last_update_date at or before today - N days.
    if (($days = askToolInt($params['not_updated_within_days'] ?? null, 1, 3660)) !== null) {
        $where[] = "STR_TO_DATE(LEFT(COALESCE(c.last_update_date, '1970-01-01'), 10), '%Y-%m-%d') <= :stale_before";
        $bind[':stale_before'] = askToolDayShift('-' . $days . ' days');
    }

    // "Sitting in the same status" / stagnant cases: the status itself has
    // not changed for at least N days (status_changed_at stamp; cases with
    // no stamp fall back to creation so never-moved cases still qualify).
    // Workload-shaped like missing_due_date/unassigned: defaults to OPEN
    // cases unless an explicit status/completion filter overrides.
    if (($days = askToolInt($params['status_unchanged_for_days'] ?? null, 1, 3660)) !== null) {
        $where[] = "COALESCE(c.status_changed_at, STR_TO_DATE(LEFT(c.creation_date,10),'%Y-%m-%d')) <= :unchanged_before";
        $bind[':unchanged_before'] = askToolDayShift('-' . $days . ' days') . ' 00:00:00';
        if (empty($params['status']) && empty($params['completed_within_days'])
            && askToolDate($params['completed_from'] ?? null) === null
            && askToolDate($params['completed_to'] ?? null) === null) {
            $in = [];
            foreach ($doneStatuses as $i => $s) { $in[] = ':sud_done' . $i; $bind[':sud_done' . $i] = $s; }
            $where[] = 'c.status NOT IN (' . implode(',', $in) . ')';
        }
    }

    if (($days = askToolInt($params['completed_within_days'] ?? null, 0, 366)) !== null) {
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':cdone' . $i; $bind[':cdone' . $i] = $s; }
        $where[] = 'c.status IN (' . implode(',', $in) . ')';
        $where[] = 'DATE(' . askToolDeliveredDateExpr($bind, 'cw', $terminal) . ') >= :completed_after';
        $bind[':completed_after'] = askToolDayShift('-' . $days . ' days');
    }

    // Completed in an explicit date range. Completion time = the moment the
    // case entered the practice's terminal workflow stage: status_changed_at
    // when present, else the latest status_changed activity-log entry into
    // the terminal stage (same rule as Practice Insights "delivered").
    $completedFrom = askToolDate($params['completed_from'] ?? null);
    $completedTo = askToolDate($params['completed_to'] ?? null);
    if ($completedFrom !== null || $completedTo !== null) {
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':cr_done' . $i; $bind[':cr_done' . $i] = $s; }
        $where[] = 'c.status IN (' . implode(',', $in) . ')';
        if ($completedFrom !== null) {
            $where[] = 'DATE(' . askToolDeliveredDateExpr($bind, 'cf', $terminal) . ') >= :completed_from';
            $bind[':completed_from'] = $completedFrom;
        }
        if ($completedTo !== null) {
            $where[] = 'DATE(' . askToolDeliveredDateExpr($bind, 'ct', $terminal) . ') <= :completed_to';
            $bind[':completed_to'] = $completedTo;
        }
    }

    if (($assignee = askToolStr($params['assignee'] ?? null)) !== null) {
        $where[] = "c.assigned_to LIKE :assignee";
        $bind[':assignee'] = '%' . $assignee . '%';
    }

    // Case CREATOR is stored as created_by_user_id - distinct from the
    // current assignee. Match on name or email of the creating user.
    if (($creator = askToolStr($params['creator'] ?? null)) !== null) {
        $where[] = "c.created_by_user_id IN (SELECT cu.id FROM users cu WHERE LOWER(cu.email) LIKE :creator OR LOWER(CONCAT_WS(' ', cu.first_name, cu.last_name)) LIKE :creator2)";
        $bind[':creator'] = '%' . mb_strtolower($creator) . '%';
        $bind[':creator2'] = '%' . mb_strtolower($creator) . '%';
    }

    if (!empty($params['assigned_to_me'])) {
        $where[] = '(' . askToolAssignedToMePredicate($bind) . ')';
    }

    // "Which cases are unassigned" is also workload-shaped: open cases
    // needing an assignee. Completed/archived cases without an assignee
    // are history. An explicit status/completion filter overrides.
    if (!empty($params['unassigned'])) {
        $where[] = "(c.assigned_to IS NULL OR c.assigned_to = '')";
        if (empty($params['status']) && empty($params['completed_within_days'])
            && askToolDate($params['completed_from'] ?? null) === null
            && askToolDate($params['completed_to'] ?? null) === null) {
            $in = [];
            foreach ($doneStatuses as $i => $s) { $in[] = ':un_done' . $i; $bind[':un_done' . $i] = $s; }
            $where[] = 'c.status NOT IN (' . implode(',', $in) . ')';
        }
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

    // Patient names are encrypted at rest, so a SQL LIKE can never match.
    // Resolve matches after decryption (same approach as the cases list's
    // filterCasesBySearch) and narrow the WHERE to the matching case_ids.
    if (($patient = askToolStr($params['patient_name'] ?? null)) !== null) {
        $ids = askToolCaseIdsForPatientName($pdo, $where, $bind, $patient);
        if (!$ids) {
            $where[] = '1 = 0';
        } else {
            $in = [];
            foreach ($ids as $i => $id) { $in[] = ':pid' . $i; $bind[':pid' . $i] = $id; }
            $where[] = 'c.case_id IN (' . implode(',', $in) . ')';
        }
    }

    return [implode(' AND ', $where), $bind];
}

/**
 * Decrypt one PII field for display. Legacy plaintext rows pass through
 * unchanged when decryption fails (mirrors the cases-cache fallback).
 */
function askToolDecryptField($value) {
    if ($value === null || $value === '') return $value;
    try {
        return PIIEncryption::decrypt($value);
    } catch (Throwable $e) {
        return $value;
    }
}

/**
 * Case ids whose decrypted patient name contains the needle. The
 * candidates are pre-filtered by the WHERE/binds already built, so the
 * lookup stays inside the same authorized scope as every other filter.
 */
function askToolCaseIdsForPatientName(PDO $pdo, array $where, array $bind, string $needle): array {
    ensureAuthorizedCaseIdsTempTable((int)$bind[':practice_id']);
    $stmt = $pdo->prepare(
        "SELECT c.case_id, c.patient_first_name, c.patient_last_name
         FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($bind);

    $needle = mb_strtolower($needle);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim(askToolDecryptField($row['patient_first_name']) . ' ' . askToolDecryptField($row['patient_last_name']));
        if ($name !== '' && mb_stripos($name, $needle) !== false) {
            $ids[] = $row['case_id'];
        }
    }
    return $ids;
}

/**
 * Build the display patient/dentist names from encrypted row fields.
 */
function askToolNameFields(array &$row): void {
    $first = askToolDecryptField($row['patient_first_name'] ?? null);
    $last = askToolDecryptField($row['patient_last_name'] ?? null);
    $row['patient_name'] = trim(($first ?? '') . ' ' . ($last ?? ''));
    unset($row['patient_first_name'], $row['patient_last_name']);
    if (array_key_exists('dentist_name', $row)) {
        $row['dentist_name'] = askToolDecryptField($row['dentist_name']);
    }
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
    return [
        'count' => (int)$stmt->fetchColumn(),
        'filters_applied' => askToolAppliedFilters($params) ?: null,
    ];
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

    // Fixed ORDER BY allowlist - the model picks a key, never raw SQL.
    $sort = askToolStr($params['sort'] ?? null, 20);
    $sortOrder = (askToolStr($params['sort_order'] ?? null, 10) === 'asc') ? 'ASC' : 'DESC';
    switch ($sort) {
        case 'updated':
            $orderBy = "STR_TO_DATE(LEFT(COALESCE(c.last_update_date, '1970-01-01'), 10), '%Y-%m-%d') $sortOrder, c.case_id ASC";
            break;
        case 'created':
            $orderBy = "STR_TO_DATE(LEFT(COALESCE(c.creation_date, '1970-01-01'), 10), '%Y-%m-%d') $sortOrder, c.case_id ASC";
            break;
        case 'status_age':
            // Longest time in the CURRENT status first: oldest
            // status_changed_at wins; cases never moved rank by creation
            // date so "untouched since intake" still surfaces.
            $orderBy = "COALESCE(c.status_changed_at, STR_TO_DATE(LEFT(COALESCE(c.creation_date,'1970-01-01'),10),'%Y-%m-%d')) ASC, c.case_id ASC";
            break;
        default:
            // Due-date order is the default: soonest first, no-due-date last.
            $sort = 'due';
            $orderBy = "(c.due_date IS NULL OR c.due_date = '') ASC, c.due_date ASC, c.case_id ASC";
    }

    $stmt = $pdo->prepare(
        "SELECT c.case_id,
                c.patient_first_name, c.patient_last_name,
                c.dentist_name, c.case_type, c.status, c.due_date,
                c.assigned_to, c.patient_appointment_date, c.last_update_date,
                COALESCE(c.status_changed_at, STR_TO_DATE(LEFT(COALESCE(c.creation_date,'1970-01-01'),10),'%Y-%m-%d')) AS status_since
         FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         WHERE $where
         ORDER BY $orderBy
         LIMIT " . (int)$limit
    );
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        askToolNameFields($row);
        // Whole days the case has been in its current status - lets the
        // composer answer "sitting the longest" concretely.
        $since = $row['status_since'] ?? null;
        $row['days_in_status'] = ($since && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$since))
            ? askToolDayDiff(substr((string)$since, 0, 10), askToolToday())
            : null;
        unset($row['status_since']);
    }
    unset($row);

    return [
        'total_matching' => $total,
        'returned' => min($total, $limit),
        'sorted_by' => $sort,
        'filters_applied' => askToolAppliedFilters($params) ?: null,
        'cases' => $rows,
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
        ':since' => askToolNow()->modify('-' . $days . ' days')->format('Y-m-d H:i:s'),
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
                c.patient_first_name, c.patient_last_name,
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
    foreach ($rows as &$row) {
        askToolNameFields($row);
    }
    unset($row);

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

/**
 * Lab-designated assignee values for this practice: Lab-flagged shared
 * assignment labels plus the emails of Lab-flagged practice members.
 * Returned lowercased for matching against c.assigned_to (which stores the
 * label text or member email).
 */
function askToolLabAssigneeValues(PDO $pdo, int $practiceId): array {
    $labels = $pdo->prepare(
        "SELECT label FROM practice_assignment_labels
         WHERE practice_id = :p AND is_lab = 1"
    );
    $labels->execute([':p' => $practiceId]);

    $users = $pdo->prepare(
        "SELECT u.email FROM practice_users pu
         JOIN users u ON u.id = pu.user_id
         WHERE pu.practice_id = :p AND pu.is_lab = 1 AND u.email IS NOT NULL AND u.email != ''"
    );
    $users->execute([':p' => $practiceId]);

    $values = [];
    foreach (array_merge($labels->fetchAll(PDO::FETCH_COLUMN) ?: [], $users->fetchAll(PDO::FETCH_COLUMN) ?: []) as $v) {
        $v = mb_strtolower(trim((string)$v));
        if ($v !== '') $values[$v] = true;
    }
    return array_keys($values);
}

/**
 * Grouped counts over authorized cases. group_by is allowlisted; every row
 * still passes through the shared authorized-case filter set, so breakdowns
 * can be combined with any supported case filter (overdue, lab, date
 * ranges, etc.).
 */
function askToolAggregateCases(PDO $pdo, int $practiceId, array $params): array {
    $groupBy = askToolStr($params['group_by'] ?? null, 20);
    $allowed = ['assigned_to', 'created_by', 'lab', 'case_type', 'status'];
    if (!in_array($groupBy, $allowed, true)) {
        return ['error' => 'invalid_group_by', 'allowed' => $allowed];
    }

    [$where, $bind] = askToolCaseFilters($practiceId, $params);
    $join = '';
    $notes = [];

    // "Who has the most cases" / "which labs have the most" means CURRENT
    // WORKLOAD by default: when the caller gives no explicit population
    // constraint, current-assignee groupings scope to open cases (not
    // archived, not in a done status). An explicit status/done filter
    // overrides the default.
    $explicitPopulation = !empty($params['status']) || !empty($params['open_only'])
        || !empty($params['overdue']) || !empty($params['include_archived'])
        || !empty($params['completed_within_days'])
        || askToolDate($params['completed_from'] ?? null) !== null
        || askToolDate($params['completed_to'] ?? null) !== null;
    $scope = !empty($params['include_archived'])
        ? 'all authorized cases (including archived)'
        : 'all authorized non-archived cases';
    if (in_array($groupBy, ['assigned_to', 'lab'], true) && !$explicitPopulation) {
        $terminal = getLastActiveWorkflowColumnId($practiceId);
        $doneStatuses = array_values(array_unique(array_filter([$terminal, 'Completed', 'Shipped'])));
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':agg_done' . $i; $bind[':agg_done' . $i] = $s; }
        $where .= ' AND c.status NOT IN (' . implode(',', $in) . ')';
        $scope = 'open cases only (not archived, not in a done status) - current workload';
        $notes[] = 'Grouped over open cases only (current workload). Ask for a specific status or include_archived to widen the scope.';
    }

    switch ($groupBy) {
        case 'assigned_to':
            $groupExpr = "CASE WHEN c.assigned_to IS NULL OR c.assigned_to = '' THEN '__unassigned__' ELSE TRIM(c.assigned_to) END";
            break;
        case 'lab':
            $labValues = askToolLabAssigneeValues($pdo, $practiceId);
            if (!$labValues) {
                return ['group_by' => 'lab', 'groups' => [], 'total_matching' => 0,
                        'note' => 'no_lab_assignees',
                        'definition' => 'Lab = Lab-flagged shared assignment labels and Lab-flagged practice members. This practice has none configured.'];
            }
            $in = [];
            foreach ($labValues as $i => $l) { $in[] = ':lv' . $i; $bind[':lv' . $i] = $l; }
            $where .= ' AND LOWER(TRIM(c.assigned_to)) IN (' . implode(',', $in) . ')';
            $groupExpr = 'TRIM(c.assigned_to)';
            $notes[] = 'Counts cases whose CURRENT assignee is a lab. Historical lab work is not covered by this grouping.';
            break;
        case 'created_by':
            $join = ' LEFT JOIN users cu ON cu.id = c.created_by_user_id';
            $groupExpr = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', cu.first_name, cu.last_name)), ''), cu.email, '__unknown__')";
            $notes[] = 'Creator = the user who created the case, which may differ from the current assignee.';
            break;
        case 'case_type':
            $groupExpr = "COALESCE(NULLIF(TRIM(c.case_type), ''), '__no_case_type__')";
            break;
        case 'status':
        default:
            $groupExpr = 'c.status';
            break;
    }

    $limit = askToolInt($params['limit'] ?? null, 1, 50) ?? 25;
    $stmt = $pdo->prepare(
        "SELECT $groupExpr AS grp, COUNT(*) AS cnt
         FROM cases_cache c
         INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci
         $join
         WHERE $where
         GROUP BY grp
         ORDER BY cnt DESC, grp ASC
         LIMIT " . (int)$limit
    );
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sentinels = [
        '__unassigned__' => '(unassigned)',
        '__unknown__' => '(unknown creator)',
        '__no_case_type__' => '(no case type)',
    ];
    $total = 0;
    foreach ($rows as &$r) {
        $total += (int)$r['cnt'];
        if (isset($sentinels[$r['grp']])) $r['grp'] = $sentinels[$r['grp']];
    }
    unset($r);

    // Flag which assignee groups are labs so answers can call them out.
    if ($groupBy === 'assigned_to') {
        $labSet = array_fill_keys(askToolLabAssigneeValues($pdo, $practiceId), true);
        foreach ($rows as &$r) {
            $r['is_lab'] = isset($labSet[mb_strtolower(trim((string)$r['grp']))]);
        }
        unset($r);
    }

    $groups = [];
    foreach ($rows as $r) {
        $g = ['name' => $r['grp'], 'count' => (int)$r['cnt']];
        if (isset($r['is_lab'])) $g['is_lab'] = (bool)$r['is_lab'];
        $groups[] = $g;
    }

    // Ties: name every group sharing the top count so "who has the most"
    // is never answered with an arbitrary single winner.
    $tiedAtTop = null;
    if (count($groups) > 1 && $groups[0]['count'] === $groups[1]['count']) {
        $topCount = $groups[0]['count'];
        $tiedAtTop = array_map(
            fn($g) => $g['name'],
            array_values(array_filter($groups, fn($g) => $g['count'] === $topCount))
        );
    }

    return [
        'group_by' => $groupBy,
        'scope' => $scope,
        'filters_applied' => askToolAppliedFilters($params) ?: null,
        'groups' => $groups,
        'tied_at_top' => $tiedAtTop,
        'total_matching' => $total,
        'note' => $notes ? implode(' ', $notes) : null,
    ];
}

/**
 * The shared "date the case reached the terminal workflow stage"
 * expression. status_changed_at when present, else the latest
 * status_changed activity-log entry into the terminal stage - the same
 * rule Practice Insights uses for "delivered".
 */
function askToolDeliveredDateExpr(array &$bind, string $paramPrefix, string $terminal): string {
    // Each call gets a unique placeholder: PDO/MySQL rejects a named
    // parameter that appears more than once in a single statement, and
    // callers embed this expression at several spots in one query.
    static $seq = 0;
    $key = ':' . $paramPrefix . '_' . (++$seq) . '_terminal';
    $bind[$key] = $terminal;
    // status_changed_at is stamped on EVERY status change, so callers MUST
    // pair this with a `c.status IN (<terminal>)` predicate - then the
    // current status being terminal guarantees the stamp is the latest
    // entry into the terminal stage. The activity-log fallback additionally
    // accepts a 'case_created' event into the terminal stage so cases
    // created directly in the final column still get a completion date.
    return "COALESCE(c.status_changed_at, (SELECT MAX(l.created_at) FROM case_activity_log l WHERE l.case_id = c.case_id AND l.event_type IN ('status_changed','case_created') AND l.new_status = $key))";
}

/**
 * Computed metrics with explicit definitions. Each result carries its own
 * `definition` text plus the inputs that shaped it (date range, scope),
 * so the composer can state exactly what was measured - and cannot quietly
 * blur currently-overdue vs completed-late, or creator vs assignee.
 */
function askToolCaseMetrics(PDO $pdo, int $practiceId, array $params): array {
    $metric = askToolStr($params['metric'] ?? null, 40);
    $allowed = ['open_overdue_share', 'late_completions', 'turnaround', 'volume', 'remake_rate'];
    if (!in_array($metric, $allowed, true)) {
        return ['error' => 'invalid_metric', 'allowed' => $allowed];
    }

    $today = askToolToday();
    $terminal = getLastActiveWorkflowColumnId($practiceId);
    $doneStatuses = array_values(array_unique(array_filter([$terminal, 'Completed', 'Shipped'])));
    $doneIn = function (array &$bind, string $prefix) use ($doneStatuses) {
        $in = [];
        foreach ($doneStatuses as $i => $s) { $in[] = ':' . $prefix . $i; $bind[':' . $prefix . $i] = $s; }
        return implode(',', $in);
    };
    $authJoin = 'INNER JOIN authorized_case_ids a ON a.case_id = c.case_id COLLATE utf8mb4_unicode_ci';

    switch ($metric) {

        case 'open_overdue_share': {
            // Denominator: authorized, non-archived, not in a done status
            // ("open cases"). Numerator: open cases with due_date < today.
            // A caller-supplied `status` is meaningless here - the metric
            // defines its own status predicate. Dropping it prevents
            // status:"Open" from silently zeroing the denominator.
            $applied = askToolAppliedFilters($params);
            unset($applied['status'], $params['status']);
            [$where, $bind] = askToolCaseFilters($practiceId, $params);
            $bind[':practice_id'] = $practiceId;
            $in = $doneIn($bind, 'oos_done');
            $where .= " AND c.status NOT IN ($in)";

            $openStmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache c $authJoin WHERE $where");
            $openStmt->execute($bind);
            $open = (int)$openStmt->fetchColumn();

            $ovBind = $bind;
            $ovBind[':oos_today'] = $today;
            $ovStmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache c $authJoin WHERE $where AND c.due_date IS NOT NULL AND c.due_date != '' AND c.due_date < :oos_today");
            $ovStmt->execute($ovBind);
            $overdue = (int)$ovStmt->fetchColumn();

            return [
                'metric' => $metric,
                'as_of' => $today,
                'scope' => 'open cases (not archived, not in a done status)',
                'filters_applied' => $applied ?: null,
                'open_cases' => $open,
                'overdue_cases' => $overdue,
                'pct' => $open > 0 ? round($overdue / $open * 100, 1) : null,
                'summary' => $open > 0
                    ? "$overdue of $open open cases are currently overdue (" . round($overdue / $open * 100, 1) . '%)'
                    : ($applied
                        ? 'No open cases match the applied filters, so no overdue share can be calculated for that subset'
                        : 'There are no open cases, so no overdue share can be calculated'),
                'definition' => 'Currently overdue = open case whose due date is before today. Cases completed late in the past are not included.',
            ];
        }

        case 'late_completions': {
            // Cases that reached a done status inside [from, to]. Late =
            // due date strictly before the delivered date. Cases with no
            // due date are excluded from the rate and counted separately.
            // Archived cases are included: archiving is a visibility flag,
            // not an un-completion - historical completions still count.
            $from = askToolDate($params['from'] ?? null) ?? askToolDayShift('-30 days');
            $to = askToolDate($params['to'] ?? null) ?? $today;

            $lcApplied = askToolAppliedFilters($params);
            $params['include_archived'] = true;
            unset($lcApplied['status'], $params['status']); // metric defines its own status scope
            [$where, $bind] = askToolCaseFilters($practiceId, $params);
            $in = $doneIn($bind, 'lc_done');
            $where .= " AND c.status IN ($in)";
            $deliveredFrom = askToolDeliveredDateExpr($bind, 'lc', $terminal);
            $deliveredTo = askToolDeliveredDateExpr($bind, 'lc', $terminal);
            $deliveredLate = askToolDeliveredDateExpr($bind, 'lc', $terminal);
            $where .= " AND DATE($deliveredFrom) >= :lc_from AND DATE($deliveredTo) <= :lc_to";
            $bind[':lc_from'] = $from;
            $bind[':lc_to'] = $to;

            $row = $pdo->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN c.due_date IS NOT NULL AND c.due_date != '' THEN 1 ELSE 0 END) AS with_due,
                        SUM(CASE WHEN c.due_date IS NOT NULL AND c.due_date != ''
                                  AND STR_TO_DATE(LEFT(c.due_date,10),'%Y-%m-%d') < DATE($deliveredLate) THEN 1 ELSE 0 END) AS late
                 FROM cases_cache c $authJoin WHERE $where"
            );
            $row->execute($bind);
            $r = $row->fetch(PDO::FETCH_ASSOC) ?: [];

            $withDue = (int)($r['with_due'] ?? 0);
            $late = (int)($r['late'] ?? 0);
            return [
                'metric' => $metric,
                'range' => ['from' => $from, 'to' => $to, 'inclusive' => true],
                'filters_applied' => $lcApplied ?: null,
                'completed_cases' => (int)($r['total'] ?? 0),
                'completed_with_due_date' => $withDue,
                'completed_without_due_date' => (int)($r['total'] ?? 0) - $withDue,
                'completed_late' => $late,
                'pct' => $withDue > 0 ? round($late / $withDue * 100, 1) : null,
                'summary' => $withDue > 0
                    ? "$late of $withDue cases completed between $from and $to were late (" . round($late / $withDue * 100, 1) . '%)'
                    : ((int)($r['total'] ?? 0) > 0
                        ? "No cases completed between $from and $to had a due date, so lateness cannot be measured"
                        : "No cases were completed between $from and $to"),
                'definition' => 'Completed late = case reached the final workflow stage after its due date. Completion date = the date it entered the final stage. Cases without a due date are excluded from the percentage. Archived cases are included as history.',
            ];
        }

        case 'turnaround': {
            // Delivered in [from, to]: days from creation_date to the date
            // the case entered the terminal stage. Optional group_by=lab
            // uses observed lab assignment periods that ended 'delivered'
            // (the Lab Insights definition), not current assignment.
            $from = askToolDate($params['from'] ?? null) ?? askToolDayShift('-90 days');
            $to = askToolDate($params['to'] ?? null) ?? $today;
            $groupBy = askToolStr($params['group_by'] ?? null, 10);

            if ($groupBy === 'lab') {
                // Placeholder names differ per occurrence: PDO/MySQL
                // rejects a named parameter used twice in one statement.
                $stmt = $pdo->prepare(
                    "SELECT p.assignee_display_name_snapshot AS lab,
                            COUNT(DISTINCT p.case_id) AS cases,
                            AVG(x.lab_days) AS avg_days
                     FROM case_lab_assignment_periods p
                     INNER JOIN authorized_case_ids a ON a.case_id = p.case_id COLLATE utf8mb4_unicode_ci
                     JOIN (
                         SELECT p2.case_id, p2.label_id, p2.user_id, p2.assignee_type,
                                SUM(TIMESTAMPDIFF(SECOND, p2.started_at, p2.ended_at)) / 86400.0 AS lab_days
                         FROM case_lab_assignment_periods p2
                         WHERE p2.practice_id = :p_inner AND p2.is_lab_snapshot = 1
                           AND p2.history_quality = 'observed' AND p2.end_reason = 'delivered'
                           AND p2.ended_at >= :from_inner AND p2.ended_at < :to_inner
                         GROUP BY p2.case_id, p2.label_id, p2.user_id, p2.assignee_type
                     ) x ON x.case_id = p.case_id AND x.label_id <=> p.label_id
                        AND x.user_id <=> p.user_id AND x.assignee_type = p.assignee_type
                     WHERE p.practice_id = :p_outer AND p.is_lab_snapshot = 1
                       AND p.history_quality = 'observed' AND p.end_reason = 'delivered'
                       AND p.ended_at >= :from_outer AND p.ended_at < :to_outer
                     GROUP BY p.assignee_display_name_snapshot, p.label_id, p.user_id, p.assignee_type
                     ORDER BY avg_days DESC"
                );
                $stmt->execute([
                    ':p_inner' => $practiceId,
                    ':p_outer' => $practiceId,
                    ':from_inner' => $from . ' 00:00:00',
                    ':from_outer' => $from . ' 00:00:00',
                    ':to_inner' => askToolDayShift('+1 day', $to) . ' 00:00:00',
                    ':to_outer' => askToolDayShift('+1 day', $to) . ' 00:00:00',
                ]);
                $labs = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $labs[] = [
                        'lab' => $r['lab'],
                        'completed_cases' => (int)$r['cases'],
                        'avg_turnaround_days' => round((float)$r['avg_days'], 1),
                    ];
                }
                return [
                    'metric' => $metric,
                    'range' => ['from' => $from, 'to' => $to, 'inclusive' => true],
                    'filters_applied' => askToolAppliedFilters($params) ?: null,
                    'group_by' => 'lab',
                    'labs' => $labs,
                    'definition' => 'Lab turnaround = average days a case spent assigned to that lab (observed lab assignments that ended with the case being delivered). Cases with no recorded lab assignment are not included.',
                ];
            }

            // Same archived-as-history rule as late_completions.
            $taApplied = askToolAppliedFilters($params);
            $params['include_archived'] = true;
            unset($taApplied['status'], $params['status']); // metric defines its own status scope
            [$where, $bind] = askToolCaseFilters($practiceId, $params);
            $in = $doneIn($bind, 'ta_done');
            $where .= " AND c.status IN ($in)";
            $where .= " AND DATE(" . askToolDeliveredDateExpr($bind, 'ta', $terminal) . ") >= :ta_from"
                    . " AND DATE(" . askToolDeliveredDateExpr($bind, 'ta', $terminal) . ") <= :ta_to";
            $where .= " AND c.creation_date IS NOT NULL AND c.creation_date != ''";
            $bind[':ta_from'] = $from;
            $bind[':ta_to'] = $to;

            $dAvg = askToolDeliveredDateExpr($bind, 'ta', $terminal);
            $dMin = askToolDeliveredDateExpr($bind, 'ta', $terminal);
            $dMax = askToolDeliveredDateExpr($bind, 'ta', $terminal);
            $row = $pdo->prepare(
                "SELECT AVG(DATEDIFF(DATE($dAvg), STR_TO_DATE(LEFT(c.creation_date,10),'%Y-%m-%d'))) AS avg_days,
                        MIN(DATEDIFF(DATE($dMin), STR_TO_DATE(LEFT(c.creation_date,10),'%Y-%m-%d'))) AS min_days,
                        MAX(DATEDIFF(DATE($dMax), STR_TO_DATE(LEFT(c.creation_date,10),'%Y-%m-%d'))) AS max_days,
                        COUNT(*) AS n
                 FROM cases_cache c $authJoin WHERE $where"
            );
            $row->execute($bind);
            $r = $row->fetch(PDO::FETCH_ASSOC) ?: [];

            $avg = isset($r['avg_days']) ? round((float)$r['avg_days'], 1) : null;
            return [
                'metric' => $metric,
                'range' => ['from' => $from, 'to' => $to, 'inclusive' => true],
                'filters_applied' => $taApplied ?: null,
                'completed_cases' => (int)($r['n'] ?? 0),
                'avg_turnaround_days' => $avg,
                'min_days' => (int)($r['min_days'] ?? 0),
                'max_days' => (int)($r['max_days'] ?? 0),
                'summary' => $avg !== null
                    ? "Average turnaround was $avg days across " . (int)$r['n'] . " case(s) completed between $from and $to"
                    : "No cases were completed between $from and $to, so turnaround cannot be measured",
                'definition' => 'Turnaround = calendar days from case creation to the date the case entered the final workflow stage. Only cases completed in the range are measured. Archived cases are included as history.',
            ];
        }

        case 'volume': {
            // Created and delivered counts for [from, to] vs the
            // same-length window immediately before it.
            $from = askToolDate($params['from'] ?? null) ?? askToolDayShift('-30 days');
            $to = askToolDate($params['to'] ?? null) ?? $today;
            $len = askToolDayDiff($from, $to) + 1;
            $prevTo = askToolDayShift('-1 day', $from);
            $prevFrom = askToolDayShift('-' . $len . ' days', $from);

            $countRange = function (string $kind, string $f, string $t) use ($pdo, $practiceId, $authJoin, $doneIn, $terminal) {
                $bind = [':p' => $practiceId];
                if ($kind === 'created') {
                    $where = "c.practice_id = :p AND STR_TO_DATE(LEFT(COALESCE(c.creation_date,''),10),'%Y-%m-%d') >= :f AND STR_TO_DATE(LEFT(COALESCE(c.creation_date,''),10),'%Y-%m-%d') <= :t";
                } else {
                    $in = $doneIn($bind, 'vd');
                    $dF = askToolDeliveredDateExpr($bind, 'vt', $terminal);
                    $dT = askToolDeliveredDateExpr($bind, 'vt', $terminal);
                    $where = "c.practice_id = :p AND c.status IN ($in) AND DATE($dF) >= :f AND DATE($dT) <= :t";
                }
                $bind[':f'] = $f; $bind[':t'] = $t;
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache c $authJoin WHERE $where");
                $stmt->execute($bind);
                return (int)$stmt->fetchColumn();
            };

            $created = $countRange('created', $from, $to);
            $delivered = $countRange('delivered', $from, $to);
            $prevCreated = $countRange('created', $prevFrom, $prevTo);
            $prevDelivered = $countRange('delivered', $prevFrom, $prevTo);

            $delta = function ($cur, $prev) {
                if ($prev <= 0) return null;
                return round(($cur - $prev) / $prev * 100, 1);
            };

            return [
                'metric' => $metric,
                'range' => ['from' => $from, 'to' => $to, 'inclusive' => true],
                'filters_applied' => askToolAppliedFilters($params) ?: null,
                'previous_range' => ['from' => $prevFrom, 'to' => $prevTo, 'inclusive' => true],
                'cases_created' => $created,
                'cases_delivered' => $delivered,
                'prev_cases_created' => $prevCreated,
                'prev_cases_delivered' => $prevDelivered,
                'created_change_pct' => $delta($created, $prevCreated),
                'delivered_change_pct' => $delta($delivered, $prevDelivered),
                'summary' => "$created cases created and $delivered delivered between $from and $to, versus $prevCreated created and $prevDelivered delivered in the prior equal period ($prevFrom to $prevTo)",
                'definition' => 'Volume = cases created vs cases that reached the final workflow stage in each range. Archived cases are included as history. Percent change is null when the previous period had zero cases.',
            ];
        }

        case 'remake_rate': {
            // Numerator and denominator describe the SAME population:
            // cases that reached a done status inside [from, to]. The rate
            // asks "of the cases completed in this period, how many were
            // ever remade" - so the numerator counts completed-in-range
            // cases having at least one recorded remake event, NOT events
            // on unrelated cases. remake_events (events initiated in the
            // range, on any case) is still reported for transparency.
            $from = askToolDate($params['from'] ?? null) ?? askToolDayShift('-90 days');
            $to = askToolDate($params['to'] ?? null) ?? $today;
            $toTs = $to . ' 23:59:59';

            $ev = $pdo->prepare(
                "SELECT COUNT(*) AS events, COUNT(DISTINCT r.case_id) AS uniq_cases
                 FROM case_remake_events r
                 INNER JOIN authorized_case_ids a ON a.case_id = r.case_id COLLATE utf8mb4_unicode_ci
                 WHERE r.practice_id = :p AND r.initiated_at >= :f AND r.initiated_at <= :t"
            );
            $ev->execute([':p' => $practiceId, ':f' => $from . ' 00:00:00', ':t' => $toTs]);
            $er = $ev->fetch(PDO::FETCH_ASSOC) ?: [];

            unset($params['status']); // metric defines its own status scope
            $bind = [':p' => $practiceId];
            $in = $doneIn($bind, 'rr_done');
            $dF = askToolDeliveredDateExpr($bind, 'rr', $terminal);
            $dT = askToolDeliveredDateExpr($bind, 'rr', $terminal);
            $comp = $pdo->prepare(
                "SELECT COUNT(DISTINCT c.case_id) AS completed,
                        COUNT(DISTINCT CASE WHEN r.case_id IS NOT NULL THEN c.case_id END) AS remade
                 FROM cases_cache c
                 $authJoin
                 LEFT JOIN case_remake_events r ON r.case_id = c.case_id
                 WHERE c.practice_id = :p AND c.status IN ($in)
                   AND DATE($dF) >= :f2 AND DATE($dT) <= :t2"
            );
            $comp->execute($bind + [':f2' => $from, ':t2' => $to]);
            $cr = $comp->fetch(PDO::FETCH_ASSOC) ?: [];
            $completed = (int)($cr['completed'] ?? 0);
            $remade = (int)($cr['remade'] ?? 0);

            return [
                'metric' => $metric,
                'range' => ['from' => $from, 'to' => $to, 'inclusive' => true],
                'filters_applied' => askToolAppliedFilters($params) ?: null,
                'remake_events' => (int)($er['events'] ?? 0),
                'completed_cases_with_remake' => $remade,
                'completed_cases_in_range' => $completed,
                'remake_rate_pct' => $completed > 0 ? round($remade / $completed * 100, 1) : null,
                'summary' => $completed > 0
                    ? "$remade of $completed cases completed between $from and $to had a recorded remake (" . round($remade / $completed * 100, 1) . "%); " . (int)($er['events'] ?? 0) . " remake event(s) were initiated in the period"
                    : (int)($er['events'] ?? 0) . " remake event(s) were initiated between $from and $to; no cases completed in the range, so no rate can be calculated",
                'definition' => 'Remake rate = cases completed in the range that have at least one recorded remake, divided by all cases completed in the range. A case with multiple remakes counts once. Archived cases are included as history.',
            ];
        }
    }

    return ['error' => 'invalid_metric', 'allowed' => $allowed];
}
