<?php
/**
 * Google OAuth 2.0 Login Callback Handler
 * 
 * This file handles the callback from Google's OAuth 2.0 flow for LOGIN ONLY.
 * This uses only openid, email, profile scopes - NO Drive access.
 * Drive authorization is handled separately via google-drive-callback.php.
 */

// Disable error display in production
ini_set('display_errors', 0);
// Only log real errors, not notices or warnings
error_reporting(E_ERROR | E_PARSE);

// Include configuration first (it handles session start)
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/redirect-uris.php';
require_once __DIR__ . '/unified-identity.php';
require_once __DIR__ . '/totp.php';

// Simple log function that only logs actual errors
function logMsg($message, $isError = false) {
    if ($isError) {
        error_log('[AUTH-CALLBACK] ERROR: ' . $message);
    }
}

// Log the start of process (disabled to avoid noisy logs)
// logMsg('Auth callback started. Session ID: ' . session_id());

// Check for required parameters
$code = $_GET['code'] ?? null;
if (!$code) {
    logMsg('No authorization code received', true);
    header('Location: ../login.php?error=no_code');
    exit;
}

// Exchange code for token
// logMsg('Exchanging code for token');
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenParams = [
    'code'          => $code,
    'client_id'     => $appConfig['google_client_id'],
    'client_secret' => $appConfig['google_client_secret'],
    // Use the redirect URI from our central configuration
    // This ensures it matches what's in Google Cloud Console
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenParams));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// LOCAL DEVELOPMENT ONLY: disable SSL verification to avoid missing/invalid cacert.pem issues
// Do NOT use this in production; instead configure a valid CA bundle in php.ini (curl.cainfo)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Log HTTP code and raw response for debugging (only on error to keep logs clean)
if ($curlError || $httpCode < 200 || $httpCode >= 300) {
    logMsg('Token HTTP code: ' . $httpCode);
    if ($curlError) {
        logMsg('cURL error during token exchange: ' . $curlError);
    }
    logMsg('Token raw response: ' . $response);
}

// Parse token response
$tokenData = json_decode($response, true);
if (!$tokenData || isset($tokenData['error'])) {
    $error = isset($tokenData['error']) ? $tokenData['error'] : 'token_exchange_failed';
    logMsg('Token error: ' . $error);
    // If Google provided more details, log them too
    if (isset($tokenData['error_description'])) {
        logMsg('Token error description: ' . $tokenData['error_description']);
    }
    header('Location: ../login.php?error=' . urlencode($error));
    exit;
}

// Get access token
$accessToken = $tokenData['access_token'];

// Store LOGIN token for user info retrieval (NOT for Drive - Drive has separate tokens)
// This token only has openid, email, profile scopes
$_SESSION['google_login_token'] = $tokenData;

// Get user info
$userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
// LOCAL DEVELOPMENT ONLY: disable SSL verification for this call as well
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$userInfoResponse = curl_exec($ch);
curl_close($ch);

// Parse user info
$userInfo = json_decode($userInfoResponse, true);
if (!$userInfo || isset($userInfo['error'])) {
    $error = isset($userInfo['error']) ? $userInfo['error'] : 'user_info_failed';
    logMsg('User info error: ' . $error);
    header('Location: ../login.php?error=' . urlencode($error));
    exit;
}

// Normalize the profile picture URL
$pictureUrl = $userInfo['picture'] ?? '';
if (!empty($pictureUrl)) {
    // If Google ever returns an http URL, upgrade it to https to avoid mixed-content issues
    if (strpos($pictureUrl, 'http://') === 0) {
        $pictureUrl = 'https://' . substr($pictureUrl, 7);
    }
}
// logMsg('User info picture URL: ' . ($pictureUrl ?: '[none]'));

// Prepare user data for unified identity system
$googleData = [
    'sub'     => $userInfo['sub'] ?? '',
    'id'      => $userInfo['sub'] ?? '',
    'name'    => $userInfo['name'] ?? '',
    'email'   => $userInfo['email'] ?? '',
    'picture' => $pictureUrl
];

// Use unified identity system to authenticate/create user
// This ensures no duplicate users are created and properly links auth methods
$authResult = authenticateWithGoogle($googleData);

if (!$authResult['success']) {
    logMsg('Unified identity auth failed: ' . ($authResult['message'] ?? 'Unknown error'), true);
    header('Location: ../index.php?error=' . urlencode($authResult['message'] ?? 'auth_failed'));
    exit;
}

$dbUser = $authResult['user'];
$isNewUser = $authResult['is_new_user'] ?? false;

// ============================================
// TWO-FACTOR AUTHENTICATION CHECK
// Security: If 2FA is enabled, redirect to 2FA verification page
// ============================================
try {
    $twoFAStatus = get2FAStatus($dbUser['id']);
} catch (Exception $e) {
    $twoFAStatus = ['enabled' => false];
}

if ($twoFAStatus['enabled']) {
    // Store pending 2FA data in session
    $_SESSION['pending_2fa_user_id'] = $dbUser['id'];
    $_SESSION['pending_2fa_auth_method'] = 'google';
    $_SESSION['pending_2fa_user_data'] = [
        'id'      => $dbUser['google_id'] ?? '',
        'name'    => trim(($dbUser['first_name'] ?? '') . ' ' . ($dbUser['last_name'] ?? '')),
        'email'   => $dbUser['email'] ?? '',
        'picture' => $dbUser['profile_picture'] ?? $pictureUrl
    ];
    $_SESSION['pending_2fa_db_user'] = $dbUser;
    
    // Redirect to login page with 2FA flag
    header('Location: ../login.php?require_2fa=google');
    exit;
}

// Store user data in session (for backward compatibility)
$_SESSION['user'] = [
    'id'      => $dbUser['google_id'] ?? '',
    'name'    => trim(($dbUser['first_name'] ?? '') . ' ' . ($dbUser['last_name'] ?? '')),
    'email'   => $dbUser['email'] ?? '',
    'picture' => $dbUser['profile_picture'] ?? $pictureUrl
];

// Set up unified session
setupUserSession($dbUser, 'google');

// Session already set up by setupUserSession(), but keep backward-compatible fields
$_SESSION['db_user_id'] = $dbUser['id'];
$_SESSION['user_role'] = $dbUser['role'];

// Set first login flag if this is a new user
if ($isNewUser) {
    $_SESSION['first_login'] = true;
}

// Record the login activity
logUserActivity($dbUser['id'], 'login', 'User logged in via Google OAuth');

// Create a session record
createSessionRecord($dbUser['id'], session_id());

// Get user preferences and store in session
$preferences = getUserPreferences($dbUser['id']);
if ($preferences) {
    $_SESSION['user_preferences'] = $preferences;
} else {
    $preferences = [];
}

// Check if user needs to select a practice
try {
    // First check if the practice_users table exists
    $tableExists = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'practice_users'");
        $tableExists = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        logMsg("Error checking for practice_users table: " . $e->getMessage());
        $tableExists = false;
    }
    
    if (!$tableExists) {
        // Tables don't exist yet, set first-time flag
        $_SESSION['needs_practice_setup'] = true;
        $_SESSION['first_time_login'] = true;
        logMsg("Practice tables don't exist yet. User will be sent to setup page.");
    } else {
        // Check if user is associated with any practices
        $stmt = $pdo->prepare("SELECT p.id, p.practice_name, pu.role, pu.is_owner 
                               FROM practice_users pu 
                               JOIN practices p ON pu.practice_id = p.id 
                               WHERE pu.user_id = :user_id");
        $stmt->execute(['user_id' => $dbUser['id']]);
        $userPractices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasPractice = !empty($userPractices);
        $practiceCount = count($userPractices);

        $preferredPracticeId = null;
        if (!empty($preferences) && !empty($preferences['preferred_practice_id'])) {
            $preferredPracticeId = (int)$preferences['preferred_practice_id'];
            $validPreference = false;
            foreach ($userPractices as $practice) {
                if ((int)$practice['id'] === $preferredPracticeId) {
                    $validPreference = true;
                    break;
                }
            }
            if (!$validPreference) {
                $preferredPracticeId = null;
            }
        }
        
        // Store all practices in session for the selection page
        $_SESSION['available_practices'] = $userPractices;
        
        if (!$hasPractice) {
            // User has no practice at all - needs to set up one
            $_SESSION['needs_practice_setup'] = true;
            $_SESSION['needs_practice_selection'] = false;
            $_SESSION['has_multiple_practices'] = false;
            logMsg("User has no practice. Will be sent to practice setup.");
        } else if ($preferredPracticeId) {
            // User has a preferred practice set - use it
            $_SESSION['current_practice_id'] = $preferredPracticeId;
            $_SESSION['needs_practice_setup'] = false;
            $_SESSION['needs_practice_selection'] = false;
            $_SESSION['has_multiple_practices'] = ($practiceCount > 1);
            logMsg("Using preferred practice ID from user preferences: {$preferredPracticeId}");
        } else if ($practiceCount === 1) {
            // User has exactly one practice (owned or member) - auto-select it
            $_SESSION['current_practice_id'] = $userPractices[0]['id'];
            $_SESSION['needs_practice_setup'] = false;
            $_SESSION['needs_practice_selection'] = false;
            $_SESSION['has_multiple_practices'] = false;
            logMsg("User has a single practice. Auto-selecting practice ID: {$_SESSION['current_practice_id']}");
        } else {
            // User has multiple practices but no preference - auto-select first one
            // User can switch practices via the header switcher
            $_SESSION['current_practice_id'] = $userPractices[0]['id'];
            $_SESSION['has_multiple_practices'] = true;
            $_SESSION['needs_practice_selection'] = false;
            $_SESSION['needs_practice_setup'] = false;
            logMsg("User has {$practiceCount} practices. Auto-selecting first practice ID: {$_SESSION['current_practice_id']}");
        }
    }
} catch (PDOException $e) {
    logMsg("Error checking practice status: " . $e->getMessage());
    // Default to practice setup if we can't determine status
    $_SESSION['needs_practice_setup'] = true;
}

logMsg('User data saved to database: ID=' . $dbUser['id'] . ', Role=' . $dbUser['role']);

// Determine if this flow was started in a popup window
$mode = $_SESSION['oauth_mode'] ?? 'redirect';
unset($_SESSION['oauth_mode']);

if ($mode === 'popup') {
    // Return a small page whose only job is to update the opener and close itself
    ?><!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Signing In...</title>
        <script>
        (function() {
          try {
            if (window.opener && !window.opener.closed) {
              // Check if we need to go to practice setup
              var needsPracticeSetup = <?php echo isset($_SESSION['needs_practice_setup']) && $_SESSION['needs_practice_setup'] ? 'true' : 'false'; ?>;
              var needsPracticeSelection = <?php echo isset($_SESSION['needs_practice_selection']) && $_SESSION['needs_practice_selection'] ? 'true' : 'false'; ?>;
              
              if (needsPracticeSetup || needsPracticeSelection) {
                window.opener.location.href = '../practice-setup.php';
              } else {
                window.opener.location.href = '../main.php';
              }
              window.close();
            } else {
              // Fallback: just navigate this window
              var needsPracticeSetup = <?php echo isset($_SESSION['needs_practice_setup']) && $_SESSION['needs_practice_setup'] ? 'true' : 'false'; ?>;
              var needsPracticeSelection = <?php echo isset($_SESSION['needs_practice_selection']) && $_SESSION['needs_practice_selection'] ? 'true' : 'false'; ?>;
              
              if (needsPracticeSetup || needsPracticeSelection) {
                window.location.href = '../practice-setup.php';
              } else {
                window.location.href = '../main.php';
              }
            }
          } catch (e) {
            // If anything goes wrong, fallback to navigating this window
            window.location.href = '../main.php';
          }
        })();
        </script>
    </head>
    <body>
        <p>Completing sign-in...</p>
    </body>
    </html><?php
    exit;
}

// Determine where to redirect based on practice setup needs
if (isset($_SESSION['needs_practice_setup']) && $_SESSION['needs_practice_setup'] ||
    isset($_SESSION['needs_practice_selection']) && $_SESSION['needs_practice_selection']) {
    header('Location: ../practice-setup.php');
} else {
    header('Location: ../main.php');
}
exit;
