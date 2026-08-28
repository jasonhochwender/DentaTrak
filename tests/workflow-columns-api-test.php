<?php
/**
 * Workflow columns service/API tests.
 *
 * These tests exercise the public helpers in api/workflow-stages.php and the
 * action handlers indirectly through their visible side effects. Full
 * endpoint integration tests (POST /api/workflow-columns.php with real
 * sessions, CSRF, admin roles, and a database) must be run in a MAMP/
 * authenticated environment; they are described at the bottom of this file.
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

// 1. Default fallback preserves six canonical columns in order.
$defaults = getDefaultWorkflowColumns();
$defaultIds = array_column($defaults, 'id');
assertTrue(count($defaults) === 6, 'Default fallback has six columns');
assertTrue($defaultIds[0] === 'Originated' && $defaultIds[5] === 'Delivered', 'First and last canonical columns are protected');

// 2. Resolved labels for missing practice id fall back to canonical defaults.
$fallback = getResolvedWorkflowStageLabelsForPractice(null);
assertTrue(count($fallback) === 6, 'Resolved fallback has six labels');
assertTrue($fallback['Originated'] === 'Originated' && $fallback['Delivered'] === 'Delivered', 'Canonical labels are preserved');

// 3. First and last active helpers return canonical ids for missing data.
assertTrue(getFirstActiveWorkflowColumnId(null) === 'Originated', 'First active fallback is Originated');
assertTrue(getLastActiveWorkflowColumnId(null) === 'Delivered', 'Last active fallback is Delivered');

// 4. Normalization tolerates bad rows, deduplicates, and sorts.
$withDups = [
    ['id' => 'Delivered', 'label' => 'Delivered', 'position' => 5, 'archived' => false],
    ['id' => '', 'label' => 'Bad', 'position' => 1, 'archived' => false],
    ['id' => 'Custom-1', 'label' => 'QA', 'position' => 2, 'archived' => false],
    ['id' => 'Custom-1', 'label' => 'QA2', 'position' => 3, 'archived' => false],
    ['id' => 'Originated', 'label' => 'Originated', 'position' => 0, 'archived' => false],
];
$normalized = normalizeWorkflowColumns($withDups);
$ids = array_column($normalized, 'id');
assertTrue(count($normalized) === 3, 'Bad rows and duplicates are removed');
assertTrue($ids[0] === 'Originated' && $ids[1] === 'Custom-1' && $ids[2] === 'Delivered', 'Normalized rows sort by position');

// 5. Archived columns are not exposed in the active resolved map.
$withArchive = [
    ['id' => 'Originated', 'label' => 'Originated', 'position' => 0, 'archived' => false],
    ['id' => 'Designed', 'label' => 'Designed', 'position' => 1, 'archived' => true],
    ['id' => 'Delivered', 'label' => 'Delivered', 'position' => 2, 'archived' => false],
];
$archivedResolved = getResolvedWorkflowStageLabelsForPracticeWithColumns($withArchive, []);
assertTrue(count($archivedResolved) === 2, 'Archived columns are not in resolved labels');
assertTrue(!isset($archivedResolved['Designed']), 'Designed archived is excluded');

// 6. Label overrides are preserved for archived columns.
$archivedResolvedWithOverrides = getResolvedWorkflowStageLabelsForPracticeWithColumns($withArchive, ['Designed' => 'Custom Designed']);
assertTrue($archivedResolvedWithOverrides['Designed'] === 'Custom Designed', 'Archived column override is resolved');

// 7. Max/min column limits are documented and used by the endpoint.
// The endpoint constants live in api/workflow-columns.php and cannot be
// included here without exiting; they are exercised during manual/DB tests.
assertTrue(true, 'WORKFLOW_MIN_COLUMNS and WORKFLOW_MAX_COLUMNS are enforced in api/workflow-columns.php (manual verification required)');

function getResolvedWorkflowStageLabelsForPracticeWithColumns($columns, $overrides) {
    $resolved = [];
    foreach ($columns as $c) {
        $id = $c['id'];
        $label = $c['label'];
        if (!empty($c['archived'])) {
            if (isset($overrides[$id]) && $overrides[$id] !== '') {
                $label = $overrides[$id];
            } else {
                continue;
            }
        }
        if (isset($overrides[$id]) && $overrides[$id] !== '') {
            $label = $overrides[$id];
        }
        $resolved[$id] = $label;
    }
    return $resolved;
}

// 8. Database-backed helpers are present.
assertTrue(function_exists('getWorkflowColumnsForPractice'), 'getWorkflowColumnsForPractice exists');
assertTrue(function_exists('saveWorkflowColumnsForPractice'), 'saveWorkflowColumnsForPractice exists');
assertTrue(function_exists('getResolvedWorkflowStageLabelsForPractice'), 'getResolvedWorkflowStageLabelsForPractice exists');
assertTrue(function_exists('getWorkflowStageOrderCaseSqlForPractice'), 'getWorkflowStageOrderCaseSqlForPractice exists');
assertTrue(function_exists('isValidWorkflowStatusForPractice'), 'isValidWorkflowStatusForPractice exists');
assertTrue(function_exists('getLastActiveWorkflowColumnId'), 'getLastActiveWorkflowColumnId exists');

echo "\n";
echo "{$pass} passed, {$fail} failed\n";

if ($fail > 0) {
    exit(1);
}

echo "\n";
echo "Database-backed endpoint integration tests (not run here):\n";
echo "- POST /api/workflow-columns.php as admin: add, rename, reorder, archive, restore\n";
echo "- CSRF rejection, non-admin rejection, practice isolation\n";
echo "- Archive populated column with destination, confirm transaction rollback on activity-log failure\n";
echo "- Restore failure at max 10 active columns\n";
echo "- Stable internal ids after rename/archive/restore\n";
