<?php
/**
 * Tests that a session-lock timeout returns a transient 503 and never appears
 * as a logout or timeout. Uses a separate process to hold the advisory lock.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$host = '127.0.0.1';
$port = 3308;
$db = getenv('DB_NAME') ?: 'dental_case_tracker';
$user = getenv('DB_USER_LOCAL') ?: 'root';
$pass = getenv('DB_PASSWORD_LOCAL') ?: 'root';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

putenv('DENTATRAK_SESSION_LOCK_TIMEOUT=5');

$pdo = new PDO($dsn, $user, $pass, $options);
require_once __DIR__ . '/../api/session-db-handler.php';

$results = [];

$sessionId = bin2hex(random_bytes(16));
$handler = new PdoSessionHandler($pdo);
$handler->open('', 'PHPSESSID');
$handler->write($sessionId, 'a:1:{s:4:"test";s:5:"value";}');
$handler->close();

$hash = PdoSessionHandler::hashId($sessionId);

$hash = PdoSessionHandler::hashId($sessionId);

// Hold the advisory lock from a separate connection.
$lockPdo = new PDO($dsn, $user, $pass, $options);
$lockPdo->prepare("SELECT GET_LOCK(:name, 5)")->execute(['name' => $hash]);

$childScript = <<<'PHP'
<?php
putenv('DENTATRAK_SESSION_LOCK_TIMEOUT=' . $argv[2]);
$_COOKIE['PHPSESSID'] = $argv[1];
try {
    require 'C:\MAMP\htdocs\DentaTrak\api\appConfig.php';
} catch (Throwable $e) {
    echo 'EXCEPTION:' . get_class($e) . ':' . $e->getMessage();
    exit;
}
if (http_response_code() === 503) {
    echo "503 lock-timeout";
    exit;
}
echo "data=" . ($_SESSION['test'] ?? 'missing');
PHP;

$tmp = sys_get_temp_dir() . '/dentatrak_lock_timeout_child.php';
file_put_contents($tmp, $childScript);

// While the lock is held, the child must not be able to start the session.
$output = shell_exec('php "' . $tmp . '" ' . $sessionId . ' 0 2>&1');
$results[] = 'Locked session returns transient 503: ' . (
    strpos($output, 'Session store temporarily unavailable') !== false
    || strpos($output, '503 lock-timeout') !== false
    ? 'PASS' : 'FAIL (' . trim($output) . ')'
);
$results[] = 'Locked session does not say timeout or logged out: ' . (
    strpos($output, 'timeout=1') === false
    && strpos($output, 'session_expired=1') === false
    && strpos($output, 'Authentication required') === false
    && strpos($output, 'missing') === false
    ? 'PASS' : 'FAIL'
);

// Release the lock and confirm the same session can now start normally.
$lockPdo->prepare("SELECT RELEASE_LOCK(:name)")->execute(['name' => $hash]);
$output = shell_exec('php "' . $tmp . '" ' . $sessionId . ' 5 2>&1');
$results[] = 'Released session reads successfully: ' . (
    strpos($output, 'data=value') !== false ? 'PASS' : 'FAIL (' . trim($output) . ')'
);

@unlink($tmp);

header('Content-Type: text/plain');
echo implode("\n", $results);
