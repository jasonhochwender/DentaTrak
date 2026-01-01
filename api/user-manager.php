<?php
/**
 * User Management Functions
 * 
 * Handles database operations related to users, including:
 * - Creating or updating user records
 * - Managing user preferences
 * - Tracking user sessions
 */

require_once __DIR__ . '/appConfig.php';

function ensureUserPreferencesSchema() {
    global $pdo;
    static $checked = false;
    if ($checked || !$pdo) {
        return;
    }
    $checked = true;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'delivered_hide_days'");
        $hasDelivered = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hasDelivered) {
            $pdo->exec("ALTER TABLE user_preferences ADD delivered_hide_days INT UNSIGNED NOT NULL DEFAULT 0 AFTER past_due_days");
        }
    } catch (PDOException $e) {
        userLog('Error ensuring delivered_hide_days column on user_preferences: ' . $e->getMessage(), true);
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'preferred_practice_id'");
        $hasPreferred = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hasPreferred) {
            $pdo->exec("ALTER TABLE user_preferences ADD preferred_practice_id INT UNSIGNED NULL DEFAULT NULL AFTER delivered_hide_days");
        }
    } catch (PDOException $e) {
        userLog('Error ensuring preferred_practice_id column on user_preferences: ' . $e->getMessage(), true);
    }
}

/**
 * Logs a message with a user-management prefix
 * Only logs errors, not informational messages
 * @param string $message The message to log
 * @param bool $isError Whether this is an actual error (true) or just info (false)
 */
function userLog($message, $isError = false) {
    // Only log if it's an error or if we're in debug mode
    if ($isError) {
        error_log('[USER-MANAGER] ERROR: ' . $message);
    }
}

/**
 * Creates or updates a user in the database based on Google OAuth data
 * 
 * @param array $userData User data from Google OAuth
 * @return array|bool User record or false on failure
 */
function saveUserData($userData) {
    global $pdo;
    
    if (!$pdo) {
        userLog("Database connection not available");
        return false;
    }
    
    try {
        // Ensure profile picture URL is not too long for the column
        // This is now handled by changing the column to TEXT, but keep the check for safety
        if (isset($userData['picture']) && strlen($userData['picture']) > 1000) {
            userLog("Profile picture URL is very long: " . strlen($userData['picture']) . " characters");
        }
        
        // Check if user exists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $userData['email']]);
        $existingUser = $stmt->fetch();
        
        // Current timestamp
        $now = date('Y-m-d H:i:s');
        
        if ($existingUser) {
            // Update existing user
            $stmt = $pdo->prepare("
                UPDATE users SET
                    google_id = :google_id,
                    first_name = :first_name,
                    last_name = :last_name,
                    profile_picture = :profile_picture,
                    last_login_at = :last_login_at,
                    updated_at = :updated_at
                WHERE id = :id
            ");
            
            // Extract first and last name
            $nameParts = explode(' ', $userData['name']);
            $firstName = array_shift($nameParts);
            $lastName = implode(' ', $nameParts);
            
            $result = $stmt->execute([
                'google_id' => $userData['id'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'profile_picture' => $userData['picture'] ?? null,
                'last_login_at' => $now,
                'updated_at' => $now,
                'id' => $existingUser['id']
            ]);
            
            if ($result) {
                userLog("Updated existing user: {$userData['email']} (ID: {$existingUser['id']})");
                return array_merge($existingUser, [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'last_login_at' => $now
                ]);
            } else {
                userLog("Failed to update user: {$userData['email']}");
                return false;
            }
        } else {
            global $appConfig;
            
            // Check if this is the first user (make them admin)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
            $stmt->execute();
            $userCount = $stmt->fetchColumn();
            $isFirstUser = ($userCount == 0);
            
            // Also check if user's email is in the powerUsers or admins lists
            $isAdmin = $isFirstUser;
            if (!$isAdmin && isset($userData['email'])) {
                $powerUsers = $appConfig['powerUsers'] ?? [];
                $admins = $appConfig['admins'] ?? [];
                
                if (in_array($userData['email'], $powerUsers) || 
                    in_array($userData['email'], $admins)) {
                    $isAdmin = true;
                }
            }
            
            // Extract first and last name
            $nameParts = explode(' ', $userData['name']);
            $firstName = array_shift($nameParts);
            $lastName = implode(' ', $nameParts);
            
            // Create new user
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    email, google_id, first_name, last_name, 
                    profile_picture, role, last_login_at
                ) VALUES (
                    :email, :google_id, :first_name, :last_name,
                    :profile_picture, :role, :last_login_at
                )
            ");
            
            $result = $stmt->execute([
                'email' => $userData['email'],
                'google_id' => $userData['id'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'profile_picture' => $userData['picture'] ?? null,
                'role' => $isAdmin ? 'admin' : 'user',
                'last_login_at' => $now
            ]);
            
            if ($result) {
                $userId = $pdo->lastInsertId();
                userLog("Created new user: {$userData['email']} (ID: {$userId}, Role: " . 
                       ($isAdmin ? 'admin' : 'user') . ")");
                
                // Create default preferences
                createDefaultUserPreferences($userId);
                
                return [
                    'id' => $userId,
                    'email' => $userData['email'],
                    'google_id' => $userData['id'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'profile_picture' => $userData['picture'] ?? null,
                    'role' => $isAdmin ? 'admin' : 'user',
                    'is_active' => true,
                    'last_login_at' => $now
                ];
            } else {
                userLog("Failed to create user: {$userData['email']}");
                return false;
            }
        }
    } catch (PDOException $e) {
        userLog("Database error while saving user data: " . $e->getMessage());
        return false;
    }
}

/**
 * Creates default preferences for a new user
 * 
 * @param int $userId User ID
 * @return bool Success status
 */
function createDefaultUserPreferences($userId) {
    global $pdo;
    
    if (!$pdo) {
        userLog("Database connection not available");
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_preferences (
                user_id, theme, allow_card_delete, highlight_past_due, past_due_days
            ) VALUES (
                :user_id, 'light', TRUE, TRUE, 7
            )
        ");
        
        $result = $stmt->execute(['user_id' => $userId]);
        
        if ($result) {
            userLog("Created default preferences for user ID: {$userId}");
            return true;
        } else {
            userLog("Failed to create default preferences for user ID: {$userId}");
            return false;
        }
    } catch (PDOException $e) {
        userLog("Database error while creating default preferences: " . $e->getMessage());
        return false;
    }
}

/**
 * Creates a session record in the database
 * 
 * @param int $userId User ID
 * @param string $sessionId PHP Session ID
 * @return bool Success status
 */
function createSessionRecord($userId, $sessionId) {
    global $pdo;
    
    if (!$pdo) {
        userLog("Database connection not available");
        return false;
    }
    
    try {
        // Update expires_at after insertion because we're using DEFAULT CURRENT_TIMESTAMP
        $stmt = $pdo->prepare("
            INSERT INTO sessions (
                user_id, session_token, ip_address, user_agent
            ) VALUES (
                :user_id, :session_token, :ip_address, :user_agent
            )
        ");
        
        $result = $stmt->execute([
            'user_id' => $userId,
            'session_token' => $sessionId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        // Now set the expiration to 30 minutes from now
        if ($result) {
            $sessionId = $pdo->lastInsertId();
            $expiresAt = date('Y-m-d H:i:s', time() + 1800);
            $pdo->prepare("UPDATE sessions SET expires_at = :expires_at WHERE id = :id")->execute([
                'expires_at' => $expiresAt,
                'id' => $sessionId
            ]);
        }
        
        if ($result) {
            userLog("Created session record for user ID: {$userId}");
            return true;
        } else {
            userLog("Failed to create session record for user ID: {$userId}");
            return false;
        }
    } catch (PDOException $e) {
        userLog("Database error while creating session record: " . $e->getMessage());
        return false;
    }
}

/**
 * Logs user activity
 * 
 * @param int $userId User ID
 * @param string $activityType Type of activity (login, logout, etc.)
 * @param string $description Description of the activity
 * @return bool Success status
 */
function logUserActivity($userId, $activityType, $description = '') {
    global $pdo;
    
    if (!$pdo) {
        userLog("Database connection not available");
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_activity_log (
                user_id, activity_type, description, ip_address
            ) VALUES (
                :user_id, :activity_type, :description, :ip_address
            )
        ");
        
        $result = $stmt->execute([
            'user_id' => $userId,
            'activity_type' => $activityType,
            'description' => $description,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        if (!$result) {
            userLog("Failed to log user activity for user ID: {$userId}");
        }
        
        return $result;
    } catch (PDOException $e) {
        userLog("Database error while logging user activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Gets user preferences from the database
 * 
 * @param int $userId User ID
 * @return array|bool Preferences or false on failure
 */
function getUserPreferences($userId) {
    global $pdo;
    
    if (!$pdo) {
        userLog("Database connection not available");
        return false;
    }
    ensureUserPreferencesSchema();
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM user_preferences WHERE user_id = :user_id
        ");
        
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        userLog("Database error while getting user preferences: " . $e->getMessage());
        return false;
    }
}
