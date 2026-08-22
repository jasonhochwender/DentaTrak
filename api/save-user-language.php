<?php
/**
 * Save User Language API Endpoint
 *
 * Allows the current user to update their own language preference without
 * requiring practice admin rights. This is separated from save-settings.php
 * because that endpoint is protected by the admin-only gate.
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

$userId = $_SESSION['db_user_id'];
$currentPracticeId = $_SESSION['current_practice_id'] ?? null;

$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

if (!$data || !array_key_exists('language', $data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('api.settings.invalid_data')]);
    exit;
}

$languageInput = $data['language'];

// `null` or the sentinel value "use_practice_default" means the user follows the practice default
if ($languageInput === null || $languageInput === 'use_practice_default') {
    $userLocale = null;
} else {
    $userLocale = validateLocale($languageInput, null);
    if ($userLocale === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t('settings.language.invalid')]);
        exit;
    }
}

try {
    ensureLocaleColumns();

    $stmt = $pdo->prepare("UPDATE users SET locale = :locale WHERE id = :user_id");
    $stmt->execute([
        'locale' => $userLocale,
        'user_id' => $userId
    ]);

    // user_preferences.locale is retained for backward compatibility but is no longer authoritative.

    // Re-resolve and persist the active locale immediately
    $resolved = resolveLocale(null, $userId, $currentPracticeId);
    setResolvedLocale($resolved);

    echo json_encode([
        'success' => true,
        'message' => t('settings.language.saved'),
        'language' => $resolved
    ]);
} catch (PDOException $e) {
    error_log('[save-user-language] Error saving user language: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('settings.language.error')]);
}
