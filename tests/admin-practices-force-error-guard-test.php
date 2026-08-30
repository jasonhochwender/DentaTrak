<?php
/**
 * Source-level test for tests/force-admin-error.php environment guard.
 *
 * Confirms that the force-admin-error helper mirrors the same production/UAT
 * environment guard used by api/test-helpers.php and cannot be activated in
 * production through request parameters.
 */

$base = __DIR__ . '/..';
$source = file_get_contents("{$base}/tests/force-admin-error.php");
$guardSource = file_get_contents("{$base}/api/test-helpers.php");

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, string $context = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}" . ($context ? " ({$context})" : '') . "\n";
    }
}

// ---------------------------------------------------------------------------
// Match the repository's established test-helpers guard.
// ---------------------------------------------------------------------------
assertTrue('force-admin-error reads current_environment and environment fallback', strpos($source, '$appConfig[\'current_environment\'] ?? $appConfig[\'environment\'] ?? \'production\'') !== false);
assertTrue('force-admin-error has production 403 guard', strpos($source, '$environment === \'production\'') !== false);
assertTrue('force-admin-error has test_mode / DENTATRAK_TEST_MODE guard', strpos($source, 'getenv(\'DENTATRAK_TEST_MODE\')') !== false);
assertTrue('force-admin-error rejects non-development/non-test-mode', strpos($source, '!$testMode && $environment !== \'development\'') !== false);

// ---------------------------------------------------------------------------
// Guard executes before loading application behavior.
// ---------------------------------------------------------------------------
assertTrue('force-admin-error guard appears before admin-practices require', strpos($source, "require_once __DIR__ . '/../api/admin-practices.php'") > strpos($source, '$environment === \'production\''));

// ---------------------------------------------------------------------------
// No user-controlled production parameters can activate it.
// ---------------------------------------------------------------------------
assertTrue('force-admin-error does not read user request parameters for guard', strpos($source, '$environment') !== false && strpos($source, '$_GET[\'environment\']') === false);
assertTrue('force-admin-error does not bypass guard with query secret', strpos($source, '$_GET[\'test_secret\']') === false);
assertTrue('force-admin-error mock PDO prepare throws before real DB use', strpos($source, 'throw new RuntimeException') !== false);

// ---------------------------------------------------------------------------
// It uses the same guard shape as api/test-helpers.php.
// ---------------------------------------------------------------------------
assertTrue('test-helpers.php also uses current_environment and environment fallback', strpos($guardSource, '$appConfig[\'current_environment\'] ?? $appConfig[\'environment\'] ?? \'production\'') !== false);
assertTrue('test-helpers.php also checks production first', strpos($guardSource, '$environment === \'production\'') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
