<?php
/**
 * Integration test: get-analytics.php must return valid JSON and include the
 * expected top-level payload structure for Practice Insights rendering.
 */

$base = __DIR__ . '/..';

// Capture the API response with a controlled authenticated session.
$_COOKIE['PHPSESSID'] = 'dt-analytics-api-test';
require_once "{$base}/api/appConfig.php";

$_SESSION['db_user_id'] = 21;
$_SESSION['user'] = [
    'id' => 21,
    'name' => 'Test Admin',
    'email' => 'pacoletstudios@gmail.com',
    'picture' => ''
];
$_SESSION['current_practice_id'] = 9;
$_SESSION['last_activity'] = time();
$_SESSION['last_regeneration'] = time();

ob_start();
$_GET = [
    'team_period' => '12',
    'team_filter' => 'both',
    'volume_period' => '13',
    'status_period' => 'active',
    'type_period' => 'active',
    'duration_period' => 'active'
];
require "{$base}/api/get-analytics.php";
$output = ob_get_clean();

$passed = 0;
$failed = 0;

function assertResult(string $name, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}\n";
    }
}

$json = json_decode($output, true);
assertResult('Response is valid JSON', $json !== null && json_last_error() === JSON_ERROR_NONE);
assertResult('Response contains success=true', is_array($json) && ($json['success'] ?? false) === true);
assertResult('Response contains data.metrics', is_array($json) && isset($json['data']['metrics']));
assertResult('Response contains data.charts', is_array($json) && isset($json['data']['charts']));
assertResult('Response does not contain PHP fatal HTML', stripos($output, '<br />') === false && stripos($output, 'Fatal error') === false);

// Clean up the test session row.
@session_write_close();
if (defined('ENCRYPTION_KEY')) {
    $hash = hash_hmac('sha256', 'dt-analytics-api-test', ENCRYPTION_KEY);
    $pdo->prepare("DELETE FROM php_sessions WHERE session_hash = ?")->execute([$hash]);
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
