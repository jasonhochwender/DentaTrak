<?php
/**
 * Session management
 *
 * This file handles session timeout configuration and "Remember Me" auto-login.
 * The actual session storage is configured in appConfig.php, which now uses the
 * shared PDO session handler instead of container-local files.
 */

// Session timeout configuration
$sessionTimeout = (int)(getenv('SESSION_TIMEOUT') ?: ($_ENV['SESSION_TIMEOUT'] ?? 3600));
$sessionTimeout = max(300, min(86400, $sessionTimeout)); // clamp between 5 min and 24 hours
$sessionWarningTime = (int)(getenv('SESSION_WARNING_TIME') ?: ($_ENV['SESSION_WARNING_TIME'] ?? 300));
$sessionWarningTime = max(60, min($sessionTimeout - 60, $sessionWarningTime)); // at least 60s, before timeout
$sessionGcLifetime = (int)($sessionTimeout * 1.5);

define('SESSION_TIMEOUT', $sessionTimeout);        // inactivity timeout (default 60 minutes)
define('SESSION_WARNING_TIME', $sessionWarningTime); // warning before timeout (default 5 minutes)
define('SESSION_GC_LIFETIME', $sessionGcLifetime);  // must exceed timeout to allow keepalive

// Set GC lifetime before appConfig.php starts the session so the PDO handler picks it up
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_GC_LIFETIME);
}

// Load app configuration and start the session with the shared PDO handler
require_once __DIR__ . '/appConfig.php';

/**
 * Regenerates the session ID and updates last activity time
 */
function regenerateSession() {
    // Only regenerate if session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    
    // Regenerate session ID periodically
    if (!isset($_SESSION['last_regeneration']) ||
        $_SESSION['last_regeneration'] < time() - 300) {

        $oldId = session_id();

        // Update the regeneration timestamp first so it is included in the
        // new session row written by markRotated().
        $_SESSION['last_regeneration'] = time();

        // Use delete_old_session=false so the handler can manage a tightly
        // bounded 30-second rotation mapping. markRotated() makes the old id
        // expire immediately while keeping in-flight requests valid.
        @session_regenerate_id(false);

        $newId = session_id();
        if ($oldId !== $newId && $newId !== '') {
            $handler = $GLOBALS['pdoSessionHandler'] ?? null;
            if ($handler instanceof PdoSessionHandler) {
                $handler->markRotated($oldId, $newId);
            }
        }
    }
}

// Initialize or regenerate session
regenerateSession();

/**
 * Check if session has timed out due to inactivity
 * @return bool True if session is still valid, false if timed out
 */
function checkSessionTimeout() {
    // Skip timeout check for API endpoints that check session status
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($currentScript === 'session-status.php') {
        return true;
    }
    
    if (isset($_SESSION['last_activity'])) {
        $inactiveTime = time() - $_SESSION['last_activity'];
        
        if ($inactiveTime > SESSION_TIMEOUT) {
            // Session has timed out - destroy it
            session_unset();
            session_destroy();
            
            // NOTE: Do NOT clear remember_token cookie here!
            // The Remember Me cookie should persist across session timeouts
            // so users can be auto-logged in when they return.
            // The cookie is only cleared on explicit logout.
            
            return false;
        }
    }
    
    return true;
}

/**
 * Get remaining session time in seconds
 * @return int Seconds remaining before timeout
 */
function getSessionTimeRemaining() {
    if (!isset($_SESSION['last_activity'])) {
        return SESSION_TIMEOUT;
    }
    
    $elapsed = time() - $_SESSION['last_activity'];
    return max(0, SESSION_TIMEOUT - $elapsed);
}

// Check for session timeout (only for logged-in users)
if (!empty($_SESSION['db_user_id']) && !checkSessionTimeout()) {
    // Session timed out - redirect to login if this is a page request (not API)
    $isApiRequest = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    if (!$isApiRequest) {
        $loginRedirect = '/login.php?timeout=1';
        if (!empty($_GET['notification_id'])) {
            $loginRedirect .= '&notification_id=' . urlencode($_GET['notification_id']);
        }
        header('Location: ' . $loginRedirect);
        exit;
    }
}

// Update last activity time - but NOT for passive/polling endpoints
// This ensures the inactivity timer only resets on actual user activity
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$passiveEndpoints = ['session-status.php', 'get-notifications.php', 'check-updates.php'];
if (!in_array($currentScript, $passiveEndpoints)) {
    $_SESSION['last_activity'] = time();
}

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
    
    // Access $pdo directly from GLOBALS - appConfig.php should already be loaded by the parent script
    $pdo = $GLOBALS['pdo'] ?? null;
    
    if (!$pdo) {
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
                    
                    // Resolve which practice (if any) to auto-select, or
                    // whether the user needs to be sent to the existing
                    // practice chooser. Same resolution used by every other
                    // login path - see resolveLoginPracticeSelection() in
                    // user-manager.php.
                    $userManagerPath = __DIR__ . '/user-manager.php';
                    if (file_exists($userManagerPath)) {
                        require_once $userManagerPath;
                    }
                    if (function_exists('resolveLoginPracticeSelection')) {
                        resolveLoginPracticeSelection($user['id']);
                    }
                    
                    return true;
                }
            }
        }
    }
    
    return false;
}

// NOTE: attemptRememberMeLogin() is NOT called automatically here
// It must be called explicitly by the parent script (e.g., login.php)
// AFTER appConfig.php has been loaded to ensure $pdo is available
