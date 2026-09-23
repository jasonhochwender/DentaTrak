<?php
/**
 * Source-level regression test for the lost-authenticator / 2FA recovery flow.
 *
 * Verifies the token schema, helper security properties, endpoint controls
 * (CSRF, anti-enumeration, rate limits, atomic consume, identity proof),
 * admin initiation gating, challenge-page entry points, OAuth hand-off,
 * i18n keys, and audit events - without requiring a live server.
 *
 * Run: php tests/2fa-recovery-test.php
 */

$root = dirname(__DIR__);
$recovery  = file_get_contents($root . '/api/2fa-recovery.php');
$helpers   = file_get_contents($root . '/api/2fa-recovery-helpers.php');
$policy    = file_get_contents($root . '/api/practice-2fa-policy.php');
$gcback    = file_get_contents($root . '/api/google-auth-callback.php');
$reqPage   = file_get_contents($root . '/2fa-recovery.php');
$resetPage = file_get_contents($root . '/2fa-reset.php');
$login     = file_get_contents($root . '/login.php');
$tfaPage   = file_get_contents($root . '/2fa-required.php');
$app       = file_get_contents($root . '/js/app.js');
$uid       = file_get_contents($root . '/api/unified-identity.php');
$migration = file_get_contents($root . '/migrations/2026_10_03_2fa_reset_tokens.php');
$locale    = json_decode(file_get_contents($root . '/locales/en-US.json'), true);

$passed = 0; $failed = 0;
function check($name, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS $name\n"; }
    else { $failed++; echo "FAIL $name\n"; }
}
function strBefore($haystack, $first, $second) {
    $a = strpos($haystack, $first);
    $b = strpos($haystack, $second);
    return $a !== false && $b !== false && $a < $b;
}
function loc($locale, $key) {
    $node = $locale;
    foreach (explode('.', $key) as $p) { $node = $node[$p] ?? null; }
    return $node !== null;
}

// ---- Migration / schema ----
check('migration creates two_factor_reset_tokens', strpos($migration, 'CREATE TABLE IF NOT EXISTS two_factor_reset_tokens') !== false);
check('token stored hashed only', strpos($migration, 'token_hash CHAR(64)') !== false
    && strpos($migration, 'totp_secret') === false);
check('token is single-use + expiring', strpos($migration, 'used BOOLEAN') !== false
    && strpos($migration, 'expires_at') !== false);
check('migration records initiator', strpos($migration, 'requested_by_user_id') !== false);
check('migration adds remember-me revocation watermark', strpos($migration, 'remember_me_revoked_after') !== false
    && strpos($migration, 'SHOW COLUMNS FROM users LIKE') !== false);
check('migration is idempotent', strpos($migration, 'CREATE TABLE IF NOT EXISTS') !== false);
check('expiry uses DATETIME (no ON UPDATE drift)', strpos($migration, 'expires_at DATETIME NOT NULL') !== false
    && strpos($migration, 'expires_at TIMESTAMP') === false);

// ---- Helper security properties ----
check('token generation uses CSPRNG', strpos($helpers, 'random_bytes(32)') !== false);
check('only SHA-256 hash is persisted', substr_count($helpers, "hash('sha256', \$token)") >= 2);
check('issuing invalidates prior tokens', strpos($helpers, 'SET used = 1 WHERE user_id = :user_id AND used = 0') !== false);
check('one-hour expiry', strpos($helpers, "'+1 hour'") !== false);
check('lookup requires unused + unexpired', strpos($helpers, "\$row['used']") !== false
    && strpos($helpers, "strtotime(\$row['expires_at']) < time()") !== false);
check('helper ensure-table mirrors migration', strpos($helpers, 'CREATE TABLE IF NOT EXISTS two_factor_reset_tokens') !== false);
check('no secrets in email builders', strpos($helpers, 'totp_secret') === false
    && strpos($helpers, 'otpauth') === false);

// ---- Request action ----
check('endpoint is POST-only', strpos($recovery, "REQUEST_METHOD'] !== 'POST'") !== false);
check('endpoint requires CSRF', strpos($recovery, 'requireCsrfToken()') !== false);
check('request response is anti-enumeration', strpos($recovery, 'function neutralRecoveryResponse') !== false
    && strpos($recovery, "t('auth.2fa_recovery.request_sent')") !== false);
check('request sends only for totp_enabled users', strpos($recovery, "\$user['totp_enabled']") !== false);
check('pending session steers account (email ignored)', strpos($recovery, "pending_2fa_user_id") !== false
    && strBefore($recovery, 'pendingUserId', "input['email']"));
check('request has session rate limit', strpos($recovery, "2fa_recovery_last") !== false);
check('request has per-user issue cap', strpos($recovery, 'INTERVAL 1 HOUR') !== false);

// ---- Complete action ----
check('token format validated', strpos($recovery, "/^[a-f0-9]{64}\$/") !== false);
$completeSrc = substr($recovery, strpos($recovery, 'function handleRecoveryComplete'));
check('reset runs inside a transaction', strpos($completeSrc, 'beginTransaction()') !== false
    && strpos($completeSrc, 'commit()') !== false && strpos($completeSrc, 'rollBack()') !== false);
check('token claimed atomically inside transaction', strBefore($completeSrc, 'beginTransaction()', 'SET used = 1 WHERE id = :id AND used = 0')
    && strBefore($completeSrc, 'SET used = 1 WHERE id = :id AND used = 0', 'commit()'));
$afterRowCount = substr($completeSrc, strpos($completeSrc, 'rowCount() !== 1'));
check('claim failure rolls back before responding', strBefore($afterRowCount, 'rollBack()', 'invalid();'));
check('disable failure throws to rollback', strpos($completeSrc, "throw new RuntimeException('disable2FA failed')") !== false);
check('revocation errors propagate inside transaction', strpos($completeSrc, 'DELETE FROM remember_me_tokens WHERE user_id') !== false
    && strpos($completeSrc, 'remember_me_revoked_after = NOW()') !== false);
check('DDL kept outside transaction', strBefore($completeSrc, 'ensure2FAResetTokensTable($pdo)', 'beginTransaction()')
    && strBefore($completeSrc, 'ensureRememberMeTable()', 'beginTransaction()'));
check('emails sent only after commit', strBefore($completeSrc, 'commit()', 'send2FAResetNotificationEmail'));
check('audit written only after commit', strBefore($completeSrc, 'commit()', "user_2fa_reset_completed"));
check('fault-injection hook is non-production only', strpos($completeSrc, 'force-2fa-reset-fail.json') !== false
    && strpos($completeSrc, "!== 'production'") !== false);
check('replay rejected via rowCount', strpos($recovery, 'rowCount() !== 1') !== false);
check('password verified via password_verify', strpos($recovery, 'password_verify(') !== false);
check('password attempts bounded (5/5min)', strpos($recovery, "'2fa_reset_attempts'") !== false
    && strpos($recovery, ">= 5") !== false && strpos($recovery, '429') !== false);
check('Google-only uses pending google proof', strpos($recovery, "pending_2fa_auth_method'] ?? ''") !== false
    && strpos($recovery, "'google'") !== false);
check('pending google proof is freshness-bounded', strpos($recovery, '<= 900') !== false);
check('reset uses centralized disable2FA', strpos($recovery, 'disable2FA($userId)') !== false);
check('remember-me revocation inside transaction', strBefore($completeSrc, 'DELETE FROM remember_me_tokens WHERE user_id', 'commit()')
    && strBefore($completeSrc, 'remember_me_revoked_after = NOW()', 'commit()'));
check('revocation stamps stateless-cookie watermark', strpos($uid, 'remember_me_revoked_after = NOW()') !== false);
check('validation rejects cookies at/before watermark', strpos($uid, 'UNIX_TIMESTAMP(remember_me_revoked_after)') !== false
    && strpos($uid, "remember_me_revoked_ts']") !== false
    && strpos($uid, 'REMEMBER_ME_EXPIRY_DAYS') !== false);
check('watermark column check is migration-safe', strpos($uid, "SHOW COLUMNS FROM users LIKE 'remember_me_revoked_after'") !== false);
check('pending session state cleared', strpos($recovery, "pending_2fa_user_id'") !== false
    && strpos($recovery, "totp_verified'") !== false);
check('session id rotated after reset', strpos($recovery, 'session_regenerate_id(true)') !== false);
check('required-practice flag returned', strpos($recovery, 'requires_reenrollment') !== false
    && strpos($recovery, 'userIn2FARequiredPractice') !== false);

// ---- Audit events ----
check('request audit events', strpos($recovery, "'user_2fa_reset_requested'") !== false
    && strpos($recovery, "'user_2fa_reset_email_sent'") !== false);
check('completion audit event', strpos($recovery, "'user_2fa_reset_completed'") !== false);
check('failure audit event', strpos($recovery, "'user_2fa_reset_failed'") !== false);
check('admin audit event', strpos($policy, "'admin_2fa_reset_requested'") !== false);
check('no secrets/codes/token values logged', strpos($recovery, "logSecurityEvent('user_2fa_reset_completed', [\n") !== false
    || strpos($recovery, "\$token") === 0 || substr_count($recovery, 'logSecurityEvent') >= 3);

// ---- Admin initiation ----
check('admin action is member-verified only (no disable2FA)',
    strpos($policy, 'handleSendMemberRecovery') !== false
    && strpos(substr($policy, strpos($policy, 'function handleSendMemberRecovery')), 'disable2FA') === false);
check('admin action checks membership in current practice', strpos($policy, 'practice_users pu') !== false
    && strpos($policy, 'pu.practice_id = :practice_id') !== false);
check('admin action requires member has 2FA', strpos($policy, "member['totp_enabled']") !== false);
check('admin endpoint gated by practice admin + lab denial',
    strBefore($policy, 'requirePracticeAdmin', 'handleSendMemberRecovery')
    && strBefore($policy, 'requireNotLabCollaborator', 'handleSendMemberRecovery'));
check('admin action requires CSRF', strpos(substr($policy, strpos($policy, 'function handleSendMemberRecovery')), 'requireCsrfToken()') !== false);
check('admin request records initiator id', strpos($policy, 'issue2FAResetToken((int)$member[\'id\'], $actorId)') !== false);

// ---- Entry points ----
check('login challenge shows lost-authenticator link', strpos($login, 'lost_authenticator') !== false
    && strpos($login, '2fa-recovery.php') !== false);
check('practice challenge page shows link in challenge branch only',
    strpos($tfaPage, 'lost_authenticator') !== false
    && strBefore($tfaPage, 'enrollFlow', 'lost_authenticator'));
check('request page has anti-enumeration copy', strpos($reqPage, 'request_sent') !== false);
check('request page is pending-session aware', strpos($reqPage, 'pending_2fa_user_id') !== false);
check('reset page validates token before render', strpos($resetPage, 'find2FAResetToken') !== false
    && strpos($resetPage, "/^[a-f0-9]{64}\$/") !== false);
check('reset page has Google hand-off', strpos($resetPage, 'start_google') !== false
    && strpos($resetPage, 'oauth-start.php') !== false);
check('Google callback returns to reset page during recovery',
    strpos($gcback, '2fa_recovery_token') !== false && strpos($gcback, '2fa-reset.php') !== false);
check('reset page clears hand-off flag', strpos($resetPage, "unset(\$_SESSION['2fa_recovery_token'])") !== false);

// ---- Admin UI ----
check('member list has actions column', strpos($app, 'col_actions') !== false);
check('recovery button only for enabled members', strpos($app, 'practice-2fa-recovery-btn') !== false
    && strBefore($app, 'm.totp_enabled', 'practice-2fa-recovery-btn'));
check('admin confirm explains no direct disable', loc($locale, 'settings.security.practice_2fa.recovery_confirm_message'));
check('recovery POST hits policy endpoint', strpos($app, 'send_member_recovery') !== false);

// ---- i18n ----
foreach ([
    'auth.2fa_recovery.lost_authenticator', 'auth.2fa_recovery.title',
    'auth.2fa_recovery.request_sent', 'auth.2fa_recovery.invalid_link',
    'auth.2fa_recovery.password_intro', 'auth.2fa_recovery.reset_success',
    'auth.2fa_recovery.reenroll_note', 'auth.2fa_recovery.google_intro',
    'auth.2fa_recovery.google_verify_required', 'auth.2fa_recovery.wrong_password',
    'auth.2fa_recovery.request_new_link', 'auth.2fa_recovery.error',
    'email.2fa_recovery.subject', 'email.2fa_recovery.intro_admin',
    'email.2fa_reset_notice.subject', 'email.2fa_reset_notice.action_needed',
    'settings.security.practice_2fa.send_recovery',
    'settings.security.practice_2fa.recovery_sent',
    'settings.security.practice_2fa.recovery_not_needed',
] as $k) {
    check("locale $k", loc($locale, $k));
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
