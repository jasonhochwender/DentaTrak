<?php
/**
 * Test Helpers API
 * 
 * SECURITY: This endpoint should ONLY be available in development/test environments.
 * It provides helper functions for automated testing.
 */

require_once __DIR__ . '/appConfig.php';

// SECURITY CHECK: Only allow in development environment
$environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if ($environment === 'production') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Test helpers not available in production']);
    exit;
}

// Also check for a test mode flag
$testMode = getenv('DENTATRAK_TEST_MODE') === 'true' || 
            ($appConfig['test_mode'] ?? false) === true ||
            $environment === 'development';

if (!$testMode && $environment !== 'development') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Test mode not enabled']);
    exit;
}

header('Content-Type: application/json');

// Get JSON input
$jsonInput = file_get_contents('php://input');
$input = json_decode($jsonInput, true);

$action = $input['action'] ?? '';

switch ($action) {
    case 'verify_email':
        handleVerifyEmail($pdo, $input);
        break;
    case 'setup_test_user':
        handleSetupTestUser($pdo, $input);
        break;
    case 'cleanup_test_user':
        handleCleanupTestUser($pdo, $input);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

/**
 * Verify email for a test user (bypasses email verification)
 */
function handleVerifyEmail($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE email = :email");
        $stmt->execute(['email' => $email]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Email verified']);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

/**
 * Set up a complete test user with practice and BAA
 * This is a one-stop setup for E2E testing
 */
function handleSetupTestUser($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $firstName = trim($input['firstName'] ?? 'E2E');
    $lastName = trim($input['lastName'] ?? 'Test');
    $practiceName = trim($input['practiceName'] ?? 'E2E Test Practice');
    
    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and password are required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Check if user already exists
        $stmt = $pdo->prepare("SELECT id, email_verified FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $userId = null;
        
        if ($existingUser) {
            $userId = $existingUser['id'];
            
            // Ensure email is verified and reset created_at to prevent trial expiration
            $stmt = $pdo->prepare("UPDATE users SET email_verified = 1, created_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $userId]);
        } else {
            // Create new user
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    email, password_hash, auth_method, first_name, last_name,
                    role, is_active, email_verified, created_at
                ) VALUES (
                    :email, :password_hash, 'email', :first_name, :last_name,
                    'admin', 1, 1, NOW()
                )
            ");
            $stmt->execute([
                'email' => $email,
                'password_hash' => $passwordHash,
                'first_name' => $firstName,
                'last_name' => $lastName
            ]);
            
            $userId = $pdo->lastInsertId();
            
            // Create default preferences
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO user_preferences (user_id, theme, allow_card_delete, highlight_past_due, past_due_days, tour_completed)
                    VALUES (:user_id, 'light', TRUE, TRUE, 7, TRUE)
                ");
                $stmt->execute(['user_id' => $userId]);
            } catch (PDOException $e) {
                // Preferences table might not exist or have different schema
            }
        }
        
        // Check if user has a practice
        $stmt = $pdo->prepare("
            SELECT p.id, p.baa_accepted 
            FROM practices p
            JOIN practice_users pu ON p.id = pu.practice_id
            WHERE pu.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $existingPractice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $practiceId = null;
        
        if ($existingPractice) {
            $practiceId = $existingPractice['id'];
            
            // Ensure BAA is accepted
            if (!$existingPractice['baa_accepted']) {
                $stmt = $pdo->prepare("
                    UPDATE practices SET 
                        baa_accepted = 1,
                        baa_accepted_at = NOW(),
                        baa_version = 'v1.0-test',
                        baa_accepted_by_user_id = :user_id,
                        baa_signer_name = :signer_name,
                        baa_signer_title = 'Test Admin'
                    WHERE id = :practice_id
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'signer_name' => $firstName . ' ' . $lastName,
                    'practice_id' => $practiceId
                ]);
            }
        } else {
            // Create practice with BAA
            $practiceUuid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            
            $stmt = $pdo->prepare("
                INSERT INTO practices (
                    practice_id, practice_name, legal_name, display_name, practice_address,
                    baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id,
                    baa_signer_name, baa_signer_title, created_by
                ) VALUES (
                    :practice_uuid, :practice_name, :legal_name, :display_name, :practice_address,
                    1, NOW(), 'v1.0-test', :user_id,
                    :signer_name, 'Test Admin', :created_by
                )
            ");
            
            $stmt->execute([
                'practice_uuid' => $practiceUuid,
                'practice_name' => $practiceName,
                'legal_name' => $practiceName,
                'display_name' => $practiceName,
                'practice_address' => '123 Test Street, Test City, TS 12345',
                'user_id' => $userId,
                'signer_name' => $firstName . ' ' . $lastName,
                'created_by' => $userId
            ]);
            
            $practiceId = $pdo->lastInsertId();
            
            // Add user to practice as admin/owner
            $stmt = $pdo->prepare("
                INSERT INTO practice_users (practice_id, user_id, role, is_owner)
                VALUES (:practice_id, :user_id, 'admin', TRUE)
            ");
            $stmt->execute([
                'practice_id' => $practiceId,
                'user_id' => $userId
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Test user setup complete',
            'user_id' => $userId,
            'practice_id' => $practiceId,
            'email' => $email
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[test-helpers] Setup error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Setup failed: ' . $e->getMessage()]);
    }
}

/**
 * Clean up test user data (for test isolation)
 */
function handleCleanupTestUser($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        return;
    }
    
    // Safety check - only allow cleanup of test emails
    if (!str_contains($email, 'test') && !str_contains($email, 'e2e')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Can only cleanup test accounts']);
        return;
    }
    
    try {
        // Get user ID
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => true, 'message' => 'User not found (already clean)']);
            return;
        }
        
        $userId = $user['id'];
        
        // Delete user's cases (cascade should handle related data)
        $stmt = $pdo->prepare("
            DELETE c FROM cases c
            JOIN practices p ON c.practice_id = p.id
            JOIN practice_users pu ON p.id = pu.practice_id
            WHERE pu.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        
        // Note: We don't delete the user or practice - just clean up test data
        // This allows the test user to persist between test runs
        
        echo json_encode(['success' => true, 'message' => 'Test data cleaned up']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cleanup failed']);
    }
}
