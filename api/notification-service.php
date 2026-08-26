<?php
/**
 * Phase 2 Notification Service
 *
 * - Structured notification event generation
 * - Recipient resolution from the case's current assignment
 * - In-app user_notifications row creation
 *
 * This service is intentionally independent of email, SMS, push, and queues.
 * It is used synchronously inside case endpoints, before the final response is
 * sent. Failures are logged and swallowed so the parent case operation always
 * returns its normal success response.
 *
 * Every successful call creates one notification_events parent row and one
 * user_notifications row per resolved recipient inside a single transaction.
 * If no valid recipient exists (unassigned, actor-only, invalid/inactive),
 * no rows are created.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/notification-preferences.php';
require_once __DIR__ . '/i18n.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Best-effort safety: if PDO is not available, callers will receive
    // swallowed failures and the case operation remains unaffected.
}

// Priority order for choosing a primary event type when several categories are present.
$_NOTIFICATION_CATEGORY_PRIORITY = [
    'assignment_changed' => 1,
    'status_changed' => 2,
    'file_deleted' => 3,
    'file_added' => 4,
    'due_date_changed' => 5,
    'appointment_date_changed' => 6,
    'notes_changed' => 7,
    'protected_details_changed' => 8,
    'case_details_changed' => 9,
    'case_created' => 10,
];

// Allowed field-category slugs for metadata.  No patient names, DOB, clinical
// values, filenames, or any PHI ever appears.
$_NOTIFICATION_FIELD_CATEGORIES = [
    // Patient and clinical fields are grouped under protected
    'patient' => 'protected',
    'clinical' => 'protected',
    'dentist' => 'protected',
    // Non-PHI operational groupings
    'shipping' => 'operational',
    'material' => 'operational',
    'shades' => 'operational',
    'other' => 'operational',
];

/**
 * Emit a structured notification event for a case action, returning the number
 * of in-app recipients notified.  Returns null on any failure, and returns an
 * empty result if no valid recipients were resolved.
 *
 * @param int    $practiceId  Current practice ID
 * @param string $caseId      Case ID
 * @param int    $actorUserId User ID that performed the action
 * @param string $eventType   Stable event type (e.g. 'case_created')
 * @param array  $categories  One or more event categories
 * @param array  $metadata    Sanitized, non-PHI metadata
 * @param array  $testOptions Optional test-only controls (never used in production paths)
 * @return array|null ['event_id' => int, 'recipient_count' => int] or null on failure/no-op
 */
function emitCaseNotificationEvent($practiceId, $caseId, $actorUserId, $eventType, array $categories, array $metadata = [], array $testOptions = []) {
    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return null;
    }

    // Central master gating for structured case notifications.
    // This single check prevents any endpoint from accidentally creating
    // notification events, in-app rows, or email queue rows when the feature
    // is disabled. The legacy @mention path does not use this function.
    require_once __DIR__ . '/feature-flags.php';
    if (!isFeatureEnabled('SHOW_NOTIFICATIONS')) {
        return null;
    }

    try {
        if (!$actorUserId || empty($eventType) || empty($categories)) {
            return null;
        }

        // No unassigned case notifications.
        $assignedTo = _getCaseAssignedTo($caseId);
        if (empty($assignedTo)) {
            return null;
        }

        // Resolve and validate recipients first.  No event is created if there
        // is nobody to notify (actor-only, inactive, unmapped, etc.).
        $recipientIds = _resolveCaseNotificationRecipients($practiceId, $caseId, $assignedTo, $actorUserId);
        if (empty($recipientIds)) {
            return null;
        }

        $actorName = _getNotificationUserDisplayName($actorUserId);
        $sanitizedMetadata = _sanitizeNotificationMetadata($metadata);

        $pdo->beginTransaction();

        // Build the canonical event row.
        $eventStmt = $pdo->prepare("
            INSERT INTO notification_events
                (practice_id, case_id, actor_user_id, event_type, event_categories, metadata_json, created_at)
            VALUES
                (:practice_id, :case_id, :actor_user_id, :event_type, :event_categories, :metadata_json, NOW())
        ");
        $eventStmt->execute([
            'practice_id' => (int)$practiceId,
            'case_id' => (string)$caseId,
            'actor_user_id' => (int)$actorUserId,
            'event_type' => (string)$eventType,
            'event_categories' => json_encode(array_values($categories)),
            'metadata_json' => json_encode($sanitizedMetadata),
        ]);
        $eventId = (int)$pdo->lastInsertId();

        $recipientStmt = $pdo->prepare("
            INSERT INTO user_notifications
                (user_id, practice_id, notification_type, case_id, from_user_id, from_user_name,
                 preview_text, metadata_json, event_id, expires_at, created_at)
            VALUES
                (:user_id, :practice_id, :notification_type, :case_id, :from_user_id, :from_user_name,
                 NULL, :metadata_json, :event_id, DATE_ADD(NOW(), INTERVAL 90 DAY), NOW())
        ");

        $queueStmt = $pdo->prepare("
            INSERT INTO notification_email_queue
                (event_id, notification_id, user_id, practice_id, locale, subject_key, body_key,
                 template_data_json, category_keys_json, status, scheduled_at, created_at)
            VALUES
                (:event_id, :notification_id, :user_id, :practice_id, :locale, :subject_key, :body_key,
                 :template_data_json, :category_keys_json, 'pending', NOW(), NOW())
        ");

        $inserted = 0;
        $queued = 0;
        foreach ($recipientIds as $index => $recipientId) {
            if (!empty($testOptions['force_recipient_error_after']) && $inserted >= (int)$testOptions['force_recipient_error_after']) {
                throw new RuntimeException('Test-forced recipient insert failure');
            }
            $recipientStmt->execute([
                'user_id' => (int)$recipientId,
                'practice_id' => (int)$practiceId,
                'notification_type' => (string)$eventType,
                'case_id' => (string)$caseId,
                'from_user_id' => (int)$actorUserId,
                'from_user_name' => $actorName,
                'metadata_json' => json_encode($sanitizedMetadata),
                'event_id' => $eventId,
            ]);
            $notificationId = (int)$pdo->lastInsertId();
            $inserted++;

            // Queue an email only if this recipient's preferences permit it.
            if (!empty($testOptions['force_queue_error'])) {
                throw new RuntimeException('Test-forced queue insert failure');
            }

            if (userWantsEmailNotification($pdo, (int)$recipientId, (int)$practiceId, $categories)) {
                $recipientLocale = resolveEmailLocale($recipientId, $practiceId);
                $queueStmt->execute([
                    'event_id' => $eventId,
                    'notification_id' => $notificationId,
                    'user_id' => (int)$recipientId,
                    'practice_id' => (int)$practiceId,
                    'locale' => (string)$recipientLocale,
                    'subject_key' => 'notifications.email.subject',
                    'body_key' => 'notifications.email.body',
                    'template_data_json' => json_encode([
                        'from' => $actorName,
                        'event_type' => $eventType,
                    ]),
                    'category_keys_json' => json_encode(array_values($categories)),
                ]);
                $queued++;
            }
        }

        $pdo->commit();

        return [
            'event_id' => $eventId,
            'recipient_count' => $inserted,
            'queued_count' => $queued,
        ];

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[notification-service] emitCaseNotificationEvent error: ' . $e->getMessage());

        // Allow the test-only queue insert failure to propagate so callers
        // can confirm the transaction was rolled back without hiding the cause.
        if (!empty($testOptions['force_queue_error']) && strpos($e->getMessage(), 'Test-forced queue insert failure') !== false) {
            throw $e;
        }

        return null;
    }
}

/**
 * Determine the primary event type from a list of categories.
 */
function getPrimaryNotificationType(array $categories) {
    global $_NOTIFICATION_CATEGORY_PRIORITY;
    $primary = 'case_details_changed';
    $bestPriority = PHP_INT_MAX;

    foreach ($categories as $category) {
        $priority = $_NOTIFICATION_CATEGORY_PRIORITY[$category] ?? PHP_INT_MAX;
        if ($priority < $bestPriority) {
            $bestPriority = $priority;
            $primary = $category;
        }
    }

    return $primary;
}

/**
 * Build the category set for a newly created case.
 */
function buildCreateCaseNotificationCategories(array $caseData, array $attachments = []) {
    $categories = ['case_created'];

    $notes = $caseData['notes'] ?? '';
    if (is_string($notes) && trim($notes) !== '') {
        $categories[] = 'notes_changed';
    }

    if (!empty($attachments)) {
        $categories[] = 'file_added';
    }

    return $categories;
}

/**
 * Build the metadata allowlist for a create-case event.
 */
function buildCreateCaseNotificationMetadata(array $caseData, array $attachments = []) {
    return [
        'has_notes' => !empty($caseData['notes']) && trim((string)$caseData['notes']) !== '',
        'has_attachments' => !empty($attachments),
    ];
}

/**
 * Categorize a changed case field for safe metadata.  Returns a category slug
 * without any value.
 */
function _getFieldChangeCategory($key) {
    $protected = ['patientFirstName', 'patientLastName', 'patientDOB', 'patientGender', 'dentistName'];
    $clinical = ['clinicalDetails'];
    $shipping = ['carrier', 'trackingNumber', 'customCarrier'];
    $material = ['toothShade', 'material'];

    if (in_array($key, $protected, true)) {
        return 'patient';
    }
    if (in_array($key, $clinical, true)) {
        return 'clinical';
    }
    if (in_array($key, $shipping, true)) {
        return 'shipping';
    }
    if (in_array($key, $material, true)) {
        return 'material';
    }

    return 'other';
}

/**
 * Build the category set and safe metadata for a case update.
 *
 * @param array $before     Previous case state (from cache)
 * @param array $after      New case state
 * @param int   $filesAddedCount Number of uploaded files added in this request
 * @param int   $filesDeletedCount Number of files deleted in this request
 * @return array ['categories' => [...], 'metadata' => [...]]
 */
function buildUpdateCaseNotificationCategories(array $before, array $after, $filesAddedCount = 0, $filesDeletedCount = 0) {
    $categories = [];
    $metadata = [];

    // Assignment change
    $oldAssigned = $before['assignedTo'] ?? '';
    $newAssigned = $after['assignedTo'] ?? '';
    if (($oldAssigned !== '' || $newAssigned !== '') && $oldAssigned !== $newAssigned) {
        $categories[] = 'assignment_changed';
        $metadata['assignment_set'] = $newAssigned !== '';
        $metadata['from_previous'] = $oldAssigned !== '';
    }

    // Status change
    $oldStatus = $before['status'] ?? '';
    $newStatus = $after['status'] ?? '';
    if ($oldStatus !== $newStatus) {
        $categories[] = 'status_changed';
        $metadata['from_status'] = $oldStatus;
        $metadata['to_status'] = $newStatus;
        $metadata['regression'] = false; // set by caller if known
    }

    // Due date change
    $oldDue = $before['dueDate'] ?? '';
    $newDue = $after['dueDate'] ?? '';
    if (($oldDue !== '' || $newDue !== '') && $oldDue !== $newDue) {
        $categories[] = 'due_date_changed';
    }

    // Patient appointment date change
    $oldAppt = $before['patientAppointmentDate'] ?? '';
    $newAppt = $after['patientAppointmentDate'] ?? '';
    if (($oldAppt !== '' || $newAppt !== '') && $oldAppt !== $newAppt) {
        $categories[] = 'appointment_date_changed';
    }

    // Notes change
    $oldNotes = $before['notes'] ?? '';
    $newNotes = $after['notes'] ?? '';
    if ($oldNotes !== $newNotes) {
        $categories[] = 'notes_changed';
    }

    // Files added
    if ($filesAddedCount > 0) {
        $categories[] = 'file_added';
        $metadata['file_added_count'] = $filesAddedCount;
    }

    // Files deleted
    if ($filesDeletedCount > 0) {
        $categories[] = 'file_deleted';
        $metadata['file_deleted_count'] = $filesDeletedCount;
    }

    // Any other user-visible field changes are grouped into generic,
    // PHI-free category slugs.  No values are stored.
    $ignored = ['id', 'driveFolderId', 'version', 'createdByUserId', 'createdByName',
                'atRisk', 'lastUpdateDate', 'creationDate', 'revisions', 'attachments',
                'assignedTo', 'status', 'dueDate', 'patientAppointmentDate', 'notes', 'clinicalDetails'];
    $changedCategories = [];

    foreach ($after as $key => $value) {
        if (in_array($key, $ignored, true)) {
            continue;
        }
        if (!array_key_exists($key, $before) || _jsonEncodeForCompare($before[$key]) !== _jsonEncodeForCompare($value)) {
            $changedCategories[] = _getFieldChangeCategory($key);
        }
    }

    if (array_key_exists('clinicalDetails', $before) && array_key_exists('clinicalDetails', $after)) {
        if (_jsonEncodeForCompare($before['clinicalDetails']) !== _jsonEncodeForCompare($after['clinicalDetails'])) {
            $changedCategories[] = 'clinical';
        }
    } elseif (array_key_exists('clinicalDetails', $after) || array_key_exists('clinicalDetails', $before)) {
        $changedCategories[] = 'clinical';
    }

    $changedCategories = array_values(array_unique($changedCategories));

    if (!empty($changedCategories)) {
        if (in_array('patient', $changedCategories) || in_array('clinical', $changedCategories)) {
            $categories[] = 'protected_details_changed';
        } else {
            $categories[] = 'case_details_changed';
        }
        $metadata['changed_categories'] = $changedCategories;
    }

    return [
        'categories' => $categories,
        'metadata' => $metadata,
    ];
}

/**
 * Resolve recipients from a case's current assigned_to value.
 *
 * @return array<int> Active user IDs to notify (actor already excluded)
 */
function _resolveCaseNotificationRecipients($practiceId, $caseId, $assignedTo, $actorUserId) {
    global $pdo;

    if (empty($assignedTo) || !isset($pdo) || !($pdo instanceof PDO)) {
        return [];
    }

    $assignedTo = trim((string)$assignedTo);
    $recipients = [];

    // 1. If assigned_to is a known assignment label, notify all active mapped users.
    $labelStmt = $pdo->prepare("
        SELECT pal.id
        FROM practice_assignment_labels pal
        WHERE pal.practice_id = :practice_id
          AND LOWER(pal.label) = LOWER(:label)
        LIMIT 1
    ");
    $labelStmt->execute([
        'practice_id' => (int)$practiceId,
        'label' => $assignedTo,
    ]);
    $labelId = $labelStmt->fetchColumn();

    if ($labelId !== false) {
        $mapStmt = $pdo->prepare("
            SELECT DISTINCT palr.user_id
            FROM practice_assignment_label_recipients palr
            JOIN users u ON u.id = palr.user_id
            JOIN practices p ON p.id = palr.practice_id
            WHERE palr.label_id = :label_id
              AND palr.practice_id = :practice_id
              AND u.is_active = 1
              AND (p.is_active = 1 OR p.is_active IS NULL)
        ");
        $mapStmt->execute([
            'label_id' => (int)$labelId,
            'practice_id' => (int)$practiceId,
        ]);
        while ($row = $mapStmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)$row['user_id'];
            if ($uid !== $actorUserId) {
                $recipients[$uid] = true;
            }
        }
    } else {
        // 2. Otherwise, treat it as a direct email assignment if the user is an
        //    active member of the current practice.
        $userStmt = $pdo->prepare("
            SELECT u.id
            FROM users u
            JOIN practice_users pu ON pu.user_id = u.id
            JOIN practices p ON p.id = pu.practice_id
            WHERE LOWER(u.email) = LOWER(:email)
              AND pu.practice_id = :practice_id
              AND u.is_active = 1
              AND (p.is_active = 1 OR p.is_active IS NULL)
            LIMIT 1
        ");
        $userStmt->execute([
            'email' => $assignedTo,
            'practice_id' => (int)$practiceId,
        ]);
        $uid = $userStmt->fetchColumn();
        if ($uid !== false && (int)$uid !== $actorUserId) {
            $recipients[(int)$uid] = true;
        }
    }

    return array_keys($recipients);
}

/**
 * Get the current assigned_to value for a case from the cache.
 */
function _getCaseAssignedTo($caseId) {
    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT assigned_to FROM cases_cache WHERE case_id = :case_id LIMIT 1");
        $stmt->execute(['case_id' => (string)$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['assigned_to'] ?? null;
    } catch (Throwable $e) {
        error_log('[notification-service] _getCaseAssignedTo error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Fetch a display name for a user ID.
 */
function _getNotificationUserDisplayName($userId) {
    global $pdo;

    if (isset($_SESSION['db_user_id']) && (int)$_SESSION['db_user_id'] === (int)$userId
        && isset($_SESSION['first_name'])) {
        $name = trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return 'Unknown';
    }

    try {
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int)$userId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            return $name !== '' ? $name : 'Unknown';
        }
    } catch (Throwable $e) {
        error_log('[notification-service] _getNotificationUserDisplayName error: ' . $e->getMessage());
    }

    return 'Unknown';
}

/**
 * Strip metadata to an allowed allowlist.  This guarantees no PHI or arbitrary
 * request data can be persisted in notification metadata.
 */
function _sanitizeNotificationMetadata(array $metadata) {
    $allowedKeys = [
        'has_notes',
        'has_attachments',
        'assignment_set',
        'from_previous',
        'from_status',
        'to_status',
        'regression',
        'file_added_count',
        'file_deleted_count',
        'changed_categories',
    ];

    $out = [];
    foreach ($allowedKeys as $key) {
        if (array_key_exists($key, $metadata)) {
            $out[$key] = $metadata[$key];
        }
    }
    return $out;
}

/**
 * JSON-encode a value for stable comparison of nested arrays.
 */
function _jsonEncodeForCompare($value) {
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return '';
    }
    return (string)$value;
}
