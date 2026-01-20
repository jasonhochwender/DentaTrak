<?php
// Load bootstrap to set environment variables early
require_once __DIR__ . '/api/bootstrap.php';

// Suppress deprecation notices
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Load appConfig FIRST to establish database connection
require_once __DIR__ . '/api/appConfig.php';
// Then load session
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

// ============================================
// REMEMBER ME AUTO-LOGIN
// Attempt to auto-login user via persistent token cookie
// Must be called AFTER appConfig.php and session.php are loaded
// ============================================
if (function_exists('attemptRememberMeLogin')) {
    attemptRememberMeLogin();
}

// ============================================
// REDIRECT IF ALREADY LOGGED IN
// Security: Includes users auto-logged in via Remember Me token
// ============================================
if (!empty($_SESSION['db_user_id'])) {
    header('Location: main.php');
    exit;
}

// Get client ID for Google Sign-In
$googleClientId = $appConfig['google_client_id'];

// Check for authentication errors
$authError = isset($_GET['auth_error']) ? htmlspecialchars($_GET['auth_error']) : null;

// Check for session timeout
$sessionTimeout = isset($_GET['timeout']) && $_GET['timeout'] == '1';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  
  <!-- Google Analytics -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-MBJDENR3H2"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'G-MBJDENR3H2');
  </script>
  
  <title><?php echo htmlspecialchars($appConfig['appName']); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/app.css">
  <link rel="stylesheet" href="css/login.css">
  
  <!-- No external Google libraries needed for server-side OAuth flow -->
  <script src="js/app.js"></script>
</head>
<?php 
  // Determine environment for visual cues
  $currentEnv = $appConfig['current_environment'] ?? 'production';
  if ($currentEnv === 'production') {
      $envClass = 'env-prod';
  } elseif ($currentEnv === 'uat') {
      $envClass = 'env-uat';
  } else {
      $envClass = 'env-dev';
  }
  $appName = $appConfig['appName'];
  $hasLogo = isset($appConfig['logo']) && !empty($appConfig['logo']);
?>
<body class="login-body <?php echo $envClass; ?>">
  <!-- Animated background elements -->
  <div class="login-bg-shapes">
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>
    <div class="shape shape-4"></div>
    <div class="shape shape-5"></div>
  </div>
  
  <div class="login-wrapper">
    <!-- Left side - Branding & Features -->
    <div class="login-hero">
      <div class="hero-content">
        <?php if ($hasLogo): ?>
          <img src="<?php echo htmlspecialchars($appConfig['logo']); ?>" alt="Logo" class="hero-logo">
        <?php else: ?>
          <div class="hero-icon">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 2C8.5 2 6 4 6 7c0 2 .5 3.5 1 5 .5 1.5 1 3 1 5 0 3 1.5 5 4 5s4-2 4-5c0-2 .5-3.5 1-5s1-3 1-5c0-3-2.5-5-6-5z"/>
              <path d="M9 7h6"/>
            </svg>
          </div>
        <?php endif; ?>
        
        <h1 class="hero-title"><?php echo htmlspecialchars($appName); ?></h1>
        <p class="hero-tagline">Case visibility for complex dental work.</p>
        
        <div class="hero-features">
          <div class="feature-item">
            <div class="feature-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
              </svg>
            </div>
            <div class="feature-text">
              <strong>Prevent lost or forgotten cases</strong>
            </div>
          </div>
          
          <div class="feature-item">
            <div class="feature-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </div>
            <div class="feature-text">
              <strong>Visibility across lab, referral, and chair</strong>
            </div>
          </div>
        </div>
      </div>
      
      <div class="hero-footer">
        <div class="trust-badges">
          <span class="trust-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
            HIPAA Compliant
          </span>
        </div>
      </div>
    </div>
    
    <!-- Right side - Sign In Form -->
    <div class="login-container">
      <div class="login-card">
        <div class="login-card-header">
          <h2>Welcome Back</h2>
          <p>Sign in to continue managing your cases</p>
          <p class="invited-only-note">Dentatrak is currently available to invited practices only.</p>
        </div>
        
        <?php if ($sessionTimeout): ?>
          <div class="auth-error" style="background: #fef3c7; border-color: #fcd34d; color: #92400e;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>
            <p>Your session has expired due to inactivity. Please sign in again.</p>
          </div>
        <?php elseif ($authError): ?>
          <div class="auth-error">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/>
              <line x1="12" y1="8" x2="12" y2="12"/>
              <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p><?php echo $authError; ?></p>
          </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['oauth_status']) && $_GET['oauth_status'] === 'initiated'): ?>
          <div class="auth-status">
            <div class="auth-status-spinner"></div>
            <p>Redirecting to Google Sign-In...</p>
          </div>
        <?php endif; ?>
        
        <div class="google-signin-wrapper">
          <a href="api/oauth-start.php" class="google-signin-btn" id="googleSignInBtn">
            <img src="images/google-logo.svg" alt="Google logo" class="google-logo">
            <span>Continue with Google</span>
          </a>
        </div>
        
        <div class="login-divider">
          <span>or</span>
        </div>
        
        <!-- Progressive Email Authentication -->
        <div class="email-signin-toggle">
          <button type="button" id="showEmailSignIn" class="email-toggle-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            <span>Sign in with Email</span>
          </button>
        </div>
        
        <!-- Step 1: Email Entry -->
        <div id="emailEntryForm" class="email-signin-form" style="display: none;">
          <form id="emailCheckForm" class="email-form">
            <div class="form-group">
              <label for="checkEmail">Email</label>
              <input type="email" id="checkEmail" name="email" required placeholder="your@email.com" autocomplete="email">
            </div>
            <div id="emailCheckError" class="form-error" style="display: none;"></div>
            <button type="submit" class="email-submit-btn" id="emailContinueBtn">Continue</button>
          </form>
        </div>
        
        <!-- Step 2a: Password Login (for users with password auth) -->
        <div id="passwordLoginForm" class="email-signin-form" style="display: none;">
          <div class="user-greeting" id="loginGreeting"></div>
          <form id="emailLoginForm" class="email-form">
            <input type="hidden" id="loginEmail" name="email">
            <div class="form-group">
              <label for="loginPassword">Password</label>
              <div class="password-input-wrapper">
                <input type="password" id="loginPassword" name="password" required placeholder="Enter your password" autocomplete="current-password">
                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="loginPassword">
                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
            </div>
            <!-- Remember Me Checkbox -->
            <div class="remember-me-wrapper">
              <label class="remember-me-label">
                <input type="checkbox" id="rememberMe" name="rememberMe" class="remember-me-checkbox">
                <span class="remember-me-checkmark"></span>
                <span class="remember-me-text">Remember me</span>
              </label>
            </div>
            <div id="loginError" class="form-error" style="display: none;"></div>
            <button type="submit" class="email-submit-btn" id="loginSubmitBtn">Sign In</button>
            <div class="forgot-password-link">
              <a href="forgot-password.php" class="link-btn">Forgot your password?</a>
            </div>
          </form>
          
          <!-- Two-Factor Authentication Input (shown when 2FA is required) -->
          <div id="twoFactorLoginForm" class="two-factor-login" style="display: none;">
            <div class="two-factor-header">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
              <h3>Two-Factor Authentication</h3>
              <p>Enter the 6-digit code from your authenticator app</p>
            </div>
            <div class="two-factor-input-group">
              <input type="text" id="login2FACode" maxlength="6" pattern="[0-9]*" inputmode="numeric" placeholder="000000" autocomplete="one-time-code">
            </div>
            <div id="twoFactorLoginError" class="form-error" style="display: none;"></div>
            <button type="button" id="verify2FALoginBtn" class="email-submit-btn">Verify & Sign In</button>
            <button type="button" id="cancel2FALogin" class="link-btn" style="margin-top: 20px;">← Back to login</button>
          </div>
          <div class="email-form-footer">
            <button type="button" id="changeEmailBtn" class="link-btn">← Use a different email</button>
          </div>
        </div>
        
        <!-- Step 2b: Google Only Account -->
        <div id="googleOnlyForm" class="email-signin-form" style="display: none;">
          <div class="user-greeting" id="googleOnlyGreeting"></div>
          <div class="google-only-notice">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/>
              <line x1="12" y1="16" x2="12" y2="12"/>
              <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <p>This account uses Google Sign-In. Please continue with Google or set up a password.</p>
          </div>
          <div class="google-signin-wrapper">
            <a href="api/oauth-start.php" class="google-signin-btn" id="googleOnlySignInBtn">
              <img src="images/google-logo.svg" alt="Google logo" class="google-logo">
              <span>Continue with Google</span>
            </a>
          </div>
          <div class="or-divider"><span>or</span></div>
          <button type="button" id="setupPasswordBtn" class="email-toggle-btn secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <span>Set up a password for this account</span>
          </button>
          <div class="email-form-footer">
            <button type="button" id="changeEmailBtn2" class="link-btn">← Use a different email</button>
          </div>
        </div>
        
        <!-- Step 2c: New User Registration -->
        <div id="emailRegisterForm" class="email-signin-form" style="display: none;">
          <div class="user-greeting">Create your account</div>
          <form id="emailRegForm" class="email-form">
            <input type="hidden" id="regEmail" name="email">
            <div class="form-row">
              <div class="form-group half">
                <label for="regFirstName">First Name</label>
                <input type="text" id="regFirstName" name="firstName" placeholder="First name" autocomplete="given-name">
              </div>
              <div class="form-group half">
                <label for="regLastName">Last Name</label>
                <input type="text" id="regLastName" name="lastName" placeholder="Last name" autocomplete="family-name">
              </div>
            </div>
            <div class="form-group">
              <label for="regPassword">Password</label>
              <div class="password-input-wrapper">
                <input type="password" id="regPassword" name="password" required placeholder="Create a password" autocomplete="new-password">
                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="regPassword">
                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
              <div class="password-requirements">
                <span class="req" id="reqLength">✗ At least 8 characters</span>
                <span class="req" id="reqUpper">✗ One uppercase letter</span>
                <span class="req" id="reqNumber">✗ One number</span>
                <span class="req" id="reqSpecial">✗ One special character</span>
              </div>
            </div>
            <div class="form-group">
              <label for="regConfirmPassword">Confirm Password</label>
              <div class="password-input-wrapper">
                <input type="password" id="regConfirmPassword" name="confirmPassword" required placeholder="Confirm your password" autocomplete="new-password">
                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="regConfirmPassword">
                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
              <div id="passwordMatch" class="password-match"></div>
            </div>
            <div id="registerError" class="form-error" style="display: none;"></div>
            <button type="submit" class="email-submit-btn" id="registerBtn" disabled>Create Account</button>
          </form>
          <div class="email-form-footer">
            <button type="button" id="changeEmailBtn3" class="link-btn">← Use a different email</button>
          </div>
        </div>
        
        <!-- Password Setup Form (for Google-only users) -->
        <div id="passwordSetupForm" class="email-signin-form" style="display: none;">
          <div class="user-greeting">Set up your password</div>
          <div class="setup-notice">
            <p>We'll send a verification link to your email to confirm your identity.</p>
          </div>
          <form id="requestPasswordSetupForm" class="email-form">
            <input type="hidden" id="setupEmail" name="email">
            <div id="setupError" class="form-error" style="display: none;"></div>
            <button type="submit" class="email-submit-btn">Send Verification Link</button>
          </form>
          <div class="email-form-footer">
            <button type="button" id="backToGoogleOnly" class="link-btn">← Back</button>
          </div>
        </div>
        
        <!-- Immediate Password Setup (for verified Google users) -->
        <div id="immediatePasswordSetup" class="email-signin-form" style="display: none;">
          <div class="user-greeting">Set your password</div>
          <form id="immediateSetupForm" class="email-form">
            <input type="hidden" id="immediateSetupToken" name="token">
            <div class="form-group">
              <label for="newPassword">New Password</label>
              <div class="password-input-wrapper">
                <input type="password" id="newPassword" name="password" required placeholder="Create a password" autocomplete="new-password">
                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="newPassword">
                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
              <div class="password-requirements">
                <span class="req" id="newReqLength">✗ At least 8 characters</span>
                <span class="req" id="newReqUpper">✗ One uppercase letter</span>
                <span class="req" id="newReqNumber">✗ One number</span>
                <span class="req" id="newReqSpecial">✗ One special character</span>
              </div>
            </div>
            <div class="form-group">
              <label for="confirmNewPassword">Confirm Password</label>
              <div class="password-input-wrapper">
                <input type="password" id="confirmNewPassword" name="confirmPassword" required placeholder="Confirm your password" autocomplete="new-password">
                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="confirmNewPassword">
                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
              <div id="newPasswordMatch" class="password-match"></div>
            </div>
            <div id="immediateSetupError" class="form-error" style="display: none;"></div>
            <button type="submit" class="email-submit-btn" id="setPasswordBtn" disabled>Set Password</button>
          </form>
          <div class="email-form-footer">
            <button type="button" id="backFromImmediate" class="link-btn">← Back</button>
          </div>
        </div>
        
        <div class="login-benefits">
          <div class="benefit-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            <span>Prevent costly remakes</span>
          </div>
          <div class="benefit-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            <span>Free to start, no credit card</span>
          </div>
        </div>
        
        <p class="login-disclaimer">
          By signing in, you agree to our 
          <a href="#" id="privacyLink">Privacy Policy</a> and 
          <a href="#" id="termsLink">Terms of Use</a>
        </p>
      </div>
      
      <div class="login-copyright">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.
      </div>
    </div>
  </div>

  <!-- Privacy Policy Modal -->
  <div id="privacyModal" class="modal">
    <div class="modal-content">
      <button class="btn-close"><span>&times;</span></button>
      <div class="modal-header">
        <h2 class="modal-title"><?php echo htmlspecialchars($appName); ?> Privacy Policy</h2>
      </div>
      <div class="modal-body" id="privacyContent">
        <p><strong>Effective Date:</strong> December 22, 2025</p>
        
        <p>This Privacy Policy explains how <?php echo htmlspecialchars($appName); ?> ("we," "us," or "our") collects, uses, and protects your information when you use our dental case tracking service ("Service"). <?php echo htmlspecialchars($appName); ?> is a case management tool designed for dental practices.</p>
        
        <h4>1. Information We Collect</h4>
        <p>We collect the following types of information:</p>
        <ul>
          <li><strong>Account Information:</strong> Your name and email address, used to create and manage your account</li>
          <li><strong>Authentication Data:</strong> Information necessary to verify your identity when you sign in</li>
          <li><strong>Practice and Case Data:</strong> Information you and your team enter into the Service, including patient case details, notes, due dates, and related practice information</li>
        </ul>
        
        <h4>2. Google OAuth Disclosure</h4>
        <p><?php echo htmlspecialchars($appName); ?> uses Google OAuth solely for authentication purposes. When you sign in with Google:</p>
        <ul>
          <li>We access only your basic profile information (name and email address)</li>
          <li>We use this information only to identify your account and enable sign-in</li>
          <li>We do not sell, share, or transfer your Google user data to any third parties</li>
          <li>We do not use your Google data for advertising or any purpose unrelated to providing the Service</li>
        </ul>
        
        <h4>3. How We Use Your Information</h4>
        <p>We use your information to:</p>
        <ul>
          <li>Provide access to your account and the Service</li>
          <li>Enable your dental practice to track and manage cases</li>
          <li>Deliver the core functionality of the Service</li>
          <li>Communicate with you about your account or the Service</li>
        </ul>
        
        <h4>4. Data Protection</h4>
        <p>We take reasonable measures to protect your information, including:</p>
        <ul>
          <li>Encryption of data during transmission and storage</li>
          <li>Access controls to limit who can view your data</li>
          <li>Regular review of our security practices</li>
        </ul>
        
        <h4>5. Data Sharing</h4>
        <p>We do not sell your information. We share your data only:</p>
        <ul>
          <li>With service providers who help us operate the Service (under appropriate agreements)</li>
          <li>When required by law or to protect our rights</li>
          <li>With your consent</li>
        </ul>
        
        <h4>6. Your Rights</h4>
        <p>You may:</p>
        <ul>
          <li><strong>Access your data:</strong> Request a copy of the information we hold about you</li>
          <li><strong>Correct your data:</strong> Update inaccurate information in your account</li>
          <li><strong>Delete your data:</strong> Request deletion of your account and associated data</li>
          <li><strong>Revoke access:</strong> Remove <?php echo htmlspecialchars($appName); ?>'s access to your Google account at any time through your Google account settings</li>
        </ul>
        <p>To exercise these rights, contact us at <a href="mailto:privacy@dentatrak.com">privacy@dentatrak.com</a>.</p>
        
        <h4>7. Data Retention</h4>
        <p>We retain your data while your account is active. If you request account deletion, we will remove your data within 30 days, unless we are required to retain it for legal reasons.</p>
        
        <h4>8. Changes to This Policy</h4>
        <p>We may update this Privacy Policy from time to time. We will post changes on this page and update the effective date. Continued use of the Service after changes constitutes acceptance of the updated policy.</p>
        
        <h4>9. Contact Us</h4>
        <p>If you have questions about this Privacy Policy or our data practices, please contact us at:</p>
        <p><a href="mailto:privacy@dentatrak.com">privacy@dentatrak.com</a></p>
      </div>
      <div class="modal-footer">
        <button class="btn-primary modal-close-btn">Close</button>
      </div>
    </div>
  </div>

  <!-- Terms of Service Modal -->
  <div id="termsModal" class="modal">
    <div class="modal-content">
      <button class="btn-close"><span>&times;</span></button>
      <div class="modal-header">
        <h2 class="modal-title"><?php echo htmlspecialchars($appName); ?> Terms of Service</h2>
      </div>
      <div class="modal-body" id="termsContent">
        <p><strong>Effective Date:</strong> December 22, 2025</p>
        
        <p>These Terms of Service ("Terms") govern your use of <?php echo htmlspecialchars($appName); ?> ("Service"), a case tracking application provided to dental practices. By using the Service, you agree to these Terms.</p>
        
        <h4>1. Service Description</h4>
        <p><?php echo htmlspecialchars($appName); ?> is a case management tool that helps dental practices track and manage dental cases. The Service is intended for use by dental professionals and their authorized staff.</p>
        
        <h4>2. Account Security</h4>
        <p>You are responsible for:</p>
        <ul>
          <li>Keeping your login credentials secure</li>
          <li>All activity that occurs under your account</li>
          <li>Notifying us promptly if you suspect unauthorized access to your account</li>
          <li>Ensuring that only authorized personnel in your practice access the Service</li>
        </ul>
        
        <h4>3. Acceptable Use</h4>
        <p>You agree to use the Service only for its intended purpose of dental case management. You agree not to:</p>
        <ul>
          <li>Attempt to gain unauthorized access to the Service or its systems</li>
          <li>Use the Service for any unlawful purpose</li>
          <li>Interfere with or disrupt the Service</li>
          <li>Copy, modify, or distribute any part of the Service</li>
          <li>Use the Service in any way that could harm other users</li>
        </ul>
        
        <h4>4. Your Data</h4>
        <p>You retain ownership of all data you enter into the Service. You are responsible for the accuracy and legality of the information you store. You are also responsible for complying with all applicable laws regarding patient data and privacy.</p>
        
        <h4>5. Disclaimer of Warranties</h4>
        <p>The Service is provided "as is" without warranties of any kind, express or implied. We do not warrant that the Service will be uninterrupted, error-free, or completely secure. We do not provide legal, medical, or compliance advice.</p>
        
        <h4>6. Limitation of Liability</h4>
        <p>To the maximum extent permitted by law, <?php echo htmlspecialchars($appName); ?> and its operators shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of the Service. This includes, but is not limited to:</p>
        <ul>
          <li>Loss of data or unauthorized access to your data</li>
          <li>Service interruptions or downtime</li>
          <li>Your failure to comply with applicable laws or regulations</li>
          <li>Any decisions made based on information in the Service</li>
        </ul>
        <p>Our total liability for any claims related to the Service shall not exceed the amount you paid us, if any, in the twelve months preceding the claim.</p>
        
        <h4>7. Termination</h4>
        <p>We may suspend or terminate your access to the Service at any time, with or without cause. You may also stop using the Service at any time. Upon termination:</p>
        <ul>
          <li>Your right to use the Service ends immediately</li>
          <li>You may request export of your data within 30 days</li>
          <li>We may delete your data after 30 days</li>
        </ul>
        
        <h4>8. Changes to Terms</h4>
        <p>We may update these Terms from time to time. We will post changes on this page and update the effective date. Continued use of the Service after changes constitutes acceptance of the updated Terms.</p>
        
        <h4>9. Governing Law</h4>
        <p>These Terms are governed by the laws of the United States. Any disputes shall be resolved in accordance with applicable federal and state law.</p>
        
        <h4>10. Contact Us</h4>
        <p>If you have questions about these Terms, please contact us at:</p>
        <p><a href="mailto:support@dentatrak.com">support@dentatrak.com</a></p>
      </div>
      <div class="modal-footer">
        <button class="btn-primary modal-close-btn">Close</button>
      </div>
    </div>
  </div>
  
</div>

<script src="js/app.js"></script>
<script>
// Progressive Email Authentication JavaScript
document.addEventListener('DOMContentLoaded', function() {
  
  // ============================================
  // PASSWORD VISIBILITY TOGGLE
  // Toggles password field between masked and visible states
  // Security: Never logs or stores password values
  // ============================================
  function initPasswordToggle() {
    const toggleButtons = document.querySelectorAll('.password-toggle-btn');
    
    toggleButtons.forEach(function(btn) {
      btn.addEventListener('click', function() {
        const targetId = this.getAttribute('data-target');
        const passwordInput = document.getElementById(targetId);
        
        if (!passwordInput) return;
        
        // Toggle between password and text input types
        const isCurrentlyPassword = passwordInput.type === 'password';
        passwordInput.type = isCurrentlyPassword ? 'text' : 'password';
        
        // Update button state and ARIA label for accessibility
        this.classList.toggle('is-visible', isCurrentlyPassword);
        this.setAttribute('aria-label', isCurrentlyPassword ? 'Hide password' : 'Show password');
      });
      
      // Handle keyboard activation (Enter and Space)
      btn.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.click();
        }
      });
    });
  }
  
  // Initialize password toggles
  initPasswordToggle();
  
  // Form elements
  const showEmailSignInBtn = document.getElementById('showEmailSignIn');
  const emailEntryForm = document.getElementById('emailEntryForm');
  const passwordLoginForm = document.getElementById('passwordLoginForm');
  const googleOnlyForm = document.getElementById('googleOnlyForm');
  const emailRegisterForm = document.getElementById('emailRegisterForm');
  const passwordSetupForm = document.getElementById('passwordSetupForm');
  const immediatePasswordSetup = document.getElementById('immediatePasswordSetup');
  
  // Form inputs
  const emailCheckForm = document.getElementById('emailCheckForm');
  const emailLoginForm = document.getElementById('emailLoginForm');
  const emailRegForm = document.getElementById('emailRegForm');
  const requestPasswordSetupForm = document.getElementById('requestPasswordSetupForm');
  const immediateSetupForm = document.getElementById('immediateSetupForm');
  
  // Error elements
  const emailCheckError = document.getElementById('emailCheckError');
  const loginError = document.getElementById('loginError');
  const registerError = document.getElementById('registerError');
  const setupError = document.getElementById('setupError');
  const immediateSetupError = document.getElementById('immediateSetupError');
  
  // Buttons
  const emailContinueBtn = document.getElementById('emailContinueBtn');
  const registerBtn = document.getElementById('registerBtn');
  const setPasswordBtn = document.getElementById('setPasswordBtn');
  
  // Password validation elements for registration
  const regPassword = document.getElementById('regPassword');
  const regConfirmPassword = document.getElementById('regConfirmPassword');
  const reqLength = document.getElementById('reqLength');
  const reqUpper = document.getElementById('reqUpper');
  const reqNumber = document.getElementById('reqNumber');
  const reqSpecial = document.getElementById('reqSpecial');
  const passwordMatch = document.getElementById('passwordMatch');
  
  // Password validation elements for immediate setup
  const newPassword = document.getElementById('newPassword');
  const confirmNewPassword = document.getElementById('confirmNewPassword');
  const newReqLength = document.getElementById('newReqLength');
  const newReqUpper = document.getElementById('newReqUpper');
  const newReqNumber = document.getElementById('newReqNumber');
  const newReqSpecial = document.getElementById('newReqSpecial');
  const newPasswordMatch = document.getElementById('newPasswordMatch');
  
  // State
  let currentEmail = '';
  let currentFlow = '';
  let lastEnteredPassword = ''; // Store password for potential reuse in registration
  
  // Resend verification email function
  function resendVerificationEmail(email) {
    fetch('api/email-verification.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'resend', email: email }),
      credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
      alert(data.message || 'Verification email sent. Please check your inbox.');
    })
    .catch(error => {
      alert('Failed to resend verification email. Please try again.');
    });
  }
  
  // Helper function to get cookie value
  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
  }
  
  // Helper function to set cookie value
  function setCookie(name, value, days) {
    let expires = '';
    if (days) {
      const date = new Date();
      date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
      expires = '; expires=' + date.toUTCString();
    }
    document.cookie = name + '=' + (value || '') + expires + '; path=/; SameSite=Lax';
  }
  
  // Helper function to delete cookie
  function deleteCookie(name) {
    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';
  }
  
  // Hide all forms
  function hideAllForms() {
    emailEntryForm.style.display = 'none';
    passwordLoginForm.style.display = 'none';
    googleOnlyForm.style.display = 'none';
    emailRegisterForm.style.display = 'none';
    passwordSetupForm.style.display = 'none';
    immediatePasswordSetup.style.display = 'none';
  }
  
  // Reset to email entry
  function resetToEmailEntry() {
    hideAllForms();
    emailEntryForm.style.display = 'block';
    document.getElementById('checkEmail').value = currentEmail;
    document.getElementById('checkEmail').focus();
    emailCheckError.style.display = 'none';
  }
  
  // Check if user prefers email login and auto-expand if so
  const loginPreference = getCookie('login_preference');
  if (loginPreference === 'email' && showEmailSignInBtn && emailEntryForm) {
    emailEntryForm.style.display = 'block';
    showEmailSignInBtn.innerHTML = `
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="18 15 12 9 6 15"/>
      </svg>
      <span>Hide email sign-in</span>
    `;
  }
  
  // Toggle email sign-in form
  if (showEmailSignInBtn) {
    showEmailSignInBtn.addEventListener('click', function() {
      if (emailEntryForm.style.display === 'none' && 
          passwordLoginForm.style.display === 'none' && 
          googleOnlyForm.style.display === 'none' &&
          emailRegisterForm.style.display === 'none') {
        hideAllForms();
        emailEntryForm.style.display = 'block';
        document.getElementById('checkEmail').focus();
        showEmailSignInBtn.innerHTML = `
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="18 15 12 9 6 15"/>
          </svg>
          <span>Hide email sign-in</span>
        `;
      } else {
        hideAllForms();
        currentEmail = '';
        currentFlow = '';
        showEmailSignInBtn.innerHTML = `
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          <span>Sign in with Email</span>
        `;
      }
    });
  }
  
  // Step 1: Email check form submission
  if (emailCheckForm) {
    emailCheckForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const email = document.getElementById('checkEmail').value.trim();
      emailCheckError.style.display = 'none';
      emailContinueBtn.disabled = true;
      emailContinueBtn.textContent = 'Checking...';
      
      fetch('api/check-email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        emailContinueBtn.disabled = false;
        emailContinueBtn.textContent = 'Continue';
        
        if (!data.success) {
          emailCheckError.textContent = data.message || 'An error occurred';
          emailCheckError.style.display = 'block';
          return;
        }
        
        currentEmail = email;
        currentFlow = data.flow;
        hideAllForms();
        
        if (data.flow === 'login') {
          // User has password auth - show password login
          document.getElementById('loginEmail').value = email;
          const greeting = data.first_name ? `Welcome back, ${data.first_name}!` : `Welcome back!`;
          document.getElementById('loginGreeting').textContent = greeting;
          passwordLoginForm.style.display = 'block';
          document.getElementById('loginPassword').focus();
        } else if (data.flow === 'google_only') {
          // User has Google only - show Google options
          const greeting = data.first_name ? `Hi ${data.first_name}!` : `Welcome!`;
          document.getElementById('googleOnlyGreeting').textContent = greeting;
          googleOnlyForm.style.display = 'block';
        } else if (data.flow === 'register') {
          // New user - show registration
          document.getElementById('regEmail').value = email;
          // Pre-fill password if user previously entered one (e.g., from failed login attempt)
          if (lastEnteredPassword) {
            document.getElementById('regPassword').value = lastEnteredPassword;
            validatePasswordRequirements();
            updateRegisterButton();
          }
          emailRegisterForm.style.display = 'block';
          // Focus on first name if password is pre-filled, otherwise focus on first name anyway
          document.getElementById('regFirstName').focus();
        }
      })
      .catch(error => {
        emailContinueBtn.disabled = false;
        emailContinueBtn.textContent = 'Continue';
        emailCheckError.textContent = 'An error occurred. Please try again.';
        emailCheckError.style.display = 'block';
      });
    });
  }
  
  // "Use a different email" buttons
  document.getElementById('changeEmailBtn')?.addEventListener('click', resetToEmailEntry);
  document.getElementById('changeEmailBtn2')?.addEventListener('click', resetToEmailEntry);
  document.getElementById('changeEmailBtn3')?.addEventListener('click', resetToEmailEntry);
  
  // "Set up a password" button for Google-only users
  document.getElementById('setupPasswordBtn')?.addEventListener('click', function() {
    document.getElementById('setupEmail').value = currentEmail;
    hideAllForms();
    passwordSetupForm.style.display = 'block';
  });
  
  // Back to Google-only form
  document.getElementById('backToGoogleOnly')?.addEventListener('click', function() {
    hideAllForms();
    googleOnlyForm.style.display = 'block';
  });
  
  // Back from immediate setup
  document.getElementById('backFromImmediate')?.addEventListener('click', function() {
    hideAllForms();
    googleOnlyForm.style.display = 'block';
  });
  
  // Request password setup form
  if (requestPasswordSetupForm) {
    requestPasswordSetupForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const email = document.getElementById('setupEmail').value;
      setupError.style.display = 'none';
      
      fetch('api/request-password-setup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'request', email: email }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        if (data.immediate_setup && data.token) {
          // User is verified via Google session - allow immediate setup
          document.getElementById('immediateSetupToken').value = data.token;
          hideAllForms();
          immediatePasswordSetup.style.display = 'block';
          newPassword.focus();
        } else {
          // Show success message
          setupError.style.background = '#f0fdf4';
          setupError.style.borderColor = '#bbf7d0';
          setupError.style.color = '#16a34a';
          setupError.textContent = data.message || 'Check your email for a verification link.';
          setupError.style.display = 'block';
        }
      })
      .catch(error => {
        setupError.textContent = 'An error occurred. Please try again.';
        setupError.style.display = 'block';
      });
    });
  }
  
  // Password validation
  function validatePasswordRequirements() {
    const password = regPassword.value;
    let allValid = true;
    
    // Length check
    if (password.length >= 8) {
      reqLength.textContent = '✓ At least 8 characters';
      reqLength.classList.add('valid');
    } else {
      reqLength.textContent = '✗ At least 8 characters';
      reqLength.classList.remove('valid');
      allValid = false;
    }
    
    // Uppercase check
    if (/[A-Z]/.test(password)) {
      reqUpper.textContent = '✓ One uppercase letter';
      reqUpper.classList.add('valid');
    } else {
      reqUpper.textContent = '✗ One uppercase letter';
      reqUpper.classList.remove('valid');
      allValid = false;
    }
    
    // Number check
    if (/[0-9]/.test(password)) {
      reqNumber.textContent = '✓ One number';
      reqNumber.classList.add('valid');
    } else {
      reqNumber.textContent = '✗ One number';
      reqNumber.classList.remove('valid');
      allValid = false;
    }
    
    // Special character check
    if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) {
      reqSpecial.textContent = '✓ One special character';
      reqSpecial.classList.add('valid');
    } else {
      reqSpecial.textContent = '✗ One special character';
      reqSpecial.classList.remove('valid');
      allValid = false;
    }
    
    return allValid;
  }
  
  function checkPasswordMatch() {
    const password = regPassword.value;
    const confirm = regConfirmPassword.value;
    
    if (confirm.length === 0) {
      passwordMatch.textContent = '';
      passwordMatch.className = 'password-match';
      return false;
    }
    
    if (password === confirm) {
      passwordMatch.textContent = '✓ Passwords match';
      passwordMatch.className = 'password-match match';
      return true;
    } else {
      passwordMatch.textContent = '✗ Passwords do not match';
      passwordMatch.className = 'password-match no-match';
      return false;
    }
  }
  
  function updateRegisterButton() {
    const passwordValid = validatePasswordRequirements();
    const passwordsMatch = checkPasswordMatch();
    const emailValid = regEmail && regEmail.value.includes('@');
    
    registerBtn.disabled = !(passwordValid && passwordsMatch && emailValid);
  }
  
  if (regPassword) {
    regPassword.addEventListener('input', function() {
      validatePasswordRequirements();
      checkPasswordMatch();
      updateRegisterButton();
    });
  }
  
  if (regConfirmPassword) {
    regConfirmPassword.addEventListener('input', function() {
      checkPasswordMatch();
      updateRegisterButton();
    });
  }
  
  const regEmail = document.getElementById('regEmail');
  if (regEmail) {
    regEmail.addEventListener('input', updateRegisterButton);
  }
  
  // Handle login form submission
  const loginSubmitBtn = document.getElementById('loginSubmitBtn');
  
  if (emailLoginForm) {
    emailLoginForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const email = document.getElementById('loginEmail').value;
      const password = document.getElementById('loginPassword').value;
      
      // Store password in case user needs to register instead
      lastEnteredPassword = password;
      
      loginError.style.display = 'none';
      
      // Show loading state
      if (loginSubmitBtn) {
        loginSubmitBtn.disabled = true;
        loginSubmitBtn.textContent = 'Signing in...';
      }
      
      // Get Remember Me checkbox value
      const rememberMe = document.getElementById('rememberMe')?.checked || false;
      
      fetch('api/auth-email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'login',
          email: email,
          password: password,
          rememberMe: rememberMe
        }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          lastEnteredPassword = ''; // Clear stored password on successful login
          // Save preference for email login (expires in 30 days)
          setCookie('login_preference', 'email', 30);
          window.location.href = data.redirect || 'main.php';
        } else if (data.requires_2fa) {
          // ============================================
          // TWO-FACTOR AUTHENTICATION REQUIRED
          // Show 2FA input field for users with 2FA enabled
          // ============================================
          if (loginSubmitBtn) {
            loginSubmitBtn.disabled = false;
            loginSubmitBtn.textContent = 'Sign In';
          }
          
          // Show 2FA input section
          show2FAInput(email, password, rememberMe, data.message);
        } else {
          // Reset button state on error
          if (loginSubmitBtn) {
            loginSubmitBtn.disabled = false;
            loginSubmitBtn.textContent = 'Sign In';
          }
          
          // Check if verification is required
          if (data.requires_verification) {
            loginError.innerHTML = (data.message || 'Please verify your email.') + 
              '<br><a href="#" class="resend-verification-link" style="color: #3b82f6;">Resend verification email</a>';
            loginError.style.display = 'block';
            
            // Add click handler for resend link
            const resendLink = loginError.querySelector('.resend-verification-link');
            if (resendLink) {
              resendLink.addEventListener('click', function(e) {
                e.preventDefault();
                resendVerificationEmail(data.email || email);
              });
            }
          } else {
            loginError.textContent = data.message || 'Login failed';
            loginError.style.display = 'block';
          }
        }
      })
      .catch(error => {
        // Reset button state on error
        if (loginSubmitBtn) {
          loginSubmitBtn.disabled = false;
          loginSubmitBtn.textContent = 'Sign In';
        }
        loginError.textContent = 'An error occurred. Please try again.';
        loginError.style.display = 'block';
      });
    });
  }
  
  // Handle registration form submission
  if (emailRegForm) {
    emailRegForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const firstName = document.getElementById('regFirstName').value;
      const lastName = document.getElementById('regLastName').value;
      const email = document.getElementById('regEmail').value;
      const password = document.getElementById('regPassword').value;
      const confirmPassword = document.getElementById('regConfirmPassword').value;
      
      registerError.style.display = 'none';
      
      fetch('api/auth-email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'register',
          firstName: firstName,
          lastName: lastName,
          email: email,
          password: password,
          confirmPassword: confirmPassword
        }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Clear stored password on successful registration
          lastEnteredPassword = '';
          
          if (data.requires_verification) {
            // Show verification required message
            hideAllForms();
            document.getElementById('loginEmail').value = email;
            document.getElementById('loginGreeting').textContent = 'Check your email';
            passwordLoginForm.style.display = 'block';
            loginError.style.display = 'block';
            loginError.style.background = '#eff6ff';
            loginError.style.borderColor = '#bfdbfe';
            loginError.style.color = '#1d4ed8';
            loginError.innerHTML = (data.message || 'Please check your email to verify your account.') + 
              '<br><a href="#" class="resend-verification-link" style="color: #3b82f6; margin-top: 8px; display: inline-block;">Resend verification email</a>';
            
            // Add click handler for resend link
            const resendLink = loginError.querySelector('.resend-verification-link');
            if (resendLink) {
              resendLink.addEventListener('click', function(e) {
                e.preventDefault();
                resendVerificationEmail(email);
              });
            }
          } else {
            // Show success and switch to password login form (for linked accounts)
            hideAllForms();
            document.getElementById('loginEmail').value = email;
            const greeting = 'Account created! Sign in below.';
            document.getElementById('loginGreeting').textContent = greeting;
            passwordLoginForm.style.display = 'block';
            loginError.style.display = 'block';
            loginError.style.background = '#f0fdf4';
            loginError.style.borderColor = '#bbf7d0';
            loginError.style.color = '#16a34a';
            loginError.textContent = data.message || 'Account created! Please sign in.';
            document.getElementById('loginPassword').focus();
          }
        } else {
          registerError.textContent = data.message || 'Registration failed';
          if (data.errors && data.errors.length > 0) {
            registerError.textContent = data.errors.join('. ');
          }
          registerError.style.display = 'block';
        }
      })
      .catch(error => {
        registerError.textContent = 'An error occurred. Please try again.';
        registerError.style.display = 'block';
      });
    });
  }
  
  // Password validation for immediate setup
  function validateNewPasswordRequirements() {
    const password = newPassword.value;
    let allValid = true;
    
    if (password.length >= 8) {
      newReqLength.textContent = '✓ At least 8 characters';
      newReqLength.classList.add('valid');
    } else {
      newReqLength.textContent = '✗ At least 8 characters';
      newReqLength.classList.remove('valid');
      allValid = false;
    }
    
    if (/[A-Z]/.test(password)) {
      newReqUpper.textContent = '✓ One uppercase letter';
      newReqUpper.classList.add('valid');
    } else {
      newReqUpper.textContent = '✗ One uppercase letter';
      newReqUpper.classList.remove('valid');
      allValid = false;
    }
    
    if (/[0-9]/.test(password)) {
      newReqNumber.textContent = '✓ One number';
      newReqNumber.classList.add('valid');
    } else {
      newReqNumber.textContent = '✗ One number';
      newReqNumber.classList.remove('valid');
      allValid = false;
    }
    
    if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) {
      newReqSpecial.textContent = '✓ One special character';
      newReqSpecial.classList.add('valid');
    } else {
      newReqSpecial.textContent = '✗ One special character';
      newReqSpecial.classList.remove('valid');
      allValid = false;
    }
    
    return allValid;
  }
  
  function checkNewPasswordMatch() {
    const password = newPassword.value;
    const confirm = confirmNewPassword.value;
    
    if (confirm.length === 0) {
      newPasswordMatch.textContent = '';
      newPasswordMatch.className = 'password-match';
      return false;
    }
    
    if (password === confirm) {
      newPasswordMatch.textContent = '✓ Passwords match';
      newPasswordMatch.className = 'password-match match';
      return true;
    } else {
      newPasswordMatch.textContent = '✗ Passwords do not match';
      newPasswordMatch.className = 'password-match no-match';
      return false;
    }
  }
  
  function updateSetPasswordButton() {
    const passwordValid = validateNewPasswordRequirements();
    const passwordsMatch = checkNewPasswordMatch();
    setPasswordBtn.disabled = !(passwordValid && passwordsMatch);
  }
  
  if (newPassword) {
    newPassword.addEventListener('input', function() {
      validateNewPasswordRequirements();
      checkNewPasswordMatch();
      updateSetPasswordButton();
    });
  }
  
  if (confirmNewPassword) {
    confirmNewPassword.addEventListener('input', function() {
      checkNewPasswordMatch();
      updateSetPasswordButton();
    });
  }
  
  // Handle immediate password setup form
  if (immediateSetupForm) {
    immediateSetupForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const token = document.getElementById('immediateSetupToken').value;
      const password = newPassword.value;
      
      immediateSetupError.style.display = 'none';
      setPasswordBtn.disabled = true;
      setPasswordBtn.textContent = 'Setting password...';
      
      fetch('api/request-password-setup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'complete',
          token: token,
          password: password
        }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        setPasswordBtn.textContent = 'Set Password';
        
        if (data.success) {
          // Show success and switch to password login
          hideAllForms();
          document.getElementById('loginEmail').value = currentEmail;
          document.getElementById('loginGreeting').textContent = 'Password set! Sign in below.';
          passwordLoginForm.style.display = 'block';
          loginError.style.display = 'block';
          loginError.style.background = '#f0fdf4';
          loginError.style.borderColor = '#bbf7d0';
          loginError.style.color = '#16a34a';
          loginError.textContent = data.message || 'Password set successfully!';
          document.getElementById('loginPassword').focus();
        } else {
          setPasswordBtn.disabled = false;
          immediateSetupError.textContent = data.message || 'Failed to set password';
          immediateSetupError.style.display = 'block';
        }
      })
      .catch(error => {
        setPasswordBtn.disabled = false;
        setPasswordBtn.textContent = 'Set Password';
        immediateSetupError.textContent = 'An error occurred. Please try again.';
        immediateSetupError.style.display = 'block';
      });
    });
  }
});

// Modal functionality for Privacy Policy and Terms of Service
const privacyLink = document.getElementById('privacyLink');
const termsLink = document.getElementById('termsLink');
const privacyModal = document.getElementById('privacyModal');
const termsModal = document.getElementById('termsModal');

function openModal(modal) {
  if (modal) {
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(modal) {
  if (modal) {
    modal.classList.remove('active');
    document.body.style.overflow = '';
  }
}

if (privacyLink && privacyModal) {
  privacyLink.addEventListener('click', function(e) {
    e.preventDefault();
    openModal(privacyModal);
  });
}

if (termsLink && termsModal) {
  termsLink.addEventListener('click', function(e) {
    e.preventDefault();
    openModal(termsModal);
  });
}

// Close modal handlers
document.querySelectorAll('.modal .btn-close, .modal .modal-close-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const modal = this.closest('.modal');
    closeModal(modal);
  });
});

// Close modal when clicking outside content
document.querySelectorAll('.modal').forEach(function(modal) {
  modal.addEventListener('click', function(e) {
    if (e.target === this) {
      closeModal(this);
    }
  });
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal.active').forEach(function(modal) {
      closeModal(modal);
    });
  }
});

// ============================================
// TWO-FACTOR AUTHENTICATION LOGIN FLOW
// ============================================
var pending2FACredentials = null;
var pending2FAGoogle = false; // Flag for Google sign-in 2FA

function showGoogle2FAInput() {
  // Hide all login forms, show 2FA form
  var emailForm = document.getElementById('emailLoginForm');
  var passwordLoginFormContainer = document.getElementById('passwordLoginForm');
  var googleWrapper = document.querySelector('.google-signin-wrapper');
  var emailToggle = document.querySelector('.email-signin-toggle');
  var loginDivider = document.querySelector('.login-divider');
  var twoFactorForm = document.getElementById('twoFactorLoginForm');
  var twoFactorError = document.getElementById('twoFactorLoginError');
  var codeInput = document.getElementById('login2FACode');
  var twoFactorHeader = document.querySelector('.two-factor-header p');
  var emailEntryForm = document.getElementById('emailEntryForm');
  var changeEmailBtn = document.getElementById('changeEmailBtn');
  var loginGreeting = document.getElementById('loginGreeting');
  
  // Hide all login elements
  if (emailForm) emailForm.style.display = 'none';
  if (emailEntryForm) emailEntryForm.style.display = 'none';
  if (googleWrapper) googleWrapper.style.display = 'none';
  if (emailToggle) emailToggle.style.display = 'none';
  if (loginDivider) loginDivider.style.display = 'none';
  if (changeEmailBtn) changeEmailBtn.style.display = 'none';
  if (loginGreeting) loginGreeting.style.display = 'none';
  
  // Show the password login form container (which contains the 2FA form)
  if (passwordLoginFormContainer) passwordLoginFormContainer.style.display = 'block';
  
  // Show 2FA form
  if (twoFactorForm) twoFactorForm.style.display = 'block';
  if (twoFactorHeader) twoFactorHeader.textContent = 'Enter the 6-digit code from your authenticator app to complete Google sign-in';
  if (twoFactorError) twoFactorError.style.display = 'none';
  if (codeInput) {
    codeInput.value = '';
    codeInput.focus();
  }
}

// Check if we were redirected here for Google 2FA (must be after function definition)
(function checkGoogle2FA() {
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('require_2fa') === 'google') {
    // Show 2FA form for Google sign-in
    pending2FAGoogle = true;
    showGoogle2FAInput();
    // Clean up URL
    window.history.replaceState({}, document.title, window.location.pathname);
  }
})();

function show2FAInput(email, password, rememberMe, message) {
  // Store credentials for 2FA verification
  pending2FACredentials = { email: email, password: password, rememberMe: rememberMe };
  pending2FAGoogle = false;
  
  // Hide password form, show 2FA form
  var passwordForm = document.getElementById('emailLoginForm');
  var twoFactorForm = document.getElementById('twoFactorLoginForm');
  var twoFactorError = document.getElementById('twoFactorLoginError');
  var codeInput = document.getElementById('login2FACode');
  
  if (passwordForm) passwordForm.style.display = 'none';
  if (twoFactorForm) twoFactorForm.style.display = 'block';
  if (twoFactorError) {
    twoFactorError.textContent = message || '';
    twoFactorError.style.display = message ? 'block' : 'none';
  }
  if (codeInput) {
    codeInput.value = '';
    codeInput.focus();
  }
}

function hide2FAInput() {
  pending2FACredentials = null;
  
  var passwordForm = document.getElementById('emailLoginForm');
  var twoFactorForm = document.getElementById('twoFactorLoginForm');
  
  if (passwordForm) passwordForm.style.display = 'block';
  if (twoFactorForm) twoFactorForm.style.display = 'none';
}

// Verify 2FA code and complete login
var verify2FABtn = document.getElementById('verify2FALoginBtn');
var cancel2FABtn = document.getElementById('cancel2FALogin');
var login2FACodeInput = document.getElementById('login2FACode');
var twoFactorLoginError = document.getElementById('twoFactorLoginError');

if (verify2FABtn) {
  verify2FABtn.addEventListener('click', function() {
    // Check if this is Google 2FA or email/password 2FA
    if (!pending2FACredentials && !pending2FAGoogle) {
      hide2FAInput();
      return;
    }
    
    var code = login2FACodeInput ? login2FACodeInput.value.trim() : '';
    
    if (!code || code.length !== 6 || !/^\d+$/.test(code)) {
      if (twoFactorLoginError) {
        twoFactorLoginError.textContent = 'Please enter a valid 6-digit code.';
        twoFactorLoginError.style.display = 'block';
      }
      return;
    }
    
    verify2FABtn.disabled = true;
    verify2FABtn.textContent = 'Verifying...';
    if (twoFactorLoginError) twoFactorLoginError.style.display = 'none';
    
    // Determine which API endpoint to use
    var apiUrl = pending2FAGoogle ? 'api/verify-google-2fa.php' : 'api/auth-email.php';
    var requestBody = pending2FAGoogle 
      ? { totpCode: code }
      : {
          action: 'login',
          email: pending2FACredentials.email,
          password: pending2FACredentials.password,
          rememberMe: pending2FACredentials.rememberMe,
          totpCode: code
        };
    
    // Submit 2FA code
    fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(requestBody),
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        pending2FACredentials = null;
        // Save email login preference if this was email 2FA (not Google 2FA)
        if (!pending2FAGoogle) {
          setCookie('login_preference', 'email', 30);
        }
        pending2FAGoogle = false;
        window.location.href = data.redirect || 'main.php';
      } else {
        verify2FABtn.disabled = false;
        verify2FABtn.textContent = 'Verify & Sign In';
        
        if (twoFactorLoginError) {
          twoFactorLoginError.textContent = data.message || 'Invalid code. Please try again.';
          twoFactorLoginError.style.display = 'block';
        }
        
        // Clear the code input for retry
        if (login2FACodeInput) {
          login2FACodeInput.value = '';
          login2FACodeInput.focus();
        }
      }
    })
    .catch(function() {
      verify2FABtn.disabled = false;
      verify2FABtn.textContent = 'Verify & Sign In';
      
      if (twoFactorLoginError) {
        twoFactorLoginError.textContent = 'An error occurred. Please try again.';
        twoFactorLoginError.style.display = 'block';
      }
    });
  });
}

// Cancel 2FA and go back to password form
if (cancel2FABtn) {
  cancel2FABtn.addEventListener('click', function() {
    if (pending2FAGoogle) {
      // For Google 2FA, restore the main login page elements
      pending2FAGoogle = false;
      var googleWrapper = document.querySelector('.google-signin-wrapper');
      var emailToggle = document.querySelector('.email-signin-toggle');
      var loginDivider = document.querySelector('.login-divider');
      var twoFactorForm = document.getElementById('twoFactorLoginForm');
      var passwordLoginFormContainer = document.getElementById('passwordLoginForm');
      
      if (twoFactorForm) twoFactorForm.style.display = 'none';
      if (passwordLoginFormContainer) passwordLoginFormContainer.style.display = 'none';
      if (googleWrapper) googleWrapper.style.display = 'block';
      if (emailToggle) emailToggle.style.display = 'block';
      if (loginDivider) loginDivider.style.display = 'flex';
    } else {
      hide2FAInput();
    }
  });
}

// Allow Enter key to submit 2FA code
if (login2FACodeInput) {
  login2FACodeInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (verify2FABtn) verify2FABtn.click();
    }
  });
}
</script>
</body>
</html>
