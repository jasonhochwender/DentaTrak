<?php
/**
 * Two-Factor Recovery API
 *
 * Handles the lost-authenticator flow:
 *
 *   action=request  - issue a single-use, emailed reset token. Always
 *                     returns the same neutral response whether or not the
 *                     account exists / has 2FA (anti-enumeration). When the
 *                     caller is mid-challenge (pending_2fa session state),
 *                     the pending account is used and the email field is
 *                     ignored so a pending session cannot target other
 *                     accounts.
 *   action=complete - consume a token and reset 2FA, but ONLY after
 *                     identity re-verification: the account password for
 *                     password-capable accounts, or a fresh Google sign-in
 *                     (pending_2fa google state) for Google-only accounts.
 *                     Email possession alone is never sufficient.
 *
 * Security:
 * - POST + CSRF for both actions
 * - Rate-limited requests (session window) and bounded password attempts
 * - Hashed, single-use, 1-hour tokens; old tokens invalidated on issue
 * - disable2FA() clears the authenticator centrally; remember-me tokens
 *   are revoked; pending session state is cleared
 * - No secrets, codes, or token values are logged
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/2fa-recovery-helpers.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireCsrfToken();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'request':
        handleRecoveryRequest($input);
        break;
    case 'complete':
        handleRecoveryComplete($input);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

/**
 * Neutral anti-enumeration response - identical for every request outcome.
 */
function neutralRecoveryResponse(): void {
    echo json_encode([
        'success' => true,
        'message' => t('auth.2fa_recovery.request_sent')
    ]);
}

function handleRecoveryRequest(array $input): void {
    global $pdo;

    // Rate limit: one recovery request per 60s per session (same pattern
    // as demo-request.php), plus per-IP tracking in-session.
    $now = time();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ipKey = '2fa_recovery_' . md5($ip);
    if (($now - (int)($_SESSION['2fa_recovery_last'] ?? 0)) < 60
        || ($now - (int)($_SESSION[$ipKey] ?? 0)) < 60) {
        // Still respond neutrally - a rate-limit error would reveal that
        // requests are being processed for this address.
        neutralRecoveryResponse();
        return;
    }
    $_SESSION['2fa_recovery_last'] = $now;
    $_SESSION[$ipKey] = $now;

    // Resolve the target account. A pending-2FA session already proves who
    // the caller was authenticating as - never let the email field steer a
    // pending session toward a different account.
    $user = null;
    $pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
    try {
        if ($pendingUserId) {
            $stmt = $pdo->prepare("
                SELECT id, email, first_name, auth_method, totp_enabled, is_active
                FROM users WHERE id = :id AND is_active = 1
            ");
            $stmt->execute(['id' => $pendingUserId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $email = trim($input['email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stmt = $pdo->prepare("
                    SELECT id, email, first_name, auth_method, totp_enabled, is_active
                    FROM users WHERE email = :email AND is_active = 1
                ");
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } catch (PDOException $e) {
        error_log('[2fa-recovery] Request lookup failed: ' . $e->getMessage());
        neutralRecoveryResponse();
        return;
    }

    // Only act when the account exists AND actually has 2FA to reset.
    // Everything else returns the same neutral response. A per-user issue
    // cap (DB-backed, not session) keeps fresh sessions from mail-bombing
    // the account - still invisible behind the neutral response.
    if ($user && !empty($user['totp_enabled'])) {
        ensure2FAResetTokensTable($pdo);
        $recent = $pdo->prepare(
            "SELECT COUNT(*) FROM two_factor_reset_tokens
             WHERE user_id = :id AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $recent->execute(['id' => (int)$user['id']]);
        if ((int)$recent->fetchColumn() < 3) {
            $token = issue2FAResetToken((int)$user['id'], null);
        } else {
            $token = null;
        }
        if ($token) {
            send2FARecoveryEmail($user, $token, false);
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('user_2fa_reset_requested', [
                    'affected_user_id' => (int)$user['id'],
                    'method' => 'self_service'
                ]);
                logSecurityEvent('user_2fa_reset_email_sent', [
                    'affected_user_id' => (int)$user['id']
                ]);
            }
            if (function_exists('logUserActivity')) {
                logUserActivity((int)$user['id'], '2fa_reset_requested', 'User requested a two-factor recovery link');
            }
        }
    }

    neutralRecoveryResponse();
}

function handleRecoveryComplete(array $input): void {
    global $pdo, $appConfig;

    $token = trim((string)($input['token'] ?? ''));
    $password = (string)($input['password'] ?? '');

    $invalid = function (string $messageKey = 'auth.2fa_recovery.invalid_link'): void {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => t($messageKey)]);
    };

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $invalid();
        return;
    }

    $tokenRow = find2FAResetToken($token);
    if (!$tokenRow) {
        $invalid();
        return;
    }

    $userId = (int)$tokenRow['user_id'];
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, auth_method, password_hash, totp_enabled, is_active
        FROM users WHERE id = :id AND is_active = 1
    ");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || empty($user['totp_enabled'])) {
        // Account gone or 2FA already removed - the token is spent either way.
        $invalid();
        return;
    }

    // Identity re-verification - the email link alone is never sufficient.
    // Password-capable accounts prove with the current password; Google-only
    // accounts prove with a fresh Google sign-in held in the pending-2FA
    // session state (bounded to a recent sign-in).
    $hasPassword = !empty($user['password_hash']);
    if ($hasPassword) {
        // Bounded password attempts: 5 per 5-minute window per session.
        $attempts = $_SESSION['2fa_reset_attempts'] ?? ['count' => 0, 'window_start' => 0];
        if ((time() - (int)$attempts['window_start']) > 300) {
            $attempts = ['count' => 0, 'window_start' => time()];
        }
        if ((int)$attempts['count'] >= 5) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => t('auth.errors.too_many_2fa_attempts')
            ]);
            return;
        }
        if ($password === '' || !password_verify($password, $user['password_hash'])) {
            $attempts['count']++;
            $_SESSION['2fa_reset_attempts'] = $attempts;
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('user_2fa_reset_failed', [
                    'affected_user_id' => $userId,
                    'reason' => 'password_verification_failed'
                ]);
            }
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => t('auth.2fa_recovery.wrong_password')]);
            return;
        }
        $verificationMethod = 'password';
    } else {
        // Google-only account: a fresh Google sign-in leaves the account in
        // pending_2fa state. That state IS the identity proof - Google
        // already authenticated them; we only require it to be recent and
        // for this exact account.
        $pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        $pendingMethod = $_SESSION['pending_2fa_auth_method'] ?? '';
        $pendingAt = (int)($_SESSION['pending_2fa_timestamp'] ?? 0);
        $pendingFresh = $pendingAt > 0 && (time() - $pendingAt) <= 900;
        if ($pendingUserId !== $userId || $pendingMethod !== 'google' || !$pendingFresh) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error_code' => 'GOOGLE_VERIFICATION_REQUIRED',
                'message' => t('auth.2fa_recovery.google_verify_required')
            ]);
            return;
        }
        $verificationMethod = 'google';
    }

    // Identity verified - run the security-critical reset as ONE
    // transaction: token claim + TOTP disable + remember-me revocation all
    // commit together or not at all. A failure mid-reset must never leave
    // the token consumed while 2FA stays enabled (the link would be dead
    // with nothing recovered).
    //
    // DDL/ensure calls run BEFORE beginTransaction - CREATE TABLE (even IF
    // NOT EXISTS) can implicit-commit and silently break the atomicity.
    ensure2FAResetTokensTable($pdo);
    if (function_exists('ensureRememberMeTable')) {
        ensureRememberMeTable();
    }

    try {
        $pdo->beginTransaction();

        // Atomic claim under the transaction's row lock: a concurrent
        // attempt either waits for this commit then matches nothing
        // (rowCount 0), or won the claim first - both still cannot succeed.
        $consume = $pdo->prepare(
            "UPDATE two_factor_reset_tokens SET used = 1 WHERE id = :id AND used = 0"
        );
        $consume->execute(['id' => (int)$tokenRow['id']]);
        if ($consume->rowCount() !== 1) {
            $pdo->rollBack();
            $invalid();
            return;
        }

        // Test-only fault injection (mirrors force-email-failure.json;
        // verifies the rollback path end-to-end). Never active in prod.
        if (($appConfig['current_environment'] ?? 'production') !== 'production'
            && file_exists(__DIR__ . '/../testResults/force-2fa-reset-fail.json')) {
            throw new RuntimeException('Simulated reset failure');
        }

        if (!disable2FA($userId)) {
            throw new RuntimeException('disable2FA failed');
        }

        // Remember-me revocation with PROPAGATING errors - the shared
        // revokeAllRememberMeTokens() swallows PDOExceptions, which would
        // defeat this rollback guarantee, so the same two writes run here
        // directly: legacy selector rows + the HMAC-cookie watermark.
        $pdo->prepare("DELETE FROM remember_me_tokens WHERE user_id = :id")
            ->execute(['id' => $userId]);
        if (rememberMeRevocationColumnExists()) {
            $pdo->prepare("UPDATE users SET remember_me_revoked_after = NOW() WHERE id = :id")
                ->execute(['id' => $userId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[2fa-recovery] Reset transaction failed: ' . $e->getMessage());
        if (function_exists('logSecurityEvent')) {
            logSecurityEvent('user_2fa_reset_failed', [
                'affected_user_id' => $userId,
                'reason' => 'reset_transaction_failed'
            ]);
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('auth.2fa_recovery.reset_failed')]);
        return;
    }

    // Side effects only AFTER the security state is committed - session
    // rotation, audit rows, and email must never roll the reset back.
    // Other live sessions are not keyed by user and cannot be revoked
    // individually (documented limitation); this session is rotated and
    // cleared instead.
    unset(
        $_SESSION['totp_verified'],
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_email'],
        $_SESSION['pending_2fa_remember_me'],
        $_SESSION['pending_2fa_timestamp'],
        $_SESSION['pending_2fa_auth_method'],
        $_SESSION['pending_2fa_user_data'],
        $_SESSION['pending_2fa_db_user'],
        $_SESSION['pending_2fa_practice_id'],
        $_SESSION['2fa_recovery_token'],
        $_SESSION['2fa_reset_attempts']
    );
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    if (function_exists('logSecurityEvent')) {
        logSecurityEvent('user_2fa_reset_completed', [
            'affected_user_id' => $userId,
            'method' => $verificationMethod,
            'admin_initiated' => !empty($tokenRow['requested_by_user_id'])
        ]);
    }
    if (function_exists('logUserActivity')) {
        logUserActivity($userId, '2fa_reset_completed', 'Two-factor authentication was reset via recovery link');
    }

    send2FAResetNotificationEmail($user);

    echo json_encode([
        'success' => true,
        'requires_reenrollment' => userIn2FARequiredPractice($userId),
        'message' => t('auth.2fa_recovery.reset_success')
    ]);
}
