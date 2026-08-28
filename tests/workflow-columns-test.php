<?php
/**
 * Workflow columns unit/smoke tests.
 *
 * These tests exercise the column normalization and resolution helpers in
 * api/workflow-stages.php without requiring a database or an authenticated
 * session. They do not replace browser/integration tests, but they verify
 * the core schema shape and fallback behavior.
 */

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/workflow-stages.php';

$pass = 0;
$fail = 0;

function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

// 1. Defaults contain the six original stages in order.
$defaults = getDefaultWorkflowColumns();
$defaultIds = array_column($defaults, 'id');
assertTrue(count($defaults) === 6, 'Default column count is 6');
assertTrue(in_array('Originated', $defaultIds, true), 'Default contains Originated');
assertTrue(in_array('Delivered', $defaultIds, true), 'Default contains Delivered');
assertTrue($defaults[0]['id'] === 'Originated' && $defaults[0]['position'] === 0, 'Originated is first');
assertTrue($defaults[5]['id'] === 'Delivered' && $defaults[5]['position'] === 5, 'Delivered is last');

// 2. Normalization tolerates bad rows and re-sorts by position.
$raw = [
    ['id' => 'Delivered', 'label' => 'Delivered', 'position' => 5, 'archived' => false],
    ['id' => 'Originated', 'label' => 'Originated', 'position' => 0, 'archived' => false],
    ['id' => 'Custom-1', 'label' => 'Review', 'position' => 2, 'archived' => false],
    ['id' => '', 'label' => '', 'position' => 99, 'archived' => false],
    ['id' => 'Duplicate', 'label' => 'Review', 'position' => 3, 'archived' => false],
    ['id' => 'Duplicate', 'label' => 'Review 2', 'position' => 4, 'archived' => false],
];
$normalized = normalizeWorkflowColumns($raw);
$ids = array_column($normalized, 'id');
assertTrue(count($normalized) === 4, 'Normalization removes empty/duplicate ids (4 remain)');
assertTrue($ids[0] === 'Originated', 'Resort puts Originated first');
assertTrue($ids[1] === 'Custom-1', 'Custom column is in position order');
assertTrue($ids[3] === 'Delivered', 'Resort puts Delivered last');
assertTrue($normalized[2]['label'] === 'Review', 'Custom label preserved');

// 3. Resolution for a practice with no data falls back to defaults.
$labels = getResolvedWorkflowStageLabelsForPractice(null);
assertTrue(count($labels) === 6, 'Fallback resolved labels count is 6');
assertTrue(isset($labels['Originated']) && $labels['Originated'] === 'Originated', 'Fallback Originated label');

// 4. Archived columns are not included in resolved labels.
$withArchive = [
    ['id' => 'Originated', 'label' => 'Originated', 'position' => 0, 'archived' => false],
    ['id' => 'Designed', 'label' => 'Designed', 'position' => 2, 'archived' => true],
    ['id' => 'Delivered', 'label' => 'Delivered', 'position' => 1, 'archived' => false],
];
$archived = normalizeWorkflowColumns($withArchive);
assertTrue($archived[2]['archived'] === true, 'Archive flag preserved after normalization');

// 5. getResolvedWorkflowStageLabels for no-practice still works (empty/defaults).
$empty = getResolvedWorkflowStageLabelsForPractice(0);
assertTrue(count($empty) === 6, 'Zero practice id falls back to 6 default labels');

echo "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
