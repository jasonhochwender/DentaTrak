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
        echo json_encode(['success' => false, 'message' => t('auth.errors.invalid_action')]);
}

function handleRequest($email) {
    global $appConfig;
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.invalid_email')]);
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
            'message' => t('auth.errors.request_password_setup_email_unknown'),
            'immediate_setup' => false
        ]);
        return;
    }
    
    // If user is currently authenticated with Google, allow immediate setup
    if ($isGoogleAuthenticated) {
        echo json_encode([
            'success' => true,
            'message' => t('auth.errors.request_password_setup_verified'),
            'immediate_setup' => true,
            'token' => $result['token']
        ]);
        return;
    }
    
    // Otherwise, send verification email
    $token = $result['token'];
    $firstName = $result['first_name'] ?? '';
    $setupUserId = $result['user_id'] ?? null;

    // Build the setup URL
    $baseUrl = rtrim(($appConfig['baseUrl'] ?? ''), '/');
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $setupUrl = $baseUrl ? $baseUrl . '/set-password.php?token=' . urlencode($token) : $protocol . '://' . $host . '/set-password.php?token=' . urlencode($token);

    // Send email (using the app's email sending mechanism)
    $emailSent = sendPasswordSetupEmail($email, $firstName, $setupUrl, $setupUserId);
    
    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'message' => t('auth.errors.request_password_setup_link_sent'),
            'immediate_setup' => false
        ]);
    } else {
        // Still return success to prevent enumeration, but log the error
        error_log("[request-password-setup] Failed to send email to: $email");
        echo json_encode([
            'success' => true,
            'message' => t('auth.errors.request_password_setup_email_unknown'),
            'immediate_setup' => false
        ]);
    }
}

function handleValidate($token) {
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.token_required')]);
        return;
    }
    
    $result = validatePasswordSetupToken($token);
    echo json_encode($result);
}

function handleComplete($token, $password) {
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.token_required')]);
        return;
    }
    
    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('auth.errors.password_required')]);
        return;
    }
    
    // Validate password strength
    $passwordErrors = validatePasswordStrength($password);
    if (!empty($passwordErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => t('auth.errors.password_requirements_not_met'),
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

function sendPasswordSetupEmail($email, $firstName, $setupUrl, $userId = null) {
    global $appConfig;

    $locale = resolveEmailLocale($userId, null, null);
    $appName = $appConfig['appName'] ?? 'DentaTrak';
    $safeFirstName = $firstName ? htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') : '';
    $greetingHtml = $safeFirstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => $safeFirstName])
        : tForLocale($locale, 'email.common.greeting_generic');
    $greetingText = $firstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => $firstName])
        : tForLocale($locale, 'email.common.greeting_generic');

    $subject = tForLocale($locale, 'email.password_setup.subject', ['appName' => $appName]);
    $heading = tForLocale($locale, 'email.password_setup.heading', ['appName' => $appName]);
    $intro = tForLocale($locale, 'email.password_setup.intro', ['appName' => $appName]);
    $cta = tForLocale($locale, 'email.password_setup.cta');
    $copyLink = tForLocale($locale, 'email.common.copy_link');
    $expiryMinutes = 60;
    $expiry = tForLocale($locale, 'email.password_setup.expiry', ['count' => $expiryMinutes]);
    $ignore = tForLocale($locale, 'email.common.ignore_unsolicited');
    $footer = tForLocale($locale, 'email.common.footer', ['appName' => $appName]);

    $htmlBody = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #2563eb;'>{$heading}</h2>
            <p>{$greetingHtml}</p>
            <p>{$intro}</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$setupUrl}' style='background-color: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>{$cta}</a>
            </p>
            <p>{$copyLink}</p>
            <p style='word-break: break-all; color: #666;'>{$setupUrl}</p>
            <p><strong>{$expiry}</strong></p>
            <p>{$ignore}</p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
            <p style='color: #666; font-size: 12px;'>{$footer}</p>
        </div>
    </body>
    </html>
    ";

    $textBody = "{$greetingText}\n\n" .
        "{$intro}\n\n" .
        "{$cta}:\n{$setupUrl}\n\n" .
        "{$copyLink}\n{$setupUrl}\n\n" .
        "{$expiry}\n\n" .
        "{$ignore}\n\n" .
        "{$footer}";

    $result = sendAppEmail($email, $subject, $htmlBody, $textBody);
    return $result['success'] ?? false;
}
