<?php
/**
 * Case Remake Tracking
 *
 * Structured record of true remakes - explicitly user-recorded, never
 * inferred from workflow regressions, reopens, edits, or status moves.
 * Reason and attribution are independent fields: a remake is never
 * automatically attributed to the lab.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/lab-assignment-history.php';

/**
 * Canonical remake reason codes -> i18n label keys.
 * Codes are stable identifiers stored in case_remake_events.reason_code;
 * display text always resolves through t() at render time.
 */
function getRemakeReasons() {
    return [
        'fit_issue'                => 'remakes.reasons.fit_issue',
        'shade_color'              => 'remakes.reasons.shade_color',
        'esthetics'                => 'remakes.reasons.esthetics',
        'occlusion_bite'           => 'remakes.reasons.occlusion_bite',
        'margins'                  => 'remakes.reasons.margins',
        'contacts'                 => 'remakes.reasons.contacts',
        'incorrect_design'         => 'remakes.reasons.incorrect_design',
        'incorrect_material'       => 'remakes.reasons.incorrect_material',
        'damage_breakage'          => 'remakes.reasons.damage_breakage',
        'missing_incorrect_item'   => 'remakes.reasons.missing_incorrect_item',
        'scan_impression'          => 'remakes.reasons.scan_impression',
        'prescription_instruction' => 'remakes.reasons.prescription_instruction',
        'patient_change'           => 'remakes.reasons.patient_change',
        'practice_requested'       => 'remakes.reasons.practice_requested',
        'lab_error'                => 'remakes.reasons.lab_error',
        'other'                    => 'remakes.reasons.other',
    ];
}

/**
 * Canonical attribution codes -> i18n label keys. Independent of
 * reason_code by design - a fit issue is not automatically lab-related.
 */
function getRemakeAttributions() {
    return [
        'lab_related'             => 'remakes.attribution.lab_related',
        'practice_related'        => 'remakes.attribution.practice_related',
        'patient_related'         => 'remakes.attribution.patient_related',
        'scan_impression_related' => 'remakes.attribution.scan_impression_related',
        'unclear'                 => 'remakes.attribution.unclear',
        'other'                   => 'remakes.attribution.other',
    ];
}

function isValidRemakeReason($code) {
    return is_string($code) && array_key_exists($code, getRemakeReasons());
}

function isValidRemakeAttribution($code) {
    return is_string($code) && array_key_exists($code, getRemakeAttributions());
}

/**
 * Ensure the case_remake_events table exists (self-healing, matches the
 * ensure*Table() convention used throughout this codebase).
 *
 * UNIQUE (case_id, remake_number) makes the server-derived sequence
 * enforceable even if a race ever slipped past the row lock.
 */
function ensureCaseRemakeEventsTable() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS case_remake_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL,
        practice_id BIGINT UNSIGNED NOT NULL,
        remake_number INT UNSIGNED NOT NULL,
        reason_code VARCHAR(50) NOT NULL,
        attribution VARCHAR(50) NOT NULL,
        notes TEXT DEFAULT NULL,
        initiated_at DATETIME NOT NULL,
        completed_at DATETIME DEFAULT NULL,
        lab_period_id BIGINT UNSIGNED DEFAULT NULL,
        created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_case_remake_number (case_id, remake_number),
        INDEX idx_case_id (case_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_lab_period_id (lab_period_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[remakes] Error creating case_remake_events table: ' . $e->getMessage());
    }
}

/**
 * Resolve the lab-assignment period a remake should be linked to.
 *
 * Rule: the case's currently OPEN lab period wins when one exists (the lab
 * actively holding the case). Otherwise the most recently CLOSED period is
 * used - the lab that most recently held the case (e.g. delivered work now
 * being sent back). NULL when no period exists at all - never fabricated
 * and never silently assumed from the current assigned_to text.
 *
 * @return int|null case_lab_assignment_periods.id or null
 */
function findRemakeLabPeriodId($caseId, $practiceId) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return null;
    }

    try {
        // Open period first (ended_at IS NULL sorts as 1 with DESC), then
        // the most recently closed period by end/start time.
        $stmt = $pdo->prepare("
            SELECT id FROM case_lab_assignment_periods
            WHERE case_id = :case_id AND practice_id = :practice_id
            ORDER BY (ended_at IS NULL) DESC,
                     COALESCE(ended_at, started_at) DESC,
                     id DESC
            LIMIT 1
        ");
        $stmt->execute(['case_id' => $caseId, 'practice_id' => $practiceId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (PDOException $e) {
        error_log('[remakes] Error resolving lab period: ' . $e->getMessage());
        return null;
    }
}

/**
 * Create a remake record. remake_number is derived server-side under a
 * row lock on the case (SELECT ... FOR UPDATE) so concurrent requests can
 * never produce the same sequence number; the UNIQUE key is a hard
 * backstop. Caller must have already verified case access.
 *
 * @return array|null The inserted row, or null on failure.
 */
function createCaseRemake($caseId, $practiceId, $reasonCode, $attribution, $notes, $userId) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId
        || !isValidRemakeReason($reasonCode) || !isValidRemakeAttribution($attribution)) {
        return null;
    }

    ensureCaseRemakeEventsTable();
    ensureLabAssignmentHistoryTable();

    $notes = is_string($notes) ? trim($notes) : '';
    $notes = ($notes === '') ? null : mb_substr($notes, 0, 2000);

    try {
        $pdo->beginTransaction();

        // Serialize concurrent remake creation for this case on the
        // cases_cache row lock - MAX()+1 is then race-free.
        $lock = $pdo->prepare("SELECT case_id FROM cases_cache WHERE case_id = :case_id AND practice_id = :practice_id FOR UPDATE");
        $lock->execute(['case_id' => $caseId, 'practice_id' => $practiceId]);
        if (!$lock->fetchColumn()) {
            $pdo->rollBack();
            return null;
        }

        $seq = $pdo->prepare("SELECT COALESCE(MAX(remake_number), 0) + 1 FROM case_remake_events WHERE case_id = :case_id");
        $seq->execute(['case_id' => $caseId]);
        $remakeNumber = (int)$seq->fetchColumn();

        $labPeriodId = findRemakeLabPeriodId($caseId, $practiceId);

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO case_remake_events (
                case_id, practice_id, remake_number, reason_code, attribution,
                notes, initiated_at, completed_at, lab_period_id, created_by_user_id
            ) VALUES (
                :case_id, :practice_id, :remake_number, :reason_code, :attribution,
                :notes, :initiated_at, NULL, :lab_period_id, :created_by_user_id
            )
        ");
        $stmt->execute([
            'case_id' => $caseId,
            'practice_id' => $practiceId,
            'remake_number' => $remakeNumber,
            'reason_code' => $reasonCode,
            'attribution' => $attribution,
            'notes' => $notes,
            'initiated_at' => $now,
            'lab_period_id' => $labPeriodId,
            'created_by_user_id' => $userId,
        ]);

        $remakeId = (int)$pdo->lastInsertId();
        $pdo->commit();

        return [
            'id' => $remakeId,
            'case_id' => $caseId,
            'remake_number' => $remakeNumber,
            'reason_code' => $reasonCode,
            'attribution' => $attribution,
            'notes' => $notes,
            'initiated_at' => $now,
            'completed_at' => null,
            'lab_period_id' => $labPeriodId,
            'created_by_user_id' => $userId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[remakes] Error creating remake for case ' . $caseId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Mark an open remake completed. Idempotent: completing an
 * already-completed remake returns 'already_completed' rather than
 * overwriting the original timestamp.
 *
 * @return string 'completed' | 'already_completed' | 'not_found'
 */
function completeCaseRemake($remakeId, $caseId, $practiceId) {
    global $pdo;

    if (!$pdo || !$remakeId || !$caseId || !$practiceId) {
        return 'not_found';
    }

    ensureCaseRemakeEventsTable();

    $stmt = $pdo->prepare("
        UPDATE case_remake_events
        SET completed_at = NOW()
        WHERE id = :id AND case_id = :case_id AND practice_id = :practice_id
          AND completed_at IS NULL
    ");
    $stmt->execute([
        'id' => $remakeId,
        'case_id' => $caseId,
        'practice_id' => $practiceId,
    ]);

    if ($stmt->rowCount() === 1) {
        return 'completed';
    }

    // Distinguish "already completed" from "no such row".
    $check = $pdo->prepare("SELECT completed_at FROM case_remake_events WHERE id = :id AND case_id = :case_id AND practice_id = :practice_id LIMIT 1");
    $check->execute(['id' => $remakeId, 'case_id' => $caseId, 'practice_id' => $practiceId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'not_found';
    }
    return 'already_completed';
}

/**
 * Fetch a case's remake history with the linked lab's display-name
 * snapshot and the recording user's identity.
 */
function getCaseRemakes($caseId, $practiceId) {
    global $pdo;

    if (!$pdo || !$caseId || !$practiceId) {
        return [];
    }

    ensureCaseRemakeEventsTable();
    ensureLabAssignmentHistoryTable();

    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.case_id, r.remake_number, r.reason_code, r.attribution,
                   r.notes, r.initiated_at, r.completed_at, r.lab_period_id,
                   r.created_by_user_id, r.created_at,
                   p.assignee_display_name_snapshot AS lab_name,
                   p.is_lab_snapshot AS lab_period_was_lab,
                   u.email AS created_by_email,
                   u.first_name AS created_by_first_name,
                   u.last_name AS created_by_last_name
            FROM case_remake_events r
            LEFT JOIN case_lab_assignment_periods p ON p.id = r.lab_period_id
            LEFT JOIN users u ON u.id = r.created_by_user_id
            WHERE r.case_id = :case_id AND r.practice_id = :practice_id
            ORDER BY r.remake_number ASC
        ");
        $stmt->execute(['case_id' => $caseId, 'practice_id' => $practiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[remakes] Error listing remakes for case ' . $caseId . ': ' . $e->getMessage());
        return [];
    }
}
