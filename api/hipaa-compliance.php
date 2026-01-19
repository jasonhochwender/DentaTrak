<?php
/**
 * HIPAA Compliance Management
 * 
 * Handles:
 * - PHI access logging
 * - Practice active/inactive status
 * - Data retention policies (7-year minimum)
 * - Audit trail management
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';

// Data retention period in years (HIPAA requires minimum 6 years, we use 7 for safety)
if (!defined('DATA_RETENTION_YEARS')) {
    define('DATA_RETENTION_YEARS', 7);
}

/**
 * Ensure HIPAA-related database tables exist
 */
function ensureHIPAASchema() {
    global $pdo;
    static $initialized = false;
    
    if ($initialized || !$pdo) {
        return;
    }
    
    try {
        // PHI Access Log - tracks every time PHI is viewed/accessed
        $pdo->exec("CREATE TABLE IF NOT EXISTS phi_access_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            user_email VARCHAR(255),
            practice_id BIGINT UNSIGNED NOT NULL,
            case_id VARCHAR(64),
            access_type VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_practice_id (practice_id),
            INDEX idx_case_id (case_id),
            INDEX idx_access_type (access_type),
            INDEX idx_accessed_at (accessed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Add active status and retention columns to practices if not exists
        $columns = $pdo->query("SHOW COLUMNS FROM practices")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('is_active', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN is_active BOOLEAN DEFAULT TRUE");
        }
        if (!in_array('deactivated_at', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN deactivated_at DATETIME DEFAULT NULL");
        }
        if (!in_array('deactivated_by', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN deactivated_by BIGINT UNSIGNED DEFAULT NULL");
        }
        if (!in_array('deactivation_reason', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN deactivation_reason TEXT DEFAULT NULL");
        }
        if (!in_array('data_deletion_eligible_at', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN data_deletion_eligible_at DATETIME DEFAULT NULL");
        }
        if (!in_array('last_activity_at', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN last_activity_at DATETIME DEFAULT NULL");
        }
        if (!in_array('google_drive_folder_id', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN google_drive_folder_id VARCHAR(255) DEFAULT NULL");
        }
        if (!in_array('google_drive_backup_enabled', $columns)) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN google_drive_backup_enabled BOOLEAN DEFAULT FALSE");
        }
        
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[HIPAA] Error ensuring schema: ' . $e->getMessage());
    }
}

/**
 * Log PHI access event
 * 
 * @param string $accessType Type of access (view_case, view_case_list, export_case, print_case, etc.)
 * @param string|null $caseId Case ID if applicable
 * @param array $meta Additional metadata
 */
function logPHIAccess($accessType, $caseId = null, $meta = []) {
    global $pdo;
    
    if (!$pdo) return;
    
    ensureHIPAASchema();
    
    $userId = $_SESSION['db_user_id'] ?? null;
    $userEmail = $_SESSION['user_email'] ?? null;
    $practiceId = $_SESSION['current_practice_id'] ?? null;
    
    if (!$userId || !$practiceId) return;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO phi_access_log (
                user_id, user_email, practice_id, case_id, 
                access_type, ip_address, user_agent, accessed_at
            ) VALUES (
                :user_id, :user_email, :practice_id, :case_id,
                :access_type, :ip_address, :user_agent, NOW()
            )
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'user_email' => $userEmail,
            'practice_id' => $practiceId,
            'case_id' => $caseId,
            'access_type' => $accessType,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
        
        // Also update practice last activity
        $pdo->prepare("UPDATE practices SET last_activity_at = NOW() WHERE id = ?")->execute([$practiceId]);
        
    } catch (PDOException $e) {
        error_log('[HIPAA] Error logging PHI access: ' . $e->getMessage());
    }
}

/**
 * Check if a practice is active
 * 
 * @param int $practiceId Practice ID
 * @return array ['active' => bool, 'deactivated_at' => datetime, 'years_inactive' => int, 'deletion_eligible_at' => datetime]
 */
function checkPracticeStatus($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return ['active' => false, 'error' => 'Invalid practice'];
    }
    
    ensureHIPAASchema();
    
    try {
        $stmt = $pdo->prepare("
            SELECT is_active, deactivated_at, deactivation_reason, data_deletion_eligible_at, last_activity_at
            FROM practices WHERE id = ?
        ");
        $stmt->execute([$practiceId]);
        $practice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$practice) {
            return ['active' => false, 'error' => 'Practice not found'];
        }
        
        $isActive = $practice['is_active'] ?? true;
        $deactivatedAt = $practice['deactivated_at'];
        $deletionEligibleAt = $practice['data_deletion_eligible_at'];
        
        $yearsInactive = 0;
        if ($deactivatedAt) {
            $deactivatedDate = new DateTime($deactivatedAt);
            $now = new DateTime();
            $yearsInactive = $now->diff($deactivatedDate)->y;
        }
        
        return [
            'active' => (bool)$isActive,
            'deactivated_at' => $deactivatedAt,
            'deactivation_reason' => $practice['deactivation_reason'],
            'years_inactive' => $yearsInactive,
            'deletion_eligible_at' => $deletionEligibleAt,
            'can_delete' => $deletionEligibleAt && (new DateTime($deletionEligibleAt) <= new DateTime()),
            'last_activity_at' => $practice['last_activity_at']
        ];
    } catch (PDOException $e) {
        error_log('[HIPAA] Error checking practice status: ' . $e->getMessage());
        return ['active' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Deactivate a practice
 * 
 * @param int $practiceId Practice ID
 * @param string $reason Reason for deactivation
 * @param int $deactivatedBy User ID who deactivated
 * @return bool Success
 */
function deactivatePractice($practiceId, $reason = '', $deactivatedBy = null) {
    global $pdo;
    
    if (!$pdo || !$practiceId) return false;
    
    ensureHIPAASchema();
    
    try {
        $deletionEligibleAt = (new DateTime())->modify('+' . DATA_RETENTION_YEARS . ' years')->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare("
            UPDATE practices SET 
                is_active = FALSE,
                deactivated_at = NOW(),
                deactivated_by = :deactivated_by,
                deactivation_reason = :reason,
                data_deletion_eligible_at = :deletion_eligible_at
            WHERE id = :practice_id
        ");
        
        $result = $stmt->execute([
            'practice_id' => $practiceId,
            'deactivated_by' => $deactivatedBy ?? $_SESSION['db_user_id'] ?? null,
            'reason' => $reason,
            'deletion_eligible_at' => $deletionEligibleAt
        ]);
        
        // Log this action
        logSecurityEvent('practice_deactivated', [
            'practice_id' => $practiceId,
            'reason' => $reason,
            'deletion_eligible_at' => $deletionEligibleAt
        ]);
        
        return $result;
    } catch (PDOException $e) {
        error_log('[HIPAA] Error deactivating practice: ' . $e->getMessage());
        return false;
    }
}

/**
 * Reactivate a practice
 * 
 * @param int $practiceId Practice ID
 * @return bool Success
 */
function reactivatePractice($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) return false;
    
    ensureHIPAASchema();
    
    try {
        $stmt = $pdo->prepare("
            UPDATE practices SET 
                is_active = TRUE,
                deactivated_at = NULL,
                deactivated_by = NULL,
                deactivation_reason = NULL,
                data_deletion_eligible_at = NULL
            WHERE id = :practice_id
        ");
        
        $result = $stmt->execute(['practice_id' => $practiceId]);
        
        // Log this action
        logSecurityEvent('practice_reactivated', ['practice_id' => $practiceId]);
        
        return $result;
    } catch (PDOException $e) {
        error_log('[HIPAA] Error reactivating practice: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all practices with HIPAA compliance status
 * 
 * @return array List of practices with compliance info
 */
function getAllPracticesWithComplianceStatus() {
    global $pdo;
    
    if (!$pdo) return [];
    
    ensureHIPAASchema();
    
    try {
        $stmt = $pdo->query("
            SELECT 
                p.id,
                p.practice_name,
                p.legal_name,
                p.display_name,
                p.is_active,
                p.deactivated_at,
                p.deactivation_reason,
                p.data_deletion_eligible_at,
                p.last_activity_at,
                p.baa_accepted,
                p.baa_accepted_at,
                p.baa_version,
                p.created_at,
                (SELECT COUNT(*) FROM practice_users WHERE practice_id = p.id) as user_count,
                (SELECT COUNT(*) FROM cases_cache WHERE practice_id = p.id) as case_count,
                (SELECT COUNT(*) FROM cases_cache WHERE practice_id = p.id AND archived = 1) as archived_case_count
            FROM practices p
            ORDER BY p.is_active DESC, p.practice_name ASC
        ");
        
        $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate additional compliance info
        foreach ($practices as &$practice) {
            $practice['years_inactive'] = 0;
            $practice['can_delete'] = false;
            
            if ($practice['deactivated_at']) {
                $deactivatedDate = new DateTime($practice['deactivated_at']);
                $now = new DateTime();
                $practice['years_inactive'] = $now->diff($deactivatedDate)->y;
                
                if ($practice['data_deletion_eligible_at']) {
                    $practice['can_delete'] = (new DateTime($practice['data_deletion_eligible_at']) <= new DateTime());
                }
            }
            
            // Days since last activity
            if ($practice['last_activity_at']) {
                $lastActivity = new DateTime($practice['last_activity_at']);
                $now = new DateTime();
                $practice['days_since_activity'] = $now->diff($lastActivity)->days;
            } else {
                $practice['days_since_activity'] = null;
            }
        }
        
        return $practices;
    } catch (PDOException $e) {
        error_log('[HIPAA] Error getting practices: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get PHI access log for a practice
 * 
 * @param int $practiceId Practice ID
 * @param int $limit Number of records
 * @param int $offset Offset for pagination
 * @return array Access log entries
 */
function getPHIAccessLog($practiceId, $limit = 100, $offset = 0) {
    global $pdo;
    
    if (!$pdo || !$practiceId) return [];
    
    ensureHIPAASchema();
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pal.id,
                pal.user_id,
                pal.user_email,
                pal.case_id,
                pal.access_type,
                pal.ip_address,
                pal.accessed_at
            FROM phi_access_log pal
            WHERE pal.practice_id = :practice_id
            ORDER BY pal.accessed_at DESC
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->bindValue(':practice_id', $practiceId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[HIPAA] Error getting PHI access log: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get HIPAA compliance summary for a practice
 * 
 * @param int $practiceId Practice ID
 * @return array Compliance summary
 */
function getComplianceSummary($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) return [];
    
    ensureHIPAASchema();
    
    try {
        // Get practice info
        $stmt = $pdo->prepare("SELECT * FROM practices WHERE id = ?");
        $stmt->execute([$practiceId]);
        $practice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$practice) return [];
        
        // Get user count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM practice_users WHERE practice_id = ?");
        $stmt->execute([$practiceId]);
        $userCount = $stmt->fetchColumn();
        
        // Get case counts
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(archived) as archived FROM cases_cache WHERE practice_id = ?");
        $stmt->execute([$practiceId]);
        $caseCounts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get PHI access stats (last 30 days)
        $stmt = $pdo->prepare("
            SELECT access_type, COUNT(*) as count 
            FROM phi_access_log 
            WHERE practice_id = ? AND accessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY access_type
        ");
        $stmt->execute([$practiceId]);
        $accessStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Get unique users who accessed PHI (last 30 days)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT user_id) 
            FROM phi_access_log 
            WHERE practice_id = ? AND accessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$practiceId]);
        $uniqueAccessors = $stmt->fetchColumn();
        
        return [
            'practice_id' => $practiceId,
            'practice_name' => $practice['practice_name'],
            'legal_name' => $practice['legal_name'],
            'is_active' => (bool)($practice['is_active'] ?? true),
            'baa_accepted' => (bool)$practice['baa_accepted'],
            'baa_accepted_at' => $practice['baa_accepted_at'],
            'baa_version' => $practice['baa_version'],
            'created_at' => $practice['created_at'],
            'last_activity_at' => $practice['last_activity_at'],
            'deactivated_at' => $practice['deactivated_at'],
            'data_deletion_eligible_at' => $practice['data_deletion_eligible_at'],
            'user_count' => (int)$userCount,
            'total_cases' => (int)($caseCounts['total'] ?? 0),
            'archived_cases' => (int)($caseCounts['archived'] ?? 0),
            'phi_access_last_30_days' => $accessStats,
            'unique_phi_accessors_last_30_days' => (int)$uniqueAccessors,
            'data_retention_years' => DATA_RETENTION_YEARS
        ];
    } catch (PDOException $e) {
        error_log('[HIPAA] Error getting compliance summary: ' . $e->getMessage());
        return [];
    }
}

// Initialize schema on include
ensureHIPAASchema();
