<?php
// Start output buffering IMMEDIATELY to catch everything
ob_start();

// Load bootstrap to set environment variables early
require_once __DIR__ . '/api/bootstrap.php';

// Aggressive error suppression to prevent JavaScript syntax errors
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
// Also suppress warnings that might leak into output
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Use centralized session handling so this page shares the same session
// as index.php, create-case.php, list-cases.php, and the Drive OAuth flows
require_once __DIR__ . '/api/session.php';

// Load configuration
require_once __DIR__ . '/api/appConfig.php';

// Load CSRF and security headers
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';

// Load dev tools access control
require_once __DIR__ . '/api/dev-tools-access.php';

// Load feature flags
require_once __DIR__ . '/api/feature-flags.php';

// Set security headers for this page
setSecurityHeaders();

// Generate CSRF token for this session
$csrfToken = generateCsrfToken();

// Reset redirect counter when accessing main page
$_SESSION['practice_setup_visits'] = 0;

// Make sure we don't have conflicting flags set
if (isset($_SESSION['current_practice_id']) && !empty($_SESSION['current_practice_id'])) {
    // If we have a practice ID, make sure we're not flagged for setup
    $_SESSION['needs_practice_setup'] = false;
    $_SESSION['needs_practice_selection'] = false;
}

// Check if a practice is selected
if (!isset($_SESSION['current_practice_id']) || empty($_SESSION['current_practice_id'])) {
    // Redirect to practice setup / BAA acceptance
    header('Location: practice-setup.php');
    exit;
}

$currentPracticeId = $_SESSION['current_practice_id'];
$userId = $_SESSION['db_user_id'] ?? null;

// SECURITY: Verify user is actually a member of this practice
if ($userId && $currentPracticeId) {
    try {
        $membershipStmt = $pdo->prepare("
            SELECT 1 FROM practice_users 
            WHERE user_id = :user_id AND practice_id = :practice_id
        ");
        $membershipStmt->execute([
            'user_id' => $userId,
            'practice_id' => $currentPracticeId
        ]);
        
        if (!$membershipStmt->fetchColumn()) {
            // User is NOT a member of this practice - security violation
            error_log("[SECURITY] User {$userId} attempted to access practice {$currentPracticeId} without membership");
            
            // Clear the invalid practice from session
            unset($_SESSION['current_practice_id']);
            
            // Redirect to practice setup
            header('Location: practice-setup.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log("[SECURITY] Error verifying practice membership: " . $e->getMessage());
    }
}

// BAA ACCESS CONTROL GATE
// Block access to PHI until BAA is accepted
$baaAccepted = false;

try {
    // Check if BAA columns exist and if BAA is accepted
    $stmt = $pdo->prepare("SELECT baa_accepted FROM practices WHERE id = :id");
    $stmt->execute(['id' => $currentPracticeId]);
    $practiceData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($practiceData) {
        $baaAccepted = (bool)$practiceData['baa_accepted'];
    }
    
    if (!$baaAccepted) {
        // Redirect to BAA acceptance page
        header('Location: baa-acceptance.php');
        exit;
    }
} catch (PDOException $e) {
    // If baa_accepted column doesn't exist, the migration hasn't run yet
    // Allow access but log the issue
    if (strpos($e->getMessage(), 'baa_accepted') !== false) {
        // Column doesn't exist - migration needed
        // For now, allow access to avoid breaking existing installations
        error_log("BAA columns not found - migration needed. Run api/migrate-baa-fields.php");
    } else {
        error_log("Error checking BAA status: " . $e->getMessage());
    }
}

// Simple authentication check
if (!isset($_SESSION['user'])) {
    // Show a styled authentication required page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sign In Required</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .auth-container {
                background: white;
                border-radius: 16px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                padding: 48px;
                max-width: 420px;
                width: 100%;
                text-align: center;
            }
            .auth-icon {
                width: 80px;
                height: 80px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 24px;
            }
            .auth-icon svg {
                width: 40px;
                height: 40px;
                color: white;
            }
            h1 {
                font-size: 1.75rem;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 12px;
            }
            .auth-message {
                color: #64748b;
                font-size: 1rem;
                line-height: 1.6;
                margin-bottom: 32px;
            }
            .auth-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                text-decoration: none;
                padding: 14px 32px;
                border-radius: 999px;
                font-weight: 600;
                font-size: 1rem;
                transition: transform 0.2s, box-shadow 0.2s;
                box-shadow: 0 4px 14px rgba(102, 126, 234, 0.4);
            }
            .auth-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
            }
            .auth-btn svg {
                width: 20px;
                height: 20px;
            }
            .auth-footer {
                margin-top: 32px;
                padding-top: 24px;
                border-top: 1px solid #e2e8f0;
                color: #94a3b8;
                font-size: 0.8rem;
            }
        </style>
    </head>
    <body>
        <div class="auth-container">
            <div class="auth-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h1>Sign In Required</h1>
            <p class="auth-message">Please sign in with your Google account to access the dental lab management dashboard.</p>
            <a href="login.php" class="auth-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                </svg>
                Go to Sign In
            </a>
            <div class="auth-footer">
                Your session may have expired. Please sign in again to continue.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Store user info for display
$user = $_SESSION['user'];

// Fetch current practice information for header
$currentPracticeId = $_SESSION['current_practice_id'] ?? 0;

// Get practice name and logo
$practiceName = 'My Practice'; // Default
$practiceLogoPath = ''; // Default
$userPractices = []; // All practices user belongs to
$hasMultiplePractices = false;
$userHasPassword = false; // Whether user has a password set (false for Google-only users)

// Always try to load practice name and logo from the database for the current practice
if ($currentPracticeId) {
    try {
        // Use the existing PDO connection from api/appConfig.php
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT practice_name, logo_path FROM practices WHERE id = :id");
            $stmt->execute(['id' => $currentPracticeId]);
            $practiceInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($practiceInfo) {
                if (!empty($practiceInfo['practice_name'])) {
                    $practiceName = $practiceInfo['practice_name'];
                    $_SESSION['practice_name'] = $practiceName;
                }

                if (!empty($practiceInfo['logo_path'])) {
                    $practiceLogoPath = $practiceInfo['logo_path'];
                }
            }
            
            // Fetch all practices user belongs to (for practice switcher)
            $userId = $_SESSION['db_user_id'] ?? 0;
            if ($userId) {
                $stmt = $pdo->prepare("
                    SELECT p.id, p.practice_name, p.logo_path, pu.role, pu.is_owner
                    FROM practices p
                    JOIN practice_users pu ON p.id = pu.practice_id
                    WHERE pu.user_id = :user_id
                    ORDER BY p.practice_name ASC
                ");
                $stmt->execute(['user_id' => $userId]);
                $userPractices = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $hasMultiplePractices = count($userPractices) > 1;
                
                // Check if user has a password set (for showing/hiding change password section)
                // Users who signed in with Google only won't have a password_hash
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :user_id");
                $stmt->execute(['user_id' => $userId]);
                $userAuth = $stmt->fetch(PDO::FETCH_ASSOC);
                $userHasPassword = !empty($userAuth['password_hash']);
            }
        } else {
            // PDO not available, using defaults
        }
    } catch (Exception $e) {
        // Database error, using defaults
    }
}


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
  <?php
// Safely get app name to prevent warnings
$appName = $appConfig['appName'];
if (isset($appConfig) && is_array($appConfig) && isset($appConfig['appName'])) {
  $appName = $appConfig['appName'];
}
?>
<meta name="description" content="<?php echo htmlspecialchars($appName . ' - Professional dental case tracking and management system. Streamline your dental lab workflow with real-time case tracking, team collaboration, and analytics.'); ?>">
  <link rel="canonical" href="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/main.php')); ?>">
  <title><?php echo htmlspecialchars($appName . ' - Main'); ?></title>
  
  <!-- Structured Data for SEO -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "<?php echo htmlspecialchars($appName); ?>",
    "description": "Professional dental case tracking and management system. Streamline your dental lab workflow with real-time case tracking, team collaboration, and analytics.",
    "url": "<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (dirname($_SERVER['PHP_SELF'] ?? '/main.php') ?: '/')); ?>",
    "applicationCategory": "BusinessApplication",
    "operatingSystem": "Web Browser",
    "offers": {
      "@type": "Offer",
      "price": "0",
      "priceCurrency": "USD",
      "description": "Free tier available with premium features"
    },
    "featureList": [
      "Real-time case tracking",
      "Team collaboration",
      "Analytics dashboard",
      "Document management",
      "Google Drive integration"
    ],
    "provider": {
      "@type": "Organization",
      "name": "<?php echo htmlspecialchars($appName); ?>"
    }
  }
  </script>
  
  <!-- Performance optimizations -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  
  <!-- Preload critical resources -->
  <link rel="preload" href="js/app.js?v=20250104" as="script">
  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"></noscript>
  
  <!-- Critical CSS inlined to eliminate render-blocking -->
  <style>
    /* Error hide - inlined */
    .php-error, .php-warning, .php-notice, .php-deprecated, .php-strict {
      display: none !important;
      visibility: hidden !important;
      height: 0 !important;
      width: 0 !important;
      overflow: hidden !important;
      position: absolute !important;
      left: -9999px !important;
    }
  </style>
  
  <!-- Load app.light.css directly (skip app.css @import chain) -->
  <link rel="stylesheet" href="css/app.light.css?v=20241227">
  <link rel="stylesheet" href="css/app.css?v=20241227">
  
  <!-- Mobile responsiveness CSS -->
  <link rel="stylesheet" href="css/mobile.css?v=20250104c">
  
  <!-- Non-critical CSS - deferred loading -->
  <?php if (isFeatureEnabled('SHOW_TOUR')): ?>
  <link rel="preload" href="https://cdn.jsdelivr.net/npm/shepherd.js@11/dist/css/shepherd.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/tour.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <?php endif; ?>
  <link rel="preload" href="css/toast.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/loading.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <?php if (isFeatureEnabled('SHOW_TOUR')): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/shepherd.js@11/dist/css/shepherd.css">
    <link rel="stylesheet" href="css/tour.css?v=20241210">
    <?php endif; ?>
    <link rel="stylesheet" href="css/toast.css?v=20241210">
    <link rel="stylesheet" href="css/loading.css?v=20241210">
  </noscript>
  
  <!-- Feature-specific CSS - loaded on demand -->
  <link rel="preload" href="css/revision-history.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/delete-button.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/settings-billing.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/feedback.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/kanban-dragdrop.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/case-creation.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/case-comments.css?v=20241231" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/activity-timeline.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/insights.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/at-risk.css?v=20241231" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/clinical-details.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/ask-dentatrak.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/patient-search.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/assignments.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/practice-name.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/logo-upload.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/dev-tools.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/analytics-pro.css?v=20241231" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/realtime.css?v=20250119" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <noscript>
    <link rel="stylesheet" href="css/revision-history.css?v=20241210">
    <link rel="stylesheet" href="css/delete-button.css?v=20241210">
    <link rel="stylesheet" href="css/settings-billing.css?v=20241210">
    <link rel="stylesheet" href="css/feedback.css?v=20241210">
    <link rel="stylesheet" href="css/kanban-dragdrop.css?v=20241210">
    <link rel="stylesheet" href="css/case-creation.css?v=20241210">
    <link rel="stylesheet" href="css/case-comments.css?v=20241231">
    <link rel="stylesheet" href="css/activity-timeline.css?v=20241230">
    <link rel="stylesheet" href="css/insights.css?v=20241230">
    <link rel="stylesheet" href="css/at-risk.css?v=20241231">
    <link rel="stylesheet" href="css/clinical-details.css?v=20241230">
    <link rel="stylesheet" href="css/ask-dentatrak.css?v=20241230">
    <link rel="stylesheet" href="css/patient-search.css?v=20241210">
    <link rel="stylesheet" href="css/assignments.css?v=20241210">
    <link rel="stylesheet" href="css/practice-name.css?v=20241210">
    <link rel="stylesheet" href="css/logo-upload.css?v=20241210">
    <link rel="stylesheet" href="css/dev-tools.css?v=20241210">
    <link rel="stylesheet" href="css/analytics-pro.css?v=20241231">
    <link rel="stylesheet" href="css/realtime.css?v=20250119">
  </noscript>
  <!-- Shepherd.js Tour - CSS loaded via preload above -->
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
  // Determine dev tools visibility using the new access control
  $userEmail = $_SESSION['user_email'] ?? ($user['email'] ?? '');
  $showDevTools = canAccessDevTools($appConfig, $userEmail);
  $isSuperUserInProd = $showDevTools && isProductionOrUAT($appConfig);
  $environmentDisplayName = getEnvironmentDisplayName($appConfig);
?>
<body class="main-body <?php echo $envClass; ?>">
  <!-- Full-page loading overlay -->
  <div id="pageLoadingOverlay" class="page-loading-overlay">
    <div class="overlay-content">
      <div class="loading-spinner"></div>
      <p class="loading-text">Loading application...</p>
    </div>
  </div>
  
  <!-- Hidden data element to store user email for JavaScript -->
<div id="userEmailData" data-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" style="display: none;"></div>

<!-- CSRF Token for secure API requests -->
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">

<!-- Feature Flags for JavaScript -->
<script>
window.featureFlags = <?php echo getFeatureFlagsJson(); ?>;
</script>

<div class="main-container">
    <header class="main-header">
      <div class="main-brand">
        <?php 
        // Use practice logo from database if available
        $hasPracticeLogo = !empty($practiceLogoPath);
        ?>
        <img
          src="<?php echo $hasPracticeLogo ? htmlspecialchars($practiceLogoPath) : ''; ?>"
          alt="Logo"
          class="main-logo"
          width="56"
          height="56"
          loading="lazy"
          decoding="async"
          <?php if (!$hasPracticeLogo): ?>style="display:none;"<?php endif; ?>
        >
        <div class="app-title-container">
          <h1><?php echo htmlspecialchars($appConfig['appName']); ?></h1>
          <?php if (!empty($practiceName)): ?>
          <?php if ($hasMultiplePractices): ?>
          <div class="practice-switcher" id="practiceSwitcher">
            <button type="button" class="practice-switcher-btn" id="practiceSwitcherBtn" aria-haspopup="true" aria-expanded="false">
              <span class="practice-switcher-name"><?php echo htmlspecialchars($practiceName); ?></span>
              <svg class="practice-switcher-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </button>
            <div class="practice-switcher-dropdown" id="practiceSwitcherDropdown">
              <?php foreach ($userPractices as $practice): ?>
              <button type="button" 
                      class="practice-switcher-item<?php echo ((int)$practice['id'] === (int)$currentPracticeId) ? ' active' : ''; ?>"
                      data-practice-id="<?php echo (int)$practice['id']; ?>"
                      <?php echo ((int)$practice['id'] === (int)$currentPracticeId) ? 'aria-current="true"' : ''; ?>>
                <span class="practice-item-name"><?php echo htmlspecialchars($practice['practice_name']); ?></span>
                <?php if ((int)$practice['id'] === (int)$currentPracticeId): ?>
                <svg class="practice-item-check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <?php endif; ?>
                <span class="practice-item-role"><?php echo htmlspecialchars(ucfirst($practice['role'])); ?></span>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="practice-name"><?php echo htmlspecialchars($practiceName); ?></div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="user-profile">
        <div class="user-info">
          <span class="user-name"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></span>
          <span class="user-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
<?php if (isFeatureEnabled('SHOW_BILLING')): ?>
          <a href="billing.php" class="billing-link" id="userBillingTier">Billing</a>
<?php endif; ?>
        </div>
<?php if (isFeatureEnabled('SHOW_NOTIFICATIONS')): ?>
        <!-- Notification Bell -->
        <div class="notification-bell-wrapper" style="position: relative;">
          <button type="button" class="notification-bell" id="notificationBell" title="Notifications">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <span id="notificationBadge" class="notification-badge hidden"></span>
          </button>
          <div id="notificationDropdown" class="notification-dropdown">
            <div class="notification-dropdown-header">
              <span class="notification-dropdown-title">Notifications</span>
              <button type="button" class="notification-mark-all" onclick="markAllNotificationsRead()">Mark all read</button>
            </div>
            <div id="notificationList" class="notification-dropdown-list">
              <div class="notification-dropdown-empty">Loading...</div>
            </div>
          </div>
        </div>
<?php endif; ?>
        
        <button type="button" class="user-avatar-button" id="userMenuToggle" aria-haspopup="true" aria-expanded="false">
          <?php if (!empty($user['picture'])): ?>
            <img src="<?php echo htmlspecialchars($user['picture']); ?>" alt="Profile picture" class="profile-image" referrerpolicy="no-referrer" crossorigin="anonymous">
          <?php else: ?>
            <span class="avatar-placeholder"><?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?></span>
          <?php endif; ?>
        </button>
        <div class="user-menu" id="userMenu">
          <a href="#" class="user-menu-item">Settings</a>
<?php if (isFeatureEnabled('SHOW_BILLING')): ?>
          <a href="billing.php" class="user-menu-item">Billing</a>
<?php endif; ?>
          <div class="user-menu-divider"></div>
          <a href="#" class="user-menu-item" id="contactUsLink">Feedback</a>
          <?php if (isFeatureEnabled('SHOW_TOUR')): ?>
          <a href="#" class="user-menu-item" id="startTourLink">Take a Tour</a>
          <?php endif; ?>
          <div class="user-menu-divider"></div>
          <a href="api/logout.php" class="user-menu-item">Sign Out</a>
        </div>
      </div>
    </header>

    <main class="dashboard">
      <!-- Main Tabs -->
      <div class="main-tabs">
        <button type="button" class="main-tab active" data-tab="cases">Cases</button>
        <button type="button" class="main-tab" data-tab="insights">Insights</button>
      </div>

      <!-- Tab Content -->
      <div class="main-tab-content">
        <!-- Cases Tab -->
        <div class="main-tab-pane active" id="cases-tab">
          <div class="dashboard-toolbar">
            <button type="button" class="create-case-button">+ Create new case</button>
            <div class="dashboard-toolbar-right">
              <button type="button" id="kanbanFilterToggle" class="filter-toggle-button">
                Filters
                <span id="kanbanFilterActiveDot" class="filter-active-dot" aria-hidden="true"></span>
              </button>
              <button type="button" class="view-archived-button" id="viewArchivedBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="3" width="18" height="4" rx="1" ry="1"></rect>
                  <path d="M5 7h14v14H5z"></path>
                  <path d="M10 12h4"></path>
                  <path d="M12 12v4"></path>
                </svg>
                View Archived Cases <span id="archivedCasesBadge" class="archived-count-badge" style="display: none;"></span>
              </button>
            </div>
          </div>

          <div id="kanbanFiltersBar" class="kanban-filters-bar">
        <div class="kanban-filters-row">
          <div class="kanban-filter-field kanban-search-field">
            <label for="patientSearch">Search</label>
            <input
              type="text"
              id="patientSearch"
              class="kanban-filter-input"
              placeholder="Patient or dentist name"
              autocomplete="off"
            />
          </div>
          
          <div class="kanban-filter-field">
            <label for="filterCaseType">Case Type</label>
            <select id="filterCaseType">
              <option value="">All types</option>
              <option value="Crown">Crown</option>
              <option value="Bridge">Bridge</option>
              <option value="Implant">Implant</option>
              <option value="AOX">AOX</option>
              <option value="Denture">Denture</option>
              <option value="Partial">Partial</option>
              <option value="Veneer">Veneer</option>
              <option value="Inlay/Onlay">Inlay/Onlay</option>
              <option value="Orthodontic Appliance">Orthodontic Appliance</option>
            </select>
          </div>

          <div class="kanban-filter-field">
            <label for="filterAssignedTo">Assigned To</label>
            <select id="filterAssignedTo">
              <option value="">Anyone</option>
              <!-- Options populated dynamically -->
            </select>
          </div>

          <div class="kanban-filter-field kanban-filter-checkbox">
            <label for="filterLateCases" class="filter-checkbox-label">
              <input type="checkbox" id="filterLateCases">
              Late cases only
            </label>
          </div>

<?php if (isFeatureEnabled('SHOW_AT_RISK')): ?>
          <div class="kanban-filter-field kanban-filter-checkbox">
            <label for="filterAtRisk" class="filter-checkbox-label">
              <input type="checkbox" id="filterAtRisk">
              At Risk only
            </label>
          </div>
<?php endif; ?>

          <div class="kanban-filter-field kanban-filter-actions">
            <button type="button" id="clearFiltersBtn" class="filter-clear-btn">Clear filters</button>
          </div>
        </div>
      </div>

      <section class="kanban-board">
        <div class="kanban-column">
          <div class="kanban-column-header">
            <h2 class="kanban-column-title">Originated</h2>
            <span class="kanban-column-count">0</span>
          </div>
          <div class="kanban-column-body">
            <p class="kanban-empty">No cases in this stage.</p>
          </div>
        </div>

        <div class="kanban-column">
          <div class="kanban-column-header">
            <h2 class="kanban-column-title">Sent To External Lab</h2>
            <span class="kanban-column-count">0</span>
          </div>
          <div class="kanban-column-body">
            <p class="kanban-empty">No cases in this stage.</p>
          </div>
        </div>

        <div class="kanban-column">
          <div class="kanban-column-header">
            <h2 class="kanban-column-title">Designed</h2>
            <span class="kanban-column-count">0</span>
          </div>
          <div class="kanban-column-body">
            <p class="kanban-empty">No cases in this stage.</p>
          </div>
        </div>

        <div class="kanban-column">
          <div class="kanban-column-header">
            <h2 class="kanban-column-title">Manufactured</h2>
            <span class="kanban-column-count">0</span>
          </div>
          <div class="kanban-column-body">
            <p class="kanban-empty">No cases in this stage.</p>
          </div>
        </div>

        <div class="kanban-column">
          <div class="kanban-column-header">
            <h2 class="kanban-column-title">Received From External Lab</h2>
            <span class="kanban-column-count">0</span>
          </div>
          <div class="kanban-column-body">
            <p class="kanban-empty">No cases in this stage.</p>
          </div>
        </div>

        <div class="kanban-column">
          <div class="kanban-column-header">
            <h2 class="kanban-column-title">Delivered</h2>
            <span class="kanban-column-count">0</span>
          </div>
          <div class="kanban-column-body">
            <p class="kanban-empty">No cases in this stage.</p>
          </div>
        </div>
      </section>
        </div>
        <!-- End Cases Tab -->

        <!-- Insights Tab (consolidated analytics + AI) -->
        <div class="main-tab-pane" id="insights-tab">
          <div class="analytics-pro">
            <!-- Header -->
            <div class="ap-header">
              <div class="ap-header-content">
                <div>
                  <h1 class="ap-title">Insights</h1>
                  <p class="ap-subtitle">Track your practice performance and case flow</p>
                </div>
                <div class="ap-header-actions">
                  <button type="button" class="ap-btn ap-btn-secondary" id="apRefreshData">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                    </svg>
                    Refresh
                  </button>
                </div>
              </div>
            </div>

            <!-- Metrics Grid -->
            <div class="ap-metrics-grid">
              <div class="ap-metric-card accent-blue">
                <div class="ap-metric-header">
                  <div class="ap-metric-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                      <line x1="16" y1="2" x2="16" y2="6"></line>
                      <line x1="8" y1="2" x2="8" y2="6"></line>
                      <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                  </div>
                </div>
                <div class="ap-metric-value" id="apCasesThisMonth">-</div>
                <div class="ap-metric-label">New This Month</div>
              </div>

              <div class="ap-metric-card accent-green">
                <div class="ap-metric-header">
                  <div class="ap-metric-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                      <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                  </div>
                </div>
                <div class="ap-metric-value" id="apActiveCases">-</div>
                <div class="ap-metric-label">Active Cases</div>
              </div>

              <div class="ap-metric-card accent-green">
                <div class="ap-metric-header">
                  <div class="ap-metric-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                      <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                  </div>
                </div>
                <div class="ap-metric-value" id="apDelivered">-</div>
                <div class="ap-metric-label">Delivered</div>
              </div>

              <div class="ap-metric-card accent-orange">
                <div class="ap-metric-header">
                  <div class="ap-metric-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>
                    </svg>
                  </div>
                </div>
                <div class="ap-metric-value" id="apArchived">-</div>
                <div class="ap-metric-label">Archived</div>
              </div>
            </div>

            <!-- Operational Overview Section -->
            <div class="ap-section">
              <div class="ap-section-header">
                <div class="ap-section-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                    <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                  </svg>
                </div>
                <div>
                  <h2 class="ap-section-title">Case Flow Status</h2>
                  <p class="ap-section-subtitle">Where is your money right now?</p>
                </div>
              </div>

              <div class="ap-status-grid">
                <div class="ap-status-card">
                  <div class="ap-status-header">
                    <div class="ap-status-indicator success"></div>
                    <h3 class="ap-status-title">On Track</h3>
                  </div>
                  <div class="ap-status-value" id="apOnTrack">0</div>
                  <div class="ap-status-label">Ready to schedule or deliver</div>
                </div>

<?php if (isFeatureEnabled('SHOW_AT_RISK')): ?>
                <div class="ap-status-card">
                  <div class="ap-status-header">
                    <div class="ap-status-indicator warning"></div>
                    <h3 class="ap-status-title">At Risk</h3>
                  </div>
                  <div class="ap-status-value" id="apAtRisk">0</div>
                  <div class="ap-status-label">Due soon — potential reschedule</div>
                </div>
<?php endif; ?>

                <div class="ap-status-card">
                  <div class="ap-status-header">
                    <div class="ap-status-indicator danger"></div>
                    <h3 class="ap-status-title">Late</h3>
                  </div>
                  <div class="ap-status-value" id="apDelayed">0</div>
                  <div class="ap-status-label">Revenue at risk — needs attention</div>
                </div>
              </div>
            </div>

            <!-- Charts Section -->
            <div class="ap-section">
              <div class="ap-section-header">
                <div class="ap-section-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                  </svg>
                </div>
                <div>
                  <h2 class="ap-section-title">Case Analysis</h2>
                  <p class="ap-section-subtitle">Distribution and breakdown of your cases</p>
                </div>
              </div>

              <div class="ap-charts-grid">
                <!-- Status Distribution Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title">Status Distribution</h3>
                      <p class="ap-chart-description">Current status of all cases</p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apStatusPeriod">
                        <option value="active" selected>Active Cases</option>
                        <option value="all">All Time</option>
                        <option value="3">Last 3 Months</option>
                        <option value="6">Last 6 Months</option>
                        <option value="12">Last 12 Months</option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apStatusChart"></canvas>
                  </div>
                </div>

                <!-- Case Type Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title">Case Type Breakdown</h3>
                      <p class="ap-chart-description">Distribution by case type</p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apTypePeriod">
                        <option value="active" selected>Active Cases</option>
                        <option value="all">All Time</option>
                        <option value="3">Last 3 Months</option>
                        <option value="6">Last 6 Months</option>
                        <option value="12">Last 12 Months</option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apTypeChart"></canvas>
                  </div>
                </div>
              </div>
            </div>

            <!-- Performance Section (Trends gated for Control) -->
            <div class="ap-section ap-control-only" data-control-feature="throughput-trends" id="throughputSection">
              <div class="ap-section-header">
                <div class="ap-section-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                  </svg>
                </div>
                <div>
                  <h2 class="ap-section-title">Throughput & Capacity</h2>
                  <p class="ap-section-subtitle">Case volume and team workload</p>
                </div>
              </div>

              <div class="ap-charts-grid">
                <!-- Monthly Volume Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title">Monthly Case Volume</h3>
                      <p class="ap-chart-description">Cases in vs cases out</p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apVolumePeriod">
                        <option value="1">Last Month</option>
                        <option value="3">Last 3 Months</option>
                        <option value="6">Last 6 Months</option>
                        <option value="12" selected>Last 12 Months</option>
                        <option value="24">Last 24 Months</option>
                        <option value="36">Last 36 Months</option>
                        <option value="all">All Time</option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apVolumeChart"></canvas>
                  </div>
                </div>

                <!-- Team Performance Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title">Team Workload</h3>
                      <p class="ap-chart-description">Cases per team member</p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apTeamFilter">
                        <option value="both" selected>All Assignees</option>
                        <option value="users">Practice Users Only</option>
                      </select>
                      <select class="ap-select" id="apTeamPeriod">
                        <option value="1">Last Month</option>
                        <option value="3">Last 3 Months</option>
                        <option value="6">Last 6 Months</option>
                        <option value="12" selected>Last 12 Months</option>
                        <option value="24">Last 24 Months</option>
                        <option value="36">Last 36 Months</option>
                        <option value="all">All Time</option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apTeamChart"></canvas>
                  </div>
                </div>
              </div>

              <!-- Upgrade overlay -->
              <div class="ap-upgrade-overlay">
                <div class="ap-upgrade-overlay-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                  </svg>
                </div>
                <h3>Throughput Trends</h3>
                <p>Unlock capacity analysis, workload imbalance detection, and trend insights.</p>
                <a href="billing.php" class="ap-upgrade-btn">Upgrade to Control</a>
              </div>
            </div>

            <!-- Status Duration Section (Historical comparison gated for Control) -->
            <div class="ap-section ap-control-only" data-control-feature="status-duration" id="statusDurationSection">
              <div class="ap-section-header">
                <div class="ap-section-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                  </svg>
                </div>
                <div>
                  <h2 class="ap-section-title">Status Duration Analytics</h2>
                  <p class="ap-section-subtitle">Average time cases spend in each status</p>
                </div>
              </div>

              <div class="ap-insights-grid">
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apAvgLifecycle">-</div>
                  <div class="ap-insight-label">Avg Case Lifecycle</div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apFastestCase">-</div>
                  <div class="ap-insight-label">Fastest Delivery</div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apSlowestCase">-</div>
                  <div class="ap-insight-label">Slowest Delivery</div>
                </div>
              </div>

              <div class="ap-charts-grid">
                <!-- Status Duration Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title">Average Time by Status</h3>
                      <p class="ap-chart-description">Days cases spend in each stage</p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apDurationPeriod">
                        <option value="active" selected>Active Cases</option>
                        <option value="all">All Time</option>
                        <option value="3">Last 3 Months</option>
                        <option value="6">Last 6 Months</option>
                        <option value="12">Last 12 Months</option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apDurationChart"></canvas>
                  </div>
                </div>

                <!-- Lifecycle Distribution Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title">Lifecycle Distribution</h3>
                      <p class="ap-chart-description">Case completion time ranges</p>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apLifecycleChart"></canvas>
                  </div>
                </div>
              </div>

              <!-- Upgrade overlay -->
              <div class="ap-upgrade-overlay">
                <div class="ap-upgrade-overlay-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                  </svg>
                </div>
                <h3>Duration Analytics</h3>
                <p>Unlock historical comparisons, outlier detection, and bottleneck identification.</p>
                <a href="billing.php" class="ap-upgrade-btn">Upgrade to Control</a>
              </div>
            </div>

            <!-- Trends Section (Control tier - blur for Operate) -->
            <div class="ap-section ap-control-only" data-control-feature="yoy-trends" id="yoyTrendsSection">
              <div class="ap-section-header">
                <div class="ap-section-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                  </svg>
                </div>
                <div>
                  <h2 class="ap-section-title">Year-over-Year Trends</h2>
                  <p class="ap-section-subtitle">Compare performance across years</p>
                </div>
              </div>

              <div class="ap-insights-grid">
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apPeakMonth">-</div>
                  <div class="ap-insight-label">Peak Season</div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apGrowthRate">0%</div>
                  <div class="ap-insight-label">YoY Growth</div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apNextPeak">-</div>
                  <div class="ap-insight-label">Next Peak</div>
                </div>
              </div>

              <div class="ap-chart-card full-width">
                <div class="ap-chart-header">
                  <div>
                    <h3 class="ap-chart-title">Year-over-Year Comparison</h3>
                    <p class="ap-chart-description">Monthly case volume comparison</p>
                  </div>
                </div>
                <div class="ap-chart-container">
                  <canvas id="apTrendsChart"></canvas>
                </div>
              </div>

              <!-- Upgrade overlay (shown when locked) -->
              <div class="ap-upgrade-overlay">
                <div class="ap-upgrade-overlay-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                  </svg>
                </div>
                <h3>Year-over-Year Trends</h3>
                <p>Unlock historical comparisons, peak season insights, and growth metrics.</p>
                <a href="billing.php" class="ap-upgrade-btn">Upgrade to Control</a>
              </div>
            </div>

            <!-- AI Recommendations Section (Control tier - blur for Operate) -->
            <div class="ap-section ap-control-only" data-control-feature="smart-recommendations" id="aiRecommendationsSection">
              <div class="ap-ai-section">
                <div class="ap-ai-header">
                  <div class="ap-ai-title-group">
                    <span class="ap-ai-badge">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                      </svg>
                      AI Powered
                    </span>
                    <div>
                      <h2 class="ap-ai-title">Smart Recommendations</h2>
                      <p class="ap-ai-subtitle">Personalized insights based on your practice data</p>
                    </div>
                  </div>
                  <button type="button" class="ap-btn ap-btn-secondary" id="apRefreshAI">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                    </svg>
                    Refresh
                  </button>
                </div>

                <div class="ap-recommendations-list" id="apRecommendations">
                  <!-- Loading State -->
                  <div class="ap-loading" id="apAILoading">
                    <div class="ap-loading-spinner"></div>
                    <p class="ap-loading-text">Analyzing your practice data...</p>
                  </div>
                </div>
              </div>

              <!-- Upgrade overlay (shown when locked) -->
              <div class="ap-upgrade-overlay">
                <div class="ap-upgrade-overlay-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                  </svg>
                </div>
                <h3>Smart Recommendations</h3>
                <p>Get AI-powered insights, bottleneck detection, and actionable recommendations.</p>
                <a href="billing.php" class="ap-upgrade-btn">Upgrade to Control</a>
              </div>
            </div>

            <!-- Loading Overlay -->
            <div class="ap-loading" id="apLoading" style="display: none;">
              <div class="ap-loading-spinner"></div>
              <p class="ap-loading-text">Loading analytics data...</p>
            </div>
          </div>
        </div>
        <!-- End Insights Tab -->
      </div>
      <!-- End Tab Content -->
    </main>

      <!-- Create Case Modal -->
      <div id="createCaseModal" class="modal">
        <div class="modal-content create-case-modal">
          <div class="modal-header">
            <h2 class="modal-title">Create New Case</h2>
            <button type="button" class="btn-close" id="createCaseClose"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            <div class="case-modal-tabs">
              <button type="button" class="case-tab case-tab-active" data-tab="details">Details</button>
<?php if (isFeatureEnabled('SHOW_COMMENTS')): ?>
              <button type="button" class="case-tab" data-tab="comments">Comments <span id="caseCommentsCount" class="case-comments-count" style="display: none;">0</span></button>
<?php endif; ?>
<?php if (isFeatureEnabled('SHOW_REVISION_HISTORY')): ?>
              <button type="button" class="case-tab" data-tab="history">Revision History</button>
<?php endif; ?>
            </div>

            <form id="createCaseForm" class="case-tab-panel case-tab-panel-active" enctype="multipart/form-data" novalidate>
<?php if (isFeatureEnabled('SHOW_ACTIVITY_TIMELINE')): ?>
              <!-- Activity Timeline (visible when editing existing cases) -->
              <div id="caseActivityTimeline" class="activity-timeline-horizontal" style="display: none;">
                <div class="activity-timeline-header">
                  <span class="activity-timeline-label">Activity</span>
                  <span class="activity-timeline-toggle" id="activityTimelineToggle" title="Expand/collapse">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                  </span>
                </div>
                <div id="activityTimelineContent" class="activity-timeline-track">
                  <p class="activity-empty-state">No activity recorded yet.</p>
                </div>
              </div>
<?php endif; ?>

              <div class="modal-form-grid">
                <div class="form-field">
                  <label for="patientFirstName">Patient First Name <span class="required">*</span></label>
                  <input id="patientFirstName" name="patientFirstName" type="text" required>
                </div>

                <div class="form-field">
                  <label for="patientLastName">Patient Last Name <span class="required">*</span></label>
                  <input id="patientLastName" name="patientLastName" type="text" required>
                </div>

                <div class="form-field">
                  <label for="patientDOB">Patient DOB <span class="required">*</span></label>
                  <input id="patientDOB" name="patientDOB" type="date"
                         placeholder="mm/dd/yyyy" title="mm/dd/yyyy" required>
                </div>

                <div class="form-field">
                  <label for="patientGender">Gender <span class="required">*</span></label>
                  <select id="patientGender" name="patientGender" required>
                    <option value="">Select gender...</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="dentistName">Dentist Name <span class="required">*</span></label>
                  <div class="autocomplete-wrapper">
                    <input id="dentistName" name="dentistName" type="text" required autocomplete="off" aria-autocomplete="list" aria-controls="dentistNameSuggestions">
                    <div id="dentistNameSuggestions" class="autocomplete-dropdown" role="listbox" aria-label="Dentist name suggestions"></div>
                  </div>
                </div>

                <div class="form-field">
                  <label for="caseType">Case Type <span class="required">*</span></label>
                  <select id="caseType" name="caseType" required>
                    <option value="">Select case type...</option>
                    <option>Crown</option>
                    <option>Bridge</option>
                    <option>Implant Crown</option>
                    <option>Implant Surgical Guide</option>
                    <option>AOX</option>
                    <option>Denture</option>
                    <option>Partial</option>
                    <option>Veneer</option>
                    <option>Inlay/Onlay</option>
                    <option>Orthodontic Appliance</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="toothShade">Tooth Shade</label>
                  <input id="toothShade" name="toothShade" type="text" placeholder="e.g. A2, B1" title="e.g. A2, B1">
                </div>

                <div class="form-field">
                  <label for="material">Material</label>
                  <select id="material" name="material">
                    <option value="">Select material...</option>
                    <option>Zirconia</option>
                    <option>Lithium Disilicate</option>
                    <option>PFM</option>
                    <option>PFZ</option>
                    <option>3D Printed</option>
                  </select>
                </div>
              </div>

              <!-- Clinical Details Section (case-type-specific fields) -->
              <div id="clinicalDetailsSection" class="clinical-details-section" style="display: none;">
                <h3 class="clinical-details-title">Clinical Details</h3>
                <div class="clinical-details-grid">
                  <!-- Crown fields -->
                  <div class="form-field clinical-field" data-case-types="Crown" data-conditionally-required="true">
                    <label for="clinicalToothNumber">Tooth # <span class="required">*</span></label>
                    <input id="clinicalToothNumber" name="clinicalToothNumber" type="text" placeholder="e.g. 14, 30">
                  </div>

                  <!-- Bridge fields -->
                  <div class="form-field clinical-field" data-case-types="Bridge" data-conditionally-required="true">
                    <label for="clinicalAbutmentTeeth">Abutment Teeth <span class="required">*</span></label>
                    <input id="clinicalAbutmentTeeth" name="clinicalAbutmentTeeth" type="text" placeholder="e.g. 3, 5">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Bridge" data-conditionally-required="true">
                    <label for="clinicalPonticTeeth">Pontic Teeth <span class="required">*</span></label>
                    <input id="clinicalPonticTeeth" name="clinicalPonticTeeth" type="text" placeholder="e.g. 4">
                  </div>

                  <!-- Implant Crown fields -->
                  <div class="form-field clinical-field" data-case-types="Implant Crown" data-conditionally-required="true">
                    <label for="clinicalImplantToothNumber">Tooth # <span class="required">*</span></label>
                    <input id="clinicalImplantToothNumber" name="clinicalImplantToothNumber" type="text" placeholder="e.g. 14, 30">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown">
                    <label for="clinicalAbutmentType">Abutment Type</label>
                    <select id="clinicalAbutmentType" name="clinicalAbutmentType">
                      <option value="">Select type...</option>
                      <option value="Custom">Custom</option>
                      <option value="Ti-Base">Ti-Base</option>
                      <option value="Zirconia">Zirconia</option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown,Implant Surgical Guide">
                    <label for="clinicalImplantSystem">Implant System</label>
                    <input id="clinicalImplantSystem" name="clinicalImplantSystem" type="text" placeholder="e.g. Straumann, Nobel">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown,Implant Surgical Guide">
                    <label for="clinicalPlatformSize">Platform Size</label>
                    <input id="clinicalPlatformSize" name="clinicalPlatformSize" type="text" placeholder="e.g. 4.1mm">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown,Implant Surgical Guide">
                    <label for="clinicalScanBodyUsed">Scan Body Used</label>
                    <input id="clinicalScanBodyUsed" name="clinicalScanBodyUsed" type="text" placeholder="e.g. Elos Accurate">
                  </div>

                  <!-- Implant Surgical Guide fields -->
                  <div class="form-field clinical-field" data-case-types="Implant Surgical Guide">
                    <label for="clinicalImplantSites">Implant Site(s)</label>
                    <input id="clinicalImplantSites" name="clinicalImplantSites" type="text" placeholder="e.g. 14, 15, 16">
                  </div>

                  <!-- Denture fields -->
                  <div class="form-field clinical-field" data-case-types="Denture">
                    <label for="clinicalDentureJaw">Jaw</label>
                    <select id="clinicalDentureJaw" name="clinicalDentureJaw">
                      <option value="">Select jaw...</option>
                      <option value="Maxillary">Maxillary</option>
                      <option value="Mandibular">Mandibular</option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Denture">
                    <label for="clinicalDentureType">Type</label>
                    <select id="clinicalDentureType" name="clinicalDentureType">
                      <option value="">Select type...</option>
                      <option value="Immediate">Immediate</option>
                      <option value="Definitive">Definitive</option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Denture,AOX">
                    <label for="clinicalGingivalShade">Gingival Shade</label>
                    <input id="clinicalGingivalShade" name="clinicalGingivalShade" type="text" placeholder="Optional">
                  </div>

                  <!-- Partial fields -->
                  <div class="form-field clinical-field" data-case-types="Partial">
                    <label for="clinicalPartialJaw">Jaw</label>
                    <select id="clinicalPartialJaw" name="clinicalPartialJaw">
                      <option value="">Select jaw...</option>
                      <option value="Maxillary">Maxillary</option>
                      <option value="Mandibular">Mandibular</option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Partial" data-conditionally-required="true">
                    <label for="clinicalTeethToReplace">Teeth to be Replaced <span class="required">*</span></label>
                    <input id="clinicalTeethToReplace" name="clinicalTeethToReplace" type="text" placeholder="e.g. 3, 4, 5">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Partial">
                    <label for="clinicalPartialMaterial">Material</label>
                    <select id="clinicalPartialMaterial" name="clinicalPartialMaterial">
                      <option value="">Select material...</option>
                      <option value="Cast Metal">Cast Metal</option>
                      <option value="Valplast Flex Resin">Valplast Flex Resin</option>
                      <option value="Acrylic Base">Acrylic Base</option>
                      <option value="Interim Acrylic">Interim Acrylic</option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Partial">
                    <label for="clinicalPartialGingivalShade">Gingival Shade</label>
                    <input id="clinicalPartialGingivalShade" name="clinicalPartialGingivalShade" type="text" placeholder="Optional">
                  </div>
                </div>
              </div>

              <!-- Continue with workflow fields -->
              <div class="modal-form-grid">
                <div class="form-field">
                  <label for="dueDate">Due Date <span class="required">*</span></label>
                  <input id="dueDate" name="dueDate" type="date"
                         placeholder="mm/dd/yyyy" title="mm/dd/yyyy" required>
                </div>

                <div class="form-field">
                  <label for="status">Status <span class="required">*</span></label>
                  <select id="status" name="status" required>
                    <option value="">Select status...</option>
                    <option value="Originated" selected>Originated</option>
                    <option>Sent To External Lab</option>
                    <option>Designed</option>
                    <option>Manufactured</option>
                    <option>Received From External Lab</option>
                    <option>Delivered</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="assignedTo">Assigned To</label>
                  <select id="assignedTo" name="assignedTo">
                    <option value="">Select user...</option>
                  </select>
                </div>

                <div class="form-field form-field-notes">
                  <label for="notes">Notes</label>
                  <div class="char-counter-wrapper">
                    <textarea id="notes" name="notes" rows="3" maxlength="3000"
                              placeholder="Optional notes about this case"
                              aria-describedby="notesCharCounter"></textarea>
                    <div id="notesCharCounter" class="char-counter">0 / 3,000 characters</div>
                  </div>
                </div>
              </div>

              <h3 class="attachments-title">Attachments (optional)</h3>
              <div class="attachments-grid">
                <div class="attachment-group">
                  <div class="attachment-header">Photos</div>
                  <label class="file-button" tabindex="0">
                    Select files
                    <input type="file" name="photos[]" multiple accept="image/*" class="attachment-input" data-type="photos">
                  </label>
                  <!-- Make sure ID is both lowercase and matches API's Photos type -->
                  <div class="selected-files" id="photos-files" data-type="photos" data-api-type="Photos"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-header">Intraoral Scans</div>
                  <label class="file-button" tabindex="0">
                    Select files
                    <input type="file" name="intraoralScans[]" multiple class="attachment-input" data-type="intraoralScans">
                  </label>
                  <!-- Make sure ID is both lowercase and matches API's IntraoralScans type -->
                  <div class="selected-files" id="intraoralScans-files" data-type="intraoralScans" data-api-type="IntraoralScans"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-header">Facial Scans</div>
                  <label class="file-button" tabindex="0">
                    Select files
                    <input type="file" name="facialScans[]" multiple class="attachment-input" data-type="facialScans">
                  </label>
                  <!-- Make sure ID is both lowercase and matches API's FacialScans type -->
                  <div class="selected-files" id="facialScans-files" data-type="facialScans" data-api-type="FacialScans"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-header">Photogrammetry</div>
                  <label class="file-button" tabindex="0">
                    Select files
                    <input type="file" name="photogrammetry[]" multiple class="attachment-input" data-type="photogrammetry">
                  </label>
                  <!-- Make sure ID is both lowercase and matches API's Photogrammetry type -->
                  <div class="selected-files" id="photogrammetry-files" data-type="photogrammetry" data-api-type="Photogrammetry"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-header">Completed Designs</div>
                  <label class="file-button" tabindex="0">
                    Select files
                    <input type="file" name="completedDesigns[]" multiple class="attachment-input" data-type="completedDesigns">
                  </label>
                  <!-- Make sure ID is both lowercase and matches API's CompletedDesigns type -->
                  <div class="selected-files" id="completedDesigns-files" data-type="completedDesigns" data-api-type="CompletedDesigns"></div>
                </div>
              </div>

              <div class="modal-footer create-case-footer">
                <button type="button" class="btn-primary" id="createCaseSubmit">Create Case</button>
                <button type="button" class="btn-cancel" id="createCaseCancel">Cancel</button>
              </div>
            </form>

<?php if (isFeatureEnabled('SHOW_COMMENTS')): ?>
            <div id="caseCommentsPanel" class="case-tab-panel">
              <div class="case-comments-section">
                <div id="caseCommentsList" class="case-comments-list">
                  <div class="case-comments-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <div>No comments yet</div>
                    <div style="font-size: 0.75rem; margin-top: 4px;">Use @mentions to notify team members</div>
                  </div>
                </div>
                <div class="case-comment-input-wrapper">
                  <div id="mentionAutocomplete" class="mention-autocomplete"></div>
                  <textarea id="caseCommentInput" class="case-comment-input" placeholder="Add a comment... Use @ to mention team members" rows="2"></textarea>
                  <div class="case-comment-actions">
                    <span class="case-comment-hint">Press Enter to add, Shift+Enter for new line</span>
                    <button type="button" id="caseCommentSubmit" class="case-comment-submit" disabled>Add Comment</button>
                  </div>
                </div>
              </div>
            </div>
<?php endif; ?>

<?php if (isFeatureEnabled('SHOW_REVISION_HISTORY')): ?>
            <div id="caseRevisionHistoryPanel" class="case-tab-panel">
              <div id="caseRevisionHistory" class="case-revision-history">
                <p class="revision-empty-state">Select a case to see its revision history.</p>
              </div>
            </div>
<?php endif; ?>
          </div>
        </div>
      </div>
      
      <!-- Delete Confirmation Modal -->
      <div id="deleteConfirmModal" class="modal">
        <div class="modal-content delete-confirm-modal">
          <div class="modal-header">
            <h2 class="modal-title">Confirm Deletion</h2>
            <button type="button" class="btn-close" id="deleteConfirmClose"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            <div class="delete-confirm-icon">
              <span>🗑️</span>
            </div>
            <p class="delete-confirm-message">Are you sure you want to delete this file?</p>
            <p class="delete-confirm-warning">This action cannot be undone.</p>
          </div>

          <div class="modal-footer delete-confirm-footer">
            <button type="button" class="btn-delete" id="deleteConfirmDelete">Delete File</button>
            <button type="button" class="btn-cancel" id="deleteConfirmCancel">Cancel</button>
          </div>
        </div>
      </div>
      
      <!-- Feedback Success Modal -->
      <div id="feedbackSuccessModal" class="modal">
        <div class="modal-content feedback-success-modal">
          <div class="modal-header success-header">
            <h2 class="modal-title">Thank You!</h2>
            <button type="button" class="btn-close" id="feedbackSuccessClose"><span>&times;</span></button>
          </div>
          <div class="modal-body success-body">
            <div class="success-icon">
              <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="32" cy="32" r="30" stroke="#4CAF50" stroke-width="4"/>
                <path d="M20 32L28 40L44 24" stroke="#4CAF50" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <p class="success-message">Your feedback has been received!</p>
            <p class="success-details">Your input helps us improve DentaTrak for everyone.</p>
            <div class="modal-footer">
              <button type="button" class="btn-success" id="feedbackSuccessOk">OK</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Support Modal -->
      <div id="feedbackModal" class="modal">
        <div class="modal-content contact-modal">
          <div class="modal-header">
            <h2 class="modal-title">Send Feedback</h2>
            <button type="button" class="btn-close" id="feedbackClose"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <!-- Feedback Form -->
            <div class="feedback-content">
              <form id="feedbackForm">
                <div class="form-field">
                  <label class="feedback-question">How was your experience?</label>
                  <div class="emoji-container">
                    <label class="emoji-option">
                      <input type="radio" name="feedback_type" value="positive" required>
                      <div class="emoji-face happy-face">😊</div>
                      <span class="emoji-label">Positive</span>
                    </label>
                    <label class="emoji-option">
                      <input type="radio" name="feedback_type" value="neutral" required>
                      <div class="emoji-face neutral-face">😐</div>
                      <span class="emoji-label">Neutral</span>
                    </label>
                    <label class="emoji-option">
                      <input type="radio" name="feedback_type" value="negative" required>
                      <div class="emoji-face sad-face">😞</div>
                      <span class="emoji-label">Negative</span>
                    </label>
                  </div>
                </div>
                <div class="form-field">
                  <label for="feedback_comments">Tell us more about your experience</label>
                  <textarea id="feedback_comments" name="feedback_comments" rows="4" placeholder="What did you like? What could be improved? Please do not include any patient information (PII/PHI) in your feedback."></textarea>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn-primary" id="feedbackSubmit">Send Feedback</button>
                  <button type="button" class="btn-cancel" id="feedbackCancel">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Archived Cases Modal -->
      <div id="archivedCasesModal" class="modal">
        <div class="modal-content archived-cases-modal">
          <div class="modal-header">
            <h2 class="modal-title">Archived Cases</h2>
            <button type="button" class="btn-close" id="archivedCasesClose"><span>&times;</span></button>
          </div>
          
          <!-- Fixed Search Header -->
          <div class="archived-search-header">
            <div class="archived-filters">
              <div class="archived-search">
                <label for="archivedSearch" class="sr-only">Search archived cases by patient or dentist</label>
                <input type="text" id="archivedSearch" placeholder="Search by patient name or dentist...">
              </div>
              <div class="archived-filter-controls">
                <label for="archivedDateRange" class="sr-only">Filter archived cases by date range</label>
                <select id="archivedDateRange">
                  <option value="">All dates</option>
                  <option value="7">Last 7 days</option>
                  <option value="30">Last 30 days</option>
                  <option value="90">Last 90 days</option>
                  <option value="365">Last year</option>
                </select>
                <label for="archivedCaseType" class="sr-only">Filter archived cases by case type</label>
                <select id="archivedCaseType">
                  <option value="">All types</option>
                  <option value="Crown">Crown</option>
                  <option value="Bridge">Bridge</option>
                  <option value="Implant">Implant</option>
                  <option value="AOX">AOX</option>
                  <option value="Denture">Denture</option>
                  <option value="Veneer">Veneer</option>
                  <option value="Inlay/Onlay">Inlay/Onlay</option>
                  <option value="Partial">Partial</option>
                  <option value="Orthodontic Appliance">Orthodontic Appliance</option>
                </select>
              </div>
            </div>
            <div class="archived-count">
              <span id="archivedCount">Loading...</span>
            </div>
          </div>
          
          <div class="modal-body">
            <!-- Table Container -->
            <div class="archived-table-container">
              <table class="archived-cases-table">
                <thead>
                  <tr>
                    <th>Patient Name</th>
                    <th>Dentist</th>
                    <th>Case Type</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Archived</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="archivedCasesTableBody">
                  <tr><td colspan="7" class="loading-row">Loading archived cases...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          
          <!-- Fixed Footer -->
          <div class="modal-footer">
            <div class="archived-pagination">
              <div class="pagination-left">
                <label for="archivedPageSize" class="sr-only">Archived results per page</label>
                <select id="archivedPageSize">
                  <option value="10">10 per page</option>
                  <option value="25" selected>25 per page</option>
                  <option value="50">50 per page</option>
                  <option value="100">100 per page</option>
                </select>
              </div>
              <div class="pagination-center">
                <button type="button" id="archivedPrevPage">Previous</button>
                <span id="archivedPageInfo">Page 1 of 1</span>
                <button type="button" id="archivedNextPage">Next</button>
              </div>
              <div class="pagination-right">
                <button type="button" class="btn-cancel" id="archivedCasesFooterClose">Close</button>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Settings Modal -->
      <div id="settingsBillingModal" class="modal">
        <div class="modal-content settings-billing-modal">
          <div class="modal-header">
            <h2 class="modal-title">Settings</h2>
            <button type="button" class="btn-close" id="settingsBillingClose"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            
            <!-- Tab Content -->
            <div class="tab-content-scroll">
              <div class="tab-content">
              <!-- Settings Tab -->
              <div class="tab-pane active" id="settingsTab">
                <form id="settingsForm">
                  
                  
                  <div class="settings-twisty" data-twisty-id="practice">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title">Practice</span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        <?php
                        // Fetch current practice information including BAA fields
                        $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
                        $practiceName = '';
                        $legalNameDisplay = '';
                        $displayNameValue = '';
                        $baaAcceptedDisplay = false;
                        $baaAcceptedAtDisplay = '';
                        $baaVersionDisplay = '';
                        $isAdmin = false;
                        
                        if ($currentPracticeId) {
                            try {
                                $stmt = $pdo->prepare("
                                    SELECT p.practice_name, p.legal_name, p.display_name,
                                           p.baa_accepted, p.baa_accepted_at, p.baa_version,
                                           pu.role
                                    FROM practices p
                                    JOIN practice_users pu ON p.id = pu.practice_id
                                    WHERE p.id = :practice_id AND pu.user_id = :user_id
                                ");
                                $stmt->execute([
                                    'practice_id' => $currentPracticeId,
                                    'user_id' => $_SESSION['db_user_id']
                                ]);
                                $practiceInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($practiceInfo) {
                                    $practiceName = $practiceInfo['practice_name'];
                                    $legalNameDisplay = $practiceInfo['legal_name'] ?? $practiceName;
                                    $displayNameValue = $practiceInfo['display_name'] ?? $practiceName;
                                    $baaAcceptedDisplay = (bool)($practiceInfo['baa_accepted'] ?? false);
                                    $baaAcceptedAtDisplay = $practiceInfo['baa_accepted_at'] ?? '';
                                    $baaVersionDisplay = $practiceInfo['baa_version'] ?? '';
                                    $isAdmin = ($practiceInfo['role'] === 'admin');
                                }
                            } catch (PDOException $e) {
                                // Handle error silently - BAA columns may not exist yet
                            }
                        }
                        ?>
                        
                        <!-- Legal Practice Name -->
                        <div class="option-row option-row-inline">
                          <label>Legal Practice Name</label>
                          <div class="inline-value-group">
                            <span class="legal-name-display"><?= htmlspecialchars($legalNameDisplay) ?></span>
                            <span class="field-note-inline">Set during BAA acceptance. Cannot be changed.</span>
                          </div>
                        </div>
                        
                        <!-- Display Name (Editable) -->
                        <div class="option-row option-row-inline">
                          <label for="displayName">Display Name</label>
                          <div class="inline-value-group">
                            <input type="text" id="displayName" name="displayName" value="<?= htmlspecialchars($displayNameValue) ?>" <?= $isAdmin ? '' : 'disabled' ?>>
                            <span class="field-note-inline">Shown in the UI. Can be changed anytime.</span>
                          </div>
                        </div>
                        
                        <!-- Practice Logo (moved here, under Display Name) -->
                        <div class="option-row option-row-inline option-row-logo">
                          <label for="practiceLogo">Practice Logo</label>
                          <div class="logo-upload-container">
                            <div class="current-logo" id="currentLogo" style="display: none;">
                              <img id="currentLogoImg" src="" alt="Current logo" class="logo-preview">
                              <button type="button" id="deleteLogo" class="btn-delete-logo" <?= $isAdmin ? '' : 'disabled' ?>>Remove</button>
                            </div>
                            <div class="logo-upload" id="logoUpload">
                              <input type="file" id="practiceLogo" name="practiceLogo" accept="image/*" class="logo-input" <?= $isAdmin ? '' : 'disabled' ?>>
                              <label for="practiceLogo" class="logo-upload-label <?= $isAdmin ? '' : 'disabled' ?>">
                                <span class="upload-icon">📁</span>
                                <span class="upload-text">Choose logo file</span>
                              </label>
                              <div class="logo-upload-info">JPG, PNG, GIF, SVG, WebP (max 5MB)</div>
                            </div>
                          </div>
                        </div>
                        
                        <div class="settings-divider"></div>
                        
                        <!-- BAA Status Section -->
                        <div class="baa-status-section">
                          <h4 class="subsection-title">Business Associate Agreement</h4>
                          
                          <div class="baa-info-grid">
                            <div class="baa-info-item">
                              <span class="baa-info-label">Status</span>
                              <span class="baa-status-badge <?= $baaAcceptedDisplay ? 'accepted' : 'pending' ?>">
                                <?= $baaAcceptedDisplay ? '✓ Accepted' : '⚠ Pending' ?>
                              </span>
                            </div>
                            
                            <?php if ($baaAcceptedDisplay && $baaAcceptedAtDisplay): ?>
                            <div class="baa-info-item">
                              <span class="baa-info-label">Accepted</span>
                              <span class="baa-info-value"><?= htmlspecialchars(date('M j, Y, g:i A', strtotime($baaAcceptedAtDisplay))) ?></span>
                            </div>
                            
                            <div class="baa-info-item">
                              <span class="baa-info-label">Version</span>
                              <span class="baa-info-value"><?= htmlspecialchars($baaVersionDisplay) ?></span>
                            </div>
                          </div>
                          
                          <div class="baa-download-row">
                            <a href="api/download-baa.php" class="btn-download-baa-small" download>
                              📄 Download BAA
                            </a>
                            <?php endif; ?>
                          </div>
                        </div>
                        
                      </div>
                    </div>
                  </div>
                  
                  <div class="settings-twisty" data-twisty-id="display">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title">Display &amp; Behavior</span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        <!-- Theme selector hidden for now - may be added back later
                        <div class="option-row">
                          <label for="theme">Select Theme</label>
                          <select id="theme" name="theme" class="theme-dropdown">
                            <option value="light">Light</option>
                            <option value="dark">Dark</option>
                          </select>
                        </div>
                        -->

                        <div class="option-row">
                          <label for="deliveredHideDays">Archive delivered cases older than</label>
                          <input type="number" id="deliveredHideDays" name="deliveredHideDays" min="0" max="365" value="120" class="number-input" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span class="option-text">days (0 = show all)</span>
                        </div>
                      </div>
                      
                      <div class="settings-divider"></div>
                      
                      <div class="settings-group">
                        <div class="option-row">
                          <label for="allowCardDelete">Allow archiving of individual cases</label>
                          <input type="checkbox" id="allowCardDelete" name="allowCardDelete" <?= $isAdmin ? '' : 'disabled' ?>>
                        </div>
                        
                        <div class="option-row highlight-past-due-row">
                          <label for="highlightPastDue">Highlight past due cases</label>
                          <input type="checkbox" id="highlightPastDue" name="highlightPastDue" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span id="pastDueSettings" class="past-due-inline-settings hidden">
                            <label for="pastDueDays" class="settings-sublabel">Days past due before highlighting:</label>
                            <input type="number" id="pastDueDays" name="pastDueDays" min="1" max="99" value="7" class="number-input" <?= $isAdmin ? '' : 'disabled' ?>>
                          </span>
                        </div>
                        
                        <?php if (isFeatureEnabled('SHOW_GOOGLE_DRIVE_BACKUP')): ?>
                        <div class="option-row" id="googleDriveBackupRow">
                          <label for="googleDriveBackup">Backup case data to Google Drive</label>
                          <input type="checkbox" id="googleDriveBackup" name="googleDriveBackup" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span id="googleDriveBackupNote" class="field-note" style="display: block; margin-top: 4px; margin-left: 8px; font-size: 12px; color: #666;">All practice cases will be backed up to a shared Google Drive folder.</span>
                          <span id="googleDriveWorkspaceWarning" class="field-note" style="display: none; margin-top: 4px; margin-left: 8px; font-size: 12px; color: #d97706;">⚠️ Requires Google Workspace with a signed BAA for HIPAA compliance.</span>
                        </div>
                        <?php endif; ?>
                      </div>
                      
                      <?php if (!$isAdmin): ?>
                      <p class="admin-only-note" style="font-size:0.8rem;color:#6b7280;margin-top:8px;font-style:italic;">These settings can only be changed by practice administrators.</p>
                      <?php endif; ?>
                    </div>
                  </div>
                  
                  <div class="settings-divider"></div>

                  <div class="settings-twisty" data-twisty-id="authorized">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title">Practice Users &amp; Roles</span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        <div class="gmail-users-container">
                          <div class="add-gmail-user">
                            <div class="gmail-input-row">
                              <input type="email" id="newGmailUser" placeholder="user@example.com" class="gmail-input">
                              <button type="button" id="addGmailUser" class="add-gmail-btn">Add User</button>
                            </div>
                            <div id="gmailError" class="error-message"></div>
                          </div>

                          <div id="gmailUsersList">
                            <!-- Practice users grid will be rendered here -->
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="settings-divider"></div>
                  
                  <!-- Security Section -->
                  <div class="settings-twisty" data-twisty-id="security">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title">Security</span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        
                        <!-- Change Password Section - Only shown for users with password-based login -->
                        <?php if ($userHasPassword): ?>
                        <div class="security-section">
                          <h4 class="subsection-title">Change Password</h4>
                          <div id="changePasswordForm" class="security-form">
                            <div class="form-field">
                              <label for="currentPassword">Current Password <span class="required">*</span></label>
                              <div class="password-input-wrapper">
                                <input type="password" id="currentPassword" name="currentPassword" autocomplete="off" required>
                                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="currentPassword">
                                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                              </div>
                            </div>
                            <div class="form-field">
                              <label for="newPassword">New Password</label>
                              <div class="password-input-wrapper">
                                <input type="password" id="newPassword" name="newPassword" autocomplete="new-password">
                                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="newPassword">
                                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                              </div>
                              <div class="password-requirements" id="newPasswordReqs">
                                <span class="req" id="pwReqLength">✗ At least 8 characters</span>
                                <span class="req" id="pwReqUpper">✗ One uppercase letter</span>
                                <span class="req" id="pwReqNumber">✗ One number</span>
                                <span class="req" id="pwReqSpecial">✗ One special character</span>
                              </div>
                            </div>
                            <div class="form-field">
                              <label for="confirmNewPassword">Confirm New Password</label>
                              <div class="password-input-wrapper">
                                <input type="password" id="confirmNewPassword" name="confirmNewPassword" autocomplete="new-password">
                                <button type="button" class="password-toggle-btn" aria-label="Show password" data-target="confirmNewPassword">
                                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                              </div>
                              <div id="passwordMatchStatus" class="password-match"></div>
                            </div>
                            <div id="changePasswordError" class="form-error" style="display: none;"></div>
                            <div id="changePasswordSuccess" class="form-success" style="display: none;"></div>
                            <button type="button" id="changePasswordBtn" class="btn-secondary">Change Password</button>
                          </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Two-Factor Authentication Section -->
                        <div class="security-section">
                          <h4 class="subsection-title">Two-Factor Authentication</h4>
                          <div id="twoFactorSection">
                            <div id="twoFactorStatus" class="two-factor-status">
                              <span class="status-badge status-disabled">Disabled</span>
                              <p class="status-description">Add an extra layer of security to your account by requiring a verification code from your authenticator app.</p>
                            </div>
                            
                            <!-- Setup Flow (hidden by default) -->
                            <div id="twoFactorSetup" class="two-factor-setup" style="display: none;">
                              <div class="setup-step">
                                <p><strong>Step 1:</strong> Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.)</p>
                                <div id="twoFactorQRCode" class="qr-code-container"></div>
                                <p class="manual-entry">Or enter this code manually: <code id="twoFactorSecret"></code></p>
                              </div>
                              <div class="setup-step">
                                <p><strong>Step 2:</strong> Enter the 6-digit verification code from your app</p>
                                <div class="verification-input-group">
                                  <input type="text" id="twoFactorVerifyCode" maxlength="6" pattern="[0-9]*" inputmode="numeric" placeholder="000000" autocomplete="one-time-code">
                                  <button type="button" id="verifyTwoFactorBtn" class="btn-primary">Verify & Enable</button>
                                </div>
                                <div id="twoFactorSetupError" class="form-error" style="display: none;"></div>
                              </div>
                              <button type="button" id="cancelTwoFactorSetup" class="btn-link">Cancel Setup</button>
                            </div>
                            
                            <!-- Disable Flow (hidden by default) -->
                            <div id="twoFactorDisable" class="two-factor-disable" style="display: none;">
                              <p>Are you sure you want to disable two-factor authentication? This will make your account less secure.</p>
                              <div class="disable-actions">
                                <button type="button" id="confirmDisableTwoFactor" class="btn-danger">Disable 2FA</button>
                                <button type="button" id="cancelDisableTwoFactor" class="btn-link">Cancel</button>
                              </div>
                              <div id="twoFactorDisableError" class="form-error" style="display: none;"></div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div id="twoFactorActions" class="two-factor-actions">
                              <button type="button" id="enableTwoFactorBtn" class="btn-secondary">Enable 2FA</button>
                              <button type="button" id="disableTwoFactorBtn" class="btn-outline-danger" style="display: none;">Disable 2FA</button>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="settings-divider"></div>
                  
                  <!-- Data & Privacy Section -->
                  <div class="settings-twisty" data-twisty-id="data-privacy">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title">Data &amp; Privacy</span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        <div class="data-export-section">
                          <h4 class="subsection-title">Export Your Data</h4>
                          <p class="section-description">Download a copy of all your data including cases, notes, activity history, and settings.</p>
                          <div id="exportStatus" class="export-status" style="display: none;"></div>
                          <button type="button" id="exportDataBtn" class="btn-secondary">
                            <span class="btn-icon">📥</span> Export All Data
                          </button>
                          <p class="export-note">Your data will be prepared and a download link will be sent to your email. Links expire after 7 days.</p>
                        </div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="button-container">
                    <button type="button" class="save-settings-btn" id="saveSettings">Save Settings</button>
                    <button type="button" class="btn-cancel" id="settingsCancel">Cancel</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="main-copyright">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appConfig['appName']); ?>. All rights reserved.</div>
    </main>
  </div>
  
  <!-- Google Drive Backup Confirmation Modal -->
  <?php if (isFeatureEnabled('SHOW_GOOGLE_DRIVE_BACKUP')): ?>
  <div id="googleDriveBackupModal" class="delete-confirm-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:999999; align-items:center; justify-content:center;">
    <div class="delete-confirm-content" style="background:#fff; padding:20px; border-radius:8px; max-width:500px; width:90%; margin:20px; max-height:90vh; overflow-y:auto; box-shadow:0 5px 15px rgba(0,0,0,0.3); border:3px solid #2196f3;">
      <div class="delete-confirm-header">
        <i class="delete-icon" style="background: #2196f3;">☁</i>
        <h3>Enable Google Drive Backup</h3>
      </div>
      <div class="delete-confirm-body">
        <p style="margin-bottom: 12px; padding: 10px; background: #e3f2fd; border-radius: 4px; font-size: 13px;">
          <strong>📁 Centralized Backup:</strong> A shared backup folder will be created in your Google Drive. <strong>All practice cases</strong> created by any team member will be backed up to this single location.
        </p>
        <p>Enabling this feature will:</p>
        <ul>
          <li>Create a shared backup folder in your Google Drive</li>
          <li>Automatically backup all <strong>new cases</strong> created by any practice member</li>
          <li>Store case data and attached files in organized subfolders</li>
          <li>Keep backups in sync when cases are edited</li>
        </ul>
        <p style="margin-top: 12px;"><strong>Requirements:</strong></p>
        <ul>
          <li>You must be signed in with Google (not email/password)</li>
          <li>Only affects <strong>new cases</strong> created after enabling</li>
          <li>Existing cases will not be backed up</li>
          <li>Disabling backup later will preserve existing backup files</li>
        </ul>
        <p style="margin-top: 12px; padding: 10px; background: #fff3cd; border-radius: 4px; font-size: 13px;">
          <strong>⚠️ HIPAA Compliance:</strong> By enabling this feature, you confirm that you have a signed Business Associate Agreement (BAA) with Google for Google Workspace that covers PHI storage.
        </p>
      </div>
      <div class="delete-confirm-actions">
        <button type="button" id="gdBackupCancel" class="btn-delete-cancel">Cancel</button>
        <button type="button" id="gdBackupConfirm" class="btn-delete-confirm" style="background: #2196f3;">I Agree, Enable Backup</button>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Card Delete Confirmation Modal -->
  <div id="cardDeleteModal" class="delete-confirm-modal">
    <div class="delete-confirm-content">
      <div class="delete-confirm-header">
        <i class="delete-icon">&excl;</i>
        <h3>Archive Case</h3>
      </div>
      <div class="delete-confirm-body">
        <p>Are you sure you want to archive this case? This action will:</p>
        <ul>
          <li>Remove the case from your active board</li>
          <li>Move all associated files and folders into your Archive folder in Google Drive</li>
          <li>No files will be permanently deleted</li>
        </ul>
      </div>
      <div class="delete-confirm-actions">
        <button type="button" id="cardDeleteCancel" class="btn-delete-cancel">Cancel</button>
        <button type="button" id="cardDeleteConfirm" class="btn-delete-confirm">Archive Case</button>
      </div>
    </div>
  </div>
  
  <!-- Dev Tools Panel -->
  <?php if ($showDevTools): 
    // Get user's current billing tier and signup date for dev tools
    $currentBillingTier = 'evaluate';
    $userSignupDate = '';
    try {
        if (isset($_SESSION['db_user_id']) && isset($pdo)) {
            $tierStmt = $pdo->prepare("SELECT billing_tier, created_at FROM users WHERE id = ?");
            $tierStmt->execute([$_SESSION['db_user_id']]);
            $tierUser = $tierStmt->fetch(PDO::FETCH_ASSOC);
            if ($tierUser) {
                if (!empty($tierUser['billing_tier'])) {
                    $currentBillingTier = strtolower($tierUser['billing_tier']);
                }
                if (!empty($tierUser['created_at'])) {
                    $userSignupDate = date('Y-m-d', strtotime($tierUser['created_at']));
                }
            }
        }
    } catch (Exception $e) {
        $currentBillingTier = 'evaluate';
    }
  ?>
  <div class="dev-tools-panel collapsed <?php echo $isSuperUserInProd ? 'super-user-mode' : ''; ?>" id="devToolsPanel" 
       data-environment="<?php echo htmlspecialchars($currentEnv); ?>"
       data-is-super-user="<?php echo $isSuperUserInProd ? 'true' : 'false'; ?>"
       data-env-display-name="<?php echo htmlspecialchars($environmentDisplayName); ?>">
    <div class="dev-tools-header">
      <h3>🛠️ Dev Tools <?php if ($isSuperUserInProd): ?><span class="env-badge"><?php echo htmlspecialchars($environmentDisplayName); ?></span><?php endif; ?></h3>
      <button id="toggleDevTools" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 4px;">+</button>
    </div>
    <div class="dev-tools-content" id="devToolsContent" style="display: none;">
      <?php if ($isSuperUserInProd): ?>
      <!-- Super User Warning Banner -->
      <div class="dev-tools-warning">
        <strong>⚠️ <?php echo htmlspecialchars($environmentDisplayName); ?> ENVIRONMENT</strong>
        <p>You are using dev tools in <?php echo htmlspecialchars($environmentDisplayName); ?>. All changes will <strong>only affect your practice</strong>. Destructive actions require confirmation.</p>
      </div>
      <?php endif; ?>
      
      <?php if (!$isSuperUserInProd): ?>
      <!-- Environment Switcher - Only show in development -->
      <div class="dev-tools-section">
        <h4>🌍 Environment</h4>
        <div class="env-switcher">
          <button id="envDevBtn" class="env-btn">Development (Local DB)</button>
          <button id="envUatBtn" class="env-btn">UAT (Prod DB)</button>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Test Case Creation -->
      <div class="dev-tools-section">
        <h4>🧪 Test Cases</h4>
        <div class="test-case-controls">
          <label for="devCaseType" class="sr-only">Test case type</label>
          <select id="devCaseType" class="dev-select">
            <option value="Mixed">Mixed Case Type</option>
            <option value="Crown">Crown</option>
            <option value="Bridge">Bridge</option>
            <option value="Implant">Implant</option>
            <option value="AOX">AOX</option>
            <option value="Denture">Denture</option>
            <option value="Partial">Partial</option>
            <option value="Veneer">Veneer</option>
            <option value="Inlay/Onlay">Inlay/Onlay</option>
            <option value="Orthodontic Appliance">Orthodontic Appliance</option>
          </select>
          <label for="devCaseCount" class="sr-only">Number of test cases to generate</label>
          <input type="number" id="devCaseCount" class="dev-input" placeholder="Count" value="10" min="1" max="100">
          <button id="devGenerateCasesBtn" class="dev-btn dev-btn-primary">Generate</button>
        </div>
      </div>
      
      <!-- Billing Plan -->
      <div class="dev-tools-section">
        <h4>💳 Billing Plan</h4>
        <div class="billing-controls">
          <label for="devPlanSelect" class="sr-only">Billing plan</label>
          <select id="devPlanSelect" class="dev-select">
            <option value="evaluate" <?php echo (strtolower($currentBillingTier) === 'evaluate') ? 'selected' : ''; ?>>Evaluate</option>
            <option value="operate" <?php echo (strtolower($currentBillingTier) === 'operate') ? 'selected' : ''; ?>>Operate</option>
            <option value="control" <?php echo (strtolower($currentBillingTier) === 'control') ? 'selected' : ''; ?>>Control</option>
          </select>
          <button id="devSetPlanBtn" class="dev-btn dev-btn-primary">Set Plan</button>
        </div>
        <div class="billing-controls" style="margin-top: 10px;">
          <label for="devSignupDate" style="color: #ccc; font-size: 12px; margin-right: 8px;">Signup Date:</label>
          <input type="date" id="devSignupDate" class="dev-select" value="<?php echo htmlspecialchars($userSignupDate); ?>" style="width: 150px;">
          <button id="devSetSignupDateBtn" class="dev-btn dev-btn-primary">Set Date</button>
        </div>
      </div>
      
      <!-- Data Management -->
      <div class="dev-tools-section">
        <h4>🗂️ Data Management</h4>
        <div class="data-controls">
          <button id="devStartOverBtn" class="dev-btn dev-btn-danger">🔄 Start Over (Delete All & Reset)</button>
          <button id="devDeleteAllCasesBtn" class="dev-btn dev-btn-danger">Delete All Cases</button>
        </div>
      </div>
      
      <!-- Admin Tools -->
      <div class="dev-tools-section">
        <h4>👑 Admin Tools</h4>
        <div class="admin-links" style="display: flex; flex-direction: column; gap: 8px;">
          <a href="/admin-practices.php" class="dev-btn" style="text-align: center; text-decoration: none;">🏥 Practice Administration</a>
          <a href="/waitlist-admin.php" class="dev-btn" style="text-align: center; text-decoration: none;">📋 Waitlist Admin</a>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Toast notification container -->
  <div class="toast-container" id="toastContainer"></div>

<?php if (isFeatureEnabled('SHOW_AI_CHAT')): ?>
  <!-- Floating Ask DentaTrak Button and Panel -->
  <div class="ask-dentatrak-floating" id="askDentatrakFloating">
    <button type="button" class="ask-dentatrak-fab" id="askDentatrakFab" title="Ask <?php echo htmlspecialchars($appName); ?>">
      <svg class="fab-icon-default" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"></circle>
        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
        <line x1="12" y1="17" x2="12.01" y2="17"></line>
      </svg>
      <svg class="fab-icon-close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"></line>
        <line x1="6" y1="6" x2="18" y2="18"></line>
      </svg>
    </button>
    <div class="ask-dentatrak-panel" id="askDentatrakPanel">
      <div class="ask-panel-header">
        <span class="ask-panel-title">Ask <?php echo htmlspecialchars($appName); ?></span>
        <span class="ask-panel-subtitle">Your practice assistant</span>
      </div>
      <div class="ask-panel-body">
        <div class="ask-panel-messages" id="askDentatrakMessages">
          <div class="ask-message assistant">
            <p>Hi! I can help you with:</p>
            <ul>
              <li>Questions about your practice data</li>
              <li>How to use <?php echo htmlspecialchars($appName); ?> features</li>
              <li>Case status and bottlenecks</li>
            </ul>
            <p>What would you like to know?</p>
          </div>
        </div>
      </div>
      <div class="ask-panel-input">
        <input type="text" id="askDentatrakInput" placeholder="Ask a question..." autocomplete="off">
        <button type="button" id="askDentatrakSubmit" title="Send">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
          </svg>
        </button>
      </div>
    </div>
  </div>
<?php endif; ?>

  <!-- Load JavaScript last -->
  <script src="js/toast.js?v=20250104" defer></script>
  <script src="js/session-timeout.js?v=20250118" defer></script>
  <script src="js/app.js?v=20250104" defer></script>
  <script src="js/card-delete-fixed.js?v=20250104" defer></script>
  <script src="js/assignments.js?v=20250104" defer></script>
  <script src="js/case-comments.js?v=20250104" defer></script>
  <script src="js/notifications.js?v=20250104" defer></script>
  <script src="js/activity-timeline.js?v=20250104" defer></script>
  <script src="js/clinical-details.js?v=20250104" defer></script>
  <script src="js/ask-dentatrak.js?v=20250104" defer></script>
  <script src="js/insights.js?v=20250104" defer></script>
  <script src="js/patient-search.js?v=20250104" defer></script>
  
<?php if ($showDevTools): ?>
<!-- Dev Tools JavaScript -->
  <script>
    // Billing plan dropdown is set via PHP selected attribute
    
    // Toggle documentation content
    function toggleDocumentation() {
      const docContent = document.getElementById('documentation-content');
      if (docContent) {
        // Toggle documentation
        docContent.style.display = docContent.style.display === 'none' ? 'block' : 'none';
      }
    }

    // Dev Tools Functionality
    document.addEventListener('DOMContentLoaded', function() {
      const devToolsPanel = document.getElementById('devToolsPanel');
      const toggleBtn = document.getElementById('toggleDevTools');
      const devToolsContent = document.getElementById('devToolsContent');
      const envDevBtn = document.getElementById('envDevBtn');
      const envUatBtn = document.getElementById('envUatBtn');
      const devPlanSelect = document.getElementById('devPlanSelect');
      
      // Initialize with current environment from PHP
      const currentEnv = '<?php echo $appConfig['current_environment'] ?? 'development'; ?>';
      const currentPlan = '<?php echo $currentBillingTier; ?>';
      const isSuperUserInProd = devToolsPanel?.dataset?.isSuperUser === 'true';
      const envDisplayName = devToolsPanel?.dataset?.envDisplayName || 'Production';
      
      // Helper function for confirmation dialogs in UAT/Production
      function confirmDestructiveAction(actionName, details) {
        if (!isSuperUserInProd) {
          return true; // No confirmation needed in development
        }
        
        const message = `⚠️ WARNING: ${envDisplayName} ENVIRONMENT\n\n` +
          `You are about to: ${actionName}\n\n` +
          `${details}\n\n` +
          `This action will ONLY affect YOUR practice.\n\n` +
          `Type "CONFIRM" to proceed:`;
        
        const userInput = prompt(message);
        return userInput === 'CONFIRM';
      }
      
      function updateEnvironmentUI(env) {
        if (envDevBtn) envDevBtn.classList.toggle('active', env === 'development');
        if (envUatBtn) envUatBtn.classList.toggle('active', env === 'uat');
      }
      
      // Billing dropdown value is set via PHP selected attribute - no JS override needed
      
      // Toggle dev tools panel
      if (toggleBtn) toggleBtn.addEventListener('click', function() {
        const isCollapsed = devToolsContent.style.display === 'none';
        devToolsContent.style.display = isCollapsed ? 'block' : 'none';
        toggleBtn.textContent = isCollapsed ? '−' : '+';
        if (devToolsPanel) devToolsPanel.classList.toggle('collapsed', !isCollapsed);
      });
      
      // Environment switcher (only exists in development mode)
      if (envDevBtn) {
        envDevBtn.addEventListener('click', function() {
          switchEnvironment('development');
        });
      }
      
      if (envUatBtn) {
        envUatBtn.addEventListener('click', function() {
          switchEnvironment('uat');
        });
      }
      
      function switchEnvironment(env) {
        // Don't switch if already on this environment
        if (env === currentEnv) {
          showToast('Already in ' + (env === 'development' ? 'Development (Local DB)' : 'UAT (Prod DB)') + ' mode', 'info');
          return;
        }
        
        // Show loading state
        if (envDevBtn) envDevBtn.disabled = true;
        if (envUatBtn) envUatBtn.disabled = true;
        showToast('Switching environment...', 'info');
        
        // Call API to switch environment
        fetch('api/switch-environment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                environment: env
            })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Update UI
            updateEnvironmentUI(env);
            
            // Show success notification
            showToast('Environment switched to: ' + (env === 'development' ? 'Development (Local DB)' : 'UAT (Prod DB)'), 'success');
            
            // Reload page after a short delay to apply changes
            setTimeout(() => {
              location.reload();
            }, 1500);
          } else {
            showToast('Failed to switch environment: ' + data.message, 'error');
          }
        })
        .catch(error => {
          console.error('Error switching environment:', error);
          showToast('Error switching environment', 'error');
        })
        .finally(() => {
          // Re-enable buttons
          if (envDevBtn) envDevBtn.disabled = false;
          if (envUatBtn) envUatBtn.disabled = false;
        });
      }
      
      // Generate test cases - handled by app.js, no duplicate handler needed here
      
      // Delete all cases
      const devDeleteAllCasesBtn = document.getElementById('devDeleteAllCasesBtn');
      if (devDeleteAllCasesBtn) devDeleteAllCasesBtn.addEventListener('click', function() {
          // Require confirmation in UAT/Production
          if (!confirmDestructiveAction(
            'DELETE ALL CASES',
            'This will permanently delete all cases in your practice.\nThis action cannot be undone.'
          )) {
            showToast('Action cancelled', 'info');
            return;
          }
          
          this.disabled = true;
          this.textContent = 'Deleting...';
          showToast('Deleting all cases...', 'warning');
          
          fetch('../api/delete-all-cases.php', {
            method: 'DELETE',
            headers: {
              'Content-Type': 'application/json'
            }
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('All cases deleted! Refreshing...', 'success');
              setTimeout(() => location.reload(), 1000);
            } else {
              showToast('Failed to delete cases: ' + data.message, 'error');
              this.disabled = false;
              this.textContent = 'Delete All Cases';
            }
          })
          .catch(error => {
            console.error('Error deleting all cases:', error);
            showToast('Error deleting all cases', 'error');
            this.disabled = false;
            this.textContent = 'Delete All Cases';
          });
      });
      
      // Set billing plan
      const devSetPlanBtn = document.getElementById('devSetPlanBtn');
      if (devSetPlanBtn) {
        devSetPlanBtn.addEventListener('click', function() {
          const plan = document.getElementById('devPlanSelect').value;
          
          if (!plan) {
            showToast('Please select a billing plan', 'error');
            return;
          }
          
          // Prevent multiple clicks during processing
          if (this.disabled) {
            return;
          }
          
          // Disable button during request
          this.disabled = true;
          this.textContent = 'Setting...';
          
          // Make API call with the exact value from dropdown
          fetch('../api/set-billing-tier.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              billing_tier: plan
            })
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('Billing plan changed! Refreshing...', 'success');
              setTimeout(() => location.reload(), 1000);
            } else {
              showToast('Failed to set plan: ' + data.message, 'error');
              this.disabled = false;
              this.textContent = 'Set Plan';
            }
          })
          .catch(error => {
            console.error('Error setting billing plan:', error);
            showToast('Error setting billing plan', 'error');
            this.disabled = false;
            this.textContent = 'Set Plan';
          });
        });
      }
      
      // Set signup date
      const devSetSignupDateBtn = document.getElementById('devSetSignupDateBtn');
      if (devSetSignupDateBtn) {
        devSetSignupDateBtn.addEventListener('click', function() {
          const signupDate = document.getElementById('devSignupDate').value;
          
          if (!signupDate) {
            showToast('Please select a date', 'error');
            return;
          }
          
          this.disabled = true;
          this.textContent = 'Setting...';
          
          fetch('api/set-signup-date.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              signup_date: signupDate
            })
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('Signup date changed! Refreshing...', 'success');
              setTimeout(() => location.reload(), 1000);
            } else {
              showToast('Failed to set date: ' + data.message, 'error');
              this.disabled = false;
              this.textContent = 'Set Date';
            }
          })
          .catch(error => {
            console.error('Error setting signup date:', error);
            showToast('Error setting signup date', 'error');
            this.disabled = false;
            this.textContent = 'Set Date';
          });
        });
      }
      
      // Start Over - Complete Reset
      const devStartOverBtn = document.getElementById('devStartOverBtn');
      if (devStartOverBtn) devStartOverBtn.addEventListener('click', function() {
        // Require confirmation in UAT/Production (extra strict for this dangerous action)
        if (!confirmDestructiveAction(
          'COMPLETE DATA RESET',
          'This will DELETE EVERYTHING for your practice including:\n' +
          '• All cases and case history\n' +
          '• All Google Drive folders and files\n' +
          '• All user accounts in this practice\n' +
          '• All user preferences and settings\n' +
          '• The practice itself\n' +
          '• Your session will be terminated\n\n' +
          'THIS ACTION CANNOT BE UNDONE!\n\n' +
          'You will need to create a new account after this reset.'
        )) {
          showToast('Action cancelled', 'info');
          return;
        }
        
        // Show toast message
        if (typeof showToast === 'function') {
          showToast('Deleting your practice and all associated data...', 'info');
        }
        
        // Clear browser storage silently
        try {
          localStorage.clear();
          sessionStorage.clear();
        } catch(e) {}
        
        // Clear cookies
        try {
          document.cookie.split(";").forEach(function(c) { 
            document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/"); 
          });
        } catch(e) {}
        
        // Call reset API and redirect silently
        fetch('api/reset-all-data.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
          },
          credentials: 'same-origin',
          body: JSON.stringify({ confirm: true })
        })
        .then(function() {
          window.location.href = 'login.php';
        })
        .catch(function() {
          window.location.href = 'login.php';
        });
      });
    });
  </script>
<?php endif; ?>

  <!-- Confirmation Modal (reusable) -->
  <div id="confirmModal" class="modal confirm-modal">
    <div class="modal-content confirm-modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="confirmModalTitle">Confirm</h2>
      </div>
      <div class="modal-body">
        <p id="confirmModalMessage">Are you sure?</p>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn-cancel" id="confirmModalCancel">Cancel</button>
        <button type="button" class="btn-primary" id="confirmModalOk">OK</button>
      </div>
    </div>
  </div>
  
  <!-- Chart.js and Analytics Pro are lazy-loaded when Analytics tab is clicked -->
  
  <!-- Shepherd.js Tour - deferred since not needed immediately -->
  <?php if (isFeatureEnabled('SHOW_TOUR')): ?>
  <script src="https://cdn.jsdelivr.net/npm/shepherd.js@11/dist/js/shepherd.min.js" defer></script>
  <script src="js/tour.js?v=20241227o" defer></script>
  <?php endif; ?>
</body>
</html>
<?php
// Clean the output buffer, removing any PHP warnings/errors
$output = ob_get_clean();


// Surgical cleanup - remove only PHP error patterns, preserve legitimate HTML
$output = preg_replace('/<br\s*\/>\s*<b>(Warning|Notice|Error|Deprecated|Fatal error):[^<]*<\/b>:[^<]*<br\s*\/>/i', '', $output);
$output = preg_replace('/<br\s*\/>\s*(Warning|Notice|Error|Deprecated|Fatal error):[^<]*<br\s*\/>/i', '', $output);
$output = preg_replace('/<b>(Warning|Notice|Error|Deprecated|Fatal error):<\/b>[^<]*<br\s*\/>/i', '', $output);
$output = preg_replace('/<br\s*\/>\s*<b>Parse error<\/b>:[^<]*<br\s*\/>/i', '', $output);
$output = preg_replace('/Deprecated:\s+[^<]*<br\s*\/>/i', '', $output);
$output = preg_replace('/Deprecated:[^<]*<br\s*\/>/i', '', $output);
$output = preg_replace('/<br[^>]*>/i', '', $output);

// Additional patterns for OpenSSL errors that might bypass standard format
$output = preg_replace('/<br\s*\/>\s*openssl_decrypt\(\):[^<]*<br\s*\/>/i', '', $output);
$output = preg_replace('/<br\s*\/>\s*error:1C80006B:[^<]*<br\s*\/>/i', '', $output);


// Output the cleaned HTML
echo $output;
?>
