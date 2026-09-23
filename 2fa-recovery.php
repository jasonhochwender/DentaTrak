<?php
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

$appName = $appConfig['appName'];
$currentEnv = $appConfig['current_environment'] ?? 'production';
$envClass = $currentEnv === 'production' ? 'env-prod' : ($currentEnv === 'uat' ? 'env-uat' : 'env-dev');
$csrfToken = generateCsrfToken();

// A mid-challenge session already identifies the account - the request form
// confirms the email on file instead of asking for one (and the endpoint
// ignores any emailed address for pending sessions).
$pendingUserId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
$pendingEmail = (string)($_SESSION['pending_2fa_email'] ?? '');
if ($pendingUserId && $pendingEmail === '') {
    try {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
        $stmt->execute(['id' => $pendingUserId]);
        $pendingEmail = (string)($stmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $pendingEmail = '';
    }
}

$pageStrings = [
    'request_sent' => t('auth.2fa_recovery.request_sent'),
    'error' => t('auth.2fa_recovery.error'),
];
?><!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
  <title><?php echo htmlspecialchars(t('auth.2fa_recovery.title')) . ' - ' . htmlspecialchars($appName); ?></title>
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
      <h2><?php echo htmlspecialchars(t('auth.2fa_recovery.title')); ?></h2>
      <p><?php echo htmlspecialchars(t('auth.2fa_recovery.subtitle')); ?></p>
    </div>

    <div id="recoveryRequestForm">
      <?php if ($pendingUserId && $pendingEmail !== ''): ?>
        <p class="form-description">
          <?php echo htmlspecialchars(t('auth.2fa_recovery.pending_intro', ['email' => $pendingEmail])); ?>
        </p>
        <input type="hidden" id="recoveryEmail" value="">
      <?php else: ?>
        <p class="form-description"><?php echo htmlspecialchars(t('auth.2fa_recovery.intro')); ?></p>
        <div class="form-group">
          <label for="recoveryEmail"><?php echo htmlspecialchars(t('auth.2fa_recovery.email_label')); ?></label>
          <input type="email" id="recoveryEmail" autocomplete="email" required
                 placeholder="<?php echo htmlspecialchars(t('auth.2fa_recovery.email_placeholder')); ?>">
        </div>
      <?php endif; ?>
      <div id="recoveryError" class="form-error" style="display: none;" role="alert"></div>
      <button type="button" id="recoverySubmitBtn" class="email-submit-btn">
        <?php echo htmlspecialchars(t('auth.2fa_recovery.send_link')); ?>
      </button>
    </div>

    <div id="recoverySent" style="display: none;">
      <p class="form-description"><?php echo htmlspecialchars(t('auth.2fa_recovery.request_sent')); ?></p>
    </div>

    <div class="email-form-footer">
      <a href="login.php" class="link-btn">← <?php echo htmlspecialchars(t('auth.login.back_to_login')); ?></a>
    </div>
  </div>

  <script>
  (function() {
    var strings = <?php echo json_encode($pageStrings, JSON_UNESCAPED_SLASHES); ?>;
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var btn = document.getElementById('recoverySubmitBtn');
    var emailInput = document.getElementById('recoveryEmail');
    var errorBox = document.getElementById('recoveryError');

    function showError(msg) {
      errorBox.textContent = msg || strings.error;
      errorBox.style.display = 'block';
    }

    btn.addEventListener('click', function() {
      var email = emailInput ? emailInput.value.trim() : '';
      errorBox.style.display = 'none';
      btn.disabled = true;
      fetch('api/2fa-recovery.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'request', email: email, csrf_token: csrfToken })
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          document.getElementById('recoveryRequestForm').style.display = 'none';
          document.getElementById('recoverySent').style.display = 'block';
        } else {
          btn.disabled = false;
          showError(data.message);
        }
      })
      .catch(function() {
        btn.disabled = false;
        showError(strings.error);
      });
    });

    if (emailInput && !emailInput.value) {
      emailInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
      });
      emailInput.focus();
    }
  })();
  </script>
</body>
</html>
