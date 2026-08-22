<?php
/**
 * Locale Diagnostics API Endpoint
 *
 * Returns stored and resolved locale values for development and support.
 * Available only to super users when SHOW_DEV_TOOLS is enabled.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/dev-tools-access.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

header('Content-Type: application/json');

$userId = $_SESSION['db_user_id'] ?? null;
$userEmail = $_SESSION['user_email'] ?? null;

if (!canAccessDevTools($appConfig, $userEmail)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$practiceId = $_SESSION['current_practice_id'] ?? null;
$storedUserLocale = null;
$storedPracticeDefaultLocale = 'en-US';

if ($userId && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT locale FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $storedUserLocale = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[locale-diagnostics] Error reading user locale: ' . $e->getMessage());
    }
}

if ($practiceId && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT default_locale FROM practices WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        $storedPracticeDefaultLocale = $stmt->fetchColumn() ?: 'en-US';
    } catch (PDOException $e) {
        error_log('[locale-diagnostics] Error reading practice default locale: ' . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'storedUserLocale' => $storedUserLocale,
    'storedPracticeDefaultLocale' => $storedPracticeDefaultLocale,
    'resolvedLocale' => getResolvedLocale(),
    'fallbackLocale' => getFallbackLocale(),
    'supportedLocales' => getSupportedLocales(),
]);
