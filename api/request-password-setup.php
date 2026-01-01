<?php
/**
 * Request Password Setup API
 * 
 * For Google-only users who want to add password authentication.
 * Sends a verification email with a secure token link.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/email-sender.php';
require_once __DIR__ . '/unified-identity.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$jsonInput = file_get_contents('php://input');
$input = json_decode($jsonInput, true);

$action = $input['action'] ?? '';
$email = trim($input['email'] ?? '');

switch ($action) {
    case 'request':
        handleRequest($email);
        break;
    case 'validate':
        handleValidate($input['token'] ?? '');
        break;
    case 'complete':
        handleComplete($input['token'] ?? '', $input['password'] ?? '');
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function handleRequest($email) {
    global $appConfig;
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        return;
    }
    
    // Check if user is currently authenticated with Google for this email
    // This provides immediate verification without email
    $isGoogleAuthenticated = isAuthenticatedWithGoogle($email);
    
    // Generate token
    $result = generatePasswordSetupToken($email);
    
    if (!$result['success']) {
        // Don't reveal if user exists or not for security
        // Always return success to prevent email enumeration
        echo json_encode([
            'success' => true,
            'message' => 'If an account exists with this email, you will receive a password setup link.',
            'immediate_setup' => false
        ]);
        return;
    }
    
    // If user is currently authenticated with Google, allow immediate setup
    if ($isGoogleAuthenticated) {
        echo json_encode([
            'success' => true,
            'message' => 'You are verified via Google. You can set your password now.',
            'immediate_setup' => true,
            'token' => $result['token']
        ]);
        return;
    }
    
    // Otherwise, send verification email
    $token = $result['token'];
    $firstName = $result['first_name'] ?? '';
    
    // Build the setup URL
    $baseUrl = rtrim(($appConfig['baseUrl'] ?? ''), '/');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $setupUrl = $baseUrl ? $baseUrl . '/set-password.php?token=' . urlencode($token) : $protocol . '://' . $host . '/set-password.php?token=' . urlencode($token);
    
    // Send email (using the app's email sending mechanism)
    $emailSent = sendPasswordSetupEmail($email, $firstName, $setupUrl);
    
    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'message' => 'A password setup link has been sent to your email.',
            'immediate_setup' => false
        ]);
    } else {
        // Still return success to prevent enumeration, but log the error
        error_log("[request-password-setup] Failed to send email to: $email");
        echo json_encode([
            'success' => true,
            'message' => 'If an account exists with this email, you will receive a password setup link.',
            'immediate_setup' => false
        ]);
    }
}

function handleValidate($token) {
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token is required']);
        return;
    }
    
    $result = validatePasswordSetupToken($token);
    echo json_encode($result);
}

function handleComplete($token, $password) {
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token is required']);
        return;
    }
    
    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        return;
    }
    
    // Validate password strength
    $passwordErrors = validatePasswordStrength($password);
    if (!empty($passwordErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Password does not meet requirements',
            'errors' => $passwordErrors
        ]);
        return;
    }
    
    $result = completePasswordSetup($token, $password);
    
    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }
}

function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
        $errors[] = 'Password must contain at least one special character';
    }
    
    return $errors;
}

function sendPasswordSetupEmail($email, $firstName, $setupUrl) {
    global $appConfig;
    
    $appName = $appConfig['appName'] ?? 'DentalFlow';
    $greeting = $firstName ? "Hi $firstName," : "Hello,";
    
    $subject = "Set up your password for $appName";
    
    $htmlBody = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #2563eb;'>$appName</h2>
            <p>$greeting</p>
            <p>You requested to set up a password for your account. Click the button below to create your password:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='$setupUrl' style='background-color: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>Set Password</a>
            </p>
            <p>Or copy and paste this link into your browser:</p>
            <p style='word-break: break-all; color: #666;'>$setupUrl</p>
            <p><strong>This link expires in 1 hour.</strong></p>
            <p>If you didn't request this, you can safely ignore this email.</p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
            <p style='color: #666; font-size: 12px;'>This email was sent by $appName. Please do not reply to this email.</p>
        </div>
    </body>
    </html>
    ";
    
    $textBody = "$greeting\n\n" .
        "You requested to set up a password for your $appName account.\n\n" .
        "Click this link to create your password:\n$setupUrl\n\n" .
        "This link expires in 1 hour.\n\n" .
        "If you didn't request this, you can safely ignore this email.";
    
    $result = sendAppEmail($email, $subject, $htmlBody, $textBody);
    return $result['success'] ?? false;
}
