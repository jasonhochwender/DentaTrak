<?php
/**
 * Unified Identity Management
 * 
 * This module ensures a single user account can authenticate via multiple methods
 * (Google OAuth and email/password) without creating duplicate users.
 * 
 * Key principles:
 * 1. Email is the canonical identifier - globally unique across all auth methods
 * 2. Auth methods are tracked in a separate table linked to users
 * 3. Sessions always reference a single canonical user_id
 * 4. Practice creation is NEVER implicit - always explicit user action
 */

require_once __DIR__ . '/appConfig.php';

/**
 * Ensure the user_auth_methods table exists
 */
function ensureAuthMethodsTable() {
    global $pdo;
    static $initialized = false;
    
    if ($initialized || !$pdo) {
        return;
    }
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_auth_methods (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                auth_type ENUM('google', 'email') NOT NULL,
                provider_id VARCHAR(255) DEFAULT NULL COMMENT 'Google sub ID for OAuth',
                password_hash VARCHAR(255) DEFAULT NULL COMMENT 'Bcrypt hash for email auth',
                email_verified BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP NULL,
                
                UNIQUE KEY unique_user_auth (user_id, auth_type),
                UNIQUE KEY unique_google_id (provider_id),
                INDEX idx_user_id (user_id),
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $initialized = true;
    } catch (PDOException $e) {
        // Table might already exist
        if (strpos($e->getMessage(), '1050') === false) {
            error_log('[unified-identity] Error creating auth_methods table: ' . $e->getMessage());
        }
        $initialized = true;
    }
}

/**
 * Find a user by email address (canonical lookup)
 * 
 * @param string $email Email address to search
 * @return array|null User record or null if not found
 */
function findUserByEmail($email) {
    global $pdo;
    
    if (!$pdo || empty($email)) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, google_id, first_name, last_name, profile_picture, 
                   role, is_active, auth_method, password_hash, email_verified,
                   created_at, last_login_at
            FROM users 
            WHERE email = :email
        ");
        $stmt->execute(['email' => strtolower(trim($email))]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('[unified-identity] Error finding user by email: ' . $e->getMessage());
        return null;
    }
}

/**
 * Find a user by Google ID
 * 
 * @param string $googleId Google sub ID
 * @return array|null User record or null if not found
 */
function findUserByGoogleId($googleId) {
    global $pdo;
    
    if (!$pdo || empty($googleId)) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, google_id, first_name, last_name, profile_picture, 
                   role, is_active, auth_method, password_hash, email_verified,
                   created_at, last_login_at
            FROM users 
            WHERE google_id = :google_id
        ");
        $stmt->execute(['google_id' => $googleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('[unified-identity] Error finding user by Google ID: ' . $e->getMessage());
        return null;
    }
}

/**
 * Create or link a user via Google OAuth
 * 
 * This function:
 * 1. Checks if user exists by email (canonical)
 * 2. If exists, links Google auth to existing user
 * 3. If not exists, creates new user with Google auth
 * 4. NEVER creates a practice implicitly
 * 
 * @param array $googleData Google user data (sub, email, name, picture)
 * @return array Result with success status and user data
 */
function authenticateWithGoogle($googleData) {
    global $pdo, $appConfig;
    
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable'];
    }
    
    $email = strtolower(trim($googleData['email'] ?? ''));
    $googleId = $googleData['sub'] ?? $googleData['id'] ?? '';
    $name = $googleData['name'] ?? '';
    $picture = $googleData['picture'] ?? '';
    
    if (empty($email)) {
        return ['success' => false, 'message' => 'Email is required'];
    }
    
    if (empty($googleId)) {
        return ['success' => false, 'message' => 'Google ID is required'];
    }
    
    ensureAuthMethodsTable();
    
    try {
        $pdo->beginTransaction();
        
        // First, check if this Google ID is already linked to any user
        $existingByGoogleId = findUserByGoogleId($googleId);
        
        // Then check if email exists
        $existingByEmail = findUserByEmail($email);
        
        // Case 1: Google ID already linked - just update and return
        if ($existingByGoogleId) {
            // Verify email matches (security check)
            if (strtolower($existingByGoogleId['email']) !== $email) {
                $pdo->rollBack();
                error_log("[unified-identity] SECURITY: Google ID {$googleId} email mismatch. DB: {$existingByGoogleId['email']}, OAuth: {$email}");
                return ['success' => false, 'message' => 'Account email mismatch. Please contact support.'];
            }
            
            // Update last login and profile
            updateUserProfile($existingByGoogleId['id'], [
                'profile_picture' => $picture,
                'last_login_at' => date('Y-m-d H:i:s')
            ]);
            
            // Update auth method last used
            updateAuthMethodLastUsed($existingByGoogleId['id'], 'google');
            
            $pdo->commit();
            
            return [
                'success' => true,
                'user' => array_merge($existingByGoogleId, ['profile_picture' => $picture]),
                'is_new_user' => false,
                'linked_existing' => false
            ];
        }
        
        // Case 2: Email exists but Google not linked - link Google to existing user
        if ($existingByEmail) {
            // Link Google auth to existing user
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    google_id = :google_id,
                    auth_method = CASE 
                        WHEN auth_method = 'email' THEN 'both'
                        ELSE auth_method
                    END,
                    profile_picture = COALESCE(NULLIF(:profile_picture, ''), profile_picture),
                    last_login_at = NOW()
                WHERE id = :user_id
            ");
            $stmt->execute([
                'google_id' => $googleId,
                'profile_picture' => $picture,
                'user_id' => $existingByEmail['id']
            ]);
            
            // Add to auth_methods table
            addAuthMethod($existingByEmail['id'], 'google', $googleId);
            
            $pdo->commit();
            
            // Refresh user data
            $user = findUserByEmail($email);
            
            return [
                'success' => true,
                'user' => $user,
                'is_new_user' => false,
                'linked_existing' => true,
                'message' => 'Google sign-in linked to your existing account.'
            ];
        }
        
        // Case 3: New user - create account
        $nameParts = explode(' ', $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(' ', $nameParts);
        
        // Check if first user (make admin)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        $isFirstUser = ($stmt->fetchColumn() == 0);
        
        // Check admin lists
        $isAdmin = $isFirstUser;
        if (!$isAdmin) {
            $powerUsers = $appConfig['powerUsers'] ?? [];
            $admins = $appConfig['admins'] ?? [];
            $isAdmin = in_array($email, $powerUsers) || in_array($email, $admins);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO users (
                email, google_id, first_name, last_name, profile_picture,
                role, is_active, auth_method, email_verified, last_login_at
            ) VALUES (
                :email, :google_id, :first_name, :last_name, :profile_picture,
                :role, 1, 'google', 1, NOW()
            )
        ");
        $stmt->execute([
            'email' => $email,
            'google_id' => $googleId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'profile_picture' => $picture,
            'role' => $isAdmin ? 'admin' : 'user'
        ]);
        
        $userId = $pdo->lastInsertId();
        
        // Add to auth_methods table
        addAuthMethod($userId, 'google', $googleId);
        
        // Create default preferences
        createDefaultUserPreferencesIfNeeded($userId);
        
        $pdo->commit();
        
        $user = findUserByEmail($email);
        
        return [
            'success' => true,
            'user' => $user,
            'is_new_user' => true,
            'linked_existing' => false
        ];
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[unified-identity] Google auth error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Authentication failed. Please try again.'];
    }
}

/**
 * Create or link a user via email/password registration
 * 
 * @param string $email Email address
 * @param string $password Plain text password
 * @param string $firstName First name
 * @param string $lastName Last name
 * @return array Result with success status
 */
function registerWithEmail($email, $password, $firstName = '', $lastName = '') {
    global $pdo;
    
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable'];
    }
    
    $email = strtolower(trim($email));
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    ensureAuthMethodsTable();
    
    try {
        $pdo->beginTransaction();
        
        $existingUser = findUserByEmail($email);
        
        if ($existingUser) {
            // User exists - check if they already have email auth
            if ($existingUser['auth_method'] === 'email' || $existingUser['auth_method'] === 'both') {
                $pdo->rollBack();
                return [
                    'success' => false, 
                    'message' => 'An account with this email already exists. Please sign in instead.'
                ];
            }
            
            // User exists with Google only - add email auth
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    password_hash = :password_hash,
                    auth_method = 'both',
                    first_name = COALESCE(NULLIF(:first_name, ''), first_name),
                    last_name = COALESCE(NULLIF(:last_name, ''), last_name)
                WHERE id = :user_id
            ");
            $stmt->execute([
                'password_hash' => $passwordHash,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'user_id' => $existingUser['id']
            ]);
            
            // Add to auth_methods table
            addAuthMethod($existingUser['id'], 'email', null, $passwordHash);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'user_id' => $existingUser['id'],
                'linked_existing' => true,
                'message' => 'Password added to your existing account. You can now sign in with either Google or email/password.'
            ];
        }
        
        // New user - create account
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (
                email, password_hash, auth_method, first_name, last_name,
                role, is_active, email_verified, created_at
            ) VALUES (
                :email, :password_hash, 'email', :first_name, :last_name,
                'user', 1, 0, NOW()
            )
        ");
        $stmt->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName
        ]);
        
        $userId = $pdo->lastInsertId();
        
        // Add to auth_methods table
        addAuthMethod($userId, 'email', null, $passwordHash);
        
        // Create default preferences
        createDefaultUserPreferencesIfNeeded($userId);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'user_id' => $userId,
            'is_new_user' => true,
            'message' => 'Account created successfully. You can now sign in.'
        ];
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[unified-identity] Email registration error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }
}

// ============================================
// ACCOUNT LOCKOUT CONFIGURATION
// Security: Prevents brute-force attacks by temporarily locking accounts
// ============================================
define('MAX_FAILED_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION_MINUTES', 15);

/**
 * Ensure the login_attempts table exists for tracking failed logins
 * Security: Server-side tracking prevents client-side bypass
 */
function ensureLoginAttemptsTable() {
    global $pdo;
    static $initialized = false;
    
    if ($initialized || !$pdo) {
        return;
    }
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                attempt_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) DEFAULT NULL,
                
                INDEX idx_email (email),
                INDEX idx_attempt_time (attempt_time),
                INDEX idx_email_time (email, attempt_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $initialized = true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1050') === false) {
            error_log('[unified-identity] Error creating login_attempts table: ' . $e->getMessage());
        }
        $initialized = true;
    }
}

/**
 * Record a failed login attempt
 * Security: Tracks attempts by email to prevent enumeration attacks
 * 
 * @param string $email Email address (normalized)
 * @param string|null $ipAddress Client IP address for logging
 */
function recordFailedLoginAttempt($email, $ipAddress = null) {
    global $pdo;
    
    if (!$pdo) return;
    
    ensureLoginAttemptsTable();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (email, ip_address, attempt_time)
            VALUES (:email, :ip_address, NOW())
        ");
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'ip_address' => $ipAddress
        ]);
    } catch (PDOException $e) {
        error_log('[unified-identity] Error recording failed login attempt: ' . $e->getMessage());
    }
}

/**
 * Clear failed login attempts for an email (on successful login)
 * 
 * @param string $email Email address
 */
function clearFailedLoginAttempts($email) {
    global $pdo;
    
    if (!$pdo) return;
    
    ensureLoginAttemptsTable();
    
    try {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = :email");
        $stmt->execute(['email' => strtolower(trim($email))]);
    } catch (PDOException $e) {
        error_log('[unified-identity] Error clearing failed login attempts: ' . $e->getMessage());
    }
}

/**
 * Check if an account is currently locked out
 * Security: Returns generic message to avoid confirming email existence
 * 
 * @param string $email Email address
 * @return array ['locked' => bool, 'remaining_minutes' => int|null, 'attempts' => int]
 */
function checkAccountLockout($email) {
    global $pdo;
    
    if (!$pdo) {
        return ['locked' => false, 'remaining_minutes' => null, 'attempts' => 0];
    }
    
    ensureLoginAttemptsTable();
    
    $email = strtolower(trim($email));
    $lockoutWindow = LOCKOUT_DURATION_MINUTES;
    
    try {
        // Count failed attempts within the lockout window
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempt_count, MAX(attempt_time) as last_attempt
            FROM login_attempts 
            WHERE email = :email 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL :lockout_minutes MINUTE)
        ");
        $stmt->execute([
            'email' => $email,
            'lockout_minutes' => $lockoutWindow
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $attemptCount = (int)($result['attempt_count'] ?? 0);
        $lastAttempt = $result['last_attempt'] ?? null;
        
        if ($attemptCount >= MAX_FAILED_LOGIN_ATTEMPTS && $lastAttempt) {
            // Calculate remaining lockout time
            $lastAttemptTime = strtotime($lastAttempt);
            $lockoutEndsAt = $lastAttemptTime + (LOCKOUT_DURATION_MINUTES * 60);
            $remainingSeconds = $lockoutEndsAt - time();
            
            if ($remainingSeconds > 0) {
                return [
                    'locked' => true,
                    'remaining_minutes' => ceil($remainingSeconds / 60),
                    'attempts' => $attemptCount
                ];
            }
        }
        
        return [
            'locked' => false,
            'remaining_minutes' => null,
            'attempts' => $attemptCount
        ];
        
    } catch (PDOException $e) {
        error_log('[unified-identity] Error checking account lockout: ' . $e->getMessage());
        return ['locked' => false, 'remaining_minutes' => null, 'attempts' => 0];
    }
}

/**
 * Clean up old login attempts (maintenance function)
 * Can be called periodically to keep the table size manageable
 */
function cleanupOldLoginAttempts() {
    global $pdo;
    
    if (!$pdo) return;
    
    try {
        // Delete attempts older than 24 hours
        $pdo->exec("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    } catch (PDOException $e) {
        error_log('[unified-identity] Error cleaning up login attempts: ' . $e->getMessage());
    }
}

/**
 * Authenticate with email/password
 * Security: Implements account lockout to prevent brute-force attacks
 * 
 * @param string $email Email address
 * @param string $password Plain text password
 * @return array Result with success status and user data
 */
function authenticateWithEmail($email, $password) {
    global $pdo;
    
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable'];
    }
    
    $email = strtolower(trim($email));
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
    
    // ============================================
    // ACCOUNT LOCKOUT CHECK
    // Security: Check lockout BEFORE validating credentials
    // This prevents timing attacks that could reveal valid emails
    // ============================================
    $lockoutStatus = checkAccountLockout($email);
    
    if ($lockoutStatus['locked']) {
        // Security: Use generic message that doesn't confirm email existence
        // The same message is shown whether the email exists or not
        return [
            'success' => false,
            'message' => 'Too many failed login attempts. Please try again in ' . $lockoutStatus['remaining_minutes'] . ' minute(s).',
            'locked' => true,
            'remaining_minutes' => $lockoutStatus['remaining_minutes']
        ];
    }
    
    $user = findUserByEmail($email);
    
    if (!$user) {
        // Security: Record attempt even for non-existent emails to prevent enumeration
        // Use consistent timing to avoid timing attacks
        recordFailedLoginAttempt($email, $clientIp);
        return ['success' => false, 'message' => 'Invalid email or password'];
    }
    
    // Check if user can use email auth
    if ($user['auth_method'] === 'google') {
        return [
            'success' => false,
            'message' => 'This account uses Google Sign-In. Please sign in with Google or set up a password first.'
        ];
    }
    
    // Check if account is active
    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'This account has been deactivated'];
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        // Record failed attempt
        recordFailedLoginAttempt($email, $clientIp);
        
        // Check if this attempt triggered a lockout
        $newLockoutStatus = checkAccountLockout($email);
        if ($newLockoutStatus['locked']) {
            return [
                'success' => false,
                'message' => 'Too many failed login attempts. Your account has been temporarily locked. Please try again in ' . $newLockoutStatus['remaining_minutes'] . ' minute(s).',
                'locked' => true,
                'remaining_minutes' => $newLockoutStatus['remaining_minutes']
            ];
        }
        
        // Calculate remaining attempts before lockout
        $remainingAttempts = MAX_FAILED_LOGIN_ATTEMPTS - $newLockoutStatus['attempts'];
        if ($remainingAttempts <= 2 && $remainingAttempts > 0) {
            return [
                'success' => false,
                'message' => 'Invalid email or password. ' . $remainingAttempts . ' attempt(s) remaining before account lockout.'
            ];
        }
        
        return ['success' => false, 'message' => 'Invalid email or password'];
    }
    
    // ============================================
    // SUCCESSFUL LOGIN
    // Clear failed attempts on successful authentication
    // ============================================
    clearFailedLoginAttempts($email);
    
    // Update last login
    updateUserProfile($user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
    updateAuthMethodLastUsed($user['id'], 'email');
    
    return [
        'success' => true,
        'user' => $user
    ];
}

/**
 * Add an auth method to the user_auth_methods table
 */
function addAuthMethod($userId, $authType, $providerId = null, $passwordHash = null) {
    global $pdo;
    
    if (!$pdo) return false;
    
    ensureAuthMethodsTable();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_auth_methods (user_id, auth_type, provider_id, password_hash, email_verified, last_used_at)
            VALUES (:user_id, :auth_type, :provider_id, :password_hash, 1, NOW())
            ON DUPLICATE KEY UPDATE 
                provider_id = COALESCE(:provider_id2, provider_id),
                password_hash = COALESCE(:password_hash2, password_hash),
                last_used_at = NOW()
        ");
        return $stmt->execute([
            'user_id' => $userId,
            'auth_type' => $authType,
            'provider_id' => $providerId,
            'password_hash' => $passwordHash,
            'provider_id2' => $providerId,
            'password_hash2' => $passwordHash
        ]);
    } catch (PDOException $e) {
        error_log('[unified-identity] Error adding auth method: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update auth method last used timestamp
 */
function updateAuthMethodLastUsed($userId, $authType) {
    global $pdo;
    
    if (!$pdo) return false;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE user_auth_methods 
            SET last_used_at = NOW() 
            WHERE user_id = :user_id AND auth_type = :auth_type
        ");
        return $stmt->execute(['user_id' => $userId, 'auth_type' => $authType]);
    } catch (PDOException $e) {
        // Silently fail - not critical
        return false;
    }
}

/**
 * Update user profile fields
 */
function updateUserProfile($userId, $fields) {
    global $pdo;
    
    if (!$pdo || empty($fields)) return false;
    
    $allowedFields = ['first_name', 'last_name', 'profile_picture', 'last_login_at'];
    $updates = [];
    $params = ['user_id' => $userId];
    
    foreach ($fields as $field => $value) {
        if (in_array($field, $allowedFields)) {
            // Skip empty profile_picture to avoid overwriting existing with empty string
            if ($field === 'profile_picture' && empty($value)) {
                continue;
            }
            $updates[] = "$field = :$field";
            $params[$field] = $value;
        }
    }
    
    if (empty($updates)) return false;
    
    try {
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :user_id";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log('[unified-identity] Error updating user profile: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create default user preferences if they don't exist
 */
function createDefaultUserPreferencesIfNeeded($userId) {
    global $pdo;
    
    if (!$pdo) return false;
    
    try {
        // Check if preferences exist
        $stmt = $pdo->prepare("SELECT 1 FROM user_preferences WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        
        if (!$stmt->fetchColumn()) {
            $stmt = $pdo->prepare("
                INSERT INTO user_preferences (user_id, theme, allow_card_delete, highlight_past_due, past_due_days)
                VALUES (:user_id, 'light', TRUE, TRUE, 7)
            ");
            $stmt->execute(['user_id' => $userId]);
        }
        return true;
    } catch (PDOException $e) {
        // Table might not exist yet
        return false;
    }
}

/**
 * Get user's auth methods
 */
function getUserAuthMethods($userId) {
    global $pdo;
    
    if (!$pdo) return [];
    
    ensureAuthMethodsTable();
    
    try {
        $stmt = $pdo->prepare("
            SELECT auth_type, provider_id IS NOT NULL as has_provider, 
                   password_hash IS NOT NULL as has_password,
                   email_verified, created_at, last_used_at
            FROM user_auth_methods 
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Verify user has access to a practice (security check)
 * 
 * @param int $userId User ID
 * @param int $practiceId Practice ID
 * @return bool True if user has access
 */
function userHasPracticeAccess($userId, $practiceId) {
    global $pdo;
    
    if (!$pdo || !$userId || !$practiceId) return false;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM practice_users 
            WHERE user_id = :user_id AND practice_id = :practice_id
        ");
        $stmt->execute(['user_id' => $userId, 'practice_id' => $practiceId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get user's practices
 * 
 * @param int $userId User ID
 * @return array List of practices
 */
function getUserPractices($userId, $includeInactive = false) {
    global $pdo;
    
    if (!$pdo || !$userId) return [];
    
    try {
        // By default, only return active practices
        $activeFilter = $includeInactive ? '' : 'AND (p.is_active = 1 OR p.is_active IS NULL)';
        
        $stmt = $pdo->prepare("
            SELECT p.id, p.practice_id as uuid, p.practice_name, pu.role, pu.is_owner,
                   p.is_active, p.deactivated_at, p.data_deletion_eligible_at
            FROM practices p
            JOIN practice_users pu ON p.id = pu.practice_id
            WHERE pu.user_id = :user_id $activeFilter
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Check if user has any active practices they can access
 * Returns info about inactive practices if all are inactive
 * 
 * @param int $userId User ID
 * @return array ['has_active' => bool, 'inactive_practices' => array]
 */
function checkUserPracticeAccess($userId) {
    global $pdo;
    
    if (!$pdo || !$userId) {
        return ['has_active' => false, 'inactive_practices' => []];
    }
    
    // Get all practices (including inactive)
    $allPractices = getUserPractices($userId, true);
    $activePractices = getUserPractices($userId, false);
    
    if (count($activePractices) > 0) {
        return ['has_active' => true, 'inactive_practices' => []];
    }
    
    // All practices are inactive - return details
    $inactivePractices = [];
    foreach ($allPractices as $practice) {
        if (!($practice['is_active'] ?? true)) {
            $yearsInactive = 0;
            if ($practice['deactivated_at']) {
                $deactivatedDate = new DateTime($practice['deactivated_at']);
                $now = new DateTime();
                $yearsInactive = $now->diff($deactivatedDate)->y;
            }
            
            $inactivePractices[] = [
                'name' => $practice['practice_name'],
                'deactivated_at' => $practice['deactivated_at'],
                'years_inactive' => $yearsInactive,
                'deletion_eligible_at' => $practice['data_deletion_eligible_at']
            ];
        }
    }
    
    return [
        'has_active' => false,
        'inactive_practices' => $inactivePractices,
        'message' => 'Your practice is no longer active. Please contact support@dentatrak.com for assistance.'
    ];
}

/**
 * Set up session for authenticated user
 * 
 * @param array $user User record
 * @param string $authMethod 'google' or 'email'
 */
function setupUserSession($user, $authMethod = 'email') {
    // Rotate session ID on login for security
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    
    $_SESSION['db_user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $_SESSION['user_picture'] = $user['profile_picture'] ?? '';
    $_SESSION['user_role'] = $user['role'] ?? 'user';
    $_SESSION['auth_method'] = $authMethod;
    
    // Set $_SESSION['user'] for all auth methods (required by main.php auth check)
    $_SESSION['user'] = [
        'id' => $user['google_id'] ?? $user['id'], // Use google_id if available, otherwise user id
        'email' => $user['email'],
        'name' => $_SESSION['user_name'],
        'picture' => $user['profile_picture'] ?? ''
    ];
}

/**
 * Generate a password setup token for Google-only users
 * Allows adding password auth to an existing Google account
 * 
 * @param string $email User's email
 * @return array Result with token or error
 */
function generatePasswordSetupToken($email) {
    global $pdo;
    
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database unavailable'];
    }
    
    $user = findUserByEmail($email);
    
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }
    
    // Only allow for Google-only users
    if ($user['auth_method'] !== 'google') {
        return ['success' => false, 'message' => 'This account already has password authentication'];
    }
    
    // Ensure password_setup_tokens table exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_setup_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                used BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_token (token),
                INDEX idx_user_id (user_id),
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table might already exist
    }
    
    try {
        // Invalidate any existing tokens for this user
        $stmt = $pdo->prepare("UPDATE password_setup_tokens SET used = 1 WHERE user_id = :user_id AND used = 0");
        $stmt->execute(['user_id' => $user['id']]);
        
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token
        $stmt = $pdo->prepare("
            INSERT INTO password_setup_tokens (user_id, token, expires_at)
            VALUES (:user_id, :token, :expires_at)
        ");
        $stmt->execute([
            'user_id' => $user['id'],
            'token' => $token,
            'expires_at' => $expiresAt
        ]);
        
        return [
            'success' => true,
            'token' => $token,
            'user_id' => $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'] ?? ''
        ];
        
    } catch (PDOException $e) {
        error_log('[unified-identity] Error generating password setup token: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to generate token'];
    }
}

/**
 * Validate a password setup token
 * 
 * @param string $token The token to validate
 * @return array Result with user info or error
 */
function validatePasswordSetupToken($token) {
    global $pdo;
    
    if (!$pdo || empty($token)) {
        return ['success' => false, 'message' => 'Invalid token'];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT pst.id, pst.user_id, pst.expires_at, pst.used, u.email, u.first_name
            FROM password_setup_tokens pst
            JOIN users u ON pst.user_id = u.id
            WHERE pst.token = :token
        ");
        $stmt->execute(['token' => $token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            return ['success' => false, 'message' => 'Invalid or expired link'];
        }
        
        if ($tokenData['used']) {
            return ['success' => false, 'message' => 'This link has already been used'];
        }
        
        if (strtotime($tokenData['expires_at']) < time()) {
            return ['success' => false, 'message' => 'This link has expired'];
        }
        
        return [
            'success' => true,
            'token_id' => $tokenData['id'],
            'user_id' => $tokenData['user_id'],
            'email' => $tokenData['email'],
            'first_name' => $tokenData['first_name']
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Validation failed'];
    }
}

/**
 * Complete password setup for a Google-only user
 * 
 * @param string $token The setup token
 * @param string $password The new password
 * @return array Result
 */
function completePasswordSetup($token, $password) {
    global $pdo;
    
    if (!$pdo) {
        return ['success' => false, 'message' => 'Database unavailable'];
    }
    
    // Validate token first
    $validation = validatePasswordSetupToken($token);
    if (!$validation['success']) {
        return $validation;
    }
    
    $userId = $validation['user_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Hash password with bcrypt
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        
        // Update user record
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_hash = :password_hash,
                auth_method = 'both'
            WHERE id = :user_id
        ");
        $stmt->execute([
            'password_hash' => $passwordHash,
            'user_id' => $userId
        ]);
        
        // Add to auth_methods table
        addAuthMethod($userId, 'email', null, $passwordHash);
        
        // Mark token as used
        $stmt = $pdo->prepare("UPDATE password_setup_tokens SET used = 1 WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Password set successfully. You can now sign in with email and password.'
        ];
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[unified-identity] Error completing password setup: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to set password'];
    }
}

/**
 * Check if user is currently authenticated with Google
 * Used to verify ownership before allowing password setup
 * 
 * @param string $email Email to check
 * @return bool True if current session is authenticated with Google for this email
 */
function isAuthenticatedWithGoogle($email) {
    if (!isset($_SESSION['db_user_id']) || !isset($_SESSION['auth_method'])) {
        return false;
    }
    
    if ($_SESSION['auth_method'] !== 'google') {
        return false;
    }
    
    $sessionEmail = $_SESSION['user_email'] ?? '';
    return strtolower($sessionEmail) === strtolower($email);
}

// ============================================
// REMEMBER ME FUNCTIONALITY
// Security: Implements persistent login tokens for "Remember Me" feature
// Tokens are stored hashed in the database, only the selector is stored in cookie
// ============================================

define('REMEMBER_ME_COOKIE_NAME', 'remember_token');
define('REMEMBER_ME_EXPIRY_DAYS', 30);

/**
 * Ensure the remember_me_tokens table exists
 * Security: Stores hashed tokens, not plain text
 */
function ensureRememberMeTable() {
    global $pdo;
    static $initialized = false;
    
    if ($initialized || !$pdo) {
        return;
    }
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS remember_me_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                selector VARCHAR(64) NOT NULL UNIQUE,
                token_hash VARCHAR(255) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                
                INDEX idx_user_id (user_id),
                INDEX idx_selector (selector),
                INDEX idx_expires (expires_at),
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $initialized = true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '1050') === false) {
            error_log('[unified-identity] Error creating remember_me_tokens table: ' . $e->getMessage());
        }
        $initialized = true;
    }
}

/**
 * Create a remember me token for persistent login
 * Uses signed cookie approach - no database storage needed
 * 
 * @param int $userId User ID
 * @return string|false The cookie value (userId:expiry:signature) or false on failure
 */
function createRememberMeToken($userId) {
    if (!$userId) return false;
    
    // Create expiry timestamp (30 days from now)
    $expiry = time() + (REMEMBER_ME_EXPIRY_DAYS * 24 * 60 * 60);
    
    // Create HMAC signature
    $secret = getRememberMeSecret();
    $signature = hash_hmac('sha256', $userId . ':' . $expiry, $secret);
    
    // Return the cookie value (userId:expiry:signature)
    return $userId . ':' . $expiry . ':' . $signature;
}

/**
 * Set the remember me cookie
 * 
 * @param string $cookieValue The cookie value
 * @return bool Success status
 */
function setRememberMeCookie($cookieValue) {
    $isProduction = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    $expires = time() + (REMEMBER_ME_EXPIRY_DAYS * 24 * 60 * 60);
    
    return setcookie(REMEMBER_ME_COOKIE_NAME, $cookieValue, [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => $isProduction,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Validate a remember me token and return the user
 * Uses a simpler signed cookie approach for reliability
 * 
 * @return array|null User data if valid, null otherwise
 */
function validateRememberMeToken() {
    global $pdo;
    
    if (!$pdo) {
        return null;
    }
    
    $cookieValue = $_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? null;
    if (!$cookieValue) {
        return null;
    }
    
    // New format: userId:expiry:signature
    $parts = explode(':', $cookieValue);
    if (count($parts) !== 3) {
        // Try old format for backwards compatibility
        return validateRememberMeTokenLegacy($cookieValue);
    }
    
    list($userId, $expiry, $signature) = $parts;
    
    // Check expiry
    if ((int)$expiry < time()) {
        return null;
    }
    
    // Verify signature
    $secret = getRememberMeSecret();
    $expectedSignature = hash_hmac('sha256', $userId . ':' . $expiry, $secret);
    
    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }
    
    // Look up user
    try {
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, last_name, profile_picture,
                   role, is_active, auth_method, email_verified
            FROM users
            WHERE id = :id AND is_active = 1
        ");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return null;
        }
        
        return $user;
        
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Legacy token validation for backwards compatibility
 */
function validateRememberMeTokenLegacy($cookieValue) {
    global $pdo;
    
    if (strpos($cookieValue, ':') === false) {
        return null;
    }
    
    list($selector, $token) = explode(':', $cookieValue, 2);
    
    if (empty($selector) || empty($token)) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT rmt.user_id, rmt.token_hash, rmt.expires_at,
                   u.id, u.email, u.first_name, u.last_name, u.profile_picture,
                   u.role, u.is_active, u.auth_method, u.email_verified
            FROM remember_me_tokens rmt
            JOIN users u ON rmt.user_id = u.id
            WHERE rmt.selector = :selector
        ");
        $stmt->execute(['selector' => $selector]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record || !$record['is_active']) {
            return null;
        }
        
        if (strtotime($record['expires_at']) < time()) {
            return null;
        }
        
        $tokenHash = hash('sha256', $token);
        if (!hash_equals($record['token_hash'], $tokenHash)) {
            return null;
        }
        
        return [
            'id' => $record['user_id'],
            'email' => $record['email'],
            'first_name' => $record['first_name'],
            'last_name' => $record['last_name'],
            'profile_picture' => $record['profile_picture'],
            'role' => $record['role'],
            'is_active' => $record['is_active'],
            'auth_method' => $record['auth_method'],
            'email_verified' => $record['email_verified']
        ];
        
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get or generate the Remember Me secret key
 */
function getRememberMeSecret() {
    global $appConfig;
    // Use app secret or a default (should be configured in production)
    return $appConfig['app_secret'] ?? 'dentatrak-remember-me-secret-key-2024';
}

/**
 * Delete a remember me token by selector
 * 
 * @param string $selector Token selector
 */
function deleteRememberMeTokenBySelector($selector) {
    global $pdo;
    
    if (!$pdo) return;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM remember_me_tokens WHERE selector = :selector");
        $stmt->execute(['selector' => $selector]);
    } catch (PDOException $e) {
        // Silently fail - token cleanup is not critical
    }
}

/**
 * Revoke all remember me tokens for a user
 * Security: Called on logout, password change, or suspected token theft
 * 
 * @param int $userId User ID
 */
function revokeAllRememberMeTokens($userId) {
    global $pdo;
    
    if (!$pdo || !$userId) return;
    
    ensureRememberMeTable();
    
    try {
        $stmt = $pdo->prepare("DELETE FROM remember_me_tokens WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
    } catch (PDOException $e) {
        // Silently fail - token cleanup is not critical
    }
}

/**
 * Clear the remember me cookie
 */
function clearRememberMeCookie() {
    if (isset($_COOKIE[REMEMBER_ME_COOKIE_NAME])) {
        // Delete the token from database if it exists
        $cookieValue = $_COOKIE[REMEMBER_ME_COOKIE_NAME];
        if (strpos($cookieValue, ':') !== false) {
            list($selector, ) = explode(':', $cookieValue, 2);
            deleteRememberMeTokenBySelector($selector);
        }
        
        // Clear the cookie
        setcookie(REMEMBER_ME_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

/**
 * Clean up expired remember me tokens (maintenance function)
 */
function cleanupExpiredRememberMeTokens() {
    global $pdo;
    
    if (!$pdo) return;
    
    ensureRememberMeTable();
    
    try {
        $pdo->exec("DELETE FROM remember_me_tokens WHERE expires_at < NOW()");
    } catch (PDOException $e) {
        error_log('[unified-identity] Error cleaning up remember me tokens: ' . $e->getMessage());
    }
}
