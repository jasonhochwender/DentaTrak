<?php
/**
 * Verifies long-running endpoints release the per-session advisory lock
 * before streaming/GCS work, and that another request using the same
 * session can proceed during a simulated long download.
 */

declare(strict_types=1);

$base = __DIR__ . '/..';
$results = [];

// 1. Source-level check: Download All endpoints call session_write_close()
// before GCS metadata / ZIP streaming.
$download = file_get_contents($base . '/api/download-case-attachments-zip.php');
$results[] = 'Download endpoint contains session_write_close(): ' . (
    strpos($download, 'session_write_close()') !== false ? 'PASS' : 'FAIL'
);
$downloadStreamPos = strpos($download, '$zip = new ZipStream');
$writeClosePos = strpos($download, 'session_write_close()');
$results[] = 'Download lock released before ZipStream: ' . (
    $writeClosePos !== false && $writeClosePos < $downloadStreamPos ? 'PASS' : 'FAIL'
);

$preflight = file_get_contents($base . '/api/preflight-download-case-attachments-zip.php');
$results[] = 'Preflight endpoint contains session_write_close(): ' . (
    strpos($preflight, 'session_write_close()') !== false ? 'PASS' : 'FAIL'
);
$preflightGcsPos = strpos($preflight, '$bucket = getGcsBucket');
$writeClosePos2 = strpos($preflight, 'session_write_close()');
$results[] = 'Preflight lock released before GCS bucket: ' . (
    $writeClosePos2 !== false && $writeClosePos2 < $preflightGcsPos ? 'PASS' : 'FAIL'
);

$attach = file_get_contents($base . '/api/attachment-content.php');
$results[] = 'Attachment content endpoint contains session_write_close(): ' . (
    strpos($attach, 'session_write_close()') !== false ? 'PASS' : 'FAIL'
);

$print = file_get_contents($base . '/api/print-case.php');
$results[] = 'Print endpoint contains session_write_close(): ' . (
    strpos($print, 'session_write_close()') !== false ? 'PASS' : 'FAIL'
);

$export = file_get_contents($base . '/api/data-export.php');
$results[] = 'Data export endpoint contains session_write_close(): ' . (
    strpos($export, 'session_write_close()') !== false ? 'PASS' : 'FAIL'
);

// 2. Functional check: a child script simulates a long download by
// authenticating, calling session_write_close(), then sleeping. While it
// sleeps, a second request using the same session id must be able to start.
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

$pdo = new PDO($dsn, $user, $pass, $options);

require_once __DIR__ . '/../api/session-db-handler.php';
$sessionId = bin2hex(random_bytes(16));
$handler = new PdoSessionHandler($pdo);
$handler->open('', 'PHPSESSID');
$handler->write($sessionId, 'a:1:{s:10:"db_user_id";i:1;}');
$handler->close();

$longDownload = <<<'PHP'
<?php
$_COOKIE['PHPSESSID'] = $argv[1];
require 'C:\MAMP\htdocs\DentaTrak\api\appConfig.php';
$_SESSION['long_download'] = true;
session_write_close();
echo "lock_released\n";
@fflush(STDOUT);
sleep(2);
echo "done\n";
@fflush(STDOUT);
PHP;

$tmp = sys_get_temp_dir() . '/dentatrak_long_download.php';
file_put_contents($tmp, $longDownload);

$descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
$process = proc_open('php "' . $tmp . '" ' . $sessionId, $descriptors, $pipes);
if (!is_resource($process)) {
    $results[] = 'Simulated long download started: FAIL';
} else {
    $output = '';
    while (true) {
        $status = proc_get_status($process);
        $chunk = fgets($pipes[1]);
        if ($chunk !== false) {
            $output .= $chunk;
        }
        if (strpos($output, 'lock_released') !== false) {
            break;
        }
        if (!$status['running']) {
            break;
        }
        usleep(50000);
    }

    $results[] = 'Simulated long download started: ' . (
        strpos($output, 'lock_released') !== false ? 'PASS' : 'FAIL'
    );

    // Second request with the same session id must start immediately.
    $second = <<<'PHP'
<?php
$_COOKIE['PHPSESSID'] = $argv[1];
require 'C:\MAMP\htdocs\DentaTrak\api\appConfig.php';
echo 'second_ok=' . (session_status() === PHP_SESSION_ACTIVE ? 'yes' : 'no') . ' data=' . ($_SESSION['db_user_id'] ?? 'missing');
PHP;
    $tmp2 = sys_get_temp_dir() . '/dentatrak_second_request.php';
    file_put_contents($tmp2, $second);
    $secondOutput = shell_exec('php "' . $tmp2 . '" ' . $sessionId . ' 2>&1');
    $results[] = 'Second request proceeds during long download: ' . (
        strpos($secondOutput, 'second_ok=yes') !== false && strpos($secondOutput, 'data=1') !== false ? 'PASS' : 'FAIL'
    );

    // Clean up child.
    proc_terminate($process);
    proc_close($process);
    @unlink($tmp2);
}

@unlink($tmp);

header('Content-Type: text/plain');
echo implode("\n", $results);
