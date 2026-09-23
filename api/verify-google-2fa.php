<?php
/**
 * Verify 2FA Code for Pending Sign-In
 *
 * Completes any sign-in held in the server-side pending-2FA state after
 * TOTP verification. Used by the Google OAuth callback and by Remember Me
 * restores for users with personal 2FA configured - in both cases the
 * first factor already happened elsewhere and only the code is verified
 * here.
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

// Brute-force guard: max 5 code attempts per 5-minute window per session
// (same bound as the authenticated challenge endpoint - the code space
// is only 10^6 and a 30s window needs an attempt cap).
$attempts = $_SESSION['2fa_challenge_attempts'] ?? ['count' => 0, 'window_start' => 0];
if ((time() - (int)$attempts['window_start']) > 300) {
    $attempts = ['count' => 0, 'window_start' => time()];
}
if ((int)$attempts['count'] >= 5) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many attempts. Please wait a few minutes and try again.'
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
    $attempts['count']++;
    $_SESSION['2fa_challenge_attempts'] = $attempts;
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid authentication code. Please try again.'
    ]);
    exit;
}

// 2FA verified successfully - complete the login
// Clear pending 2FA data (email + remember-me paths share these fields)
unset($_SESSION['pending_2fa_user_id']);
unset($_SESSION['pending_2fa_auth_method']);
unset($_SESSION['pending_2fa_user_data']);
unset($_SESSION['pending_2fa_db_user']);
unset($_SESSION['pending_2fa_email']);
unset($_SESSION['pending_2fa_remember_me']);
unset($_SESSION['pending_2fa_timestamp']);
unset($_SESSION['2fa_challenge_attempts']);

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

// Per-session TOTP proof: this endpoint IS the Google 2FA challenge, so a
// successful verification marks this session as 2FA-satisfied for
// practice-wide enforcement (set after setupUserSession clears it).
$_SESSION['totp_verified'] = true;

// Session already set up by setupUserSession(), but keep backward-compatible fields
$_SESSION['db_user_id'] = $dbUser['id'];
$_SESSION['user_role'] = $dbUser['role'];

// Record the login activity
$methodLabel = $authMethod === 'remember_me' ? 'Remember Me' : 'Google OAuth';
logUserActivity($dbUser['id'], 'login', 'User logged in via ' . $methodLabel . ' with 2FA');

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
