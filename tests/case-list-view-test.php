<?php
/**
 * Static verification for the Case List View feature.
 *
 * Verifies that the List View module, markup, styles, refresh hooks, and
 * locale keys are all wired correctly. Does not require a browser; reads
 * the source files and checks for the expected integration points.
 *
 * Run: php tests/case-list-view-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$baseDir = __DIR__ . '/..';
$failures = [];
$passes = 0;

function assertContains(string $path, string $pattern, string $message): void
{
    global $failures, $passes;
    $content = file_exists($path) ? file_get_contents($path) : false;
    if ($content === false || !preg_match($pattern, $content)) {
        $failures[] = $message;
    } else {
        $passes++;
    }
}

/* ---------- main.php wiring ---------- */

assertContains(
    $baseDir . '/main.php',
    '/id="boardViewToggle"/',
    'main.php must render the Board view toggle button'
);
assertContains(
    $baseDir . '/main.php',
    '/id="listViewToggle"/',
    'main.php must render the List view toggle button'
);
assertContains(
    $baseDir . '/main.php',
    '/id="caseListView"/',
    'main.php must render the #caseListView container'
);
assertContains(
    $baseDir . '/main.php',
    '/js\/case-list\.js\?v=/',
    'main.php must load js/case-list.js'
);
assertContains(
    $baseDir . '/main.php',
    '/css\/case-list\.css\?v=/',
    'main.php must load css/case-list.css'
);

/* ---------- case-list.js module ---------- */

assertContains(
    $baseDir . '/js/case-list.js',
    '/window\.caseListView\s*=/',
    'case-list.js must expose window.caseListView'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/addEventListener\(\s*.cardsLoaded./',
    'case-list.js must listen for the cardsLoaded event'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/addEventListener\(\s*.cardsUpdated./',
    'case-list.js must listen for the cardsUpdated event'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/editCaseHandler/',
    'case-list.js must open cases via the shared editCaseHandler card path'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/openCaseById/',
    'case-list.js must keep openCaseById as the archived/missing-card fallback'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/updateCaseReviewStatus/',
    'case-list.js must reuse window.updateCaseReviewStatus for review chips'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/dataset\.caseJson/',
    'case-list.js must project case data from .kanban-card dataset.caseJson'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/case-review-tracking-off/',
    'case-list.js must gate the Review column on the tracking feature flag'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/caseViewMode_/',
    'case-list.js must persist the selected view per user in localStorage'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/aria-expanded/',
    'case-list.js expand control must expose aria-expanded'
);
assertContains(
    $baseDir . '/js/case-list.js',
    '/aria-sort/',
    'case-list.js sort headers must expose aria-sort'
);

/* ---------- app.js refresh hooks ---------- */

assertContains(
    $baseDir . '/js/app.js',
    '/applyCaseReviewTrackingEnabled[\s\S]*caseListView\.scheduleRefresh/',
    'applyCaseReviewTrackingEnabled must schedule a List View refresh'
);
assertContains(
    $baseDir . '/js/app.js',
    '/applyReviewStateToCard[\s\S]*triggerCardsUpdated/',
    'applyReviewStateToCard must trigger cardsUpdated so the list refreshes'
);

/* ---------- realtime-updates.js refresh hooks ---------- */

$rt = file_get_contents($baseDir . '/js/realtime-updates.js');
$triggerCount = substr_count($rt, 'triggerCardsUpdated');
if ($triggerCount >= 3) {
    $passes++;
} else {
    $failures[] = 'realtime-updates.js must call triggerCardsUpdated for card update/move/remove (found ' . $triggerCount . ')';
}

/* ---------- case-list.css ---------- */

assertContains(
    $baseDir . '/css/case-list.css',
    '/\.case-view-list\s+\.kanban-board/',
    'case-list.css must hide .kanban-board when List View is active'
);
assertContains(
    $baseDir . '/css/case-list.css',
    '/\.case-view-list\s+\.mobile-kanban-nav/',
    'case-list.css must hide .mobile-kanban-nav when List View is active'
);
assertContains(
    $baseDir . '/css/case-list.css',
    '/\.case-list-table/',
    'case-list.css must style .case-list-table'
);
assertContains(
    $baseDir . '/css/case-list.css',
    '/@media\s*\(max-width:\s*768px\)/',
    'case-list.css must include a tablet/mobile stacked-row treatment'
);
assertContains(
    $baseDir . '/css/case-list.css',
    '/\.case-list-expand/',
    'case-list.css must style the expand chevron control'
);

/* ---------- locales ---------- */

assertContains(
    $baseDir . '/locales/en-US.json',
    '/"list"\s*:\s*\{[^}]*"view_board"/s',
    'en-US.json must define cases.list.view_board'
);
assertContains(
    $baseDir . '/locales/en-US.json',
    '/"list"\s*:\s*\{[^}]*"empty_filtered"/s',
    'en-US.json must define cases.list.empty_filtered'
);

/* ---------- Report ---------- */

foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "{$passes} passed, " . count($failures) . " failed\n";
exit(count($failures) > 0 ? 1 : 0);
