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
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/lab-assignment-history.php';

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
            ensureAdminHiddenPracticesSchema();
            $practices = getAllPracticesWithComplianceStatus();
            $hiddenIds = getAdminHiddenPracticeIds($_SESSION['db_user_id'] ?? 0);
            foreach ($practices as &$practice) {
                $practice['is_hidden'] = in_array((int)$practice['id'], $hiddenIds, true);
            }
            unset($practice);
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
            
        case 'settings':
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }
            
            $settings = getPracticeSettings($practiceId);
            echo json_encode([
                'success' => true,
                'settings' => $settings
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
            
        case 'hide':
            $practiceId = $input['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }
            
            ensureAdminHiddenPracticesSchema();
            $result = hidePracticeForAdmin($practiceId, $_SESSION['db_user_id'] ?? 0);
            
            if ($result) {
                logAdminAction('practice_hidden', ['practice_id' => $practiceId]);
                echo json_encode(['success' => true, 'message' => 'Practice hidden from admin view']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to hide practice']);
            }
            break;
            
        case 'unhide':
            $practiceId = $input['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }
            
            ensureAdminHiddenPracticesSchema();
            $result = unhidePracticeForAdmin($practiceId, $_SESSION['db_user_id'] ?? 0);
            
            if ($result) {
                logAdminAction('practice_unhidden', ['practice_id' => $practiceId]);
                echo json_encode(['success' => true, 'message' => 'Practice unhidden']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to unhide practice']);
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
        $hasIsActive = in_array('is_active', $userColumns);
        $hasLimitedVisibility = in_array('limited_visibility', $puColumns);
        $hasCanViewAnalytics = in_array('can_view_analytics', $puColumns);
        $hasCanEditCases = in_array('can_edit_cases', $puColumns);
        $hasIsLab = in_array('is_lab', $puColumns);

        $lastLoginSelect = $hasLastLoginAt ? 'u.last_login_at as last_login' : 'NULL as last_login';
        $isOwnerSelect = $hasIsOwner ? 'IFNULL(pu.is_owner, 0) as is_owner' : '0 as is_owner';
        $isActiveSelect = $hasIsActive ? 'IFNULL(u.is_active, 1) as is_active' : '1 as is_active';
        $limitedVisibilitySelect = $hasLimitedVisibility ? 'IFNULL(pu.limited_visibility, 0) as limited_visibility' : '0 as limited_visibility';
        $canViewAnalyticsSelect = $hasCanViewAnalytics ? 'IFNULL(pu.can_view_analytics, 0) as can_view_analytics' : '0 as can_view_analytics';
        $canEditCasesSelect = $hasCanEditCases ? 'IFNULL(pu.can_edit_cases, 0) as can_edit_cases' : '0 as can_edit_cases';
        $isLabSelect = $hasIsLab ? 'IFNULL(pu.is_lab, 0) as is_lab' : '0 as is_lab';
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
                $isActiveSelect,
                $limitedVisibilitySelect,
                $canViewAnalyticsSelect,
                $canEditCasesSelect,
                $isLabSelect,
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

/**
 * Ensure the admin_hidden_practices table exists.
 */
function ensureAdminHiddenPracticesSchema() {
    global $pdo;
    static $initialized = false;
    
    if ($initialized || !$pdo) {
        return;
    }
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_hidden_practices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            practice_id BIGINT UNSIGNED NOT NULL,
            hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_user_id (admin_user_id),
            INDEX idx_practice_id (practice_id),
            UNIQUE KEY idx_admin_practice (admin_user_id, practice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error ensuring admin hidden practices schema: ' . $e->getMessage());
    }
}

/**
 * Get the practice IDs hidden by the given admin.
 */
function getAdminHiddenPracticeIds($adminUserId) {
    global $pdo;
    
    if (!$pdo || !$adminUserId) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT practice_id FROM admin_hidden_practices WHERE admin_user_id = ?");
        $stmt->execute([$adminUserId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting hidden practice IDs: ' . $e->getMessage());
        return [];
    }
}

/**
 * Hide a practice from the admin's view.
 */
function hidePracticeForAdmin($practiceId, $adminUserId) {
    global $pdo;
    
    if (!$pdo || !$practiceId || !$adminUserId || !is_numeric($practiceId)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO admin_hidden_practices (admin_user_id, practice_id, hidden_at) VALUES (?, ?, NOW())");
        return $stmt->execute([$adminUserId, $practiceId]);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error hiding practice: ' . $e->getMessage());
        return false;
    }
}

/**
 * Unhide a practice from the admin's view.
 */
function unhidePracticeForAdmin($practiceId, $adminUserId) {
    global $pdo;
    
    if (!$pdo || !$practiceId || !$adminUserId || !is_numeric($practiceId)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM admin_hidden_practices WHERE admin_user_id = ? AND practice_id = ?");
        return $stmt->execute([$adminUserId, $practiceId]);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error unhiding practice: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get a representative user ID for a practice (owner/creator preferred, then first admin).
 */
function getRepresentativeUserId($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT created_by FROM practices WHERE id = ?");
        $stmt->execute([$practiceId]);
        $createdBy = $stmt->fetchColumn();
        
        if ($createdBy) {
            $stmt = $pdo->prepare("SELECT 1 FROM user_preferences WHERE user_id = ?");
            $stmt->execute([$createdBy]);
            if ($stmt->fetchColumn()) {
                return (int)$createdBy;
            }
        }
        
        $puColumns = $pdo->query("SHOW COLUMNS FROM practice_users")->fetchAll(PDO::FETCH_COLUMN);
        $hasIsOwner = in_array('is_owner', $puColumns);
        
        $ownerClause = $hasIsOwner ? "OR pu.is_owner = 1" : "";
        $stmt = $pdo->prepare("SELECT u.id 
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = ? AND (pu.role = 'admin' {$ownerClause})
            ORDER BY pu.created_at ASC, u.id ASC
            LIMIT 1");
        $stmt->execute([$practiceId]);
        $adminId = $stmt->fetchColumn();
        
        if ($adminId) {
            return (int)$adminId;
        }
        
        $stmt = $pdo->prepare("SELECT u.id 
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = ?
            ORDER BY pu.created_at ASC
            LIMIT 1");
        $stmt->execute([$practiceId]);
        $firstUserId = $stmt->fetchColumn();
        return $firstUserId ? (int)$firstUserId : null;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting representative user: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get user preference values for a representative user.
 */
function getRepresentativeUserPreferences($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return [];
    }
    
    $userId = getRepresentativeUserId($practiceId);
    if (!$userId) {
        return [];
    }
    
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM user_preferences")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error reading user_preferences columns: ' . $e->getMessage());
        return [];
    }
    
    $desired = [
        'allow_card_delete',
        'delivered_hide_days',
        'highlight_past_due',
        'past_due_days',
        'highlight_coming_due',
        'coming_due_days'
    ];
    $available = array_intersect($desired, $columns);
    
    if (empty($available)) {
        return [];
    }
    
    try {
        $sql = "SELECT " . implode(', ', $available) . " FROM user_preferences WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting user preferences: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get assignment labels for the Settings tab.
 */
function getPracticeAssignmentLabelsForSettings($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return [];
    }
    
    ensureLabDesignationColumns();
    
    try {
        $stmt = $pdo->prepare("SELECT label, is_lab FROM practice_assignment_labels WHERE practice_id = ? ORDER BY sort_order ASC, label ASC");
        $stmt->execute([$practiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting assignment labels: ' . $e->getMessage());
        return [];
    }
}

/**
 * Check if any practice user has two-factor authentication enabled.
 */
function getTwoFactorEnabledForPractice($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return false;
    }
    
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_enabled'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('totp_enabled', $columns)) {
            return false;
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN practice_users pu ON u.id = pu.user_id WHERE pu.practice_id = ? AND u.totp_enabled = 1");
        $stmt->execute([$practiceId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error checking 2FA status: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get read-only Settings payload for the practice.
 */
function getPracticeSettings($practiceId) {
    global $pdo;
    
    $preferences = getRepresentativeUserPreferences($practiceId);
    $workflowLabels = getResolvedWorkflowStageLabels(
        getWorkflowStageLabelOverridesForPractice($practiceId)
    );
    $users = getPracticeUsers($practiceId);
    $labels = getPracticeAssignmentLabelsForSettings($practiceId);
    $twoFactorEnabled = getTwoFactorEnabledForPractice($practiceId);
    
    $deliveredHideDays = isset($preferences['delivered_hide_days']) ? (int)$preferences['delivered_hide_days'] : 0;
    $autoArchive = $deliveredHideDays > 0;
    
    $allowArchiving = isset($preferences['allow_card_delete']) ? (bool)$preferences['allow_card_delete'] : true;
    $archiveAfterDays = $autoArchive ? $deliveredHideDays : 0;
    
    $userList = [];
    foreach ($users as $u) {
        $isOwner = !empty($u['is_owner']);
        $isAdmin = $isOwner || (($u['role'] ?? '') === 'admin');
        $userList[] = [
            'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? ''),
            'email' => $u['email'] ?? '',
            'role' => $isOwner ? 'Owner' : 'User',
            'admin' => $isAdmin,
            'assigned_only' => !$isOwner && !empty($u['limited_visibility']),
            'insights' => $isOwner || !empty($u['can_view_analytics']),
            'edit_cases' => $isOwner || !empty($u['can_edit_cases']),
            'lab' => !empty($u['is_lab']),
            'active' => !empty($u['is_active'])
        ];
    }
    
    $labelList = [];
    foreach ($labels as $l) {
        $labelList[] = [
            'label' => $l['label'],
            'is_lab' => (bool)$l['is_lab']
        ];
    }
    
    return [
        'case_management' => [
            'allow_archiving_individual_cases' => $allowArchiving,
            'auto_archive_delivered_cases' => $autoArchive,
            'archive_delivered_cases_after_days' => $archiveAfterDays
        ],
        'due_date_highlighting' => [
            'highlight_past_due' => isset($preferences['highlight_past_due']) ? (bool)$preferences['highlight_past_due'] : false,
            'past_due_days' => isset($preferences['past_due_days']) ? (int)$preferences['past_due_days'] : 0,
            'highlight_coming_due' => isset($preferences['highlight_coming_due']) ? (bool)$preferences['highlight_coming_due'] : false,
            'coming_due_days' => isset($preferences['coming_due_days']) ? (int)$preferences['coming_due_days'] : 5,
            'highlight_appointment_risk' => isset($preferences['highlight_appointment_risk']) ? (bool)$preferences['highlight_appointment_risk'] : true,
            'appointment_risk_days' => isset($preferences['appointment_risk_days']) ? (int)$preferences['appointment_risk_days'] : 3
        ],
        'workflow_stages' => $workflowLabels,
        'users' => $userList,
        'assignment_labels' => $labelList,
        'security' => [
            'two_factor_authentication_enabled' => $twoFactorEnabled
        ]
    ];
}
