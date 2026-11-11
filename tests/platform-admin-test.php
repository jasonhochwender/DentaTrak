<?php
/**
 * Static regression test for the Platform Administration restructure.
 *
 * Verifies:
 *  - the floating Dev Tools panel is gone from main.php
 *  - the avatar menu exposes Platform Administration only behind the
 *    server-side super-user / development gate
 *  - every administration page uses the shared chrome and keeps its
 *    own authorization check
 *  - the overview API enforces authentication and authorization
 *  - the new locale sections exist in every locale file
 *
 * No database or browser required.
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';
$failures = [];
$passes = 0;

function assertContains(string $path, string $pattern, string $message): void
{
    global $failures, $passes;
    $content = @file_get_contents($path);
    if ($content === false || !preg_match($pattern, $content)) {
        $failures[] = $message;
    } else {
        $passes++;
    }
}

function assertNotContains(string $path, string $pattern, string $message): void
{
    global $failures, $passes;
    $content = @file_get_contents($path);
    if ($content === false || preg_match($pattern, $content)) {
        $failures[] = $message;
    } else {
        $passes++;
    }
}

$mainPhp = $baseDir . '/main.php';

// ---- 1. Floating Dev Tools panel fully removed ----
assertNotContains($mainPhp, '/devToolsPanel/', 'main.php must not contain the devToolsPanel element');
assertNotContains($mainPhp, '/dev-tools\.css/', 'main.php must not load css/dev-tools.css');
assertNotContains($mainPhp, '/devGenerateCasesBtn|devDeleteAllCasesBtn|devCaseCount/', 'main.php must not contain Dev Tools control ids');
assertNotContains($mainPhp, '/id="devToolsContent"/', 'main.php must not contain the Dev Tools content container');
assertContains($baseDir . '/js/app.js', '/function printCase/', 'app.js must still parse (sanity)');
assertNotContains($baseDir . '/js/app.js', '/devGenerateCasesBtn/', 'app.js must not retain the dead fake-case handler');

// ---- 2. Avatar menu entry, gated ----
assertContains($mainPhp, '/\$showPlatformAdmin\s*=\s*isSuperUser\(/', 'main.php must compute $showPlatformAdmin via isSuperUser()');
assertContains($mainPhp, '/platformAdminMenuItem/', 'main.php must render the Platform Administration menu item');
assertContains($mainPhp, '/if \(\$showPlatformAdmin\): \?>\s*<div class="user-menu-divider"><\/div>\s*<a href="platform-admin\.php"/', 'menu item must be wrapped in the $showPlatformAdmin guard');
assertContains($mainPhp, '/href="platform-admin\.php"/', 'menu item must link to platform-admin.php');

// ---- 3. Shared chrome on every administration page ----
$pages = [
    'platform-admin.php'        => ['overview', 'admin_overview'],
    'admin-practices.php'       => ['practices', 'admin_practices'],
    'practice-usage-report.php' => ['usage', 'usage_report'],
    'dev-ask-usage.php'         => ['ai', 'admin_nav'],
    'test-diagnostics.php'      => ['test', 'admin_testdiag'],
];
foreach ($pages as $file => [$section, $titleKey]) {
    $path = $baseDir . '/' . $file;
    assertContains($path, "~admin-layout\\.php'~", "$file must require admin-layout.php");
    assertContains($path, '/css\/admin\.css/', "$file must load css/admin.css");
    assertContains($path, '/paAdminChrome\(\'' . $section . '\'/', "$file must call paAdminChrome('$section')");
    assertContains($path, '/paAdminChromeEnd\(\)/', "$file must call paAdminChromeEnd()");
    assertContains($path, '/isSuperUser\(\$appConfig, \$userEmail\) \|\| \$isDev/', "$file must keep the super-user-or-dev authorization gate");
    assertContains($path, '/header\(\'Location: main\.php\'\)/', "$file must redirect unauthorized users");
}

// ---- 4. Shared layout itself ----
assertContains($baseDir . '/admin-layout.php', '/function paAdminSections/', 'admin-layout.php must define paAdminSections()');
assertContains($baseDir . '/admin-layout.php', '/platform-admin\.php/', 'admin-layout.php must list platform-admin.php');
assertContains($baseDir . '/admin-layout.php', '/admin-practices\.php/', 'admin-layout.php must list admin-practices.php');
assertContains($baseDir . '/admin-layout.php', '/practice-usage-report\.php/', 'admin-layout.php must list practice-usage-report.php');
assertContains($baseDir . '/admin-layout.php', '/dev-ask-usage\.php/', 'admin-layout.php must list dev-ask-usage.php');
assertContains($baseDir . '/admin-layout.php', '/test-diagnostics\.php/', 'admin-layout.php must list test-diagnostics.php');
assertContains($baseDir . '/admin-layout.php', '/getEnvironmentDisplayName/', 'admin-layout.php must show the environment indicator');
assertContains($baseDir . '/admin-layout.php', '/href="main\.php"/', 'admin-layout.php must provide a return-to-app link');

// ---- 5. Overview API authorization ----
$apiPath = $baseDir . '/api/platform-admin-overview.php';
assertContains($apiPath, '/http_response_code\(401\)/', 'overview API must return 401 when unauthenticated');
assertContains($apiPath, '/http_response_code\(403\)/', 'overview API must return 403 when unauthorized');
assertContains($apiPath, '/isSuperUser\(\$appConfig, \$userEmail\)/', 'overview API must check isSuperUser()');
assertContains($apiPath, '/cases_cache/', 'overview API must use cases_cache for case counts');
assertContains($apiPath, '/practice_storage_usage/', 'overview API must use cached practice_storage_usage rows');

// ---- 6. Locale coverage ----
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
foreach ($locales as $loc) {
    $json = json_decode(@file_get_contents($baseDir . "/locales/$loc.json"), true);
    if (!is_array($json)) { $failures[] = "$loc.json is not valid JSON"; continue; }
    foreach (['admin_nav' => ['overview','practices','usage','ai_analytics','test_diag','back_to_app','menu_item'],
              'admin_overview' => ['subtitle','m_practices','m_storage','sections_title','load_failed'],
              'admin_testdiag' => ['subtitle','gen_title','danger_title','delete_all','delete_scope']] as $section => $keys) {
        foreach ($keys as $k) {
            if (empty($json[$section][$k])) {
                $failures[] = "$loc.json missing $section.$k";
            } else {
                $passes++;
            }
        }
    }
}

// ---- 7. Test & Diagnostics safety ----
$tdPath = $baseDir . '/test-diagnostics.php';
assertContains($tdPath, '/api\/generate-fake-cases\.php/', 'test-diagnostics must call api/generate-fake-cases.php');
assertContains($tdPath, '/api\/delete-all-cases\.php/', 'test-diagnostics must call api/delete-all-cases.php');
assertContains($tdPath, '/X-CSRF-Token/', 'test-diagnostics must send the CSRF token');
assertContains($tdPath, '/current_practice_id/', 'test-diagnostics must display the scoped practice');
assertContains($tdPath, '/CONFIRM/', 'test-diagnostics must require typed CONFIRM in production');

// ---- Report ----
if ($failures) {
    echo "FAILURES: " . count($failures) . "\n";
    foreach ($failures as $f) { echo "- $f\n"; }
    echo "PASSES: $passes\n";
    exit(1);
}
echo "All $passes platform administration checks passed.\n";
exit(0);
