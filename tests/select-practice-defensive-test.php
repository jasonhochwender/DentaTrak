<?php
namespace SelectPracticeDefensiveTest;

if (PHP_SAPI !== 'cli') {
    exit;
}

function header($value) { $GLOBALS['capturedHeaders'][] = $value; }
function session_status() { return PHP_SESSION_ACTIVE; }
function file_get_contents($path) {
    return $path === 'php://input' ? json_encode($GLOBALS['scenario']['json'] ?? []) : \file_get_contents($path);
}
function t($key) { return $key; }
function userLog($message, $error = false) {}
function error_log(...$args) {}
function ensureUserPreferencesSchema() { $GLOBALS['events'][] = 'schema'; }
function resolveLocale($unused, $userId, $practiceId) { return 'en-US'; }
function setResolvedLocale($locale) {
    $GLOBALS['events'][] = 'locale';
    $_SESSION['locale'] = $locale;
}

class FakeDatabase {
    public function prepare($sql) {
        $GLOBALS['queries'][] = $sql;
        if (!empty($GLOBALS['scenario']['query_failure'])) {
            throw new \PDOException('SQLSTATE private database detail');
        }
        return new FakeStatement(strpos($sql, 'INSERT INTO user_preferences') !== false);
    }
}

class FakeStatement {
    private $saving;
    public function __construct($saving) { $this->saving = $saving; }
    public function execute($params) {
        if ($this->saving) {
            $GLOBALS['events'][] = 'save';
            if ($_SESSION !== $GLOBALS['initialSession']) {
                throw new \RuntimeException('Session changed before preference save');
            }
            if (($GLOBALS['scenario']['save_failure'] ?? '') === 'throw') {
                throw new \PDOException('SQLSTATE private preference detail');
            }
            if (($GLOBALS['scenario']['save_failure'] ?? '') === 'false') {
                return false;
            }
        }
        return true;
    }
    public function fetch($mode) {
        if (!empty($GLOBALS['scenario']['denied'])) {
            return false;
        }
        return ['id' => 42, 'uuid' => 'practice-uuid', 'practice_name' => 'Selected practice',
            'organization_type' => 'dental_practice', 'baa_accepted' => 1,
            'role' => 'member', 'is_owner' => 0, 'limited_visibility' => 1,
            'can_view_analytics' => 0, 'can_edit_cases' => 0, 'is_lab' => 1];
    }
}

if (($argv[1] ?? '') === 'worker') {
    $scenario = json_decode(base64_decode($argv[2]), true);
    $_SERVER = ['REQUEST_METHOD' => $scenario['method'] ?? 'POST',
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if (!empty($scenario['header_token'])) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $scenario['header_token'];
    }
    $_GET = $scenario['get'] ?? [];
    $_POST = $scenario['post'] ?? [];
    $_SESSION = ['db_user_id' => 7, 'csrf_token' => 'test-token', 'current_practice_id' => 9,
        'practice_name' => 'Old practice', 'practice_role' => 'admin', 'practice_is_owner' => true,
        'practice_permissions' => ['can_edit_cases' => true], 'locale' => 'old',
        'cases_cache' => [1], 'practice_users_cache' => [2], 'practice_settings_cache' => [3],
        'needs_practice_selection' => true];
    if (!empty($scenario['unauthenticated'])) {
        unset($_SESSION['db_user_id']);
    }
    $initialSession = $_SESSION;
    $events = $queries = $capturedHeaders = [];
    $pdo = new FakeDatabase();
    ob_start();
    register_shutdown_function(function () {
        $body = ob_get_clean();
        echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body,
            'json' => json_decode($body, true), 'session' => $_SESSION,
            'unchanged' => $_SESSION === $GLOBALS['initialSession'],
            'headers' => $GLOBALS['capturedHeaders'], 'events' => $GLOBALS['events'],
            'queries' => $GLOBALS['queries']]);
    });
    $csrf = \file_get_contents(__DIR__ . '/../api/csrf.php');
    eval('namespace SelectPracticeDefensiveTest;' . substr($csrf, 5));
    $selectSource = \file_get_contents(__DIR__ . '/../api/select-practice.php');
    foreach (['appConfig.php', 'user-manager.php', 'csrf.php', 'practice-security.php'] as $dependency) {
        $selectSource = str_replace("require_once __DIR__ . '/{$dependency}';", '', $selectSource);
    }
    $practiceSecurity = \file_get_contents(__DIR__ . '/../api/practice-security.php');
    $practiceSecurity = str_replace("require_once __DIR__ . '/appConfig.php';", '', $practiceSecurity);
    $source = substr($practiceSecurity, 5) . "\n" . substr($selectSource, 5);
    eval('namespace SelectPracticeDefensiveTest; use PDO; use PDOException;' . $source);
    exit;
}

$passed = 0;
function check($condition, $name) {
    if (!$condition) {
        throw new \RuntimeException('FAIL: ' . $name);
    }
    $GLOBALS['passed']++;
    echo "PASS: {$name}\n";
}
function run($scenario) {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($scenario))],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $error !== '' || !is_array($result = json_decode($out, true))) {
        throw new \RuntimeException("Worker failed: {$error} {$out}");
    }
    return $result;
}

$valid = ['json' => ['practice_id' => 42], 'header_token' => 'test-token'];
foreach ([['unauthenticated' => true], ['header_token' => ''], ['header_token' => 'wrong']] as $case) {
    $result = run(array_replace($valid, $case));
    check($result['status'] === (!empty($case['unauthenticated']) ? 401 : 403) && $result['unchanged'] && !$result['queries'], 'Authentication/CSRF rejected before DB and session writes');
}
foreach ([0, -1, 'abc', '42abc', '1.2', 1.2, true, [], '99999999999999999999999999999'] as $id) {
    $result = run(array_replace($valid, ['json' => ['practice_id' => $id]]));
    check($result['status'] === 400 && $result['unchanged'] && !$result['queries'], 'Invalid practice ID rejected: ' . json_encode($id));
}
foreach ([['method' => 'GET', 'get' => ['practice_id' => 42, 'remember' => '1']], ['method' => 'PUT']] as $case) {
    $result = run(array_replace($valid, $case));
    check($result['status'] === 405 && $result['unchanged'] && !$result['queries'] && $result['json']['preference_saved'] === false, 'Non-POST default write rejected');
}
$result = run(array_replace($valid, ['denied' => true]));
check($result['status'] === 403 && $result['unchanged'] && !$result['events'], 'Denied membership cannot open or save practice');
$result = run($valid);
$query = $result['queries'][0];
foreach (['FROM practice_users pu', 'JOIN practices p ON p.id = pu.practice_id', 'JOIN users u ON u.id = pu.user_id', 'pu.practice_id = :practice_id AND pu.user_id = :user_id', 'u.is_active = 1', '(p.is_active = 1 OR p.is_active IS NULL)'] as $fragment) {
    check(strpos($query, $fragment) !== false, 'Membership query includes ' . $fragment);
}
check(strpos($query, 'pu.is_active') === false, 'No unsupported membership is_active column');
check($result['session']['practice_permissions'] === ['limited_visibility' => true, 'can_view_analytics' => false, 'can_edit_cases' => false, 'is_lab' => true] && $result['session']['practice_role'] === 'member' && $result['session']['practice_is_owner'] === false, 'New practice permissions replace old privileges');
check(!isset($result['session']['cases_cache'], $result['session']['practice_users_cache'], $result['session']['practice_settings_cache']), 'Old practice caches cleared');
check($result['json']['preference_saved'] === false && $result['events'] === ['locale'], 'Session-only selection does not save a default');
foreach (['false', '0', false, 'off', 'no'] as $remember) {
    $result = run(array_replace($valid, ['json' => ['practice_id' => 42, 'remember_preference' => $remember]]));
    check($result['json']['preference_saved'] === false && $result['events'] === ['locale'], 'False remember parsed correctly: ' . json_encode($remember));
}
foreach (['header', 'json', 'form'] as $tokenSource) {
    $case = ['json' => ['practice_id' => 42, 'remember_preference' => true]];
    if ($tokenSource === 'header') $case['header_token'] = 'test-token';
    if ($tokenSource === 'json') $case['json']['csrf_token'] = 'test-token';
    if ($tokenSource === 'form') $case = ['post' => ['practice_id' => '42', 'remember_preference' => '1', 'csrf_token' => 'test-token']];
    $result = run($case);
    check($result['json']['success'] === true && $result['json']['preference_saved'] === true && $result['events'] === ['schema', 'save', 'locale'], 'Default saved before session mutation with ' . $tokenSource . ' CSRF');
}
foreach (['throw', 'false'] as $failure) {
    $result = run(array_replace($valid, ['json' => ['practice_id' => 42, 'remember_preference' => true], 'save_failure' => $failure, 'get' => ['redirect' => 1, 'billing' => 1]]));
    check($result['status'] === 500 && $result['unchanged'] && $result['json']['success'] === false && $result['json']['preference_saved'] === false && !preg_match('/SQLSTATE|private/', $result['body']) && count($result['headers']) === 1, 'Save failure leaves session unchanged and returns safe JSON without redirect: ' . $failure);
}
$result = run(array_replace($valid, ['query_failure' => true]));
check($result['status'] === 500 && $result['unchanged'] && strpos($result['body'], 'SQLSTATE') === false, 'Membership PDO error is not exposed');
foreach ([[], ['redirect' => '1'], ['billing' => '1', 'redirect' => '1'], ['remember' => 'false']] as $query) {
    $result = run(['method' => 'GET', 'get' => array_merge(['practice_id' => '42'], $query)]);
    $target = '../main.php' . (isset($query['billing']) ? '?billing=1' : '');
    check(in_array('Location: ' . $target, $result['headers'], true) && $result['session']['current_practice_id'] === 42 && $result['events'] === ['locale'], 'Legacy GET redirect preserved: ' . json_encode($query));
}
echo "{$passed} checks passed; no application bootstrap, database, or persistent sessions used.\n";
