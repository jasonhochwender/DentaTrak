<?php
/**
 * Update Practice API Endpoint
 * Updates an existing dental practice's name.
 *
 * Practice CREATION no longer happens here. It used to (see the git history
 * for the old $practiceId-less branch), but practice creation is now
 * exclusively handled by api/accept-baa.php, which - unlike this endpoint -
 * atomically: (a) locks and gets-or-creates the subscription OWNER's single
 * `subscriptions` row (api/subscription-owner.php), (b) enforces that
 * owner's plan-based practice-count limit (api/plan-entitlements.php), and
 * (c) requires BAA acceptance. This endpoint had none of those guards, so a
 * request with no `practice_id` created a practice with no entitlement
 * check at all - a direct bypass of the Operate/Control/Scale limits - and
 * with practices.subscription_status/trial_ends_at (deprecated, unused
 * elsewhere) instead of the owner-level subscription. See
 * tests/practice/practice-limit-entitlement.spec.ts for the regression
 * coverage of the limit this endpoint used to be able to bypass.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'User not authenticated'
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];

// Get request data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['practice_name']) || empty($data['practice_name'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Practice name is required'
    ]);
    exit;
}

$practiceName = $data['practice_name'];
$practiceId = isset($data['practice_id']) ? $data['practice_id'] : null;

// Practice creation (no practice_id) is no longer supported here - see the
// file-level docblock. Reject rather than silently creating an
// unentitled, un-BAA'd practice.
if (!$practiceId) {
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'message' => 'Practice creation has moved. Please use the Business Associate Agreement flow (baa-acceptance.php) to create a practice.',
        'error_code' => 'MOVED_TO_BAA_FLOW',
    ]);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check if user has admin access to this practice
    $stmt = $pdo->prepare("
        SELECT practice_users.role
        FROM practice_users
        WHERE practice_id = :practice_id AND user_id = :user_id
    ");
    $stmt->execute([
        'practice_id' => $practiceId,
        'user_id' => $userId
    ]);
    $userRole = $stmt->fetchColumn();

    if ($userRole !== 'admin') {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to update this practice'
        ]);
        exit;
    }

    // Update existing practice
    $legalName = isset($data['legal_name']) ? $data['legal_name'] : null;

    if ($legalName) {
        $stmt = $pdo->prepare("
            UPDATE practices
            SET practice_name = :practice_name,
                legal_name = :legal_name,
                display_name = COALESCE(display_name, :display_name)
            WHERE id = :id
        ");
        $result = $stmt->execute([
            'practice_name' => $practiceName,
            'legal_name' => $legalName,
            'display_name' => $legalName,
            'id' => $practiceId
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE practices
            SET practice_name = :practice_name
            WHERE id = :id
        ");
        $result = $stmt->execute([
            'practice_name' => $practiceName,
            'id' => $practiceId
        ]);
    }

    // Commit transaction
    $pdo->commit();

    // Get the updated practice data
    $stmt = $pdo->prepare("
        SELECT id, practice_id as uuid, practice_name
        FROM practices
        WHERE id = :id
    ");
    $stmt->execute(['id' => $practiceId]);
    $practice = $stmt->fetch(PDO::FETCH_ASSOC);

    // Return the practice ID in the response so the frontend can verify
    echo json_encode([
        'success' => true,
        'message' => 'Practice updated successfully',
        'practice' => $practice,
        'current_practice_id' => $_SESSION['current_practice_id'] // Include for verification
    ]);

} catch (PDOException $e) {
    // Roll back transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating practice: ' . $e->getMessage()
    ]);

    userLog("Error updating practice: " . $e->getMessage(), true);
}
