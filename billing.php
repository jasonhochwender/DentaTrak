<?php
// Billing page
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/security-headers.php';
require_once __DIR__ . '/api/practice-security.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

// SECURITY: Billing is an admin-only surface. Non-admins (and anyone
// without a valid practice context) are redirected back to the dashboard
// even if they navigate here directly, matching the hidden Billing links
// in main.php and the admin enforcement already on every billing API
// endpoint (api/billing.php, api/billing-portal.php,
// api/create-checkout-session.php, api/create-portal-session.php).
// getCurrentPracticeId() is used (rather than requireValidPracticeContext())
// because this is an HTML page, not a JSON API endpoint.
$currentPracticeId = getCurrentPracticeId();
if (!$currentPracticeId || !isPracticeAdmin($currentPracticeId)) {
    header('Location: main.php');
    exit;
}

// ── Billing feature gate ────────────────────────────────────────────────────
// When billing is disabled (the production default until Stripe is fully
// configured), this entire page is unreachable.  Do this before loading
// appConfig.php so no Stripe config is evaluated.
$billingEnabledRaw = getenv('BILLING_ENABLED');
if ($billingEnabledRaw === false) {
    $billingEnabledRaw = $_ENV['BILLING_ENABLED'] ?? '';
}
if (!filter_var($billingEnabledRaw, FILTER_VALIDATE_BOOLEAN)) {
    header('Location: main.php');
    exit;
}

setSecurityHeaders();

require_once __DIR__ . '/api/appConfig.php';

// Get Stripe configuration
$stripeConfig = $appConfig['stripe'] ?? [];
$stripePricingTableId = $stripeConfig['pricing_table_id'] ?? '';
$stripePricingTableKey = $stripeConfig['pricing_table_publishable_key'] ?? '';

// Get billing information directly
$billingInfo = null;
$userEmail = $_SESSION['user']['email'] ?? '';

try {
    // Get user's billing tier, case count, and created_at for trial calculation
    $stmt = $pdo->prepare("SELECT billing_tier, case_count, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['db_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $userEmail = $user['email'] ?? $userEmail;
        
        // Get current case count (real-time calculation)
        $currentCaseCount = 0;
        $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
        
        if ($currentPracticeId) {
            // Count ALL cases including archived ones for evaluate plan limit
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ?");
            $stmt->execute([$currentPracticeId]);
            $currentCaseCount = (int)$stmt->fetchColumn();
        }
        
        // Get billing tier configuration
        $tierConfig = $appConfig['billing']['tiers'][$user['billing_tier']] ?? $appConfig['billing']['tiers']['evaluate'];
        
        // Calculate trial days remaining for Evaluate plan
        $trialDaysRemaining = null;
        $trialExpired = false;
        $isTrial = $tierConfig['is_trial'] ?? false;
        
        if ($isTrial && isset($user['created_at'])) {
            $trialDays = $appConfig['billing']['trial_days'] ?? 90;
            $createdAt = new DateTime($user['created_at']);
            $now = new DateTime();
            $daysSinceSignup = $now->diff($createdAt)->days;
            $trialDaysRemaining = max(0, $trialDays - $daysSinceSignup);
            $trialExpired = $trialDaysRemaining <= 0;
        }
        
        // Check if user can create more cases
        $canCreateCases = true;
        if ($isTrial && $trialExpired) {
            $canCreateCases = false;
        } elseif ($tierConfig['max_cases'] > 0) {
            $canCreateCases = $currentCaseCount < $tierConfig['max_cases'];
        }

        if ($currentCaseCount !== $user['case_count']) {
            $stmt = $pdo->prepare("UPDATE users SET case_count = ? WHERE id = ?");
            $stmt->execute([$currentCaseCount, $_SESSION['db_user_id']]);
        }
        
        // Get current user count for pre-selection warning
        $currentUserCount = 0;
        if ($currentPracticeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM practice_users WHERE practice_id = ?");
            $stmt->execute([$currentPracticeId]);
            $currentUserCount = (int)$stmt->fetchColumn();
        }

        $billingInfo = [
            'billing_tier' => $user['billing_tier'],
            'tier_name' => $tierConfig['name'],
            'case_count' => $currentCaseCount,
            'max_cases' => $tierConfig['max_cases'],
            'can_create_cases' => $canCreateCases,
            'can_add_users' => $isTrial ? !$trialExpired : $tierConfig['can_add_users'],
            'has_analytics' => $isTrial ? !$trialExpired : $tierConfig['has_analytics'],
            'practice_id' => $currentPracticeId,
            'is_trial' => $isTrial,
            'trial_days_remaining' => $trialDaysRemaining,
            'trial_expired' => $trialExpired,
            'user_count' => $currentUserCount
        ];
    }
} catch (PDOException $e) {
    error_log('Billing page error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
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
    
    <title><?php echo t('billing.title'); ?> - <?php echo htmlspecialchars($appConfig['appName']); ?></title>

    <!-- Favicon / App Icons -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="android-chrome-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="css/app.light.css">
    <script async src="https://js.stripe.com/v3/pricing-table.js"></script>
    <style>
        :root {
            --billing-primary: #1e40af;
            --billing-primary-light: #3b82f6;
            --billing-success: #10b981;
            --billing-warning: #f59e0b;
            --billing-danger: #ef4444;
            --billing-gray-50: #f9fafb;
            --billing-gray-100: #f3f4f6;
            --billing-gray-200: #e5e7eb;
            --billing-gray-600: #4b5563;
            --billing-gray-800: #1f2937;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f0f4ff 0%, #faf5ff 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
        }
        
        .billing-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .billing-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--billing-gray-600);
            text-decoration: none;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: 8px;
            transition: all 0.2s;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .back-link:hover {
            color: var(--billing-primary);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }
        
        .billing-header {
            text-align: center;
            margin-bottom: 48px;
        }
        
        .billing-header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--billing-gray-800);
            margin-bottom: 12px;
        }
        
        .billing-header p {
            color: var(--billing-gray-600);
            font-size: 1.15rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .billing-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 32px;
        }
        
        @media (min-width: 900px) {
            .billing-grid {
                grid-template-columns: 280px 1fr;
            }
        }
        
        .billing-page {
            max-width: 1400px;
        }
        
        .billing-sidebar {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        .billing-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            padding: 28px;
            border: 1px solid var(--billing-gray-100);
        }
        
        .billing-card h3 { 
            font-size: 1rem;
            font-weight: 600;
            color: var(--billing-gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 16px;
        }
        
        .current-plan-display {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .plan-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .plan-icon svg {
            width: 24px;
            height: 24px;
            stroke: white;
            stroke-width: 2;
            fill: none;
        }
        
        .plan-icon.evaluate {
            background: linear-gradient(135deg, #64748b 0%, #475569 100%);
            box-shadow: 0 4px 12px rgba(71, 85, 105, 0.3);
        }
        
        .plan-icon.operate {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .plan-icon.control {
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .plan-details h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--billing-gray-800);
            margin-bottom: 4px;
        }
        
        .plan-details .plan-status {
            font-size: 0.9rem;
            color: var(--billing-success);
            font-weight: 500;
        }
        
        .usage-meter {
            margin-top: 20px;
        }
        
        .usage-meter-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        
        .usage-meter-label {
            color: var(--billing-gray-600);
        }
        
        .usage-meter-value {
            font-weight: 600;
            color: var(--billing-gray-800);
        }
        
        .usage-meter-bar {
            height: 8px;
            background: var(--billing-gray-200);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .usage-meter-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .usage-meter-fill.low {
            background: linear-gradient(90deg, var(--billing-success), #34d399);
        }
        
        .usage-meter-fill.medium {
            background: linear-gradient(90deg, var(--billing-warning), #fbbf24);
        }
        
        .usage-meter-fill.high {
            background: linear-gradient(90deg, var(--billing-danger), #f87171);
        }
        
        .pricing-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            padding: 32px;
            border: 1px solid var(--billing-gray-100);
        }
        
        .pricing-section-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .pricing-section-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--billing-gray-800);
            margin-bottom: 8px;
        }
        
        .pricing-section-header p {
            color: var(--billing-gray-600);
        }
        
        .stripe-pricing-container {
            min-height: 300px;
        }
        
        stripe-pricing-table {
            width: 100%;
            --stripe-pricing-table-layout: row;
        }
        
        /* Force horizontal layout for Stripe pricing table */
        stripe-pricing-table::part(base) {
            flex-direction: row !important;
        }
        
        .billing-footer {
            text-align: center;
            margin-top: 48px;
            padding-top: 32px;
            border-top: 1px solid var(--billing-gray-200);
        }
        
        .billing-footer p {
            color: var(--billing-gray-600);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }
        
        .billing-footer a {
            color: var(--billing-primary);
            text-decoration: none;
        }
        
        .billing-footer a:hover {
            text-decoration: underline;
        }
        
        .info-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
            border: 1px solid #c7d2fe;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .info-banner-icon {
            color: var(--billing-primary);
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .info-banner-text {
            font-size: 0.9rem;
            color: #3730a3;
            line-height: 1.5;
        }
        
        .info-banner-text strong {
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="billing-page">
        <nav class="billing-nav">
            <a href="main.php" class="back-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                <?php echo t('billing.back_to_dashboard'); ?>
            </a>
        </nav>
        
        <div class="billing-header">
            <h1><?php echo t('billing.title'); ?></h1>
            <p><?php echo t('billing.subtitle'); ?></p>
        </div>
        
        <?php if ($billingInfo && !isset($billingInfo['error'])): ?>
            <div class="billing-grid">
                <!-- Sidebar with current plan info -->
                <div class="billing-sidebar">
                    <div class="billing-card">
                        <h3><?php echo t('billing.current_plan.title'); ?></h3>
                        <div class="current-plan-display">
                            <div class="plan-icon <?php echo strtolower($billingInfo['tier_name']); ?>">
                                <?php 
                                $tierName = strtolower($billingInfo['tier_name']);
                                if ($tierName === 'evaluate') {
                                    // Compass/explore icon
                                    echo '<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>';
                                } elseif ($tierName === 'operate') {
                                    // Layers/workflow icon
                                    echo '<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>';
                                } elseif ($tierName === 'control') {
                                    // Shield/premium icon
                                    echo '<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>';
                                } else {
                                    echo '<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>';
                                }
                                ?>
                            </div>
                            <div class="plan-details">
                                <h2><?php echo htmlspecialchars($billingInfo['tier_name']); ?></h2>
                                <span class="plan-status">
                                    <svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor" style="margin-right: 4px;"><circle cx="4" cy="4" r="4"/></svg>
                                    <?php echo t('billing.status.active'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($billingInfo['is_trial'] && $billingInfo['trial_days_remaining'] !== null): 
                            $trialPercent = min(100, (($appConfig['billing']['trial_days'] - $billingInfo['trial_days_remaining']) / $appConfig['billing']['trial_days']) * 100);
                            $trialClass = $billingInfo['trial_days_remaining'] > 10 ? 'low' : ($billingInfo['trial_days_remaining'] > 3 ? 'medium' : 'high');
                        ?>
                        <div class="usage-meter">
                            <div class="usage-meter-header">
                                <span class="usage-meter-label"><?php echo t('billing.current_plan.trial_period'); ?></span>
                                <span class="usage-meter-value"><?php echo $billingInfo['trial_expired'] ? t('billing.current_plan.trial_expired') : pluralize($billingInfo['trial_days_remaining'], 'billing.current_plan.trial_days_remaining'); ?></span>
                            </div>
                            <div class="usage-meter-bar">
                                <div class="usage-meter-fill <?php echo $trialClass; ?>" style="width: <?php echo $trialPercent; ?>%"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Main pricing section -->
                <div class="pricing-section">
                    <div class="pricing-section-header">
                        <h2><?php echo t('billing.plans.title'); ?></h2>
                        <p><?php echo t('billing.plans.subtitle'); ?></p>
                    </div>
                    
                    <?php if (isset($billingInfo['user_count']) && $billingInfo['user_count'] > 5): ?>
                    <div class="info-banner" style="margin-bottom: 24px; background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-color: #fcd34d;">
                        <div class="info-banner-icon" style="color: #d97706;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                <line x1="12" y1="9" x2="12" y2="13"></line>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                        </div>
                        <div class="info-banner-text" style="color: #92400e;">
                            <?php echo t('billing.current_plan.user_limit_warning', [
                                'count' => (int)$billingInfo['user_count'],
                                'max' => $appConfig['billing']['tiers']['operate']['max_users'] ?? 5
                            ]); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="stripe-pricing-container">
                        <?php if (!empty($stripePricingTableId) && !empty($stripePricingTableKey)): ?>
                        <stripe-pricing-table 
                            pricing-table-id="<?php echo htmlspecialchars($stripePricingTableId); ?>"
                            publishable-key="<?php echo htmlspecialchars($stripePricingTableKey); ?>"
                            <?php if (!empty($userEmail)): ?>
                            customer-email="<?php echo htmlspecialchars($userEmail); ?>"
                            <?php endif; ?>
                        ></stripe-pricing-table>
                        <?php else: ?>
                        <div style="text-align: center; padding: 60px 20px; color: var(--billing-gray-600);">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 16px; opacity: 0.5;">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                <line x1="1" y1="10" x2="23" y2="10"></line>
                            </svg>
                            <p><?php echo t('billing.pricing_table_unavailable'); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="billing-footer">
                <p><?php echo t('billing.security_note'); ?></p>
            </div>
            
        <?php else: ?>
            <div class="billing-card" style="max-width: 600px; margin: 0 auto; text-align: center;">
                <p style="color: var(--billing-gray-600);"><?php echo t('billing.loading_error'); ?></p>
                <a href="main.php" class="back-link" style="margin-top: 20px; display: inline-flex;"><?php echo t('billing.return_to_dashboard'); ?></a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
