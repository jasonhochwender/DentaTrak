<?php
/**
 * Password Reset API
 * Handles password reset requests and token validation
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/email-sender.php';
require_once __DIR__ . '/unified-identity.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Get JSON input
$jsonInput = file_get_contents('php://input');
$input = json_decode($jsonInput, true);

$action = $input['action'] ?? $_GET['action'] ?? '';

// Ensure password_reset_tokens table exists (auto-migration)
function ensureResetTokensTable($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                used BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_token (token),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB
        ");
    } catch (PDOException $e) {
        // Table might already exist
    }
}

// Password validation rules (same as auth-email.php)
function validatePassword($password) {
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

// Run migration
ensureResetTokensTable($pdo);

switch ($action) {
    case 'request':
        handleResetRequest($pdo, $input);
        break;
    case 'validate':
        handleValidateToken($pdo, $input);
        break;
    case 'reset':
        handlePasswordReset($pdo, $input);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function handleResetRequest($pdo, $input) {
    global $appConfig;
    
    $email = trim($input['email'] ?? '');
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        return;
    }
    
    try {
        // Check if user exists and can use email auth
        $stmt = $pdo->prepare("
            SELECT id, auth_method, first_name 
            FROM users 
            WHERE email = :email AND is_active = 1
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Always return success to prevent email enumeration
        // But only actually send email if user exists and uses email auth
        if ($user && ($user['auth_method'] === 'email' || $user['auth_method'] === 'both')) {
            // Invalidate any existing tokens for this user
            $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE user_id = :user_id AND used = 0");
            $stmt->execute(['user_id' => $user['id']]);
            
            // Generate secure token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token
            $stmt = $pdo->prepare("
                INSERT INTO password_reset_tokens (user_id, token, expires_at)
                VALUES (:user_id, :token, :expires_at)
            ");
            $stmt->execute([
                'user_id' => $user['id'],
                'token' => $token,
                'expires_at' => $expiresAt
            ]);
            
            // Build reset URL
            $baseUrl = rtrim(($appConfig['baseUrl'] ?? ''), '/');
            if ($baseUrl) {
                $resetUrl = $baseUrl . '/reset-password.php?token=' . $token;
            } else {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetUrl = "{$protocol}://{$host}/reset-password.php?token={$token}";
            }
            
            // Send email
            $appName = $appConfig['appName'] ?? 'App';
            $firstName = $user['first_name'] ?: 'User';
            
            $subject = "Password Reset Request - {$appName}";
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
                        <h2>Password Reset Request</h2>
                        <p>Hi {$firstName},</p>
                        <p>We received a request to reset your password for your {$appName} account.</p>
                        <p>Click the button below to reset your password:</p>
                        <p><a href='{$resetUrl}' class='button' style='display: inline-block; padding: 12px 24px; background-color: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold;'>Reset Password</a></p>
                        <p>Or copy and paste this link into your browser:</p>
                        <p style='word-break: break-all;'>{$resetUrl}</p>
                        <p>This link will expire in 1 hour.</p>
                        <p>If you didn't request this password reset, you can safely ignore this email.</p>
                        <div class='footer'>
                            <p>This is an automated message from {$appName}. Please do not reply to this email.</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            sendAppEmail($email, $subject, $message);
        }
        
        // Always return success to prevent email enumeration
        echo json_encode([
            'success' => true,
            'message' => 'If an account exists with this email, you will receive password reset instructions shortly.'
        ]);
        
    } catch (PDOException $e) {
        error_log('Password reset request error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
}

function handleValidateToken($pdo, $input) {
    $token = $input['token'] ?? $_GET['token'] ?? '';
    
    if (empty($token)) {
        echo json_encode(['success' => false, 'valid' => false, 'message' => 'Token is required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT prt.id, prt.user_id, prt.expires_at, prt.used, u.email
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = :token
        ");
        $stmt->execute(['token' => $token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            echo json_encode(['success' => true, 'valid' => false, 'message' => 'Invalid or expired reset link']);
            return;
        }
        
        if ($tokenData['used']) {
            echo json_encode(['success' => true, 'valid' => false, 'message' => 'This reset link has already been used']);
            return;
        }
        
        if (strtotime($tokenData['expires_at']) < time()) {
            echo json_encode(['success' => true, 'valid' => false, 'message' => 'This reset link has expired']);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'valid' => true,
            'email' => $tokenData['email']
        ]);
        
    } catch (PDOException $e) {
        error_log('Token validation error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'valid' => false, 'message' => 'An error occurred']);
    }
}

function handlePasswordReset($pdo, $input) {
    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';
    
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token is required']);
        return;
    }
    
    if ($password !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        return;
    }
    
    // Validate password strength
    $passwordErrors = validatePassword($password);
    if (!empty($passwordErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Password does not meet requirements',
            'errors' => $passwordErrors
        ]);
        return;
    }
    
    try {
        // Validate token
        $stmt = $pdo->prepare("
            SELECT prt.id, prt.user_id, prt.expires_at, prt.used
            FROM password_reset_tokens prt
            WHERE prt.token = :token
        ");
        $stmt->execute(['token' => $token]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid reset link']);
            return;
        }
        
        if ($tokenData['used']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This reset link has already been used']);
            return;
        }
        
        if (strtotime($tokenData['expires_at']) < time()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This reset link has expired']);
            return;
        }
        
        // Update password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_hash = :password_hash,
                auth_method = CASE 
                    WHEN auth_method = 'google' THEN 'both'
                    ELSE auth_method
                END
            WHERE id = :user_id
        ");
        $stmt->execute([
            'password_hash' => $passwordHash,
            'user_id' => $tokenData['user_id']
        ]);
        
        // Also update the user_auth_methods table
        addAuthMethod($tokenData['user_id'], 'email', null, $passwordHash);
        
        // ============================================
        // SECURITY: Invalidate all remember me tokens on password change
        // This ensures any stolen tokens become useless after password reset
        // ============================================
        revokeAllRememberMeTokens($tokenData['user_id']);
        
        // Mark token as used
        $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = :id");
        $stmt->execute(['id' => $tokenData['id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Password reset successfully. You can now sign in with your new password.'
        ]);
        
    } catch (PDOException $e) {
        error_log('Password reset error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
}
