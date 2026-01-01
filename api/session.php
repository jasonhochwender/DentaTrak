<?php
/**
 * Session management
 * 
 * This file handles session configuration and initialization.
 */

// Detect if we're in production (HTTPS) or development
$isProduction = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// Set secure session parameters
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', $isProduction ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');

// Set session lifetime (30 minutes)
ini_set('session.gc_maxlifetime', 1800);
ini_set('session.cookie_lifetime', 1800);

// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Cloud Run: use /tmp for session storage (only writable path)
    // K_SERVICE environment variable is always set in Cloud Run
    if (getenv('K_SERVICE')) {
        session_save_path('/tmp');
    }
    
    session_set_cookie_params([
        'lifetime' => 1800,
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
