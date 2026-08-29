<?php
/**
 * Render test: ensure the user header/user-menu Billing UI is in the correct
 * initial visibility state for authorized and bypass users. This runs main.php
 * in a controlled session and greps the first-paint HTML.
 */

$base = __DIR__ . '/..';

function runPhp(string $code): string
{
    $tmp = sys_get_temp_dir() . '/dentatrak-billing-flash-' . md5($code) . '.php';
    file_put_contents($tmp, $code);
    $output = shell_exec('php "' . $tmp . '" 2>&1');
    @unlink($tmp);
    return (string) $output;
}

$adminEmail = 'pacoletstudios@gmail.com';
$bypassEmail = 'jason.hochwender@dentatrak.com';

// Authorized admin: header Billing must be present and hidden until JS reveals it.
$adminCode = <<<PHP
<?php
putenv('SHOW_BILLING=true');
\$_ENV['SHOW_BILLING'] = 'true';
\$_COOKIE['PHPSESSID'] = 'dt-billing-admin-test';
require_once '{$base}/api/appConfig.php';
\$_SESSION['db_user_id'] = 21;
\$_SESSION['user'] = [
    'id' => 21,
    'name' => 'Test Admin',
    'email' => '{$adminEmail}',
    'picture' => ''
];
\$_SESSION['current_practice_id'] = 9;
\$_SESSION['last_activity'] = time();
\$_SESSION['last_regeneration'] = time();

ob_start();
require '{$base}/main.php';
\$html = ob_get_clean();

echo 'userBillingTier_present:' . (strpos(\$html, 'id="userBillingTier"') !== false ? 'yes' : 'no') . "\n";
echo 'userBillingTier_hidden_initially:' . (strpos(\$html, 'id="userBillingTier" style="visibility: hidden;"') !== false ? 'yes' : 'no') . "\n";
echo 'billingMenuItem_present:' . (strpos(\$html, 'id="billingMenuItem"') !== false ? 'yes' : 'no') . "\n";

// Clean up the temporary session row written by main.php.
@session_write_close();
if (defined('ENCRYPTION_KEY')) {
    \$hash = hash_hmac('sha256', 'dt-billing-admin-test', ENCRYPTION_KEY);
    \$pdo->prepare("DELETE FROM php_sessions WHERE session_hash = ?")->execute([\$hash]);
}
PHP;

// Bypass admin: header/user-menu Billing must be absent from first paint.
$bypassCode = <<<PHP
<?php
putenv('SHOW_BILLING=true');
\$_ENV['SHOW_BILLING'] = 'true';
\$_COOKIE['PHPSESSID'] = 'dt-billing-bypass-test';
require_once '{$base}/api/appConfig.php';
\$_SESSION['db_user_id'] = 334;
\$_SESSION['user'] = [
    'id' => 334,
    'name' => 'Test Bypass Admin',
    'email' => '{$bypassEmail}',
    'picture' => ''
];
\$_SESSION['current_practice_id'] = 1234;
\$_SESSION['last_activity'] = time();
\$_SESSION['last_regeneration'] = time();

ob_start();
require '{$base}/main.php';
\$html = ob_get_clean();

echo 'userBillingTier_present:' . (strpos(\$html, 'id="userBillingTier"') !== false ? 'yes' : 'no') . "\n";
echo 'billingMenuItem_present:' . (strpos(\$html, 'id="billingMenuItem"') !== false ? 'yes' : 'no') . "\n";

@session_write_close();
if (defined('ENCRYPTION_KEY')) {
    \$hash = hash_hmac('sha256', 'dt-billing-bypass-test', ENCRYPTION_KEY);
    \$pdo->prepare("DELETE FROM php_sessions WHERE session_hash = ?")->execute([\$hash]);
}
PHP;

function parseResults(string $output): array {
    $results = [];
    foreach (explode("\n", trim($output)) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $results[$parts[0]] = $parts[1];
        }
    }
    return $results;
}

$adminResults = parseResults(runPhp($adminCode));
$bypassResults = parseResults(runPhp($bypassCode));

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

assertResult('Authorized admin: header Billing link is rendered', ($adminResults['userBillingTier_present'] ?? 'no') === 'yes');
assertResult('Authorized admin: header Billing link is hidden until JS sets text', ($adminResults['userBillingTier_hidden_initially'] ?? 'no') === 'yes');
assertResult('Authorized admin: user-menu Billing item is rendered', ($adminResults['billingMenuItem_present'] ?? 'no') === 'yes');

assertResult('Bypass admin: header Billing link is absent', ($bypassResults['userBillingTier_present'] ?? 'yes') === 'no');
assertResult('Bypass admin: user-menu Billing item is absent', ($bypassResults['billingMenuItem_present'] ?? 'yes') === 'no');

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
