<?php
/**
 * Admin-practices authorization unit tests.
 *
 * Verifies the helper functions and the exact authorization rule used by the
 * admin practices API and page. Production-style environments are exercised
 * without a live HTTP server because the local PHP dev server always runs as
 * `development`.
 */

$base = __DIR__ . '/..';
require_once "{$base}/api/dev-tools-access.php";

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}\n";
    }
}

function canAccessAdminPractices(array $appConfig, string $userEmail): bool {
    $isDev = ($appConfig['current_environment'] ?? '') === 'development';
    return isSuperUser($appConfig, $userEmail) || $isDev;
}

// ---------------------------------------------------------------------------
// isSuperUser()
// ---------------------------------------------------------------------------
$appConfig = [
    'current_environment' => 'production',
    'super_users' => ['super@example.com', ' Admin@Example.COM ']
];

assertTrue('isSuperUser matches configured super email', isSuperUser($appConfig, 'super@example.com'));
assertTrue('isSuperUser is case-insensitive', isSuperUser($appConfig, 'SUPER@EXAMPLE.COM'));
assertTrue('isSuperUser trims whitespace in list', isSuperUser($appConfig, 'admin@example.com'));
assertTrue('isSuperUser trims whitespace in argument', isSuperUser($appConfig, '  super@example.com  '));
assertTrue('isSuperUser rejects non-super', !isSuperUser($appConfig, 'normal@example.com'));
assertTrue('isSuperUser returns false with empty super list', !isSuperUser(['current_environment' => 'production', 'super_users' => []], 'super@example.com'));
assertTrue('isSuperUser returns false with empty email', !isSuperUser($appConfig, ''));

// ---------------------------------------------------------------------------
// canAccessAdminPractices()
// ---------------------------------------------------------------------------
$prodConfig = ['current_environment' => 'production', 'super_users' => ['super@example.com']];
$devConfig = ['current_environment' => 'development', 'super_users' => []];

assertTrue('super user is allowed in production', canAccessAdminPractices($prodConfig, 'super@example.com'));
assertTrue('non-super user is rejected in production', !canAccessAdminPractices($prodConfig, 'normal@example.com'));
assertTrue('any authenticated user is allowed in development', canAccessAdminPractices($devConfig, 'normal@example.com'));
assertTrue('super user still allowed in development', canAccessAdminPractices($devConfig, 'super@example.com'));

// ---------------------------------------------------------------------------
// Source-level rule in the API
// ---------------------------------------------------------------------------
$apiSource = file_get_contents("{$base}/api/admin-practices.php");
assertTrue('API uses isSuperUser and current_environment for canAccess', strpos($apiSource, '$canAccess = isSuperUser($appConfig, $userEmail) || $isDev') !== false);

// The page uses the same rule.
$pageSource = file_get_contents("{$base}/admin-practices.php");
assertTrue('Page uses isSuperUser and current_environment for canAccess', strpos($pageSource, '$canAccess = isSuperUser($appConfig, $userEmail) || $isDev') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
