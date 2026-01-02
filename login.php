<?php
// Load bootstrap to set environment variables early
require_once __DIR__ . '/api/bootstrap.php';

// Suppress deprecation notices
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

// Get client ID for Google Sign-In
$googleClientId = $appConfig['google_client_id'];

// Check for authentication errors
$authError = isset($_GET['auth_error']) ? htmlspecialchars($_GET['auth_error']) : null;
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
        
        <?php if ($authError): ?>
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
              <input type="password" id="loginPassword" name="password" required placeholder="Enter your password" autocomplete="current-password">
            </div>
            <div id="loginError" class="form-error" style="display: none;"></div>
            <button type="submit" class="email-submit-btn" id="loginSubmitBtn">Sign In</button>
            <div class="forgot-password-link">
              <a href="forgot-password.php" class="link-btn">Forgot your password?</a>
            </div>
          </form>
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
              <input type="password" id="regPassword" name="password" required placeholder="Create a password" autocomplete="new-password">
              <div class="password-requirements">
                <span class="req" id="reqLength">✗ At least 8 characters</span>
                <span class="req" id="reqUpper">✗ One uppercase letter</span>
                <span class="req" id="reqNumber">✗ One number</span>
                <span class="req" id="reqSpecial">✗ One special character</span>
              </div>
            </div>
            <div class="form-group">
              <label for="regConfirmPassword">Confirm Password</label>
              <input type="password" id="regConfirmPassword" name="confirmPassword" required placeholder="Confirm your password" autocomplete="new-password">
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
              <input type="password" id="newPassword" name="password" required placeholder="Create a password" autocomplete="new-password">
              <div class="password-requirements">
                <span class="req" id="newReqLength">✗ At least 8 characters</span>
                <span class="req" id="newReqUpper">✗ One uppercase letter</span>
                <span class="req" id="newReqNumber">✗ One number</span>
                <span class="req" id="newReqSpecial">✗ One special character</span>
              </div>
            </div>
            <div class="form-group">
              <label for="confirmNewPassword">Confirm Password</label>
              <input type="password" id="confirmNewPassword" name="confirmPassword" required placeholder="Confirm your password" autocomplete="new-password">
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
      
      fetch('api/auth-email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'login',
          email: email,
          password: password
        }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          lastEnteredPassword = ''; // Clear stored password on successful login
          window.location.href = data.redirect || 'main.php';
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
</script>
</body>
</html>
