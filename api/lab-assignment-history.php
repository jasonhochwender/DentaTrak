<?php
/**
 * Lab Assignment History
 *
 * Foundational infrastructure for "Lab Insights". Tracks, per case, the
 * periods during which a lab-designated user or Assignment Label was the
 * case's actual assignee - independent of case status.
 *
 * IMPORTANT DESIGN RULES (do not violate when extending this file):
 *  - A lab assignment period represents ASSIGNMENT OWNERSHIP, never case
 *    status, with ONE deliberate exception: a case reaching the terminal
 *    Delivered status closes its own open period (end_reason='delivered'),
 *    since a delivered case's lab work is understood to be complete. No
 *    OTHER status transition (Archived, any non-Delivered status, or a
 *    regression away from Delivered) opens or closes a period. Only a
 *    genuine assignment change, an explicit Lab-designation toggle, or
 *    this Delivered transition may touch a period's ended_at/end_reason.
 *  - Stable identity is `user:<user_id>` or `label:<label_id>` - NEVER
 *    label text. Label text (assigned_to / label_text_normalized) is only
 *    used to resolve CURRENT assignment matches and for diagnostics.
 *  - `assignee_display_name_snapshot` / `is_lab_snapshot` are immutable
 *    once written - later renames/deactivation/unflagging must never
 *    rewrite historical rows.
 *  - Backfilled/unknown-start periods (`history_quality =
 *    'backfilled_unknown_start'`) must never have their `started_at`
 *    inferred from case creation/update dates - only "now" (the moment
 *    the association was first observed) is ever used.
 *  - No FK constraint on `label_id`/`user_id` - deleting a live label or
 *    user must never cascade-delete or invalidate historical rows.
 *
 * This module intentionally never opens/commits its own DB transaction -
 * callers (save-settings.php, update-case-assignment.php, update-case.php)
 * control transaction boundaries so label/assignment/lab-history writes
 * can be committed or rolled back together atomically.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/workflow-stages.php';

/**
 * Ensure the case_lab_assignment_periods table exists.
 */
function ensureLabAssignmentHistoryTable() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS case_lab_assignment_periods (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        assignee_type ENUM('user','label') NOT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        label_id INT UNSIGNED DEFAULT NULL,
        label_text_normalized VARCHAR(255) DEFAULT NULL,
        assignee_display_name_snapshot VARCHAR(255) NOT NULL,
        is_lab_snapshot TINYINT(1) NOT NULL DEFAULT 1,
        started_at DATETIME NOT NULL,
        ended_at DATETIME DEFAULT NULL,
        end_reason ENUM('reassigned_to_lab','reassigned_to_internal','lab_designation_removed','case_deleted','delivered') DEFAULT NULL,
        history_quality ENUM('observed','backfilled_unknown_start') NOT NULL DEFAULT 'observed',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case_id (case_id),
        INDEX idx_practice_lab_active (practice_id, is_lab_snapshot, ended_at),
        INDEX idx_user_id (user_id),
        INDEX idx_label_id (label_id),
        INDEX idx_history_quality (history_quality)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[lab-assignment-history] Error creating case_lab_assignment_periods: ' . $e->getMessage());
    }

    ensureDeliveredEndReason();
}

/**
 * Self-healing migration: add 'delivered' to the end_reason ENUM for
 * databases created before this value existed. Idempotent / no-op once
 * the column already includes it (matches the existing auto-migration
 * convention used throughout this codebase).
 */
function ensureDeliveredEndReason() {
    global $pdo;
    static $checked = false;

    if ($checked || !$pdo) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM case_lab_assignment_periods LIKE 'end_reason'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($col && strpos($col['Type'], "'delivered'") === false) {
            $pdo->exec("ALTER TABLE case_lab_assignment_periods MODIFY COLUMN end_reason ENUM('reassigned_to_lab','reassigned_to_internal','lab_designation_removed','case_deleted','delivered') DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log('[lab-assignment-history] Error adding delivered end_reason: ' . $e->getMessage());
    }
}

/**
 * Ensure the `is_lab` designation columns exist on practice_users and
 * practice_assignment_labels. Idempotent / self-healing, matching the
 * existing auto-migration convention used throughout this codebase
 * (see get-settings.php's limited_visibility/can_view_analytics checks).
 */
function ensureLabDesignationColumns() {
    global $pdo;
    static $done = false;

    if ($done || !$pdo) {
        return;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'is_lab'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practice_users ADD COLUMN is_lab BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'If true, this user represents an external dental lab (Lab Insights)'");
        }
    } catch (PDOException $e) {
        error_log('[lab-assignment-history] Error adding practice_users.is_lab: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM practice_assignment_labels LIKE 'is_lab'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practice_assignment_labels ADD COLUMN is_lab BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'If true, this assignment label represents an external dental lab (Lab Insights)'");
        }
    } catch (PDOException $e) {
        error_log('[lab-assignment-history] Error adding practice_assignment_labels.is_lab: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Resolve a raw `assigned_to` text value (as stored in cases_cache) to its
 * underlying entity, practice-scoped. Mirrors the exact label-then-user
 * resolution order already used in update-case-assignment.php.
 *
 * @return array|null {
 *   type: 'user'|'label',
 *   user_id: int|null,
 *   label_id: int|null,
 *   is_lab: bool,
 *   display_name: string,           // email for users, label text for labels
 *   label_text_normalized: string|null,
 * } or null if empty/unrecognized (not a lab, no identity).
 */
function resolveAssignee($practiceId, $assignedToText) {
    global $pdo;

    $text = is_string($assignedToText) ? trim($assignedToText) : '';
    if ($text === '' || !$pdo || !$practiceId) {
        return null;
    }

    // 1. Assignment Label (case-insensitive), scoped to practice.
    $stmt = $pdo->prepare("
        SELECT id, label, is_lab FROM practice_assignment_labels
        WHERE practice_id = :practice_id AND LOWER(label) = LOWER(:label)
        LIMIT 1
    ");
    $stmt->execute(['practice_id' => $practiceId, 'label' => $text]);
    $labelRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($labelRow) {
        return [
            'type' => 'label',
            'user_id' => null,
            'label_id' => (int)$labelRow['id'],
            'is_lab' => !empty($labelRow['is_lab']),
            'display_name' => $labelRow['label'],
            'label_text_normalized' => mb_strtolower(trim($labelRow['label'])),
        ];
    }

    // 2. Real user by email (case-insensitive, trimmed - consistent with
    // the label lookup above and with the current-workload name matching
    // in get-lab-insights.php).
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute(['email' => $text]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userRow) {
        $labStmt = $pdo->prepare("
            SELECT is_lab FROM practice_users
            WHERE practice_id = :practice_id AND user_id = :user_id
            LIMIT 1
        ");
        $labStmt->execute(['practice_id' => $practiceId, 'user_id' => $userRow['id']]);
        $isLab = (bool)$labStmt->fetchColumn();

        return [
            'type' => 'user',
            'user_id' => (int)$userRow['id'],
            'label_id' => null,
            'is_lab' => $isLab,
            'display_name' => $userRow['email'],
            'label_text_normalized' => null,
        ];
    }

    // 3. Unrecognized free text (phantom value) - no identity, not a lab.
    return null;
}

/**
 * Type-safe stable identity key for a resolved assignee, e.g. "user:12" or
 * "label:42". Never based on text, so label renames never change identity.
 */
function labIdentityKey($resolved) {
    if (!$resolved) {
        return null;
    }
    if ($resolved['type'] === 'user') {
        return 'user:' . $resolved['user_id'];
    }
    if ($resolved['type'] === 'label') {
        return 'label:' . $resolved['label_id'];
    }
    return null;
}

/**
 * Handle a genuine case-assignment transition (Internal<->Lab, Lab A<->Lab B).
 * Called from both update-case-assignment.php and update-case.php so the
 * two write paths can never diverge.
 *
 * Does NOT run for label renames (identity unchanged -> no-op) and does
 * NOT run for case status changes (never called from status-update code).
 */
function recordLabAssignmentChange($caseId, $practiceId, $oldAssignedToText, $newAssignedToText) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return;
    }

    ensureLabAssignmentHistoryTable();

    $old = resolveAssignee($practiceId, $oldAssignedToText);
    $new = resolveAssignee($practiceId, $newAssignedToText);

    $oldKey = labIdentityKey($old);
    $newKey = labIdentityKey($new);

    // Same lab (or same non-lab entity) - no-op. Also correctly covers the
    // "Lab A -> Lab A" re-save case: identity unchanged, nothing to do.
    if ($oldKey !== null && $oldKey === $newKey) {
        return;
    }

    $now = date('Y-m-d H:i:s');

    // Close the old lab period, if the previous assignee was a lab.
    if ($old && $old['is_lab']) {
        $reason = ($new && $new['is_lab']) ? 'reassigned_to_lab' : 'reassigned_to_internal';
        $type = $old['type'];
        $entityId = ($type === 'user') ? $old['user_id'] : $old['label_id'];
        $col = ($type === 'user') ? 'user_id' : 'label_id';

        $stmt = $pdo->prepare("
            UPDATE case_lab_assignment_periods
            SET ended_at = :ended_at, end_reason = :reason
            WHERE case_id = :case_id AND practice_id = :practice_id
              AND assignee_type = :assignee_type AND {$col} = :entity_id
              AND ended_at IS NULL
        ");
        $stmt->execute([
            'ended_at' => $now,
            'reason' => $reason,
            'case_id' => $caseId,
            'practice_id' => $practiceId,
            'assignee_type' => $type,
            'entity_id' => $entityId,
        ]);
    }

    // Open a new lab period, if the new assignee is a lab.
    if ($new && $new['is_lab']) {
        $type = $new['type'];
        $entityId = ($type === 'user') ? $new['user_id'] : $new['label_id'];
        $col = ($type === 'user') ? 'user_id' : 'label_id';

        // Defensive check: avoid a duplicate open period for the same
        // identity+case (the oldKey===newKey no-op above already prevents
        // the common case, this guards against any out-of-band data).
        $check = $pdo->prepare("
            SELECT id FROM case_lab_assignment_periods
            WHERE case_id = :case_id AND practice_id = :practice_id
              AND assignee_type = :assignee_type AND {$col} = :entity_id
              AND ended_at IS NULL
            LIMIT 1
        ");
        $check->execute([
            'case_id' => $caseId,
            'practice_id' => $practiceId,
            'assignee_type' => $type,
            'entity_id' => $entityId,
        ]);

        if (!$check->fetchColumn()) {
            $stmt = $pdo->prepare("
                INSERT INTO case_lab_assignment_periods (
                    case_id, practice_id, assignee_type, user_id, label_id,
                    label_text_normalized, assignee_display_name_snapshot,
                    is_lab_snapshot, started_at, ended_at, end_reason, history_quality
                ) VALUES (
                    :case_id, :practice_id, :assignee_type, :user_id, :label_id,
                    :label_text_normalized, :display_name,
                    1, :started_at, NULL, NULL, 'observed'
                )
            ");
            $stmt->execute([
                'case_id' => $caseId,
                'practice_id' => $practiceId,
                'assignee_type' => $type,
                'user_id' => $type === 'user' ? $entityId : null,
                'label_id' => $type === 'label' ? $entityId : null,
                'label_text_normalized' => $new['label_text_normalized'],
                'display_name' => $new['display_name'],
                'started_at' => $now,
            ]);
        }
    }
}

/**
 * Lab checkbox No -> Yes (or re-enabled later). For every case currently
 * assigned to this exact entity (by current text match), open a new lab
 * period if one isn't already open for this identity. Conservative: the
 * true assignment start is unknown, so history_quality is always
 * 'backfilled_unknown_start' and started_at is always "now" - never a
 * guessed/inferred earlier date. Used by both the Lab checkbox ON path and
 * the one-time rollout backfill script.
 */
function initializeOpenLabPeriodsForEntity($practiceId, $type, $entityId, $displayName) {
    global $pdo;

    if (!$pdo || !$practiceId || !$entityId || $displayName === null || $displayName === '') {
        return;
    }

    ensureLabAssignmentHistoryTable();

    // For users, $displayName is the email (the exact text cases_cache
    // stores for a user assignment). For labels, it's the label text.
    $stmt = $pdo->prepare("
        SELECT case_id FROM cases_cache
        WHERE practice_id = :practice_id AND LOWER(TRIM(assigned_to)) = LOWER(TRIM(:match_text))
    ");
    $stmt->execute(['practice_id' => $practiceId, 'match_text' => $displayName]);
    $caseIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($caseIds)) {
        return;
    }

    $col = ($type === 'user') ? 'user_id' : 'label_id';
    $now = date('Y-m-d H:i:s');
    $labelTextNormalized = ($type === 'label') ? mb_strtolower(trim($displayName)) : null;

    $checkStmt = $pdo->prepare("
        SELECT id FROM case_lab_assignment_periods
        WHERE case_id = :case_id AND practice_id = :practice_id
          AND assignee_type = :assignee_type AND {$col} = :entity_id
          AND ended_at IS NULL
        LIMIT 1
    ");

    $insertStmt = $pdo->prepare("
        INSERT INTO case_lab_assignment_periods (
            case_id, practice_id, assignee_type, user_id, label_id,
            label_text_normalized, assignee_display_name_snapshot,
            is_lab_snapshot, started_at, ended_at, end_reason, history_quality
        ) VALUES (
            :case_id, :practice_id, :assignee_type, :user_id, :label_id,
            :label_text_normalized, :display_name,
            1, :started_at, NULL, NULL, 'backfilled_unknown_start'
        )
    ");

    foreach ($caseIds as $caseId) {
        $checkStmt->execute([
            'case_id' => $caseId,
            'practice_id' => $practiceId,
            'assignee_type' => $type,
            'entity_id' => $entityId,
        ]);
        if ($checkStmt->fetchColumn()) {
            continue; // Already has an open period for this exact identity.
        }

        $insertStmt->execute([
            'case_id' => $caseId,
            'practice_id' => $practiceId,
            'assignee_type' => $type,
            'user_id' => $type === 'user' ? $entityId : null,
            'label_id' => $type === 'label' ? $entityId : null,
            'label_text_normalized' => $labelTextNormalized,
            'display_name' => $displayName,
            'started_at' => $now,
        ]);
    }
}

/**
 * Lab checkbox Yes -> No. Does NOT touch case assignment at all - only
 * closes any currently-open lab periods for this entity (across the whole
 * practice, all cases) with end_reason='lab_designation_removed'. Historical
 * rows are never modified.
 */
function closeOpenLabPeriodsForEntity($practiceId, $type, $entityId, $reason) {
    global $pdo;

    if (!$pdo || !$practiceId || !$entityId) {
        return;
    }

    ensureLabAssignmentHistoryTable();

    $col = ($type === 'user') ? 'user_id' : 'label_id';
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        UPDATE case_lab_assignment_periods
        SET ended_at = :ended_at, end_reason = :reason
        WHERE practice_id = :practice_id AND assignee_type = :assignee_type
          AND {$col} = :entity_id AND ended_at IS NULL
    ");
    $stmt->execute([
        'ended_at' => $now,
        'reason' => $reason,
        'practice_id' => $practiceId,
        'assignee_type' => $type,
        'entity_id' => $entityId,
    ]);
}

/**
 * Case reaches the terminal Delivered status. Closes the case's OWN open
 * lab-assignment period (if any) with end_reason='delivered', since a
 * delivered case's lab work is understood to be complete.
 *
 * Deliberately scoped by case_id only (not assignee_type/entity_id) - a
 * case can have at most one open period at a time (recordLabAssignmentChange()
 * guarantees the old period is closed before a new one opens), so this
 * closes exactly that one row, whichever lab it belongs to.
 *
 * Safe / idempotent to call on every Delivered transition:
 *   - No open period (never lab-assigned, already closed, or fake/demo
 *     case with no tracked history) -> the UPDATE matches zero rows, no-op.
 *   - Called again for an already-Delivered case -> ended_at IS NULL guard
 *     means it will never re-close or duplicate a period.
 * Must NOT be called for any other status transition. See
 * reopenLabPeriodOnDeliveredRegression() below for the companion case of a
 * case regressing FROM Delivered - that DOES deliberately open a new
 * period (a second deliberate, narrowly-scoped exception to "status
 * changes never touch periods", alongside this one).
 */
function closeOpenLabPeriodForDeliveredCase($caseId, $practiceId) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return;
    }

    ensureLabAssignmentHistoryTable();

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE case_lab_assignment_periods
        SET ended_at = :ended_at, end_reason = 'delivered'
        WHERE case_id = :case_id AND practice_id = :practice_id AND ended_at IS NULL
    ");
    $stmt->execute([
        'ended_at' => $now,
        'case_id' => $caseId,
        'practice_id' => $practiceId,
    ]);
}

/**
 * Case regresses from the terminal Delivered status back to a non-terminal
 * status (e.g. additional lab work is required) while still assigned to a
 * currently live lab. Opens a brand-new observed period starting NOW - a
 * second, independent round of lab work, never merged with or backdated
 * to the period Delivered previously closed.
 *
 * Only call this with the actual old/new status values from the same
 * transition that closeOpenLabPeriodForDeliveredCase() would react to in
 * the opposite direction - i.e. $oldStatus/$newStatus straight from the
 * request, not re-derived. No-op unless $oldStatus === 'Delivered' and
 * $newStatus is a different, non-Delivered status.
 *
 * No-ops (by design, not by accident):
 *   - $assigned_to is empty, resolves to no known identity, or resolves to
 *     an identity that is not CURRENTLY designated as a lab (e.g. the lab
 *     checkbox was unchecked, or the practice user/label was removed,
 *     while the case sat Delivered) - matches the current-workload rule
 *     in get-lab-insights.php: a case can only be "at a lab" via its
 *     current assignment resolving to a currently live lab.
 *   - An open period for this exact case+lab identity already exists -
 *     idempotent guard so repeated saves at any non-terminal status (or a
 *     second regression call for the same transition) never open a
 *     second period. This also means a later non-terminal -> non-terminal
 *     status change (e.g. Designed -> In Production) is a natural no-op
 *     here too, since $oldStatus will no longer be 'Delivered'.
 *
 * Deliberately does NOT touch the period Delivered just closed - that
 * remains a separate, preserved, completed historical record.
 */
function reopenLabPeriodOnDeliveredRegression($caseId, $practiceId, $oldStatus, $newStatus) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return;
    }
    // "Delivered" here means the practice's current terminal (last) column.
    $terminal = getLastActiveWorkflowColumnId($practiceId);
    if ($oldStatus !== $terminal || $newStatus === $terminal) {
        return;
    }

    ensureLabAssignmentHistoryTable();

    $stmt = $pdo->prepare("SELECT assigned_to FROM cases_cache WHERE case_id = :case_id AND practice_id = :practice_id LIMIT 1");
    $stmt->execute(['case_id' => $caseId, 'practice_id' => $practiceId]);
    $assignedTo = $stmt->fetchColumn();

    $resolved = resolveAssignee($practiceId, $assignedTo === false ? '' : $assignedTo);
    if (!$resolved || empty($resolved['is_lab'])) {
        // Empty, unrecognized, non-lab, or no-longer-a-lab assignment -
        // the case may return to the Kanban board, but it must not appear
        // as currently at a lab in Lab Insights unless its CURRENT
        // assignment actually resolves to a currently live lab.
        return;
    }

    $type = $resolved['type'];
    $entityId = ($type === 'user') ? $resolved['user_id'] : $resolved['label_id'];
    $col = ($type === 'user') ? 'user_id' : 'label_id';

    // Idempotency: an open period already existing for this exact
    // case+identity means a prior call already reopened it - never open
    // a second one.
    $check = $pdo->prepare("
        SELECT id FROM case_lab_assignment_periods
        WHERE case_id = :case_id AND practice_id = :practice_id
          AND assignee_type = :assignee_type AND {$col} = :entity_id
          AND ended_at IS NULL
        LIMIT 1
    ");
    $check->execute([
        'case_id' => $caseId,
        'practice_id' => $practiceId,
        'assignee_type' => $type,
        'entity_id' => $entityId,
    ]);
    if ($check->fetchColumn()) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO case_lab_assignment_periods (
            case_id, practice_id, assignee_type, user_id, label_id,
            label_text_normalized, assignee_display_name_snapshot,
            is_lab_snapshot, started_at, ended_at, end_reason, history_quality
        ) VALUES (
            :case_id, :practice_id, :assignee_type, :user_id, :label_id,
            :label_text_normalized, :display_name,
            1, :started_at, NULL, NULL, 'observed'
        )
    ");
    $stmt->execute([
        'case_id' => $caseId,
        'practice_id' => $practiceId,
        'assignee_type' => $type,
        'user_id' => $type === 'user' ? $entityId : null,
        'label_id' => $type === 'label' ? $entityId : null,
        'label_text_normalized' => $resolved['label_text_normalized'],
        'display_name' => $resolved['display_name'],
        'started_at' => $now,
    ]);
}
