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

// The Terms-acceptance gate redirects owners/admins who have not accepted the
// current Terms. For this billing-render fixture, record acceptance for the
// test user and restore the original value afterward.
\$origTermsStmt = \$pdo->prepare("SELECT terms_accepted_version, terms_accepted_at FROM users WHERE id = ?");
\$origTermsStmt->execute([21]);
\$origTerms = \$origTermsStmt->fetch(PDO::FETCH_ASSOC);
\$pdo->prepare("UPDATE users SET terms_accepted_version = '2026-09-01', terms_accepted_at = NOW() WHERE id = ?")->execute([21]);

ob_start();
require '{$base}/main.php';
\$html = ob_get_clean();

if (\$origTerms) {
    \$pdo->prepare("UPDATE users SET terms_accepted_version = ?, terms_accepted_at = ? WHERE id = ?")
        ->execute([\$origTerms['terms_accepted_version'], \$origTerms['terms_accepted_at'], 21]);
} else {
    \$pdo->prepare("UPDATE users SET terms_accepted_version = NULL, terms_accepted_at = NULL WHERE id = ?")->execute([21]);
}

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

\$origTermsStmt = \$pdo->prepare("SELECT terms_accepted_version, terms_accepted_at FROM users WHERE id = ?");
\$origTermsStmt->execute([334]);
\$origTerms = \$origTermsStmt->fetch(PDO::FETCH_ASSOC);
\$pdo->prepare("UPDATE users SET terms_accepted_version = '2026-09-01', terms_accepted_at = NOW() WHERE id = ?")->execute([334]);

ob_start();
require '{$base}/main.php';
\$html = ob_get_clean();

if (\$origTerms) {
    \$pdo->prepare("UPDATE users SET terms_accepted_version = ?, terms_accepted_at = ? WHERE id = ?")
        ->execute([\$origTerms['terms_accepted_version'], \$origTerms['terms_accepted_at'], 334]);
} else {
    \$pdo->prepare("UPDATE users SET terms_accepted_version = NULL, terms_accepted_at = NULL WHERE id = ?")->execute([334]);
}

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
