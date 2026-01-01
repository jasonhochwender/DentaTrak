<?php
// Case activity logging helper
// Records case creation, status changes, and other events for auditing.

require_once __DIR__ . '/appConfig.php';

/**
 * Sanitize meta payloads so audit logging never stores PII/PHI.
 * We keep only a small allowlist of operational fields.
 */
function sanitizeCaseActivityMeta(array $meta) {
    $allowedKeys = [
        'source',
        'reason',
        'delivered_hide_days',
        'drive_archived',
        'drive_folder_id',
        'has_attachments',
        'has_notes',
        'length',
        'count',
        'files_deleted',
        'changed_fields',
        'fields_count',
        'attachment_count',
        'assignee_user_id',
    ];

    $clean = [];
    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $meta)) {
            $clean[$key] = $meta[$key];
        }
    }

    // Normalize changed_fields to just field names (no values)
    if (isset($clean['changed_fields']) && is_array($clean['changed_fields'])) {
        $clean['changed_fields'] = array_values(array_filter(array_map(function($v) {
            return is_string($v) ? $v : null;
        }, $clean['changed_fields'])));
    }

    // Ensure numeric fields are numeric
    foreach (['delivered_hide_days', 'count', 'files_deleted', 'fields_count', 'attachment_count'] as $numKey) {
        if (isset($clean[$numKey]) && is_numeric($clean[$numKey])) {
            $clean[$numKey] = (int)$clean[$numKey];
        }
    }

    if (isset($clean['assignee_user_id']) && is_numeric($clean['assignee_user_id'])) {
        $clean['assignee_user_id'] = (int)$clean['assignee_user_id'];
    }

    if (isset($clean['drive_archived'])) {
        $clean['drive_archived'] = (bool)$clean['drive_archived'];
    }

    // Drop empty values to keep meta_json small.
    foreach ($clean as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) {
            unset($clean[$k]);
        }
    }

    return $clean;
}

/**
 * Ensure the case_activity_log table exists.
 */
function ensureCaseActivityLogTable() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS case_activity_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        old_status VARCHAR(100) DEFAULT NULL,
        new_status VARCHAR(100) DEFAULT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        user_email VARCHAR(255) DEFAULT NULL,
        meta_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case_id (case_id),
        INDEX idx_event_type (event_type),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[case_activity_log] Error creating table: ' . $e->getMessage());
    }
}

/**
 * Log a case activity event.
 *
 * @param string      $caseId     The case identifier.
 * @param string      $eventType  Short event key, e.g. 'case_created', 'status_changed'.
 * @param string|null $oldStatus  Previous status (if applicable).
 * @param string|null $newStatus  New status (if applicable).
 * @param array       $meta       Optional extra context, stored as JSON.
 */
function logCaseActivity($caseId, $eventType, $oldStatus = null, $newStatus = null, array $meta = []) {
    global $pdo;

    if (!$pdo || !$caseId || !$eventType) {
        return;
    }

    ensureCaseActivityLogTable();

    $userId = isset($_SESSION['db_user_id']) ? (int)$_SESSION['db_user_id'] : null;
    $userEmail = null;
    if (isset($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['email'])) {
        $userEmail = $_SESSION['user']['email'];
    } else if (isset($_SESSION['user_email']) && !empty($_SESSION['user_email'])) {
        $userEmail = $_SESSION['user_email'];
    }

    $sanitizedMeta = sanitizeCaseActivityMeta($meta);
    $metaJson = !empty($sanitizedMeta) ? json_encode($sanitizedMeta) : null;

    $sql = "INSERT INTO case_activity_log (
                case_id,
                event_type,
                old_status,
                new_status,
                user_id,
                user_email,
                meta_json,
                created_at
            ) VALUES (
                :case_id,
                :event_type,
                :old_status,
                :new_status,
                :user_id,
                :user_email,
                :meta_json,
                NOW()
            )";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'case_id'    => $caseId,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'user_id'    => $userId,
            'user_email' => $userEmail,
            'meta_json'  => $metaJson,
        ]);
    } catch (PDOException $e) {
        // Do not break the main flow if logging fails; just log the error.
        error_log('[case_activity_log] Error logging activity: ' . $e->getMessage());
    }
}

/**
 * Get case activity events.
 *
 * @param string $caseId The case identifier.
 * @return array Array of activity events.
 */
function getCaseActivity($caseId) {
    global $pdo;

    if (!$pdo || !$caseId) {
        return [];
    }

    ensureCaseActivityLogTable();

    $sql = "SELECT 
                id,
                case_id,
                event_type,
                old_status,
                new_status,
                user_id,
                user_email,
                meta_json,
                created_at
            FROM case_activity_log 
            WHERE case_id = :case_id 
            ORDER BY created_at DESC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['case_id' => $caseId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse meta_json for each event
        foreach ($events as &$event) {
            $event['meta'] = !empty($event['meta_json']) ? json_decode($event['meta_json'], true) : [];
            unset($event['meta_json']);
        }

        return $events;
    } catch (PDOException $e) {
        error_log('[case_activity_log] Error getting activity: ' . $e->getMessage());
        return [];
    }
}
