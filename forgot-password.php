<?php
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

$appName = $appConfig['appName'];
$currentEnv = $appConfig['current_environment'] ?? 'production';
if ($currentEnv === 'production') {
    $envClass = 'env-prod';
} elseif ($currentEnv === 'uat') {
    $envClass = 'env-uat';
} else {
    $envClass = 'env-dev';
}
?><!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?php echo htmlspecialchars(t('auth.password.forgot_title')) . ' - ' . htmlspecialchars($appName); ?></title>

  <!-- Favicon / App Icons -->
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
  <!-- Animated background elements -->
  <div class="login-bg-shapes">
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>
    <div class="shape shape-4"></div>
    <div class="shape shape-5"></div>
  </div>
  
  <div class="reset-password-container">
    <?php
    // Global language selector (hidden until a second locale is enabled)
    echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false);
    ?>
    <div class="reset-password-header">
      <div class="icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </div>
      <h2><?php echo htmlspecialchars(t('auth.password.forgot_title')); ?></h2>
      <p><?php echo htmlspecialchars(t('auth.password.forgot_subtitle')); ?></p>
    </div>
    
    <div id="requestForm">
      <form id="forgotPasswordForm" class="reset-form">
        <div class="form-group">
          <label for="email"><?php echo htmlspecialchars(t('auth.password.email_label')); ?></label>
          <input type="email" id="email" name="email" required placeholder="<?php echo htmlspecialchars(t('auth.password.email_placeholder')); ?>" autofocus>
        </div>
        <div id="formError" class="reset-error" style="display: none;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <p id="errorMessage"></p>
        </div>
        <button type="submit" class="reset-submit-btn" id="submitBtn"><?php echo htmlspecialchars(t('auth.password.send_reset_link')); ?></button>
      </form>
    </div>
    
    <div id="successMessage" class="reset-success" style="display: none;">
      <div class="success-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
          <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
      </div>
      <h3><?php echo htmlspecialchars(t('auth.password.success_title')); ?></h3>
      <p><?php echo htmlspecialchars(t('auth.password.success_message')); ?></p>
    </div>
    
    <div class="reset-form-footer">
      <a href="login.php">← <?php echo htmlspecialchars(t('auth.password.back_to_sign_in')); ?></a>
    </div>
  </div>

  <script>
    document.getElementById('forgotPasswordForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const email = document.getElementById('email').value;
      const submitBtn = document.getElementById('submitBtn');
      const formError = document.getElementById('formError');
      const errorMessage = document.getElementById('errorMessage');
      
      // Disable button and show loading state
      submitBtn.disabled = true;
      submitBtn.textContent = t('auth.password.sending');
      formError.style.display = 'none';
      
      try {
        const response = await fetch('api/password-reset.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'request',
            email: email
          }),
          credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
          // Show success message
          document.getElementById('requestForm').style.display = 'none';
          document.getElementById('successMessage').style.display = 'flex';
        } else {
          errorMessage.textContent = data.message || t('auth.errors.generic');
          formError.style.display = 'flex';
          submitBtn.disabled = false;
          submitBtn.textContent = t('auth.password.send_reset_link');
        }
      } catch (error) {
        errorMessage.textContent = t('auth.errors.generic');
        formError.style.display = 'flex';
        submitBtn.disabled = false;
        submitBtn.textContent = t('auth.password.send_reset_link');
      }
    });
  </script>
</body>
</html>
