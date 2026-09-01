<?php
/**
 * Switch Practice API Endpoint
 * 
 * Switches the user's active practice context for the current session only.
 * This is a temporary, session-scoped switch - it does NOT change the
 * user's default login practice. The only way to change the stored
 * preferred_practice_id (and therefore what practice a user lands in on
 * their next login) is the explicit "Always use this practice" checkbox
 * in the practice chooser (see practice-setup.php / select-practice.php).
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
        'error' => t('auth.errors.not_authenticated')
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
        'error' => t('auth.errors.practice_id_required')
    ]);
    exit;
}

try {
    // Verify user has access to the requested practice
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.baa_accepted, pu.role, pu.is_owner,
               IFNULL(pu.limited_visibility, 0) AS limited_visibility,
               IFNULL(pu.can_view_analytics, 1) AS can_view_analytics,
               IFNULL(pu.can_edit_cases, 1) AS can_edit_cases
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
            'error' => t('auth.errors.no_access_practice')
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

    // Resolve and persist the active locale for the new practice context
    setResolvedLocale(resolveLocale(null, $userId, $newPracticeId));
    
    // Clear practice selection flags
    $_SESSION['needs_practice_setup'] = false;
    $_SESSION['needs_practice_selection'] = false;
    
    // NOTE: This switch is intentionally session-only and does NOT update
    // user_preferences.preferred_practice_id. The stored default login
    // practice should only change via an explicit, opt-in action (the
    // "Always use this practice" checkbox in the practice chooser -
    // see select-practice.php), not as a side effect of a temporary
    // context switch from the header switcher.
    
    // Log the practice switch for audit
    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'switch_practice', 
            "Switched from practice {$oldPracticeId} to {$newPracticeId} ({$practice['practice_name']})");
    }
    
    logSecurityEvent('practice_switch', [
        'from_practice_id' => $oldPracticeId,
        'to_practice_id' => $newPracticeId
    ]);

    // Commit the new practice context immediately so any subsequent request
    // on the same session cannot observe stale state while this process is
    // still winding down (defensive against fast-following API/page loads).
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

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
        'error' => t('auth.errors.failed_switch_practice')
    ]);
}
