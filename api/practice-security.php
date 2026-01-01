<?php
/**
 * Practice Security Module
 * 
 * Provides security functions to prevent cross-practice data access.
 * All API endpoints that access practice-specific data should use these functions.
 */

require_once __DIR__ . '/appConfig.php';

/**
 * Verify the current user has access to the specified practice.
 * This should be called at the start of any API that accesses practice data.
 * 
 * @param int|null $practiceId Practice ID to check (defaults to session practice)
 * @return bool True if user has access, false otherwise
 */
function verifyPracticeAccess($practiceId = null) {
    global $pdo;
    
    // Must be logged in
    if (!isset($_SESSION['db_user_id'])) {
        return false;
    }
    
    $userId = $_SESSION['db_user_id'];
    
    // Use session practice if not specified
    if ($practiceId === null) {
        $practiceId = $_SESSION['current_practice_id'] ?? null;
    }
    
    if (!$practiceId) {
        return false;
    }
    
    // Verify user is a member of this practice
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM practice_users 
            WHERE user_id = :user_id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $practiceId
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[practice-security] Error verifying access: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get the current user's role in the specified practice.
 * 
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return string|null Role ('admin', 'user') or null if no access
 */
function getUserPracticeRole($practiceId = null) {
    global $pdo;
    
    if (!isset($_SESSION['db_user_id'])) {
        return null;
    }
    
    $userId = $_SESSION['db_user_id'];
    
    if ($practiceId === null) {
        $practiceId = $_SESSION['current_practice_id'] ?? null;
    }
    
    if (!$practiceId) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT role FROM practice_users 
            WHERE user_id = :user_id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $practiceId
        ]);
        return $stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Check if current user is an admin of the specified practice.
 * 
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return bool True if user is admin
 */
function isPracticeAdmin($practiceId = null) {
    return getUserPracticeRole($practiceId) === 'admin';
}

/**
 * Check if current user is the owner of the specified practice.
 * 
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return bool True if user is owner
 */
function isPracticeOwner($practiceId = null) {
    global $pdo;
    
    if (!isset($_SESSION['db_user_id'])) {
        return false;
    }
    
    $userId = $_SESSION['db_user_id'];
    
    if ($practiceId === null) {
        $practiceId = $_SESSION['current_practice_id'] ?? null;
    }
    
    if (!$practiceId) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT is_owner FROM practice_users 
            WHERE user_id = :user_id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $practiceId
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Verify a case belongs to the current practice.
 * Prevents accessing cases from other practices.
 * 
 * @param string $caseId Case ID to verify
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return bool True if case belongs to practice
 */
function verifyCaseBelongsToPractice($caseId, $practiceId = null) {
    global $pdo;
    
    if (!$caseId) {
        return false;
    }
    
    if ($practiceId === null) {
        $practiceId = $_SESSION['current_practice_id'] ?? null;
    }
    
    if (!$practiceId) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM cases_cache 
            WHERE case_id = :case_id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'case_id' => $caseId,
            'practice_id' => $practiceId
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Add practice_id filter to a SQL query.
 * Helper to ensure queries are always scoped to the current practice.
 * 
 * @param string $tableAlias Table alias (e.g., 'c' for cases_cache)
 * @return string SQL WHERE clause fragment
 */
function getPracticeFilter($tableAlias = '') {
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    $prefix = $tableAlias ? "$tableAlias." : '';
    return "{$prefix}practice_id = " . intval($practiceId);
}

/**
 * Require practice access or die with error.
 * Use at the start of API endpoints that require practice access.
 * 
 * @param int|null $practiceId Practice ID to check
 */
function requirePracticeAccess($practiceId = null) {
    if (!verifyPracticeAccess($practiceId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not have permission to access this practice.'
        ]);
        exit;
    }
}

/**
 * Require admin role or die with error.
 * Use at the start of API endpoints that require admin access.
 * 
 * @param int|null $practiceId Practice ID to check
 */
function requirePracticeAdmin($practiceId = null) {
    if (!isPracticeAdmin($practiceId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Admin privileges required.'
        ]);
        exit;
    }
}

/**
 * Get the current user's full permissions for the specified practice.
 * Returns all permission flags from practice_users table.
 * 
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return array|null Permissions array or null if no access
 */
function getUserPracticePermissions($practiceId = null) {
    global $pdo;
    
    if (!isset($_SESSION['db_user_id'])) {
        return null;
    }
    
    $userId = $_SESSION['db_user_id'];
    
    if ($practiceId === null) {
        $practiceId = $_SESSION['current_practice_id'] ?? null;
    }
    
    if (!$practiceId) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT role, is_owner, limited_visibility, can_view_analytics, can_edit_cases
            FROM practice_users 
            WHERE user_id = :user_id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $practiceId
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        return [
            'role' => $row['role'],
            'is_owner' => (bool)$row['is_owner'],
            'is_admin' => $row['role'] === 'admin',
            'limited_visibility' => (bool)$row['limited_visibility'],
            'can_view_analytics' => (bool)$row['can_view_analytics'],
            'can_edit_cases' => (bool)$row['can_edit_cases']
        ];
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Check if user can view analytics for the current practice.
 * 
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return bool True if user can view analytics
 */
function canViewAnalytics($practiceId = null) {
    $permissions = getUserPracticePermissions($practiceId);
    return $permissions && $permissions['can_view_analytics'];
}

/**
 * Check if user can edit cases for the current practice.
 * 
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return bool True if user can edit cases
 */
function canEditCases($practiceId = null) {
    $permissions = getUserPracticePermissions($practiceId);
    return $permissions && $permissions['can_edit_cases'];
}

/**
 * Check if user has limited visibility (can only see assigned cases).
 * 
 * @param int|null $practiceId Practice ID (defaults to session practice)
 * @return bool True if user has limited visibility
 */
function hasLimitedVisibility($practiceId = null) {
    $permissions = getUserPracticePermissions($practiceId);
    return $permissions && $permissions['limited_visibility'];
}

/**
 * Log a security event for auditing.
 * 
 * @param string $eventType Type of security event
 * @param array $details Additional details
 */
function logSecurityEvent($eventType, $details = []) {
    $userId = $_SESSION['db_user_id'] ?? null;
    $practiceId = $_SESSION['current_practice_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $eventType,
        'user_id' => $userId,
        'practice_id' => $practiceId,
        'ip' => $ip,
        'details' => $details
    ];
    
    error_log('[SECURITY] ' . json_encode($logEntry));
}

/**
 * CRITICAL: Get the current practice ID with strict validation.
 * Returns null if no valid practice context exists.
 * This is the ONLY function that should be used to get practice_id for queries.
 * 
 * @return int|null The validated practice ID or null
 */
function getCurrentPracticeId() {
    // Must have a session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Must be logged in
    if (!isset($_SESSION['db_user_id'])) {
        return null;
    }
    
    // Must have a practice ID set
    $practiceId = $_SESSION['current_practice_id'] ?? null;
    if (!$practiceId) {
        return null;
    }
    
    return (int)$practiceId;
}

/**
 * CRITICAL: Require a valid practice context or fail with error.
 * Use this at the START of every API endpoint that accesses practice data.
 * 
 * @param bool $verifyMembership Also verify user is a member of the practice
 * @return int The validated practice ID
 */
function requireValidPracticeContext($verifyMembership = true) {
    global $pdo;
    
    // Must have a session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Must be logged in
    if (!isset($_SESSION['db_user_id'])) {
        // Don't log - unauthenticated requests are expected (login page, prefetch, etc.)
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]);
        exit;
    }
    
    $userId = $_SESSION['db_user_id'];
    $practiceId = $_SESSION['current_practice_id'] ?? null;
    
    // Must have a practice ID set
    if (!$practiceId) {
        logSecurityEvent('access_denied', [
            'reason' => 'no_practice_context',
            'user_id' => $userId
        ]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No active practice selected. Please select a practice first.',
            'redirect' => 'practice-setup.php'
        ]);
        exit;
    }
    
    // Optionally verify user is actually a member of this practice
    if ($verifyMembership && $pdo) {
        try {
            $stmt = $pdo->prepare("
                SELECT 1 FROM practice_users 
                WHERE user_id = :user_id AND practice_id = :practice_id
            ");
            $stmt->execute([
                'user_id' => $userId,
                'practice_id' => $practiceId
            ]);
            
            if (!$stmt->fetchColumn()) {
                // User is NOT a member of this practice - critical security violation
                logSecurityEvent('security_violation', [
                    'reason' => 'unauthorized_practice_access',
                    'user_id' => $userId,
                    'attempted_practice_id' => $practiceId
                ]);
                
                // Clear the invalid practice from session
                unset($_SESSION['current_practice_id']);
                
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied. You are not a member of this practice.',
                    'redirect' => 'practice-setup.php'
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log('[practice-security] Error verifying membership: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error verifying practice access'
            ]);
            exit;
        }
    }
    
    return (int)$practiceId;
}

/**
 * Verify that a record's practice_id matches the current practice.
 * Use this to validate data before returning it to the client.
 * 
 * @param int|null $recordPracticeId The practice_id from the database record
 * @return bool True if matches, false if mismatch (security violation)
 */
function verifyRecordBelongsToPractice($recordPracticeId) {
    $currentPracticeId = getCurrentPracticeId();
    
    if (!$currentPracticeId || !$recordPracticeId) {
        return false;
    }
    
    if ((int)$recordPracticeId !== (int)$currentPracticeId) {
        logSecurityEvent('data_leak_prevented', [
            'reason' => 'practice_id_mismatch',
            'record_practice_id' => $recordPracticeId,
            'session_practice_id' => $currentPracticeId
        ]);
        return false;
    }
    
    return true;
}
