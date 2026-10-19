<?php
/**
 * Lab Acknowledgment
 *
 * Optional practice-level workflow: when enabled (Settings > Display &
 * Behavior > "Lab acknowledgment"), assigning a case to a Lab-designated
 * user or Assignment Label marks the case "Awaiting acknowledgment". The
 * lab can then confirm receipt (optionally with a promised return date)
 * or request missing information; practice staff can record a phone/email
 * confirmation received outside the app.
 *
 * IMPORTANT DESIGN RULES (do not violate when extending this file):
 *  - Acknowledgment is NOT a workflow stage. It never touches cases_cache
 *    .status, never blocks progress, and never implies a case is "safe".
 *  - Acknowledgment is scoped to the assigned lab identity (user:<id> or
 *    label:<id> - the same stable keys used by lab-assignment-history.php).
 *    Reassigning to a different lab supersedes the previous lab's record;
 *    it must never carry over as the new lab's commitment.
 *  - Lab-promised return date (cases_cache.lab_promised_date) is distinct
 *    from the practice-requested due_date and the Open Dental-owned
 *    patient_appointment_date. Integration sync must never write it.
 *  - Disabling the practice setting hides UI/notifications but preserves
 *    all recorded rows and history. Re-enabling never backfills or resends.
 *  - This module intentionally never opens/commits its own DB transaction -
 *    callers control transaction boundaries (same convention as
 *    lab-assignment-history.php).
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/case-activity-log.php';

// Valid statuses for the denormalized mirror on cases_cache.lab_ack_status
// and case_lab_acknowledgments.status. 'superseded' is terminal history.
define('LAB_ACK_STATUSES', ['awaiting', 'needs_info', 'confirmed', 'superseded']);

/**
 * Self-healing schema: case_lab_acknowledgments table +
 * practices.lab_acknowledgment_enabled + cases_cache.lab_ack_status /
 * lab_promised_date columns. Idempotent, matching the existing
 * SHOW COLUMNS/ALTER convention used throughout this codebase.
 */
function ensureLabAcknowledgmentSchema() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS case_lab_acknowledgments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        lab_key VARCHAR(64) NOT NULL COMMENT 'Stable identity: user:<id> or label:<id>',
        lab_assignee_type ENUM('user','label') NOT NULL,
        lab_user_id BIGINT UNSIGNED DEFAULT NULL,
        lab_label_id BIGINT UNSIGNED DEFAULT NULL,
        lab_display_name VARCHAR(255) NOT NULL,
        status ENUM('awaiting','needs_info','confirmed','superseded') NOT NULL DEFAULT 'awaiting',
        promised_return_date VARCHAR(50) DEFAULT NULL,
        promised_by_user_id BIGINT UNSIGNED DEFAULT NULL,
        promised_at DATETIME DEFAULT NULL,
        info_requested_by BIGINT UNSIGNED DEFAULT NULL,
        info_requested_at DATETIME DEFAULT NULL,
        info_request_comment_id BIGINT UNSIGNED DEFAULT NULL,
        confirmed_by_user_id BIGINT UNSIGNED DEFAULT NULL,
        confirmed_by_name VARCHAR(255) DEFAULT NULL,
        confirmed_at DATETIME DEFAULT NULL,
        confirmation_source ENUM('lab','staff') DEFAULT NULL,
        confirmation_method ENUM('portal','phone','email') DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        superseded_at DATETIME DEFAULT NULL,
        INDEX idx_case_id (case_id),
        INDEX idx_practice_status (practice_id, status),
        INDEX idx_lab_user (lab_user_id),
        INDEX idx_lab_label (lab_label_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        error_log('[lab-ack] Error creating case_lab_acknowledgments: ' . $e->getMessage());
    }

    $practiceCols = [
        'lab_acknowledgment_enabled' =>
            "ALTER TABLE practices ADD COLUMN lab_acknowledgment_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Optional lab acknowledgment workflow (Settings > Display & Behavior)'",
    ];
    foreach ($practiceCols as $col => $alterSql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE " . $pdo->quote($col));
            if ($stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        } catch (PDOException $e) {
            error_log('[lab-ack] Error adding practices.' . $col . ': ' . $e->getMessage());
        }
    }

    $caseCols = [
        'lab_ack_status' =>
            "ALTER TABLE cases_cache ADD COLUMN lab_ack_status VARCHAR(20) DEFAULT NULL COMMENT 'Denormalized open lab-acknowledgment status: awaiting|needs_info|confirmed'",
        'lab_promised_date' =>
            "ALTER TABLE cases_cache ADD COLUMN lab_promised_date VARCHAR(50) DEFAULT NULL COMMENT 'Lab-promised return date (distinct from due_date and OD-owned patient_appointment_date)'",
    ];
    foreach ($caseCols as $col => $alterSql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM cases_cache LIKE " . $pdo->quote($col));
            if ($stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        } catch (PDOException $e) {
            error_log('[lab-ack] Error adding cases_cache.' . $col . ': ' . $e->getMessage());
        }
    }

    $initialized = true;
}

/**
 * Whether the Lab acknowledgment practice setting is ON. Defaults to OFF
 * when the column is absent so existing practices are never opted in.
 */
function isLabAcknowledgmentEnabled($practiceId = null) {
    global $pdo;

    if (!$pdo) {
        return false;
    }
    if ($practiceId === null) {
        $practiceId = $_SESSION['current_practice_id'] ?? null;
    }
    if (!$practiceId) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT lab_acknowledgment_enabled FROM practices WHERE id = :practice_id");
        $stmt->execute(['practice_id' => $practiceId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fetch the currently open (non-superseded) acknowledgment row for a case.
 *
 * @return array|null
 */
function getOpenLabAcknowledgment($caseId, $practiceId) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM case_lab_acknowledgments
            WHERE case_id = :case_id AND practice_id = :practice_id
              AND status <> 'superseded'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['case_id' => (string)$caseId, 'practice_id' => (int)$practiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('[lab-ack] getOpenLabAcknowledgment error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Whether the given user may act as the lab on an acknowledgment row:
 * the row's lab identity is either the user directly (user:<id>) or a
 * lab-designated Assignment Label the user is a mapped recipient of
 * (label:<id>). Stale responses from a previously-assigned lab fail this
 * check because the open row's lab_key belongs to the new lab.
 */
function userMatchesLabAckIdentity($userId, array $ackRow) {
    global $pdo;

    if (!$pdo || !$userId) {
        return false;
    }

    if ($ackRow['lab_assignee_type'] === 'user') {
        return (int)$ackRow['lab_user_id'] === (int)$userId;
    }

    if ($ackRow['lab_assignee_type'] === 'label' && !empty($ackRow['lab_label_id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT 1 FROM practice_assignment_label_recipients
                WHERE label_id = :label_id AND user_id = :user_id LIMIT 1
            ");
            $stmt->execute(['label_id' => (int)$ackRow['lab_label_id'], 'user_id' => (int)$userId]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('[lab-ack] userMatchesLabAckIdentity error: ' . $e->getMessage());
            return false;
        }
    }

    return false;
}

/**
 * Keep the denormalized cases_cache.lab_ack_status mirror in sync with the
 * acknowledgment table. Pass null to clear.
 */
function _syncCaseAckStatusMirror($caseId, $practiceId, $status) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            UPDATE cases_cache SET lab_ack_status = :status
            WHERE case_id = :case_id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'status' => $status,
            'case_id' => (string)$caseId,
            'practice_id' => (int)$practiceId,
        ]);
    } catch (PDOException $e) {
        error_log('[lab-ack] _syncCaseAckStatusMirror error: ' . $e->getMessage());
    }
}

/**
 * Handle a case-assignment transition for the acknowledgment workflow.
 * Called immediately after recordLabAssignmentChange() from every
 * assignment write path so the two can never diverge.
 *
 * Behavior:
 *  - Lab A -> Lab B / internal / unassigned: Lab A's open acknowledgment
 *    is superseded (preserved as history, never carried over).
 *  - Anything -> Lab: when the practice setting is ON, a new 'awaiting'
 *    acknowledgment opens for that lab identity (deduplicated).
 *  - Setting OFF: no new 'awaiting' rows are ever created; superseding a
 *    stale open row still runs so re-enabling cannot resurrect an ack
 *    that belongs to a different lab.
 *
 * @return array ['opened' => bool, 'lab_display_name' => string|null]
 */
function recordLabAckAssignmentTransition($caseId, $practiceId, $oldAssignedToText, $newAssignedToText, $actorUserId = null) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return ['opened' => false, 'lab_display_name' => null];
    }

    ensureLabAcknowledgmentSchema();

    $new = resolveAssignee($practiceId, $newAssignedToText);
    $newKey = ($new && $new['is_lab']) ? labIdentityKey($new) : null;
    $old = resolveAssignee($practiceId, $oldAssignedToText);
    $oldKey = ($old && $old['is_lab']) ? labIdentityKey($old) : null;
    $now = date('Y-m-d H:i:s');
    $result = ['opened' => false, 'lab_display_name' => null];

    // Supersede the previous lab's open acknowledgment when the handoff
    // moved to a different identity (including internal/unassigned). The
    // prior confirmation/promise stays on the superseded row as history.
    $open = getOpenLabAcknowledgment($caseId, $practiceId);
    $ackSuperseded = false;
    if ($open && $open['lab_key'] !== $newKey) {
        $stmt = $pdo->prepare("
            UPDATE case_lab_acknowledgments
            SET status = 'superseded', superseded_at = :now
            WHERE id = :id AND status <> 'superseded'
        ");
        $stmt->execute(['now' => $now, 'id' => (int)$open['id']]);
        _syncCaseAckStatusMirror($caseId, $practiceId, null);
        $ackSuperseded = true;

        logCaseActivity(
            $caseId,
            'lab_ack_superseded',
            $open['status'],
            null,
            [
                'lab_display_name' => $open['lab_display_name'],
                'previous_status' => $open['status'],
                'cleared_promised_date' => $open['promised_return_date'] ?? null,
                'source' => 'lab-acknowledgment.php',
            ],
            null,
            false,
            $actorUserId
        );
        $open = null;
    }

    // A lab-promised return date belongs to the lab that made it. When the
    // case leaves a lab's commitment context - an open acknowledgment was
    // superseded above, or the assignment moved from one lab identity to a
    // different one (including a non-lab) - the live mirror on cases_cache
    // must clear so the previous lab's promise cannot drive the new
    // assignee's appointment-risk calculation. History rows keep the date.
    if ($ackSuperseded || ($oldKey !== null && $oldKey !== $newKey)) {
        updateCaseLabPromisedDate($caseId, $practiceId, null, $actorUserId);
    }

    // Open a new 'awaiting' acknowledgment when the new assignee is a lab
    // and the practice has enabled the workflow. No backfill: rows are only
    // created at a real handoff (old lab identity must differ), never for
    // cases already with a lab when the setting was switched on.
    if ($newKey && $newKey !== $oldKey && isLabAcknowledgmentEnabled($practiceId)) {
        $check = $pdo->prepare("
            SELECT id FROM case_lab_acknowledgments
            WHERE case_id = :case_id AND practice_id = :practice_id
              AND lab_key = :lab_key AND status <> 'superseded'
            LIMIT 1
        ");
        $check->execute([
            'case_id' => (string)$caseId,
            'practice_id' => (int)$practiceId,
            'lab_key' => $newKey,
        ]);

        if (!$check->fetchColumn()) {
            $type = $new['type'];
            $entityId = ($type === 'user') ? $new['user_id'] : $new['label_id'];
            $stmt = $pdo->prepare("
                INSERT INTO case_lab_acknowledgments
                    (case_id, practice_id, lab_key, lab_assignee_type, lab_user_id, lab_label_id,
                     lab_display_name, status)
                VALUES
                    (:case_id, :practice_id, :lab_key, :type, :user_id, :label_id,
                     :display_name, 'awaiting')
            ");
            $stmt->execute([
                'case_id' => (string)$caseId,
                'practice_id' => (int)$practiceId,
                'lab_key' => $newKey,
                'type' => $type,
                'user_id' => $type === 'user' ? $entityId : null,
                'label_id' => $type === 'label' ? $entityId : null,
                'display_name' => (string)$new['display_name'],
            ]);
            _syncCaseAckStatusMirror($caseId, $practiceId, 'awaiting');
            $result['opened'] = true;
            $result['lab_display_name'] = (string)$new['display_name'];

            logCaseActivity(
                $caseId,
                'lab_ack_requested',
                null,
                'awaiting',
                [
                    'lab_display_name' => (string)$new['display_name'],
                    'source' => 'lab-acknowledgment.php',
                ],
                null,
                false,
                $actorUserId
            );

            // Notify via the existing recipient model: the lab assignee (or
            // label members) + case creator, minus the actor. If a lab label
            // has no mapped recipients there is no lab-side user to reach -
            // the acknowledgment simply stays pending in-app.
            require_once __DIR__ . '/notification-service.php';
            if (function_exists('emitCaseNotificationEvent') && $actorUserId) {
                emitCaseNotificationEvent(
                    $practiceId,
                    $caseId,
                    $actorUserId,
                    'lab_ack_requested',
                    ['lab_ack_requested'],
                    []
                );
            }
        }
    }

    return $result;
}

/**
 * Close the open acknowledgment when a case leaves the lab's hands without
 * a reassignment - e.g. the case reaches Delivered. The row is preserved as
 * 'superseded' history (same mechanism as reassignment) so a Delivered case
 * cannot linger in the "Awaiting acknowledgment" filter. Nothing is reopened
 * automatically on regression.
 */
function supersedeOpenLabAcknowledgment($caseId, $practiceId, $reason = 'case_delivered', $actorUserId = null) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return;
    }

    ensureLabAcknowledgmentSchema();
    $open = getOpenLabAcknowledgment($caseId, $practiceId);
    if (!$open) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE case_lab_acknowledgments
            SET status = 'superseded', superseded_at = :now
            WHERE id = :id AND status <> 'superseded'
        ");
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int)$open['id']]);
        _syncCaseAckStatusMirror($caseId, $practiceId, null);
        updateCaseLabPromisedDate($caseId, $practiceId, null, $actorUserId);

        logCaseActivity(
            $caseId,
            'lab_ack_superseded',
            $open['status'],
            null,
            [
                'lab_display_name' => $open['lab_display_name'],
                'previous_status' => $open['status'],
                'reason' => $reason,
                'cleared_promised_date' => $open['promised_return_date'] ?? null,
                'source' => 'lab-acknowledgment.php',
            ],
            null,
            false,
            $actorUserId
        );
    } catch (PDOException $e) {
        error_log('[lab-ack] supersedeOpenLabAcknowledgment error: ' . $e->getMessage());
    }
}

/**
 * Normalize a submitted date to Y-m-d, or return null when empty / invalid.
 * Matches the loose VARCHAR(50) date convention used by cases_cache dates.
 */
function normalizeLabAckDate($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/**
 * Update the case-level lab-promised return date and record the change in
 * the case activity log (previous date, new date, actor). Returns the
 * stored value or null.
 */
function updateCaseLabPromisedDate($caseId, $practiceId, $newDate, $actorUserId, $reason = null) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            UPDATE cases_cache SET lab_promised_date = :d
            WHERE case_id = :case_id AND practice_id = :practice_id
        ");
        $stmt->execute(['d' => $newDate, 'case_id' => (string)$caseId, 'practice_id' => (int)$practiceId]);
    } catch (PDOException $e) {
        error_log('[lab-ack] updateCaseLabPromisedDate error: ' . $e->getMessage());
        return false;
    }
    return true;
}

/**
 * Cancel still-pending acknowledgment notification emails for a practice.
 * Called when the setting is switched OFF: in-app history is preserved but
 * unsent lab-acknowledgment emails are suppressed, and nothing re-sends on
 * re-enable.
 */
function cancelPendingLabAckNotifications($practiceId) {
    global $pdo;

    if (!$pdo || !$practiceId) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE notification_email_queue q
            JOIN notification_events e ON e.id = q.event_id
            SET q.status = 'cancelled'
            WHERE q.practice_id = :practice_id
              AND q.status = 'pending'
              AND e.event_type IN ('lab_ack_requested','lab_ack_confirmed','lab_info_requested','lab_promised_date_changed')
        ");
        $stmt->execute(['practice_id' => (int)$practiceId]);
    } catch (PDOException $e) {
        error_log('[lab-ack] cancelPendingLabAckNotifications error: ' . $e->getMessage());
    }
}

/**
 * Build the case-modal view model for the acknowledgment panel.
 * Everything the client needs is computed server-side so a lab
 * collaborator (who cannot see member lists) still learns only whether
 * THEY may act on THIS case.
 *
 * @param array|null $ackRow Open acknowledgment row (or null)
 * @param bool       $enabled Practice setting state
 * @param int        $userId  Current session user
 * @param bool       $canEdit can_edit_cases result for this practice
 * @param bool       $isLab   is_lab result for this practice
 * @return array|null
 */
function buildLabAckViewModel($ackRow, $enabled, $userId, $canEdit, $isLab, $caseArchived = false) {
    global $pdo;

    if (!$enabled || $caseArchived) {
        return null;
    }
    if (!$ackRow) {
        return null;
    }

    // Resolve the linked info-request comment text (PHI stays in the case;
    // it never flows into notification metadata).
    $infoRequestText = null;
    if (!empty($ackRow['info_request_comment_id']) && $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT comment_text FROM case_comments WHERE id = :id AND case_id = :case_id LIMIT 1");
            $stmt->execute(['id' => (int)$ackRow['info_request_comment_id'], 'case_id' => (string)$ackRow['case_id']]);
            $infoRequestText = $stmt->fetchColumn() ?: null;
        } catch (PDOException $e) {
            $infoRequestText = null;
        }
    }

    $isStaffConfirmation = ($ackRow['confirmation_source'] === 'staff');

    return [
        'enabled'         => true,
        'status'          => $ackRow['status'],
        'labName'         => $ackRow['lab_display_name'],
        'requestedAt'     => $ackRow['created_at'],
        'confirmedAt'     => $ackRow['confirmed_at'],
        'actorName'       => $ackRow['confirmed_by_name'],
        // Staff-recorded confirmations are labeled as such everywhere they
        // surface - never presented as a direct lab action.
        'recordedByStaff' => $isStaffConfirmation,
        'staffMethod'     => $isStaffConfirmation ? $ackRow['confirmation_method'] : null,
        'promisedDate'    => $ackRow['promised_return_date'],
        'dateNotConfirmed' => $ackRow['status'] === 'confirmed' && empty($ackRow['promised_return_date']),
        'infoRequestText' => $infoRequestText,
        'labUser'         => $isLab && userMatchesLabAckIdentity($userId, $ackRow)
                             && in_array($ackRow['status'], ['awaiting', 'needs_info'], true),
        // The assigned lab may revise its own promised return date once it
        // has confirmed receipt (server enforces the same rule).
        'labCanPromised'  => $isLab && userMatchesLabAckIdentity($userId, $ackRow)
                             && $ackRow['status'] === 'confirmed',
        'staffUser'       => $canEdit && !$isLab,
    ];
}
