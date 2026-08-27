<?php
/**
 * Clock-controlled tests for the session inactivity timeout.
 * No real-time waits are used.
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';

function runPhp(string $code): string
{
    $tmp = sys_get_temp_dir() . '/dentatrak-session-test-' . md5($code) . '.php';
    file_put_contents($tmp, '<?php ' . $code);
    $output = shell_exec('php "' . $tmp . '" 2>&1');
    @unlink($tmp);
    return trim((string)$output);
}

$results = [];

$results[] = '1. Default timeout 3600: ' . (
    runPhp("require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo SESSION_TIMEOUT;") === '3600' ? 'PASS' : 'FAIL'
);

$results[] = '2. Default warning 300: ' . (
    runPhp("require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo SESSION_WARNING_TIME;") === '300' ? 'PASS' : 'FAIL'
);

$results[] = '3. Default GC lifetime 5400: ' . (
    runPhp("require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo SESSION_GC_LIFETIME;") === '5400' ? 'PASS' : 'FAIL'
);

$results[] = '4. Valid env override 1800: ' . (
    runPhp("putenv('SESSION_TIMEOUT=1800'); \$_ENV['SESSION_TIMEOUT']='1800'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo SESSION_TIMEOUT;") === '1800' ? 'PASS' : 'FAIL'
);

$results[] = '5. Too-small timeout clamped to 300: ' . (
    runPhp("putenv('SESSION_TIMEOUT=0'); \$_ENV['SESSION_TIMEOUT']='0'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo SESSION_TIMEOUT;") === '300' ? 'PASS' : 'FAIL'
);

$results[] = '6. Excessively large timeout clamped to 86400: ' . (
    runPhp("putenv('SESSION_TIMEOUT=100000'); \$_ENV['SESSION_TIMEOUT']='100000'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo SESSION_TIMEOUT;") === '86400' ? 'PASS' : 'FAIL'
);

$results[] = '7. Warning time clamped before timeout: ' . (
    runPhp("putenv('SESSION_TIMEOUT=3600'); putenv('SESSION_WARNING_TIME=4000'); \$_ENV['SESSION_TIMEOUT']='3600'; \$_ENV['SESSION_WARNING_TIME']='4000'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo SESSION_WARNING_TIME;") === '3540' ? 'PASS' : 'FAIL'
);

$results[] = '8. Session active at 3599s: ' . (
    runPhp("session_id('sess3599'); session_start(); require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; \$_SESSION['last_activity'] = time() - 3599; \$rem = getSessionTimeRemaining(); echo (\$rem > 0) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '9. Session expired at 3601s: ' . (
    runPhp("session_id('sess3601'); session_start(); require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; \$_SESSION['last_activity'] = time() - 3601; \$rem = getSessionTimeRemaining(); echo (\$rem === 0) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '10. Warning threshold begins at 300s remaining: ' . (
    runPhp("session_id('sesswarn'); session_start(); require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; \$_SESSION['last_activity'] = time() - (SESSION_TIMEOUT - 300); echo (getSessionTimeRemaining() === 300) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '11. Activity request resets last_activity: ' . (
    runPhp("session_id('sessact'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/main.php'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] > \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '12. Passive background polling does not reset last_activity: ' . (
    runPhp("session_id('sesspass'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/check-updates.php'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

// JavaScript fallback check
$js = file_get_contents(__DIR__ . '/../js/session-timeout.js');
$results[] = '13. JS sessionTimeout fallback 3600000: ' . (
    strpos($js, 'var sessionTimeout = 60 * 60 * 1000') !== false ? 'PASS' : 'FAIL'
);

header('Content-Type: text/plain');
echo implode("\n", $results);
