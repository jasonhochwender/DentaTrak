<?php
/**
 * Session management
 * 
 * This file handles session configuration and initialization.
 * Also handles "Remember Me" auto-login via persistent tokens.
 */

// Detect if we're in production (HTTPS) or development
$isProduction = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Set secure session parameters BEFORE starting session
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', $isProduction ? 1 : 0);
    ini_set('session.cookie_samesite', 'Lax');

    // Set session lifetime (30 minutes for non-remembered sessions)
    ini_set('session.gc_maxlifetime', 1800);
    ini_set('session.cookie_lifetime', 0); // Session cookie (expires on browser close) by default
    
    // Set session storage path
    if (getenv('K_SERVICE')) {
        // Cloud Run: use /tmp for session storage (only writable path)
        session_save_path('/tmp');
    } else {
        // Local development: use a sessions folder in the project to avoid permission issues
        $localSessionPath = __DIR__ . '/../sessions';
        if (!is_dir($localSessionPath)) {
            @mkdir($localSessionPath, 0700, true);
        }
        if (is_dir($localSessionPath) && is_writable($localSessionPath)) {
            session_save_path($localSessionPath);
        }
    }
    
    session_set_cookie_params([
        'lifetime' => 0, // Session cookie - expires when browser closes
        'path' => '/',
        'domain' => '',
        'secure' => $isProduction,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    try {
        session_start();
    } catch (Exception $e) {
        error_log('Failed to start session in ' . __FILE__);
        throw $e;
    }
}

/**
 * Regenerates the session ID and updates last activity time
 */
function regenerateSession() {
    // Regenerate session ID
    if (!isset($_SESSION['last_regeneration']) || 
        $_SESSION['last_regeneration'] < time() - 300) {
        
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Initialize or regenerate session
regenerateSession();

// Update last activity time
$_SESSION['last_activity'] = time();

// ============================================
// REMEMBER ME AUTO-LOGIN
// Security: Validates persistent token and restores session
// Only runs if user is not already logged in
// ============================================
function attemptRememberMeLogin() {
    // Only attempt if not already logged in
    if (!empty($_SESSION['db_user_id'])) {
        return false;
    }
    
    // Check if remember me cookie exists
    if (empty($_COOKIE['remember_token'])) {
        return false;
    }
    
    // Load unified identity functions if not already loaded
    $unifiedIdentityPath = __DIR__ . '/unified-identity.php';
    if (file_exists($unifiedIdentityPath)) {
        require_once $unifiedIdentityPath;
        
        // Validate the remember me token
        if (function_exists('validateRememberMeToken')) {
            $user = validateRememberMeToken();
            
            if ($user) {
                // Token is valid - set up session
                if (function_exists('setupUserSession')) {
                    setupUserSession($user, 'remember_me');
                    
                    // Load user practices
                    if (function_exists('getUserPractices')) {
                        $userPractices = getUserPractices($user['id']);
                        $_SESSION['available_practices'] = $userPractices;
                        
                        if (count($userPractices) > 0) {
                            $_SESSION['current_practice_id'] = $userPractices[0]['id'];
                            $_SESSION['practice_uuid'] = $userPractices[0]['uuid'] ?? null;
                            $_SESSION['has_multiple_practices'] = (count($userPractices) > 1);
                            $_SESSION['needs_practice_setup'] = false;
                            $_SESSION['needs_practice_selection'] = false;
                        } else {
                            $_SESSION['needs_practice_setup'] = true;
                        }
                    }
                    
                    return true;
                }
            }
        }
    }
    
    return false;
}

// Attempt remember me login (only on page loads, not API calls)
// This is called automatically when session.php is included
attemptRememberMeLogin();
