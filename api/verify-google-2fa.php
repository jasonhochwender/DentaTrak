<?php
/**
 * Verify 2FA Code for Google Sign-In
 * 
 * This endpoint completes the Google sign-in process after 2FA verification.
 * The user data is stored in session during the Google OAuth callback.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/unified-identity.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$totpCode = $input['totpCode'] ?? '';

// Validate we have pending 2FA data
if (empty($_SESSION['pending_2fa_user_id']) || empty($_SESSION['pending_2fa_auth_method'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No pending authentication. Please sign in again.'
    ]);
    exit;
}

// Validate TOTP code format
if (empty($totpCode) || strlen($totpCode) !== 6 || !ctype_digit($totpCode)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid 6-digit code.'
    ]);
    exit;
}

$userId = $_SESSION['pending_2fa_user_id'];
$authMethod = $_SESSION['pending_2fa_auth_method'];
$userData = $_SESSION['pending_2fa_user_data'] ?? [];
$dbUser = $_SESSION['pending_2fa_db_user'] ?? null;

// Verify the TOTP code
$secret = get2FASecret($userId);
if (!$secret || !TOTP::verifyCode($secret, $totpCode)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid authentication code. Please try again.'
    ]);
    exit;
}

// 2FA verified successfully - complete the login
// Clear pending 2FA data
unset($_SESSION['pending_2fa_user_id']);
unset($_SESSION['pending_2fa_auth_method']);
unset($_SESSION['pending_2fa_user_data']);
unset($_SESSION['pending_2fa_db_user']);

if (!$dbUser) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please sign in again.'
    ]);
    exit;
}

// Store user data in session
$_SESSION['user'] = $userData;

// Set up unified session
setupUserSession($dbUser, $authMethod);

// Session already set up by setupUserSession(), but keep backward-compatible fields
$_SESSION['db_user_id'] = $dbUser['id'];
$_SESSION['user_role'] = $dbUser['role'];

// Record the login activity
logUserActivity($dbUser['id'], 'login', 'User logged in via Google OAuth with 2FA');

// Create a session record
createSessionRecord($dbUser['id'], session_id());

// Get user preferences and store in session
$preferences = getUserPreferences($dbUser['id']);
if ($preferences) {
    $_SESSION['user_preferences'] = $preferences;
} else {
    $preferences = [];
}

// Check practice setup (similar to google-auth-callback.php)
$redirect = 'main.php';
try {
    global $pdo;
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'practice_users'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        $_SESSION['needs_practice_setup'] = true;
        $_SESSION['first_time_login'] = true;
        $redirect = 'practice-setup.php';
    } else {
        // Resolve which practice (if any) to auto-select, or whether the
        // user needs to be sent to the existing practice chooser. Single
        // source of truth shared with the other login paths - see
        // resolveLoginPracticeSelection() in user-manager.php.
        $practiceSelection = resolveLoginPracticeSelection($dbUser['id']);
        $redirect = $practiceSelection['redirect'];
    }
} catch (PDOException $e) {
    error_log('[verify-google-2fa] Error checking practice status: ' . $e->getMessage());
    $_SESSION['needs_practice_setup'] = true;
    $redirect = 'practice-setup.php';
}

echo json_encode([
    'success' => true,
    'redirect' => $redirect
]);
