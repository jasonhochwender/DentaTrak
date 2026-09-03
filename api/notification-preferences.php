<?php
/**
 * Notification Preferences
 *
 * Centralized preference resolution and persistence for email notifications.
 * Missing rows are treated as enabled (default-on).
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';

// If this library file is requested directly, treat it as a disabled page.
if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'] ?? '') && !isFeatureEnabled('SHOW_NOTIFICATIONS')) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

/**
 * Supported email event types.
 *
 * @return string[]
 */
function getSupportedEmailEventTypes(): array {
    return [
        'case_created',
        'assignment_changed',
        'file_added',
        'file_deleted',
        'notes_changed',
        'status_changed',
        'due_date_changed',
        'appointment_date_changed',
        'case_details_changed',
        'mention',
    ];
}

/**
 * Determine whether a user wants email for a given set of event categories.
 *
 * Rules:
 * - Missing rows mean enabled.
 * - If an explicit 'all' row exists and is disabled, no email is sent.
 * - Otherwise, email is sent if at least one of the supplied categories
 *   is enabled (or has no explicit disabled row).
 *
 * @param PDO      $pdo
 * @param int      $userId
 * @param int      $practiceId
 * @param string[] $eventCategories
 * @return bool
 */
function userWantsEmailNotification(PDO $pdo, int $userId, int $practiceId, array $eventCategories): bool {
    if (!$userId || !$practiceId) {
        return false;
    }

    $eventCategories = array_values(array_unique(array_filter(array_map('strval', $eventCategories))));
    $supported = getSupportedEmailEventTypes();
    $eventCategories = array_values(array_filter($eventCategories, function ($c) use ($supported) {
        return in_array($c, $supported, true);
    }));

    try {
        $types = ['all'];
        if (!empty($eventCategories)) {
            $types = array_merge($types, $eventCategories);
        }

        $inClause = implode(',', array_fill(0, count($types), '?'));
        $stmt = $pdo->prepare("
            SELECT event_type, enabled
            FROM user_notification_preferences
            WHERE user_id = ?
              AND practice_id = ?
              AND channel = 'email'
              AND event_type IN ($inClause)
        ");
        $params = array_merge([$userId, $practiceId], $types);
        $stmt->execute($params);

        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[(string)$r['event_type']] = (bool)$r['enabled'];
        }

        // Master 'all' switch
        if (isset($rows['all']) && !$rows['all']) {
            return false;
        }

        // If no specific categories are supplied, master-on (or missing) means true
        if (empty($eventCategories)) {
            return true;
        }

        // At least one category must be enabled (or missing, which is enabled)
        foreach ($eventCategories as $category) {
            $explicit = $rows[$category] ?? null;
            if ($explicit === true || $explicit === null) {
                return true;
            }
        }

        return false;
    } catch (PDOException $e) {
        error_log('[notification-preferences] userWantsEmailNotification error: ' . $e->getMessage());
        // Fail closed if preferences cannot be read
        return false;
    }
}

/**
 * Get the effective email preferences for a user/practice.
 *
 * Returns an associative array with every supported event type and 'all'.
 * Each entry contains:
 *   - event_type
 *   - enabled (bool)
 *   - is_default (bool) — true when no database row exists
 *
 * @param PDO $pdo
 * @param int $userId
 * @param int $practiceId
 * @return array[]
 */
function getEffectiveEmailPreferences(PDO $pdo, int $userId, int $practiceId): array {
    $supported = getSupportedEmailEventTypes();
    $types = array_merge(['all'], $supported);

    $rows = [];
    try {
        $inClause = implode(',', array_fill(0, count($types), '?'));
        $stmt = $pdo->prepare("
            SELECT event_type, enabled
            FROM user_notification_preferences
            WHERE user_id = ?
              AND practice_id = ?
              AND channel = 'email'
              AND event_type IN ($inClause)
        ");
        $params = array_merge([$userId, $practiceId], $types);
        $stmt->execute($params);

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[(string)$r['event_type']] = (bool)$r['enabled'];
        }
    } catch (PDOException $e) {
        error_log('[notification-preferences] getEffectiveEmailPreferences error: ' . $e->getMessage());
        $rows = [];
    }

    $preferences = [];
    foreach ($types as $type) {
        $preferences[] = [
            'event_type' => $type,
            'enabled' => $rows[$type] ?? true,
            'is_default' => !array_key_exists($type, $rows),
        ];
    }

    return $preferences;
}
