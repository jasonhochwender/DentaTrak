<?php
/**
 * Notifications API
 * Handles user notifications (mentions, etc.)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

/**
 * Ensure the user_notifications table exists
 */
function ensureUserNotificationsTable() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS user_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        notification_type VARCHAR(50) NOT NULL DEFAULT 'mention',
        case_id VARCHAR(64) DEFAULT NULL,
        comment_id BIGINT UNSIGNED DEFAULT NULL,
        from_user_id BIGINT UNSIGNED NOT NULL,
        from_user_name VARCHAR(255) NOT NULL,
        preview_text VARCHAR(255) DEFAULT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        read_at DATETIME DEFAULT NULL,
        dismissed_at DATETIME DEFAULT NULL,
        metadata_json LONGTEXT,
        event_id BIGINT UNSIGNED DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_is_read (is_read),
        INDEX idx_dismissed_at (dismissed_at),
        INDEX idx_created_at (created_at),
        INDEX idx_case_id (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        error_log('[user_notifications] Error creating table: ' . $e->getMessage());
        return;
    }

    // Backfill columns added by the Phase 1 migration if an older table exists.
    // NOTE: dismissed_at is intentionally NOT added here; it is added by
    // migrations/2026_09_09_notification_dismissal.php to avoid request-time DDL.
    $columns = [
        'metadata_json'  => "ALTER TABLE user_notifications ADD COLUMN metadata_json LONGTEXT",
        'expires_at'     => "ALTER TABLE user_notifications ADD COLUMN expires_at DATETIME DEFAULT NULL",
        'event_id'       => "ALTER TABLE user_notifications ADD COLUMN event_id BIGINT UNSIGNED DEFAULT NULL",
    ];

    foreach ($columns as $col => $alterSql) {
        try {
            $quotedCol = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM user_notifications LIKE {$quotedCol}");
            if ($stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        } catch (PDOException $e) {
            error_log('[user_notifications] Error extending table: ' . $e->getMessage());
        }
    }

    // Verify the dismissal schema is present before declaring the table ready.
    // If the migration has not been run, fail closed and tell the operator exactly
    // what to do, rather than attempting DDL from an ordinary request or running
    // queries against a missing column.
    try {
        $schemaStmt = $pdo->query("SHOW COLUMNS FROM user_notifications LIKE 'dismissed_at'");
        if (!$schemaStmt || $schemaStmt->rowCount() === 0) {
            throw new PDOException('dismissed_at column is missing');
        }
    } catch (PDOException $e) {
        error_log('[notifications] Required schema missing: user_notifications.dismissed_at. ' .
                  'Run migrations/2026_09_09_notification_dismissal.php. Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Notification schema is not current. Please run migrations/2026_09_09_notification_dismissal.php.'
        ]);
        exit;
    }

    $initialized = true;
}

// Ensure table exists
ensureUserNotificationsTable();

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'count') {
        // Get unread notification count
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM user_notifications
                WHERE user_id = :user_id
                AND practice_id = :practice_id
                AND is_read = FALSE
                AND dismissed_at IS NULL
            ");
            $stmt->execute([
                'user_id' => $userId,
                'practice_id' => $currentPracticeId
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'count' => (int)$result['count']
            ]);
        } catch (PDOException $e) {
            error_log('[notifications] Error getting count: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error getting notification count']);
        }
        
    } else {
        // List notifications
        $limit = min((int)($_GET['limit'] ?? 20), 50);
        $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
        
        try {
            $sql = "
                SELECT n.id, n.notification_type, n.case_id, n.comment_id,
                       n.from_user_id, n.from_user_name, n.preview_text,
                       n.is_read, n.created_at, n.metadata_json, n.event_id,
                       e.event_type, e.event_categories, e.metadata_json as event_metadata,
                       u.first_name as actor_first_name, u.last_name as actor_last_name
                FROM user_notifications n
                LEFT JOIN notification_events e ON n.event_id = e.id
                LEFT JOIN users u ON u.id = n.from_user_id
                WHERE n.user_id = :user_id
                AND n.practice_id = :practice_id
                AND n.dismissed_at IS NULL
            ";

            if ($unreadOnly) {
                $sql .= " AND n.is_read = FALSE";
            }

            $sql .= " ORDER BY n.created_at DESC LIMIT :limit";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':practice_id', $currentPracticeId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format notifications
            $formatted = array_map(function($n) {
                $eventType = $n['event_type'] ?? $n['notification_type'];
                $categories = [];
                if (!empty($n['event_categories'])) {
                    $decoded = json_decode($n['event_categories'], true);
                    if (is_array($decoded)) {
                        $categories = $decoded;
                    }
                }
                if (empty($categories) && $n['notification_type'] === 'mention') {
                    $categories = ['mention'];
                }

                $metadata = [];
                $metadataSource = $n['metadata_json'] ?? $n['event_metadata'] ?? null;
                if (!empty($metadataSource)) {
                    $decoded = json_decode($metadataSource, true);
                    if (is_array($decoded)) {
                        $metadata = $decoded;
                    }
                }

                // Resolve a current, safe actor display name. System events use the
                // application label; known users with no name fall back to a
                // generic team label instead of persisting "Unknown".
                if (empty($n['from_user_id'])) {
                    $actorDisplayName = (string)t('notifications.system_label');
                    if ($actorDisplayName === '') {
                        $actorDisplayName = 'DentaTrak';
                    }
                } else {
                    $actorDisplayName = trim(($n['actor_first_name'] ?? '') . ' ' . ($n['actor_last_name'] ?? ''));
                    if ($actorDisplayName === '') {
                        $actorDisplayName = $n['from_user_name'];
                        if ($actorDisplayName === '' || $actorDisplayName === 'Unknown') {
                            $actorDisplayName = (string)t('notifications.team_member');
                            if ($actorDisplayName === '') {
                                $actorDisplayName = 'A team member';
                            }
                        }
                    }
                }

                return [
                    'id' => (int)$n['id'],
                    'type' => $eventType,
                    'case_id' => $n['case_id'],
                    'comment_id' => $n['comment_id'] ? (int)$n['comment_id'] : null,
                    'from_user_name' => $actorDisplayName,
                    'preview' => $n['preview_text'],
                    'categories' => $categories,
                    'metadata' => $metadata,
                    'is_read' => (bool)$n['is_read'],
                    'created_at' => $n['created_at']
                ];
            }, $notifications);
            
            echo json_encode([
                'success' => true,
                'notifications' => $formatted
            ]);
        } catch (PDOException $e) {
            error_log('[notifications] Error listing: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error listing notifications']);
        }
    }
    
} elseif ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token']);
        exit;
    }

    $action = $input['action'] ?? 'mark_read';
    
    if ($action === 'mark_read') {
        // Mark notification(s) as read
        $notificationId = $input['notification_id'] ?? null;
        $markAll = $input['mark_all'] ?? false;
        
        try {
            if ($markAll) {
                // Mark all as read
                $stmt = $pdo->prepare("
                    UPDATE user_notifications 
                    SET is_read = TRUE, read_at = NOW()
                    WHERE user_id = :user_id 
                    AND practice_id = :practice_id
                    AND is_read = FALSE
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'practice_id' => $currentPracticeId
                ]);
            } elseif ($notificationId) {
                // Mark single notification as read
                $stmt = $pdo->prepare("
                    UPDATE user_notifications 
                    SET is_read = TRUE, read_at = NOW()
                    WHERE id = :id 
                    AND user_id = :user_id 
                    AND practice_id = :practice_id
                ");
                $stmt->execute([
                    'id' => $notificationId,
                    'user_id' => $userId,
                    'practice_id' => $currentPracticeId
                ]);
            }
            
            echo json_encode(['success' => true]);
            
        } catch (PDOException $e) {
            error_log('[notifications] Error marking read: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error marking notification as read']);
        }
        
    } elseif ($action === 'mark_case_read') {
        // Mark all notifications for a specific case as read
        $caseId = $input['case_id'] ?? null;

        if (!$caseId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Case ID required']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE user_notifications
                SET is_read = TRUE, read_at = NOW()
                WHERE user_id = :user_id
                AND practice_id = :practice_id
                AND case_id = :case_id
                AND is_read = FALSE
            ");
            $stmt->execute([
                'user_id' => $userId,
                'practice_id' => $currentPracticeId,
                'case_id' => $caseId
            ]);

            echo json_encode(['success' => true]);

        } catch (PDOException $e) {
            error_log('[notifications] Error marking case read: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error marking notifications as read']);
        }

    } elseif ($action === 'dismiss') {
        // Dismiss a single notification for the current user.
        // The row is retained for audit/retention and simply hidden from the panel.
        $notificationId = $input['notification_id'] ?? null;

        if (!$notificationId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE user_notifications
                SET dismissed_at = NOW()
                WHERE id = :id
                AND user_id = :user_id
                AND practice_id = :practice_id
                AND dismissed_at IS NULL
            ");
            $stmt->execute([
                'id' => $notificationId,
                'user_id' => $userId,
                'practice_id' => $currentPracticeId
            ]);

            echo json_encode(['success' => true]);

        } catch (PDOException $e) {
            error_log('[notifications] Error dismissing notification: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error dismissing notification']);
        }
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
