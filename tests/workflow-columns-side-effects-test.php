<?php
/**
 * Workflow Columns side-effect audit tests.
 *
 * These are static code-audits because the service requires a live database
 * connection and session to run end-to-end. They verify that the only case
 * mutation paths in the service are the explicit archive/reset blocks and that
 * workflow constants are available from the shared workflow-stages.php file.
 */

$stagesFile = __DIR__ . '/../api/workflow-stages.php';
require_once $stagesFile;

$serviceFile = __DIR__ . '/../api/workflow-columns-service.php';
$source = file_get_contents($serviceFile);

if ($source === false) {
    echo "FAIL: Could not read $serviceFile\n";
    exit(1);
}

$tests = 0;
$passed = 0;

function check($name, $condition) {
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        echo "PASS: $name\n";
    } else {
        echo "FAIL: $name\n";
    }
}

// Workflow constants are defined in workflow-stages.php and available to the service.
check('WORKFLOW_MIN_COLUMNS is defined', defined('WORKFLOW_MIN_COLUMNS'));
check('WORKFLOW_MAX_COLUMNS is defined', defined('WORKFLOW_MAX_COLUMNS'));
check('WORKFLOW_MIN_COLUMNS equals 3', WORKFLOW_MIN_COLUMNS === 3);
check('WORKFLOW_MAX_COLUMNS equals 10', WORKFLOW_MAX_COLUMNS === 10);

// The active-column validation/persistence block must not touch cases_cache.
$activeBlockEnd = strpos($source, '// Move cases for archived columns');
$activeBlock = $activeBlockEnd !== false ? substr($source, 0, $activeBlockEnd) : '';
check('Active column persistence does not UPDATE cases_cache', $activeBlockEnd !== false && strpos($activeBlock, 'cases_cache') === false);

// The archive and reset blocks are the only places that may update cases_cache.
$archiveBlockStart = strpos($source, '// Move cases for archived columns');
$resetBlockEnd = strpos($source, '// Clear custom label overrides for canonical columns on reset');
$resetBlockEnd = $resetBlockEnd !== false ? $resetBlockEnd + 100 : false;
check('Archive/reset blocks exist', $archiveBlockStart !== false && $resetBlockEnd !== false);

$archiveAndReset = ($archiveBlockStart !== false && $resetBlockEnd !== false)
    ? substr($source, $archiveBlockStart, $resetBlockEnd - $archiveBlockStart)
    : '';
check('Archive/reset blocks call logCaseActivity', strpos($archiveAndReset, "logCaseActivity(") !== false);

check('Archive/reset blocks set status_changed_at', strpos($archiveAndReset, 'status_changed_at') !== false);
check('Archive/reset blocks do not increment revision_count', strpos($archiveAndReset, 'revision_count') === false);
check('Archive/reset blocks do not call update-case-status endpoint', strpos($archiveAndReset, 'update-case-status') === false);

// Reorder must not log activity.
check('Active-only path does not call logCaseActivity', strpos($activeBlock, "logCaseActivity(") === false);

// No notification creation in this service.
check('Service does not create notifications', strpos($source, 'createNotification') === false && strpos($source, 'Notification') === false);

echo "\n$passed passed, " . ($tests - $passed) . " failed\n";
