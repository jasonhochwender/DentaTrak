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
$loginUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars(t('auth.set_password.title')) . ' - ' . htmlspecialchars($appName); ?></title>

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
                    <h2><?php echo t('auth.set_password.title'); ?></h2>
                    <?php if ($tokenValid): ?>
                        <p><?php echo t('auth.set_password.create_for', ['email' => htmlspecialchars($userEmail)]); ?></p>
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
                        <a href="<?php echo htmlspecialchars($loginUrl); ?>">← <?php echo t('common.back_to_sign_in'); ?></a>
                    </div>
                <?php else: ?>
                    <form id="setPasswordForm" class="email-form">
                        <input type="hidden" id="token" name="token" value="<?php echo htmlspecialchars($token); ?>">
                        
                        <div class="form-group">
                            <label for="password"><?php echo t('auth.set_password.new_password_label'); ?></label>
                            <input type="password" id="password" name="password" required placeholder="<?php echo t('auth.set_password.new_password_placeholder'); ?>" autocomplete="new-password">
                            <div class="password-requirements">
                                <span class="req" id="reqLength">✗ <?php echo t('auth.set_password.requirements_length'); ?></span>
                                <span class="req" id="reqUpper">✗ <?php echo t('auth.set_password.requirements_upper'); ?></span>
                                <span class="req" id="reqNumber">✗ <?php echo t('auth.set_password.requirements_number'); ?></span>
                                <span class="req" id="reqSpecial">✗ <?php echo t('auth.set_password.requirements_special'); ?></span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirmPassword"><?php echo t('auth.set_password.confirm_password_label'); ?></label>
                            <input type="password" id="confirmPassword" name="confirmPassword" required placeholder="<?php echo t('auth.set_password.confirm_password_placeholder'); ?>" autocomplete="new-password">
                            <div id="passwordMatch" class="password-match"></div>
                        </div>
                        
                        <div id="formError" class="form-error" style="display: none;"></div>
                        
                        <button type="submit" class="email-submit-btn" id="submitBtn" disabled><?php echo t('auth.set_password.set_button'); ?></button>
                    </form>
                    
                    <div id="successMessage" style="display: none;">
                        <div class="reset-success">
                            <div class="success-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                            <h3><?php echo t('auth.set_password.success_title'); ?></h3>
                            <p><?php echo t('auth.set_password.success_message'); ?></p>
                        </div>
                        <a href="<?php echo htmlspecialchars($loginUrl); ?>" class="email-submit-btn" style="display: block; text-align: center; text-decoration: none; margin-top: 20px;"><?php echo t('auth.set_password.sign_in'); ?></a>
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
                reqLength.textContent = '✓ ' + t('auth.set_password.requirements_length');
                reqLength.classList.add('valid');
            } else {
                reqLength.textContent = '✗ ' + t('auth.set_password.requirements_length');
                reqLength.classList.remove('valid');
                allValid = false;
            }
            
            if (/[A-Z]/.test(pwd)) {
                reqUpper.textContent = '✓ ' + t('auth.set_password.requirements_upper');
                reqUpper.classList.add('valid');
            } else {
                reqUpper.textContent = '✗ ' + t('auth.set_password.requirements_upper');
                reqUpper.classList.remove('valid');
                allValid = false;
            }
            
            if (/[0-9]/.test(pwd)) {
                reqNumber.textContent = '✓ ' + t('auth.set_password.requirements_number');
                reqNumber.classList.add('valid');
            } else {
                reqNumber.textContent = '✗ ' + t('auth.set_password.requirements_number');
                reqNumber.classList.remove('valid');
                allValid = false;
            }
            
            if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pwd)) {
                reqSpecial.textContent = '✓ ' + t('auth.set_password.requirements_special');
                reqSpecial.classList.add('valid');
            } else {
                reqSpecial.textContent = '✗ ' + t('auth.set_password.requirements_special');
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
                passwordMatch.textContent = '✓ ' + t('auth.set_password.match_yes');
                passwordMatch.className = 'password-match match';
                return true;
            } else {
                passwordMatch.textContent = '✗ ' + t('auth.set_password.match_no');
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
            submitBtn.textContent = t('auth.set_password.setting');
            
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
                    submitBtn.textContent = t('auth.set_password.set_button');
                    formError.textContent = data.message || t('auth.set_password.error');
                    formError.style.display = 'block';
                }
            })
            .catch(error => {
                submitBtn.disabled = false;
                submitBtn.textContent = t('auth.set_password.set_button');
                formError.textContent = t('auth.set_password.unknown_error');
                formError.style.display = 'block';
            });
        });
    });
    </script>
</body>
</html>
