<?php
/**
 * Tests for the PDO session handler and timeout behavior.
 * Does not perform any real-time waits.
 */

declare(strict_types=1);

$base = __DIR__ . '/..';

function runPhp(string $code): string
{
    $tmp = sys_get_temp_dir() . '/dentatrak-sessiondb-' . md5($code) . '.php';
    file_put_contents($tmp, $code);
    $output = shell_exec('php "' . $tmp . '" 2>&1');
    @unlink($tmp);
    return trim((string)$output);
}

$results = [];

$results[] = 'Default timeout 3600: ' . (
    runPhp("<?php require '{$base}/api/session.php'; echo SESSION_TIMEOUT;") === '3600' ? 'PASS' : 'FAIL'
);
$results[] = 'Default warning 300: ' . (
    runPhp("<?php require '{$base}/api/session.php'; echo SESSION_WARNING_TIME;") === '300' ? 'PASS' : 'FAIL'
);
$results[] = 'GC lifetime 5400: ' . (
    runPhp("<?php require '{$base}/api/session.php'; echo SESSION_GC_LIFETIME;") === '5400' ? 'PASS' : 'FAIL'
);
$results[] = 'Valid env override 1800: ' . (
    runPhp("<?php putenv('SESSION_TIMEOUT=1800'); \$_ENV['SESSION_TIMEOUT']='1800'; require '{$base}/api/session.php'; echo SESSION_TIMEOUT;") === '1800' ? 'PASS' : 'FAIL'
);
$results[] = 'Too-small timeout clamped 300: ' . (
    runPhp("<?php putenv('SESSION_TIMEOUT=0'); \$_ENV['SESSION_TIMEOUT']='0'; require '{$base}/api/session.php'; echo SESSION_TIMEOUT;") === '300' ? 'PASS' : 'FAIL'
);
$results[] = 'Too-large timeout clamped 86400: ' . (
    runPhp("<?php putenv('SESSION_TIMEOUT=100000'); \$_ENV['SESSION_TIMEOUT']='100000'; require '{$base}/api/session.php'; echo SESSION_TIMEOUT;") === '86400' ? 'PASS' : 'FAIL'
);
$results[] = 'Warning clamped before timeout: ' . (
    runPhp("<?php putenv('SESSION_TIMEOUT=3600'); putenv('SESSION_WARNING_TIME=4000'); \$_ENV['SESSION_TIMEOUT']='3600'; \$_ENV['SESSION_WARNING_TIME']='4000'; require '{$base}/api/session.php'; echo SESSION_WARNING_TIME;") === '3540' ? 'PASS' : 'FAIL'
);

// DB handler persistence and second-instance read
$results[] = 'DB session persistence: ' . (
    runPhp("<?php require '{$base}/api/session.php'; \$_SESSION['test_key'] = 'test_value'; \$id = session_id(); session_write_close(); session_id(\$id); @session_start(); echo (\$_SESSION['test_key'] ?? 'missing') === 'test_value' ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

// Two simulated instances (same id, separate sessions)
$results[] = 'Second instance reads session: ' . (
    runPhp("<?php require '{$base}/api/session.php'; \$_SESSION['shared'] = 'shared-value'; \$id = session_id(); session_write_close(); session_id(\$id); @session_start(); session_write_close(); session_id(\$id); @session_start(); echo (\$_SESSION['shared'] ?? 'missing') === 'shared-value' ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

// Regeneration
$results[] = 'Regenerate preserves old session overlap: ' . (
    runPhp("<?php require '{$base}/api/session.php'; \$oldId = session_id(); \$_SESSION['test_key'] = 'regen'; regenerateSession(); session_write_close(); session_id(\$oldId); @session_start(); echo (\$_SESSION['test_key'] ?? 'missing') === 'regen' ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);
$results[] = 'Rotated old id maps to new session: ' . (
    runPhp("<?php require '{$base}/api/session.php'; \$oldId = session_id(); \$_SESSION['test_key'] = 'rotated'; regenerateSession(); session_write_close(); session_id(\$oldId); @session_start(); session_write_close(); session_id(\$oldId); @session_start(); echo (\$_SESSION['test_key'] ?? 'missing') === 'rotated' ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

// Inactivity / expiry
$results[] = 'Active at 3599s: ' . (
    runPhp("<?php require '{$base}/api/session.php'; \$_SESSION['last_activity'] = time() - 3599; \$rem = getSessionTimeRemaining(); echo (\$rem > 0) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);
$results[] = 'Expired at 3601s: ' . (
    runPhp("<?php require '{$base}/api/session.php'; \$_SESSION['last_activity'] = time() - 3601; \$rem = getSessionTimeRemaining(); echo (\$rem === 0) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);
$results[] = 'Warning threshold 300s remaining: ' . (
    runPhp("<?php require '{$base}/api/session.php'; \$_SESSION['last_activity'] = time() - 3300; \$rem = getSessionTimeRemaining(); echo (\$rem >= 299 && \$rem <= 301) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

header('Content-Type: text/plain');
echo implode("\n", $results);
