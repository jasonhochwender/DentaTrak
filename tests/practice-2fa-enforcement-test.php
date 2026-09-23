<?php
/**
 * Source-level regression test for practice-wide 2FA enforcement.
 *
 * Verifies the schema migration, centralized enforcement helpers, session
 * proof lifecycle, endpoint gating, routing (login / switch / select /
 * BAA / remember-me), the blocking page, admin policy endpoint + UI,
 * i18n keys, and audit events - without requiring a live server.
 *
 * Run: php tests/practice-2fa-enforcement-test.php
 */

$root = dirname(__DIR__);
$sec     = file_get_contents($root . '/api/practice-security.php');
$ui      = file_get_contents($root . '/api/unified-identity.php');
$auth    = file_get_contents($root . '/api/auth-email.php');
$vgoogle = file_get_contents($root . '/api/verify-google-2fa.php');
$gcback  = file_get_contents($root . '/api/google-auth-callback.php');
$setup   = file_get_contents($root . '/api/2fa-setup.php');
$chal    = file_get_contents($root . '/api/2fa-challenge.php');
$policy  = file_get_contents($root . '/api/practice-2fa-policy.php');
$umgr    = file_get_contents($root . '/api/user-manager.php');
$selpr   = file_get_contents($root . '/api/select-practice.php');
$swpr    = file_get_contents($root . '/api/switch-practice.php');
$baa     = file_get_contents($root . '/api/accept-baa.php');
$invite  = file_get_contents($root . '/api/practice-invite-email.php');
$saveset = file_get_contents($root . '/api/save-settings.php');
$main    = file_get_contents($root . '/main.php');
$billing = file_get_contents($root . '/billing.php');
$tfaPage = file_get_contents($root . '/2fa-required.php');
$sess    = file_get_contents($root . '/api/session.php');
$login   = file_get_contents($root . '/login.php');
$chooser = file_get_contents($root . '/practice-setup.php');
$app     = file_get_contents($root . '/js/app.js');
$css     = file_get_contents($root . '/css/settings-billing.css');
$migration = file_get_contents($root . '/migrations/2026_10_02_practice_require_2fa.php');
$locale  = json_decode(file_get_contents($root . '/locales/en-US.json'), true);

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

// ---- Migration / schema ----
check('migration adds practices.require_2fa', strpos($migration, "ADD COLUMN require_2fa") !== false
    && strpos($migration, "practices LIKE {\$q}") !== false);
check('migration defaults enforcement OFF', strpos($migration, "NOT NULL DEFAULT 0") !== false);
check('migration is idempotent (SHOW COLUMNS guard)', strpos($migration, "SHOW COLUMNS FROM practices LIKE") !== false);
check('migration does not touch users.totp', strpos($migration, 'ALTER TABLE users') === false
    && strpos($migration, 'UPDATE users') === false
    && strpos($migration, 'totp_secret') === false);

// ---- Centralized helpers (practice-security.php) ----
check('practiceRequires2FA helper', strpos($sec, 'function practiceRequires2FA') !== false
    && strpos($sec, "SHOW COLUMNS FROM practices LIKE 'require_2fa'") !== false);
check('userHas2FAConfigured reads totp_enabled only', strpos($sec, 'function userHas2FAConfigured') !== false
    && strpos($sec, 'SELECT totp_enabled FROM users') !== false);
check('session proof flag helper', strpos($sec, 'function session2FASatisfied') !== false
    && strpos($sec, "\$_SESSION['totp_verified']") !== false);
check('block classifier returns both codes', strpos($sec, "'PRACTICE_2FA_SETUP_REQUIRED'") !== false
    && strpos($sec, "'PRACTICE_2FA_CHALLENGE_REQUIRED'") !== false);
check('block redirects to 2fa-required page', strpos($sec, "2fa-required.php?practice_id=") !== false);

// ---- API boundary enforcement ----
check('requireValidPracticeContext enforces after membership',
    strBefore($sec, "'unauthorized_practice_access'", 'getPractice2FABlock((int)$practiceId, $userId)'));
check('API block emits structured error_code', strpos($sec, "'error_code' => \$twoFABlock['error_code']") !== false);
check('activatePracticeSession blocks before session mutation',
    strBefore($sec, 'getPractice2FABlock((int)$practice', "\$_SESSION['current_practice_id'] = (int) \$practice['id']"));
check('activation clears pending flag on success', strpos($sec, "unset(\$_SESSION['pending_2fa_practice_id']);") !== false);

// ---- Session proof lifecycle ----
check('setupUserSession clears per-session proof', strpos($ui, "\$_SESSION['totp_verified'],") !== false);
check('email login records proof after challenge',
    strBefore($auth, 'setupUserSession($user', "\$_SESSION['totp_verified'] = true")
    && strpos($auth, '$totpVerifiedThisLogin = true') !== false);
check('google 2FA verify records proof', strpos($vgoogle, "\$_SESSION['totp_verified'] = true;") !== false);
check('enrollment verify records proof', strpos($setup, "\$_SESSION['totp_verified'] = true;") !== false);
check('disable clears session proof', strpos($setup, "unset(\$_SESSION['totp_verified']);") !== false);

// ---- 2fa-setup.php hardening ----
check('setup requires POST+CSRF', strpos($setup, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false
    && strpos($setup, 'requireCsrfToken()') !== false);
check('disable blocked inside required practice', strpos($setup, 'practiceRequires2FA($currentPracticeId)') !== false
    && strpos($setup, "'PRACTICE_2FA_DISABLE_BLOCKED'") !== false);

// ---- Session challenge endpoint ----
check('challenge endpoint exists + POST only', $chal !== false
    && strpos($chal, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false);
check('challenge requires CSRF + session', strpos($chal, 'requireCsrfToken()') !== false
    && strpos($chal, "\$_SESSION['db_user_id']") !== false);
check('challenge bounded attempts (5/5min)', strpos($chal, "'count'] >= 5") !== false
    && strpos($chal, '> 300') !== false);
check('challenge requires configured authenticator', strpos($chal, 'userHas2FAConfigured($userId)') !== false);
check('challenge sets proof + audit', strpos($chal, "\$_SESSION['totp_verified'] = true") !== false
    && strpos($chal, "'2fa_session_verified'") !== false);
check('challenge never returns secrets', strpos($chal, "'secret'") === false
    && strpos($chal, 'totp_secret') === false);

// ---- Admin policy endpoint ----
check('policy endpoint gated: admin + not lab + CSRF',
    strpos($policy, 'requirePracticeAdmin($currentPracticeId)') !== false
    && strpos($policy, 'requireNotLabCollaborator($currentPracticeId') !== false
    && strpos($policy, 'requireCsrfToken()') !== false);
check('policy requires valid practice context', strpos($policy, 'requireValidPracticeContext()') !== false);
check('actor lockout protection (428 ACTOR_2FA_REQUIRED)', strpos($policy, "'ACTOR_2FA_REQUIRED'") !== false
    && strpos($policy, 'userHas2FAConfigured($userId)') !== false);
check('audit events for enable/disable', strpos($policy, "'practice_2fa_required_enabled'") !== false
    && strpos($policy, "'practice_2fa_required_disabled'") !== false);
check('member list exposes no secrets', strpos($policy, 'totp_secret') === false
    && strpos($policy, 'totp_enabled') !== false);
check('member list is practice-scoped', strpos($policy, 'pu.practice_id = :practice_id') !== false);
check('schema guard when column unmigrated', strpos($policy, "'SCHEMA_NOT_MIGRATED'") !== false);

// ---- Login / switch / select routing ----
check('login resolution holds pending practice', strpos($umgr, "\$_SESSION['pending_2fa_practice_id']") !== false
    && strpos($umgr, "unset(\$_SESSION['current_practice_id'], \$_SESSION['practice_uuid']);") !== false
    && strpos($umgr, "'2fa-required.php'") !== false);
check('login resolution clears stale pending', strpos($umgr, "unset(\$_SESSION['pending_2fa_practice_id']);") !== false);
check('google callback honors 2FA redirect', strpos($gcback, "\$needs2FA") !== false
    && strpos($gcback, "'../2fa-required.php'") !== false);
check('select-practice relays block + browser redirect', strpos($selpr, 'PRACTICE_2FA_SETUP_REQUIRED') !== false
    && strpos($selpr, "Location: ../2fa-required.php?practice_id=") !== false);
check('switch-practice relays error_code, skips denial log', strpos($swpr, "\$twoFABlocked") !== false
    && strpos($swpr, "\$response['error_code']") !== false);
check('accept-baa converts required practice to pending', strpos($baa, "\$_SESSION['pending_2fa_practice_id'] = (int)\$practiceId;") !== false);
check('chooser JS follows 2FA redirect', strpos($chooser, "PRACTICE_2FA_SETUP_REQUIRED") !== false
    && strpos($chooser, "2fa-required.php") !== false);

// ---- Page gates ----
check('main.php enforces on practice pages', strpos($main, 'practiceRequires2FA($currentPracticeId)') !== false
    && strpos($main, 'session2FASatisfied()') !== false
    && strpos($main, "Location: 2fa-required.php") !== false);
check('main.php routes pending practice to 2FA page', strpos($main, 'pending_2fa_practice_id') !== false);
check('billing.php enforces', strpos($billing, 'practiceRequires2FA($currentPracticeId)') !== false
    && strpos($billing, "Location: 2fa-required.php") !== false);

// ---- Blocking page ----
check('2fa-required page exists + requires session only', $tfaPage !== false
    && strpos($tfaPage, "\$_SESSION['db_user_id']") !== false
    && strpos($tfaPage, 'requireValidPracticeContext') === false);
check('page validates membership server-side', strpos($tfaPage, 'twoFaRequiredMembershipOk') !== false
    && strpos($tfaPage, 'pu.practice_id = :practice_id') !== false);
check('page activates pending practice only after proof', strpos($tfaPage, 'activatePracticeSession($pendingPracticeId)') !== false);
check('page supports enroll + challenge flows', strpos($tfaPage, 'enrollFlow') !== false
    && strpos($tfaPage, 'challengeFlow') !== false
    && strpos($tfaPage, "api/2fa-challenge.php") !== false
    && strpos($tfaPage, "api/2fa-setup.php?action=setup") !== false);
check('page offers practice escape + sign out', strpos($tfaPage, 'choose_practice') !== false
    && strpos($tfaPage, 'api/logout.php') !== false);
check('page CSRF token wired', strpos($tfaPage, 'csrf_token') !== false
    && strpos($tfaPage, 'generateCsrfToken()') !== false);

// ---- Settings UI ----
check('settings section admin-gated server-side', strpos($main, 'practice-2fa-section') !== false
    && strBefore($main, 'if ($isCurrentUserPracticeAdmin): ?>' . "\n" . '                        <!-- Practice-Wide', 'practice-2fa-section'));
check('toggle markup present', strpos($main, 'id="practiceRequire2fa"') !== false
    && strpos($main, 'practice_2fa.toggle_label') !== false);
check('member status table markup', strpos($main, 'practice2faMembersBody') !== false
    && strpos($main, 'practice_2fa.col_status') !== false);
check('app.js policy module present', strpos($app, 'practice2faLoadStatus') !== false
    && strpos($app, 'api/practice-2fa-policy.php?action=status') !== false
    && strpos($app, 'api/practice-2fa-policy.php?action=update') !== false);
check('enable requires confirmation modal', strpos($app, "practice_2fa.confirm_title") !== false
    && strpos($app, 'showConfirmModal(') !== false);
check('actor-required routes to setup', strpos($app, "'ACTOR_2FA_REQUIRED'") !== false
    && strpos($app, 'data.redirect') !== false);
check('checkbox reverts on failure', strpos($app, 'practiceRequire2fa.checked = !enabled') !== false);
check('global fetch interceptor routes on block codes', strpos($app, 'PRACTICE_2FA_SETUP_REQUIRED') !== false
    && strpos($app, 'PRACTICE_2FA_CHALLENGE_REQUIRED') !== false
    && strpos($app, 'response.clone().json()') !== false);
check('member list escapes user data', strpos($app, 'practice2faEscape') !== false);

// ---- Invitation email ----
check('invite email accepts requires_2fa flag', strpos($invite, 'bool $requires2fa = false') !== false
    && strpos($invite, "email.practice_invite.requires_2fa") !== false);
check('save-settings passes practice flag to invites', strpos($saveset, 'practiceRequires2FA($currentPracticeId)') !== false
    && strpos($saveset, '$inviteRequires2fa') !== false);

// ---- i18n ----
$security = $locale['settings']['security'] ?? [];
$p2fa = $security['practice_2fa'] ?? [];
$errors = $locale['auth']['errors'] ?? [];
$tfa = $locale['two_fa_required'] ?? [];
$inviteKeys = $locale['email']['practice_invite'] ?? [];
check('settings.practice_2fa keys', !empty($p2fa['title']) && !empty($p2fa['toggle_label'])
    && !empty($p2fa['confirm_title']) && !empty($p2fa['confirm_message'])
    && !empty($p2fa['status_enabled']) && !empty($p2fa['status_setup_required'])
    && !empty($p2fa['actor_setup_required']) && !empty($p2fa['enabled_success'])
    && !empty($p2fa['disabled_success']) && !empty($p2fa['summary']));
check('two_fa_required page keys', !empty($tfa['title']) && !empty($tfa['intro_enroll'])
    && !empty($tfa['intro_challenge']) && !empty($tfa['enroll']['begin'])
    && !empty($tfa['challenge']['verify']) && !empty($tfa['choose_practice'])
    && !empty($tfa['sign_out']));
check('auth.errors keys', !empty($errors['practice_2fa_required']) && !empty($errors['too_many_2fa_attempts'])
    && !empty($errors['2fa_code_required']) && !empty($errors['2fa_not_configured'])
    && !empty($errors['2fa_verified']));
check('disable blocked message key', !empty($security['two_factor']['disable']['blocked_by_practice']));
check('invite 2fa note key', !empty($inviteKeys['requires_2fa']));

// ---- Remember Me cannot bypass personal 2FA ----
// The persistent token restores identity only; a user with totp_enabled
// must be held at a pending-2FA challenge before any session exists -
// regardless of whether any practice requires 2FA.
$rmGuardPos  = strpos($sess, "get2FAStatus(\$user['id'])");
$rmSetupPos  = strpos($sess, "setupUserSession(\$user, 'remember_me')");
check('remember-me checks personal 2FA before creating a session',
    $rmGuardPos !== false && $rmSetupPos !== false && $rmGuardPos < $rmSetupPos);
check('remember-me holds 2FA users in pending state',
    strpos($sess, "\$_SESSION['pending_2fa_auth_method'] = 'remember_me'") !== false
    && strpos($sess, "\$_SESSION['pending_2fa_db_user']") !== false);
check('remember-me pending path does not set up the session',
    preg_match("/pending_2fa_auth_method'\] = 'remember_me';[^}]+return false;/s", $sess) === 1);
check('login page detects pending remember-me challenge',
    strpos($login, "\$pendingRememberMe2FA") !== false
    && strpos($login, "pending_2fa_auth_method'] ?? '') === 'remember_me'") !== false);
check('login page renders challenge for remember-me pending',
    strpos($login, 'pendingRememberMe') !== false
    && strpos($login, 'remember_me_2fa_subtitle') !== false);
check('pending-verify endpoint has bounded attempts',
    strpos($vgoogle, "2fa_challenge_attempts") !== false
    && strpos($vgoogle, '429') !== false);
check('pending-verify clears every pending field on success',
    substr_count($vgoogle, 'pending_2fa_') >= 8
    && strpos($vgoogle, "unset(\$_SESSION['pending_2fa_email'])") !== false
    && strpos($vgoogle, "unset(\$_SESSION['pending_2fa_timestamp'])") !== false);
check('setupUserSession clears all pending-2FA state centrally',
    strpos($ui, "\$_SESSION['pending_2fa_auth_method']") !== false
    && strpos($ui, "\$_SESSION['pending_2fa_db_user']") !== false
    && strpos($ui, "\$_SESSION['pending_2fa_user_data']") !== false);
check('remember_me_2fa_subtitle key exists',
    !empty($locale['auth']['login']['remember_me_2fa_subtitle']));

// ---- CSS ----
check('warning badge style', strpos($css, '.status-badge.status-warning') !== false);
check('member table responsive (data-label pattern)', strpos($css, '.practice-2fa-table td::before') !== false
    && strpos($css, 'max-width: 768px') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
