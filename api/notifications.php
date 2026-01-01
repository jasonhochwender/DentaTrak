<?php
/**
 * Notifications API
 * Handles user notifications (mentions, etc.)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';

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
                       n.is_read, n.created_at,
                       c.patient_first_name, c.patient_last_name
                FROM user_notifications n
                LEFT JOIN cases_cache c ON n.case_id = c.case_id
                WHERE n.user_id = :user_id 
                AND n.practice_id = :practice_id
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
                $patientName = trim(($n['patient_first_name'] ?? '') . ' ' . ($n['patient_last_name'] ?? ''));
                return [
                    'id' => (int)$n['id'],
                    'type' => $n['notification_type'],
                    'case_id' => $n['case_id'],
                    'comment_id' => $n['comment_id'] ? (int)$n['comment_id'] : null,
                    'from_user_name' => $n['from_user_name'],
                    'preview' => $n['preview_text'],
                    'patient_name' => $patientName ?: null,
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
    $input = json_decode(file_get_contents('php://input'), true);
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
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
