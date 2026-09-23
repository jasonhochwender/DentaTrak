<?php
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
require_once __DIR__ . '/api/2fa-recovery-helpers.php';
setSecurityHeaders();

$appName = $appConfig['appName'];
$currentEnv = $appConfig['current_environment'] ?? 'production';
$envClass = $currentEnv === 'production' ? 'env-prod' : ($currentEnv === 'uat' ? 'env-uat' : 'env-dev');
$csrfToken = generateCsrfToken();

$token = trim((string)($_GET['token'] ?? ''));

// Google verification hand-off: stash the presented token, then send the
// user through the standard Google OAuth flow. The callback returns here
// and the pending-2FA google state becomes the identity proof.
if (isset($_GET['start_google']) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $_SESSION['2fa_recovery_token'] = $token;
    header('Location: api/oauth-start.php');
    exit;
}

// The hand-off flag only exists to steer the OAuth callback back here -
// once this page renders it has served its purpose. The pending Google
// state (not this flag) is what the completion endpoint verifies.
unset($_SESSION['2fa_recovery_token']);

// Validate the token server-side before rendering anything.
$state = 'invalid';
$user = null;
$tokenRow = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenRow = find2FAResetToken($token);
    if ($tokenRow) {
        $stmt = $pdo->prepare("
            SELECT id, email, first_name, auth_method, password_hash, totp_enabled, is_active
            FROM users WHERE id = :id AND is_active = 1
        ");
        $stmt->execute(['id' => (int)$tokenRow['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($user && !empty($user['totp_enabled'])) {
            if (!empty($user['password_hash'])) {
                $state = 'password';
            } else {
                // Google-only: either the pending Google sign-in is already
                // present (they just returned from oauth-start), or they
                // still need to complete it.
                $pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
                $pendingMethod = $_SESSION['pending_2fa_auth_method'] ?? '';
                $pendingAt = (int)($_SESSION['pending_2fa_timestamp'] ?? 0);
                $googleVerified = $pendingUserId === (int)$user['id']
                    && $pendingMethod === 'google'
                    && $pendingAt > 0 && (time() - $pendingAt) <= 900;
                $state = $googleVerified ? 'google_confirmed' : 'google';
            }
        }
    }
}

$pageStrings = [
    'invalid' => t('auth.2fa_recovery.invalid_link'),
    'wrong_password' => t('auth.2fa_recovery.wrong_password'),
    'reset_failed' => t('auth.2fa_recovery.reset_failed'),
    'reset_success' => t('auth.2fa_recovery.reset_success'),
    'reenroll_note' => t('auth.2fa_recovery.reenroll_note'),
    'verifying' => t('auth.2fa_recovery.verifying'),
    'error' => t('auth.2fa_recovery.error'),
];
?><!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
  <title><?php echo htmlspecialchars(t('auth.2fa_recovery.reset_title')) . ' - ' . htmlspecialchars($appName); ?></title>
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">
  <link rel="stylesheet" href="css/app.css">
  <link rel="stylesheet" href="css/login.css">
  <script>window.__i18n = <?php echo getTranslationsJsonForJs(); ?>;</script>
  <script src="js/i18n.js"></script>
</head>
<body class="login-body <?php echo $envClass; ?>">
  <div class="login-bg-shapes">
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>
    <div class="shape shape-4"></div>
    <div class="shape shape-5"></div>
  </div>

  <div class="reset-password-container">
    <?php echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false); ?>
    <div class="reset-password-header">
      <div class="icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </div>
      <h2><?php echo htmlspecialchars(t('auth.2fa_recovery.reset_title')); ?></h2>
    </div>

    <?php if ($state === 'invalid'): ?>
      <p class="form-description"><?php echo htmlspecialchars(t('auth.2fa_recovery.invalid_link')); ?></p>
      <p class="form-description">
        <a href="2fa-recovery.php"><?php echo htmlspecialchars(t('auth.2fa_recovery.request_new_link')); ?></a>
      </p>

    <?php elseif ($state === 'password'): ?>
      <p class="form-description"><?php echo htmlspecialchars(t('auth.2fa_recovery.password_intro')); ?></p>
      <div class="form-group">
        <label for="resetPassword"><?php echo htmlspecialchars(t('auth.2fa_recovery.password_label')); ?></label>
        <input type="password" id="resetPassword" autocomplete="current-password" required>
      </div>
      <div id="resetError" class="form-error" style="display: none;" role="alert"></div>
      <button type="button" id="resetSubmitBtn" class="email-submit-btn">
        <?php echo htmlspecialchars(t('auth.2fa_recovery.reset_button')); ?>
      </button>

    <?php elseif ($state === 'google'): ?>
      <p class="form-description"><?php echo htmlspecialchars(t('auth.2fa_recovery.google_intro')); ?></p>
      <a href="2fa-reset.php?token=<?php echo urlencode($token); ?>&start_google=1" class="google-signin-btn" style="display:inline-block;">
        <?php echo htmlspecialchars(t('auth.2fa_recovery.google_verify_button')); ?>
      </a>

    <?php elseif ($state === 'google_confirmed'): ?>
      <p class="form-description"><?php echo htmlspecialchars(t('auth.2fa_recovery.google_confirmed_intro')); ?></p>
      <div id="resetError" class="form-error" style="display: none;" role="alert"></div>
      <button type="button" id="resetSubmitBtn" class="email-submit-btn">
        <?php echo htmlspecialchars(t('auth.2fa_recovery.reset_button')); ?>
      </button>
    <?php endif; ?>

    <div id="resetDone" style="display: none;">
      <p class="form-description" id="resetDoneMessage"></p>
      <p class="form-description" id="resetDoneReenroll" style="display:none;"></p>
      <p class="form-description"><a href="login.php"><?php echo htmlspecialchars(t('auth.login.sign_in')); ?></a></p>
    </div>

    <div class="email-form-footer">
      <a href="login.php" class="link-btn">← <?php echo htmlspecialchars(t('auth.login.back_to_login')); ?></a>
    </div>
  </div>

  <script>
  (function() {
    var strings = <?php echo json_encode($pageStrings, JSON_UNESCAPED_SLASHES); ?>;
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var btn = document.getElementById('resetSubmitBtn');
    if (!btn) return;

    var passwordInput = document.getElementById('resetPassword');
    var errorBox = document.getElementById('resetError');
    var token = <?php echo json_encode($token); ?>;

    function showError(msg) {
      if (!errorBox) return;
      errorBox.textContent = msg || strings.error;
      errorBox.style.display = 'block';
    }

    btn.addEventListener('click', function() {
      if (errorBox) errorBox.style.display = 'none';
      btn.disabled = true;
      btn.textContent = strings.verifying;
      fetch('api/2fa-recovery.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'complete',
          token: token,
          password: passwordInput ? passwordInput.value : '',
          csrf_token: csrfToken
        })
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          btn.style.display = 'none';
          if (passwordInput) passwordInput.closest('.form-group').style.display = 'none';
          document.getElementById('resetDoneMessage').textContent = data.message || strings.reset_success;
          if (data.requires_reenrollment) {
            var r = document.getElementById('resetDoneReenroll');
            r.textContent = strings.reenroll_note;
            r.style.display = 'block';
          }
          document.getElementById('resetDone').style.display = 'block';
        } else {
          btn.disabled = false;
          btn.textContent = <?php echo json_encode(t('auth.2fa_recovery.reset_button')); ?>;
          showError(data.message);
          if (passwordInput) { passwordInput.value = ''; passwordInput.focus(); }
        }
      })
      .catch(function() {
        btn.disabled = false;
        btn.textContent = <?php echo json_encode(t('auth.2fa_recovery.reset_button')); ?>;
        showError(strings.error);
      });
    });

    if (passwordInput) {
      passwordInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
      });
      passwordInput.focus();
    }
  })();
  </script>
</body>
</html>
