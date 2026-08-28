<?php
/**
 * Workflow columns save service.
 *
 * Transactionally persists a complete workflow draft from the Settings form.
 * Revalidates the entire proposed configuration, moves affected cases, and
 * records activity in a single database transaction.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/lab-assignment-history.php';

/**
 * Save a complete workflow columns draft.
 *
 * @param string $practiceId
 * @param int    $userId
 * @param array  $draft
 * @param array  $labelOverrides
 * @return array
 */
function saveWorkflowColumnsDraft($practiceId, $userId, array $draft) {
    global $pdo;

    if (!$pdo) {
        return ['success' => false, 'message' => t('settings.workflow_columns.save_failed')];
    }

    $workflowSaveStage = 'start';

    $active = $draft['active'] ?? [];
    $archived = $draft['archived'] ?? [];
    $labelOverrides = $draft['labelOverrides'] ?? [];
    $archiveDestinations = $draft['archiveDestinations'] ?? [];
    $resetPending = !empty($draft['resetPending']);
    $resetDestination = $draft['resetDestination'] ?? null;
    $baseVersion = $draft['baseVersion'] ?? '';

    $activeCount = count($active);
    if ($activeCount < WORKFLOW_MIN_COLUMNS) {
        return ['success' => false, 'message' => t('settings.workflow_columns.min_active_required', ['min' => WORKFLOW_MIN_COLUMNS])];
    }
    if ($activeCount > WORKFLOW_MAX_COLUMNS) {
        return ['success' => false, 'message' => t('settings.workflow_columns.max_reached', ['max' => WORKFLOW_MAX_COLUMNS])];
    }

    if (function_exists('ensureCaseActivityLogTable')) {
        ensureCaseActivityLogTable();
    }

    try {
        $workflowSaveStage = 'lock_practice';
        $pdo->beginTransaction();

        $workflowSaveStage = 'validate_fingerprint';
        $existing = getWorkflowColumnsForPractice($practiceId, true);
        $fingerprint = md5(json_encode($existing));
        if ($fingerprint !== $baseVersion) {
            $pdo->rollBack();
            return ['success' => false, 'message' => t('settings.workflow_columns.stale_configuration')];
        }

        $workflowSaveStage = 'validate_columns';
        $resolvedDefaults = getWorkflowStageDefaultLabels();

        // Ensure first and last are canonical or protected.
        $first = $active[0] ?? null;
        $last = $active[$activeCount - 1] ?? null;
        if (!$first || !$last) {
            $pdo->rollBack();
            return ['success' => false, 'message' => t('settings.workflow_columns.min_active_required', ['min' => WORKFLOW_MIN_COLUMNS])];
        }

        $canonical = ['Originated', 'Sent To External Lab', 'Designed', 'Manufactured', 'Received From External Lab', 'Delivered'];
        if (!in_array($first['id'], $canonical, true) && !isCustomId($first['id'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => t('settings.workflow_columns.first_position_protected')];
        }
        if (!in_array($last['id'], $canonical, true) && !isCustomId($last['id'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => t('settings.workflow_columns.last_position_protected')];
        }

        $terminalId = $last['id'];

        // Convert temporary IDs to permanent Custom- IDs.
        $workflowSaveStage = 'assign_ids';
        $idMapping = [];
        $newColumns = [];
        $seenNames = [];
        foreach ($active as $i => $col) {
            $id = $col['id'];
            $label = trim($col['label'] ?? $col['display'] ?? '');
            if (empty($label)) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'Column ' . ($i + 1) . ' (' . $id . ') requires a name.',
                    'field' => 'workflowColumns',
                    'invalidId' => $id,
                    'invalidPosition' => $i
                ];
            }
            if (mb_strlen($label) > 40) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('settings.workflow_columns.name_too_long', ['max' => 40])];
            }
            $normalized = mb_strtolower($label);
            if (isset($seenNames[$normalized])) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('settings.workflow_columns.duplicate_name')];
            }
            $seenNames[$normalized] = true;

            if (preg_match('/^Temp-/', $id)) {
                $newId = generateWorkflowColumnId($practiceId);
                $idMapping[$id] = $newId;
                $id = $newId;
            }

            $newColumns[] = [
                'id' => $id,
                'label' => $label,
                'position' => $i,
                'colorKey' => (int)($col['colorKey'] ?? 0),
                'archived' => false,
            ];
        }

        foreach ($archived as $i => $col) {
            $id = $col['id'];
            $label = trim($col['label'] ?? $col['display'] ?? '');
            if (empty($label)) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'Archived column ' . ($i + 1) . ' (' . $id . ') requires a name.',
                    'field' => 'workflowColumns',
                    'invalidId' => $id,
                    'invalidPosition' => $i
                ];
            }
            if (preg_match('/^Temp-/', $id)) {
                if (isset($idMapping[$id])) {
                    $id = $idMapping[$id];
                } else {
                    $id = generateWorkflowColumnId($practiceId);
                    $idMapping[$col['id']] = $id;
                }
            }
            $newColumns[] = [
                'id' => $id,
                'label' => $label,
                'position' => $i + $activeCount,
                'colorKey' => (int)($col['colorKey'] ?? 0),
                'archived' => true,
            ];
        }

        // Apply mapped IDs to archive destinations.
        $workflowSaveStage = 'process_archives';
        $mappedArchiveDestinations = [];
        foreach ($archiveDestinations as $sourceId => $destId) {
            $mappedSource = $idMapping[$sourceId] ?? $sourceId;
            $mappedDest = $idMapping[$destId] ?? $destId;
            $mappedArchiveDestinations[$mappedSource] = $mappedDest;
        }

        // Move cases for archived columns and log each status change.
        foreach ($mappedArchiveDestinations as $sourceId => $destId) {
            if (!in_array($sourceId, array_column($newColumns, 'id'), true)) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('settings.workflow_columns.not_found')];
            }
            if (!in_array($destId, array_column($newColumns, 'id'), true)) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('settings.workflow_columns.invalid_destination')];
            }

            $caseIds = fetchCaseIdsForStatus($practiceId, $sourceId);

            $now = date('Y-m-d H:i:s');
            $moveSql = "UPDATE cases_cache
                        SET status = :dest,
                            status_changed_at = :now_changed,
                            last_update_date = :now_update
                        WHERE practice_id = :pid AND status = :src AND archived = 0";
            $moveStmt = $pdo->prepare($moveSql);
            $moveStmt->execute([':dest' => $destId, ':now_changed' => $now, ':now_update' => $now, ':pid' => $practiceId, ':src' => $sourceId]);

            foreach ($caseIds as $caseId) {
                logCaseActivity($caseId, 'status_changed', $sourceId, $destId, ['reason' => 'column_archived'], null, true);
                if ($destId === $terminalId) {
                    closeOpenLabPeriodForDeliveredCase($caseId, $practiceId);
                } elseif ($sourceId === $terminalId && $destId !== $terminalId) {
                    reopenLabPeriodOnDeliveredRegression($caseId, $practiceId, $sourceId, $destId);
                }
            }
        }

        // Reset: move active cases from custom columns that are no longer active.
        $workflowSaveStage = 'process_reset';
        if ($resetPending && !empty($resetDestination)) {
            if (!in_array($resetDestination, $canonical, true)) {
                $pdo->rollBack();
                return ['success' => false, 'message' => t('settings.workflow_columns.invalid_destination')];
            }
            $activeIds = array_column($newColumns, 'id');
            $customActiveIds = array_filter($activeIds, 'isCustomId');
            $existingActiveIds = array_column(array_filter($existing, function ($c) { return empty($c['archived']); }), 'id');
            $removedIds = array_diff($existingActiveIds, $activeIds);
            $toMove = array_unique(array_merge($removedIds, $customActiveIds));

            foreach ($toMove as $sourceId) {
                $caseIds = fetchCaseIdsForStatus($practiceId, $sourceId);

                $now = date('Y-m-d H:i:s');
                $moveSql = "UPDATE cases_cache
                            SET status = :dest,
                                status_changed_at = :now_changed,
                                last_update_date = :now_update
                            WHERE practice_id = :pid AND status = :src AND archived = 0";
                $moveStmt = $pdo->prepare($moveSql);
                $moveStmt->execute([':dest' => $resetDestination, ':now_changed' => $now, ':now_update' => $now, ':pid' => $practiceId, ':src' => $sourceId]);

                foreach ($caseIds as $caseId) {
                    logCaseActivity($caseId, 'status_changed', $sourceId, $resetDestination, ['reason' => 'workflow_reset'], null, true);
                    if ($resetDestination === $terminalId) {
                        closeOpenLabPeriodForDeliveredCase($caseId, $practiceId);
                    } elseif ($sourceId === $terminalId && $resetDestination !== $terminalId) {
                        reopenLabPeriodOnDeliveredRegression($caseId, $practiceId, $sourceId, $resetDestination);
                    }
                }
            }

            // Clear custom label overrides for canonical columns on reset.
            foreach ($canonical as $id) {
                if (isset($labelOverrides[$id])) {
                    unset($labelOverrides[$id]);
                }
            }
        }

        // Save columns and label overrides.
        $workflowSaveStage = 'save_columns';
        if (!saveWorkflowColumnsForPractice($practiceId, $newColumns)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => t('settings.workflow_columns.save_failed')];
        }

        $workflowSaveStage = 'save_labels';
        if (!saveWorkflowStageLabelOverridesForPractice($practiceId, $labelOverrides)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => t('settings.workflow_columns.save_failed')];
        }

        $workflowSaveStage = 'commit';
        $pdo->commit();
        return [
            'success' => true,
            'message' => t('settings.workflow_columns.saved'),
            'idMapping' => $idMapping
        ];
    } catch (Throwable $e) {
        $inTransaction = $pdo->inTransaction();
        $rolledBack = false;
        if ($inTransaction) {
            try {
                $pdo->rollBack();
                $rolledBack = true;
            } catch (PDOException $rollbackEx) {
                $rolledBack = false;
            }
        }

        return [
            'success' => false,
            'message' => t('settings.workflow_columns.save_failed'),
            'diagnosticCode' => 'WORKFLOW_SAVE_EXCEPTION',
            'diagnosticStage' => $workflowSaveStage,
            'diagnosticType' => get_class($e),
            'diagnosticMessage' => $e->getMessage()
        ];
    }
}

function isCustomId($id) {
    return is_string($id) && strpos($id, 'Custom-') === 0;
}

function fetchCaseIdsForStatus($practiceId, $status) {
    global $pdo;
    if (!$pdo) return [];
    $stmt = $pdo->prepare("SELECT id FROM cases_cache WHERE practice_id = :pid AND status = :status AND archived = 0");
    $stmt->execute([':pid' => $practiceId, ':status' => $status]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function generateWorkflowColumnId($practiceId) {
    return 'Custom-' . substr(md5(uniqid($practiceId . '_', true)), 0, 12);
}

