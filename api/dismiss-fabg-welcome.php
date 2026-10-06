<?php
/**
 * Dismiss FABG Pilot Welcome API Endpoint
 *
 * Permanently marks the FABG pilot welcome message as shown for the
 * current practice (practices.fabg_welcome_shown = 1). Any authenticated
 * member of the practice may dismiss it; the flag is practice-level so it
 * is not shown to other members again.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('auth.errors.not_authenticated')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('auth.errors.method_not_allowed')]);
    exit;
}

requireCsrfToken();

$currentPracticeId = $_SESSION['current_practice_id'] ?? null;
if (!$currentPracticeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('api.settings.invalid_data')]);
    exit;
}

try {
    $fabgColumnCheck = $pdo->query("SHOW COLUMNS FROM practices LIKE 'fabg_welcome_shown'");
    if ($fabgColumnCheck && $fabgColumnCheck->rowCount() > 0) {
        $stmt = $pdo->prepare("UPDATE practices SET fabg_welcome_shown = 1 WHERE id = :id");
        $stmt->execute(['id' => (int)$currentPracticeId]);
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('[dismiss-fabg-welcome] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to dismiss welcome message']);
}
