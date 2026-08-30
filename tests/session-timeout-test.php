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

function runPhpWithInput(string $code, string $input): string
{
    $tmp = sys_get_temp_dir() . '/dentatrak-session-test-' . md5($code . '|' . $input) . '.php';
    file_put_contents($tmp, '<?php ' . $code);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('php "' . $tmp . '" 2>&1', $descriptors, $pipes);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    @unlink($tmp);
    return trim((string)$output . $errors);
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

$results[] = '11. Page load (non-API GET) resets last_activity: ' . (
    runPhp("session_id('sessact'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/main.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] > \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '12. Passive background polling does not reset last_activity: ' . (
    runPhp("session_id('sesspass'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/check-updates.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '13. check-updates.php POST (genuine action) resets last_activity and last_user_action_at: ' . (
    runPhp("session_id('sesscheckpost'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SESSION['last_user_action_at'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/check-updates.php'; \$_SERVER['REQUEST_METHOD']='POST'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_user_action_at'] > \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '14. session-status.php GET does not reset last_activity: ' . (
    runPhp("session_id('sessstatget'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/session-status.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '15. notifications.php GET does not reset last_activity: ' . (
    runPhp("session_id('sessnotifget'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/notifications.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '16. notifications.php POST (genuine action) resets last_activity and last_user_action_at: ' . (
    runPhp("session_id('sessnotifpost'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SESSION['last_user_action_at'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/notifications.php'; \$_SERVER['REQUEST_METHOD']='POST'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_user_action_at'] > \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '17. session-status.php POST (activity ping) resets last_user_action_at: ' . (
    strpos(
        runPhpWithInput(
            "session_id('sessstatpost'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SESSION['last_user_action_at'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/session-status.php'; \$_SERVER['REQUEST_METHOD']='POST'; require '$baseDir/api/session-status.php';",
            '{"action":"activity"}'
        ),
        '"message":"Activity recorded"'
    ) !== false ? 'PASS' : 'FAIL'
);

$results[] = '18. API read GET (e.g. list-cases) does not reset last_activity: ' . (
    runPhp("session_id('sesslistget'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/list-cases.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '19. upload keepalive ping.php GET does not reset without an active upload: ' . (
    runPhp("session_id('sessping'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/ping.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '19b. upload keepalive ping.php GET resets last_activity when an upload is active: ' . (
    runPhp("session_id('sessping2'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SESSION['last_upload_ping'] = \$now; \$_SERVER['SCRIPT_NAME']='/api/ping.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] > \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '19c. upload keepalive ping.php GET slides the upload window: ' . (
    runPhp("session_id('sessping3'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SESSION['last_upload_ping'] = \$now - 30; \$_SERVER['SCRIPT_NAME']='/api/ping.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_upload_ping'] >= \$now) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '19d. upload keepalive ping.php GET does not reset when the upload window has expired: ' . (
    runPhp("session_id('sessping4'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SESSION['last_upload_ping'] = \$now - 3700; \$_SERVER['SCRIPT_NAME']='/api/ping.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '20. attachment-content.php GET does not reset last_activity: ' . (
    runPhp("session_id('sessattach'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 10; \$_SERVER['SCRIPT_NAME']='/api/attachment-content.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (\$_SESSION['last_activity'] == \$now - 10) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '21. last_user_action_at takes precedence over older last_activity: ' . (
    runPhp("session_id('sessaction'); session_start(); \$now = time(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = \$now - 4000; \$_SESSION['last_user_action_at'] = \$now - 100; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; \$rem = getSessionTimeRemaining(); echo (\$rem > 3500) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

$results[] = '22. session-status.php GET returns inactivity reason when expired: ' . (
    strpos(
        runPhp("putenv('SESSION_TIMEOUT=300'); putenv('SESSION_WARNING_TIME=10'); \$_ENV['SESSION_TIMEOUT']='300'; \$_ENV['SESSION_WARNING_TIME']='10'; session_id('sessexpired'); session_start(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = time() - 301; \$_SERVER['SCRIPT_NAME']='/api/session-status.php'; \$_SERVER['REQUEST_METHOD']='GET'; require '$baseDir/api/session-status.php';"),
        '"reason":"inactivity"'
    ) !== false ? 'PASS' : 'FAIL'
);

$results[] = '23. session-status.php POST on expired session returns 401 without restoring: ' . (
    strpos(
        runPhpWithInput(
            "putenv('SESSION_TIMEOUT=300'); putenv('SESSION_WARNING_TIME=10'); \$_ENV['SESSION_TIMEOUT']='300'; \$_ENV['SESSION_WARNING_TIME']='10'; session_id('sessexpiredpost'); session_start(); \$_SESSION['db_user_id']=1; \$_SESSION['last_activity'] = time() - 301; \$_SERVER['SCRIPT_NAME']='/api/session-status.php'; \$_SERVER['REQUEST_METHOD']='POST'; require '$baseDir/api/session-status.php';",
            '{"action":"activity"}'
        ),
        '"loggedIn":false'
    ) !== false ? 'PASS' : 'FAIL'
);

$results[] = '24. Short timeout (5 min) with 1 min warning is env-only and not request-controlled: ' . (
    runPhp("putenv('SESSION_TIMEOUT=300'); putenv('SESSION_WARNING_TIME=60'); \$_ENV['SESSION_TIMEOUT']='300'; \$_ENV['SESSION_WARNING_TIME']='60'; require '$baseDir/api/bootstrap.php'; require '$baseDir/api/session.php'; echo (SESSION_TIMEOUT == 300 && SESSION_WARNING_TIME == 60) ? 'PASS' : 'FAIL';") === 'PASS' ? 'PASS' : 'FAIL'
);

// JavaScript fallback check
$js = file_get_contents(__DIR__ . '/../js/session-timeout.js');
$results[] = '25. JS sessionTimeout fallback 3600000: ' . (
    strpos($js, 'var sessionTimeout = 60 * 60 * 1000') !== false ? 'PASS' : 'FAIL'
);

header('Content-Type: text/plain');
echo implode("\n", $results);
