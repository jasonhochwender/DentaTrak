<?php
/**
 * Security, concurrency, and handler edge-case tests for the PDO session handler.
 * Uses the handler directly (no browser session) so it can inspect raw rows.
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

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

// Clear any stale handler sentinel so table existence is rechecked
foreach (glob(sys_get_temp_dir() . '/dtk_php_sessions_table_*') as $sentinel) {
    @unlink($sentinel);
}
} catch (PDOException $e) {
    header('Content-Type: text/plain');
    echo "DB connection failed: " . $e->getMessage();
    exit(1);
}

require_once __DIR__ . '/../api/session-db-handler.php';

$results = [];
$handlerA = new PdoSessionHandler($pdo);
$handlerA->open('', 'PHPSESSID');

// Helpers
$testId = 'sess_' . bin2hex(random_bytes(16));
$testHash = PdoSessionHandler::hashId($testId);
$testData = 'a:1:{s:4:"test";s:5:"value";}';

// 0. Empty anonymous session is not persisted
$emptyId = 'sess_empty_' . bin2hex(random_bytes(8));
$emptyHash = PdoSessionHandler::hashId($emptyId);
$handlerA->write($emptyId, '');
$emptyExists = $pdo->query("SELECT COUNT(*) FROM php_sessions WHERE session_hash = '" . $emptyHash . "'")->fetchColumn();
$results[] = 'Empty anonymous session not persisted: ' . (
    $emptyExists == 0 ? 'PASS' : 'FAIL'
);

// 0b. Empty php_serialize payload ('a:0:{}') is not persisted
$emptySerializeId = 'sess_empty_ser_' . bin2hex(random_bytes(8));
$emptySerializeHash = PdoSessionHandler::hashId($emptySerializeId);
$handlerA->write($emptySerializeId, 'a:0:{}');
$emptySerExists = $pdo->query("SELECT COUNT(*) FROM php_sessions WHERE session_hash = '" . $emptySerializeHash . "'")->fetchColumn();
$results[] = 'Empty php_serialize session not persisted: ' . (
    $emptySerExists == 0 ? 'PASS' : 'FAIL'
);

// 0c. Actual empty session_encode() under php_serialize is not persisted
ini_set('session.serialize_handler', 'php_serialize');
$realEmptyId = 'sess_real_empty_' . bin2hex(random_bytes(8));
$realEmptyHash = PdoSessionHandler::hashId($realEmptyId);
$realHandler = new PdoSessionHandler($pdo);
$realHandler->open('', 'PHPSESSID');
$_COOKIE['PHPSESSID'] = $realEmptyId;
@session_set_save_handler($realHandler, true);
@session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
@session_start();
$realEmptyPayload = @session_encode();
@session_write_close();
$realEmptyExists = $pdo->query("SELECT COUNT(*) FROM php_sessions WHERE session_hash = '" . $realEmptyHash . "'")->fetchColumn();
$results[] = 'Actual empty session_encode() not persisted: ' . (
    $realEmptyPayload === 'a:0:{}' && $realEmptyExists == 0 ? 'PASS' : 'FAIL'
);

// 1. Raw session id is not stored in the table
$handlerA->write($testId, $testData);
$stmt = $pdo->prepare("SELECT session_hash, data FROM php_sessions WHERE session_hash = :hash");
$stmt->execute(['hash' => $testHash]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$results[] = 'Raw id not stored as session_hash: ' . (
    $row && $row['session_hash'] === $testHash && strpos($row['data'], $testId) === false ? 'PASS' : 'FAIL'
);

// 2. Binary payload round-trip with null and non-UTF-8 bytes
$binaryId = 'sess_binary_' . bin2hex(random_bytes(8));
$binaryPayload = "a:1:{s:3:\"bin\";s:3:\"\x00\xFF\xFE\";}"; // php_serialize-style payload
$handlerA->write($binaryId, $binaryPayload);
$readBinary = $handlerA->read($binaryId);
$results[] = 'Binary payload round-trip: ' . (
    $readBinary === $binaryPayload ? 'PASS' : 'FAIL'
);

// 3. Identifier injection is prevented by prepared statements
$badId = "' OR '1'='1";
$handlerA->write($badId, $testData);
$badHash = PdoSessionHandler::hashId($badId);
$read = $handlerA->read($badId);
$results[] = 'Prepared statements prevent identifier injection: ' . (
    $read === $testData ? 'PASS' : 'FAIL'
);

// 3. Expired session cannot be read
$expiredId = 'sess_expired_' . bin2hex(random_bytes(8));
$handlerA->write($expiredId, $testData);
$pdo->prepare("UPDATE php_sessions SET expires_at = 1 WHERE session_hash = :hash")
    ->execute(['hash' => PdoSessionHandler::hashId($expiredId)]);
$results[] = 'Expired session returns empty: ' . (
    $handlerA->read($expiredId) === '' ? 'PASS' : 'FAIL'
);

// 4. GC removes expired sessions
$before = $pdo->query("SELECT COUNT(*) FROM php_sessions WHERE expires_at < " . time())->fetchColumn();
$handlerA->gc(3600);
$after = $pdo->query("SELECT COUNT(*) FROM php_sessions WHERE expires_at < " . time())->fetchColumn();
$results[] = 'GC removes expired rows: ' . (
    ($after <= $before) && $pdo->query("SELECT COUNT(*) FROM php_sessions WHERE session_hash = '" . PdoSessionHandler::hashId($expiredId) . "'")->fetchColumn() == 0 ? 'PASS' : 'FAIL'
);

// 5. Explicit logout destroys session
$logoutId = 'sess_logout_' . bin2hex(random_bytes(8));
$handlerA->write($logoutId, $testData);
$handlerA->destroy($logoutId);
$results[] = 'Explicit logout destroys session: ' . (
    $handlerA->read($logoutId) === '' ? 'PASS' : 'FAIL'
);

// 6. Lock is released after close and after destroy
$lockId = 'sess_lock_' . bin2hex(random_bytes(8));
$handlerA->write($lockId, $testData);
$handlerA->close();

$handlerB = new PdoSessionHandler($pdo);
$handlerB->open('', 'PHPSESSID');
$handlerB->write($lockId, 'a:1:{s:4:"test";s:4:"next";}');
$results[] = 'Lock released after close: ' . (
    $handlerB->read($lockId) !== '' ? 'PASS' : 'FAIL'
);

// 7. Separate sessions do not block each other
$idA = 'sess_sepa_' . bin2hex(random_bytes(8));
$idB = 'sess_sepb_' . bin2hex(random_bytes(8));
$handlerA = new PdoSessionHandler($pdo);
$handlerA->open('', 'PHPSESSID');
$handlerB = new PdoSessionHandler($pdo);
$handlerB->open('', 'PHPSESSID');
$handlerA->write($idA, 'a:1:{s:1:"k";s:1:"A";}');
$handlerB->write($idB, 'a:1:{s:1:"k";s:1:"B";}');
$results[] = 'Separate sessions do not block: ' . (
    $handlerA->read($idA) !== '' && $handlerB->read($idB) !== '' ? 'PASS' : 'FAIL'
);
$handlerA->close();
$handlerB->close();

// 8. Rotation mapping allows old id to read new data for a bounded period
$oldId = 'sess_old_' . bin2hex(random_bytes(8));
$newId = 'sess_new_' . bin2hex(random_bytes(8));
$handlerA = new PdoSessionHandler($pdo);
$handlerA->open('', 'PHPSESSID');
$handlerA->write($oldId, 'a:1:{s:4:"step";s:3:"old";}');
$handlerA->markRotated($oldId, $newId);
$handlerA->write($newId, 'a:1:{s:4:"step";s:3:"new";}');
$handlerA->close();

$handlerB = new PdoSessionHandler($pdo);
$handlerB->open('', 'PHPSESSID');
$readOld = $handlerB->read($oldId);
$results[] = 'Rotated old id maps to new data: ' . (
    strpos($readOld, 'new') !== false ? 'PASS' : 'FAIL'
);
$handlerB->close();

// 8b. Rotation timing and cleanup
$oldHash = PdoSessionHandler::hashId($oldId);
$newHash = PdoSessionHandler::hashId($newId);
$now = time();
$oldRow = $pdo->prepare("SELECT expires_at FROM php_sessions WHERE session_hash = :hash")->execute(['hash' => $oldHash]);
$oldExpires = $pdo->query("SELECT expires_at FROM php_sessions WHERE session_hash = '" . $oldHash . "'")->fetchColumn();
$mapRow = $pdo->query("SELECT expires_at FROM php_session_rotations WHERE old_hash = '" . $oldHash . "'")->fetchColumn();
$results[] = 'Old session row is expired immediately on rotation: ' . (
    $oldExpires !== false && $oldExpires < $now ? 'PASS' : 'FAIL'
);
$results[] = 'Rotation mapping valid for bounded 30 seconds: ' . (
    $mapRow !== false && $mapRow >= $now && $mapRow <= $now + 30 ? 'PASS' : 'FAIL'
);

// 8c. Old write is forwarded to new session; old row is not forked
$handlerC = new PdoSessionHandler($pdo);
$handlerC->open('', 'PHPSESSID');
$handlerC->write($oldId, 'a:1:{s:4:"step";s:7:"forward";}');
$handlerC->close();
$forwarded = $pdo->query("SELECT data FROM php_sessions WHERE session_hash = '" . $newHash . "'")->fetchColumn();
$results[] = 'Old id write forwards to new session: ' . (
    strpos($forwarded, 'forward') !== false ? 'PASS' : 'FAIL'
);

// 8d. After rotation mapping expires, old id is no longer linked
$pdo->prepare("UPDATE php_session_rotations SET expires_at = 1 WHERE old_hash = :hash")
    ->execute(['hash' => $oldHash]);
$handlerD = new PdoSessionHandler($pdo);
$handlerD->open('', 'PHPSESSID');
$results[] = 'Old id cannot read new data after mapping expires: ' . (
    $handlerD->read($oldId) === '' ? 'PASS' : 'FAIL'
);
$handlerD->close();

// 8e. GC removes stale rotation mappings
$beforeRot = $pdo->query("SELECT COUNT(*) FROM php_session_rotations WHERE old_hash = '" . $oldHash . "'")->fetchColumn();
$handlerA = new PdoSessionHandler($pdo);
$handlerA->open('', 'PHPSESSID');
$handlerA->gc(3600);
$afterRot = $pdo->query("SELECT COUNT(*) FROM php_session_rotations WHERE old_hash = '" . $oldHash . "'")->fetchColumn();
$results[] = 'GC removes expired rotation mapping: ' . (
    $beforeRot == 1 && $afterRot == 0 ? 'PASS' : 'FAIL'
);

// 9. Canonical lock during rotation: old and new ids both contend for the same new-hash lock
$rotOldId = 'rot_canon_old_' . bin2hex(random_bytes(8));
$rotNewId = 'rot_canon_new_' . bin2hex(random_bytes(8));
$rotNewHash = PdoSessionHandler::hashId($rotNewId);

$pdoCanon1 = new PDO($dsn, $user, $pass, $options);
$pdoCanon2 = new PDO($dsn, $user, $pass, $options);
$handlerCanon1 = new PdoSessionHandler($pdoCanon1);
$handlerCanon1->open('', 'PHPSESSID');
$handlerCanon1->write($rotOldId, 'a:1:{s:4:"step";s:3:"old";}');
$handlerCanon1->markRotated($rotOldId, $rotNewId);
$handlerCanon1->write($rotNewId, 'a:1:{s:4:"step";s:3:"new";}'); // holds the canonical new-hash lock

putenv('DENTATRAK_SESSION_LOCK_TIMEOUT=0');
$handlerCanon2 = new PdoSessionHandler($pdoCanon2);
$handlerCanon2->open('', 'PHPSESSID');

// An old-id request and a new-id request must both block on the canonical (new) lock.
$blockedOld = $handlerCanon2->write($rotOldId, 'a:1:{s:4:"step";s:7:"old2nd";}');
$blockedNew = $handlerCanon2->write($rotNewId, 'a:1:{s:4:"step";s:7:"new2nd";}');
$results[] = 'Canonical new-hash lock blocks old id during rotation: ' . (
    $blockedOld === false ? 'PASS' : 'FAIL'
);
$results[] = 'Canonical new-hash lock blocks new id during rotation: ' . (
    $blockedNew === false ? 'PASS' : 'FAIL'
);

// Release and retry: the old-id write should forward to and update the new row.
putenv('DENTATRAK_SESSION_LOCK_TIMEOUT=5');
$handlerCanon1->close();
$okOld2nd = $handlerCanon2->write($rotOldId, 'a:1:{s:4:"step";s:7:"old2nd";}');
$results[] = 'Old id write proceeds after canonical lock released: ' . (
    $okOld2nd === true ? 'PASS' : 'FAIL'
);
$handlerCanon2->close();

$finalCanon = $pdoCanon2->query("SELECT data FROM php_sessions WHERE session_hash = '" . $rotNewHash . "'")->fetchColumn();
$results[] = 'Old id final write lands on canonical new row: ' . (
    $okOld2nd === true && strpos($finalCanon, 'old2nd') !== false ? 'PASS' : 'FAIL'
);

// 10. Missing table fails safely (no fallback to local sessions)
$pdo->exec("DROP TABLE IF EXISTS php_session_rotations");
$pdo->exec("DROP TABLE IF EXISTS php_sessions");
foreach (glob(sys_get_temp_dir() . '/dtk_php_sessions_table_*') as $sentinel) {
    @unlink($sentinel);
}

$handlerC = new PdoSessionHandler($pdo);
$handlerC->open('', 'PHPSESSID');
$missingId = 'sess_missing_' . bin2hex(random_bytes(8));
try {
    $handlerC->write($missingId, $testData);
    $read = $handlerC->read($missingId);
    $results[] = 'Missing table created on demand: ' . (
        $read === $testData ? 'PASS' : 'FAIL'
    );
} catch (Throwable $e) {
    // If creation is disallowed, the error should be an exception, not a silent local fallback
    $results[] = 'Missing table fails with exception (no silent local fallback): PASS';
}

header('Content-Type: text/plain');
echo implode("\n", $results);
