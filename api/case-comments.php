<?php
/**
 * Case Comments API
 * Handles internal comment threads with @mentions for cases
 * Comments are for discussion/coordination, NOT documentation (that's Notes)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/notification-service.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

if (!isFeatureEnabled('SHOW_COMMENTS')) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

/**
 * Ensure the case_comments table exists
 */
function ensureCaseCommentsTable() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS case_comments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        user_name VARCHAR(255) NOT NULL,
        user_email VARCHAR(255) NOT NULL,
        comment_text TEXT NOT NULL,
        mentions_json TEXT DEFAULT NULL,
        is_deleted BOOLEAN DEFAULT FALSE,
        deleted_at DATETIME DEFAULT NULL,
        deleted_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case_id (case_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at),
        INDEX idx_is_deleted (is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[case_comments] Error creating table: ' . $e->getMessage());
    }
}

// Keep user_notifications bootstrapped with the same columns the rest of the
// notification system expects, in case this endpoint is reached before the
// Phase 1 migration or api/notifications.php has run.
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
        metadata_json LONGTEXT,
        event_id BIGINT UNSIGNED DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_is_read (is_read),
        INDEX idx_created_at (created_at),
        INDEX idx_case_id (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        error_log('[case_comments] Error creating user_notifications table: ' . $e->getMessage());
        return;
    }

    // If an older table already exists without the notification-service columns,
    // add them idempotently (mirrors the Phase 1 migration).
    $columns = [
        'metadata_json' => "ALTER TABLE user_notifications ADD COLUMN metadata_json LONGTEXT",
        'expires_at'    => "ALTER TABLE user_notifications ADD COLUMN expires_at DATETIME DEFAULT NULL",
        'event_id'      => "ALTER TABLE user_notifications ADD COLUMN event_id BIGINT UNSIGNED DEFAULT NULL",
    ];

    foreach ($columns as $col => $alterSql) {
        try {
            $quotedCol = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM user_notifications LIKE {$quotedCol}");
            if ($stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        } catch (PDOException $e) {
            error_log('[case_comments] Error extending user_notifications: ' . $e->getMessage());
        }
    }

    $initialized = true;
}

// Ensure tables exist
ensureCaseCommentsTable();
ensureUserNotificationsTable();

$method = $_SERVER['REQUEST_METHOD'];

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$userName = $_SESSION['user_name'] ?? $userEmail;

// Validate CSRF for all state-changing requests.
if ($method === 'POST') {
    $inputForCsrf = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($inputForCsrf['csrf_token'] ?? null);
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token']);
        exit;
    }
    // Reset so the action below can re-read the body.
    $input = $inputForCsrf;
}

/**
 * Resolve submitted mention selections to active, case-authorized user records.
 *
 * The client sends the user IDs selected from autocomplete.  The server is the
 * authority: it looks each submitted ID up against the current practice's active
 * members and the specific case's access rules, excludes the comment author,
 * deduplicates, and records the client-supplied display token in mentions_json.
 *
 * @param array  $mentionData Array of ['user_id' => int, 'mention' => string]
 * @param int    $practiceId
 * @param string $caseId
 * @param int    $authorUserId
 * @return array Resolved mentions with keys: user_id, email, name, mention
 */
function resolveSubmittedMentions($mentionData, $practiceId, $caseId, $authorUserId) {
    $resolved = [];

    if (empty($mentionData) || !$practiceId || !$caseId) {
        return $resolved;
    }

    $authorizedUsers = getCaseAuthorizedUsers($practiceId, $caseId);
    if (empty($authorizedUsers)) {
        return $resolved;
    }

    // Index authorized users by user ID for exact, unambiguous lookup.
    $authorizedById = [];
    foreach ($authorizedUsers as $user) {
        $authorizedById[(int)$user['id']] = $user;
    }

    $seenUserIds = [];

    foreach ($mentionData as $entry) {
        $submittedUserId = isset($entry['user_id']) ? (int)$entry['user_id'] : 0;
        $displayToken = isset($entry['mention']) ? trim($entry['mention']) : '';

        if ($submittedUserId <= 0) {
            continue;
        }

        // Do not trust the client: the selected user must be active, a member,
        // and explicitly authorized to access this case.
        if (!isset($authorizedById[$submittedUserId])) {
            continue;
        }

        // Never notify the comment author, even on a self-mention.
        if ($submittedUserId === (int)$authorUserId) {
            continue;
        }

        // Deduplicate multiple tokens for the same user.
        if (isset($seenUserIds[$submittedUserId])) {
            continue;
        }

        $user = $authorizedById[$submittedUserId];
        $firstName = (string)($user['first_name'] ?? '');
        $lastName = (string)($user['last_name'] ?? '');
        $fullName = trim($firstName . ' ' . $lastName);

        $resolved[] = [
            'user_id' => $submittedUserId,
            'email' => $user['email'],
            'name' => $fullName ?: $user['email'],
            'mention' => $displayToken ?: ($fullName ? preg_replace('/\s+/', '', $fullName) : ''),
        ];
        $seenUserIds[$submittedUserId] = true;
    }

    return $resolved;
}

if ($method === 'GET') {
    // Get comments for a case
    $caseId = $_GET['case_id'] ?? null;

    if (!$caseId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Case ID required']);
        exit;
    }

    // SECURITY: Assigned Only users may only view comments on cases assigned to them.
    requireCaseAccess($caseId, $currentPracticeId);

    try {
        $stmt = $pdo->prepare("
            SELECT id, case_id, user_id, user_name, user_email, comment_text, 
                   mentions_json, is_deleted, created_at
            FROM case_comments
            WHERE case_id = :case_id 
            AND practice_id = :practice_id
            ORDER BY created_at ASC
        ");
        $stmt->execute([
            'case_id' => $caseId,
            'practice_id' => $currentPracticeId
        ]);

        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format comments for response
        $formattedComments = array_map(function($comment) {
            return [
                'id' => (int)$comment['id'],
                'case_id' => $comment['case_id'],
                'user_id' => (int)$comment['user_id'],
                'user_name' => $comment['user_name'],
                'user_email' => $comment['user_email'],
                'text' => $comment['is_deleted'] ? '[Comment removed]' : $comment['comment_text'],
                'mentions' => $comment['mentions_json'] ? json_decode($comment['mentions_json'], true) : [],
                'is_deleted' => (bool)$comment['is_deleted'],
                'created_at' => $comment['created_at']
            ];
        }, $comments);

        echo json_encode([
            'success' => true,
            'comments' => $formattedComments
        ]);

    } catch (PDOException $e) {
        error_log('[case_comments] Error fetching comments: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching comments']);
    }

} elseif ($method === 'POST') {
    $action = $input['action'] ?? 'create';

    if ($action === 'create') {
        // Create a new comment
        $caseId = $input['case_id'] ?? null;
        $commentText = trim($input['text'] ?? '');

        if (!$caseId || empty($commentText)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Case ID and comment text required']);
            exit;
        }

        // SECURITY: Assigned Only users may only comment on cases assigned to them.
        requireCaseAccess($caseId, $currentPracticeId);

        // Resolve submitted mentions against active, case-authorized users.
        $submittedMentions = $input['mentions'] ?? [];
        $resolvedMentions = resolveSubmittedMentions($submittedMentions, $currentPracticeId, $caseId, $userId);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO case_comments 
                (case_id, practice_id, user_id, user_name, user_email, comment_text, mentions_json)
                VALUES (:case_id, :practice_id, :user_id, :user_name, :user_email, :comment_text, :mentions_json)
            ");
            $stmt->execute([
                'case_id' => $caseId,
                'practice_id' => $currentPracticeId,
                'user_id' => $userId,
                'user_name' => $userName,
                'user_email' => $userEmail,
                'comment_text' => $commentText,
                'mentions_json' => !empty($resolvedMentions) ? json_encode($resolvedMentions) : null
            ]);

            $commentId = $pdo->lastInsertId();

            // Create in-app and email notifications for mentioned users.
            // This reuses the existing notification-service queue/worker path and
            // respects the SHOW_NOTIFICATIONS master flag.
            if (!empty($resolvedMentions)) {
                emitMentionNotificationEvent(
                    $currentPracticeId,
                    $caseId,
                    $userId,
                    $userName,
                    $commentId,
                    $resolvedMentions
                );
            }

            // Log to case activity
            ensureCaseActivityLogTable();
            logCaseActivity($caseId, 'comment_added', null, null, [
                'comment_id' => (int)$commentId,
                'has_mentions' => !empty($resolvedMentions),
                'mention_count' => count($resolvedMentions)
            ]);

            echo json_encode([
                'success' => true,
                'comment' => [
                    'id' => (int)$commentId,
                    'case_id' => $caseId,
                    'user_id' => (int)$userId,
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'text' => $commentText,
                    'mentions' => $resolvedMentions,
                    'is_deleted' => false,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (PDOException $e) {
            error_log('[case_comments] Error creating comment: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error creating comment']);
        }

    } elseif ($action === 'delete') {
        // Soft delete a comment (admin only)
        $commentId = $input['comment_id'] ?? null;

        if (!$commentId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Comment ID required']);
            exit;
        }

        try {
            // Get comment info for audit and access verification
            $stmt = $pdo->prepare("
                SELECT case_id, user_name FROM case_comments 
                WHERE id = :id AND practice_id = :practice_id
            ");
            $stmt->execute([
                'id' => $commentId,
                'practice_id' => $currentPracticeId
            ]);
            $comment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$comment) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Comment not found']);
                exit;
            }

            // SECURITY: Verify the requesting user can access the comment's case
            // in addition to the admin role requirement.
            requireCaseAccess($comment['case_id'], $currentPracticeId);

            // Check if user is admin
            $stmt = $pdo->prepare("
                SELECT role FROM practice_users 
                WHERE user_id = :user_id AND practice_id = :practice_id
            ");
            $stmt->execute([
                'user_id' => $userId,
                'practice_id' => $currentPracticeId
            ]);
            $userRole = $stmt->fetchColumn();

            if ($userRole !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only admins can delete comments']);
                exit;
            }

            // Soft delete
            $stmt = $pdo->prepare("
                UPDATE case_comments 
                SET is_deleted = TRUE, deleted_at = NOW(), deleted_by = :deleted_by
                WHERE id = :id AND practice_id = :practice_id
            ");
            $stmt->execute([
                'id' => $commentId,
                'practice_id' => $currentPracticeId,
                'deleted_by' => $userId
            ]);

            // Log deletion
            ensureCaseActivityLogTable();
            logCaseActivity($comment['case_id'], 'comment_deleted', null, null, [
                'comment_id' => (int)$commentId,
                'original_author' => $comment['user_name']
            ]);

            echo json_encode(['success' => true]);

        } catch (PDOException $e) {
            error_log('[case_comments] Error deleting comment: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Error deleting comment']);
        }
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
