<?php
/**
 * Static responsive/CSS audit test.
 *
 * Verifies that the root causes of the Phase 1 mobile/tablet overflow
 * issues have been corrected in the shared styles. It does not require
 * a browser; it reads the source files and checks for the corrected rules.
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';
$failures = [];
$passes = 0;

function assertContains(string $path, string $pattern, string $message): void
{
    global $failures, $passes;
    $content = file_get_contents($path);
    if (!preg_match($pattern, $content)) {
        $failures[] = $message;
    } else {
        $passes++;
    }
}

function assertNotContains(string $path, string $pattern, string $message): void
{
    global $failures, $passes;
    $content = file_get_contents($path);
    if (preg_match($pattern, $content)) {
        $failures[] = $message;
    } else {
        $passes++;
    }
}

// 1. Generic modal content no longer has a desktop-only 835px min-width.
assertNotContains(
    $baseDir . '/css/app.light.css',
    '/\.modal-content\s*\{[^}]*min-width:\s*835px/s',
    'app.light.css .modal-content must not set min-width: 835px'
);

// 2. The base modal now allows shrinking and caps width.
assertContains(
    $baseDir . '/css/app.light.css',
    '/\.modal-content\s*\{[^}]*min-width:\s*0[^}]*max-width:/s',
    'app.light.css .modal-content must set min-width: 0 and a max-width'
);

// 3. The mobile stylesheet must not globally hide overflow on html/body.
assertNotContains(
    $baseDir . '/css/mobile.css',
    '/html,\s*body\s*\{[^}]*overflow-x:\s*hidden\s*!/s',
    'mobile.css must not use !important overflow-x: hidden on html, body'
);

// 4. The root container is guarded instead.
assertContains(
    $baseDir . '/css/mobile.css',
    '/\.main-container\s*\{[^}]*max-width:\s*100%[^}]*box-sizing:\s*border-box/s',
    'mobile.css must constrain .main-container with max-width: 100% and border-box'
);

// 5. Notifications have a small-screen side sheet or width cap.
assertContains(
    $baseDir . '/css/mobile.css',
    '/\.notification-dropdown\s*\{[^}]*position:\s*fixed[^}]*right:\s*0/s',
    'mobile.css must render .notification-dropdown as a fixed right side sheet on small screens'
);
assertContains(
    $baseDir . '/css/mobile.css',
    '/@media\s*\(min-width:\s*481px\)\s*and\s*\(max-width:\s*768px\)[^}]*\.notification-dropdown/s',
    'mobile.css must cap .notification-dropdown width on small tablets'
);

// 6. The search input no longer has a fixed 260px min-width or 500px field min-width.
assertContains(
    $baseDir . '/css/app.light.css',
    '/\.dashboard-search-input\s*\{[^}]*min-width:\s*0/s',
    'app.light.css .dashboard-search-input must have min-width: 0'
);
assertContains(
    $baseDir . '/css/app.light.css',
    '/\.kanban-search-field\s*\{[^}]*min-width:\s*0[^}]*max-width:\s*100%/s',
    'app.light.css .kanban-search-field must be able to shrink (min-width: 0, max-width: 100%)'
);
assertContains(
    $baseDir . '/css/patient-search.css',
    '/\.kanban-search-field\s*\{[^}]*min-width:\s*0[^}]*max-width:\s*100%/s',
    'patient-search.css .kanban-search-field must allow shrink and fit container'
);

// 7. Confirmation modals are viewport-aware.
assertContains(
    $baseDir . '/css/app.light.css',
    '/\.confirm-modal\s*\.confirm-modal-content\s*\{[^}]*min-width:\s*0[^}]*max-width:\s*90vw/s',
    'app.light.css .confirm-modal .confirm-modal-content must be viewport-safe (min-width: 0, max-width: 90vw)'
);

// 8. Dev Tools are positioned safely and below modal backdrops.
assertContains(
    $baseDir . '/css/dev-tools.css',
    '/\.dev-tools-panel\s*\{[^}]*z-index:\s*50/s',
    'dev-tools.css .dev-tools-panel must have a z-index below modal backdrops'
);
assertContains(
    $baseDir . '/css/dev-tools.css',
    '/@media\s*\(max-width:\s*480px\)[^}]*\.dev-tools-panel\s*\{[^}]*right:/s',
    'dev-tools.css must reposition the panel on phones so it does not block primary controls'
);

// 9. Toasts cannot overflow the viewport on small screens.
assertContains(
    $baseDir . '/css/toast.css',
    '/\.toast\s*\{[^}]*max-width:\s*min\(350px,\s*calc\(100vw\s*-\s*40px\)\)/s',
    'toast.css .toast must cap max-width at the viewport on phones'
);

// 10. The main viewport allows user zoom (no maximum-scale or user-scalable=no).
$mainPhp = file_get_contents($baseDir . '/main.php');
$viewportPattern = '/<meta\s+name="viewport"\s+content="[^"]*width=device-width,\s*initial-scale=1\.0[^"]*"/i';
if (!preg_match($viewportPattern, $mainPhp)) {
    $failures[] = 'main.php must contain a standard device-width viewport meta';
} else {
    $passes++;
}

// 11. main.php loads the mobile stylesheet.
if (strpos($mainPhp, 'css/mobile.css') === false) {
    $failures[] = 'main.php must load css/mobile.css';
} else {
    $passes++;
}

// 12. Notification panel markup contains an accessible close control.
if (strpos($mainPhp, 'notificationDropdownClose') === false) {
    $failures[] = 'main.php notification dropdown must contain a close button (notificationDropdownClose)';
} else {
    $passes++;
}

// Report
if ($failures) {
    echo "FAILURES: " . count($failures) . "\n";
    foreach ($failures as $f) {
        echo "- $f\n";
    }
    echo "PASSES: $passes\n";
    exit(1);
}

echo "All $passes responsive CSS audit checks passed.\n";
exit(0);
