<?php
/**
 * Case Comments API
 * Handles internal comment threads with @mentions for cases
 * Comments are for discussion/coordination, NOT documentation (that's Notes)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/case-activity-log.php';

header('Content-Type: application/json');

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
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_practice_id (practice_id),
        INDEX idx_is_read (is_read),
        INDEX idx_created_at (created_at),
        INDEX idx_case_id (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[user_notifications] Error creating table: ' . $e->getMessage());
    }
}

/**
 * Extract @mentions from comment text
 * Returns array of mentioned identifiers (emails or names)
 */
function extractMentions($text) {
    $mentions = [];
    // Match @name patterns (alphanumeric, dots, underscores, hyphens)
    if (preg_match_all('/@([a-zA-Z0-9._-]+)/', $text, $matches)) {
        $mentions = array_unique($matches[1]);
    }
    return $mentions;
}

/**
 * Resolve mention identifiers to user records
 */
function resolveMentions($mentionIdentifiers, $practiceId) {
    global $pdo;
    $resolved = [];
    
    if (empty($mentionIdentifiers)) {
        return $resolved;
    }
    
    foreach ($mentionIdentifiers as $identifier) {
        // Search by name or email prefix
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name 
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = :practice_id
            AND (
                LOWER(CONCAT(IFNULL(u.first_name, ''), ' ', IFNULL(u.last_name, ''))) LIKE LOWER(:name_pattern)
                OR LOWER(u.email) LIKE LOWER(:email_pattern)
                OR LOWER(CONCAT(IFNULL(u.first_name, ''), IFNULL(u.last_name, ''))) LIKE LOWER(:name_no_space)
            )
            LIMIT 1
        ");
        $stmt->execute([
            'practice_id' => $practiceId,
            'name_pattern' => '%' . $identifier . '%',
            'email_pattern' => $identifier . '%',
            'name_no_space' => '%' . $identifier . '%'
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            $resolved[] = [
                'user_id' => (int)$user['id'],
                'email' => $user['email'],
                'name' => $fullName ?: $user['email'],
                'mention' => $identifier
            ];
        }
    }
    
    return $resolved;
}

/**
 * Create notification records for mentioned users
 */
function createMentionNotifications($commentId, $caseId, $practiceId, $mentions, $fromUserId, $fromUserName, $commentText) {
    global $pdo;
    
    // Create preview text (first 100 chars)
    $preview = mb_strlen($commentText) > 100 
        ? mb_substr($commentText, 0, 100) . '...' 
        : $commentText;
    
    foreach ($mentions as $mention) {
        // Don't notify yourself
        if ($mention['user_id'] == $fromUserId) {
            continue;
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_notifications 
                (user_id, practice_id, notification_type, case_id, comment_id, from_user_id, from_user_name, preview_text)
                VALUES (:user_id, :practice_id, 'mention', :case_id, :comment_id, :from_user_id, :from_user_name, :preview_text)
            ");
            $stmt->execute([
                'user_id' => $mention['user_id'],
                'practice_id' => $practiceId,
                'case_id' => $caseId,
                'comment_id' => $commentId,
                'from_user_id' => $fromUserId,
                'from_user_name' => $fromUserName,
                'preview_text' => $preview
            ]);
        } catch (PDOException $e) {
            error_log('[notifications] Error creating mention notification: ' . $e->getMessage());
        }
    }
}

// Ensure tables exist
ensureCaseCommentsTable();
ensureUserNotificationsTable();

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$userName = $_SESSION['user_name'] ?? $userEmail;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get comments for a case
    $caseId = $_GET['case_id'] ?? null;
    
    if (!$caseId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Case ID required']);
        exit;
    }
    
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
    $input = json_decode(file_get_contents('php://input'), true);
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
        
        // Extract and resolve mentions
        $mentionIdentifiers = extractMentions($commentText);
        $resolvedMentions = resolveMentions($mentionIdentifiers, $currentPracticeId);
        
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
            
            // Create notifications for mentioned users
            if (!empty($resolvedMentions)) {
                createMentionNotifications($commentId, $caseId, $currentPracticeId, $resolvedMentions, $userId, $userName, $commentText);
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
        
        try {
            // Get comment info for audit
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
