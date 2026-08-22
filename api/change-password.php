<?php
/**
 * Change Password API Endpoint
 * 
 * Allows authenticated users to change their password.
 * Security: Validates current password before allowing change.
 * No password values are logged or exposed.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/unified-identity.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

// ============================================
// SECURITY: Require authenticated user
// ============================================
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('billing.errors.authentication_required')]);
    exit;
}

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('billing.errors.method_not_allowed')]);
    exit;
}

// Get JSON data from request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('auth.errors.invalid_request_data')]);
    exit;
}

$userId = $_SESSION['db_user_id'];
$currentPassword = $data['currentPassword'] ?? '';
$newPassword = $data['newPassword'] ?? '';
$confirmPassword = $data['confirmPassword'] ?? '';

// ============================================
// VALIDATION: Check required fields
// ============================================
if (empty($currentPassword)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('auth.errors.current_password_required'), 'field' => 'currentPassword']);
    exit;
}

if (empty($newPassword)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('auth.errors.new_password_required'), 'field' => 'newPassword']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('auth.errors.passwords_not_match'), 'field' => 'confirmPassword']);
    exit;
}

// ============================================
// VALIDATION: Password strength requirements
// Business Rule: Enforce consistent password strength rules
// ============================================
$passwordErrors = [];

if (strlen($newPassword) < 8) {
    $passwordErrors[] = t('auth.errors.password_min_length');
}

if (!preg_match('/[A-Z]/', $newPassword)) {
    $passwordErrors[] = t('auth.errors.password_upper');
}

if (!preg_match('/[0-9]/', $newPassword)) {
    $passwordErrors[] = t('auth.errors.password_number');
}

if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $newPassword)) {
    $passwordErrors[] = t('auth.errors.password_special');
}

if (!empty($passwordErrors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => implode('. ', $passwordErrors),
        'field' => 'newPassword'
    ]);
    exit;
}

try {
    global $pdo;
    
    // ============================================
    // SECURITY: Verify current password before allowing change
    // ============================================
    $stmt = $pdo->prepare("SELECT password_hash, email, auth_method FROM users WHERE id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('auth.errors.no_account')]);
        exit;
    }
    
    // Check if user has a password set (might be Google-only account)
    if (empty($user['password_hash'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => t('auth.errors.google_only_setup_password')
        ]);
        exit;
    }
    
    // Verify current password
    if (!password_verify($currentPassword, $user['password_hash'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => t('auth.errors.current_password_incorrect'),
            'field' => 'currentPassword'
        ]);
        exit;
    }
    
    // ============================================
    // SECURITY: Hash new password with bcrypt
    // Never store plaintext passwords
    // ============================================
    $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Update password in users table
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_hash = :password_hash,
                updated_at = NOW()
            WHERE id = :user_id
        ");
        $stmt->execute([
            'password_hash' => $newPasswordHash,
            'user_id' => $userId
        ]);
        
        // ============================================
        // SECURITY: Invalidate all Remember Me tokens for this user
        // This ensures any stolen tokens become invalid
        // ============================================
        if (function_exists('revokeAllRememberMeTokens')) {
            revokeAllRememberMeTokens($userId);
        }
        
        // ============================================
        // SECURITY: Invalidate other sessions (optional enhancement)
        // For now, we keep the current session active
        // ============================================
        // Note: Full session invalidation would require a session store
        // that tracks sessions by user ID. Current implementation
        // relies on Remember Me token revocation for security.
        
        $pdo->commit();
        
        // Log the password change (no sensitive data)
        if (function_exists('logUserActivity')) {
            logUserActivity($userId, 'password_changed', 'User changed their password');
        }
        
        echo json_encode([
            'success' => true,
            'message' => t('auth.errors.password_change_success')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('[change-password] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => t('auth.errors.password_change_error')
    ]);
}
