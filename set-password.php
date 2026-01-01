<?php
/**
 * Set Password Page
 * 
 * Allows Google-only users to set up a password after email verification.
 * Accessed via a secure token link sent to their email.
 */

require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/unified-identity.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

// Get token from URL
$token = $_GET['token'] ?? '';
$tokenValid = false;
$tokenError = '';
$userEmail = '';
$firstName = '';

if (!empty($token)) {
    $validation = validatePasswordSetupToken($token);
    if ($validation['success']) {
        $tokenValid = true;
        $userEmail = $validation['email'] ?? '';
        $firstName = $validation['first_name'] ?? '';
    } else {
        $tokenError = $validation['message'] ?? 'Invalid or expired link';
    }
}

$appName = $appConfig['appName'] ?? 'DentalFlow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Password - <?php echo htmlspecialchars($appName); ?></title>
    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-body">
    <div class="login-bg-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>
    
    <div class="login-wrapper" style="justify-content: center;">
        <div class="login-container" style="max-width: 480px;">
            <div class="login-card">
                <div class="login-card-header">
                    <h2>Set Your Password</h2>
                    <?php if ($tokenValid): ?>
                        <p>Create a password for <?php echo htmlspecialchars($userEmail); ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if (!$tokenValid): ?>
                    <div class="reset-error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <p><?php echo htmlspecialchars($tokenError); ?></p>
                    </div>
                    <div class="reset-form-footer" style="text-align: center; margin-top: 20px;">
                        <a href="index.php">← Back to Sign In</a>
                    </div>
                <?php else: ?>
                    <form id="setPasswordForm" class="email-form">
                        <input type="hidden" id="token" name="token" value="<?php echo htmlspecialchars($token); ?>">
                        
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <input type="password" id="password" name="password" required placeholder="Create a password" autocomplete="new-password">
                            <div class="password-requirements">
                                <span class="req" id="reqLength">✗ At least 8 characters</span>
                                <span class="req" id="reqUpper">✗ One uppercase letter</span>
                                <span class="req" id="reqNumber">✗ One number</span>
                                <span class="req" id="reqSpecial">✗ One special character</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirmPassword">Confirm Password</label>
                            <input type="password" id="confirmPassword" name="confirmPassword" required placeholder="Confirm your password" autocomplete="new-password">
                            <div id="passwordMatch" class="password-match"></div>
                        </div>
                        
                        <div id="formError" class="form-error" style="display: none;"></div>
                        
                        <button type="submit" class="email-submit-btn" id="submitBtn" disabled>Set Password</button>
                    </form>
                    
                    <div id="successMessage" style="display: none;">
                        <div class="reset-success">
                            <div class="success-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                            <h3>Password Set Successfully!</h3>
                            <p>You can now sign in with your email and password.</p>
                        </div>
                        <a href="index.php" class="email-submit-btn" style="display: block; text-align: center; text-decoration: none; margin-top: 20px;">Sign In</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('setPasswordForm');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const submitBtn = document.getElementById('submitBtn');
        const formError = document.getElementById('formError');
        const successMessage = document.getElementById('successMessage');
        
        const reqLength = document.getElementById('reqLength');
        const reqUpper = document.getElementById('reqUpper');
        const reqNumber = document.getElementById('reqNumber');
        const reqSpecial = document.getElementById('reqSpecial');
        const passwordMatch = document.getElementById('passwordMatch');
        
        if (!form) return;
        
        function validatePassword() {
            const pwd = password.value;
            let allValid = true;
            
            if (pwd.length >= 8) {
                reqLength.textContent = '✓ At least 8 characters';
                reqLength.classList.add('valid');
            } else {
                reqLength.textContent = '✗ At least 8 characters';
                reqLength.classList.remove('valid');
                allValid = false;
            }
            
            if (/[A-Z]/.test(pwd)) {
                reqUpper.textContent = '✓ One uppercase letter';
                reqUpper.classList.add('valid');
            } else {
                reqUpper.textContent = '✗ One uppercase letter';
                reqUpper.classList.remove('valid');
                allValid = false;
            }
            
            if (/[0-9]/.test(pwd)) {
                reqNumber.textContent = '✓ One number';
                reqNumber.classList.add('valid');
            } else {
                reqNumber.textContent = '✗ One number';
                reqNumber.classList.remove('valid');
                allValid = false;
            }
            
            if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pwd)) {
                reqSpecial.textContent = '✓ One special character';
                reqSpecial.classList.add('valid');
            } else {
                reqSpecial.textContent = '✗ One special character';
                reqSpecial.classList.remove('valid');
                allValid = false;
            }
            
            return allValid;
        }
        
        function checkMatch() {
            const pwd = password.value;
            const confirm = confirmPassword.value;
            
            if (confirm.length === 0) {
                passwordMatch.textContent = '';
                passwordMatch.className = 'password-match';
                return false;
            }
            
            if (pwd === confirm) {
                passwordMatch.textContent = '✓ Passwords match';
                passwordMatch.className = 'password-match match';
                return true;
            } else {
                passwordMatch.textContent = '✗ Passwords do not match';
                passwordMatch.className = 'password-match no-match';
                return false;
            }
        }
        
        function updateButton() {
            submitBtn.disabled = !(validatePassword() && checkMatch());
        }
        
        password.addEventListener('input', function() {
            validatePassword();
            checkMatch();
            updateButton();
        });
        
        confirmPassword.addEventListener('input', function() {
            checkMatch();
            updateButton();
        });
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const token = document.getElementById('token').value;
            const pwd = password.value;
            
            formError.style.display = 'none';
            submitBtn.disabled = true;
            submitBtn.textContent = 'Setting password...';
            
            fetch('api/request-password-setup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'complete',
                    token: token,
                    password: pwd
                }),
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    form.style.display = 'none';
                    successMessage.style.display = 'block';
                } else {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Set Password';
                    formError.textContent = data.message || 'Failed to set password';
                    formError.style.display = 'block';
                }
            })
            .catch(error => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Set Password';
                formError.textContent = 'An error occurred. Please try again.';
                formError.style.display = 'block';
            });
        });
    });
    </script>
</body>
</html>
