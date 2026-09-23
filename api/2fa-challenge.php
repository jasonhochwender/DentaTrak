<?php
/**
 * Two-Factor Session Challenge API
 *
 * Verifies a TOTP code for an already-authenticated session that has not
 * yet proven 2FA this session (e.g. Remember Me restores, sessions that
 * predate the proof flag, or switching into a practice that newly requires
 * 2FA). Distinct from login: the user is already authenticated; this only
 * elevates the session's 2FA proof state.
 *
 * Security:
 * - POST + CSRF only
 * - Requires an authenticated session AND a configured authenticator
 *   (users without one belong in the enrollment flow instead)
 * - Bounded per-session attempts to resist brute force (the code space is
 *   only 10^6, so the 30s window needs an attempt cap)
 * - Success sets $_SESSION['totp_verified'] only - no other session state
 *   is granted by this endpoint
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

requireCsrfToken();

$userId = (int)$_SESSION['db_user_id'];

// Brute-force guard: max 5 challenge attempts per 5-minute window per session.
$attempts = $_SESSION['2fa_challenge_attempts'] ?? ['count' => 0, 'window_start' => 0];
if ((time() - (int)$attempts['window_start']) > 300) {
    $attempts = ['count' => 0, 'window_start' => time()];
}
if ((int)$attempts['count'] >= 5) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => t('auth.errors.too_many_2fa_attempts')
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$code = trim((string)($data['code'] ?? ''));

if ($code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('auth.errors.2fa_code_required')]);
    exit;
}

if (!userHas2FAConfigured($userId)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error_code' => 'PRACTICE_2FA_SETUP_REQUIRED',
        'message' => t('auth.errors.2fa_not_configured')
    ]);
    exit;
}

$secret = get2FASecret($userId);
if (!$secret || !TOTP::verifyCode($secret, $code)) {
    $attempts['count']++;
    $_SESSION['2fa_challenge_attempts'] = $attempts;
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => t('auth.errors.invalid_2fa')
    ]);
    exit;
}

$_SESSION['totp_verified'] = true;
unset($_SESSION['2fa_challenge_attempts']);

if (function_exists('logUserActivity')) {
    logUserActivity($userId, '2fa_session_verified', 'User completed a 2FA challenge for the current session');
}
if (function_exists('logSecurityEvent')) {
    logSecurityEvent('2fa_session_verified', ['user_id' => $userId]);
}

echo json_encode([
    'success' => true,
    'message' => t('auth.errors.2fa_verified')
]);
