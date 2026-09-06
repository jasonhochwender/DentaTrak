<?php
/**
 * Switch Practice API Endpoint
 *
 * Switches the user's active practice context for the current session only.
 * This is a temporary, session-scoped switch - it does NOT change the
 * user's default login practice. The only way to change the stored
 * preferred_practice_id is the explicit "Automatically open the practice
 * I select next time" checkbox in the practice chooser (see
 * practice-setup.php / select-practice.php).
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';

// All practice switching must be state-changing POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'message' => 'Practice switching requires a POST request.'
    ]);
    exit;
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => t('auth.errors.not_authenticated')
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];

// Require a valid CSRF token before any state change.
requireCsrfToken();

// Parse and strictly validate practice_id.
$rawPracticeId = null;
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (isset($_POST['practice_id']) && $_POST['practice_id'] !== '') {
    $rawPracticeId = $_POST['practice_id'];
} elseif (isset($input['practice_id']) && $input['practice_id'] !== '') {
    $rawPracticeId = $input['practice_id'];
}

if ($rawPracticeId === null ||
    (!is_int($rawPracticeId) && !is_string($rawPracticeId)) ||
    !preg_match('/^[1-9][0-9]*$/D', (string) $rawPracticeId) ||
    filter_var($rawPracticeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => t('auth.errors.practice_id_required')
    ]);
    exit;
}

$practiceId = (int) $rawPracticeId;
$oldPracticeId = $_SESSION['current_practice_id'] ?? null;

// Centralized activation validates active user, active practice, and membership.
$result = activatePracticeSession($practiceId);

if (!$result['success']) {
    logSecurityEvent('practice_switch_denied', [
        'attempted_practice_id' => $practiceId,
        'reason' => $result['code'] === 403 ? 'no_access' : 'activation_error',
        'message' => $result['message']
    ]);
    http_response_code($result['code']);
    echo json_encode([
        'success' => false,
        'message' => $result['message']
    ]);
    exit;
}

$practice = $result['practice'];

// NOTE: This switch is intentionally session-only and does NOT update
// user_preferences.preferred_practice_id. The stored default login practice
// should only change via an explicit, opt-in action in the practice chooser.

// Log the practice switch for audit.
if (function_exists('logUserActivity')) {
    logUserActivity($userId, 'switch_practice',
        "Switched from practice {$oldPracticeId} to {$practiceId} ({$practice['practice_name']})");
}

logSecurityEvent('practice_switch', [
    'from_practice_id' => $oldPracticeId,
    'to_practice_id' => $practiceId
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
        'id' => (int) $practice['id'],
        'name' => $practice['practice_name'],
        'role' => $practice['role'],
        'is_owner' => (bool) $practice['is_owner'],
        'baa_accepted' => (bool) $practice['baa_accepted']
    ]
]);
