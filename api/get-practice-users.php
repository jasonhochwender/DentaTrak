<?php
/**
 * Get Practice Users API
 * Returns list of users in the current practice for @mention autocomplete
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/practice-security.php';

header('Content-Type: application/json');

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();

$caseId = $_GET['case_id'] ?? null;

// When a specific case is supplied, this request is part of the Comments
// mention flow. Scope it to the Comments feature and to users who can
// actually access that case.
if ($caseId !== null) {
    if (!isFeatureEnabled('SHOW_COMMENTS')) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found']);
        exit;
    }

    requireCaseAccess($caseId, $currentPracticeId);
    $users = getCaseAuthorizedUsers($currentPracticeId, $caseId);
} else {
    // Existing general-practice-users path (no case-scoped filtering).
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = :practice_id
            ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC
        ");
        $stmt->execute(['practice_id' => $currentPracticeId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[get-practice-users] Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching users']);
        exit;
    }
}

// Format for frontend
$formattedUsers = array_map(function($user) {
    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    return [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'name' => $fullName ?: $user['email']
    ];
}, $users);

echo json_encode([
    'success' => true,
    'users' => $formattedUsers
]);
