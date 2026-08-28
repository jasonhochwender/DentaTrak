<?php
/**
 * Workflow column management API.
 *
 * Allows practice administrators to add, rename, reorder, archive, and
 * restore workflow columns. All rules (min/max counts, protected first/last
 * positions, duplicate names, case moves on archive) are enforced on the
 * server. Scopes every operation to the current practice.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/case-activity-log.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Do not leak HTML warnings/fatals in API responses; they should go to the PHP error log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function ($e) {
    http_response_code(500);
    error_log('[workflow-columns] unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});

$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

// All workflow-column management is admin-only.
requirePracticeAdmin($currentPracticeId);

function requireExpectedPracticeMatch($currentPracticeId, $expected) {
    if ($expected === null || $expected === '') {
        return;
    }
    if ((int)$expected !== (int)$currentPracticeId) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'The active practice changed. Refresh DentaTrak and try again.',
            'diagnosticCode' => 'PRACTICE_CONTEXT_CHANGED'
        ]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireExpectedPracticeMatch($currentPracticeId, $_GET['expectedPracticeId'] ?? null);
    // Return current active and archived columns for the practice.
    $columns = getWorkflowColumnsForPractice($currentPracticeId);
    $resolved = getResolvedWorkflowStageLabelsForPractice($currentPracticeId);

    $active = [];
    $archived = [];
    foreach ($columns as $column) {
        $item = [
            'id' => $column['id'],
            'position' => $column['position'],
            'label' => $column['label'],
            'display' => $resolved[$column['id']] ?? $column['label'],
            'isFirst' => ($column['position'] === 0),
            'isLast' => false,
        ];
        if (!empty($column['archived'])) {
            $archived[] = $item;
        } else {
            $active[] = $item;
        }
    }
    if (!empty($active)) {
        $maxPos = max(array_column($active, 'position'));
        foreach ($active as $i => $item) {
            if ($item['position'] === $maxPos) {
                $active[$i]['isLast'] = true;
            }
        }
    }

    // Optional archive preview: returns the number of affected cases and
    // eligible destination columns without mutating anything. This is a
    // read-only operation used to populate the archive confirmation modal.
    if (isset($_GET['action']) && $_GET['action'] === 'archivePreview' && !empty($_GET['id'])) {
        $preview = handleArchivePreview($columns, $currentPracticeId, (string)$_GET['id']);
        http_response_code($preview['success'] ? 200 : 422);
        echo json_encode($preview);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'resetPreview') {
        $preview = handleResetPreview($columns, $currentPracticeId);
        http_response_code($preview['success'] ? 200 : 422);
        echo json_encode($preview);
        exit;
    }

    echo json_encode([
        'success' => true,
        'columns' => $active,
        'archived' => $archived,
        'minColumns' => WORKFLOW_MIN_COLUMNS,
        'maxColumns' => WORKFLOW_MAX_COLUMNS,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('api.settings.invalid_data')]);
        exit;
    }

    requireExpectedPracticeMatch($currentPracticeId, $data['expectedPracticeId'] ?? null);

    $action = isset($data['action']) ? (string)$data['action'] : '';
    $allowedActions = ['add', 'rename', 'reorder', 'archive', 'restore'];
    if (!in_array($action, $allowedActions, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('settings.workflow_columns.invalid_action')]);
        exit;
    }

    // Begin a transaction and lock the practice row so concurrent admin
    // requests cannot create duplicate positions, duplicate names, or bypass
    // min/max counts.
    $pdo->beginTransaction();

    try {
        $columns = getWorkflowColumnsForPractice($currentPracticeId, true);
        $result = handleWorkflowColumnAction($columns, $currentPracticeId, $data);

        if (!$result['success']) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? t('settings.workflow_columns.save_failed'),
                'errors' => $result['errors'] ?? [],
            ]);
            exit;
        }

        // Persist normalized column list and any label overrides.
        if (!empty($result['columns'])) {
            if (!saveWorkflowColumnsForPractice($currentPracticeId, $result['columns'])) {
                throw new PDOException('Failed to save workflow columns');
            }
        }
        if (isset($result['labelOverrides'])) {
            if (!saveWorkflowStageLabelOverridesForPractice($currentPracticeId, $result['labelOverrides'])) {
                throw new PDOException('Failed to save workflow stage labels');
            }
        }

        $pdo->commit();

        $resolved = getResolvedWorkflowStageLabelsForPractice($currentPracticeId);
        echo json_encode([
            'success' => true,
            'columns' => $result['columns'],
            'resolved' => $resolved,
            'affectedCount' => $result['affectedCount'] ?? 0,
            'message' => $result['message'] ?? t('settings.workflow_columns.saved'),
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[workflow-columns] Transaction error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => t('settings.workflow_columns.save_failed'),
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => t('common.method_not_allowed')]);
exit;

/* ============================================================
   ACTION HANDLERS
   ============================================================ */

function handleWorkflowColumnAction(array $columns, $practiceId, array $data) {
    $action = (string)$data['action'];

    switch ($action) {
        case 'add':
            return handleAddColumn($columns, $data);
        case 'rename':
            return handleRenameColumn($columns, $practiceId, $data);
        case 'reorder':
            return handleReorderColumns($columns, $data);
        case 'archive':
            return handleArchiveColumn($columns, $practiceId, $data);
        case 'restore':
            return handleRestoreColumn($columns, $practiceId, $data);
    }

    return ['success' => false, 'message' => t('settings.workflow_columns.invalid_action')];
}

function getActiveColumns(array $columns) {
    return array_values(array_filter($columns, function ($c) {
        return empty($c['archived']);
    }));
}

function getActiveColumnIds(array $columns) {
    return array_map(function ($c) {
        return $c['id'];
    }, getActiveColumns($columns));
}

function findColumnById(array $columns, $id) {
    foreach ($columns as $i => $c) {
        if ($c['id'] === $id) {
            return ['index' => $i, 'column' => $c];
        }
    }
    return null;
}

function validateColumnName($name) {
    $clean = sanitizeWorkflowStageLabelText($name);
    if ($clean === '') {
        return ['valid' => false, 'message' => t('settings.workflow_columns.name_required')];
    }
    if (mb_strlen($clean) > WORKFLOW_STAGE_LABEL_MAX_LENGTH) {
        return ['valid' => false, 'message' => t('settings.workflow_columns.name_too_long', ['max' => WORKFLOW_STAGE_LABEL_MAX_LENGTH])];
    }
    return ['valid' => true, 'value' => $clean];
}

function nameAlreadyInUse(array $columns, $practiceId, $name, $excludeId = null) {
    $normalized = mb_strtolower($name);

    // Only active columns must have unique display names. Archived columns
    // may share names with active ones; if so, the restore action will
    // require a rename before it can be reactivated.
    $active = getActiveColumns($columns);
    foreach ($active as $c) {
        if ($excludeId !== null && $c['id'] === $excludeId) {
            continue;
        }
        if (mb_strtolower(sanitizeWorkflowStageLabelText($c['label'])) === $normalized) {
            return true;
        }
    }

    // Also compare against resolved active labels (overrides, defaults).
    $resolved = getResolvedWorkflowStageLabelsForPractice($practiceId);
    foreach ($resolved as $id => $label) {
        if ($excludeId !== null && $id === $excludeId) {
            continue;
        }
        if (mb_strtolower(sanitizeWorkflowStageLabelText($label)) === $normalized) {
            return true;
        }
    }

    return false;
}

function handleAddColumn(array $columns, array $data) {
    global $currentPracticeId;
    $active = getActiveColumns($columns);
    if (count($active) >= WORKFLOW_MAX_COLUMNS) {
        return ['success' => false, 'message' => t('settings.workflow_columns.max_reached', ['max' => WORKFLOW_MAX_COLUMNS])];
    }

    $labelResult = validateColumnName($data['label'] ?? '');
    if (!$labelResult['valid']) {
        return ['success' => false, 'message' => $labelResult['message']];
    }

    $practiceId = $currentPracticeId;
    if (nameAlreadyInUse($columns, $practiceId, $labelResult['value'])) {
        return ['success' => false, 'message' => t('settings.workflow_columns.duplicate_name')];
    }

    // New columns get a stable, practice-scoped internal id.
    $newId = 'Custom-' . bin2hex(random_bytes(8));

    // Position the new column just before the current last column.
    usort($active, function ($a, $b) {
        return $a['position'] <=> $b['position'];
    });
    $maxPos = $active[count($active) - 1]['position'];
    $newPosition = max(1, $maxPos);

    // Shift the current last column and anything after it up by one.
    foreach ($columns as $i => $c) {
        if ($c['position'] >= $newPosition) {
            $columns[$i]['position'] = $c['position'] + 1;
        }
    }

    $columns[] = [
        'id' => $newId,
        'label' => $labelResult['value'],
        'position' => $newPosition,
        'archived' => false,
    ];

    // Re-sort and re-assign positions to be 0..N contiguous.
    $columns = reassignPositions($columns);

    return ['success' => true, 'columns' => $columns, 'newId' => $newId];
}

function handleRenameColumn(array $columns, $practiceId, array $data) {
    $id = (string)($data['id'] ?? '');
    $found = findColumnById($columns, $id);
    if (!$found) {
        return ['success' => false, 'message' => t('settings.workflow_columns.not_found')];
    }

    $labelResult = validateColumnName($data['label'] ?? '');
    if (!$labelResult['valid']) {
        return ['success' => false, 'message' => $labelResult['message']];
    }

    if (nameAlreadyInUse($columns, $practiceId, $labelResult['value'], $id)) {
        return ['success' => false, 'message' => t('settings.workflow_columns.duplicate_name')];
    }

    // The label is written to workflow_stage_labels (a display override) so
    // renaming never changes the internal id or any case.status values.
    $overrides = getWorkflowStageLabelOverridesForPractice($practiceId);
    $overrides[$id] = $labelResult['value'];

    return [
        'success' => true,
        'columns' => $columns,
        'labelOverrides' => $overrides,
    ];
}

function handleReorderColumns(array $columns, array $data) {
    $order = isset($data['order']) && is_array($data['order']) ? $data['order'] : [];
    if (empty($order)) {
        return ['success' => false, 'message' => t('settings.workflow_columns.order_required')];
    }

    $active = getActiveColumns($columns);
    if (count($order) !== count($active)) {
        return ['success' => false, 'message' => t('settings.workflow_columns.order_mismatch')];
    }

    $activeIds = array_column($active, 'id');
    if (array_diff($order, $activeIds) || array_diff($activeIds, $order)) {
        return ['success' => false, 'message' => t('settings.workflow_columns.order_invalid')];
    }

    // The first and last ids must stay at positions 0 and N.
    $firstId = $order[0];
    $lastId = $order[count($order) - 1];
    usort($active, function ($a, $b) {
        return $a['position'] <=> $b['position'];
    });
    $origFirstId = $active[0]['id'];
    $origLastId = $active[count($active) - 1]['id'];

    if ($firstId !== $origFirstId) {
        return ['success' => false, 'message' => t('settings.workflow_columns.first_position_protected')];
    }
    if ($lastId !== $origLastId) {
        return ['success' => false, 'message' => t('settings.workflow_columns.last_position_protected')];
    }

    // Assign new contiguous positions based on the provided order.
    $positionMap = array_flip($order);
    foreach ($columns as $i => $c) {
        if (!empty($c['archived'])) {
            continue;
        }
        if (isset($positionMap[$c['id']])) {
            $columns[$i]['position'] = $positionMap[$c['id']];
        }
    }

    $columns = reassignPositions($columns);
    return ['success' => true, 'columns' => $columns];
}

function countCasesInColumn($practiceId, $columnId) {
    global $pdo;

    if (!$pdo || empty($practiceId) || empty($columnId)) {
        return 0;
    }

    $sql = "SELECT COUNT(*) FROM cases_cache WHERE practice_id = :practice_id AND status = :status AND COALESCE(archived, 0) = 0";
    $params = ['practice_id' => $practiceId, 'status' => $columnId];
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[workflow-columns] Error counting cases: ' . $e->getMessage());
        return -1;
    }
}

function handleArchivePreview(array $columns, $practiceId, $columnId) {
    $found = findColumnById($columns, $columnId);
    if (!$found || !empty($found['column']['archived'])) {
        return ['success' => false, 'message' => t('settings.workflow_columns.not_found'), 'diagnosticCode' => 'WORKFLOW_COLUMN_NOT_FOUND'];
    }

    $active = getActiveColumns($columns);
    $first = $active[0]['id'];
    $last = $active[count($active) - 1]['id'];

    if ($columnId === $first || $columnId === $last) {
        return ['success' => false, 'message' => t('settings.workflow_columns.cannot_archive_protected')];
    }

    $affectedCount = countCasesInColumn($practiceId, $columnId);
    if ($affectedCount < 0) {
        return ['success' => false, 'message' => t('settings.workflow_columns.archive_count_failed'), 'diagnosticCode' => 'WORKFLOW_COUNT_QUERY_FAILED'];
    }

    $destinations = [];
    foreach ($active as $col) {
        if ($col['id'] !== $columnId) {
            $destinations[] = [
                'id' => $col['id'],
                'label' => $col['label'],
            ];
        }
    }

    return [
        'success' => true,
        'affectedCount' => $affectedCount,
        'destinations' => $destinations,
    ];
}

function handleResetPreview(array $columns, $practiceId) {
    $defaultOrder = ['Originated', 'Sent To External Lab', 'Designed', 'Manufactured', 'Received From External Lab', 'Delivered'];
    $active = getActiveColumns($columns);
    $affectedCount = 0;
    foreach ($active as $col) {
        if (!in_array($col['id'], $defaultOrder, true)) {
            $affectedCount += countCasesInColumn($practiceId, $col['id']);
        }
    }

    $destinations = [];
    foreach ($active as $col) {
        if (in_array($col['id'], $defaultOrder, true)) {
            $destinations[] = [
                'id' => $col['id'],
                'label' => $col['label'],
            ];
        }
    }

    return [
        'success' => true,
        'affectedCount' => $affectedCount,
        'destinations' => $destinations,
    ];
}

function handleArchiveColumn(array $columns, $practiceId, array $data) {
    global $pdo;

    $id = (string)($data['id'] ?? '');
    $destinationId = isset($data['destinationId']) ? (string)$data['destinationId'] : null;

    $found = findColumnById($columns, $id);
    if (!$found) {
        return ['success' => false, 'message' => t('settings.workflow_columns.not_found')];
    }

    $column = $found['column'];
    if (!empty($column['archived'])) {
        return ['success' => false, 'message' => t('settings.workflow_columns.already_archived')];
    }

    $active = getActiveColumns($columns);
    $first = $active[0]['id'];
    $last = $active[count($active) - 1]['id'];

    if ($id === $first || $id === $last) {
        return ['success' => false, 'message' => t('settings.workflow_columns.cannot_archive_protected')];
    }

    if (count($active) <= WORKFLOW_MIN_COLUMNS) {
        return ['success' => false, 'message' => t('settings.workflow_columns.min_active_required', ['min' => WORKFLOW_MIN_COLUMNS])];
    }

    // Count affected cases in the current practice.
    $affectedCount = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = :practice_id AND status = :status");
        $stmt->execute(['practice_id' => $practiceId, 'status' => $id]);
        $affectedCount = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[workflow-columns] Error counting cases for archive: ' . $e->getMessage());
        return ['success' => false, 'message' => t('settings.workflow_columns.archive_count_failed')];
    }

    if ($affectedCount > 0) {
        if (empty($destinationId)) {
            return [
                'success' => false,
                'message' => t('settings.workflow_columns.destination_required'),
                'errors' => ['destinationId' => t('settings.workflow_columns.destination_required')],
                'affectedCount' => $affectedCount,
            ];
        }

        if ($destinationId === $id || !in_array($destinationId, getActiveColumnIds($columns), true)) {
            return ['success' => false, 'message' => t('settings.workflow_columns.invalid_destination')];
        }

        if ($destinationId === $first || $destinationId === $last) {
            // Destination is the first or last system column. Moving to a
            // system column is allowed but documented in the audit trail.
        }

        // Move the affected cases as a controlled, one-step operation.
        try {
            // Lock the affected rows and capture their ids before the update.
            $caseStmt = $pdo->prepare("
                SELECT case_id FROM cases_cache
                WHERE practice_id = :practice_id AND status = :status
                FOR UPDATE
            ");
            $caseStmt->execute(['practice_id' => $practiceId, 'status' => $id]);
            $movedCaseIds = $caseStmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $pdo->prepare("
                UPDATE cases_cache
                SET status = :destination, status_changed_at = :now
                WHERE practice_id = :practice_id AND status = :status
            ");
            $stmt->execute([
                'destination' => $destinationId,
                'now' => date('Y-m-d H:i:s'),
                'practice_id' => $practiceId,
                'status' => $id,
            ]);

            // Audit-log each moved case. Not a notification-per-case; one
            // consolidated change is sufficient.
            foreach ($movedCaseIds as $caseId) {
                logCaseActivity($caseId, 'status_changed', $id, $destinationId, [
                    'reason' => 'column_archived',
                    'user_id' => $_SESSION['db_user_id'] ?? null,
                ], null, true);
            }
        } catch (PDOException $e) {
            error_log('[workflow-columns] Error moving cases for archive: ' . $e->getMessage());
            return ['success' => false, 'message' => t('settings.workflow_columns.move_failed')];
        }
    }

    $columns[$found['index']]['archived'] = true;
    $columns = reassignPositions($columns);

    return [
        'success' => true,
        'columns' => $columns,
        'affectedCount' => $affectedCount,
        'message' => t('settings.workflow_columns.archived', ['count' => $affectedCount]),
    ];
}

function handleRestoreColumn(array $columns, $practiceId, array $data) {
    $id = (string)($data['id'] ?? '');
    $found = findColumnById($columns, $id);
    if (!$found) {
        return ['success' => false, 'message' => t('settings.workflow_columns.not_found')];
    }

    if (empty($found['column']['archived'])) {
        return ['success' => false, 'message' => t('settings.workflow_columns.not_archived')];
    }

    $active = getActiveColumns($columns);
    if (count($active) >= WORKFLOW_MAX_COLUMNS) {
        return ['success' => false, 'message' => t('settings.workflow_columns.max_reached', ['max' => WORKFLOW_MAX_COLUMNS])];
    }

    // Do not restore a column whose name collides with an active one.
    // Archived labels are preserved, but their names must not clash with
    // the active board.
    $restoreName = $found['column']['label'];
    $overrides = getWorkflowStageLabelOverridesForPractice($practiceId);
    if (isset($overrides[$id]) && $overrides[$id] !== '') {
        $restoreName = $overrides[$id];
    }
    if (nameAlreadyInUse($columns, $practiceId, $restoreName, $id)) {
        return ['success' => false, 'message' => t('settings.workflow_columns.duplicate_name')];
    }

    // Restore just before the last active column.
    usort($active, function ($a, $b) {
        return $a['position'] <=> $b['position'];
    });
    $maxPos = $active[count($active) - 1]['position'];

    foreach ($columns as $i => $c) {
        if ($c['position'] >= $maxPos) {
            $columns[$i]['position'] = $c['position'] + 1;
        }
    }

    $columns[$found['index']]['archived'] = false;
    $columns[$found['index']]['position'] = $maxPos;
    $columns = reassignPositions($columns);

    return ['success' => true, 'columns' => $columns];
}

function reassignPositions(array $columns) {
    $active = [];
    $archived = [];
    foreach ($columns as $c) {
        if (empty($c['archived'])) {
            $active[] = $c;
        } else {
            $archived[] = $c;
        }
    }

    usort($active, function ($a, $b) {
        return $a['position'] <=> $b['position'];
    });

    usort($archived, function ($a, $b) {
        return $a['position'] <=> $b['position'];
    });

    foreach ($active as $i => $c) {
        $active[$i]['position'] = $i;
    }
    foreach ($archived as $i => $c) {
        $archived[$i]['position'] = $i;
    }

    return array_merge($active, $archived);
}
