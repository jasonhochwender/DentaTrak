<?php
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$env = $appConfig['environment'] ?? 'production';
if ($env !== 'development') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Not available outside the development environment.'
    ]);
    exit;
}

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'User not authenticated.'
    ]);
    exit;
}

$userId = (int)$_SESSION['db_user_id'];

// Clear legacy cookie (no longer used for preference, but keep for completeness)
$expiry = time() - 3600;
setcookie('preferred_practice_id', '', [
    'expires' => $expiry,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict'
]);

unset($_COOKIE['preferred_practice_id']);

// Also clear the stored preferred practice in user_preferences
try {
    ensureUserPreferencesSchema();
    if ($pdo) {
        $stmt = $pdo->prepare("UPDATE user_preferences SET preferred_practice_id = NULL WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
    }
} catch (PDOException $e) {
    userLog('Error clearing preferred_practice_id for user ' . $userId . ': ' . $e->getMessage(), true);
}

echo json_encode([
    'success' => true,
    'message' => 'Preferred practice selection cleared. Sign out and sign back in to re-test the login and practice selection flow.'
]);
