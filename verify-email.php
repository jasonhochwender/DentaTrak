<?php
/**
 * Email Verification Page
 * Handles the verification link from email
 */

require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

$token = $_GET['token'] ?? '';
$appName = $appConfig['appName'] ?? 'App';
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars(t('auth.verification.title')) . ' - ' . htmlspecialchars($appName); ?></title>

    <!-- Favicon / App Icons -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/login.css">
    <script>window.__i18n = <?php echo getTranslationsJsonForJs(); ?>;</script>
    <script src="js/i18n.js"></script>
    <style>
        .verify-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        .verify-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            max-width: 450px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .verify-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .verify-icon.loading {
            background: #e0e7ff;
            color: #4f46e5;
        }
        .verify-icon.success {
            background: #d1fae5;
            color: #059669;
        }
        .verify-icon.error {
            background: #fee2e2;
            color: #dc2626;
        }
        .verify-icon svg {
            width: 40px;
            height: 40px;
        }
        .verify-title {
            font-size: 24px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 12px;
        }
        .verify-message {
            color: #64748b;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .verify-btn {
            display: inline-block;
            padding: 12px 32px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .verify-btn:hover {
            background: #2563eb;
        }
        .verify-btn.secondary {
            background: #e2e8f0;
            color: #475569;
        }
        .verify-btn.secondary:hover {
            background: #cbd5e1;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e0e7ff;
            border-top-color: #4f46e5;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .resend-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        .resend-link {
            color: #3b82f6;
            cursor: pointer;
            text-decoration: underline;
        }
        .resend-link:hover {
            color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-card" style="position: relative;">
            <?php
            // Global language selector (hidden until a second locale is enabled)
            echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false);
            ?>
            <div class="verify-icon loading" id="verifyIcon">
                <div class="spinner"></div>
            </div>
            <h1 class="verify-title" id="verifyTitle"><?php echo htmlspecialchars(t('auth.verification.verifying')); ?></h1>
            <p class="verify-message" id="verifyMessage"><?php echo htmlspecialchars(t('auth.verification.wait_message')); ?></p>
            <div id="verifyActions" style="display: none;">
                <a href="login.php" class="verify-btn"><?php echo htmlspecialchars(t('auth.verification.sign_in')); ?></a>
            </div>
            <div id="resendSection" class="resend-section" style="display: none;">
                <p><?php echo htmlspecialchars(t('auth.verification.resend_prompt')); ?> <span class="resend-link" id="resendLink"><?php echo htmlspecialchars(t('auth.verification.resend_action')); ?></span></p>
            </div>
        </div>
    </div>

    <script>
        const token = '<?php echo htmlspecialchars($token); ?>';
        const verifyIcon = document.getElementById('verifyIcon');
        const verifyTitle = document.getElementById('verifyTitle');
        const verifyMessage = document.getElementById('verifyMessage');
        const verifyActions = document.getElementById('verifyActions');
        const resendSection = document.getElementById('resendSection');
        
        function showSuccess(message) {
            verifyIcon.className = 'verify-icon success';
            verifyIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
            verifyTitle.textContent = t('auth.verification.success_title');
            verifyMessage.textContent = message || t('auth.verification.success_message');
            verifyActions.style.display = 'block';
        }
        
        function showError(message, showResend = false) {
            verifyIcon.className = 'verify-icon error';
            verifyIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
            verifyTitle.textContent = t('auth.verification.failed_title');
            verifyMessage.textContent = message || t('auth.verification.generic_error');
            verifyActions.innerHTML = '<a href="login.php" class="verify-btn secondary">' + t('auth.verification.back_to_sign_in') + '</a>';
            verifyActions.style.display = 'block';
            
            if (showResend) {
                resendSection.style.display = 'block';
            }
        }
        
        function showAlreadyVerified() {
            verifyIcon.className = 'verify-icon success';
            verifyIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
            verifyTitle.textContent = t('auth.verification.already_verified_title');
            verifyMessage.textContent = t('auth.verification.already_verified_message');
            verifyActions.style.display = 'block';
        }
        
        // Verify the token
        if (!token) {
            showError(t('auth.verification.no_token'));
        } else {
            fetch('api/email-verification.php?action=verify&token=' + encodeURIComponent(token))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess(data.message);
                    } else if (data.already_verified) {
                        showAlreadyVerified();
                    } else if (data.expired) {
                        showError(data.message, true);
                    } else {
                        showError(data.message);
                    }
                })
                .catch(error => {
                    showError(t('auth.verification.generic_error'));
                });
        }
        
        // Handle resend
        document.getElementById('resendLink').addEventListener('click', function() {
            const email = prompt(t('auth.verification.resend_email_prompt'));
            if (email) {
                fetch('api/email-verification.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'resend', email: email })
                })
                .then(response => response.json())
                .then(data => {
                    alert(data.message);
                })
                .catch(error => {
                    alert(t('auth.verification.resend_failed'));
                });
            }
        });
    </script>
</body>
</html>
