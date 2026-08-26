<?php
/**
 * Local-only, session-scoped enablement for SHOW_NOTIFICATIONS.
 *
 * This page is safe to keep in the repo because it:
 * - Requires an active, logged-in PHP session.
 * - Only runs when the environment is development/test/local or DENTATRAK_TEST_MODE is true.
 * - Sets a session flag, never a global default.
 * - Redirects to main.php after enabling.
 *
 * Usage (local only):
 * 1. Log in at http://localhost/DentaTrak/login.php
 * 2. Visit http://localhost/DentaTrak/dev-enable-notifications.php
 * 3. The notification bell and panel are now visible for that browser session.
 */

/**
 * Pre-environment guard: if we are running in a Cloud Run service, this URL
 * must not be reachable, regardless of any later server-side check. The
 * K_SERVICE environment variable is only present in Cloud Run production.
 */
if (getenv('K_SERVICE') !== false && getenv('K_SERVICE') !== '') {
    http_response_code(404);
    echo '<h1>Not Found</h1>';
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo '<h1>Please log in first</h1><a href="login.php">Log in</a>';
    return;
}

require_once __DIR__ . '/api/appConfig.php';

$currentEnv = $appConfig['current_environment'] ?? 'production';
$isTestMode = in_array($currentEnv, ['development', 'test', 'local'], true)
    || getenv('DENTATRAK_TEST_MODE') === 'true'
    || (getEnvVar('DENTATRAK_TEST_MODE') === 'true');

if (!$isTestMode) {
    http_response_code(403);
    echo '<h1>Not available in this environment</h1>';
    return;
}

$_SESSION['SHOW_NOTIFICATIONS'] = true;

$baseUrl = $appConfig['app_base_url'] ?? 'http://localhost/DentaTrak';
header('Location: ' . rtrim($baseUrl, '/') . '/main.php');
return;
