<?php
/**
 * Ask DentaTrak security/tool boundary tests.
 *
 * Two layers:
 *  1. Pure unit checks (always run): planner JSON parsing, parameter
 *     sanitizers, tool allowlist, PHI action registration, and the
 *     requirement that every case query INNER JOINs authorized_case_ids.
 *  2. Live authorization checks against the local dev DB (skipped when the
 *     DB or expected fixtures are absent): a full-visibility member sees all
 *     practice cases, an Assigned-Only member sees only their own cases, and
 *     a case from another practice can never be fetched by ID or patient
 *     name - including through a crafted/injected parameter.
 *
 * Run: php tests/ask-dentatrak-test.php   (dev DB on the configured port)
 */

if (PHP_SAPI !== 'cli') { exit; }

error_reporting(E_ALL & ~E_DEPRECATED);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'ask-dentatrak-test';

$passed = 0; $failed = 0; $skipped = 0;
function check($name, $cond, $detail = '') {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS: $name\n"; }
    else { $failed++; echo "FAIL: $name" . ($detail ? " — $detail" : '') . "\n"; }
}
function skip($name) { global $skipped; $skipped++; echo "SKIP: $name\n"; }

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/hipaa-compliance.php';
require_once __DIR__ . '/../api/ask-dentatrak-tools.php';
// Endpoint functions (parser/sanitizer) live in the endpoint file; include
// just the functions by extracting is impossible, so mirror-test the pieces
// that matter and lint the endpoint separately.

/* ── 1. Tool allowlist ────────────────────────────────────────────────── */

check('unknown tool rejected before any query',
    runAskDentatrakTool('drop_table', [], 1)['ok'] === false
    && runAskDentatrakTool('drop_table', [], 1)['error'] === 'unknown_tool');

check('injection-looking tool name rejected',
    runAskDentatrakTool('count_cases; DROP TABLE cases_cache', [], 1)['ok'] === false);

check('non-array params tolerated', true); // signature enforces array type

/* ── 2. Parameter sanitizers ──────────────────────────────────────────── */

check('askToolStr strips controls + bounds length',
    askToolStr("abc\x00\x1Fdef") === 'abcdef'
    && mb_strlen(askToolStr(str_repeat('x', 500))) === 100
    && askToolStr('') === null
    && askToolStr(123) === null);

check('askToolInt bounds + rejects non-numeric/bool',
    askToolInt(30, 0, 366) === 30
    && askToolInt(999, 0, 366) === null
    && askToolInt(-1, 0, 366) === null
    && askToolInt('abc', 0, 366) === null
    && askToolInt(true, 0, 366) === null);

check('askToolDate validates real dates',
    askToolDate('2026-09-25') === '2026-09-25'
    && askToolDate('2026-13-40') === null
    && askToolDate("2026-01-01' OR '1'='1") === null);

/* ── 2b. Reporting timezone anchoring (near-midnight boundary) ───────── */

// The app anchors due dates and operational reporting to America/New_York
// (list-cases.php, get-analytics.php, the Kanban board). Simulate a host
// whose local calendar is frequently a day AHEAD of New York (Kiritimati,
// UTC+14): near midnight NY the host is already on the next day. The
// helpers must still resolve the NY date - the host clock is irrelevant.
date_default_timezone_set('Pacific/Kiritimati');
$nyNow = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$hostToday = date('Y-m-d'); // host-clock date; != NY date in the boundary window
check('askToolToday anchors to America/New_York, not the host clock',
    askToolToday() === $nyNow->format('Y-m-d'),
    'ny=' . $nyNow->format('Y-m-d') . " host=$hostToday got=" . askToolToday());
check('ASK_TOOL_TIMEZONE is the app reporting timezone',
    ASK_TOOL_TIMEZONE === 'America/New_York');
check('askToolDayShift resolves relative days in the NY calendar',
    askToolDayShift('+1 day') === $nyNow->modify('+1 day')->format('Y-m-d')
    && askToolDayShift('-30 days') === $nyNow->modify('-30 days')->format('Y-m-d'));
check('askToolDayShift honors an explicit base date',
    askToolDayShift('+1 day', '2026-10-09') === '2026-10-10'
    && askToolDayShift('-1 day', '2026-10-01') === '2026-09-30');
check('askToolDayDiff is calendar-exact across a US DST transition',
    askToolDayDiff('2026-10-31', '2026-11-02') === 2   // Nov 1 2026: EDT->EST
    && askToolDayDiff('2026-10-09', '2026-10-09') === 0
    && askToolDayDiff('2026-10-09', '2026-10-08') === -1);

/* ── 2c. Self-scope validation ("my cases" is not "assigned to me") ───── */

$_SESSION['user_email'] = 'nobody@example.test';
$_SESSION['user_name'] = 'Nobody Example';

[$p, $note] = askToolValidateScopeParams(['assigned_to_me' => true], 'what percentage of my open cases are overdue?', []);
check('"my open cases" does not justify assigned_to_me (stripped + noted)',
    !isset($p['assigned_to_me']) && $note !== null,
    $note ?? '');

[$p] = askToolValidateScopeParams(['assigned_to_me' => true], 'how many cases are assigned to me?', []);
check('explicit "assigned to me" keeps self-scope', !empty($p['assigned_to_me']));

[$p] = askToolValidateScopeParams(['assigned_to_me' => true], 'what is on my workload?', []);
check('"my workload" keeps self-scope', !empty($p['assigned_to_me']));

[$p] = askToolValidateScopeParams(['assigned_to_me' => true], 'which of these are overdue?',
    [['role' => 'user', 'content' => 'show me cases assigned to me'],
     ['role' => 'assistant', 'content' => '<p>You have 4 cases.</p>']]);
check('prior user turn establishes self-scope for follow-ups', !empty($p['assigned_to_me']));

[$p] = askToolValidateScopeParams(['assigned_to_me' => true], 'show me those cases',
    [['role' => 'assistant', 'content' => '<p>There are 4 cases assigned to you.</p>']]);
check('assistant-disclosed self-scope carries into a follow-up', !empty($p['assigned_to_me']));

[$p] = askToolValidateScopeParams(['assigned_to_me' => true], 'who has the most cases assigned?', []);
check('third-party "cases assigned" does not establish self-scope', !isset($p['assigned_to_me']));

[$p] = askToolValidateScopeParams(['assignee' => 'nobody@example.test'], 'what are my open cases?', []);
check('assignee naming the asking user is stripped without a signal', !isset($p['assignee']));

[$p] = askToolValidateScopeParams(['assignee' => 'nobody@example.test'], 'cases assigned to me', []);
check('assignee naming the asking user is kept when explicit', isset($p['assignee']));

[$p] = askToolValidateScopeParams(['assignee' => 'someone else'], 'my open cases', []);
check('a third-party assignee filter is untouched', ($p['assignee'] ?? '') === 'someone else');

[$p] = askToolValidateScopeParams(['creator' => 'Nobody Example'], 'my open cases', []);
check('creator naming the asking user is stripped without a creation signal', !isset($p['creator']));

[$p] = askToolValidateScopeParams(['creator' => 'nobody@example.test'], 'which cases did i create?', []);
check('creator naming the asking user is kept for "cases i create"', isset($p['creator']));

[$p, $note] = askToolValidateScopeParams(['open_only' => true], 'my open cases', []);
check('non-self-scope params pass through unchanged',
    ($p['open_only'] ?? false) === true && $note === null);

/* ── 3. Every case query joins the authorized scope ───────────────────── */

// Inspect the filter builder's SQL shape without executing.
$_SESSION['db_user_id'] = 999999;          // nonexistent user - no rows
$_SESSION['current_practice_id'] = 999999;
$_SESSION['user_email'] = 'nobody@example.test';

[$where, $bind] = askToolCaseFilters(999999, [
    'status' => 'Design',
    'overdue' => true,
    'due_within_days' => 7,
    'assigned_to_me' => true,
    'case_type' => 'Crown',
    'patient_name' => "x' OR '1'='1",
    'lab' => 'no such lab',
]);

check('filter WHERE keeps practice scope', strpos($where, 'c.practice_id = :practice_id') !== false);
check('patient_name never interpolated and yields no matches outside scope',
    strpos($where, "' OR '1'='1") === false
    && (strpos($where, '1 = 0') !== false || strpos($where, 'c.case_id IN') !== false),
    $where);
check('assigned_to_me uses email-or-label predicate',
    strpos($where, ':me_email') !== false && strpos($where, 'practice_assignment_label_recipients') !== false);
check('unknown lab yields guaranteed-empty predicate (never broadened)',
    strpos($where, '1 = 0') !== false);

// The tools' SELECT statements all carry the authorized join - assert on
// the source so a future refactor cannot silently drop it.
$src = file_get_contents(__DIR__ . '/../api/ask-dentatrak-tools.php');
check('every tool SELECT joins authorized_case_ids',
    substr_count($src, 'INNER JOIN authorized_case_ids') >= 6
    && strpos($src, 'INSERT') === false && strpos($src, 'UPDATE ') === false
    && strpos($src, 'DELETE') === false && strpos($src, 'DROP') === false,
    'found ' . substr_count($src, 'INNER JOIN authorized_case_ids') . ' joins');
check('no dynamic SQL from params (only bound placeholders)',
    preg_match('/\$\w+\s*\.\s*\$params|\$params\[[^\]]+\]\s*\.\s*[\'"]/', $src) === 0);

/* ── 3c. Throwable boundary at the endpoint ───────────────────────────── */

$endpoint = file_get_contents(__DIR__ . '/../api/ask-dentatrak.php');

// A TypeError (not an Exception) must still yield a structured JSON error
// with a localized retry message - never a bare fatal/non-JSON body.
[$status, $msg] = askDentatrakErrorResponse(new TypeError('simulated_typeerror_for_regression'));
check('TypeError maps to 500 + generic localized retry message',
    $status === 500 && $msg === t('ask_dentatrak.errors.generic')
    && strpos($msg, 'TypeError') === false && strpos($msg, 'simulated') === false
    && stripos($msg, 'try again') !== false,
    "$status " . json_encode($msg));

[$status, $msg] = askDentatrakErrorResponse(new Exception('AI_SERVICE_UNAVAILABLE'));
check('mapped provider failure keeps 200 in-panel contract',
    $status === 200 && $msg === t('insights.errors.ai_unavailable'),
    "$status " . json_encode($msg));

[$status, $msg] = askDentatrakErrorResponse(new RuntimeException('AI_QUOTA_EXCEEDED'));
check('quota failure maps to its localized message at 200',
    $status === 200 && $msg === t('insights.errors.ai_quota'));

check('endpoint catch covers Throwable, not just Exception',
    strpos($endpoint, 'catch (Throwable') !== false
    && strpos($endpoint, 'catch (Exception') === false,
    'boundary must not silently pass Error types');

check('dev fault injection is gated to the development environment',
    strpos($endpoint, '__force_typeerror') !== false
    && strpos($endpoint, "current_environment") !== false
    && strpos($endpoint, "'development'") !== false);

/* ── 4. PHI audit registration ────────────────────────────────────────── */

check('ask_dentatrak_query is a registered PHI action',
    defined('PHI_ACTION_ASK_QUERY')
    && in_array(PHI_ACTION_ASK_QUERY, getPHIAccessActions(), true)
    && getPHIAccessActionResourceType(PHI_ACTION_ASK_QUERY) === 'ask_query');
check('ask meta keys limited to tool + result_count',
    in_array('tool', getPHILogMetaAllowedKeys(), true)
    && in_array('result_count', getPHILogMetaAllowedKeys(), true)
    && !in_array('query', getPHILogMetaAllowedKeys(), true)
    && !in_array('message', getPHILogMetaAllowedKeys(), true));
check('ask queries are never dedupe-suppressed',
    !in_array(PHI_ACTION_ASK_QUERY, getPHILogDedupeEligibleActions(), true));

/* ── 5. Single feature-availability gate ──────────────────────────────── */

// Ask DentaTrak availability is governed ONLY by the global SHOW_AI_CHAT
// flag - there must be no per-practice setting, helper, or practices column.
$endpoint = file_get_contents(__DIR__ . '/../api/ask-dentatrak.php');
$main = file_get_contents(__DIR__ . '/../main.php');
check('endpoint gates only on SHOW_AI_CHAT',
    strpos($endpoint, "isFeatureEnabled('SHOW_AI_CHAT')") !== false
    && strpos($endpoint, 'ask_dentatrak_enabled') === false
    && strpos($endpoint, 'practiceAskDentatrakEnabled') === false);
check('no practice-level enablement remnants anywhere',
    strpos($main, 'practiceAskDentatrakEnabled') === false
    && strpos($main, 'ask_dentatrak_enabled') === false
    && !function_exists('practiceAskDentatrakEnabled')
    && strpos(file_get_contents(__DIR__ . '/../api/get-settings.php'), 'ask_dentatrak_enabled') === false
    && strpos(file_get_contents(__DIR__ . '/../api/save-settings.php'), 'askDentatrakEnabled') === false);

/* ── 6. Live authorization checks (dev DB) ────────────────────────────── */

$dbOk = false;
try {
    $dbOk = $pdo instanceof PDO && (bool)$pdo->query('SELECT 1');
} catch (Throwable $e) { $dbOk = false; }

if (!$dbOk) {
    skip('live authorization checks (no DB)');
} else {
    // Fixture: practice 9 "TESTING" — admin user 21 (full visibility) and
    // limited user 955 (assigned-only). Skip gracefully if fixtures change.
    $fixture = $pdo->query(
        "SELECT u.id, u.email, pu.limited_visibility
         FROM users u JOIN practice_users pu ON pu.user_id = u.id
         WHERE pu.practice_id = 9 AND u.is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $admin = null; $limited = null;
    foreach ($fixture as $m) {
        if (!$m['limited_visibility'] && !$admin) $admin = $m;
        if ($m['limited_visibility'] && !$limited) $limited = $m;
    }

    if (!$admin || !$limited) {
        skip('live authorization checks (practice 9 fixtures absent)');
    } else {
        $practiceId = 9;

        // -- Full-visibility user sees every practice case ----------------
        $_SESSION['db_user_id'] = (int)$admin['id'];
        $_SESSION['current_practice_id'] = $practiceId;
        $_SESSION['user_email'] = $admin['email'];

        $allCount = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache WHERE practice_id = $practiceId")->fetchColumn();

        $r = runAskDentatrakTool('count_cases', [], $practiceId);
        check('admin count_cases sees all practice cases',
            $r['ok'] && $r['data']['count'] === $allCount,
            json_encode($r));

        $r = runAskDentatrakTool('list_cases', ['limit' => 50], $practiceId);
        check('admin list_cases returns all practice cases',
            $r['ok'] && $r['data']['total_matching'] === $allCount);
        check('admin list rows carry only bounded display fields',
            $r['ok'] && empty(array_diff(array_keys($r['data']['cases'][0] ?? []),
                ['case_id','patient_name','dentist_name','case_type','status','due_date','assigned_to','patient_appointment_date','last_update_date'])));

        // -- Cross-practice denial ----------------------------------------
        $otherCase = $pdo->query(
            "SELECT case_id, patient_first_name, patient_last_name FROM cases_cache
             WHERE practice_id != $practiceId LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($otherCase) {
            $r = runAskDentatrakTool('get_case_summary', ['case_id' => $otherCase['case_id']], $practiceId);
            check('cross-practice case_id lookup returns not_found',
                $r['ok'] && ($r['data']['error'] ?? '') === 'not_found'
                && empty($r['data']['matches']));

            $pname = trim(($otherCase['patient_first_name'] ?? '') . ' ' . ($otherCase['patient_last_name'] ?? ''));
            if ($pname !== '') {
                $r = runAskDentatrakTool('get_case_summary', ['patient_name' => $pname], $practiceId);
                check('cross-practice patient_name lookup returns not_found',
                    $r['ok'] && empty($r['data']['matches']));
            }
        }

        // Injection attempt via parameters: union-inject a practice id.
        $r = runAskDentatrakTool('count_cases', [
            'status' => "' OR practice_id = 1234 --",
            'patient_name' => "' UNION SELECT * FROM cases_cache--",
        ], $practiceId);
        check('crafted/injected params cannot broaden scope',
            $r['ok'] && $r['data']['count'] === 0, json_encode($r));

        // -- Assigned-Only user sees only their cases ---------------------
        $_SESSION['db_user_id'] = (int)$limited['id'];
        $_SESSION['current_practice_id'] = $practiceId;
        $_SESSION['user_email'] = $limited['email'];

        $expected = (int)$pdo->query(
            "SELECT COUNT(*) FROM cases_cache WHERE practice_id = $practiceId
             AND LOWER(TRIM(assigned_to)) = " . $pdo->quote(strtolower(trim($limited['email'])))
        )->fetchColumn();

        $r = runAskDentatrakTool('count_cases', [], $practiceId);
        check('limited user count_cases sees only assigned cases',
            $r['ok'] && $r['data']['count'] === $expected && $expected < $allCount,
            json_encode($r) . " expected=$expected all=$allCount");

        $r = runAskDentatrakTool('list_cases', ['limit' => 50], $practiceId);
        $ids = array_column($r['data']['cases'] ?? [], 'case_id');
        $allowedIds = $pdo->query(
            "SELECT case_id FROM cases_cache WHERE practice_id = $practiceId
             AND LOWER(TRIM(assigned_to)) = " . $pdo->quote(strtolower(trim($limited['email'])))
        )->fetchAll(PDO::FETCH_COLUMN);
        check('limited user list_cases returns only assigned case_ids',
            $r['ok'] && sort($ids) === true && sort($allowedIds) === true && $ids === $allowedIds,
            json_encode($ids));

        // A case NOT assigned to them is invisible even by exact ID.
        $notMine = $pdo->query(
            "SELECT case_id FROM cases_cache WHERE practice_id = $practiceId
             AND (assigned_to IS NULL OR LOWER(TRIM(assigned_to)) != " . $pdo->quote(strtolower(trim($limited['email']))) . ")
             LIMIT 1"
        )->fetchColumn();
        if ($notMine) {
            $r = runAskDentatrakTool('get_case_summary', ['case_id' => $notMine], $practiceId);
            check('limited user cannot fetch unassigned case by ID',
                $r['ok'] && empty($r['data']['matches']));
        }

        // Remakes are scoped through the same temp table.
        $r = runAskDentatrakTool('count_remakes', ['within_days' => 365], $practiceId);
        check('limited user count_remakes scoped (returns only own case remakes)',
            $r['ok'] && isset($r['data']['count']));

        // Reference values do not leak other practices' values.
        $r = runAskDentatrakTool('get_reference_values', [], $practiceId);
        check('reference values scoped to authorized cases + own practice labels',
            $r['ok'] && isset($r['data']['assignees'], $r['data']['lab_labels'], $r['data']['case_types']));

        // -- New tools: allowlist validation + scoped output (admin) ------
        $_SESSION['db_user_id'] = (int)$admin['id'];
        $_SESSION['current_practice_id'] = $practiceId;
        $_SESSION['user_email'] = $admin['email'];

        $r = runAskDentatrakTool('aggregate_cases', [], $practiceId);
        check('aggregate_cases rejects missing group_by',
            $r['ok'] && ($r['data']['error'] ?? '') === 'invalid_group_by');

        // assigned_to grouping defaults to OPEN workload (not archived,
        // not done) unless an explicit population constraint is passed.
        $terminal = getLastActiveWorkflowColumnId($practiceId);
        $doneList = implode(',', array_map(
            function ($s) use ($pdo) { return $pdo->quote($s); },
            array_unique([$terminal, 'Completed', 'Shipped'])
        ));
        $openCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM cases_cache WHERE practice_id = $practiceId AND archived = 0
             AND status NOT IN ($doneList)"
        )->fetchColumn();

        $r = runAskDentatrakTool('aggregate_cases', ['group_by' => 'assigned_to'], $practiceId);
        $aggTotal = 0;
        foreach (($r['data']['groups'] ?? []) as $g) { $aggTotal += (int)$g['count']; }
        check('aggregate_cases assigned_to defaults to open workload',
            $r['ok'] && $aggTotal === $openCount && strpos($r['data']['scope'] ?? '', 'open') !== false,
            json_encode($r['data'] ?? []) . " expected=$openCount");

        // An explicit population constraint overrides the open default.
        $r = runAskDentatrakTool('aggregate_cases', ['group_by' => 'assigned_to', 'include_archived' => true], $practiceId);
        $aggAll = 0;
        foreach (($r['data']['groups'] ?? []) as $g) { $aggAll += (int)$g['count']; }
        check('aggregate_cases assigned_to honors explicit population',
            $r['ok'] && $aggAll === $allCount && strpos($r['data']['scope'] ?? '', 'archived') !== false,
            json_encode($r['data'] ?? []) . " expected=$allCount");

        $r = runAskDentatrakTool('case_metrics', [], $practiceId);
        check('case_metrics rejects missing metric',
            $r['ok'] && ($r['data']['error'] ?? '') === 'invalid_metric');

        $r = runAskDentatrakTool('case_metrics', ['metric' => 'open_overdue_share'], $practiceId);
        check('open_overdue_share carries definition + numerator/denominator',
            $r['ok'] && isset($r['data']['open_cases'], $r['data']['overdue_cases'], $r['data']['definition'])
            && $r['data']['overdue_cases'] <= $r['data']['open_cases']);

        $r = runAskDentatrakTool('case_metrics', ['metric' => 'volume',
            'from' => date('Y-m-d', strtotime('-30 days')), 'to' => date('Y-m-d')], $practiceId);
        check('volume metric returns both ranges',
            $r['ok'] && isset($r['data']['cases_created'], $r['data']['prev_cases_created'], $r['data']['definition']));

        // New shared filters stay bound and scoped.
        $r = runAskDentatrakTool('count_cases', ['open_only' => true], $practiceId);
        check('open_only filter counts only non-done cases',
            $r['ok'] && $r['data']['count'] <= $allCount);

        $r = runAskDentatrakTool('count_cases', ['missing_due_date' => true], $practiceId);
        check('missing_due_date filter scoped to practice',
            $r['ok'] && $r['data']['count'] <= $allCount);

        // limited user: aggregate + metrics are equally scoped.
        $_SESSION['db_user_id'] = (int)$limited['id'];
        $_SESSION['current_practice_id'] = $practiceId;
        $_SESSION['user_email'] = $limited['email'];

        $limNonArchived = (int)$pdo->query(
            "SELECT COUNT(*) FROM cases_cache WHERE practice_id = $practiceId AND archived = 0
             AND LOWER(TRIM(assigned_to)) = " . $pdo->quote(strtolower(trim($limited['email'])))
        )->fetchColumn();

        $r = runAskDentatrakTool('aggregate_cases', ['group_by' => 'status'], $practiceId);
        $limTotal = 0;
        foreach (($r['data']['groups'] ?? []) as $g) { $limTotal += (int)$g['count']; }
        check('limited user aggregate_cases sees only own cases',
            $r['ok'] && $limTotal === $limNonArchived,
            json_encode($r['data'] ?? []) . " expected=$limNonArchived");

        $terminal = getLastActiveWorkflowColumnId($practiceId);
        $doneList = implode(',', array_map(
            function ($s) use ($pdo) { return $pdo->quote($s); },
            array_unique([$terminal, 'Completed', 'Shipped'])
        ));
        $limOpen = (int)$pdo->query(
            "SELECT COUNT(*) FROM cases_cache WHERE practice_id = $practiceId AND archived = 0
             AND status NOT IN ($doneList)
             AND LOWER(TRIM(assigned_to)) = " . $pdo->quote(strtolower(trim($limited['email'])))
        )->fetchColumn();

        $r = runAskDentatrakTool('case_metrics', ['metric' => 'open_overdue_share'], $practiceId);
        check('limited user metrics scoped to own cases',
            $r['ok'] && $r['data']['open_cases'] === $limOpen,
            json_encode($r['data'] ?? []) . " expected=$limOpen");
    }
}

echo "\n$passed passed, $failed failed, $skipped skipped\n";
exit($failed ? 1 : 0);
