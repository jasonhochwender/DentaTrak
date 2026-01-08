<?php
/**
 * Two-Factor Authentication Setup API
 * 
 * Handles 2FA setup flow:
 * - Generate new secret and QR code
 * - Verify setup code
 * - Enable/disable 2FA
 * 
 * Security: Secrets are never exposed after initial setup.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/totp.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

// ============================================
// SECURITY: Require authenticated user
// ============================================
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$userId = $_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';

// Handle different actions
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'status':
        // Get current 2FA status
        handle2FAStatus($userId);
        break;
        
    case 'setup':
        // Generate new secret and QR code
        handleSetup($userId, $userEmail);
        break;
        
    case 'verify':
        // Verify setup code and enable 2FA
        handleVerify($userId);
        break;
        
    case 'disable':
        // Disable 2FA
        handleDisable($userId);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
}

/**
 * Get current 2FA status
 */
function handle2FAStatus(int $userId): void {
    $status = get2FAStatus($userId);
    
    echo json_encode([
        'success' => true,
        'enabled' => $status['enabled'],
        'enabledAt' => $status['enabledAt']
    ]);
}

/**
 * Generate new secret and QR code for setup
 */
function handleSetup(int $userId, string $userEmail): void {
    // Validate CSRF for POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrfToken();
    }
    
    // Check if 2FA is already enabled
    $status = get2FAStatus($userId);
    if ($status['enabled']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '2FA is already enabled. Disable it first to set up again.'
        ]);
        return;
    }
    
    // Generate new secret
    $secret = TOTP::generateSecret();
    
    // Store pending secret (not enabled yet)
    if (!storePending2FASecret($userId, $secret)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to initialize 2FA setup. Please try again.'
        ]);
        return;
    }
    
    // Generate QR code URI
    $qrUri = TOTP::getQRCodeUri($secret, $userEmail);
    
    // Generate QR code HTML
    $qrCodeHtml = TOTP::generateQRCodeSVG($qrUri);
    
    // ============================================
    // SECURITY: Return secret only during initial setup
    // After verification, secret is never exposed again
    // ============================================
    echo json_encode([
        'success' => true,
        'secret' => $secret,
        'qrCode' => $qrCodeHtml,
        'message' => 'Scan the QR code with your authenticator app, then enter the verification code.'
    ]);
}

/**
 * Verify setup code and enable 2FA
 */
function handleVerify(int $userId): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    requireCsrfToken();
    
    // Get verification code from request
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    $code = $data['code'] ?? '';
    
    if (empty($code)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Verification code is required'
        ]);
        return;
    }
    
    // Get the pending secret
    $secret = get2FASecret($userId);
    
    if (!$secret) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No 2FA setup in progress. Please start setup again.'
        ]);
        return;
    }
    
    // Verify the code
    if (!TOTP::verifyCode($secret, $code)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid verification code. Please try again.'
        ]);
        return;
    }
    
    // ============================================
    // SECURITY: Only enable 2FA after successful verification
    // ============================================
    if (!enable2FA($userId)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to enable 2FA. Please try again.'
        ]);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Two-factor authentication has been enabled successfully.'
    ]);
}

/**
 * Disable 2FA
 * 
 * User is already authenticated via session, so no additional
 * verification is required. This works for both email/password
 * and Google OAuth users.
 */
function handleDisable(int $userId): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    requireCsrfToken();
    
    // Disable 2FA
    if (!disable2FA($userId)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to disable 2FA. Please try again.'
        ]);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Two-factor authentication has been disabled.'
    ]);
}
