<?php
/**
 * Email Verification API
 * Handles sending and verifying email verification tokens for new registrations
 * 
 * This file can be:
 * 1. Included by other files to use sendVerificationEmail() function
 * 2. Called directly as an API endpoint for verify/resend actions
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/unified-identity.php';
require_once __DIR__ . '/email-sender.php';

// Ensure email_verification_tokens table exists (auto-migration)
function ensureVerificationTokensTable($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_verification_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                used BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_token (token),
                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at),
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table might already exist
    }
}

// Run migration
ensureVerificationTokensTable($pdo);

// Only run API endpoint logic if this file is called directly (not included)
if (basename($_SERVER['SCRIPT_FILENAME']) === 'email-verification.php') {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    header('Content-Type: application/json');

    // Get JSON input
    $jsonInput = file_get_contents('php://input');
    $input = json_decode($jsonInput, true);

    $action = $input['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        case 'verify':
            handleVerifyEmail($pdo, $input);
            break;
        case 'resend':
            handleResendVerification($pdo, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    exit;
}

/**
 * Generate and send a verification email
 */
function sendVerificationEmail($pdo, $userId, $email, $firstName = '') {
    global $appConfig;
    
    try {
        // Invalidate any existing tokens for this user
        $stmt = $pdo->prepare("UPDATE email_verification_tokens SET used = 1 WHERE user_id = :user_id AND used = 0");
        $stmt->execute(['user_id' => $userId]);
        
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours')); // 24 hour expiry for verification
        
        // Store token
        $stmt = $pdo->prepare("
            INSERT INTO email_verification_tokens (user_id, token, expires_at)
            VALUES (:user_id, :token, :expires_at)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt
        ]);
        
        // Build verification URL
        $baseUrl = rtrim(($appConfig['baseUrl'] ?? ''), '/');
        if ($baseUrl) {
            $verifyUrl = $baseUrl . '/verify-email.php?token=' . $token;
        } else {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $verifyUrl = "{$protocol}://{$host}/verify-email.php?token={$token}";
        }
        
        // Send email
        $appName = $appConfig['appName'] ?? 'App';
        $displayName = $firstName ?: 'there';
        
        $subject = "Verify your email - {$appName}";
        $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .button { display: inline-block; padding: 12px 24px; background-color: #3b82f6; color: #ffffff !important; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: bold; }
                    .footer { margin-top: 30px; font-size: 12px; color: #666; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2>Verify your email address</h2>
                    <p>Hi {$displayName},</p>
                    <p>Thanks for signing up for {$appName}! Please verify your email address to complete your registration.</p>
                    <p>Click the button below to verify your email:</p>
                    <p><a href='{$verifyUrl}' class='button' style='display: inline-block; padding: 12px 24px; background-color: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold;'>Verify Email</a></p>
                    <p>Or copy and paste this link into your browser:</p>
                    <p style='word-break: break-all;'>{$verifyUrl}</p>
                    <p>This link will expire in 24 hours.</p>
                    <p>If you didn't create an account, you can safely ignore this email.</p>
                    <div class='footer'>
                        <p>This is an automated message from {$appName}. Please do not reply to this email.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        $sendResult = sendAppEmail($email, $subject, $message, null, null);
        
        return array_merge([
            'success' => true,
            'token' => $token
        ], $sendResult);
        
    } catch (PDOException $e) {
        error_log('Error sending verification email: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to send verification email'
        ];
    }
}

/**
 * Handle email verification
 */
function handleVerifyEmail($pdo, $input) {
    $token = $input['token'] ?? $_GET['token'] ?? '';
    
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Verification token is required']);
        return;
    }
    
    try {
        // Find the token
        $stmt = $pdo->prepare("
            SELECT evt.id, evt.user_id, evt.expires_at, evt.used, u.email, u.first_name
            FROM email_verification_tokens evt
            JOIN users u ON evt.user_id = u.id
            WHERE evt.token = :token
        ");
        $stmt->execute(['token' => $token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            echo json_encode(['success' => false, 'message' => 'Invalid verification link']);
            return;
        }
        
        if ($tokenData['used']) {
            echo json_encode(['success' => false, 'message' => 'This verification link has already been used', 'already_verified' => true]);
            return;
        }
        
        if (strtotime($tokenData['expires_at']) < time()) {
            echo json_encode(['success' => false, 'message' => 'This verification link has expired. Please request a new one.', 'expired' => true]);
            return;
        }
        
        // Mark user as verified
        $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = :user_id");
        $stmt->execute(['user_id' => $tokenData['user_id']]);
        
        // Mark token as used
        $stmt = $pdo->prepare("UPDATE email_verification_tokens SET used = 1 WHERE id = :id");
        $stmt->execute(['id' => $tokenData['id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Email verified successfully! You can now sign in.',
            'email' => $tokenData['email']
        ]);
        
    } catch (PDOException $e) {
        error_log('Email verification error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
}

/**
 * Handle resend verification email
 */
function handleResendVerification($pdo, $input) {
    $email = trim($input['email'] ?? '');
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        return;
    }
    
    try {
        // Find user by email
        $stmt = $pdo->prepare("
            SELECT id, first_name, email_verified, auth_method 
            FROM users 
            WHERE email = :email
        ");
        $stmt->execute(['email' => strtolower($email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Always return success to prevent email enumeration
        if (!$user) {
            echo json_encode([
                'success' => true,
                'message' => 'If an account exists with this email, a verification link will be sent.'
            ]);
            return;
        }
        
        // Check if already verified
        if ($user['email_verified']) {
            echo json_encode([
                'success' => true,
                'message' => 'Your email is already verified. You can sign in.',
                'already_verified' => true
            ]);
            return;
        }
        
        // Check if user uses email auth
        if ($user['auth_method'] === 'google') {
            echo json_encode([
                'success' => true,
                'message' => 'This account uses Google Sign-In. No email verification needed.'
            ]);
            return;
        }
        
        // Send new verification email
        $result = sendVerificationEmail($pdo, $user['id'], $email, $user['first_name']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Verification email sent. Please check your inbox.'
        ]);
        
    } catch (PDOException $e) {
        error_log('Resend verification error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
}
