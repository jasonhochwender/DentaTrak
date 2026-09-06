<?php
namespace SwitchPracticeDefensiveTest;

if (PHP_SAPI !== 'cli') {
    exit;
}

function header($value) { $GLOBALS['capturedHeaders'][] = $value; }
function session_status() { return PHP_SESSION_ACTIVE; }
function file_get_contents($path) {
    return $path === 'php://input' ? json_encode($GLOBALS['scenario']['json'] ?? []) : \file_get_contents($path);
}
function t($key) { return $key; }
function error_log(...$args) {}
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
        return new FakeStatement();
    }
}

class FakeStatement {
    public function execute($params) {
        if (!empty($GLOBALS['scenario']['inactive_user'])) {
            return false;
        }
        return true;
    }
    public function fetch($mode) {
        if (!empty($GLOBALS['scenario']['denied']) || !empty($GLOBALS['scenario']['inactive_practice'])) {
            return false;
        }
        return [
            'id' => 42,
            'uuid' => 'practice-uuid',
            'practice_name' => 'Switched practice',
            'organization_type' => 'dental_practice',
            'baa_accepted' => 1,
            'role' => 'user',
            'is_owner' => 0,
            'limited_visibility' => 1,
            'can_view_analytics' => 0,
            'can_edit_cases' => 0,
            'is_lab' => 1
        ];
    }
}

if (($argv[1] ?? '') === 'worker') {
    $scenario = json_decode(base64_decode($argv[2]), true);
    $_SERVER = array_merge([
        'REQUEST_METHOD' => $scenario['method'] ?? 'POST',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json'
    ], $scenario['server'] ?? []);
    if (!empty($scenario['header_token'])) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $scenario['header_token'];
    }
    $_GET = $scenario['get'] ?? [];
    $_POST = $scenario['post'] ?? [];
    $_SESSION = [
        'db_user_id' => 7,
        'csrf_token' => 'test-token',
        'current_practice_id' => 9,
        'practice_name' => 'Old practice',
        'practice_role' => 'admin',
        'practice_is_owner' => true,
        'practice_permissions' => ['can_edit_cases' => true],
        'locale' => 'old',
        'cases_cache' => [1],
        'practice_users_cache' => [2],
        'practice_settings_cache' => [3]
    ];
    if (!empty($scenario['unauthenticated'])) {
        unset($_SESSION['db_user_id']);
    }
    $initialSession = $_SESSION;
    $events = $queries = $capturedHeaders = [];
    $pdo = new FakeDatabase();
    ob_start();
    register_shutdown_function(function () {
        $body = ob_get_clean();
        echo json_encode([
            'status' => http_response_code() ?: 200,
            'body' => $body,
            'json' => json_decode($body, true),
            'session' => $_SESSION,
            'unchanged' => $_SESSION === $GLOBALS['initialSession'],
            'headers' => $GLOBALS['capturedHeaders'],
            'events' => $GLOBALS['events'],
            'queries' => $GLOBALS['queries']
        ]);
    });

    $csrf = \file_get_contents(__DIR__ . '/../api/csrf.php');
    eval('namespace SwitchPracticeDefensiveTest;' . substr($csrf, 5));

    $practiceSecurity = \file_get_contents(__DIR__ . '/../api/practice-security.php');
    $practiceSecurity = str_replace("require_once __DIR__ . '/appConfig.php';", '', $practiceSecurity);

    $source = \file_get_contents(__DIR__ . '/../api/switch-practice.php');
    foreach (['appConfig.php', 'user-manager.php', 'csrf.php', 'practice-security.php'] as $dependency) {
        $source = str_replace("require_once __DIR__ . '/{$dependency}';", '', $source);
    }
    $source = substr($practiceSecurity, 5) . "\n" . substr($source, 5);
    eval('namespace SwitchPracticeDefensiveTest; use PDO; use PDOException;' . $source);
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

foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $result = run(array_replace($valid, ['method' => $method]));
    check($result['status'] === 405 && stripos($result['body'], '405') === false && $result['unchanged'] && !$result['queries'], "{$method} rejected with 405");
}

foreach ([['unauthenticated' => true], ['header_token' => ''], ['header_token' => 'wrong']] as $case) {
    $result = run(array_replace($valid, $case));
    $expected = !empty($case['unauthenticated']) ? 401 : 403;
    check($result['status'] === $expected && $result['unchanged'] && !$result['queries'], 'Authentication/CSRF rejected before DB and session writes');
}

foreach ([null, 0, -1, 'abc', '42abc', '1.2', 1.2, true, [], '99999999999999999999999999999'] as $id) {
    $result = run(array_replace($valid, ['json' => ['practice_id' => $id]]));
    check($result['status'] === 400 && $result['unchanged'] && !$result['queries'], 'Invalid practice ID rejected: ' . json_encode($id));
}

$result = run(array_replace($valid, ['denied' => true]));
check($result['status'] === 403 && $result['unchanged'], 'No membership returns 403 and leaves session unchanged');

$result = run(array_replace($valid, ['inactive_practice' => true]));
check($result['status'] === 403 && $result['unchanged'], 'Inactive practice returns 403 and leaves session unchanged');

$result = run(array_replace($valid, ['query_failure' => true]));
check($result['status'] === 500 && $result['unchanged'] && strpos($result['body'], 'SQLSTATE') === false, 'Membership query error is not exposed');

$result = run($valid);
foreach (['FROM practice_users pu', 'JOIN practices p ON p.id = pu.practice_id', 'JOIN users u ON u.id = pu.user_id', 'u.is_active = 1', '(p.is_active = 1 OR p.is_active IS NULL)'] as $fragment) {
    check(strpos($result['queries'][0], $fragment) !== false, 'Membership query includes ' . $fragment);
}
check(strpos($result['queries'][0], 'pu.is_active') === false, 'No unsupported membership is_active column');
check($result['session']['current_practice_id'] === 42, 'Session current_practice_id updated');
check($result['session']['practice_role'] === 'user', 'Session practice_role refreshed from DB');
check($result['session']['practice_is_owner'] === false, 'Session practice_is_owner refreshed from DB');
check($result['session']['practice_organization_type'] === 'dental_practice', 'Session practice_organization_type set');
check($result['session']['practice_permissions'] === ['limited_visibility' => true, 'can_view_analytics' => false, 'can_edit_cases' => false, 'is_lab' => true], 'Session practice_permissions include is_lab');
check(!isset($result['session']['cases_cache'], $result['session']['practice_users_cache'], $result['session']['practice_settings_cache']), 'Old practice caches cleared');
check($result['json']['success'] === true && $result['json']['practice']['id'] === 42 && $result['json']['practice']['name'] === 'Switched practice', 'Successful switch returns practice metadata');
check(strpos(implode('\n', $result['queries']), 'user_preferences') === false, 'Switch does not write default preference');

echo "{$passed} switch-practice checks passed; no application bootstrap, database, or persistent sessions used.\n";
