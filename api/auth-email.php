<?php
/**
 * Email/Password Authentication API
 * Handles registration and login with email/password
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/unified-identity.php';
require_once __DIR__ . '/email-verification.php';
require_once __DIR__ . '/signup-notification-email.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Get JSON input
$jsonInput = file_get_contents('php://input');
$input = json_decode($jsonInput, true);

$action = $input['action'] ?? '';

// Password validation rules
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = t('auth.errors.password_min_length');
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = t('auth.errors.password_upper');
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = t('auth.errors.password_number');
    }
    
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
        $errors[] = t('auth.errors.password_special');
    }
    
    return $errors;
}

// Ensure password columns exist in users table (auto-migration)
function ensurePasswordColumns($pdo) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL COMMENT 'Bcrypt hashed password for email/password auth'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_method'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN auth_method ENUM('google', 'email', 'both') NOT NULL DEFAULT 'google' COMMENT 'How user authenticates'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN email_verified BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether email has been verified for email auth'");
        }
    } catch (PDOException $e) {
        // Columns might already exist
    }
}

// Run migration
ensurePasswordColumns($pdo);

switch ($action) {
    case 'register':
        handleRegister($pdo, $input);
        break;
    case 'login':
        handleLogin($pdo, $input);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.invalid_action')]);
        break;
}

function handleRegister($pdo, $input) {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';
    $firstName = trim($input['firstName'] ?? '');
    $lastName = trim($input['lastName'] ?? '');
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.invalid_email')]);
        return;
    }
    
    // Check passwords match
    if ($password !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.passwords_not_match')]);
        return;
    }
    
    // Validate password strength
    $passwordErrors = validatePassword($password);
    if (!empty($passwordErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => t('auth.errors.password_requirements_not_met'),
            'errors' => $passwordErrors
        ]);
        return;
    }
    
    // Use unified identity system for registration
    // This ensures no duplicate users and properly links auth methods
    $result = registerWithEmail($email, $password, $firstName, $lastName);
    
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        return;
    }
    
    // Send verification email for new registrations
    $userId = $result['user_id'] ?? null;
    if ($userId && !($result['linked_existing'] ?? false)) {
        // Internal notification to DentaTrak team - sent immediately after a new
        // user is created, independent of the verification email. Failure here
        // must not affect the registration outcome.
        try {
            sendSignupNotificationEmail($pdo, (int)$userId, $appConfig);
        } catch (Exception $e) {
            error_log('[auth-email] Failed to send signup notification for user ' . $userId . ': ' . $e->getMessage());
        }

        // New user - send verification email
        $verifyResult = sendVerificationEmail($pdo, $userId, $email, $firstName);

        echo json_encode([
            'success' => true,
            'message' => t('auth.login.verify_email_message'),
            'requires_verification' => true
        ]);
    } else {
        // Linked to existing account (e.g., Google user adding password)
        echo json_encode($result);
    }
}

function handleLogin($pdo, $input) {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $rememberMe = !empty($input['rememberMe']); // Remember Me checkbox value
    $totpCode = $input['totpCode'] ?? ''; // 2FA code if provided
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.invalid_email')]);
        return;
    }
    
    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.password_required')]);
        return;
    }
    
    // Use unified identity system for authentication
    $authResult = authenticateWithEmail($email, $password);
    
    if (!$authResult['success']) {
        http_response_code(401);
        echo json_encode($authResult);
        return;
    }
    
    $user = $authResult['user'];
    
    // Check if email is verified (only for email-only auth, not for users who also have Google)
    if ($user['auth_method'] === 'email' && !$user['email_verified']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => t('auth.errors.email_not_verified'),
            'requires_verification' => true,
            'email' => $email
        ]);
        return;
    }
    
    // ============================================
    // TWO-FACTOR AUTHENTICATION CHECK
    // Security: If 2FA is enabled, require valid TOTP code
    // ============================================
    require_once __DIR__ . '/totp.php';
    
    $twoFAStatus = get2FAStatus($user['id']);
    
    if ($twoFAStatus['enabled']) {
        // 2FA is enabled - check if code was provided
        if (empty($totpCode)) {
            // Return response indicating 2FA is required
            // Store partial auth in session for 2FA verification
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_email'] = $email;
            $_SESSION['pending_2fa_remember_me'] = $rememberMe;
            $_SESSION['pending_2fa_timestamp'] = time();
            
            echo json_encode([
                'success' => false,
                'requires_2fa' => true,
                'message' => t('auth.errors.2fa_required')
            ]);
            return;
        }
        
        // Verify the TOTP code
        $secret = get2FASecret($user['id']);
        if (!$secret || !TOTP::verifyCode($secret, $totpCode)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'requires_2fa' => true,
                'message' => t('auth.errors.invalid_2fa')
            ]);
            return;
        }
        
        // Clear pending 2FA session data
        unset($_SESSION['pending_2fa_user_id']);
        unset($_SESSION['pending_2fa_email']);
        unset($_SESSION['pending_2fa_remember_me']);
        unset($_SESSION['pending_2fa_timestamp']);
    }
    
    // Set up unified session
    setupUserSession($user, 'email');
    
    // ============================================
    // REMEMBER ME HANDLING
    // Security: Creates persistent token if user checked "Remember Me"
    // Token is stored hashed in DB, cookie is httpOnly and secure
    // ============================================
    if ($rememberMe) {
        $tokenValue = createRememberMeToken($user['id']);
        if ($tokenValue) {
            setRememberMeCookie($tokenValue);
        }
    }
    
    // Resolve which practice (if any) to auto-select, or whether the user
    // needs to be sent to the existing practice chooser. Single source of
    // truth shared with the Google OAuth login paths - see user-manager.php.
    $practiceSelection = resolveLoginPracticeSelection($user['id']);
    
    // Set cookie to remember login preference (email/password)
    setcookie('login_preference', 'email', [
        'expires' => time() + (365 * 24 * 60 * 60), // 1 year
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => false, // Needs to be readable by JavaScript
        'samesite' => 'Lax'
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => t('auth.errors.login_success'),
        'redirect' => $practiceSelection['redirect']
    ]);
}
