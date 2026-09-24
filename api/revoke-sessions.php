<?php
/**
 * Self-service account-wide session revocation.
 *
 * POST - signs the current user out of every OTHER browser/device session.
 * The calling session is intentionally preserved: after the version bump,
 * it alone is re-stamped with the new session generation. Old remember-me
 * cookies for the account are rejected at the same time, so a revoked
 * device cannot silently sign back in.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/unified-identity.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

requireCsrfToken();

$userId = (int)$_SESSION['db_user_id'];

try {
    $newVersion = revokeAllUserSessions($userId, 'self_service', $userId);
} catch (Throwable $e) {
    error_log('[revoke-sessions] Revocation failed for user ' . $userId . ': ' . $e->getMessage());
    if (function_exists('logSecurityEvent')) {
        logSecurityEvent('user_sessions_revoke_failed', [
            'affected_user_id' => $userId,
            'actor_user_id' => $userId,
            'reason' => 'self_service'
        ]);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => t('settings.security.sessions.revoke_error')
    ]);
    exit;
}

// Preserve ONLY this session: re-stamp it with the new generation. Every
// other session still carries the older stamp and fails the session.php
// check on its next request.
$_SESSION['auth_version'] = $newVersion;

if (function_exists('logSecurityEvent')) {
    logSecurityEvent('user_sessions_revoked_self_service', [
        'affected_user_id' => $userId,
        'actor_user_id' => $userId,
        'reason' => 'self_service',
        'practice_id' => $_SESSION['current_practice_id'] ?? null
    ]);
}

echo json_encode([
    'success' => true,
    'message' => t('settings.security.sessions.revoke_success')
]);
