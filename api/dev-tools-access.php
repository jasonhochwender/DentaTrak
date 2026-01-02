<?php
/**
 * Dev Tools Access Control
 * 
 * Determines if the current user can access dev tools based on:
 * 1. SHOW_DEV_TOOLS feature flag (master switch)
 * 2. super_users list in appConfig (specific users allowed)
 * 
 * IMPORTANT: Dev tools operations in UAT/Production are ALWAYS scoped
 * to the practice the super user is an admin of.
 */

require_once __DIR__ . '/feature-flags.php';

/**
 * Check if the current user can access dev tools
 * 
 * @param array $appConfig The application configuration
 * @param string|null $userEmail The current user's email (from session)
 * @return bool True if user can access dev tools
 */
function canAccessDevTools($appConfig, $userEmail = null) {
    // Check if dev tools feature flag is enabled
    if (!isFeatureEnabled('SHOW_DEV_TOOLS')) {
        return false;
    }
    
    // Check if user is in super users list
    if (empty($userEmail)) {
        return false;
    }
    
    return isSuperUser($appConfig, $userEmail);
}

/**
 * Check if a user email is in the super users list
 * 
 * @param array $appConfig The application configuration
 * @param string $userEmail The user's email to check
 * @return bool True if user is a super user
 */
function isSuperUser($appConfig, $userEmail) {
    $superUsers = $appConfig['super_users'] ?? [];
    
    if (empty($superUsers) || empty($userEmail)) {
        return false;
    }
    
    $normalizedEmail = strtolower(trim($userEmail));
    
    foreach ($superUsers as $superUser) {
        if (strtolower(trim($superUser)) === $normalizedEmail) {
            return true;
        }
    }
    
    return false;
}

/**
 * Check if the current environment is production or UAT (non-development)
 * Used to determine if extra warnings should be shown
 * 
 * @param array $appConfig The application configuration
 * @return bool True if in production or UAT
 */
function isProductionOrUAT($appConfig) {
    $environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
    return $environment !== 'development';
}

/**
 * Get the environment display name for warnings
 * 
 * @param array $appConfig The application configuration
 * @return string Environment display name
 */
function getEnvironmentDisplayName($appConfig) {
    $environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
    
    switch ($environment) {
        case 'production':
            return 'PRODUCTION';
        case 'uat':
            return 'UAT';
        case 'development':
            return 'Development';
        default:
            return ucfirst($environment);
    }
}

/**
 * Validate that a super user has admin access to the specified practice
 * This ensures dev tools operations only affect practices they administer
 * 
 * @param PDO $pdo Database connection
 * @param int $userId The user's database ID
 * @param int $practiceId The practice ID to check
 * @return bool True if user is an admin of the practice
 */
function superUserHasPracticeAdminAccess($pdo, $userId, $practiceId) {
    if (!$pdo || !$userId || !$practiceId) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM practice_users 
            WHERE user_id = :user_id 
            AND practice_id = :practice_id 
            AND (role = 'admin' OR is_owner = 1)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $practiceId
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[dev-tools-access] Error checking practice admin access: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get the practice ID that the super user can manage via dev tools
 * Returns the current practice from session if they are an admin of it
 * 
 * @param PDO $pdo Database connection
 * @param int $userId The user's database ID
 * @return int|null The practice ID or null if no valid practice
 */
function getSuperUserManagedPracticeId($pdo, $userId) {
    $currentPracticeId = $_SESSION['current_practice_id'] ?? null;
    
    if (!$currentPracticeId) {
        return null;
    }
    
    // Verify they are an admin of this practice
    if (superUserHasPracticeAdminAccess($pdo, $userId, $currentPracticeId)) {
        return (int)$currentPracticeId;
    }
    
    return null;
}
