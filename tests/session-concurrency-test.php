<?php
/**
 * Concurrency and locking tests for the PDO session handler.
 * Uses two separate database connections to simulate two Cloud Run instances.
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

$pdoA = new PDO($dsn, $user, $pass, $options);
$pdoB = new PDO($dsn, $user, $pass, $options);

require_once __DIR__ . '/../api/session-db-handler.php';

$results = [];
$hash = 'locktest_' . bin2hex(random_bytes(16));

// 1. MySQL advisory locks on separate connections block and serialize
$pdoA->prepare("SELECT GET_LOCK(:name, 5)")->execute(['name' => $hash]);
$start = microtime(true);
$stmt = $pdoB->prepare("SELECT GET_LOCK(:name, 1)");
$stmt->execute(['name' => $hash]);
$lockResult = $stmt->fetchColumn();
$elapsed = microtime(true) - $start;
$results[] = 'Advisory lock blocks second connection: ' . (
    ($lockResult == 0 || $lockResult === false || $lockResult === null) && $elapsed >= 0.9 ? 'PASS' : 'FAIL'
);

$pdoA->prepare("SELECT RELEASE_LOCK(:name)")->execute(['name' => $hash]);
$stmt = $pdoB->prepare("SELECT GET_LOCK(:name, 2)");
$stmt->execute(['name' => $hash]);
$lockResult2 = $stmt->fetchColumn();
$pdoB->prepare("SELECT RELEASE_LOCK(:name)")->execute(['name' => $hash]);
$results[] = 'Advisory lock acquired after first releases: ' . (
    ($lockResult2 == 1 || $lockResult2 === true) ? 'PASS' : 'FAIL'
);

// 2. Sequential writes through the handler do not lose updates
$sessionId = 'sess_conc_' . bin2hex(random_bytes(8));
$handlerA = new PdoSessionHandler($pdoA);
$handlerA->open('', 'PHPSESSID');
$handlerA->write($sessionId, 'a:1:{s:5:"count";i:1;}');
$handlerA->close();

$handlerB = new PdoSessionHandler($pdoB);
$handlerB->open('', 'PHPSESSID');
$read = $handlerB->read($sessionId);
$handlerB->write($sessionId, 'a:1:{s:5:"count";i:2;}');
$handlerB->close();

$handlerC = new PdoSessionHandler($pdoA);
$handlerC->open('', 'PHPSESSID');
$final = $handlerC->read($sessionId);
$handlerC->close();

$results[] = 'Sequential writes do not lose data: ' . (
    strpos($final, 'i:2') !== false ? 'PASS' : 'FAIL'
);

// 3. Two different session ids can be written independently
$idA = 'sess_concA_' . bin2hex(random_bytes(8));
$idB = 'sess_concB_' . bin2hex(random_bytes(8));
$handlerA = new PdoSessionHandler($pdoA);
$handlerA->open('', 'PHPSESSID');
$handlerB = new PdoSessionHandler($pdoB);
$handlerB->open('', 'PHPSESSID');
$handlerA->write($idA, 'a:1:{s:3:"who";s:1:"A";}');
$handlerB->write($idB, 'a:1:{s:3:"who";s:1:"B";}');
$readA = $handlerA->read($idA);
$readB = $handlerB->read($idB);
$results[] = 'Different sessions update independently: ' . (
    strpos($readA, '"A"') !== false && strpos($readB, '"B"') !== false ? 'PASS' : 'FAIL'
);
$handlerA->close();
$handlerB->close();

header('Content-Type: text/plain');
echo implode("\n", $results);
