<?php
/**
 * Switch Practice API Endpoint
 * 
 * Switches the user's active practice context.
 * Updates session and stores as preferred practice for future logins.
 * Ensures clean context switch with no data leakage between practices.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/practice-security.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Not authenticated'
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];

// Get practice ID from request
$input = json_decode(file_get_contents('php://input'), true);
$newPracticeId = isset($input['practice_id']) ? (int)$input['practice_id'] : 0;

if (!$newPracticeId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Practice ID is required'
    ]);
    exit;
}

try {
    // Verify user has access to the requested practice
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.baa_accepted, pu.role, pu.is_owner,
               pu.limited_visibility, pu.can_view_analytics, pu.can_edit_cases
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE p.id = :practice_id AND pu.user_id = :user_id
    ");
    $stmt->execute([
        'practice_id' => $newPracticeId,
        'user_id' => $userId
    ]);
    $practice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$practice) {
        logSecurityEvent('practice_switch_denied', [
            'attempted_practice_id' => $newPracticeId,
            'reason' => 'no_access'
        ]);
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'You do not have access to this practice'
        ]);
        exit;
    }
    
    // Clear any practice-specific session data from previous practice
    // This prevents data leakage between practices
    $oldPracticeId = $_SESSION['current_practice_id'] ?? null;
    
    // Clear cached data that might be practice-specific
    unset($_SESSION['cases_cache']);
    unset($_SESSION['practice_users_cache']);
    unset($_SESSION['practice_settings_cache']);
    
    // Update session with new practice context
    $_SESSION['current_practice_id'] = $newPracticeId;
    $_SESSION['practice_name'] = $practice['practice_name'];
    $_SESSION['practice_role'] = $practice['role'];
    $_SESSION['practice_is_owner'] = (bool)$practice['is_owner'];
    $_SESSION['practice_permissions'] = [
        'limited_visibility' => (bool)$practice['limited_visibility'],
        'can_view_analytics' => (bool)$practice['can_view_analytics'],
        'can_edit_cases' => (bool)$practice['can_edit_cases']
    ];
    
    // Clear practice selection flags
    $_SESSION['needs_practice_setup'] = false;
    $_SESSION['needs_practice_selection'] = false;
    
    // Update preferred_practice_id in user_preferences for future logins
    try {
        ensureUserPreferencesSchema();
        $stmt = $pdo->prepare("
            INSERT INTO user_preferences (user_id, preferred_practice_id) 
            VALUES (:user_id, :practice_id)
            ON DUPLICATE KEY UPDATE preferred_practice_id = VALUES(preferred_practice_id)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $newPracticeId
        ]);
    } catch (PDOException $e) {
        // Non-fatal - log but continue
        error_log("Error updating preferred practice: " . $e->getMessage());
    }
    
    // Log the practice switch for audit
    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'switch_practice', 
            "Switched from practice {$oldPracticeId} to {$newPracticeId} ({$practice['practice_name']})");
    }
    
    logSecurityEvent('practice_switch', [
        'from_practice_id' => $oldPracticeId,
        'to_practice_id' => $newPracticeId
    ]);
    
    echo json_encode([
        'success' => true,
        'practice' => [
            'id' => (int)$practice['id'],
            'name' => $practice['practice_name'],
            'role' => $practice['role'],
            'is_owner' => (bool)$practice['is_owner'],
            'baa_accepted' => (bool)$practice['baa_accepted']
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Error switching practice: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to switch practice'
    ]);
}
