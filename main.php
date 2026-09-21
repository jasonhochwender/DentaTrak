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
require_once __DIR__ . '/api/case-zip-helpers.php';

// Load CSRF and security headers
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';

// Load dev tools access control
require_once __DIR__ . '/api/dev-tools-access.php';

// Load feature flags
require_once __DIR__ . '/api/feature-flags.php';

// Load practice permission helpers (canViewAnalytics, etc.)
require_once __DIR__ . '/api/practice-security.php';

// Billing-bypass patterns must be available before deciding whether to render
// any billing UI in the first paint, so the link does not flash on load and
// then get hidden by client-side JavaScript.
require_once __DIR__ . '/api/billing-bypass.php';
require_once __DIR__ . '/api/remakes.php';

// Set security headers for this page
setSecurityHeaders();

// Generate CSRF token for this session
$csrfToken = generateCsrfToken();

// Reset redirect counter when accessing main page
$_SESSION['practice_setup_visits'] = 0;

// If an unauthenticated user lands on a notification deep link, send them
// through the login flow while preserving only the safe notification ID.
if (empty($_SESSION['db_user_id'])) {
    $loginUrl = 'login.php';
    if (!empty($_GET['notification_id'])) {
        $loginUrl .= '?notification_id=' . urlencode($_GET['notification_id']);
    }
    header('Location: ' . $loginUrl);
    exit;
}

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

// Existing owners and administrators must accept the current Terms of Service
// and Privacy Policy before accessing administrative features. The
// accept-terms.php page is limited to owners/admins and will not block
// ordinary invited users from urgent case access.
requireCurrentTermsAcceptedForPage((int)$userId);

// Resolve locale state for settings UI
$userLocale = null;
$practiceDefaultLocale = 'en-US';
$resolvedLocale = getResolvedLocale();

if ($userId && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT locale FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $userLocale = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[main] Error reading user locale: ' . $e->getMessage());
    }
}

if ($currentPracticeId && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT default_locale FROM practices WHERE id = :id");
        $stmt->execute(['id' => $currentPracticeId]);
        $practiceDefaultLocale = $stmt->fetchColumn() ?: 'en-US';
    } catch (PDOException $e) {
        error_log('[main] Error reading practice default locale: ' . $e->getMessage());
    }
}

$selectedUserLanguage = ($userLocale === null || $userLocale === '') ? 'use_practice_default' : $userLocale;

$showLanguageControls = hasMultipleEnabledLocales();

$supportedLanguages = [];
foreach (getSupportedLocales() as $code => $meta) {
    if (!empty($meta['enabled'])) {
        $supportedLanguages[] = [
            'value' => $code,
            'label' => $meta['name'] ?? $code,
            'nativeName' => $meta['nativeName'] ?? $code,
        ];
    }
}

// SECURITY: Verify user is an active member of an active practice.
// practice_users has no is_active column; an active membership is an existing
// row where both the user and the practice are active.
if ($userId && $currentPracticeId) {
    try {
        $membershipStmt = $pdo->prepare("
            SELECT 1
            FROM practice_users pu
            JOIN practices p ON p.id = pu.practice_id
            JOIN users u ON u.id = pu.user_id
            WHERE pu.user_id = :user_id
              AND pu.practice_id = :practice_id
              AND u.is_active = 1
              AND (p.is_active = 1 OR p.is_active IS NULL)
            LIMIT 1
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

// Insights (analytics + Ask DentaTrak) visibility for the CURRENT practice.
// This is a per-practice-membership permission (practice_users.can_view_analytics),
// not global to the user - it is intentionally recomputed on every page load
// against $currentPracticeId so it reflects whichever practice is active,
// including immediately after a practice switch (which triggers a full
// page reload). This only controls UI visibility; get-analytics.php and
// ai-recommendations.php remain the authoritative server-side enforcement.
$userCanViewAnalytics = canViewAnalytics($currentPracticeId);

require_once __DIR__ . '/api/case-view-preferences-store.php';
$caseViewBootstrap = ['userId' => (int)$userId, 'practiceId' => (int)$currentPracticeId, 'preferences' => normalizeCaseViewPreferences([]), 'available' => true];
try {
    $caseViewBootstrap['preferences'] = loadCaseViewPreferences($pdo, (int)$userId, (int)$currentPracticeId);
} catch (Throwable $e) {
    $caseViewBootstrap['available'] = false;
    error_log('[case-view-preferences] Unable to load preferences');
}

// Billing, Settings, and practice-wide data export are administrative
// surfaces - only practice admins may see or use them. Computed the same
// way as $userCanViewAnalytics: per-practice-membership, recomputed on
// every page load (including after a practice switch) against the
// authoritative practice_users.role column. This only controls UI
// visibility; every underlying API independently enforces this server-side.
$isCurrentUserPracticeAdmin = isPracticeAdmin($currentPracticeId);



// Practice-specific workflow-stage display labels, resolved once here so
// both the Kanban board and Settings > Display & Behavior can render the
// customized text server-side on first paint - avoiding a flash of the
// default label before get-settings.php's client-side bootstrap runs (see
// window.workflowStageLabels / getStageLabel() in js/app.js). This is
// display text ONLY: data-status attributes, <option value>, drag/drop,
// and revision logic all continue to use the fixed internal status values
// and never derive from $resolvedWorkflowStageLabels.
require_once __DIR__ . '/api/workflow-stages.php';
require_once __DIR__ . '/api/case-types.php';
$resolvedWorkflowStageLabels = $currentPracticeId
    ? getResolvedWorkflowStageLabelsForPractice($currentPracticeId)
    : getResolvedWorkflowStageLabels([]);
$workflowColumns = $currentPracticeId ? getWorkflowColumnsForPractice($currentPracticeId) : [];
$workflowColumnsActive = [];
$workflowColumnsArchived = [];
foreach ($workflowColumns as $column) {
    if (empty($column['archived'])) {
        $workflowColumnsActive[] = $column;
    } else {
        $workflowColumnsArchived[] = $column;
    }
}
$workflowColumnColorKeys = [];
foreach ($workflowColumns as $column) {
    $workflowColumnColorKeys[$column['id']] = (int)($column['colorKey'] ?? 0);
}
$workflowColumnCount = count($workflowColumnsActive);
$workflowMaxColumns = 10;
$workflowStageOrder = array_keys($resolvedWorkflowStageLabels);
$allWorkflowStageLabels = $resolvedWorkflowStageLabels;
foreach ($workflowColumnsArchived as $column) {
    $label = $column['label'];
    if (isset($resolvedWorkflowStageLabels[$column['id']]) && $resolvedWorkflowStageLabels[$column['id']] !== '') {
        $label = $resolvedWorkflowStageLabels[$column['id']];
    }
    $allWorkflowStageLabels[$column['id']] = $label;
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
    <html lang="<?php echo getHtmlLang(); ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title><?php echo t('auth.sign_in_required.title'); ?></title>

        <!-- Favicon / App Icons -->
        <link rel="icon" type="image/x-icon" href="favicon.ico">
        <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
        <link rel="manifest" href="site.webmanifest">

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
            <h1><?php echo t('auth.sign_in_required.title'); ?></h1>
            <p class="auth-message"><?php echo t('auth.sign_in_required.message'); ?></p>
            <a href="login.php" class="auth-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                </svg>
                <?php echo t('auth.sign_in_required.button'); ?>
            </a>
            <div class="auth-footer">
                <?php echo t('auth.sign_in_required.footer'); ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Store user info for display
$user = $_SESSION['user'];

// Billing link in the user header is shown only when billing and the legacy
// billing-link feature are enabled, the current user is a practice admin, and
// the user's email is not in the billing-bypass list. This must match the
// hide_billing_ui logic in api/billing.php to avoid a visible link that is
// hidden after the async billing info call returns.
$showBillingHeader = isFeatureEnabled('BILLING_ENABLED')
    && isFeatureEnabled('SHOW_BILLING')
    && $isCurrentUserPracticeAdmin
    && shouldShowBillingUI($user['email'] ?? '');

// Fetch current practice information for header
$currentPracticeId = $_SESSION['current_practice_id'] ?? 0;

// Plan entitlement for the Insights screens (Practice Insights, Lab
// Insights, Smart Recommendations). Distinct from $userCanViewAnalytics
// above: can_view_analytics controls whether the tab is offered at all,
// while hasControlAccess() controls whether the panes render data or an
// upgrade state. hasControlAccess() already encodes active trials,
// founder/billing bypasses, and BILLING_ENABLED=false. null = the check
// could not be evaluated - the UI then shows a recoverable error state and
// the API still enforces the entitlement independently.
require_once __DIR__ . '/api/subscription-access.php';
$userHasControlAccess = null;
try {
    $userHasControlAccess = $currentPracticeId
        ? hasControlAccess($pdo, (int)$currentPracticeId, (string)($user['email'] ?? ''))
        : false;
} catch (Throwable $e) {
    error_log('[insights] Unable to evaluate Control plan entitlement');
}

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
            
            // Fetch all active practices the user belongs to (for practice switcher).
            // practice_users has no is_active column; membership is active when both
            // the user and the practice are active.
            $userId = $_SESSION['db_user_id'] ?? 0;
            if ($userId) {
                $stmt = $pdo->prepare("
                    SELECT p.id, p.practice_name, p.logo_path, p.organization_type,
                           pu.role, pu.is_owner, IFNULL(pu.is_lab, 0) AS is_lab
                    FROM practices p
                    JOIN practice_users pu ON p.id = pu.practice_id
                    JOIN users u ON u.id = pu.user_id
                    WHERE pu.user_id = :user_id
                      AND u.is_active = 1
                      AND (p.is_active = 1 OR p.is_active IS NULL)
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
<html lang="<?php echo getHtmlLang(); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  
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
  <link rel="canonical" href="<?php echo htmlspecialchars(rtrim($appConfig['baseUrl'] ?? 'https://dentatrak.com', '/') . ($_SERVER['REQUEST_URI'] ?? '/main.php')); ?>">
  <title><?php echo htmlspecialchars($appName . ' - Main'); ?></title>

  <!-- Favicon / App Icons -->
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">
  
  <!-- Structured Data for SEO -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "<?php echo htmlspecialchars($appName); ?>",
    "description": "Professional dental case tracking and management system. Streamline your dental lab workflow with real-time case tracking, team collaboration, and analytics.",
    "url": "<?php echo htmlspecialchars(rtrim($appConfig['baseUrl'] ?? 'https://dentatrak.com', '/') . '/'); ?>",
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
  <link rel="preload" href="js/app.js?v=20260916f" as="script">
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
  <link rel="stylesheet" href="css/app.light.css?v=20260916i">
  <link rel="stylesheet" href="css/app.css?v=20260807a">
<?php if (isFeatureEnabled('SHOW_NOTIFICATIONS')): ?>
  <link rel="stylesheet" href="css/notification-preferences.css?v=20250104">
<?php endif; ?>
  
  <!-- Mobile responsiveness CSS -->
  <link rel="stylesheet" href="css/mobile.css?v=20260916b">
  
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
  <link rel="preload" href="css/settings-billing.css?v=20260905a" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/feedback.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/kanban-dragdrop.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/case-list.css?v=20260916b" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/case-creation.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/case-comments.css?v=20260909a" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/case-remakes.css?v=20260922a" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/activity-timeline.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/insights.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/at-risk.css?v=20241231" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/clinical-details.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/ask-dentatrak.css?v=20241230" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/patient-search.css?v=20241212" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/assignments.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/practice-name.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/logo-upload.css?v=20260807a" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/dev-tools.css?v=20241210" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/analytics-pro.css?v=20260916a" as="style" onload="this.onload=null;this.rel='stylesheet'">
  <link rel="preload" href="css/attachment-viewer.css?v=20260903a" as="style" onload="this.onload=null;this.rel='stylesheet'">
<?php if (isFeatureEnabled('SHOW_LAB_INSIGHTS')): ?>
  <link rel="preload" href="css/lab-insights.css?v=20260905a" as="style" onload="this.onload=null;this.rel='stylesheet'">
<?php endif; ?>
<?php if (isFeatureEnabled('BILLING_ENABLED')): ?>
  <link rel="preload" href="css/billing-portal.css?v=20260805" as="style" onload="this.onload=null;this.rel='stylesheet'">
<?php endif; ?>
  <noscript>
    <link rel="stylesheet" href="css/revision-history.css?v=20241210">
    <link rel="stylesheet" href="css/delete-button.css?v=20241210">
    <link rel="stylesheet" href="css/settings-billing.css?v=20260905a">
    <link rel="stylesheet" href="css/feedback.css?v=20241210">
    <link rel="stylesheet" href="css/kanban-dragdrop.css?v=20241210">
    <link rel="stylesheet" href="css/case-list.css?v=20260916b">
    <link rel="stylesheet" href="css/case-creation.css?v=20241210">
    <link rel="stylesheet" href="css/case-comments.css?v=20260909a">
    <link rel="stylesheet" href="css/case-remakes.css?v=20260922a">
    <link rel="stylesheet" href="css/activity-timeline.css?v=20241230">
    <link rel="stylesheet" href="css/insights.css?v=20241230">
    <link rel="stylesheet" href="css/at-risk.css?v=20241231">
    <link rel="stylesheet" href="css/clinical-details.css?v=20241230">
    <link rel="stylesheet" href="css/ask-dentatrak.css?v=20241230">
    <link rel="stylesheet" href="css/patient-search.css?v=20241212">
    <link rel="stylesheet" href="css/assignments.css?v=20241210">
    <link rel="stylesheet" href="css/practice-name.css?v=20241210">
    <link rel="stylesheet" href="css/logo-upload.css?v=20260807a">
    <link rel="stylesheet" href="css/dev-tools.css?v=20241210">
    <link rel="stylesheet" href="css/analytics-pro.css?v=20260916a">
<?php if (isFeatureEnabled('SHOW_LAB_INSIGHTS')): ?>
    <link rel="stylesheet" href="css/lab-insights.css?v=20260905a">
<?php endif; ?>
    <link rel="stylesheet" href="css/attachment-viewer.css?v=20260903a">
<?php if (isFeatureEnabled('BILLING_ENABLED')): ?>
    <link rel="stylesheet" href="css/billing-portal.css?v=20260805">
<?php endif; ?>
  </noscript>
  <!-- Shepherd.js Tour - CSS loaded via preload above -->

  <!-- Internationalization: make translations available to JavaScript -->
  <script>
    window.__i18n = <?php echo getTranslationsJsonForJs(); ?>;
    window.__caseTypeMap = <?php echo json_encode(getCaseTypeMapForJs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    window.workflowStageOrder = <?php echo json_encode($workflowStageOrder, JSON_UNESCAPED_UNICODE); ?>;
    window.allWorkflowStageLabels = <?php echo json_encode($allWorkflowStageLabels, JSON_UNESCAPED_UNICODE); ?>;
    window.workflowTerminal = <?php echo json_encode(['id' => getLastActiveWorkflowColumnId($currentPracticeId ?: null), 'label' => resolveWorkflowStageLabelForPractice(getLastActiveWorkflowColumnId($currentPracticeId ?: null), $currentPracticeId ?: null)], JSON_UNESCAPED_UNICODE); ?>;
    window.currentPracticeId = <?php echo (int)$currentPracticeId; ?>;
    window.userCanViewAnalytics = <?php echo $userCanViewAnalytics ? 'true' : 'false'; ?>;
    window.userHasControlAccess = <?php echo $userHasControlAccess === true ? 'true' : ($userHasControlAccess === false ? 'false' : 'null'); ?>;
    window.isPracticeAdmin = <?php echo $isCurrentUserPracticeAdmin ? 'true' : 'false'; ?>;
    window.workflowColumnsEndpoint = <?php echo json_encode('api/workflow-columns.php', JSON_UNESCAPED_UNICODE); ?>;
  </script>
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

  // Practice-level Case Review Tracking flag. Defaults to OFF so existing
  // practices do not show review UI before Settings is loaded.
  $caseReviewTrackingClass = 'case-review-tracking-off';
  try {
      $caseReviewStmt = $pdo->prepare("SELECT case_review_tracking_enabled FROM practices WHERE id = :practice_id");
      $caseReviewStmt->execute(['practice_id' => $currentPracticeId]);
      if ($caseReviewStmt->fetchColumn()) {
          $caseReviewTrackingClass = '';
      }
  } catch (Throwable $e) {
      $caseReviewTrackingClass = 'case-review-tracking-off';
  }
?>
<body class="main-body <?php echo trim($envClass . ' ' . $caseReviewTrackingClass); ?>">
  <!-- Full-page loading overlay -->
  <div id="pageLoadingOverlay" class="page-loading-overlay">
    <div class="overlay-content">
      <div class="loading-spinner"></div>
      <p class="loading-text"><?php echo t('common.loading_application'); ?></p>
    </div>
  </div>
  
  <!-- Hidden data element to store user email for JavaScript -->
<div id="userEmailData" data-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" data-user-id="<?php echo (int)($_SESSION['db_user_id'] ?? 0); ?>" style="display: none;"></div>

<!-- CSRF Token for secure API requests -->
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">

<!-- Feature Flags for JavaScript -->
<script>
window.featureFlags = <?php echo getFeatureFlagsJson(); ?>;
window.bulkZipMaxBytes = <?php echo (int)getBulkZipMaxSize(); ?>;
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
          alt="<?php echo htmlspecialchars(t('settings.branding.logo')); ?>"
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
              <div class="practice-switcher-divider"></div>
              <!-- Creating a practice always goes through the BAA flow (baa-acceptance.php
                   collects the practice's legal name and creates it atomically with BAA
                   acceptance) - the same flow used by the login-time "You're Part of
                   Multiple Practices" chooser. ?new=1 ensures this always starts a fresh
                   practice even if a stale current_practice_id lingers in session.
                   Deliberately NOT given the .practice-switcher-item class so it is
                   excluded from app.js's practice-switching click handler and instead
                   navigates normally. -->
              <a href="baa-acceptance.php?new=1" class="practice-switcher-create" id="createNewPracticeItem">
                <svg class="practice-item-plus-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span><?php echo t('practice_switcher.create_new_practice'); ?></span>
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="user-profile">
        <div class="user-info">
          <span class="user-name"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></span>
          <span class="user-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
<?php if ($showBillingHeader): ?>
          <a href="billing.php" class="billing-link" id="userBillingTier" style="visibility: hidden;"><?php echo t('navigation.billing'); ?></a>
<?php endif; ?>
        </div>

<?php
// Global personal language selector (hidden until a second locale is enabled)
$showPersonalLanguage = hasMultipleEnabledLocales();
if ($showPersonalLanguage):
    echo renderLanguageSelector('api/save-user-language.php', $resolvedLocale, true, $csrfToken);
endif;
?>

<?php if (isFeatureEnabled('SHOW_NOTIFICATIONS')): ?>
        <!-- Notification Bell -->
        <div class="notification-bell-wrapper" style="position: relative;">
          <button type="button" class="notification-bell" id="notificationBell" title="<?php echo t('notifications.title'); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <span id="notificationBadge" class="notification-badge hidden"></span>
          </button>
          <div id="notificationDropdown" class="notification-dropdown">
            <div class="notification-dropdown-header">
              <span class="notification-dropdown-title"><?php echo t('notifications.title'); ?></span>
              <div class="notification-header-actions">
                <button type="button" class="notification-dropdown-close" id="notificationDropdownClose" aria-label="<?php echo t('common.close'); ?>" title="<?php echo t('common.close'); ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
                <button type="button" class="notification-mark-all" id="markAllNotificationsRead" onclick="markAllNotificationsRead()" disabled aria-disabled="true"><?php echo t('notifications.mark_all_read'); ?></button>
              </div>
            </div>
            <div class="notification-dropdown-filters" role="tablist" aria-label="<?php echo t('notifications.filter_label'); ?>">
              <button type="button" class="notification-filter-btn active" data-filter="all" role="tab" aria-selected="true" onclick="switchNotificationFilter('all')"><?php echo t('notifications.all'); ?></button>
              <button type="button" class="notification-filter-btn" data-filter="unread" role="tab" aria-selected="false" onclick="switchNotificationFilter('unread')"><?php echo t('notifications.unread'); ?></button>
            </div>
            <div id="notificationList" class="notification-dropdown-list">
              <div class="notification-dropdown-empty"><?php echo t('notifications.loading'); ?></div>
            </div>
            <div class="notification-dropdown-footer">
              <a href="#" id="notificationPanelPreferencesLink" class="notification-preferences-link">
                <?php echo t('preferences.title'); ?>
              </a>
            </div>
          </div>
        </div>
<?php endif; ?>
        
        <button type="button" class="user-avatar-button" id="userMenuToggle" aria-haspopup="true" aria-expanded="false">
          <?php if (!empty($user['picture'])): ?>
            <img src="<?php echo htmlspecialchars($user['picture']); ?>" alt="<?php echo t('common.profile_picture'); ?>" class="profile-image" referrerpolicy="no-referrer">
          <?php else: ?>
            <span class="avatar-placeholder"><?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?></span>
          <?php endif; ?>
        </button>
        <div class="user-menu" id="userMenu">
<?php if ($isCurrentUserPracticeAdmin): ?>
          <a href="#" class="user-menu-item" id="settingsMenuItem"><?php echo t('settings.settings'); ?></a>
<?php endif; ?>
<?php if (isFeatureEnabled('SHOW_NOTIFICATIONS')): ?>
          <a href="#" class="user-menu-item" id="notificationPreferencesMenuItem"><?php echo t('preferences.title'); ?></a>
<?php endif; ?>
<?php if ($showBillingHeader): ?>
          <a href="#" class="user-menu-item" id="billingMenuItem"><?php echo t('navigation.billing'); ?></a>
<?php endif; ?>
<?php if ($isCurrentUserPracticeAdmin): ?>
          <div class="user-menu-divider"></div>
<?php endif; ?>
          <a href="#" class="user-menu-item" id="contactUsLink"><?php echo t('navigation.feedback'); ?></a>
          <?php if (isFeatureEnabled('SHOW_TOUR')): ?>
          <a href="#" class="user-menu-item" id="startTourLink"><?php echo t('navigation.take_tour'); ?></a>
          <?php endif; ?>
          <!-- User Guide link temporarily hidden from the menu. Restore by removing this comment wrapper.
          <a href="<?php echo htmlspecialchars($appConfig['user_guide_url'], ENT_QUOTES, 'UTF-8'); ?>" class="user-menu-item" id="userGuideLink" target="_blank" rel="noopener noreferrer"><?php echo t('navigation.user_guide'); ?></a>
          -->
          <div class="user-menu-divider"></div>
          <a href="api/logout.php" class="user-menu-item"><?php echo t('navigation.logout'); ?></a>
        </div>
      </div>
    </header>

    <main class="dashboard">
      <!-- Main Tabs -->
      <div class="main-tabs">
        <button type="button" class="main-tab active" data-tab="cases"><?php echo t('navigation.cases'); ?></button>
        <?php if ($userCanViewAnalytics): ?>
        <button type="button" class="main-tab" data-tab="insights"><?= t('navigation.insights') ?></button>
        <?php endif; ?>
      </div>

      <!-- Tab Content -->
      <div class="main-tab-content">
        <!-- Cases Tab -->
        <div class="main-tab-pane active" id="cases-tab">
          <div class="dashboard-toolbar">
            <button type="button" class="create-case-button">+ <?php echo t('cases.create_new_case'); ?></button>
            <div class="dashboard-toolbar-right">
              <div class="case-view-toggle" role="group" aria-label="<?php echo t('cases.list.view_toggle_aria'); ?>">
                <button type="button" id="boardViewToggle" class="case-view-btn active" aria-pressed="true"><?php echo t('cases.list.view_board'); ?></button>
                <button type="button" id="listViewToggle" class="case-view-btn" aria-pressed="false"><?php echo t('cases.list.view_list'); ?></button>
              </div>
              <button type="button" id="kanbanFilterToggle" class="filter-toggle-button" aria-controls="kanbanFiltersBar" aria-expanded="false">
                <?php echo t('filters.filters'); ?>
                <span id="kanbanFilterActiveDot" class="filter-active-dot" aria-hidden="true"></span>
              </button>
              <button type="button" class="view-archived-button" id="viewArchivedBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="3" width="18" height="4" rx="1" ry="1"></rect>
                  <path d="M5 7h14v14H5z"></path>
                  <path d="M10 12h4"></path>
                  <path d="M12 12v4"></path>
                </svg>
                <?php echo t('archive.view_archived_button'); ?> <span id="archivedCasesBadge" class="archived-count-badge" style="display: none;"></span>
              </button>
            </div>
          </div>

          <div id="kanbanFiltersBar" class="kanban-filters-bar">
        <div class="kanban-filters-row">
          <div class="kanban-filter-field kanban-search-field">
            <label for="patientSearch"><?php echo t('common.search'); ?></label>
            <input
              type="text"
              id="patientSearch"
              class="kanban-filter-input"
              placeholder="<?php echo t('filters.search_placeholder'); ?>"
              autocomplete="off"
            />
          </div>

          <div class="kanban-filter-field">
            <label for="filterCaseType"><?php echo t('cases.case_type'); ?></label>
            <select id="filterCaseType">
              <option value=""><?php echo t('filters.all_types'); ?></option>
              <?php echo renderCaseTypeOptions(getFilterableCaseTypes()); ?>
            </select>
          </div>

          <div class="kanban-filter-field">
            <label for="filterAssignedTo"><?php echo t('cases.assigned_to'); ?></label>
            <select id="filterAssignedTo">
              <option value=""><?php echo t('filters.anyone'); ?></option>
              <!-- Options populated dynamically -->
            </select>
          </div>

          <div class="kanban-filter-field case-review-feature">
            <label for="filterReviewStatus"><?php echo t('filters.review_status'); ?></label>
            <select id="filterReviewStatus">
              <option value=""><?php echo t('filters.all_review_status'); ?></option>
              <option value="needs_review"><?php echo t('filters.needs_review'); ?></option>
              <option value="reviewed"><?php echo t('filters.reviewed'); ?></option>
            </select>
          </div>

          <div class="kanban-filter-field">
            <label for="filterCarrier"><?php echo t('cases.carrier'); ?></label>
            <select id="filterCarrier">
              <option value=""><?php echo t('filters.all_carriers'); ?></option>
              <option value="UPS">UPS</option>
              <option value="FedEx">FedEx</option>
              <option value="USPS">USPS</option>
              <option value="DHL">DHL</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <div class="kanban-filter-field kanban-filter-checkbox">
            <label for="filterLateCases" class="filter-checkbox-label">
              <input type="checkbox" id="filterLateCases">
              <?php echo t('filters.late_cases'); ?>
            </label>
            <label for="filterDueSoon" class="filter-checkbox-label">
              <input type="checkbox" id="filterDueSoon">
              <?php echo t('filters.due_soon'); ?>
            </label>
            <label for="filterApptRisk" class="filter-checkbox-label">
              <input type="checkbox" id="filterApptRisk">
              <?php echo t('filters.appt_risk'); ?>
            </label>
          </div>

<?php if (isFeatureEnabled('SHOW_AT_RISK')): ?>
          <div class="kanban-filter-field kanban-filter-checkbox">
            <label for="filterAtRisk" class="filter-checkbox-label">
              <input type="checkbox" id="filterAtRisk">
              <?php echo t('filters.at_risk_only'); ?>
            </label>
          </div>
<?php endif; ?>

          <div class="kanban-filter-field kanban-filter-actions">
            <button type="button" id="clearFiltersBtn" class="filter-clear-btn"><?php echo t('filters.clear_filters'); ?></button>
          </div>
        </div>
        <section class="case-sort-section" aria-labelledby="caseSortHeading">
          <h3 id="caseSortHeading"><?= t('filters.sort_heading') ?></h3>
          <p id="caseSortGuidance"><?= t('filters.sort_guidance') ?></p>
          <div id="caseSortRows"></div>
          <div class="case-sort-actions">
            <button type="button" id="addCaseSort" class="filter-clear-btn"><?= t('filters.add_sort') ?></button>
            <button type="button" id="resetCaseSort" class="filter-clear-btn"><?= t('filters.reset_sort') ?></button>
          </div>
          <p id="caseSortSummary" role="status" aria-live="polite"></p>
        </section>
        <div id="caseViewSaveError" role="status" hidden>
          <span></span>
          <button type="button" id="retryCaseViewSave" class="filter-clear-btn"><?= t('common.retry') ?></button>
        </div>
      </div>

      <nav class="mobile-kanban-nav" id="mobileKanbanNav" aria-label="Workflow columns">
        <button type="button" id="mobileKanbanPrev" class="mobile-kanban-prev" aria-label="Previous workflow column" disabled>&lsaquo;</button>
        <select class="mobile-kanban-select" id="mobileKanbanSelect" aria-label="Select workflow column">
          <?php foreach ($resolvedWorkflowStageLabels as $status => $label): ?>
            <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" id="mobileKanbanNext" class="mobile-kanban-next" aria-label="Next workflow column">&rsaquo;</button>
        <span class="sr-only" id="kanbanNavAnnouncer" aria-live="polite" aria-atomic="true"></span>
      </nav>

      <section class="kanban-board" id="kanbanBoard">
        <?php foreach ($resolvedWorkflowStageLabels as $status => $label):
          $isCustomColumn = (strpos($status, 'Custom-') === 0);
          $colorKey = (int)($workflowColumnColorKeys[$status] ?? 0);
        ?>
        <div class="kanban-column<?= $isCustomColumn ? ' kanban-column-custom' : '' ?> workflow-color-<?= $colorKey ?>" data-status="<?= htmlspecialchars($status) ?>">
          <div class="kanban-column-header">
            <h2 class="kanban-column-title"><?= htmlspecialchars($label) ?></h2>
            <span class="kanban-column-count">0</span>
          </div>
          <div class="kanban-column-body">
            <p class="kanban-empty"><?php echo t('cases.no_cases_in_stage'); ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </section>

      <!-- Compact List View: populated by js/case-list.js as a projection
           of the rendered Kanban cards (same filtered case set). -->
      <section class="case-list-view" id="caseListView" aria-label="<?php echo t('cases.list.view_list'); ?>" hidden></section>
        </div>
        <!-- End Cases Tab -->

        <?php
        // Full-pane Insights gate state, rendered in place of the analytics
        // content when the practice lacks the Control entitlement
        // ($userHasControlAccess === false) or the check could not be
        // evaluated (null). Built once and reused by both Insights panes.
        // Mirrors the ap-upgrade-overlay visual language.
        ob_start();
        if ($userHasControlAccess === false) {
            ?>
            <div class="insights-upgrade-screen">
              <div class="insights-upgrade-card">
                <div class="ap-upgrade-overlay-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                  </svg>
                </div>
                <h3><?= t('insights.upgrade.screen_title') ?></h3>
                <p><?= t('insights.upgrade.screen_description') ?></p>
<?php if (isFeatureEnabled('BILLING_ENABLED') && $isCurrentUserPracticeAdmin): ?>
                <a href="billing.php" class="ap-upgrade-btn"><?= t('insights.upgrade.button') ?></a>
<?php else: ?>
                <p class="insights-upgrade-note"><?= t('insights.upgrade.contact_admin') ?></p>
<?php endif; ?>
              </div>
            </div>
            <?php
        } else {
            ?>
            <div class="insights-upgrade-screen">
              <div class="insights-upgrade-card">
                <h3><?= t('insights.upgrade.unavailable_title') ?></h3>
                <p><?= t('insights.upgrade.unavailable_description') ?></p>
                <button type="button" class="ap-upgrade-btn" onclick="window.location.reload()"><?= t('common.retry') ?></button>
              </div>
            </div>
            <?php
        }
        $insightsGateScreen = ob_get_clean();
        ?>

        <!-- Insights Tab (consolidated analytics + AI) -->
        <div class="main-tab-pane" id="insights-tab">
          <div class="analytics-pro">
<?php if ($userHasControlAccess === true): ?>
            <div class="insights-subtabs" id="insightsSubtabs" role="tablist">
              <button type="button" class="insights-subtab active" data-insights-subtab="practice" role="tab" aria-selected="true"><?= t('insights.navigation.practice') ?></button>
              <?php if (isFeatureEnabled('SHOW_LAB_INSIGHTS')): ?>
              <button type="button" class="insights-subtab" data-insights-subtab="labs" role="tab" aria-selected="false"><?= t('insights.navigation.lab') ?></button>
              <?php endif; ?>
            </div>
            <!-- Header -->
            <div class="ap-header">
              <div class="ap-header-content">
                <div>
                  <h1 class="ap-title"><?= t(isFeatureEnabled('SHOW_LAB_INSIGHTS') ? 'insights.practice_insights' : 'insights.title') ?></h1>
                  <p class="ap-subtitle"><?= t('insights.header_subtitle') ?></p>
                </div>
                <div class="ap-header-actions">
                  <button type="button" class="ap-btn ap-btn-secondary" id="apRefreshData">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                    </svg>
                    <?= t('insights.navigation.refresh') ?>
                  </button>
                </div>
              </div>
            </div>

            <!-- Metrics Grid -->
            <div class="ap-metrics-grid ap-metrics-grid--compact">
              <div class="ap-metric-card accent-blue">
                <div class="ap-metric-value" id="apCasesThisMonth">-</div>
                <div class="ap-metric-label"><?= t('insights.metrics.new_this_month') ?></div>
              </div>

              <div class="ap-metric-card accent-green">
                <div class="ap-metric-value" id="apActiveCases">-</div>
                <div class="ap-metric-label"><?= t('insights.metrics.active_cases') ?></div>
              </div>

              <div class="ap-metric-card accent-green">
                <div class="ap-metric-value" id="apDelivered">-</div>
                <div class="ap-metric-label"><?= t('insights.metrics.delivered_this_month') ?></div>
              </div>

              <div class="ap-metric-card accent-orange">
                <div class="ap-metric-value" id="apArchived">-</div>
                <div class="ap-metric-label"><?= t('insights.metrics.archived') ?></div>
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
                  <h2 class="ap-section-title"><?= t('insights.sections.case_flow_status') ?></h2>
                  <p class="ap-section-subtitle"><?= t('insights.sections.case_flow_status_subtitle') ?></p>
                </div>
              </div>

              <div class="ap-status-grid four-col">
                <div class="ap-status-card">
                  <div class="ap-status-header">
                    <div class="ap-status-indicator success"></div>
                    <h3 class="ap-status-title"><?= t('insights.sections.on_track') ?></h3>
                  </div>
                  <div class="ap-status-value" id="apOnTrack">0</div>
                  <div class="ap-status-label"><?= t('insights.sections.on_track_label') ?></div>
                </div>

                <div class="ap-status-card">
                  <div class="ap-status-header">
                    <div class="ap-status-indicator primary"></div>
                    <h3 class="ap-status-title"><?= t('insights.sections.due_soon') ?></h3>
                  </div>
                  <div class="ap-status-value" id="apDueSoon">0</div>
                  <div class="ap-status-label"><?= t('insights.sections.due_soon_label') ?></div>
                </div>

                <div class="ap-status-card">
                  <div class="ap-status-header">
                    <div class="ap-status-indicator appt-risk"></div>
                    <h3 class="ap-status-title"><?= t('insights.sections.appointment_risk') ?></h3>
                  </div>
                  <div class="ap-status-value" id="apAppointmentRisk">0</div>
                  <div class="ap-status-label"><?= t('insights.sections.appointment_risk_label') ?></div>
                </div>

                <div class="ap-status-card">
                  <div class="ap-status-header">
                    <div class="ap-status-indicator danger"></div>
                    <h3 class="ap-status-title"><?= t('insights.sections.late') ?></h3>
                  </div>
                  <div class="ap-status-value" id="apLate">0</div>
                  <div class="ap-status-label"><?= t('insights.sections.late_label') ?></div>
                </div>
              </div>
            </div>

            <!-- Smart Recommendations (Control tier - blur for Operate) -->
            <div class="ap-section ap-control-only" data-control-feature="smart-recommendations" id="aiRecommendationsSection">
              <div class="ap-section-header">
                <div class="ap-section-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                  </svg>
                </div>
                <div>
                  <h2 class="ap-section-title"><?= t('insights.recs.title') ?></h2>
                  <p class="ap-section-subtitle"><?= t('insights.recs.subtitle_practice') ?></p>
                </div>
                <div class="ap-section-actions">
                  <button type="button" class="ap-btn ap-btn-secondary" id="apRefreshAI">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                    </svg>
                    <?= t('insights.navigation.refresh') ?>
                  </button>
                </div>
              </div>

              <div class="ap-section-content">
                <ul class="ap-rec-list" id="apRecommendations">
                  <!-- Loading State -->
                  <li class="ap-rec ap-rec-empty" id="apAILoading">
                    <span class="ap-rec-text"><?= t('insights.ai.analyzing') ?></span>
                  </li>
                </ul>
                <button type="button" class="ap-rec-more" id="apRecsMore" style="display: none;"></button>
              </div>

              <!-- Upgrade overlay (shown when locked) -->
              <div class="ap-upgrade-overlay">
                <div class="ap-upgrade-overlay-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                  </svg>
                </div>
                <h3><?= t('insights.upgrade.recommendations_title') ?></h3>
                <p><?= t('insights.upgrade.recommendations_description') ?></p>
<?php if (isFeatureEnabled('BILLING_ENABLED') && $isCurrentUserPracticeAdmin): ?>
                <a href="billing.php" class="ap-upgrade-btn"><?= t('insights.upgrade.button') ?></a>
<?php endif; ?>
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
                  <h2 class="ap-section-title"><?= t('insights.sections.case_analysis') ?></h2>
                  <p class="ap-section-subtitle"><?= t('insights.sections.case_analysis_subtitle') ?></p>
                </div>
              </div>

              <div class="ap-charts-grid">
                <!-- Status Distribution Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title"><?= t('insights.charts.status_distribution') ?></h3>
                      <p class="ap-chart-description"><?= t('insights.charts.status_distribution_description') ?></p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apStatusPeriod">
                        <option value="active" selected><?= t('insights.filters.active_cases') ?></option>
                        <option value="all"><?= t('insights.filters.all_time') ?></option>
                        <option value="3"><?= t('insights.filters.last_n_months', ['count' => 3]) ?></option>
                        <option value="6"><?= t('insights.filters.last_n_months', ['count' => 6]) ?></option>
                        <option value="12"><?= t('insights.filters.last_n_months', ['count' => 12]) ?></option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apStatusChart" role="img" aria-label="Status distribution chart"></canvas>
                  </div>
                </div>

                <!-- Case Type Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title"><?= t('insights.charts.case_type_breakdown') ?></h3>
                      <p class="ap-chart-description"><?= t('insights.charts.case_type_breakdown_description') ?></p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apTypePeriod">
                        <option value="active" selected><?= t('insights.filters.active_cases') ?></option>
                        <option value="all"><?= t('insights.filters.all_time') ?></option>
                        <option value="3"><?= t('insights.filters.last_n_months', ['count' => 3]) ?></option>
                        <option value="6"><?= t('insights.filters.last_n_months', ['count' => 6]) ?></option>
                        <option value="12"><?= t('insights.filters.last_n_months', ['count' => 12]) ?></option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-scroll">
                    <div class="ap-chart-container">
                      <canvas id="apTypeChart" role="img" aria-label="Case type breakdown chart"></canvas>
                      <p class="insights-empty-state" id="apTypeChartEmpty" style="display: none; width: 100%;"><?= t('insights.empty.no_data') ?></p>
                    </div>
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
                  <h2 class="ap-section-title"><?= t('insights.sections.throughput_capacity') ?></h2>
                  <p class="ap-section-subtitle"><?= t('insights.sections.throughput_capacity_subtitle') ?></p>
                </div>
              </div>

              <div class="ap-charts-grid">
                <!-- Monthly Volume Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title"><?= t('insights.charts.monthly_case_volume') ?></h3>
                      <p class="ap-chart-description"><?= t('insights.charts.monthly_case_volume_description') ?></p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apVolumePeriod">
                        <option value="1"><?= t('insights.filters.last_month') ?></option>
                        <option value="3"><?= t('insights.filters.last_n_months', ['count' => 3]) ?></option>
                        <option value="6"><?= t('insights.filters.last_n_months', ['count' => 6]) ?></option>
                        <option value="12" selected><?= t('insights.filters.last_n_months', ['count' => 12]) ?></option>
                        <option value="24"><?= t('insights.filters.last_n_months', ['count' => 24]) ?></option>
                        <option value="36"><?= t('insights.filters.last_n_months', ['count' => 36]) ?></option>
                        <option value="all"><?= t('insights.filters.all_time') ?></option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apVolumeChart" role="img" aria-label="Monthly case volume chart"></canvas>
                  </div>
                </div>

                <!-- Team Performance Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title"><?= t('insights.charts.team_workload') ?></h3>
                      <p class="ap-chart-description"><?= t('insights.charts.team_workload_description') ?></p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apTeamFilter">
                        <option value="both" selected><?= t('insights.filters.all_assignees') ?></option>
                        <option value="users"><?= t('insights.filters.practice_users_only') ?></option>
                      </select>
                      <select class="ap-select" id="apTeamPeriod">
                        <option value="1"><?= t('insights.filters.last_month') ?></option>
                        <option value="3"><?= t('insights.filters.last_n_months', ['count' => 3]) ?></option>
                        <option value="6"><?= t('insights.filters.last_n_months', ['count' => 6]) ?></option>
                        <option value="12" selected><?= t('insights.filters.last_n_months', ['count' => 12]) ?></option>
                        <option value="24"><?= t('insights.filters.last_n_months', ['count' => 24]) ?></option>
                        <option value="36"><?= t('insights.filters.last_n_months', ['count' => 36]) ?></option>
                        <option value="all"><?= t('insights.filters.all_time') ?></option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apTeamChart" role="img" aria-label="Team performance chart"></canvas>
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
                <h3><?= t('insights.upgrade.throughput_title') ?></h3>
                <p><?= t('insights.upgrade.throughput_description') ?></p>
<?php if (isFeatureEnabled('BILLING_ENABLED') && $isCurrentUserPracticeAdmin): ?>
                <a href="billing.php" class="ap-upgrade-btn"><?= t('insights.upgrade.button') ?></a>
<?php endif; ?>
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
                  <h2 class="ap-section-title"><?= t('insights.sections.status_duration') ?></h2>
                  <p class="ap-section-subtitle"><?= t('insights.sections.status_duration_subtitle') ?></p>
                </div>
              </div>

              <div class="ap-insights-grid">
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apAvgLifecycle">-</div>
                  <div class="ap-insight-label"><?= t('insights.metrics.avg_case_lifecycle') ?></div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apFastestCase">-</div>
                  <div class="ap-insight-label"><?= t('insights.metrics.fastest_delivery') ?></div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apSlowestCase">-</div>
                  <div class="ap-insight-label"><?= t('insights.metrics.slowest_delivery') ?></div>
                </div>
              </div>

              <div class="ap-charts-grid">
                <!-- Status Duration Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title"><?= t('insights.charts.average_time_by_status') ?></h3>
                      <p class="ap-chart-description"><?= t('insights.charts.average_time_by_status_description') ?></p>
                    </div>
                    <div class="ap-chart-controls">
                      <select class="ap-select" id="apDurationPeriod">
                        <option value="active" selected><?= t('insights.filters.active_cases') ?></option>
                        <option value="all"><?= t('insights.filters.all_time') ?></option>
                        <option value="3"><?= t('insights.filters.last_n_months', ['count' => 3]) ?></option>
                        <option value="6"><?= t('insights.filters.last_n_months', ['count' => 6]) ?></option>
                        <option value="12"><?= t('insights.filters.last_n_months', ['count' => 12]) ?></option>
                      </select>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apDurationChart" role="img" aria-label="Status duration chart"></canvas>
                  </div>
                </div>

                <!-- Lifecycle Distribution Chart -->
                <div class="ap-chart-card">
                  <div class="ap-chart-header">
                    <div>
                      <h3 class="ap-chart-title"><?= t('insights.charts.lifecycle_distribution') ?></h3>
                      <p class="ap-chart-description"><?= t('insights.charts.lifecycle_distribution_description') ?></p>
                    </div>
                  </div>
                  <div class="ap-chart-container">
                    <canvas id="apLifecycleChart" role="img" aria-label="Lifecycle distribution chart"></canvas>
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
                <h3><?= t('insights.upgrade.duration_title') ?></h3>
                <p><?= t('insights.upgrade.duration_description') ?></p>
<?php if (isFeatureEnabled('BILLING_ENABLED') && $isCurrentUserPracticeAdmin): ?>
                <a href="billing.php" class="ap-upgrade-btn"><?= t('insights.upgrade.button') ?></a>
<?php endif; ?>
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
                  <h2 class="ap-section-title"><?= t('insights.sections.yoy_trends') ?></h2>
                  <p class="ap-section-subtitle"><?= t('insights.sections.yoy_trends_subtitle') ?></p>
                </div>
              </div>

              <div class="ap-insights-grid">
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apPeakMonth">-</div>
                  <div class="ap-insight-label"><?= t('insights.metrics.peak_season') ?></div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apGrowthRate">0%</div>
                  <div class="ap-insight-label"><?= t('insights.metrics.yoy_growth') ?></div>
                </div>
                <div class="ap-insight-card">
                  <div class="ap-insight-value" id="apNextPeak">-</div>
                  <div class="ap-insight-label"><?= t('insights.metrics.next_peak') ?></div>
                </div>
              </div>

              <div class="ap-chart-card full-width">
                <div class="ap-chart-header">
                  <div>
                    <h3 class="ap-chart-title"><?= t('insights.sections.yoy_comparison') ?></h3>
                    <p class="ap-chart-description"><?= t('insights.sections.yoy_comparison_subtitle') ?></p>
                  </div>
                </div>
                <div class="ap-chart-container">
                  <canvas id="apTrendsChart" role="img" aria-label="Year over year trends chart"></canvas>
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
                <h3><?= t('insights.upgrade.yoy_title') ?></h3>
                <p><?= t('insights.upgrade.yoy_description') ?></p>
<?php if (isFeatureEnabled('BILLING_ENABLED') && $isCurrentUserPracticeAdmin): ?>
                <a href="billing.php" class="ap-upgrade-btn"><?= t('insights.upgrade.button') ?></a>
<?php endif; ?>
              </div>
            </div>

            <!-- Cases Created by User Section -->
            <div class="ap-section">
              <div class="ap-section-header">
                <div class="ap-section-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                  </svg>
                </div>
                <div>
                  <h2 class="ap-section-title"><?= t('insights.sections.cases_created_by_user') ?></h2>
                  <p class="ap-section-subtitle"><?= t('insights.sections.cases_created_by_user_subtitle') ?></p>
                </div>
                <div class="ap-section-actions">
                  <select class="ap-select" id="apCreatorPeriod">
                    <option value="all" selected><?= t('insights.filters.all_time') ?></option>
                    <option value="active"><?= t('insights.filters.active_cases') ?></option>
                    <option value="3"><?= t('insights.filters.last_n_months', ['count' => 3]) ?></option>
                    <option value="6"><?= t('insights.filters.last_n_months', ['count' => 6]) ?></option>
                    <option value="12"><?= t('insights.filters.last_n_months', ['count' => 12]) ?></option>
                  </select>
                </div>
              </div>

              <div class="ap-chart-scroll">
                <div class="ap-chart-container" id="apCreatorBreakdown">
                  <canvas id="apCreatorChart" role="img" aria-label="Cases created by user chart"></canvas>
                  <p class="insights-empty-state" id="apCreatorBreakdownEmpty" style="display: none; width: 100%;"><?= t('insights.creators.empty') ?></p>
                </div>
              </div>
            </div>

            <!-- Loading Overlay -->
            <div class="ap-loading" id="apLoading" style="display: none;">
              <div class="ap-loading-spinner"></div>
              <p class="ap-loading-text"><?= t('insights.loading.analytics') ?></p>
            </div>

            <!-- Error Overlay -->
            <div class="ap-error" id="apError" style="display: none;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
              </svg>
              <p id="apErrorText"><?= t('insights.error.analytics') ?></p>
            </div>
<?php else: ?>
<?php echo $insightsGateScreen; ?>
<?php endif; ?>
          </div>
        </div>
        <!-- End Insights Tab -->

        <?php if (isFeatureEnabled('SHOW_LAB_INSIGHTS')): ?>
        <!-- Lab Insights Tab -->
        <div class="main-tab-pane" id="lab-insights-tab">
          <div class="analytics-pro li-root">
<?php if ($userHasControlAccess === true): ?>
            <div class="insights-subtabs" id="labInsightsSubtabs" role="tablist">
              <button type="button" class="insights-subtab" data-insights-subtab="practice" role="tab" aria-selected="false"><?= t('insights.navigation.practice') ?></button>
              <?php if (isFeatureEnabled('SHOW_LAB_INSIGHTS')): ?>
              <button type="button" class="insights-subtab active" data-insights-subtab="labs" role="tab" aria-selected="true"><?= t('insights.navigation.lab') ?></button>
              <?php endif; ?>
            </div>
            <div class="ap-header">
              <div class="ap-header-content">
                <div>
                  <h1 class="ap-title"><?= t('insights.lab_insights') ?></h1>
                  <p class="ap-subtitle"><?= t('insights.lab_header_subtitle') ?></p>
                </div>
                <div class="ap-header-actions">
                  <select class="ap-select" id="liRangeSelect">
                    <option value="3"><?= t('insights.filters.last_n_months', ['count' => 3]) ?></option>
                    <option value="6"><?= t('insights.filters.last_n_months', ['count' => 6]) ?></option>
                    <option value="12" selected><?= t('insights.filters.last_n_months', ['count' => 12]) ?></option>
                    <option value="24"><?= t('insights.filters.last_n_months', ['count' => 24]) ?></option>
                    <option value="all"><?= t('insights.filters.all_time') ?></option>
                  </select>
                  <button type="button" class="ap-btn ap-btn-secondary" id="liRefreshData">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                    </svg>
                    <?= t('insights.navigation.refresh') ?>
                  </button>
                </div>
              </div>
            </div>

            <!-- Empty states -->
            <div class="li-empty-state" id="liNoLabsEmptyState" style="display: none;">
              <h3><?= t('insights.labs.no_labs_title') ?></h3>
              <p><?= t('insights.labs.no_labs_description') ?></p>
            </div>
            <div class="li-empty-state" id="liNoHistoryEmptyState" style="display: none;">
              <h3><?= t('insights.labs.no_history_title') ?></h3>
              <p><?= t('insights.labs.no_history_description') ?></p>
            </div>

            <!-- Control-gated content (reuses the same [data-control-feature] blur pattern as Practice Insights) -->
            <div class="ap-section ap-control-only" data-control-feature="lab-insights" id="liContent" style="display: none;">
              <div class="ap-upgrade-overlay">
                <div class="ap-upgrade-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0110 0v4"></path>
                  </svg>
                </div>
                <h3><?= t('insights.upgrade.lab_insights_title') ?></h3>
                <p><?= t('insights.upgrade.lab_insights_description') ?></p>
<?php if (isFeatureEnabled('BILLING_ENABLED') && $isCurrentUserPracticeAdmin): ?>
                <a href="billing.php" class="ap-upgrade-btn"><?= t('insights.upgrade.button') ?></a>
<?php endif; ?>
              </div>

              <!-- Legacy Lab Insights layout - rendered when the performance
                   metrics layer is unavailable (performance: null) -->
              <div id="liLegacy">
              <!-- Summary Cards -->
              <div class="ap-metrics-grid li-summary-grid ap-metrics-grid--compact">
                <div class="ap-metric-card accent-blue">
                  <div class="ap-metric-value" id="liActiveLabs">-</div>
                  <div class="ap-metric-label"><?= t('insights.metrics.active_labs') ?></div>
                </div>
                <div class="ap-metric-card accent-blue">
                  <div class="ap-metric-value" id="liCasesAtLabs">-</div>
                  <div class="ap-metric-label"><?= t('insights.metrics.cases_at_labs') ?></div>
                </div>
                <div class="ap-metric-card accent-green">
                  <div class="ap-metric-value" id="liAvgTurnaround">-</div>
                  <div class="ap-metric-label li-label-with-tooltip">
                    <?= t('insights.metrics.average_lab_turnaround') ?>
                  </div>
                </div>
                <div class="ap-metric-card accent-orange">
                  <div class="ap-metric-value" id="liLateCases">-</div>
                  <div class="ap-metric-label"><?= t('insights.metrics.currently_late_cases') ?></div>
                </div>
                <div class="ap-metric-card accent-orange">
                  <div class="ap-metric-value" id="liRevisions">-</div>
                  <div class="ap-metric-label"><?= t('insights.metrics.revisions') ?></div>
                </div>
                <div class="ap-metric-card accent-blue">
                  <div class="ap-metric-value" id="liDirectTransfers">-</div>
                  <div class="ap-metric-label"><?= t('insights.metrics.direct_lab_transfers') ?></div>
                </div>
              </div>

              <!-- Lab Performance Table -->
              <div class="ap-section li-inner-section">
                <div class="ap-section-header">
                  <div class="ap-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                      <line x1="18" y1="20" x2="18" y2="10"></line>
                      <line x1="12" y1="20" x2="12" y2="4"></line>
                      <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                  </div>
                  <div>
                    <h2 class="ap-section-title"><?= t('insights.sections.lab_performance') ?></h2>
                    <p class="ap-section-subtitle"><?= t('insights.sections.lab_performance_subtitle') ?></p>
                  </div>
                </div>
                <div class="li-table-wrap">
                  <table class="li-table" id="liLabTable">
                    <thead>
                      <tr>
                        <th data-sort="name"><?= t('insights.labs.table_headers.lab') ?></th>
                        <th data-sort="currentWorkload"><?= t('insights.labs.table_headers.current_workload') ?></th>
                        <th data-sort="casesAssigned" id="liCasesAssignedHeader"><?= t('insights.labs.table_headers.cases_assigned') ?></th>
                        <th data-sort="completed" id="liCompletedHeader"><?= t('insights.labs.table_headers.completed') ?></th>
                        <th data-sort="avgTurnaroundDays" id="liTurnaroundHeader"><?= t('insights.labs.table_headers.avg_turnaround') ?></th>
                        <th data-sort="lateCaseRate" id="liLateRateHeader"><?= t('insights.labs.table_headers.current_late_rate') ?></th>
                        <th data-sort="lateDeliveryRate" id="liLateDeliveryRateHeader"><?= t('insights.labs.table_headers.late_delivery_rate') ?></th>
                        <th data-sort="revisionCount"><?= t('insights.labs.table_headers.revisions') ?></th>
                        <th data-sort="revisionRate"><?= t('insights.labs.table_headers.revision_rate') ?></th>
                        <th data-sort="directTransfersOut"><?= t('insights.labs.table_headers.direct_transfers') ?></th>
                      </tr>
                    </thead>
                    <tbody id="liLabTableBody"></tbody>
                  </table>
                </div>
              </div>

              <!-- Trend -->
              <div class="ap-section li-inner-section" id="liTrendSection" style="display: none;">
                <div class="ap-section-header">
                  <div class="ap-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                      <polyline points="17 6 23 6 23 12"></polyline>
                    </svg>
                  </div>
                  <div>
                    <h2 class="ap-section-title"><?= t('insights.sections.cases_assigned_over_time') ?></h2>
                    <p class="ap-section-subtitle"><?= t('insights.sections.cases_assigned_over_time_subtitle') ?></p>
                  </div>
                </div>
                <div class="ap-chart-card full-width">
                  <div class="ap-chart-container">
                    <canvas id="liTrendChart" role="img" aria-label="Lab case trend chart"></canvas>
                  </div>
                </div>
              </div>
              </div><!-- /#liLegacy -->

              <!-- Lab Performance Intelligence layout (drives off the
                   additive `performance` payload from get-lab-insights.php) -->
              <div id="liPerf" style="display: none;">
                <div class="ap-metrics-grid li-summary-grid ap-metrics-grid--compact" id="liPerfSummary"></div>
                <div class="ap-now-strip" id="liPerfNow"></div>

                <div class="ap-section li-inner-section" id="liRecs" style="display: none;">
                  <div class="ap-section-header">
                    <div class="ap-section-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                      </svg>
                    </div>
                    <div>
                      <h2 class="ap-section-title"><?= t('insights.recs.title') ?></h2>
                      <p class="ap-section-subtitle"><?= t('insights.recs.subtitle') ?></p>
                    </div>
                  </div>
                  <ul class="ap-rec-list" id="liRecsList"></ul>
                  <button type="button" class="ap-rec-more" id="liRecsMore" style="display: none;"></button>
                </div>

                <div class="ap-section li-inner-section">
                  <div class="ap-section-header">
                    <div class="ap-section-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                      </svg>
                    </div>
                    <div>
                      <h2 class="ap-section-title"><?= t('insights.perf.sections.lab_performance') ?></h2>
                      <p class="ap-section-subtitle"><?= t('insights.perf.sections.lab_performance_subtitle') ?></p>
                    </div>
                  </div>
                  <div class="li-table-wrap">
                    <table class="li-table" id="liPerfTable">
                      <thead>
                        <tr>
                          <th data-sort="name"><?= t('insights.perf.table.lab') ?></th>
                          <th data-sort="cases"><?= t('insights.perf.table.cases') ?></th>
                          <th data-sort="turnaround"><?= t('insights.perf.table.avg_turnaround') ?></th>
                          <th data-sort="onTime"><?= t('insights.perf.table.on_time') ?></th>
                          <th data-sort="remakeRate"><?= t('insights.perf.table.remake_rate') ?></th>
                          <th data-sort="labAttrRate"><?= t('insights.perf.table.lab_attributed') ?></th>
                          <th data-sort="workload"><?= t('insights.perf.table.current_workload') ?></th>
                        </tr>
                      </thead>
                      <tbody id="liPerfTableBody"></tbody>
                    </table>
                  </div>
                  <p class="li-table-footnote"><?= t('insights.perf.context.workload_now_note') ?></p>
                </div>

                <div class="ap-section li-inner-section" id="liLabDetail" style="display: none;">
                  <div class="ap-section-header">
                    <div class="ap-section-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                        <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                      </svg>
                    </div>
                    <div>
                      <h2 class="ap-section-title" id="liLabDetailTitle"><?= t('insights.perf.detail.title') ?></h2>
                      <p class="ap-section-subtitle" id="liLabDetailSubtitle"></p>
                    </div>
                    <div class="ap-section-actions">
                      <select class="ap-select" id="liLabDetailSelect" aria-label="<?= t('insights.perf.detail.select_label') ?>"></select>
                    </div>
                  </div>
                  <div id="liLabDetailBody"></div>
                </div>

                <div class="ap-section li-inner-section" id="liRevisionsSection" style="display: none;">
                  <div class="ap-section-header">
                    <div class="ap-section-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="1 4 1 10 7 10"></polyline>
                        <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                      </svg>
                    </div>
                    <div>
                      <h2 class="ap-section-title"><?= t('insights.perf.revisions.title') ?></h2>
                      <p class="ap-section-subtitle"><?= t('insights.perf.revisions.subtitle') ?></p>
                    </div>
                  </div>
                  <p class="li-revision-note"><?= t('insights.perf.revisions.note') ?></p>
                  <div class="li-table-wrap">
                    <table class="li-table">
                      <thead>
                        <tr>
                          <th><?= t('insights.perf.table.lab') ?></th>
                          <th><?= t('insights.perf.revisions.count_header') ?></th>
                          <th><?= t('insights.perf.revisions.rate_header') ?></th>
                        </tr>
                      </thead>
                      <tbody id="liRevisionsBody"></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- Loading Overlay -->
            <div class="ap-loading" id="liLoading" style="display: none;">
              <div class="ap-loading-spinner"></div>
              <p class="ap-loading-text"><?= t('insights.labs.loading') ?></p>
            </div>

            <!-- Error Overlay -->
            <div class="li-error" id="liError" style="display: none;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
              </svg>
              <p id="liErrorText"><?= t('insights.error.labs') ?></p>
            </div>
<?php else: ?>
<?php echo $insightsGateScreen; ?>
<?php endif; ?>
          </div>
        </div>
        <!-- End Lab Insights Tab -->
        <?php endif; ?>
      </div>
      <!-- End Tab Content -->
    </main>

      <!-- Create Case Modal -->
      <div id="createCaseModal" class="modal">
        <div class="modal-content create-case-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?php echo t('cases.create_new_case'); ?></h2>
            <button type="button" class="btn-close" id="createCaseClose"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            <div id="caseViewLoading" class="case-view-loading" style="display:none;">
              <p><?php echo t('cases.loading'); ?></p>
            </div>

            <div id="caseViewError" class="case-view-error" style="display:none;">
              <p class="case-view-error-text"><?php echo t('cases.load_error'); ?></p>
              <div class="case-view-error-actions">
                <button type="button" class="btn btn-primary" id="caseViewRetry"><?php echo t('common.retry'); ?></button>
                <button type="button" class="btn btn-secondary" id="caseViewErrorClose"><?php echo t('common.close'); ?></button>
              </div>
            </div>

            <div id="caseViewTabs" class="case-modal-tabs">
              <button type="button" class="case-tab case-tab-active" data-tab="details"><?php echo t('cases.details'); ?></button>
<?php if (isFeatureEnabled('SHOW_COMMENTS')): ?>
              <button type="button" class="case-tab" data-tab="comments"><?php echo t('cases.comments.title'); ?> <span id="caseCommentsCount" class="case-comments-count" style="display: none;">0</span></button>
<?php endif; ?>
<?php if (isFeatureEnabled('SHOW_REVISION_HISTORY')): ?>
              <button type="button" class="case-tab" data-tab="history"><?php echo t('cases.history.title'); ?></button>
<?php endif; ?>
            </div>

            <!-- Compact provenance metadata for saved cases only; review
                 state lives in the Workflow section and Record Remake in
                 the Remake History header. Hidden for new cases. -->
            <div id="caseModalMeta" class="case-modal-meta" style="display: none;">
              <span class="case-meta-item" id="caseCreatedByItem">
                <span class="case-meta-label"><?php echo t('cases.created_by'); ?></span>
                <span id="createdByDisplay" class="case-meta-value"><?php echo t('common.unknown'); ?></span>
              </span>
            </div>

            <form id="createCaseForm" class="case-tab-panel case-tab-panel-active" enctype="multipart/form-data" novalidate>
<?php if (isFeatureEnabled('SHOW_ACTIVITY_TIMELINE')): ?>
              <!-- Activity Timeline (visible when editing existing cases) -->
              <div id="caseActivityTimeline" class="activity-timeline-horizontal" style="display: none;">
                <div class="activity-timeline-header">
                  <span class="activity-timeline-label"><?php echo t('cases.activity.title'); ?></span>
                  <span class="activity-timeline-toggle" id="activityTimelineToggle" title="<?php echo t('common.expand_collapse'); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                  </span>
                </div>
                <div id="activityTimelineContent" class="activity-timeline-track">
                  <p class="activity-empty-state"><?php echo t('cases.activity.empty'); ?></p>
                </div>
              </div>
<?php endif; ?>

              <!-- Workflow: operational state of the loaded case, first
                   section under the metadata for Edit Case. Status is
                   edit-only - new cases enter the first active workflow
                   stage automatically (derived server-side in
                   create-case.php). For Create Case the whole section is
                   hidden and Assigned To is relocated into the dates row
                   by app.js. -->
              <div id="workflowSection" class="workflow-section">
                <h3 class="workflow-title"><?php echo t('cases.workflow'); ?></h3>
                <div class="modal-form-grid workflow-grid">
                  <div class="form-field" id="statusFieldWrap">
                    <label for="status"><?php echo t('cases.status_label'); ?> <span class="required">*</span></label>
                    <select id="status" name="status" required>
                      <option value=""><?php echo t('select.select_status'); ?></option>
                      <?php foreach ($resolvedWorkflowStageLabels as $status => $label): ?>
                      <option value="<?= htmlspecialchars($status) ?>"<?= ($status === $workflowStageOrder[0] ? ' selected' : '') ?>><?= htmlspecialchars($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="form-field" id="assignedToFieldWrap">
                    <label for="assignedTo"><?php echo t('cases.assigned_to'); ?></label>
                    <select id="assignedTo" name="assignedTo">
                      <option value=""><?php echo t('select.select_user'); ?></option>
                    </select>
                  </div>
                </div>
                <div id="reviewStatusContainer" class="review-status-field needs-review case-review-feature" style="display: none;">
                  <div class="review-status-row">
                    <span class="case-meta-label"><?php echo t('cases.review_status'); ?></span>
                    <span id="reviewStatusValue" class="review-status-value"><?php echo t('cases.needs_review'); ?></span>
                    <span id="reviewStatusTimestamp" class="review-status-timestamp"></span>
                    <button type="button" id="reviewStatusAction" class="review-status-action" data-reviewed="false">
                      <?php echo t('cases.mark_reviewed'); ?>
                    </button>
                  </div>
                </div>
              </div>

              <div class="modal-form-grid">
                <div class="form-field">
                  <label for="patientFirstName"><?php echo t('cases.patient_first_name'); ?> <span class="required">*</span></label>
                  <input id="patientFirstName" name="patientFirstName" type="text" required>
                </div>

                <div class="form-field">
                  <label for="patientLastName"><?php echo t('cases.patient_last_name'); ?> <span class="required">*</span></label>
                  <input id="patientLastName" name="patientLastName" type="text" required>
                </div>

                <div class="form-field">
                  <label for="patientDOB"><?php echo t('cases.patient_dob'); ?> <span class="required">*</span></label>
                  <input id="patientDOB" name="patientDOB" type="date"
                         placeholder="<?php echo t('common.date_format'); ?>" title="<?php echo t('common.date_format'); ?>" required>
                </div>

                <div class="form-field">
                  <label for="patientGender"><?php echo t('cases.gender'); ?> <span class="required">*</span></label>
                  <select id="patientGender" name="patientGender" required>
                    <option value=""><?php echo t('select.select_gender'); ?></option>
                    <option value="Male"><?php echo t('options.gender.male'); ?></option>
                    <option value="Female"><?php echo t('options.gender.female'); ?></option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="dentistName"><?php echo t('cases.dentist_name'); ?> <span class="required">*</span></label>
                  <div class="autocomplete-wrapper">
                    <input id="dentistName" name="dentistName" type="text" required autocomplete="off" aria-autocomplete="list" aria-controls="dentistNameSuggestions">
                    <div id="dentistNameSuggestions" class="autocomplete-dropdown" role="listbox" aria-label="<?php echo t('cases.dentist_suggestions'); ?>"></div>
                  </div>
                </div>

                <div class="form-field">
                  <label for="caseType"><?php echo t('cases.case_type'); ?> <span class="required">*</span></label>
                  <select id="caseType" name="caseType" required>
                    <option value=""><?php echo t('select.select_case_type'); ?></option>
                    <?php echo renderCaseTypeOptions(getCreatableCaseTypes()); ?>
                  </select>
                </div>

              </div>

              <!-- Clinical Details Section (case-type-specific fields) -->
              <div id="clinicalDetailsSection" class="clinical-details-section" style="display: none;">
                <h3 class="clinical-details-title"><?php echo t('cases.clinical.title'); ?></h3>
                <div class="clinical-details-grid">
                  <!-- Tooth Shade: clinical info, optional for every case type.
                       It lists all known types so the Clinical section is
                       reachable for every selected type, matching its prior
                       always-visible behavior. -->
                  <div class="form-field clinical-field" data-case-types="<?php echo htmlspecialchars(implode(',', getAllKnownCaseTypes())); ?>">
                    <label for="toothShade"><?php echo t('cases.tooth_shade'); ?></label>
                    <input id="toothShade" name="toothShade" type="text" placeholder="<?php echo t('cases.clinical.placeholders.toothShade'); ?>" title="<?php echo t('cases.clinical.placeholders.toothShade'); ?>">
                  </div>

                  <!-- Material: case-type-scoped. The applicability list is
                       rendered from the canonical server map so client and
                       server can never disagree; visibility, clear-on-hide
                       and conditional requiredness all run through the
                       clinical-field mechanism. -->
                  <div class="form-field clinical-field" data-case-types="<?php echo htmlspecialchars(implode(',', getCaseTypesRequiringMaterial())); ?>" data-conditionally-required="true">
                    <label for="material"><?php echo t('cases.material'); ?> <span class="required">*</span></label>
                    <select id="material" name="material">
                      <option value=""><?php echo t('select.select_material'); ?></option>
                      <option value="Zirconia"><?php echo t('options.materials.zirconia'); ?></option>
                      <option value="Lithium Disilicate"><?php echo t('options.materials.lithium_disilicate'); ?></option>
                      <option value="PFM"><?php echo t('options.materials.pfm'); ?></option>
                      <option value="PFZ"><?php echo t('options.materials.pfz'); ?></option>
                      <option value="3D Printed"><?php echo t('options.materials.3d_printed'); ?></option>
                    </select>
                  </div>

                  <!-- Crown fields -->
                  <div class="form-field clinical-field" data-case-types="Crown" data-conditionally-required="true">
                    <label for="clinicalToothNumber"><?php echo t('cases.fields.clinical_toothNumber'); ?> <span class="required">*</span></label>
                    <input id="clinicalToothNumber" name="clinicalToothNumber" type="text" placeholder="<?php echo t('cases.clinical.placeholders.toothNumber'); ?>" title="<?php echo t('cases.clinical.help.toothNumber'); ?>">
                  </div>

                  <!-- Bridge fields -->
                  <div class="form-field clinical-field" data-case-types="Bridge" data-conditionally-required="true">
                    <label for="clinicalAbutmentTeeth"><?php echo t('cases.fields.clinical_abutmentTeeth'); ?> <span class="required">*</span></label>
                    <input id="clinicalAbutmentTeeth" name="clinicalAbutmentTeeth" type="text" placeholder="<?php echo t('cases.clinical.placeholders.abutmentTeeth'); ?>">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Bridge" data-conditionally-required="true">
                    <label for="clinicalPonticTeeth"><?php echo t('cases.fields.clinical_ponticTeeth'); ?> <span class="required">*</span></label>
                    <input id="clinicalPonticTeeth" name="clinicalPonticTeeth" type="text" placeholder="<?php echo t('cases.clinical.placeholders.ponticTeeth'); ?>">
                  </div>

                  <!-- Implant Crown fields -->
                  <div class="form-field clinical-field" data-case-types="Implant Crown" data-conditionally-required="true">
                    <label for="clinicalImplantToothNumber"><?php echo t('cases.fields.clinical_implantToothNumber'); ?> <span class="required">*</span></label>
                    <input id="clinicalImplantToothNumber" name="clinicalImplantToothNumber" type="text" placeholder="<?php echo t('cases.clinical.placeholders.implantToothNumber'); ?>">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown">
                    <label for="clinicalAbutmentType"><?php echo t('cases.fields.clinical_abutmentType'); ?></label>
                    <select id="clinicalAbutmentType" name="clinicalAbutmentType">
                      <option value=""><?php echo t('select.select_type'); ?></option>
                      <option value="Custom"><?php echo t('options.abutment_type.custom'); ?></option>
                      <option value="Ti-Base"><?php echo t('options.abutment_type.ti_base'); ?></option>
                      <option value="Zirconia"><?php echo t('options.abutment_type.zirconia'); ?></option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown,Implant Surgical Guide">
                    <label for="clinicalImplantSystem"><?php echo t('cases.fields.clinical_implantSystem'); ?></label>
                    <input id="clinicalImplantSystem" name="clinicalImplantSystem" type="text" placeholder="<?php echo t('cases.clinical.placeholders.implantSystem'); ?>">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown,Implant Surgical Guide">
                    <label for="clinicalPlatformSize"><?php echo t('cases.fields.clinical_platformSize'); ?></label>
                    <input id="clinicalPlatformSize" name="clinicalPlatformSize" type="text" placeholder="<?php echo t('cases.clinical.placeholders.platformSize'); ?>">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Implant Crown,Implant Surgical Guide">
                    <label for="clinicalScanBodyUsed"><?php echo t('cases.fields.clinical_scanBodyUsed'); ?></label>
                    <input id="clinicalScanBodyUsed" name="clinicalScanBodyUsed" type="text" placeholder="<?php echo t('cases.clinical.placeholders.scanBodyUsed'); ?>">
                  </div>

                  <!-- Implant Surgical Guide fields -->
                  <div class="form-field clinical-field" data-case-types="Implant Surgical Guide">
                    <label for="clinicalImplantSites"><?php echo t('cases.fields.clinical_implantSites'); ?></label>
                    <input id="clinicalImplantSites" name="clinicalImplantSites" type="text" placeholder="<?php echo t('cases.clinical.placeholders.implantSites'); ?>">
                  </div>

                  <!-- Denture fields -->
                  <div class="form-field clinical-field" data-case-types="Denture">
                    <label for="clinicalDentureJaw"><?php echo t('cases.fields.clinical_dentureJaw'); ?></label>
                    <select id="clinicalDentureJaw" name="clinicalDentureJaw">
                      <option value=""><?php echo t('select.select_jaw'); ?></option>
                      <option value="Maxillary"><?php echo t('options.jaw.maxillary'); ?></option>
                      <option value="Mandibular"><?php echo t('options.jaw.mandibular'); ?></option>
                      <option value="Both"><?php echo t('options.jaw.both'); ?></option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Denture">
                    <label for="clinicalDentureType"><?php echo t('cases.fields.clinical_dentureType'); ?></label>
                    <select id="clinicalDentureType" name="clinicalDentureType">
                      <option value=""><?php echo t('select.select_type'); ?></option>
                      <option value="Immediate"><?php echo t('options.denture_type.immediate'); ?></option>
                      <option value="Definitive"><?php echo t('options.denture_type.definitive'); ?></option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Denture,AOX">
                    <label for="clinicalGingivalShade"><?php echo t('cases.fields.clinical_gingivalShade'); ?></label>
                    <input id="clinicalGingivalShade" name="clinicalGingivalShade" type="text" placeholder="<?php echo t('cases.clinical.placeholders.gingivalShade'); ?>">
                  </div>

                  <!-- Partial fields -->
                  <div class="form-field clinical-field" data-case-types="Partial">
                    <label for="clinicalPartialJaw"><?php echo t('cases.fields.clinical_partialJaw'); ?></label>
                    <select id="clinicalPartialJaw" name="clinicalPartialJaw">
                      <option value=""><?php echo t('select.select_jaw'); ?></option>
                      <option value="Maxillary"><?php echo t('options.jaw.maxillary'); ?></option>
                      <option value="Mandibular"><?php echo t('options.jaw.mandibular'); ?></option>
                      <option value="Both"><?php echo t('options.jaw.both'); ?></option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Partial" data-conditionally-required="true">
                    <label for="clinicalTeethToReplace"><?php echo t('cases.fields.clinical_teethToReplace'); ?> <span class="required">*</span></label>
                    <input id="clinicalTeethToReplace" name="clinicalTeethToReplace" type="text" placeholder="<?php echo t('cases.clinical.placeholders.teethToReplace'); ?>">
                  </div>
                  <div class="form-field clinical-field" data-case-types="Partial">
                    <label for="clinicalPartialMaterial"><?php echo t('cases.fields.clinical_partialMaterial'); ?></label>
                    <select id="clinicalPartialMaterial" name="clinicalPartialMaterial">
                      <option value=""><?php echo t('select.select_material'); ?></option>
                      <option value="Cast Metal"><?php echo t('options.partial_material.cast_metal'); ?></option>
                      <option value="Valplast Flex Resin"><?php echo t('options.partial_material.valplast_flex_resin'); ?></option>
                      <option value="Acrylic Base"><?php echo t('options.partial_material.acrylic_base'); ?></option>
                      <option value="Interim Acrylic"><?php echo t('options.partial_material.interim_acrylic'); ?></option>
                    </select>
                  </div>
                  <div class="form-field clinical-field" data-case-types="Partial">
                    <label for="clinicalPartialGingivalShade"><?php echo t('cases.fields.clinical_partialGingivalShade'); ?></label>
                    <input id="clinicalPartialGingivalShade" name="clinicalPartialGingivalShade" type="text" placeholder="<?php echo t('cases.clinical.placeholders.partialGingivalShade'); ?>">
                  </div>
                </div>
              </div>

              <!-- Remake History strip - compact, collapsible, sits between
                   Clinical Details and dates so it does not interrupt the
                   core case-editing flow. Only visible for saved cases;
                   populated by case-remakes.js. Workflow regressions never
                   appear here. -->
              <div id="caseRemakeHistory" class="remake-history-strip" style="display: none;">
                <div class="remake-history-strip-row">
                  <button type="button" class="remake-history-strip-header" id="caseRemakeHistoryToggle" aria-expanded="false" aria-controls="caseRemakeHistoryList">
                    <span class="remake-history-strip-label"><?php echo t('remakes.history_heading'); ?></span>
                    <span class="remake-history-strip-count" id="caseRemakeHistoryCount"></span>
                    <span class="remake-history-strip-summary" id="caseRemakeHistorySummary"></span>
                    <svg class="remake-history-strip-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                  </button>
                  <button type="button" class="btn-remake-action" id="recordRemakeBtn" hidden><?php echo t('remakes.record_remake'); ?></button>
                </div>
                <div id="caseRemakeHistoryList" class="remake-history-strip-list" hidden></div>
              </div>

              <!-- Dates and notes -->
              <div class="modal-form-grid date-status-row">
                <div class="form-field">
                  <label for="dueDate"><?php echo t('cases.due_date'); ?></label>
                  <input id="dueDate" name="dueDate" type="date"
                         placeholder="<?php echo t('common.date_format'); ?>" title="<?php echo t('common.date_format'); ?>">
                </div>

                <div class="form-field">
                  <label for="patientAppointmentDate"><?php echo t('cases.patient_appointment_date'); ?></label>
                  <input id="patientAppointmentDate" name="patientAppointmentDate" type="date"
                         placeholder="<?php echo t('common.date_format'); ?>" title="<?php echo t('common.date_format'); ?>">
                </div>

                <div class="form-field form-field-notes">
                  <label for="notes"><?php echo t('cases.notes'); ?></label>
                  <div class="char-counter-wrapper">
                    <textarea id="notes" name="notes" rows="3" maxlength="3000"
                              placeholder="<?php echo t('cases.notes_placeholder'); ?>"
                              aria-describedby="notesCharCounter"></textarea>
                    <div id="notesCharCounter" class="char-counter">0 / 3,000 characters</div>
                  </div>
                </div>
              </div>

              <div class="shipping-section" id="shippingSection">
                <button type="button" class="shipping-toggle" id="shippingToggle" aria-expanded="true" aria-controls="shippingFields">
                  <span class="shipping-toggle-label"><?php echo t('cases.shipping_optional'); ?></span>
                  <svg class="shipping-toggle-caret" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"></polyline>
                  </svg>
                </button>
                <div class="shipping-fields" id="shippingFields">
                <div class="form-field">
                  <label for="carrier"><?php echo t('cases.carrier'); ?></label>
                  <select id="carrier" name="carrier">
                    <option value=""><?php echo t('select.select_carrier'); ?></option>
                    <option value="UPS">UPS</option>
                    <option value="FedEx">FedEx</option>
                    <option value="USPS">USPS</option>
                    <option value="DHL">DHL</option>
                    <option value="Other">Other</option>
                  </select>
                </div>
                <div class="form-field custom-carrier-field" id="customCarrierField">
                  <label for="customCarrier"><?php echo t('cases.other_carrier'); ?></label>
                  <input type="text" id="customCarrier" name="customCarrier" placeholder="<?php echo t('cases.other_carrier_placeholder'); ?>" maxlength="100">
                </div>
                <div class="form-field">
                  <label for="trackingNumber"><?php echo t('cases.tracking_number'); ?></label>
                  <input type="text" id="trackingNumber" name="trackingNumber" placeholder="<?php echo t('cases.tracking_number_placeholder'); ?>" maxlength="100">
                  <a id="trackingNumberLink" class="tracking-number-link" href="#" target="_blank" rel="noopener noreferrer nofollow" style="display:none;"><?php echo t('cases.track_package'); ?></a>
                </div>
                </div>
              </div>

              <div class="attachments-section-header">
                <h3 class="attachments-title"><?php echo t('cases.attachments_optional'); ?></h3>
<?php if (isFeatureEnabled('SHOW_CASE_DOWNLOAD_ALL')): ?>
                <div class="attachment-download-all" id="attachmentDownloadAll" style="display: none;">
                  <button type="button" class="btn-secondary" id="downloadAllAttachmentsBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                      <polyline points="7 10 12 15 17 10"></polyline>
                      <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    <span class="download-all-label"><?php echo t('attachments.download_all'); ?></span>
                  </button>
                  <div id="downloadAllAttachmentsStatus" class="download-all-status" style="display: none;" aria-live="polite" aria-atomic="true"></div>
                </div>
<?php endif; ?>
              </div>
              <div id="attachmentsLoadStatus" class="attachments-load-status" role="status" aria-live="polite" style="display: none;"></div>
              <div class="attachments-grid">
                <div class="attachment-group">
                  <div class="attachment-row">
                    <span class="attachment-header"><?php echo t('attachments.photos'); ?></span>
                    <span class="attachment-count" data-attachment-count></span>
                    <label class="file-button" tabindex="0">
                      <?php echo t('attachments.add_files'); ?>
                      <input type="file" name="photos[]" multiple accept="image/*" class="attachment-input" data-type="photos">
                    </label>
                  </div>
                  <!-- Make sure ID is both lowercase and matches API's Photos type -->
                  <div class="selected-files" id="photos-files" data-type="photos" data-api-type="Photos"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-row">
                    <span class="attachment-header"><?php echo t('attachments.intraoral_scans'); ?></span>
                    <span class="attachment-count" data-attachment-count></span>
                    <label class="file-button" tabindex="0">
                      <?php echo t('attachments.add_files'); ?>
                      <input type="file" name="intraoralScans[]" multiple class="attachment-input" data-type="intraoralScans">
                    </label>
                  </div>
                  <!-- Make sure ID is both lowercase and matches API's IntraoralScans type -->
                  <div class="selected-files" id="intraoralScans-files" data-type="intraoralScans" data-api-type="IntraoralScans"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-row">
                    <span class="attachment-header"><?php echo t('attachments.facial_scans'); ?></span>
                    <span class="attachment-count" data-attachment-count></span>
                    <label class="file-button" tabindex="0">
                      <?php echo t('attachments.add_files'); ?>
                      <input type="file" name="facialScans[]" multiple class="attachment-input" data-type="facialScans">
                    </label>
                  </div>
                  <!-- Make sure ID is both lowercase and matches API's FacialScans type -->
                  <div class="selected-files" id="facialScans-files" data-type="facialScans" data-api-type="FacialScans"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-row">
                    <span class="attachment-header"><?php echo t('attachments.photogrammetry'); ?></span>
                    <span class="attachment-count" data-attachment-count></span>
                    <label class="file-button" tabindex="0">
                      <?php echo t('attachments.add_files'); ?>
                      <input type="file" name="photogrammetry[]" multiple class="attachment-input" data-type="photogrammetry">
                    </label>
                  </div>
                  <!-- Make sure ID is both lowercase and matches API's Photogrammetry type -->
                  <div class="selected-files" id="photogrammetry-files" data-type="photogrammetry" data-api-type="Photogrammetry"></div>
                </div>

                <div class="attachment-group">
                  <div class="attachment-row">
                    <span class="attachment-header"><?php echo t('attachments.completed_designs'); ?></span>
                    <span class="attachment-count" data-attachment-count></span>
                    <label class="file-button" tabindex="0">
                      <?php echo t('attachments.add_files'); ?>
                      <input type="file" name="completedDesigns[]" multiple class="attachment-input" data-type="completedDesigns">
                    </label>
                  </div>
                  <!-- Make sure ID is both lowercase and matches API's CompletedDesigns type -->
                  <div class="selected-files" id="completedDesigns-files" data-type="completedDesigns" data-api-type="CompletedDesigns"></div>
                </div>

                <!-- Legacy stored types have no upload category - display
                     them here only when an existing case carries them.
                     Container ids must match the lookups in
                     displayExistingFiles()/renderExistingAttachments(). -->
                <div class="attachment-group attachment-group-legacy">
                  <div class="attachment-row">
                    <span class="attachment-header"><?php echo t('attachments.radiographs'); ?></span>
                    <span class="attachment-count" data-attachment-count></span>
                  </div>
                  <div class="selected-files" id="radiographs-files" data-type="radiographs" data-api-type="Radiographs"></div>
                </div>
                <div class="attachment-group attachment-group-legacy">
                  <div class="attachment-row">
                    <span class="attachment-header"><?php echo t('attachments.documents'); ?></span>
                    <span class="attachment-count" data-attachment-count></span>
                  </div>
                  <div class="selected-files" id="documents-files" data-type="documents" data-api-type="Documents"></div>
                </div>
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
                    <div><?php echo t('cases.comments.empty'); ?></div>
                    <div style="font-size: 0.75rem; margin-top: 4px;"><?php echo t('cases.comments.mentions_hint'); ?></div>
                  </div>
                </div>
                <div class="case-comment-input-wrapper">
                  <div id="mentionAutocomplete" class="mention-autocomplete"></div>
                  <textarea id="caseCommentInput" class="case-comment-input" placeholder="<?php echo t('cases.comments.placeholder'); ?>" rows="2"></textarea>
                  <div class="case-comment-actions">
                    <span class="case-comment-hint"><?php echo t('cases.comments.hint'); ?></span>
                    <button type="button" id="caseCommentSubmit" class="case-comment-submit" disabled><?php echo t('cases.comments.add_comment'); ?></button>
                  </div>
                </div>
              </div>
            </div>
<?php endif; ?>

<?php if (isFeatureEnabled('SHOW_REVISION_HISTORY')): ?>
            <div id="caseRevisionHistoryPanel" class="case-tab-panel">
              <div id="caseRevisionHistory" class="case-revision-history">
                <p class="revision-empty-state"><?php echo t('cases.history.empty'); ?></p>
              </div>
            </div>
<?php endif; ?>

            <!-- Shared footer - visible on every tab so "Save All Changes" is
                 always reachable when case edits or a comment draft exist. -->
            <div class="modal-footer create-case-footer">
              <button type="button" class="btn-primary" id="createCaseSubmit"><?php echo t('cases.create_case'); ?></button>
              <button type="button" class="btn-cancel" id="createCaseCancel"><?php echo t('common.cancel'); ?></button>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Delete Confirmation Modal -->
      <div id="deleteConfirmModal" class="modal">
        <div class="modal-content delete-confirm-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?php echo t('attachments.delete_title'); ?></h2>
            <button type="button" class="btn-close" id="deleteConfirmClose"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            <div class="delete-confirm-icon">
              <span>🗑️</span>
            </div>
            <p class="delete-confirm-message"><?php echo t('attachments.delete_message'); ?></p>
            <p class="delete-confirm-warning"><?php echo t('attachments.delete_warning'); ?></p>
          </div>

          <div class="modal-footer delete-confirm-footer">
            <button type="button" class="btn-delete" id="deleteConfirmDelete"><?php echo t('attachments.delete_file'); ?></button>
            <button type="button" class="btn-cancel" id="deleteConfirmCancel"><?php echo t('common.cancel'); ?></button>
          </div>
        </div>
      </div>

      <!-- Record Remake Modal -->
      <div id="remakeModal" class="modal">
        <div class="modal-content remake-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?php echo t('remakes.modal_title'); ?></h2>
            <button type="button" class="btn-close" id="remakeModalClose"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            <p class="remake-modal-subtitle"><?php echo t('remakes.modal_subtitle'); ?></p>
            <div id="remakeModalError" class="remake-error" role="alert" hidden></div>

            <div class="form-field">
              <label for="remakeReason"><?php echo t('remakes.reason_label'); ?> <span class="required">*</span></label>
              <select id="remakeReason" name="remakeReason">
                <option value=""><?php echo t('remakes.reason_placeholder'); ?></option>
                <?php foreach (getRemakeReasons() as $remakeCode => $remakeKey): ?>
                <option value="<?php echo $remakeCode; ?>"><?php echo t($remakeKey); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-field">
              <label for="remakeAttribution"><?php echo t('remakes.attribution_label'); ?> <span class="required">*</span></label>
              <select id="remakeAttribution" name="remakeAttribution">
                <option value=""><?php echo t('remakes.attribution_placeholder'); ?></option>
                <?php foreach (getRemakeAttributions() as $remakeCode => $remakeKey): ?>
                <option value="<?php echo $remakeCode; ?>"><?php echo t($remakeKey); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="remake-field-hint"><?php echo t('remakes.attribution_hint'); ?></p>
            </div>

            <div class="form-field">
              <label for="remakeNotes"><?php echo t('remakes.notes_label'); ?></label>
              <textarea id="remakeNotes" name="remakeNotes" rows="3" maxlength="2000"
                        placeholder="<?php echo t('remakes.notes_placeholder'); ?>"></textarea>
              <p id="remakeOtherHint" class="remake-field-hint" hidden><?php echo t('remakes.notes_other_hint'); ?></p>
            </div>

            <div class="remake-history-block">
              <h3 class="remake-history-heading"><?php echo t('remakes.history_heading'); ?></h3>
              <div id="remakeHistoryList" class="remake-history-list">
                <p class="remake-empty"><?php echo t('remakes.empty'); ?></p>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-primary" id="remakeSubmit"><?php echo t('remakes.submit'); ?></button>
            <button type="button" class="btn-cancel" id="remakeCancel"><?php echo t('common.cancel'); ?></button>
          </div>
        </div>
      </div>

      <!-- Regression → Remake Prompt
           Shown after a successful backward workflow move. The move (and its
           regression/revision record) is already saved - this prompt only
           asks whether it was also a true remake. It never blocks or undoes
           the workflow change. -->
      <div id="regressionRemakePrompt" class="modal">
        <div class="modal-content regression-prompt-modal" role="alertdialog"
             aria-labelledby="regressionRemakePromptTitle" aria-describedby="regressionRemakePromptBody">
          <div class="modal-header">
            <h2 class="modal-title" id="regressionRemakePromptTitle"><?php echo t('remakes.regression_prompt_title'); ?></h2>
            <button type="button" class="btn-close" id="regressionRemakePromptClose" aria-label="<?php echo t('common.close'); ?>"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p id="regressionRemakePromptBody" class="regression-prompt-body"><?php echo t('remakes.regression_prompt_body'); ?></p>
            <p id="regressionRemakePromptOpen" class="regression-prompt-open" role="note" hidden><?php echo t('remakes.regression_prompt_open'); ?></p>
          </div>
          <div class="modal-footer regression-prompt-footer">
            <button type="button" class="btn-primary" id="regressionRemakeYes"><?php echo t('remakes.regression_prompt_yes'); ?></button>
            <button type="button" class="btn-cancel" id="regressionRemakeNo"><?php echo t('remakes.regression_prompt_no'); ?></button>
          </div>
        </div>
      </div>

      <!-- Feedback Success Modal -->
      <div id="feedbackSuccessModal" class="modal">
        <div class="modal-content feedback-success-modal">
          <div class="modal-header success-header">
            <h2 class="modal-title"><?php echo t('feedback.thank_you'); ?></h2>
            <button type="button" class="btn-close" id="feedbackSuccessClose"><span>&times;</span></button>
          </div>
          <div class="modal-body success-body">
            <div class="success-icon">
              <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="32" cy="32" r="30" stroke="#4CAF50" stroke-width="4"/>
                <path d="M20 32L28 40L44 24" stroke="#4CAF50" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <p class="success-message"><?php echo t('feedback.received_title'); ?></p>
            <p class="success-details"><?php echo t('feedback.received_message'); ?></p>
            <div class="modal-footer">
              <button type="button" class="btn-success" id="feedbackSuccessOk"><?php echo t('feedback.ok'); ?></button>
            </div>
          </div>
        </div>
      </div>

      <!-- Support Modal -->
      <div id="feedbackModal" class="modal">
        <div class="modal-content contact-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?php echo t('feedback.send'); ?></h2>
            <button type="button" class="btn-close" id="feedbackClose"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <!-- Feedback Form -->
            <div class="feedback-content">
              <form id="feedbackForm">
                <div class="form-field">
                  <label class="feedback-question"><?php echo t('feedback.how_experience'); ?></label>
                  <div class="emoji-container">
                    <label class="emoji-option">
                      <input type="radio" name="feedback_type" value="positive">
                      <div class="emoji-face happy-face">😊</div>
                      <span class="emoji-label"><?php echo t('feedback.positive'); ?></span>
                    </label>
                    <label class="emoji-option">
                      <input type="radio" name="feedback_type" value="neutral">
                      <div class="emoji-face neutral-face">😐</div>
                      <span class="emoji-label"><?php echo t('feedback.neutral'); ?></span>
                    </label>
                    <label class="emoji-option">
                      <input type="radio" name="feedback_type" value="negative">
                      <div class="emoji-face sad-face">😞</div>
                      <span class="emoji-label"><?php echo t('feedback.negative'); ?></span>
                    </label>
                  </div>
                </div>
                <div class="form-field">
                  <label for="feedback_comments"><?php echo t('feedback.title'); ?></label>
                  <textarea id="feedback_comments" name="feedback_comments" rows="4" placeholder="<?php echo t('feedback.placeholder'); ?>"></textarea>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn-primary" id="feedbackSubmit"><?php echo t('feedback.send'); ?></button>
                  <button type="button" class="btn-cancel" id="feedbackCancel"><?php echo t('common.cancel'); ?></button>
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
            <h2 class="modal-title"><?php echo t('archive.title'); ?></h2>
            <button type="button" class="btn-close" id="archivedCasesClose"><span>&times;</span></button>
          </div>

          <!-- Fixed Search Header -->
          <div class="archived-search-header">
            <div class="archived-filters">
              <div class="archived-search">
                <label for="archivedSearch" class="sr-only"><?php echo t('archive.search.placeholder'); ?></label>
                <input type="text" id="archivedSearch" placeholder="<?php echo t('archive.search.placeholder'); ?>">
              </div>
              <div class="archived-filter-controls">
                <label for="archivedDateRange" class="sr-only"><?php echo t('archive.filters.all_dates'); ?></label>
                <select id="archivedDateRange">
                  <option value=""><?php echo t('archive.filters.all_dates'); ?></option>
                  <option value="7"><?php echo t('archive.filters.last_n_days', ['count' => 7]); ?></option>
                  <option value="30"><?php echo t('archive.filters.last_n_days', ['count' => 30]); ?></option>
                  <option value="90"><?php echo t('archive.filters.last_n_days', ['count' => 90]); ?></option>
                  <option value="365"><?php echo t('archive.filters.last_n_days', ['count' => 365]); ?></option>
                </select>
                <label for="archivedCaseType" class="sr-only"><?php echo t('archive.fields.case_type'); ?></label>
                <select id="archivedCaseType">
                  <option value=""><?php echo t('filters.all_types'); ?></option>
                  <?php echo renderCaseTypeOptions(getFilterableCaseTypes()); ?>
                </select>
                <button type="button" class="btn-clear-filters" id="archivedClearFilters"><?php echo t('filters.clear_filters'); ?></button>
              </div>
            </div>
            <div class="archived-count">
              <span id="archivedCount"><?php echo t('common.loading'); ?></span>
            </div>
          </div>

          <div class="modal-body">
            <!-- Table Container -->
            <div class="archived-table-container">
              <table class="archived-cases-table">
                <thead>
                  <tr>
                    <th><?php echo t('archive.fields.patient_name'); ?></th>
                    <th><?php echo t('archive.fields.dentist'); ?></th>
                    <th><?php echo t('archive.fields.case_type'); ?></th>
                    <th><?php echo t('archive.fields.status'); ?></th>
                    <th><?php echo t('archive.fields.created'); ?></th>
                    <th><?php echo t('archive.fields.archived'); ?></th>
                    <th><?php echo t('archive.fields.actions'); ?></th>
                  </tr>
                </thead>
                <tbody id="archivedCasesTableBody">
                  <tr><td colspan="7" class="loading-row"><?php echo t('archive.loading'); ?></td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Fixed Footer -->
          <div class="modal-footer">
            <div class="archived-pagination">
              <div class="pagination-left">
                <label for="archivedPageSize" class="sr-only"><?php echo t('archive.pagination.per_page', ['count' => 25]); ?></label>
                <select id="archivedPageSize">
                  <option value="10"><?php echo t('archive.pagination.per_page', ['count' => 10]); ?></option>
                  <option value="25" selected><?php echo t('archive.pagination.per_page', ['count' => 25]); ?></option>
                  <option value="50"><?php echo t('archive.pagination.per_page', ['count' => 50]); ?></option>
                  <option value="100"><?php echo t('archive.pagination.per_page', ['count' => 100]); ?></option>
                </select>
              </div>
              <div class="pagination-center">
                <button type="button" id="archivedPrevPage"><?php echo t('archive.pagination.previous'); ?></button>
                <span id="archivedPageInfo"><?php echo t('archive.pagination.page_info', ['current' => 1, 'total' => 1]); ?></span>
                <button type="button" id="archivedNextPage"><?php echo t('archive.pagination.next'); ?></button>
              </div>
              <div class="pagination-right">
                <button type="button" class="btn-cancel" id="archivedCasesFooterClose"><?php echo t('common.close'); ?></button>
              </div>
            </div>
          </div>
        </div>
      </div>
      
<?php if (isFeatureEnabled('BILLING_ENABLED')): ?>
      <!-- Billing Portal Modal -->
      <div id="billingPortalModal" class="modal" style="display:none;">
        <div class="modal-content billing-portal-modal">
          <div class="modal-header">
            <h2 class="modal-title">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                <line x1="1" y1="10" x2="23" y2="10"/>
              </svg>
              <?php echo t('billing.messages.billing_modal_title'); ?>
            </h2>
            <button type="button" class="btn-close" id="billingPortalClose"><span>&times;</span></button>
          </div>
          <div class="modal-body" id="billingPortalBody">
            <!-- Content rendered by js/billing-portal.js -->
          </div>
        </div>
      </div>
<?php endif; ?>

      <!-- Rename Assignment Label Modal -->
      <div id="renameAssignmentLabelModal" class="modal">
        <div class="modal-content rename-label-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?php echo t('settings.users.shared_assignment_labels.rename_modal.title'); ?></h2>
            <button type="button" class="btn-close" id="renameAssignmentLabelClose"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <form id="renameAssignmentLabelForm">
              <div class="form-field">
                <label for="renameAssignmentLabelInput"><?php echo t('settings.users.shared_assignment_labels.rename_modal.label_name'); ?></label>
                <input id="renameAssignmentLabelInput" type="text" maxlength="150" autocomplete="off">
                <div class="error-message" id="renameAssignmentLabelError"></div>
              </div>
            </form>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-primary" id="renameAssignmentLabelSave"><?php echo t('common.save'); ?></button>
            <button type="button" class="btn-cancel" id="renameAssignmentLabelCancel"><?php echo t('common.cancel'); ?></button>
          </div>
        </div>
      </div>

      <!-- Settings Modal -->
      <div id="settingsBillingModal" class="modal">
        <div class="modal-content settings-billing-modal">
          <div class="modal-header">
            <h2 class="modal-title"><?php echo t('settings.settings'); ?></h2>
            <button type="button" class="btn-close" id="settingsBillingClose"><span>&times;</span></button>
          </div>

          <div class="modal-body">
            
            <!-- Tab Content -->
            <div class="tab-content-scroll">
              <div class="tab-content">
              <!-- Settings Tab -->
              <div class="tab-pane active" id="settingsTab">
                <form id="settingsForm">

                  <div class="settings-layout">
                    <nav class="settings-nav">
                      <button type="button" class="settings-nav-item active" data-nav-target="practice"><?php echo t('settings.navigation.practice'); ?></button>
                      <button type="button" class="settings-nav-item" data-nav-target="display"><?php echo t('settings.navigation.display'); ?></button>
                      <button type="button" class="settings-nav-item" data-nav-target="authorized"><?php echo t('settings.navigation.users'); ?></button>
                      <button type="button" class="settings-nav-item" data-nav-target="security"><?php echo t('settings.navigation.security'); ?></button>
                      <button type="button" class="settings-nav-item" data-nav-target="data-privacy"><?php echo t('settings.navigation.data_privacy'); ?></button>
                      <?php if (isFeatureEnabled('SHOW_PMS_INTEGRATIONS')): ?>
                      <button type="button" class="settings-nav-item" data-nav-target="integrations"><?php echo t('settings.navigation.integrations'); ?></button>
                      <?php endif; ?>
                    </nav>
                    <div class="settings-panels">

                  <div class="settings-twisty settings-panel-active" data-twisty-id="practice">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title"><?php echo t('settings.practice.title'); ?></span>
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
                          <label><?php echo t('settings.practice.legal_name'); ?></label>
                          <div class="inline-value-group">
                            <span class="legal-name-display"><?= htmlspecialchars($legalNameDisplay) ?></span>
                            <span class="field-note-inline"><?php echo t('settings.practice.legal_name_note'); ?></span>
                          </div>
                        </div>
                        
                        <!-- Display Name (Editable) -->
                        <div class="option-row option-row-inline">
                          <label for="displayName"><?php echo t('settings.practice.display_name'); ?></label>
                          <div class="inline-value-group">
                            <input type="text" id="displayName" name="displayName" value="<?= htmlspecialchars($displayNameValue) ?>" <?= $isAdmin ? '' : 'disabled' ?>>
                            <span class="field-note-inline"><?php echo t('settings.practice.display_name_note'); ?></span>
                          </div>
                        </div>

                        <?php if ($showLanguageControls): ?>
                        <!-- Practice Default Language (admin-only) -->
                        <div class="option-row option-row-inline">
                          <label for="practiceDefaultLanguage"><?php echo t('settings.practice.language.label'); ?></label>
                          <div class="inline-value-group">
                            <select id="practiceDefaultLanguage" name="practiceDefaultLanguage" class="settings-language-select" <?= $isAdmin ? '' : 'disabled' ?>>
                              <?php foreach ($supportedLanguages as $lang): ?>
                              <option value="<?php echo htmlspecialchars($lang['value']); ?>" <?php echo ($practiceDefaultLocale === $lang['value']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['label']); ?></option>
                              <?php endforeach; ?>
                            </select>
                            <span class="field-note-inline"><?php echo t('settings.practice.language.description'); ?></span>
                          </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Practice Logo (moved here, under Display Name) -->
                        <div class="option-row option-row-inline option-row-logo">
                          <label for="practiceLogo"><?php echo t('settings.practice.logo'); ?></label>
                          <div class="logo-upload-container">
                            <div class="current-logo" id="currentLogo" style="display: none;">
                              <img id="currentLogoImg" src="" alt="<?php echo t('settings.practice.logo_alt'); ?>" class="logo-preview">
                              <button type="button" id="deleteLogo" class="btn-delete-logo" <?= $isAdmin ? '' : 'disabled' ?>><?php echo t('settings.practice.logo_remove'); ?></button>
                            </div>
                            <div class="logo-upload" id="logoUpload">
                              <input type="file" id="practiceLogo" name="practiceLogo" accept="image/*" class="logo-input" <?= $isAdmin ? '' : 'disabled' ?>>
                              <label for="practiceLogo" class="logo-upload-label <?= $isAdmin ? '' : 'disabled' ?>">
                                <span class="upload-icon">📁</span>
                                <span class="upload-text"><?php echo t('settings.practice.logo_choose'); ?></span>
                              </label>
                              <div class="logo-upload-info"><?php echo t('settings.practice.logo_info'); ?></div>
                            </div>
                          </div>
                        </div>
                        
                        <div class="settings-divider"></div>
                        
                        <!-- BAA Status Section -->
                        <div class="baa-status-section">
                          <h4 class="subsection-title"><?php echo t('settings.practice.baa.title'); ?></h4>
                          
                          <div class="baa-info-grid">
                            <div class="baa-info-item">
                              <span class="baa-info-label"><?php echo t('settings.practice.baa.status'); ?></span>
                              <span class="baa-status-badge <?= $baaAcceptedDisplay ? 'accepted' : 'pending' ?>">
                                <?= $baaAcceptedDisplay ? t('settings.practice.baa.accepted_badge') : t('settings.practice.baa.pending_badge') ?>
                              </span>
                            </div>
                            
                            <?php if ($baaAcceptedDisplay && $baaAcceptedAtDisplay): ?>
                            <div class="baa-info-item">
                              <span class="baa-info-label"><?php echo t('settings.practice.baa.accepted'); ?></span>
                              <span class="baa-info-value"><?= htmlspecialchars(date('M j, Y, g:i A', strtotime($baaAcceptedAtDisplay))) ?></span>
                            </div>
                            
                            <div class="baa-info-item">
                              <span class="baa-info-label"><?php echo t('settings.practice.baa.version'); ?></span>
                              <span class="baa-info-value"><?= htmlspecialchars($baaVersionDisplay) ?></span>
                            </div>
                          </div>
                          
                          <div class="baa-download-row">
                            <a href="api/download-baa.php" class="btn-download-baa-small" download>
                              📄 <?php echo t('settings.practice.baa.download'); ?>
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
                      <span class="settings-twisty-title"><?php echo t('settings.display.title'); ?></span>
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

                        <div class="settings-divider"></div>

                        <div class="option-row">
                          <label for="allowCardDelete"><?php echo t('settings.display.allow_card_delete'); ?></label>
                          <input type="checkbox" id="allowCardDelete" name="allowCardDelete" <?= $isAdmin ? '' : 'disabled' ?>>
                        </div>

                        <div class="option-row">
                          <label for="deliveredHideDays"><?php echo t('settings.display.auto_archive.label'); ?></label>
                          <input type="number" id="deliveredHideDays" name="deliveredHideDays" min="0" max="365" value="120" class="number-input" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span class="option-text"><?php echo t('settings.display.auto_archive.unit'); ?></span>
                        </div>
                      </div>

                      <div class="settings-divider"></div>

                      <div class="settings-group">
                        <div class="option-row option-row-case-review">
                          <label for="caseReviewTrackingEnabled"><?php echo t('settings.display.case_review.label'); ?></label>
                          <input type="checkbox" id="caseReviewTrackingEnabled" name="caseReviewTrackingEnabled" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span class="option-text"><?php echo t('settings.display.case_review.enable'); ?></span>
                        </div>
                      </div>
                      
                      <div class="settings-divider"></div>
                      
                      <div class="settings-group">
                        <div class="option-row highlight-past-due-row">
                          <label for="highlightPastDue"><?php echo t('settings.display.past_due.label'); ?></label>
                          <input type="checkbox" id="highlightPastDue" name="highlightPastDue" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span id="pastDueSettings" class="past-due-inline-settings hidden">
                            <label for="pastDueDays" class="settings-sublabel"><?php echo t('settings.display.past_due.days_label'); ?></label>
                            <input type="number" id="pastDueDays" name="pastDueDays" min="1" max="99" value="1" class="number-input" <?= $isAdmin ? '' : 'disabled' ?>>
                          </span>
                        </div>

                        <div class="option-row highlight-past-due-row">
                          <label for="highlightComingDue"><?php echo t('settings.display.coming_due.label'); ?></label>
                          <input type="checkbox" id="highlightComingDue" name="highlightComingDue" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span id="comingDueSettings" class="past-due-inline-settings hidden">
                            <label for="comingDueDays" class="settings-sublabel"><?php echo t('settings.display.coming_due.days_label'); ?></label>
                            <input type="number" id="comingDueDays" name="comingDueDays" min="1" max="99" value="5" class="number-input" <?= $isAdmin ? '' : 'disabled' ?>>
                          </span>
                        </div>

                        <div class="option-row highlight-past-due-row">
                          <label for="highlightAppointmentRisk"><?php echo t('settings.display.appointment_risk.label'); ?></label>
                          <input type="checkbox" id="highlightAppointmentRisk" name="highlightAppointmentRisk" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span id="appointmentRiskSettings" class="past-due-inline-settings hidden">
                            <label for="appointmentRiskDays" class="settings-sublabel"><?php echo t('settings.display.appointment_risk.days_label'); ?></label>
                            <input type="number" id="appointmentRiskDays" name="appointmentRiskDays" min="0" max="99" value="3" class="number-input" <?= $isAdmin ? '' : 'disabled' ?>>
                          </span>
                        </div>
                        
                        <?php if (isFeatureEnabled('SHOW_GOOGLE_DRIVE_BACKUP')): ?>
                        <div class="option-row" id="googleDriveBackupRow">
                          <label for="googleDriveBackup"><?php echo t('settings.display.google_drive.label'); ?></label>
                          <input type="checkbox" id="googleDriveBackup" name="googleDriveBackup" <?= $isAdmin ? '' : 'disabled' ?>>
                          <span id="googleDriveBackupNote" class="field-note" style="display: block; margin-top: 4px; margin-left: 8px; font-size: 12px; color: #666;"><?php echo t('settings.display.google_drive.note'); ?></span>
                          <span id="googleDriveWorkspaceWarning" class="field-note" style="display: none; margin-top: 4px; margin-left: 8px; font-size: 12px; color: #d97706;">⚠️ <?php echo t('settings.display.google_drive.warning'); ?></span>
                        </div>
                        <?php endif; ?>
                      </div>

                      <div class="settings-divider"></div>

                      <?php
                        $workflowColumnMissing = !$pdo || !ensureWorkflowColumnsColumn();
                        if ($isAdmin && $workflowColumnMissing):
                      ?>
                      <div class="error-message" style="display:block; margin-bottom:12px;">
                        <?php echo t('settings.workflow_columns.migration_required'); ?>
                      </div>
                      <?php endif; ?>
                      <div class="settings-group workflow-columns-group" id="workflowColumnsManager">
                        <div class="workflow-columns-header">
                          <h4 class="subsection-title"><?php echo t('settings.workflow_columns.title'); ?></h4>
                          <p class="field-note-inline" id="workflowColumnsCount" data-count="<?= (int)$workflowColumnCount ?>" data-max="<?= (int)$workflowMaxColumns ?>"><?php echo t('settings.workflow_columns.count', ['count' => $workflowColumnCount, 'max' => $workflowMaxColumns]); ?></p>
                        </div>
                        <p class="field-note-inline workflow-columns-description"><?php echo t('settings.workflow_columns.description'); ?></p>

                        <div id="workflowColumnsConfirmation" class="workflow-columns-confirmation" hidden></div>

                        <div class="workflow-columns-list" id="workflowColumnsList" role="list" aria-label="<?php echo t('settings.workflow_columns.title'); ?>">
                          <?php
                            $activeCount = count($workflowColumnsActive);
                            foreach ($workflowColumnsActive as $index => $column):
                              $isFirst = ($index === 0);
                              $isLast = ($index === $activeCount - 1);
                              $canMoveUp = $isAdmin && ($index > 1);
                              $canMoveDown = $isAdmin && ($index < $activeCount - 2);
                              $isCustom = (strpos($column['id'], 'Custom-') === 0);
                          ?>
                          <div class="workflow-column-row<?= $isCustom ? ' workflow-column-row-custom' : '' ?>" role="listitem" data-column-id="<?= htmlspecialchars($column['id']) ?>" data-is-first="<?= $isFirst ? '1' : '0' ?>" data-is-last="<?= $isLast ? '1' : '0' ?>">
                            <span class="workflow-column-position"><?= $index + 1 ?></span>
                            <label for="workflowColumnLabel<?= $index ?>" class="sr-only"><?= t('settings.workflow_columns.rename') ?></label>
                            <input type="text" id="workflowColumnLabel<?= $index ?>" class="workflow-column-label-input" value="<?= htmlspecialchars($resolvedWorkflowStageLabels[$column['id']] ?? $column['label']) ?>" maxlength="40" data-internal-id="<?= htmlspecialchars($column['id']) ?>" <?= $isAdmin ? '' : 'disabled' ?>>
                            <?php if ($isAdmin && !$isFirst && !$isLast): ?>
                            <div class="workflow-column-controls" data-column-id="<?= htmlspecialchars($column['id']) ?>">
                              <?php if ($workflowColumnMissing): ?>
                              <span class="field-note-inline"><?= t('settings.workflow_columns.migration_required_short') ?></span>
                              <?php else: ?>
                              <?php if ($canMoveUp): ?>
                              <button type="button" class="workflow-column-move-up" data-action="move-up" aria-label="<?= t('settings.workflow_columns.aria_reorder_up') ?>">&#8593; <?= t('settings.workflow_columns.reorder_up') ?></button>
                              <?php endif; ?>
                              <?php if ($canMoveDown): ?>
                              <button type="button" class="workflow-column-move-down" data-action="move-down" aria-label="<?= t('settings.workflow_columns.aria_reorder_down') ?>">&#8595; <?= t('settings.workflow_columns.reorder_down') ?></button>
                              <?php endif; ?>
                              <?php if (!$isFirst && !$isLast): ?>
                              <button type="button" class="workflow-column-archive" data-action="archive" aria-label="<?= t('settings.workflow_columns.aria_archive_confirm') ?>"><?= t('settings.workflow_columns.archive') ?></button>
                              <?php endif; ?>
                              <?php endif; ?>
                            </div>
                            <?php elseif ($isFirst || $isLast): ?>
                            <span class="workflow-column-protected-note" title="<?= t('settings.workflow_columns.cannot_archive_protected') ?>"><?= t('settings.workflow_columns.required') ?></span>
                            <?php endif; ?>
                          </div>
                          <?php endforeach; ?>
                        </div>

                        <?php if ($isAdmin): ?>
                        <div class="workflow-columns-actions">
                          <button type="button" id="addWorkflowColumnBtn" class="btn-secondary"<?= ($workflowColumnCount >= $workflowMaxColumns || $workflowColumnMissing) ? ' disabled' : '' ?>><?= t('settings.workflow_columns.add') ?></button>
                          <button type="button" id="resetWorkflowColumnsBtn" class="btn-outline-warning"<?= $workflowColumnMissing ? ' disabled' : '' ?>><?= t('settings.workflow_columns.reset_to_default') ?></button>
                          <span id="addWorkflowColumnNote" class="field-note-inline"<?= ($workflowColumnCount >= $workflowMaxColumns && !$workflowColumnMissing) ? '' : ' style="display:none;"' ?>>
                            <?= $workflowColumnMissing ? t('settings.workflow_columns.migration_required_short') : t('settings.workflow_columns.add_disabled_max') ?>
                          </span>
                        </div>

                        <div id="addWorkflowColumnForm" style="display:none; margin-top:10px;">
                          <input type="text" id="newWorkflowColumnName" maxlength="40" placeholder="<?= t('settings.workflow_columns.placeholder') ?>">
                          <button type="button" id="saveNewWorkflowColumnBtn" class="btn-primary"><?= t('common.save') ?></button>
                          <button type="button" id="cancelNewWorkflowColumnBtn" class="btn-cancel"><?= t('common.cancel') ?></button>
                        </div>

                        <button type="button" id="workflowArchivedListToggle" class="workflow-archived-toggle" aria-expanded="false" aria-controls="workflowArchivedList">
                          <span class="workflow-archived-chevron" aria-hidden="true">▶</span>
                          <span id="workflowArchivedCount" class="workflow-archived-count"><?= t('settings.workflow_columns.archived_heading', ['count' => 0]) ?></span>
                        </button>
                        <div class="workflow-archived-list" id="workflowArchivedList" role="list" aria-label="<?= t('settings.workflow_columns.archived_heading', ['count' => 0]) ?>" hidden>
                          <p class="workflow-archived-empty" id="workflowArchivedEmpty" hidden><?= t('settings.workflow_columns.no_archived') ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="error-message" id="workflowColumnsError"></div>
                        <div class="success-message" id="workflowColumnsSuccess" style="display:none;"></div>
                      </div>
                      
                      <?php if (!$isAdmin): ?>
                      <p class="admin-only-note" style="font-size:0.8rem;color:#6b7280;margin-top:8px;font-style:italic;"><?php echo t('settings.messages.admin_only_note'); ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                  
                  <div class="settings-divider"></div>

                  <div class="settings-twisty" data-twisty-id="authorized">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title"><?php echo t('settings.users.title'); ?></span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        <h4 class="subsection-title"><?php echo t('settings.users.practice_users.title'); ?></h4>
                        <div class="gmail-users-container">
                          <div class="add-gmail-user">
                            <div class="gmail-input-row">
                              <input type="email" id="newGmailUser" placeholder="<?php echo t('settings.users.practice_users.email_placeholder'); ?>" class="gmail-input">
                              <button type="button" id="addGmailUser" class="add-gmail-btn"><?php echo t('settings.users.practice_users.add_user'); ?></button>
                            </div>
                            <div id="gmailError" class="error-message"></div>
                          </div>

                          <div id="gmailUsersList" data-show-lab-insights="<?= isFeatureEnabled('SHOW_LAB_INSIGHTS') ? '1' : '0' ?>">
                            <!-- Practice users grid will be rendered here -->
                          </div>
                        </div>

                        <div class="settings-divider"></div>

                        <!-- Assignment Labels Section -->
                        <div class="assignment-labels-section">
                          <h4 class="subsection-title"><?php echo t('settings.users.shared_assignment_labels.title'); ?></h4>
                          <p class="field-note-inline"><?php echo t('settings.users.shared_assignment_labels.description'); ?></p>
                          
                          <div class="gmail-users-container assignment-labels-container">
                            <?php if ($isAdmin): ?>
                            <div class="add-gmail-user">
                              <div class="gmail-input-row">
                                <input type="text" id="newAssignmentLabel" placeholder="<?php echo t('settings.users.shared_assignment_labels.placeholder'); ?>" class="gmail-input" maxlength="150">
                                <?php if (isFeatureEnabled('SHOW_LAB_INSIGHTS')): ?>
                                <label class="assignment-label-lab-checkbox" for="newAssignmentLabelIsLab" title="<?php echo t('settings.users.practice_users.lab_tooltip'); ?>">
                                  <input type="checkbox" id="newAssignmentLabelIsLab">
                                  <?php echo t('settings.users.shared_assignment_labels.lab_checkbox'); ?>
                                </label>
                                <?php endif; ?>
                                <button type="button" id="addAssignmentLabel" class="add-gmail-btn"><?php echo t('settings.users.shared_assignment_labels.add'); ?></button>
                              </div>
                              <div class="error-message" id="assignmentLabelError"></div>
                            </div>
                            <?php endif; ?>
                            
                            <p class="field-note-inline assignment-labels-hint"><?php echo t('settings.users.shared_assignment_labels.hint'); ?></p>
                            
                            <div id="assignmentLabelsList" class="assignment-labels-list" data-show-lab-insights="<?= isFeatureEnabled('SHOW_LAB_INSIGHTS') ? '1' : '0' ?>">
                              <!-- Assignment labels will be rendered here -->
                            </div>
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
                      <span class="settings-twisty-title"><?php echo t('settings.security.title'); ?></span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        
                        <!-- Change Password Section - Only shown for users with password-based login -->
                        <?php if ($userHasPassword): ?>
                        <div class="security-section">
                          <h4 class="subsection-title"><?php echo t('settings.security.change_password.title'); ?></h4>
                          <div id="changePasswordForm" class="security-form">
                            <div class="form-field">
                              <label for="currentPassword"><?php echo t('settings.security.change_password.current_password'); ?> <span class="required"><?php echo t('settings.security.change_password.required'); ?></span></label>
                              <div class="password-input-wrapper">
                                <input type="password" id="currentPassword" name="currentPassword" autocomplete="off" required>
                                <button type="button" class="password-toggle-btn" aria-label="<?php echo t('settings.security.change_password.show_password'); ?>" data-target="currentPassword">
                                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                              </div>
                            </div>
                            <div class="form-field">
                              <label for="newPassword"><?php echo t('settings.security.change_password.new_password'); ?></label>
                              <div class="password-input-wrapper">
                                <input type="password" id="newPassword" name="newPassword" autocomplete="new-password">
                                <button type="button" class="password-toggle-btn" aria-label="<?php echo t('settings.security.change_password.show_password'); ?>" data-target="newPassword">
                                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                              </div>
                              <div class="password-requirements" id="newPasswordReqs">
                                <span class="req" id="pwReqLength">✗ <?php echo t('settings.security.change_password.requirements.length'); ?></span>
                                <span class="req" id="pwReqUpper">✗ <?php echo t('settings.security.change_password.requirements.upper'); ?></span>
                                <span class="req" id="pwReqNumber">✗ <?php echo t('settings.security.change_password.requirements.number'); ?></span>
                                <span class="req" id="pwReqSpecial">✗ <?php echo t('settings.security.change_password.requirements.special'); ?></span>
                              </div>
                            </div>
                            <div class="form-field">
                              <label for="confirmNewPassword"><?php echo t('settings.security.change_password.confirm_password'); ?></label>
                              <div class="password-input-wrapper">
                                <input type="password" id="confirmNewPassword" name="confirmNewPassword" autocomplete="new-password">
                                <button type="button" class="password-toggle-btn" aria-label="<?php echo t('settings.security.change_password.show_password'); ?>" data-target="confirmNewPassword">
                                  <svg class="icon-show" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                  <svg class="icon-hide" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                              </div>
                              <div id="passwordMatchStatus" class="password-match"></div>
                            </div>
                            <div id="changePasswordError" class="form-error" style="display: none;"></div>
                            <div id="changePasswordSuccess" class="form-success" style="display: none;"></div>
                            <button type="button" id="changePasswordBtn" class="btn-secondary"><?php echo t('settings.security.change_password.button'); ?></button>
                          </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Two-Factor Authentication Section -->
                        <div class="security-section">
                          <h4 class="subsection-title"><?php echo t('settings.security.two_factor.title'); ?></h4>
                          <div id="twoFactorSection">
                            <div id="twoFactorStatus" class="two-factor-status">
                              <span class="status-badge status-disabled"><?php echo t('settings.security.two_factor.status_disabled'); ?></span>
                              <p class="status-description"><?php echo t('settings.security.two_factor.description'); ?></p>
                            </div>
                            
                            <!-- Setup Flow (hidden by default) -->
                            <div id="twoFactorSetup" class="two-factor-setup" style="display: none;">
                              <div class="setup-step">
                                <p><?php echo t('settings.security.two_factor.setup.step1'); ?></p>
                                <div id="twoFactorQRCode" class="qr-code-container"></div>
                                <p class="manual-entry"><?php echo t('settings.security.two_factor.setup.manual_entry'); ?> <code id="twoFactorSecret"></code></p>
                              </div>
                              <div class="setup-step">
                                <p><?php echo t('settings.security.two_factor.setup.step2'); ?></p>
                                <div class="verification-input-group">
                                  <input type="text" id="twoFactorVerifyCode" maxlength="6" pattern="[0-9]*" inputmode="numeric" placeholder="<?php echo t('settings.security.two_factor.setup.placeholder'); ?>" autocomplete="one-time-code">
                                  <button type="button" id="verifyTwoFactorBtn" class="btn-primary"><?php echo t('settings.security.two_factor.setup.verify'); ?></button>
                                </div>
                                <div id="twoFactorSetupError" class="form-error" style="display: none;"></div>
                              </div>
                              <button type="button" id="cancelTwoFactorSetup" class="btn-link"><?php echo t('common.cancel'); ?></button>
                            </div>
                            
                            <!-- Disable Flow (hidden by default) -->
                            <div id="twoFactorDisable" class="two-factor-disable" style="display: none;">
                              <p><?php echo t('settings.security.two_factor.disable.confirm_message'); ?></p>
                              <div class="disable-actions">
                                <button type="button" id="confirmDisableTwoFactor" class="btn-danger"><?php echo t('settings.security.two_factor.disable.button'); ?></button>
                                <button type="button" id="cancelDisableTwoFactor" class="btn-link"><?php echo t('common.cancel'); ?></button>
                              </div>
                              <div id="twoFactorDisableError" class="form-error" style="display: none;"></div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div id="twoFactorActions" class="two-factor-actions">
                              <button type="button" id="enableTwoFactorBtn" class="btn-secondary"><?php echo t('settings.security.two_factor.enable'); ?></button>
                              <button type="button" id="disableTwoFactorBtn" class="btn-outline-danger" style="display: none;"><?php echo t('settings.security.two_factor.disable.button'); ?></button>
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
                      <span class="settings-twisty-title"><?php echo t('settings.data_privacy.title'); ?></span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        <div class="data-export-section">
                          <h4 class="subsection-title"><?php echo t('settings.data_privacy.export.title'); ?></h4>
                          <p class="section-description"><?php echo t('settings.data_privacy.export.description'); ?></p>
                          <div id="exportStatus" class="export-status" style="display: none;"></div>
                          <button type="button" id="exportDataBtn" class="btn-secondary">
                            <span class="btn-icon">📥</span> <?php echo t('settings.data_privacy.export.button'); ?>
                          </button>
                          <p class="export-note"><?php echo t('settings.data_privacy.export.note'); ?></p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <?php if (isFeatureEnabled('SHOW_PMS_INTEGRATIONS')): ?>
                  <?php
                  // Provider cards render from this registry so additional PMS
                  // providers slot in without restructuring the panel. The JS
                  // in app.js keys off data-provider and fills connection
                  // state from api/integrations.php.
                  $integrationProviders = [
                      'open_dental' => [
                          'name_key' => 'settings.integrations.providers.open_dental.name',
                          'desc_key' => 'settings.integrations.providers.open_dental.description',
                      ],
                  ];
                  ?>
                  <div class="settings-twisty" data-twisty-id="integrations">
                    <button type="button" class="settings-twisty-header">
                      <span class="settings-twisty-arrow"></span>
                      <span class="settings-twisty-title"><?php echo t('settings.integrations.title'); ?></span>
                    </button>
                    <div class="settings-twisty-content">
                      <div class="settings-group">
                        <p class="section-description"><?php echo t('settings.integrations.description'); ?></p>
                        <div id="integrationsPanelError" class="error-message" style="display:none;"></div>
                        <div class="integrations-list">
                          <?php foreach ($integrationProviders as $providerKey => $providerDef): ?>
                          <?php
                          // Plan lock: Operate (and any non-Control-tier plan)
                          // sees a descriptive locked card instead of action
                          // buttons. $userHasControlAccess is the same
                          // entitlement Lab Insights uses; null means the check
                          // could not run, so the normal card renders and the
                          // API still enforces the restriction.
                          $providerLocked = ($userHasControlAccess === false);
                          ?>
                          <div class="integration-card<?php echo $providerLocked ? ' integration-card-locked' : ''; ?>" data-provider="<?php echo htmlspecialchars($providerKey); ?>"<?php echo $providerLocked ? ' data-locked="true"' : ''; ?> id="integrationCard-<?php echo htmlspecialchars($providerKey); ?>">
                            <div class="integration-card-top">
                              <span class="integration-provider-name"><?php echo t($providerDef['name_key']); ?></span>
                              <span class="integration-status-badge<?php echo $providerLocked ? ' integration-status-locked' : ''; ?>" id="integrationStatusBadge-<?php echo htmlspecialchars($providerKey); ?>"><?php echo t($providerLocked ? 'settings.integrations.status.not_on_plan' : 'settings.integrations.status.not_connected'); ?></span>
                            </div>
                            <p class="integration-card-description"><?php echo t($providerDef['desc_key']); ?></p>
                            <?php if ($providerLocked): ?>
                            <p class="integration-locked-note"><?php echo t('settings.integrations.locked_note'); ?></p>
                            <?php endif; ?>
                            <div class="integration-card-meta" id="integrationMeta-<?php echo htmlspecialchars($providerKey); ?>"></div>
                            <?php if (!$providerLocked): ?>
                            <div class="integration-import" id="integrationImport-<?php echo htmlspecialchars($providerKey); ?>" style="display:none;">
                              <div class="integration-import-controls">
                                <select class="integration-import-scope" id="integrationImportScope-<?php echo htmlspecialchars($providerKey); ?>" aria-label="<?php echo htmlspecialchars(t('settings.integrations.import.scope_aria')); ?>">
                                  <option value="30"><?php echo t('settings.integrations.import.scope_30'); ?></option>
                                  <option value="90" selected><?php echo t('settings.integrations.import.scope_90'); ?></option>
                                  <option value="180"><?php echo t('settings.integrations.import.scope_180'); ?></option>
                                  <option value="365"><?php echo t('settings.integrations.import.scope_365'); ?></option>
                                </select>
                                <button type="button" class="btn-secondary integration-action-import" data-provider="<?php echo htmlspecialchars($providerKey); ?>"><?php echo t('settings.integrations.import.button'); ?></button>
                              </div>
                            </div>
                            <div class="integration-card-actions">
                              <button type="button" class="btn-secondary integration-action-connect" data-provider="<?php echo htmlspecialchars($providerKey); ?>"><?php echo t('settings.integrations.connect'); ?></button>
                              <button type="button" class="btn-secondary integration-action-configure" data-provider="<?php echo htmlspecialchars($providerKey); ?>" style="display:none;"><?php echo t('settings.integrations.configure'); ?></button>
                              <button type="button" class="btn-secondary integration-action-test" data-provider="<?php echo htmlspecialchars($providerKey); ?>" style="display:none;"><?php echo t('settings.integrations.test_connection'); ?></button>
                              <button type="button" class="btn-secondary integration-action-subscribe" data-provider="<?php echo htmlspecialchars($providerKey); ?>" style="display:none;"><?php echo t('settings.integrations.subscription.enable'); ?></button>
                              <button type="button" class="btn-secondary integration-action-unsubscribe" data-provider="<?php echo htmlspecialchars($providerKey); ?>" style="display:none;"><?php echo t('settings.integrations.subscription.disable'); ?></button>
                              <button type="button" class="btn-secondary integration-action-reenable" data-provider="<?php echo htmlspecialchars($providerKey); ?>" style="display:none;"><?php echo t('settings.integrations.reenable'); ?></button>
                              <button type="button" class="btn-delete-confirm integration-action-disconnect" data-provider="<?php echo htmlspecialchars($providerKey); ?>" style="display:none;"><?php echo t('settings.integrations.disconnect'); ?></button>
                            </div>
                            <?php endif; ?>
                          </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php endif; ?>

                    </div>
                  </div>

                  <div class="button-container">
                    <button type="button" class="save-settings-btn" id="saveSettings"><?php echo t('settings.common.save_settings'); ?></button>
                    <button type="button" class="btn-cancel" id="settingsCancel"><?php echo t('common.cancel'); ?></button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- Integration Configuration Modal (PMS credentials) -->
      <?php if (isFeatureEnabled('SHOW_PMS_INTEGRATIONS')): ?>
      <div id="integrationConfigModal" class="modal">
        <div class="modal-content integration-config-modal">
          <div class="modal-header">
            <h2 class="modal-title" id="integrationConfigTitle"></h2>
            <button type="button" class="btn-close" id="integrationConfigClose"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p id="integrationConfigDescription" class="section-description"></p>
            <div id="integrationCredentialFields"></div>

            <!-- Guided Open Dental onboarding (key generation + install steps) -->
            <div id="integrationGuidedSetup" style="display:none;">
              <ol class="integration-setup-steps">
                <li class="integration-setup-step">
                  <div class="integration-step-title"><?php echo t('settings.integrations.setup.step1_title'); ?></div>
                  <p class="integration-step-desc"><?php echo t('settings.integrations.setup.step1_desc'); ?></p>
                  <div id="integrationKeyGenerateWrap">
                    <button type="button" class="btn-primary" id="integrationGenerateKey"><?php echo t('settings.integrations.setup.generate_key'); ?></button>
                  </div>
                  <div id="integrationKeyReady" style="display:none;">
                    <label class="integration-key-label" for="integrationKeyValue"><?php echo t('settings.integrations.setup.key_label'); ?></label>
                    <div class="integration-key-row">
                      <code id="integrationKeyValue" class="integration-key-value"></code>
                      <button type="button" class="btn-secondary" id="integrationKeyCopy"><?php echo t('settings.integrations.setup.copy_key'); ?></button>
                    </div>
                    <p class="field-note"><?php echo t('settings.integrations.setup.key_once_note'); ?></p>
                  </div>
                  <div id="integrationKeyExisting" style="display:none;">
                    <p class="configured-hint"><?php echo t('settings.integrations.setup.key_configured'); ?></p>
                    <button type="button" class="btn-secondary" id="integrationRegenerateKey"><?php echo t('settings.integrations.setup.regenerate_key'); ?></button>
                  </div>
                </li>
                <li class="integration-setup-step">
                  <div class="integration-step-title"><?php echo t('settings.integrations.setup.step2_title'); ?></div>
                  <p class="integration-step-desc"><?php echo t('settings.integrations.setup.step2_desc'); ?></p>
                  <ol class="integration-substeps">
                    <li><?php echo t('settings.integrations.setup.step2_a'); ?></li>
                    <li><?php echo t('settings.integrations.setup.step2_b'); ?></li>
                    <li><?php echo t('settings.integrations.setup.step2_c'); ?></li>
                  </ol>
                </li>
                <li class="integration-setup-step">
                  <div class="integration-step-title"><?php echo t('settings.integrations.setup.step3_title'); ?></div>
                  <p class="integration-step-desc"><?php echo t('settings.integrations.setup.step3_desc'); ?></p>
                  <p class="integration-step-desc">
                    <a href="https://www.opendental.com/manual/econnector.html" target="_blank" rel="noopener noreferrer"><?php echo t('settings.integrations.setup.econnector_link'); ?></a>
                  </p>
                </li>
                <li class="integration-setup-step">
                  <div class="integration-step-title"><?php echo t('settings.integrations.setup.step4_title'); ?></div>
                  <p class="integration-step-desc"><?php echo t('settings.integrations.setup.step4_desc'); ?></p>
                  <button type="button" class="btn-primary" id="integrationModalTest"><?php echo t('settings.integrations.test_connection'); ?></button>
                  <div id="integrationTestGuidance" class="integration-test-guidance" style="display:none;"></div>
                </li>
              </ol>
            </div>

            <div class="error-message" id="integrationConfigError"></div>
            <p class="field-note" id="integrationCredentialsNote"><?php echo t('settings.integrations.modal.credentials_note'); ?></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-primary" id="integrationConfigSave"><?php echo t('common.save'); ?></button>
            <button type="button" class="btn-cancel" id="integrationConfigCancel"><?php echo t('common.cancel'); ?></button>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="main-copyright">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appConfig['appName']); ?>. All rights reserved.</div>
    </main>
  </div>
  
  <!-- Google Drive Backup Confirmation Modal -->
  <?php if (isFeatureEnabled('SHOW_GOOGLE_DRIVE_BACKUP')): ?>
  <div id="googleDriveBackupModal" class="delete-confirm-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:999999; align-items:center; justify-content:center;">
    <div class="delete-confirm-content" style="background:#fff; padding:20px; border-radius:8px; max-width:500px; width:90%; margin:20px; max-height:90vh; overflow-y:auto; box-shadow:0 5px 15px rgba(0,0,0,0.3); border:3px solid #2196f3;">
      <div class="delete-confirm-header">
        <i class="delete-icon" style="background: #2196f3;">☁</i>
        <h3><?php echo t('settings.display.google_drive.modal_title'); ?></h3>
      </div>
      <div class="delete-confirm-body">
        <p style="margin-bottom: 12px; padding: 10px; background: #e3f2fd; border-radius: 4px; font-size: 13px;">
          <strong>📁 <?php echo t('settings.display.google_drive.modal_centralized_backup_label'); ?>:</strong> <?php echo t('settings.display.google_drive.modal_centralized_backup_text'); ?>
        </p>
        <p><?php echo t('settings.display.google_drive.modal_features_title'); ?></p>
        <ul>
          <li><?php echo t('settings.display.google_drive.modal_feature_1'); ?></li>
          <li><?php echo t('settings.display.google_drive.modal_feature_2'); ?></li>
          <li><?php echo t('settings.display.google_drive.modal_feature_3'); ?></li>
          <li><?php echo t('settings.display.google_drive.modal_feature_4'); ?></li>
        </ul>
        <p style="margin-top: 12px;"><strong><?php echo t('settings.display.google_drive.modal_requirements_title'); ?></strong></p>
        <ul>
          <li><?php echo t('settings.display.google_drive.modal_requirement_1'); ?></li>
          <li><?php echo t('settings.display.google_drive.modal_requirement_2'); ?></li>
          <li><?php echo t('settings.display.google_drive.modal_requirement_3'); ?></li>
          <li><?php echo t('settings.display.google_drive.modal_requirement_4'); ?></li>
        </ul>
        <p style="margin-top: 12px; padding: 10px; background: #fff3cd; border-radius: 4px; font-size: 13px;">
          <strong>⚠️ <?php echo t('settings.display.google_drive.modal_hipaa_warning_label'); ?>:</strong> <?php echo t('settings.display.google_drive.modal_hipaa_warning_text'); ?>
        </p>
      </div>
      <div class="delete-confirm-actions">
        <button type="button" id="gdBackupCancel" class="btn-delete-cancel"><?php echo t('settings.display.google_drive.modal_cancel'); ?></button>
        <button type="button" id="gdBackupConfirm" class="btn-delete-confirm" style="background: #2196f3;"><?php echo t('settings.display.google_drive.confirm_button'); ?></button>
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
  <?php if ($showDevTools): ?>
  <div class="dev-tools-panel collapsed <?php echo $isSuperUserInProd ? 'super-user-mode' : ''; ?>" id="devToolsPanel" 
       data-is-super-user="<?php echo $isSuperUserInProd ? 'true' : 'false'; ?>"
       data-env-display-name="<?php echo htmlspecialchars($environmentDisplayName); ?>">
    <div class="dev-tools-header">
      <h3>🛠️ <?php echo t('dev_tools.title'); ?> <?php if ($isSuperUserInProd): ?><span class="env-badge"><?php echo htmlspecialchars($environmentDisplayName); ?></span><?php endif; ?></h3>
      <button id="toggleDevTools" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; padding: 4px;">+</button>
    </div>
    <div class="dev-tools-content" id="devToolsContent" style="display: none;">
      <?php if ($isSuperUserInProd): ?>
      <!-- Super User Warning Banner -->
      <div class="dev-tools-warning">
        <strong><?php echo t('dev_tools.prompt_warning', ['environment' => htmlspecialchars($environmentDisplayName)]); ?></strong>
        <p><?php echo t('dev_tools.super_warning', ['environment' => htmlspecialchars($environmentDisplayName)]); ?></p>
      </div>
      <?php endif; ?>
      
      
      <!-- Test Case Creation -->
      <div class="dev-tools-section">
        <h4>🧪 <?php echo t('dev_tools.test_cases'); ?></h4>
        <div class="test-case-controls">
          <label for="devCaseType" class="sr-only"><?php echo t('dev_tools.test_case_type'); ?></label>
          <select id="devCaseType" class="dev-select">
            <option value="Mixed Case Type"><?php echo t('case_types.mixed'); ?></option>
            <?php echo renderCaseTypeOptions(array_diff(getCanonicalCaseTypes(), [CASE_TYPE_NEEDS_CLASSIFICATION])); ?>
          </select>
          <label for="devCaseCount" class="sr-only"><?php echo t('dev_tools.test_case_count'); ?></label>
          <input type="number" id="devCaseCount" class="dev-input" placeholder="<?php echo t('dev_tools.count_placeholder'); ?>" value="10" min="1" max="100">
          <button id="devGenerateCasesBtn" class="dev-btn dev-btn-primary"><?php echo t('dev_tools.generate'); ?></button>
        </div>
      </div>
      
      <!-- Dental Practice Demo Data -->
      <div class="dev-tools-section">
        <h4>🏥 <?php echo t('dev_tools.demo_data'); ?></h4>
        <div id="devDemoDataSummary" class="dev-demo-summary">
          <?php echo t('dev_tools.demo_loading_summary'); ?>
        </div>
        <div class="test-case-controls demo-data-controls">
          <label for="devDemoDataSize" class="sr-only"><?php echo t('dev_tools.test_case_count'); ?></label>
          <select id="devDemoDataSize" class="dev-select">
            <option value="small"><?php echo t('dev_tools.small'); ?></option>
            <option value="standard" selected><?php echo t('dev_tools.standard'); ?></option>
            <option value="large"><?php echo t('dev_tools.large'); ?></option>
          </select>
          <button id="devGenerateDemoDataBtn" class="dev-btn dev-btn-primary"><?php echo t('dev_tools.generate_demo_data'); ?></button>
          <button id="devResetDemoDataBtn" class="dev-btn dev-btn-warning"><?php echo t('dev_tools.reset_demo_data'); ?></button>
          <button id="devDeleteDemoDataBtn" class="dev-btn dev-btn-danger"><?php echo t('dev_tools.delete_demo_data'); ?></button>
        </div>
      </div>


      <!-- Data Management -->
      <div class="dev-tools-section">
        <h4>🗂️ <?php echo t('dev_tools.data_management'); ?></h4>
        <div class="data-controls">
          <button id="devDeleteAllCasesBtn" class="dev-btn dev-btn-danger"><?php echo t('dev_tools.delete_all_cases'); ?></button>
        </div>
      </div>
      
      <!-- Admin Tools -->
      <div class="dev-tools-section">
        <h4>👑 <?php echo t('dev_tools.admin_tools'); ?></h4>
        <div class="admin-links" style="display: flex; flex-direction: column; gap: 8px;">
          <a href="admin-practices.php" class="dev-btn" style="text-align: center; text-decoration: none;"><?php echo t('dev_tools.practice_administration'); ?></a>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Toast notification container -->
  <div class="toast-container" id="toastContainer"></div>

<?php if (isFeatureEnabled('SHOW_AI_CHAT') && $userCanViewAnalytics): ?>
  <!-- Floating Ask DentaTrak Button and Panel -->
  <!-- Ask DentaTrak calls api/ai-recommendations.php, which is gated server-side
       by canViewAnalytics() - hide the entry point when the current practice
       membership doesn't have Insights access, consistent with the Insights tab. -->
  <div class="ask-dentatrak-floating" id="askDentatrakFloating">
    <button type="button" class="ask-dentatrak-fab" id="askDentatrakFab" title="<?php echo t('ask_dentatrak.ask_app', ['appName' => htmlspecialchars($appName)]); ?>">
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
        <span class="ask-panel-title"><?php echo t('ask_dentatrak.title', ['appName' => htmlspecialchars($appName)]); ?></span>
        <span class="ask-panel-subtitle"><?php echo t('ask_dentatrak.subtitle'); ?></span>
      </div>
      <div class="ask-panel-body">
        <div class="ask-panel-messages" id="askDentatrakMessages">
          <div class="ask-message assistant">
            <p><?php echo t('ask_dentatrak.greeting'); ?></p>
            <ul>
              <li><?php echo t('ask_dentatrak.help_1'); ?></li>
              <li><?php echo t('ask_dentatrak.help_2', ['appName' => htmlspecialchars($appName)]); ?></li>
              <li><?php echo t('ask_dentatrak.help_3'); ?></li>
            </ul>
            <p><?php echo t('ask_dentatrak.prompt_question'); ?></p>
          </div>
        </div>
      </div>
      <div class="ask-panel-input">
        <input type="text" id="askDentatrakInput" placeholder="<?php echo t('ask_dentatrak.input_placeholder'); ?>" autocomplete="off">
        <button type="button" id="askDentatrakSubmit" title="<?php echo t('ask_dentatrak.send'); ?>">
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
  <?php require_once __DIR__ . '/api/auth-timeout-script.php'; ?>
  <script src="js/gcs-upload.js?v=20260916" defer></script>
  <script type="importmap">
  {
    "imports": {
      "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
      "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
    }
  }
  </script>
  <script src="js/workflow-draft.js?v=20260829f" defer></script>
  <script src="js/workflow-draft-ui.js?v=20260829f" defer></script>
  <script type="application/json" id="caseViewBootstrap"><?= json_encode($caseViewBootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="js/case-filter-sort.js?v=20260916f" defer></script>
  <script src="js/app.js?v=20260916f" defer></script>
  <script src="js/mobile-case-modal.js?v=20260830c" defer></script>
  <script src="js/mobile-kanban.js?v=20260916b" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js" defer></script>
  <script type="module" src="js/attachment-viewer.js?v=20260903a" defer></script>
  <script src="js/card-delete-fixed.js?v=20250104" defer></script>
  <script src="js/assignments.js?v=20250104" defer></script>
  <script src="js/case-comments.js?v=20260916a" defer></script>
  <script src="js/notifications.js?v=20260915a" defer></script>
<?php if (isFeatureEnabled('SHOW_NOTIFICATIONS')): ?>
  <script src="js/notification-preferences.js?v=20250104" defer></script>
<?php endif; ?>
  <script defer>
    (function() {
      <?php if (!empty($_GET['notification_id'])): ?>
      try {
        sessionStorage.setItem('pendingNotification', JSON.stringify({
          notification_id: <?php echo (int)$_GET['notification_id']; ?>
        }));
      } catch (e) {}
      <?php endif; ?>
      window.addEventListener('DOMContentLoaded', function() {
        if (typeof window.processPendingNotification === 'function') {
          window.processPendingNotification();
        }
      });
    })();
  </script>
  <script src="js/activity-timeline.js?v=20250104" defer></script>
  <script src="js/clinical-details.js?v=20250104" defer></script>
  <script src="js/ask-dentatrak.js?v=20250104" defer></script>
  <script src="js/insights.js?v=20250104" defer></script>
<?php if (isFeatureEnabled('BILLING_ENABLED')): ?>
  <script src="js/billing-portal.js?v=20260916c" defer></script>
<?php endif; ?>
  <script src="js/patient-search.js?v=20260916d" defer></script>
  <script src="js/realtime-updates.js?v=20260916b" defer></script>
  <script src="js/case-list.js?v=20260916d" defer></script>
  <script src="js/case-actions-menu.js?v=20260916d" defer></script>
  <script src="js/case-remakes.js?v=20260922a" defer></script>
  
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

      const isSuperUserInProd = devToolsPanel?.dataset?.isSuperUser === 'true';
      const envDisplayName = devToolsPanel?.dataset?.envDisplayName || 'Production';
      
      // Helper function for confirmation dialogs in UAT/Production
      function confirmDestructiveAction(actionName, details) {
        if (!isSuperUserInProd) {
          return true; // No confirmation needed in development
        }

        const message = t('dev_tools.destructive_prompt', {environment: envDisplayName, action: actionName, details: details});

        const userInput = prompt(message);
        return userInput === 'CONFIRM';
      }
      
      // Toggle dev tools panel
      if (toggleBtn) toggleBtn.addEventListener('click', function() {
        const isCollapsed = devToolsContent.style.display === 'none';
        devToolsContent.style.display = isCollapsed ? 'block' : 'none';
        toggleBtn.textContent = isCollapsed ? '−' : '+';
        if (devToolsPanel) devToolsPanel.classList.toggle('collapsed', !isCollapsed);
      });
      
      // Generate test cases - handled by app.js, no duplicate handler needed here
      
      // Delete all cases
      const devDeleteAllCasesBtn = document.getElementById('devDeleteAllCasesBtn');
      if (devDeleteAllCasesBtn) devDeleteAllCasesBtn.addEventListener('click', function() {
          // Require confirmation in UAT/Production
          if (!confirmDestructiveAction(
            t('dev_tools.delete_all_cases_confirm'),
            t('dev_tools.delete_all_cases_details')
          )) {
            showToast(t('common.action_cancelled'), 'info');
            return;
          }

          this.disabled = true;
          this.textContent = t('common.deleting');
          showToast(t('dev_tools.delete_all_cases_toast'), 'warning');

          fetch('api/delete-all-cases.php', {
            method: 'DELETE',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            credentials: 'same-origin'
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast(t('dev_tools.delete_all_cases_success'), 'success');
              setTimeout(() => location.reload(), 1000);
            } else {
              showToast(t('dev_tools.delete_all_cases_error', {message: data.message}), 'error');
              this.disabled = false;
              this.textContent = t('dev_tools.delete_all_cases');
            }
          })
          .catch(error => {
            showToast(t('dev_tools.delete_all_cases_unknown_error'), 'error');
            this.disabled = false;
            this.textContent = t('dev_tools.delete_all_cases');
          });
      });
      
      
      
      
    });
  </script>
<?php endif; ?>

  <!-- Confirmation Modal (reusable) -->
  <div id="confirmModal" class="modal confirm-modal">
    <div class="modal-content confirm-modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="confirmModalTitle"><?php echo t('common.confirm'); ?></h2>
      </div>
      <div class="modal-body">
        <p id="confirmModalMessage"><?php echo t('common.confirm_message'); ?></p>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn-cancel" id="confirmModalCancel"><?php echo t('common.cancel'); ?></button>
        <button type="button" class="btn-primary" id="confirmModalOk"><?php echo t('common.ok'); ?></button>
      </div>
    </div>
  </div>

  <!-- Chart.js and Analytics Pro are lazy-loaded when Analytics tab is clicked -->
  
  <!-- Shepherd.js Tour - deferred since not needed immediately -->
  <?php if (isFeatureEnabled('SHOW_TOUR')): ?>
  <script src="https://cdn.jsdelivr.net/npm/shepherd.js@11/dist/js/shepherd.min.js" defer></script>
  <script src="js/tour.js?v=20260818p" defer></script>
  <?php endif; ?>

  <!-- Attachment Viewer Modal -->
  <div id="attachmentViewerModal" class="attachment-viewer-modal" style="display:none;">
    <div class="attachment-viewer-content">
      <div class="attachment-viewer-header">
        <div class="attachment-viewer-title-wrap">
          <h2 class="attachment-viewer-title"><?php echo t('attachments.viewer.attachment'); ?></h2>
          <span class="attachment-viewer-type">STL</span>
        </div>
        <div class="attachment-viewer-actions">
          <button type="button" class="attachment-viewer-btn attachment-viewer-prev" title="<?php echo t('attachments.viewer.previous_page'); ?>" disabled>&lt;</button>
          <span class="attachment-viewer-page-info"><?php echo t('attachments.viewer.page_info', ['page' => 1]); ?></span>
          <button type="button" class="attachment-viewer-btn attachment-viewer-next" title="<?php echo t('attachments.viewer.next_page'); ?>" disabled>&gt;</button>
          <button type="button" class="attachment-viewer-btn attachment-viewer-zoom-in" title="<?php echo t('attachments.viewer.zoom_in'); ?>">+</button>
          <button type="button" class="attachment-viewer-btn attachment-viewer-zoom-out" title="<?php echo t('attachments.viewer.zoom_out'); ?>">&#8722;</button>
          <button type="button" class="attachment-viewer-btn attachment-viewer-reset" title="<?php echo t('attachments.viewer.reset_view'); ?>">Reset</button>
          <button type="button" class="attachment-viewer-btn attachment-viewer-fit" title="<?php echo t('attachments.viewer.fit_to_view'); ?>">Fit</button>
          <button type="button" class="attachment-viewer-btn attachment-viewer-download" title="<?php echo t('attachments.viewer.download_file'); ?>">Download</button>
          <button type="button" class="attachment-viewer-btn attachment-viewer-fullscreen" title="<?php echo t('attachments.viewer.full_screen'); ?>">Full Screen</button>
          <button type="button" class="attachment-viewer-btn attachment-viewer-btn-close attachment-viewer-close" title="<?php echo t('attachments.viewer.close_viewer'); ?>">&times;</button>
        </div>
      </div>
      <div class="attachment-viewer-canvas-wrap">
        <div class="attachment-viewer-canvas"></div>
        <div class="attachment-viewer-loading">
          <div class="attachment-viewer-spinner"></div>
          <span><?php echo t('attachments.viewer.loading_preview'); ?></span>
        </div>
        <div class="attachment-viewer-error"></div>
      </div>
    </div>
  </div>

<?php if (isFeatureEnabled('SHOW_NOTIFICATIONS')): ?>
  <!-- Notification Preferences Modal -->
  <div id="notificationPreferencesModal" class="modal" style="display:none;">
    <div class="modal-content notification-preferences-modal">
      <div class="modal-header">
        <h2 class="modal-title"><?php echo t('preferences.title'); ?></h2>
        <button type="button" class="btn-close" id="notificationPreferencesClose" aria-label="<?php echo t('common.close'); ?>"><span>&times;</span></button>
      </div>
      <div class="modal-body" id="notificationPreferencesBody">
        <p class="notification-preferences-intro"><?php echo t('preferences.intro'); ?></p>

        <div id="notificationPreferencesLoading" class="preferences-loading">Loading...</div>

        <div id="notificationPreferencesError" class="preferences-error" style="display:none;">
          <p class="notification-preferences-error-text"><?php echo t('preferences.load_error'); ?></p>
          <div class="notification-preferences-error-actions">
            <button type="button" class="btn btn-primary" id="notificationPreferencesRetry"><?php echo t('common.retry'); ?></button>
            <button type="button" class="btn btn-secondary" id="notificationPreferencesErrorClose"><?php echo t('common.close'); ?></button>
          </div>
        </div>

        <div id="notificationPreferencesControls" style="display:none;">
          <div class="notification-preferences-master">
            <label for="prefMaster" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.master_label'); ?></span>
              <span class="switch">
                <input type="checkbox" id="prefMaster" role="switch" aria-describedby="prefMasterHelp" disabled>
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
            <p id="prefMasterHelp" class="notification-preference-help"><?php echo t('preferences.master_help'); ?></p>
          </div>

          <div class="notification-preferences-list" id="notificationPreferencesList" role="group" aria-label="<?php echo t('preferences.event_controls'); ?>">
          <div class="notification-preference-item" data-event="case_created">
            <label for="pref_case_created" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.case_created'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_case_created" role="switch" class="event-preference" data-event="case_created">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="assignment_changed">
            <label for="pref_assignment_changed" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.assignment_changed'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_assignment_changed" role="switch" class="event-preference" data-event="assignment_changed">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="file_added">
            <label for="pref_file_added" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.file_added'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_file_added" role="switch" class="event-preference" data-event="file_added">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="file_deleted">
            <label for="pref_file_deleted" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.file_deleted'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_file_deleted" role="switch" class="event-preference" data-event="file_deleted">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="notes_changed">
            <label for="pref_notes_changed" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.notes_changed'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_notes_changed" role="switch" class="event-preference" data-event="notes_changed">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="status_changed">
            <label for="pref_status_changed" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.status_changed'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_status_changed" role="switch" class="event-preference" data-event="status_changed">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="due_date_changed">
            <label for="pref_due_date_changed" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.due_date_changed'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_due_date_changed" role="switch" class="event-preference" data-event="due_date_changed">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="appointment_date_changed">
            <label for="pref_appointment_date_changed" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.appointment_date_changed'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_appointment_date_changed" role="switch" class="event-preference" data-event="appointment_date_changed">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <div class="notification-preference-item" data-event="case_details_changed">
            <label for="pref_case_details_changed" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.case_details_changed'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_case_details_changed" role="switch" class="event-preference" data-event="case_details_changed">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <?php if (isFeatureEnabled('SHOW_COMMENTS')): ?>
          <div class="notification-preference-item" data-event="mention">
            <label for="pref_mention" class="notification-preference-label">
              <span class="notification-preference-title"><?php echo t('preferences.events.mention'); ?></span>
              <span class="switch">
                <input type="checkbox" id="pref_mention" role="switch" class="event-preference" data-event="mention">
                <span class="switch-slider" aria-hidden="true"></span>
              </span>
            </label>
          </div>
          <?php endif; ?>
        </div>
      </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="notificationPreferencesCancel"><?php echo t('common.cancel'); ?></button>
        <button type="button" class="btn btn-primary" id="notificationPreferencesSave"><?php echo t('common.save'); ?></button>
      </div>
    </div>
  </div>
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
