<?php
/**
 * Accept Updated Terms API Endpoint
 *
 * Records owner/administrator acceptance of the current Terms of Service and
 * Privacy Policy. Does not create, modify, or imply a BAA acceptance.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/schema-helpers.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('terms.errors.unauthenticated') ?: 'Authentication required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('terms.errors.method_not_allowed') ?: 'Method not allowed.']);
    exit;
}

requireCsrfToken();

$userId = (int)$_SESSION['db_user_id'];

// Only owners and administrators are required to accept updated Terms.
if (!isOwnerOrAdminOfAnyPractice($userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('terms.errors.admin_only') ?: 'Administrator or owner access required.']);
    exit;
}

$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

if (empty($data['accepted']) || $data['accepted'] !== true) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('terms.errors.acceptance_required') ?: 'Acceptance is required.']);
    exit;
}

if (empty($data['terms_version']) || $data['terms_version'] !== currentTermsVersion()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('terms.errors.version_mismatch') ?: 'The submitted terms version is not current.']);
    exit;
}

// Verify that the legal/account-classification migration has been applied.
requireAccountClassificationSchema($pdo);

try {
    $stmt = $pdo->prepare("
        UPDATE users
        SET terms_accepted_version = :version,
            terms_accepted_at = NOW()
        WHERE id = :user_id
    ");
    $stmt->execute([
        'version' => currentTermsVersion(),
        'user_id' => $userId,
    ]);

    echo json_encode([
        'success' => true,
        'message' => t('terms.errors.accepted') ?: 'Terms acceptance recorded.',
        'terms_version' => currentTermsVersion(),
    ]);
} catch (PDOException $e) {
    error_log('[accept-terms] Error recording terms acceptance: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('terms.errors.database') ?: 'Unable to record terms acceptance.']);
}
