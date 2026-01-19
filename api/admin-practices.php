<?php
/**
 * Admin Practices API
 * 
 * Provides admin functionality for managing practices:
 * - List all practices with HIPAA compliance status
 * - Activate/deactivate practices
 * - View PHI access logs
 * - Data retention management
 * 
 * SECURITY: Only accessible by system admins (users with is_system_admin = true)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/practice-security.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Load dev tools access control
require_once __DIR__ . '/dev-tools-access.php';

// Check if current user can access admin pages (super user OR dev environment)
$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super user privileges required.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        handleGetRequest($action);
        break;
    case 'POST':
        handlePostRequest($action);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

function handleGetRequest($action) {
    switch ($action) {
        case 'list':
            // List all practices with compliance status
            $practices = getAllPracticesWithComplianceStatus();
            echo json_encode([
                'success' => true,
                'practices' => $practices,
                'data_retention_years' => DATA_RETENTION_YEARS
            ]);
            break;
            
        case 'compliance':
            // Get compliance summary for a specific practice
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $summary = getComplianceSummary($practiceId);
            echo json_encode([
                'success' => true,
                'compliance' => $summary
            ]);
            break;
            
        case 'phi_log':
            // Get PHI access log for a practice
            $practiceId = $_GET['practice_id'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 100), 500);
            $offset = (int)($_GET['offset'] ?? 0);
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $log = getPHIAccessLog($practiceId, $limit, $offset);
            echo json_encode([
                'success' => true,
                'log' => $log,
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        case 'users':
            // Get users for a practice
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $users = getPracticeUsers($practiceId);
            echo json_encode([
                'success' => true,
                'users' => $users
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function handlePostRequest($action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'deactivate':
            // Deactivate a practice
            $practiceId = $input['practice_id'] ?? null;
            $reason = $input['reason'] ?? 'Deactivated by administrator';
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $result = deactivatePractice($practiceId, $reason, $_SESSION['db_user_id']);
            
            if ($result) {
                // Log this admin action
                logAdminAction('practice_deactivated', [
                    'practice_id' => $practiceId,
                    'reason' => $reason
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Practice deactivated successfully',
                    'deletion_eligible_at' => (new DateTime())->modify('+' . DATA_RETENTION_YEARS . ' years')->format('Y-m-d')
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to deactivate practice']);
            }
            break;
            
        case 'reactivate':
            // Reactivate a practice
            $practiceId = $input['practice_id'] ?? null;
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $result = reactivatePractice($practiceId);
            
            if ($result) {
                // Log this admin action
                logAdminAction('practice_reactivated', ['practice_id' => $practiceId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Practice reactivated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to reactivate practice']);
            }
            break;
            
        case 'delete':
            // Permanently delete a practice (only if retention period has passed)
            $practiceId = $input['practice_id'] ?? null;
            $confirmDelete = $input['confirm'] ?? false;
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            // Check if practice is eligible for deletion
            $status = checkPracticeStatus($practiceId);
            
            if (!$status['can_delete']) {
                $yearsRemaining = DATA_RETENTION_YEARS - ($status['years_inactive'] ?? 0);
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete practice. Data must be retained for " . DATA_RETENTION_YEARS . " years. " .
                                 "This practice has been inactive for " . ($status['years_inactive'] ?? 0) . " years. " .
                                 "Deletion will be available in approximately " . $yearsRemaining . " more years.",
                    'years_remaining' => $yearsRemaining
                ]);
                return;
            }
            
            if (!$confirmDelete) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Deletion requires confirmation. Set confirm=true to proceed.',
                    'warning' => 'This action is PERMANENT and cannot be undone. All practice data will be deleted.'
                ]);
                return;
            }
            
            // Perform deletion (implement this carefully)
            $result = permanentlyDeletePractice($practiceId);
            
            if ($result) {
                logAdminAction('practice_deleted', ['practice_id' => $practiceId]);
                echo json_encode(['success' => true, 'message' => 'Practice permanently deleted']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to delete practice']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function getPracticeUsers($practiceId) {
    global $pdo;
    
    try {
        // Check which columns exist to build a compatible query
        $userColumns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $puColumns = $pdo->query("SHOW COLUMNS FROM practice_users")->fetchAll(PDO::FETCH_COLUMN);
        
        $hasLastLoginAt = in_array('last_login_at', $userColumns);
        $hasIsOwner = in_array('is_owner', $puColumns);
        
        $lastLoginSelect = $hasLastLoginAt ? 'u.last_login_at as last_login' : 'NULL as last_login';
        $isOwnerSelect = $hasIsOwner ? 'IFNULL(pu.is_owner, 0) as is_owner' : '0 as is_owner';
        $orderBy = $hasIsOwner ? 'pu.is_owner DESC, pu.role, u.email' : 'pu.role, u.email';
        
        $sql = "
            SELECT 
                u.id,
                u.email,
                IFNULL(u.first_name, '') as first_name,
                IFNULL(u.last_name, '') as last_name,
                u.created_at as user_created_at,
                $lastLoginSelect,
                IFNULL(pu.role, 'user') as role,
                $isOwnerSelect,
                pu.created_at as joined_at
            FROM practice_users pu
            JOIN users u ON pu.user_id = u.id
            WHERE pu.practice_id = ?
            ORDER BY $orderBy
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$practiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting practice users: ' . $e->getMessage());
        return [];
    }
}

function permanentlyDeletePractice($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) return false;
    
    try {
        $pdo->beginTransaction();
        
        // Delete in order of dependencies
        $pdo->prepare("DELETE FROM phi_access_log WHERE practice_id = ?")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM case_activity_log WHERE case_id IN (SELECT id FROM cases_cache WHERE practice_id = ?)")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM cases_cache WHERE practice_id = ?")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM practice_users WHERE practice_id = ?")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM practices WHERE id = ?")->execute([$practiceId]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[admin-practices] Error deleting practice: ' . $e->getMessage());
        return false;
    }
}

function logAdminAction($action, $details = []) {
    global $pdo;
    
    try {
        // Ensure admin_audit_log table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            admin_email VARCHAR(255),
            action VARCHAR(100) NOT NULL,
            details_json TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_user_id (admin_user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $stmt = $pdo->prepare("
            INSERT INTO admin_audit_log (admin_user_id, admin_email, action, details_json, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['db_user_id'],
            $_SESSION['user_email'] ?? '',
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error logging admin action: ' . $e->getMessage());
    }
}
