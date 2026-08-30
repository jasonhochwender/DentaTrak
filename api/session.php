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
define('UPLOAD_PING_WINDOW', $sessionTimeout);      // upload keepalive is only valid for the current inactivity window

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
 * Determine the authoritative inactivity reference timestamp for the session.
 * Uses the later of the server-recorded last_activity and the client-reported
 * last_user_action_at so that both full-page loads and JavaScript-tracked
 * genuine interactions keep the deadline accurate.
 *
 * @return int Unix timestamp, or 0 if neither value is set
 */
function getSessionInactivityReference() {
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    $lastUserAction = $_SESSION['last_user_action_at'] ?? 0;
    return max($lastActivity, $lastUserAction);
}

/**
 * Fully expire a session due to inactivity:
 * - Clear and invalidate the remember-me token
 * - Destroy the PHP session
 */
function expireInactivitySession() {
    // Load the remember-me helpers if they are not already available.
    // unified-identity.php defines clearRememberMeCookie(), which both
    // deletes the matching token from the database and expires the cookie.
    if (!function_exists('clearRememberMeCookie')) {
        $unifiedIdentityPath = __DIR__ . '/unified-identity.php';
        if (file_exists($unifiedIdentityPath)) {
            require_once $unifiedIdentityPath;
        }
    }

    if (function_exists('clearRememberMeCookie')) {
        clearRememberMeCookie();
    }

    session_unset();
    session_destroy();
}

/**
 * Check if session has timed out due to inactivity
 * @return bool True if session is still valid, false if timed out
 */
function checkSessionTimeout() {
    // session-status.php is the client-visible status endpoint and handles
    // its own timeout enforcement so it can return a JSON reason to the client.
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($currentScript === 'session-status.php') {
        return true;
    }

    $lastReference = getSessionInactivityReference();
    if ($lastReference > 0) {
        $inactiveTime = time() - $lastReference;

        if ($inactiveTime > SESSION_TIMEOUT) {
            // Session has timed out. Destroy the session and clear the remember
            // token so the user cannot be silently auto-logged back in.
            expireInactivitySession();
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
    $lastReference = getSessionInactivityReference();
    if ($lastReference <= 0) {
        return SESSION_TIMEOUT;
    }

    $elapsed = time() - $lastReference;
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

// Update inactivity timestamps, but only for genuine user activity.
// Background polling, analytics, status checks, and read-only API GETs must
// NOT refresh the deadline. Page loads and mutating requests (POST/PUT/DELETE)
// are genuine. The upload keepalive endpoint is also treated as genuine because
// the user is actively uploading.
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$passiveEndpoints = ['notifications.php', 'check-updates.php'];
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isPassive = in_array($currentScript, $passiveEndpoints) && in_array($requestMethod, ['GET', 'HEAD']);
$isApiRead = strpos($scriptPath, '/api/') !== false && in_array($requestMethod, ['GET', 'HEAD']);
$isPageLoad = strpos($scriptPath, '/api/') === false && $requestMethod === 'GET';
$isMutating = in_array($requestMethod, ['POST', 'PUT', 'DELETE', 'PATCH']);

// session-status.php performs its own timeout enforcement and its own
// timestamp updates (for activity/extend), so it must not be updated here.
$isSelfHandled = ($currentScript === 'session-status.php');

if (!$isPassive && !$isSelfHandled) {
    if ($currentScript === 'ping.php') {
        // Long-running upload keepalive. Only renew when the browser recently
        // obtained an upload signed URL; otherwise this endpoint could be used
        // as an unconditional background keepalive.
        $lastUploadPing = $_SESSION['last_upload_ping'] ?? 0;
        if ($lastUploadPing > 0 && (time() - $lastUploadPing) <= UPLOAD_PING_WINDOW) {
            $_SESSION['last_activity'] = time();
            $_SESSION['last_upload_ping'] = time(); // slide the window
        }
    } elseif ($isMutating) {
        // State-changing actions such as creating/updating a case,
        // marking a notification read, changing a setting, etc.
        $_SESSION['last_activity'] = time();
        $_SESSION['last_user_action_at'] = time();
    } elseif ($isPageLoad) {
        // Navigating to an authenticated page such as main.php or admin-practices.php
        $_SESSION['last_activity'] = time();
    }
    // API read GETs (e.g. list-cases, get-case, billing status) do NOT refresh
    // the inactivity timer. The user's click/scroll/touch that triggered the
    // request will be recorded by the JS activity ping instead.
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
