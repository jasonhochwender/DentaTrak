<?php
/**
 * Workflow practice-context synchronization audit tests.
 * Run with: php tests/workflow-practice-context-test.php
 */

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

$stagesSource = file_get_contents(__DIR__ . '/../api/workflow-stages.php');
$columnsSource = file_get_contents(__DIR__ . '/../api/workflow-columns.php');
$saveSource = file_get_contents(__DIR__ . '/../api/save-settings.php');
$getSettingsSource = file_get_contents(__DIR__ . '/../api/get-settings.php');
$mainSource = file_get_contents(__DIR__ . '/../main.php');
$uiSource = file_get_contents(__DIR__ . '/../js/workflow-draft-ui.js');
$appSource = file_get_contents(__DIR__ . '/../js/app.js');

// Server-side snapshots include practice identity.
check('buildWorkflowColumnsSnapshot returns practiceId', strpos($stagesSource, "'practiceId' => (int)\$practiceId") !== false);
check('get-settings.php returns currentPracticeId', strpos($getSettingsSource, "'currentPracticeId' => (int)\$currentPracticeId,") !== false);
check('main.php exposes window.currentPracticeId', strpos($mainSource, 'window.currentPracticeId = ') !== false);

// Endpoints require expectedPracticeId to match session.
check('workflow-columns.php has requireExpectedPracticeMatch', strpos($columnsSource, 'function requireExpectedPracticeMatch') !== false);
check('workflow-columns.php checks GET expectedPracticeId', strpos($columnsSource, "requireExpectedPracticeMatch(\$currentPracticeId, \$_GET['expectedPracticeId'] ?? null)") !== false);
check('workflow-columns.php checks POST expectedPracticeId', strpos($columnsSource, "requireExpectedPracticeMatch(\$currentPracticeId, \$data['expectedPracticeId'] ?? null)") !== false);
check('save-settings.php checks expectedPracticeId for workflowColumns', strpos($saveSource, "isset(\$data['expectedPracticeId']) && (int)\$data['expectedPracticeId'] !== (int)\$currentPracticeId") !== false);

// Server returns 409 PRACTICE_CONTEXT_CHANGED on mismatch.
check('workflow-columns.php returns 409 PRACTICE_CONTEXT_CHANGED', strpos($columnsSource, "http_response_code(409);") !== false && strpos($columnsSource, "'diagnosticCode' => 'PRACTICE_CONTEXT_CHANGED'") !== false);

// Client sends expectedPracticeId and handles diagnostics.
check('workflow-draft-ui.js fetchArchivePreview sends expectedPracticeId', strpos($uiSource, "'&expectedPracticeId=' + encodeURIComponent(window.workflowColumnsSnapshot.practiceId)") !== false);
check('workflow-draft-ui.js fetchResetPreview sends expectedPracticeId', strpos($uiSource, "function fetchResetPreview") !== false && strpos($uiSource, "'&expectedPracticeId=' + encodeURIComponent(window.workflowColumnsSnapshot.practiceId)") !== false);
check('workflow-draft-ui.js makeWorkflowError includes diagnosticCode', strpos($uiSource, "err.diagnosticCode = diagnosticCode;") !== false);
check('workflow-draft-ui.js shows actual server message', strpos($uiSource, "message = (error && error.message) ? error.message : fallback;") !== false);
check('workflow-draft-ui.js maps PRACTICE_CONTEXT_CHANGED', strpos($uiSource, "'PRACTICE_CONTEXT_CHANGED'") !== false);
check('workflow-draft-ui.js getWorkflowSnapshotForDraft checks practiceId', strpos($uiSource, "getWorkflowSnapshotForDraft") !== false);

// Save Settings sends expectedPracticeId.
check('app.js save sends expectedPracticeId', strpos($appSource, "expectedPracticeId: window.currentPracticeId") !== false);

// applyUserSettings discards mismatched workflow snapshot.
check('app.js applyUserSettings compares server practice', strpos($appSource, 'pagePracticeId') !== false && strpos($appSource, 'serverPracticeId') !== false);

echo "\n$passed passed, " . ($tests - $passed) . " failed\n";
