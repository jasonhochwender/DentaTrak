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

$token = $_GET['token'] ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - <?php echo htmlspecialchars($appName); ?></title>
  <link rel="stylesheet" href="css/app.css">
  <link rel="stylesheet" href="css/login.css">
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
    <div class="reset-password-header">
      <div class="icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
        </svg>
      </div>
      <h2>Reset Your Password</h2>
      <p id="headerText">Create a new password for your account.</p>
    </div>
    
    <!-- Loading state while validating token -->
    <div id="loadingState" style="text-align: center; padding: 20px;">
      <p style="color: #64748b;">Validating reset link...</p>
    </div>
    
    <!-- Invalid token message -->
    <div id="invalidToken" style="display: none;">
      <div class="reset-error">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <p id="invalidTokenMessage">This password reset link is invalid or has expired.</p>
      </div>
      <div style="text-align: center; margin-top: 20px;">
        <a href="forgot-password.php" class="reset-submit-btn" style="display: inline-block; text-decoration: none;">Request New Reset Link</a>
      </div>
    </div>
    
    <!-- Reset form -->
    <div id="resetForm" style="display: none;">
      <form id="passwordResetForm" class="reset-form">
        <input type="hidden" id="token" value="<?php echo htmlspecialchars($token); ?>">
        
        <div class="form-group">
          <label for="password">New Password</label>
          <input type="password" id="password" name="password" required placeholder="Enter new password">
          <div class="password-requirements">
            <span class="req" id="reqLength">✗ At least 8 characters</span>
            <span class="req" id="reqUpper">✗ One uppercase letter</span>
            <span class="req" id="reqNumber">✗ One number</span>
            <span class="req" id="reqSpecial">✗ One special character</span>
          </div>
        </div>
        
        <div class="form-group">
          <label for="confirmPassword">Confirm New Password</label>
          <input type="password" id="confirmPassword" name="confirmPassword" required placeholder="Confirm new password">
          <div id="passwordMatch" class="password-match"></div>
        </div>
        
        <div id="formError" class="reset-error" style="display: none;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <p id="errorMessage"></p>
        </div>
        
        <button type="submit" class="reset-submit-btn" id="submitBtn" disabled>Reset Password</button>
      </form>
    </div>
    
    <!-- Success message -->
    <div id="successMessage" class="reset-success" style="display: none;">
      <div class="success-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
          <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
      </div>
      <h3>Password Reset Successfully!</h3>
      <p>Your password has been updated. You can now sign in with your new password.</p>
      <div style="margin-top: 20px;">
        <a href="index.php" class="reset-submit-btn" style="display: inline-block; text-decoration: none;">Sign In</a>
      </div>
    </div>
    
    <div class="reset-form-footer" id="backLink">
      <a href="index.php">← Back to Sign In</a>
    </div>
  </div>

  <script>
    const token = document.getElementById('token').value;
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const submitBtn = document.getElementById('submitBtn');
    
    // Password validation
    function validatePassword(password) {
      const requirements = {
        length: password.length >= 8,
        upper: /[A-Z]/.test(password),
        number: /[0-9]/.test(password),
        special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
      };
      
      document.getElementById('reqLength').className = 'req' + (requirements.length ? ' valid' : '');
      document.getElementById('reqLength').textContent = (requirements.length ? '✓' : '✗') + ' At least 8 characters';
      
      document.getElementById('reqUpper').className = 'req' + (requirements.upper ? ' valid' : '');
      document.getElementById('reqUpper').textContent = (requirements.upper ? '✓' : '✗') + ' One uppercase letter';
      
      document.getElementById('reqNumber').className = 'req' + (requirements.number ? ' valid' : '');
      document.getElementById('reqNumber').textContent = (requirements.number ? '✓' : '✗') + ' One number';
      
      document.getElementById('reqSpecial').className = 'req' + (requirements.special ? ' valid' : '');
      document.getElementById('reqSpecial').textContent = (requirements.special ? '✓' : '✗') + ' One special character';
      
      return requirements.length && requirements.upper && requirements.number && requirements.special;
    }
    
    function checkPasswordMatch() {
      const password = passwordInput.value;
      const confirmPassword = confirmPasswordInput.value;
      const matchDiv = document.getElementById('passwordMatch');
      
      if (confirmPassword === '') {
        matchDiv.textContent = '';
        matchDiv.className = 'password-match';
        return false;
      }
      
      if (password === confirmPassword) {
        matchDiv.textContent = '✓ Passwords match';
        matchDiv.className = 'password-match match';
        return true;
      } else {
        matchDiv.textContent = '✗ Passwords do not match';
        matchDiv.className = 'password-match no-match';
        return false;
      }
    }
    
    function updateSubmitButton() {
      const isPasswordValid = validatePassword(passwordInput.value);
      const doPasswordsMatch = checkPasswordMatch();
      submitBtn.disabled = !(isPasswordValid && doPasswordsMatch);
    }
    
    passwordInput.addEventListener('input', updateSubmitButton);
    confirmPasswordInput.addEventListener('input', updateSubmitButton);
    
    // Validate token on page load
    async function validateToken() {
      if (!token) {
        showInvalidToken('No reset token provided.');
        return;
      }
      
      try {
        const response = await fetch('api/password-reset.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'validate',
            token: token
          }),
          credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        document.getElementById('loadingState').style.display = 'none';
        
        if (data.success && data.valid) {
          document.getElementById('resetForm').style.display = 'block';
        } else {
          showInvalidToken(data.message || 'This password reset link is invalid or has expired.');
        }
      } catch (error) {
        showInvalidToken('An error occurred while validating the reset link.');
      }
    }
    
    function showInvalidToken(message) {
      document.getElementById('loadingState').style.display = 'none';
      document.getElementById('invalidTokenMessage').textContent = message;
      document.getElementById('invalidToken').style.display = 'block';
    }
    
    // Handle form submission
    document.getElementById('passwordResetForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      
      const password = passwordInput.value;
      const confirmPassword = confirmPasswordInput.value;
      const formError = document.getElementById('formError');
      const errorMessage = document.getElementById('errorMessage');
      
      submitBtn.disabled = true;
      submitBtn.textContent = 'Resetting...';
      formError.style.display = 'none';
      
      try {
        const response = await fetch('api/password-reset.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'reset',
            token: token,
            password: password,
            confirmPassword: confirmPassword
          }),
          credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
          document.getElementById('resetForm').style.display = 'none';
          document.getElementById('backLink').style.display = 'none';
          document.getElementById('successMessage').style.display = 'flex';
          document.querySelector('.reset-password-header').style.display = 'none';
        } else {
          let errorText = data.message || 'An error occurred. Please try again.';
          if (data.errors && data.errors.length > 0) {
            errorText = data.errors.join('. ');
          }
          errorMessage.textContent = errorText;
          formError.style.display = 'flex';
          submitBtn.disabled = false;
          submitBtn.textContent = 'Reset Password';
          updateSubmitButton();
        }
      } catch (error) {
        errorMessage.textContent = 'An error occurred. Please try again.';
        formError.style.display = 'flex';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Reset Password';
        updateSubmitButton();
      }
    });
    
    // Validate token when page loads
    validateToken();
  </script>
</body>
</html>
