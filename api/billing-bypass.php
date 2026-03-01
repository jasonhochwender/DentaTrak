<?php
/**
 * Billing Bypass Configuration
 * 
 * Users with emails matching these patterns automatically get full access
 * (Control tier) and never see billing screens.
 * 
 * This is for partner practices, internal users, or special arrangements.
 */

// Email patterns that bypass billing (case-insensitive substring match)
// Add patterns here - if any pattern is found in the email, user gets full access
define('BILLING_BYPASS_PATTERNS', [
    'premierimplantsanddentures',
    'verrillo',
    'hochwender'
]);

/**
 * Check if an email qualifies for billing bypass
 * 
 * @param string $email The user's email address
 * @return bool True if the email matches a bypass pattern
 */
function isBillingBypassEmail($email) {
    if (empty($email)) {
        return false;
    }
    
    $emailLower = strtolower(trim($email));
    
    foreach (BILLING_BYPASS_PATTERNS as $pattern) {
        if (strpos($emailLower, strtolower($pattern)) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get the billing tier for a user, considering bypass rules
 * 
 * @param string $email The user's email address
 * @param string|null $currentTier The user's current billing tier from DB
 * @return string The effective billing tier ('control' for bypass users)
 */
function getEffectiveBillingTier($email, $currentTier = null) {
    if (isBillingBypassEmail($email)) {
        return 'control';
    }
    return $currentTier ?? 'evaluate';
}

/**
 * Check if a user should see billing UI
 * 
 * @param string $email The user's email address
 * @return bool True if user should see billing UI, false to hide it
 */
function shouldShowBillingUI($email) {
    return !isBillingBypassEmail($email);
}

/**
 * Ensure a bypass user has the correct billing tier in the database
 * Call this on login/signup to auto-upgrade bypass users
 * 
 * @param PDO $pdo Database connection
 * @param int $userId The user's database ID
 * @param string $email The user's email address
 * @return bool True if tier was updated, false if no change needed
 */
function ensureBypassUserTier($pdo, $userId, $email) {
    if (!isBillingBypassEmail($email)) {
        return false;
    }
    
    try {
        // Check current tier
        $stmt = $pdo->prepare("SELECT billing_tier FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $currentTier = $stmt->fetchColumn();
        
        // If not already control, upgrade them
        if ($currentTier !== 'control') {
            $stmt = $pdo->prepare("UPDATE users SET billing_tier = 'control' WHERE id = ?");
            $stmt->execute([$userId]);
            error_log("[billing-bypass] Auto-upgraded user $userId ($email) to control tier");
            return true;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("[billing-bypass] Error ensuring tier for user $userId: " . $e->getMessage());
        return false;
    }
}

/**
 * Upgrade all existing users that match bypass patterns
 * Run this once to fix existing users, or periodically
 * 
 * @param PDO $pdo Database connection
 * @return array ['upgraded' => count, 'emails' => [...]]
 */
function upgradeExistingBypassUsers($pdo) {
    $upgraded = [];
    
    try {
        // Build WHERE clause for all patterns
        $conditions = [];
        $params = [];
        foreach (BILLING_BYPASS_PATTERNS as $i => $pattern) {
            $conditions[] = "LOWER(email) LIKE :pattern$i";
            $params["pattern$i"] = '%' . strtolower($pattern) . '%';
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        // Find and upgrade users
        $sql = "UPDATE users SET billing_tier = 'control' WHERE ($whereClause) AND (billing_tier != 'control' OR billing_tier IS NULL)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        
        // Get the emails that were upgraded for logging
        if ($count > 0) {
            $sql = "SELECT email FROM users WHERE $whereClause";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $upgraded = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        if ($count > 0) {
            error_log("[billing-bypass] Upgraded $count existing users to control tier: " . implode(', ', $upgraded));
        }
        
        return ['upgraded' => $count, 'emails' => $upgraded];
    } catch (PDOException $e) {
        error_log("[billing-bypass] Error upgrading existing users: " . $e->getMessage());
        return ['upgraded' => 0, 'emails' => [], 'error' => $e->getMessage()];
    }
}
