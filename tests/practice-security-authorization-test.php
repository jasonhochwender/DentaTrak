<?php
/**
 * Defensive authorization helper tests for api/practice-security.php.
 *
 * Verifies that role/permission helpers fail closed unless the user, the
 * practice, and the membership are all active. Also confirms the legacy
 * nullable-practice rule: is_active = 1 and is_active = NULL are treated as
 * active, while is_active = 0 is denied.
 */
namespace PracticeSecurityAuthorizationTest;

if (PHP_SAPI !== 'cli') {
    exit;
}

function t($key) { return $key; }
function header($value) { $GLOBALS['capturedHeaders'][] = $value; }
function session_status() { return PHP_SESSION_ACTIVE; }
function session_start() { /* no-op for isolated test */ }
function error_log(...$args) { }

class FakeDatabase {
    public function prepare($sql) {
        $GLOBALS['queries'][] = $sql;
        if (!empty($GLOBALS['scenario']['query_failure'])) {
            throw new \PDOException('SQLSTATE[HY000]: private database detail');
        }
        return new FakeStatement($sql);
    }
}

class FakeStatement {
    private $sql;
    public function __construct($sql) { $this->sql = $sql; }
    public function execute($params = []) {
        $GLOBALS['executes'][] = ['sql' => $this->sql, 'params' => $params];
        if (!empty($GLOBALS['scenario']['query_failure'])) {
            throw new \PDOException('SQLSTATE[HY000]: private database detail');
        }
        return true;
    }
    private function allowed() {
        $s = $GLOBALS['scenario'];
        $membership = !empty($s['membership']);
        $activeUser = !empty($s['active_user']);
        $activePractice = true;
        if (array_key_exists('active_practice', $s)) {
            // false means is_active = 0; 'null' means legacy NULL; true means 1.
            $activePractice = $s['active_practice'] !== false;
        }
        return $membership && $activeUser && $activePractice;
    }
    public function fetchColumn() {
        if (!$this->allowed()) {
            return false;
        }
        $s = $GLOBALS['scenario'];
        if (strpos($this->sql, 'role') !== false && strpos($this->sql, 'SELECT') !== false) {
            return $s['role'] ?? 'user';
        }
        if (strpos($this->sql, 'is_owner') !== false) {
            return !empty($s['is_owner']) ? 1 : 0;
        }
        if (strpos($this->sql, 'SELECT 1') !== false) {
            return 1;
        }
        return false;
    }
    public function fetch($mode = null) {
        if (!$this->allowed()) {
            return false;
        }
        $s = $GLOBALS['scenario'];
        return [
            'role' => $s['role'] ?? 'user',
            'is_owner' => $s['is_owner'] ?? 0,
            'limited_visibility' => $s['limited_visibility'] ?? 0,
            'is_lab' => $s['is_lab'] ?? 0,
            'can_view_analytics' => $s['can_view_analytics'] ?? 1,
            'can_edit_cases' => $s['can_edit_cases'] ?? 1,
        ];
    }
}

$source = file_get_contents(__DIR__ . '/../api/practice-security.php');
$source = str_replace("require_once __DIR__ . '/appConfig.php';", '', $source);

$_SESSION = ['db_user_id' => 7, 'current_practice_id' => 42];
$pdo = new FakeDatabase();
$GLOBALS['scenario'] = [];
$GLOBALS['queries'] = [];
$GLOBALS['executes'] = [];
$GLOBALS['securityEvents'] = [];
$GLOBALS['capturedHeaders'] = [];

eval('namespace PracticeSecurityAuthorizationTest; use PDO; use PDOException;' . substr($source, 5));

$passed = 0;
function check($condition, $name) {
    if (!$condition) {
        throw new \RuntimeException('FAIL: ' . $name);
    }
    $GLOBALS['passed']++;
    echo "PASS: {$name}\n";
}

function resetScenario($scenario = []) {
    $GLOBALS['scenario'] = array_merge([
        'membership' => true,
        'active_user' => true,
        'active_practice' => true,
        'role' => 'admin',
        'is_owner' => 1,
        'can_view_analytics' => 1,
        'can_edit_cases' => 1,
        'limited_visibility' => 0,
        'is_lab' => 0,
    ], $scenario);
    $GLOBALS['queries'] = [];
    $GLOBALS['executes'] = [];
    $GLOBALS['securityEvents'] = [];
}

// Helper used by all functions must join users/practices and enforce active state.
function assertActiveStateSql() {
    $sql = implode("\n", $GLOBALS['queries']);
    check(strpos($sql, 'FROM practice_users pu') !== false, 'query uses practice_users alias');
    check(strpos($sql, 'JOIN practices p ON p.id = pu.practice_id') !== false, 'query joins practices');
    check(strpos($sql, 'JOIN users u ON u.id = pu.user_id') !== false, 'query joins users');
    check(strpos($sql, 'u.is_active = 1') !== false, 'query filters active users');
    check(strpos($sql, '(p.is_active = 1 OR p.is_active IS NULL)') !== false, 'query allows active or legacy-null practices');
    check(strpos($sql, 'pu.is_active') === false, 'query does not reference unsupported practice_users.is_active');
}

// 1. Active owner/admin can access.
resetScenario(['role' => 'admin', 'is_owner' => 1]);
check(isPracticeAdmin(42) === true, 'active admin is recognized as admin');
assertActiveStateSql();

resetScenario(['role' => 'admin']);
$permissions = getUserPracticePermissions(42);
check(is_array($permissions) && $permissions['is_admin'] === true, 'active admin permissions include is_admin');
check($permissions['is_owner'] === true, 'active admin permissions include is_owner when set');

// 2. Active non-admin user is not admin.
resetScenario(['role' => 'user', 'is_owner' => 0]);
check(isPracticeAdmin(42) === false, 'active non-admin user is not admin');
$permissions = getUserPracticePermissions(42);
check($permissions['is_admin'] === false, 'non-admin permissions have is_admin false');

// 3. Active user with analytics permission can view analytics.
resetScenario(['can_view_analytics' => 1]);
check(canViewAnalytics(42) === true, 'active member with can_view_analytics sees analytics');

// 4. Active user without analytics permission cannot.
resetScenario(['can_view_analytics' => 0]);
check(canViewAnalytics(42) === false, 'active member without can_view_analytics is denied');

// 5. Inactive user is denied across all helpers.
resetScenario(['active_user' => false, 'role' => 'admin', 'is_owner' => 1]);
check(isPracticeAdmin(42) === false, 'inactive user is not admin');
check(isPracticeOwner(42) === false, 'inactive user is not owner');
check(canViewAnalytics(42) === false, 'inactive user cannot view analytics');
check(getUserPracticePermissions(42) === null, 'inactive user has no permissions');
check(verifyPracticeAccess(42) === false, 'inactive user has no access');

// 6. Inactive practice (is_active = 0) is denied.
resetScenario(['active_practice' => false, 'role' => 'admin', 'is_owner' => 1]);
check(isPracticeAdmin(42) === false, 'inactive practice denies admin check');
check(isPracticeOwner(42) === false, 'inactive practice denies owner check');
check(canViewAnalytics(42) === false, 'inactive practice denies analytics');
check(getUserPracticePermissions(42) === null, 'inactive practice yields no permissions');
check(verifyPracticeAccess(42) === false, 'inactive practice denies access');

// 7. Legacy active practice (is_active = NULL) is allowed.
resetScenario(['active_practice' => 'null', 'role' => 'admin', 'is_owner' => 1]);
check(isPracticeAdmin(42) === true, 'legacy NULL-active practice allows admin check');
check(isPracticeOwner(42) === true, 'legacy NULL-active practice allows owner check');
check(canViewAnalytics(42) === true, 'legacy NULL-active practice allows analytics');
check(is_array(getUserPracticePermissions(42)), 'legacy NULL-active practice returns permissions');
check(verifyPracticeAccess(42) === true, 'legacy NULL-active practice allows access');

// 8. Missing membership is denied.
resetScenario(['membership' => false, 'role' => 'admin', 'is_owner' => 1]);
check(isPracticeAdmin(42) === false, 'missing membership denies admin check');
check(verifyPracticeAccess(42) === false, 'missing membership denies access');

// 9. No authenticated session is denied.
$originalSessionUser = $_SESSION['db_user_id'] ?? null;
unset($_SESSION['db_user_id']);
check(isPracticeAdmin(42) === false, 'no session denies admin check');
check(verifyPracticeAccess(42) === false, 'no session denies access');
$_SESSION['db_user_id'] = $originalSessionUser;

// 10. Database error is not exposed and returns closed (false/null).
resetScenario(['query_failure' => true, 'role' => 'admin', 'is_owner' => 1]);
check(isPracticeAdmin(42) === false, 'database error returns false for isPracticeAdmin');
check(getUserPracticePermissions(42) === null, 'database error returns null for permissions');

echo "{$passed} practice-security authorization checks passed.\n";
