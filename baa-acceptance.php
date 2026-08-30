<?php
/**
 * BAA (Business Associate Agreement) Acceptance Page
 * 
 * This is a full-screen blocking page that requires users to accept the BAA
 * before they can access any PHI-related features of the application.
 */

require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
require_once __DIR__ . '/api/plan-entitlements.php';
require_once __DIR__ . '/api/billing-bypass.php';
setSecurityHeaders();

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Check if user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['db_user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$userName = $_SESSION['user_name'] ?? '';

// If explicitly starting a brand new practice (e.g. "Create My Own Practice"
// from the practice chooser, or "Create New Practice" from the in-app
// practice switcher), this page must not check/prefill against the OLD
// practice's BAA/legal info - the form below should always start blank and
// this page should never redirect to main.php just because some OTHER
// practice already has an accepted BAA.
//
// IMPORTANT: unlike an earlier version of this check, $_SESSION['current_
// practice_id'] is intentionally left untouched here. Destroying it as a
// side effect of merely LOADING this page (rather than successfully
// completing or explicitly canceling it) orphaned the session - main.php
// would bounce the user to practice-setup.php if they abandoned the form
// or a validation error stopped them from submitting. The "create a new
// practice rather than update the current one" decision is instead made
// explicitly via a `new` flag sent to api/accept-baa.php on submit (see
// the IS_CREATING_NEW_PRACTICE JS var below), not by session presence.
$isCreatingNewPractice = (isset($_GET['new']) && $_GET['new'] === '1');

// A Cancel action can safely return the user to whichever practice they
// were already on - this only applies when they already had a valid
// practice (i.e. they are creating an ADDITIONAL practice), never for a
// brand new user with nothing to cancel back to.
$cancelReturnPracticeId = null;
if ($isCreatingNewPractice && !empty($_SESSION['current_practice_id'])) {
    $cancelReturnPracticeId = (int)$_SESSION['current_practice_id'];
}

// Check if user already has a practice with accepted BAA. When explicitly
// creating a new practice, treat this as if there were no current
// practice for display purposes only - $_SESSION['current_practice_id']
// itself is not modified.
$hasBaaAccepted = false;
$practiceId = $isCreatingNewPractice ? null : ($_SESSION['current_practice_id'] ?? null);
$existingPracticeName = '';
$existingPracticeAddress = '';

if ($practiceId) {
    try {
        $stmt = $pdo->prepare("SELECT baa_accepted, practice_name, practice_address FROM practices WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        $practice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($practice) {
            if ($practice['baa_accepted']) {
                $hasBaaAccepted = true;
            }
            // Pre-populate form with existing practice data
            $existingPracticeName = $practice['practice_name'] ?? '';
            $existingPracticeAddress = $practice['practice_address'] ?? '';
        }
    } catch (PDOException $e) {
        // Column might not exist yet - try without BAA columns
        try {
            $stmt = $pdo->prepare("SELECT practice_name FROM practices WHERE id = :id");
            $stmt->execute(['id' => $practiceId]);
            $practice = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($practice) {
                $existingPracticeName = $practice['practice_name'] ?? '';
            }
        } catch (PDOException $e2) {
            // Ignore
        }
    }
}

// If BAA is already accepted, redirect to main
if ($hasBaaAccepted) {
    header('Location: main.php');
    exit;
}

// When creating an ADDITIONAL practice, check the user's own practice-count
// entitlement before showing the onboarding form. A subscription (and its
// practice-count limit) belongs to the subscription owner's account, not to
// any single practice — see api/plan-entitlements.php. This is a UX
// short-circuit only; api/accept-baa.php independently re-checks and is the
// authoritative enforcement point.
$entitlementBlocked = false;
$entitlement = null;
$upgradeUrl = null;
if ($isCreatingNewPractice) {
    $entitlementEmail = $_SESSION['user_email'] ?? ($_SESSION['user']['email'] ?? '');
    $entitlement = evaluatePracticeCreationEntitlement($pdo, $userId, $entitlementEmail);
    $entitlementBlocked = !$entitlement['allowed'];
}

// Build the upgrade call-to-action target. Upgrading uses the SAME in-app
// Billing/Checkout flow as the Billing menu item (api/create-checkout-session.php
// via the billing modal) - there is no separate upgrade path. Because this
// page can be reached from the practice chooser with no practice selected,
// route through select-practice.php first so main.php has a valid practice
// context and doesn't bounce back to the chooser.
if ($entitlementBlocked && !empty($entitlement['upgrade_target'])) {
    $upgradePracticeId = $cancelReturnPracticeId;
    if (!$upgradePracticeId) {
        try {
            $stmt = $pdo->prepare("
                SELECT practice_id
                FROM practice_users
                WHERE user_id = :user_id AND is_owner = 1
                ORDER BY practice_id ASC
                LIMIT 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $ownedId = $stmt->fetchColumn();
            $upgradePracticeId = $ownedId ? (int)$ownedId : null;
        } catch (PDOException $e) {
            $upgradePracticeId = null;
        }
    }
    $upgradeUrl = $upgradePracticeId
        ? 'api/select-practice.php?practice_id=' . $upgradePracticeId . '&redirect=1&billing=1'
        : 'main.php?billing=1';
}

// Determine environment for visual cues
$envValue = $appConfig['environment'] ?? 'production';
$envClass = ($envValue === 'production') ? 'env-prod' : 'env-dev';
$appName = $appConfig['appName'] ?? 'Dental Case Manager';

// Current BAA version
$baaVersion = 'v1.0-2026-08-07';
?>
<!DOCTYPE html>
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
    
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title><?php echo t('onboarding.baa.title'); ?> - <?php echo htmlspecialchars($appName); ?></title>

    <!-- Favicon / App Icons -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/toast.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 50%, #1e3a5f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .baa-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            max-width: 800px;
            width: 100%;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .baa-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            color: white;
            padding: 32px;
            text-align: center;
        }
        
        .baa-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .baa-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .baa-header .shield-icon {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        
        .baa-header .shield-icon svg {
            width: 32px;
            height: 32px;
        }
        
        .baa-content {
            flex: 1;
            overflow-y: auto;
            padding: 32px;
        }
        
        .baa-intro {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .baa-intro p {
            color: #0369a1;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .form-section {
            margin-bottom: 24px;
        }
        
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        
        .form-group label .required {
            color: #dc2626;
        }
        
        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .form-group input[type="text"]:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group .help-text {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .baa-terms {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 24px;
            font-size: 0.85rem;
            line-height: 1.7;
            color: #475569;
        }
        
        .baa-terms h3 {
            font-size: 1rem;
            color: #1e293b;
            margin: 16px 0 8px;
        }
        
        .baa-terms h3:first-child {
            margin-top: 0;
        }
        
        .baa-terms ul {
            margin: 8px 0;
            padding-left: 24px;
        }
        
        .baa-terms li {
            margin: 4px 0;
        }
        
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            font-size: 0.9rem;
            color: #92400e;
            cursor: pointer;
            line-height: 1.5;
        }
        
        .baa-footer {
            padding: 24px 32px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        
        .baa-version {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        .baa-actions {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }
        
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.9rem;
            display: none;
        }
        
        .success-message {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.9rem;
            display: none;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .loading-spinner {
            background: white;
            padding: 32px;
            border-radius: 12px;
            text-align: center;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 640px) {
            .baa-header {
                padding: 24px;
            }
            
            .baa-content {
                padding: 20px;
            }
            
            .baa-footer {
                flex-direction: column;
                padding: 20px;
            }
            
            .baa-actions {
                width: 100%;
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body class="<?php echo $envClass; ?>">
    <!-- Toast Container for notifications -->
    <div id="toastContainer" class="toast-container"></div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p><?php echo t('onboarding.baa.processing_agreement'); ?></p>
        </div>
    </div>
    
    <div class="baa-container">
        <div class="baa-header">
            <div class="shield-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <h1><?php echo t('onboarding.baa.title'); ?></h1>
            <p><?php echo t('onboarding.baa.hipaa_compliance_requirement'); ?></p>
        </div>
        
        <div class="baa-content">
            <?php if ($entitlementBlocked): ?>
            <div class="baa-intro" style="background: #fef3c7; border-color: #fcd34d;">
                <p style="color: #92400e;">
                    <strong>Your <?php echo htmlspecialchars($entitlement['plan_name']); ?> plan <?php echo $entitlement['max_practices'] === null ? 'includes 5 practices' : 'allows ' . (int)$entitlement['max_practices'] . ' owned practice' . ($entitlement['max_practices'] === 1 ? '' : 's'); ?>.</strong>
                    You already own <?php echo (int)$entitlement['current_count']; ?>.<?php echo !empty($entitlement['upgrade_target']) ? ' To create another practice, upgrade your plan.' : ''; ?>
                </p>
            </div>

            <?php if ($entitlement['upgrade_target'] === 'control'): ?>
            <p style="margin: 24px 0; color: #374151; line-height: 1.6;">
                Upgrade to <strong>Control</strong> to own up to <?php echo getMaxOwnedPractices('control'); ?> practices under one account, plus advanced analytics and Smart Recommendations.
            </p>
            <?php elseif ($entitlement['upgrade_target'] === 'scale'): ?>
            <p style="margin: 24px 0; color: #374151; line-height: 1.6;">
                Upgrade to <strong>Scale</strong> for 5 included practices under one account, with everything in Control plus support for additional practices as your organization grows.
            </p>
            <?php else: ?>
            <p style="margin: 24px 0; color: #374151; line-height: 1.6;">
                Your <?php echo htmlspecialchars($entitlement['plan_name']); ?> plan <?php echo $entitlement['max_practices'] === null ? 'includes 5 practices with additional practices billed separately' : 'supports up to ' . (int)$entitlement['max_practices'] . ' practices'; ?>. Please contact DentaTrak if you need additional practices.
            </p>
            <?php endif; ?>
            <?php else: ?>
            <div class="baa-intro">
                <p><?php echo t('onboarding.baa.welcome_user', ['name' => htmlspecialchars($userName ?: 'there')]); ?> <?php echo t('onboarding.baa.intro_text'); ?></p>
            </div>
            
            <div id="errorMessage" class="error-message"></div>
            <div id="successMessage" class="success-message"></div>
            
            <form id="baaForm" novalidate>
                <div class="form-section">
                    <div class="form-section-title"><?php echo t('onboarding.baa.practice_information_title', [], 'Practice Information'); ?></div>
                    
                    <div class="form-group">
                        <label for="legalName"><?php echo t('onboarding.baa.practice_name_label'); ?> <span class="required">*</span></label>
                        <input type="text" id="legalName" name="legalName" placeholder="<?php echo t('onboarding.baa.practice_name_placeholder', [], "Enter your practice's legal name"); ?>" value="<?php echo htmlspecialchars($existingPracticeName); ?>">
                        <p class="help-text"><?php echo t('onboarding.baa.legal_name_help'); ?></p>
                    </div>
                    
                    <div class="form-group">
                        <label for="practiceAddress"><?php echo t('onboarding.baa.practice_address_label'); ?> <span class="required">*</span></label>
                        <textarea id="practiceAddress" name="practiceAddress" placeholder="<?php echo t('onboarding.baa.practice_address_placeholder'); ?>"><?php echo htmlspecialchars($existingPracticeAddress); ?></textarea>
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="form-section-title"><?php echo t('onboarding.baa.authorized_signer_title', [], 'Authorized Signer'); ?></div>
                    
                    <div class="form-group">
                        <label for="signerName"><?php echo t('onboarding.baa.signer_name_label'); ?> <span class="required">*</span></label>
                        <input type="text" id="signerName" name="signerName" placeholder="<?php echo t('onboarding.baa.signer_name_placeholder'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="signerTitle"><?php echo t('onboarding.baa.signer_title_label'); ?> <span class="required">*</span></label>
                        <input type="text" id="signerTitle" name="signerTitle" placeholder="<?php echo t('onboarding.baa.signer_title_placeholder'); ?>">
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="form-section-title">Business Associate Agreement Terms</div>
                    
                    <div class="baa-terms">
                        <h3>1. Purpose</h3>
                        <p>This Business Associate Agreement ("Agreement") establishes the terms under which <?php echo htmlspecialchars($appName); ?> ("Business Associate") will handle Protected Health Information ("PHI") on behalf of your practice ("Covered Entity").</p>
                        
                        <h3>2. Definitions</h3>
                        <p>Terms used in this Agreement shall have the same meaning as defined in the HIPAA Rules (45 CFR Parts 160 and 164).</p>
                        
                        <h3>3. Obligations of Business Associate</h3>
                        <p>Business Associate agrees to:</p>
                        <ul>
                            <li>Not use or disclose PHI other than as permitted or required by this Agreement or as required by law</li>
                            <li>Use appropriate safeguards to prevent unauthorized use or disclosure of PHI</li>
                            <li>Report any unauthorized use or disclosure of PHI</li>
                            <li>Ensure subcontractors agree to the same restrictions</li>
                            <li>Make PHI available to individuals as required by HIPAA</li>
                            <li>Make records available to the Secretary of HHS for compliance review</li>
                            <li>Return or destroy PHI upon termination of this Agreement</li>
                        </ul>
                        
                        <h3>4. Permitted Uses and Disclosures</h3>
                        <p>Business Associate may use or disclose PHI:</p>
                        <ul>
                            <li>To perform services as specified in the Terms of Service</li>
                            <li>For proper management and administration of Business Associate</li>
                            <li>To provide data aggregation services</li>
                            <li>As required by law</li>
                        </ul>
                        
                        <h3>5. Security</h3>
                        <p>Business Associate maintains a signed Business Associate Agreement with Google Cloud Platform, where your data is stored. Business Associate implements industry-standard security measures including encryption in transit and at rest.</p>
                        
                        <h3>6. Breach Notification</h3>
                        <p>Business Associate will notify Covered Entity of any breach of unsecured PHI without unreasonable delay and no later than 60 days after discovery.</p>
                        
                        <h3>7. Term and Termination</h3>
                        <p>This Agreement is effective upon acceptance and continues until terminated. Upon termination, Business Associate will return or destroy all PHI, or if infeasible, extend protections indefinitely.</p>
                        
                        <h3>8. Governing Law</h3>
                        <p>This Agreement shall be governed by the laws of the State of Georgia and interpreted to permit compliance with HIPAA.</p>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="authorizedToBind" name="authorizedToBind">
                    <label for="authorizedToBind">
                        <strong><?php echo t('onboarding.baa.authorized_to_bind_label'); ?></strong> <?php echo t('onboarding.baa.authorized_to_bind_text'); ?>
                    </label>
                </div>
            </form>
            <?php endif; ?>
        </div>
        
        <div class="baa-footer" data-entitlement-blocked="<?php echo $entitlementBlocked ? '1' : '0'; ?>">
            <div class="baa-version"><?php echo $entitlementBlocked ? '' : t('onboarding.baa.version_label', ['version' => htmlspecialchars($baaVersion)]); ?></div>
            <div class="baa-actions">
                <?php if ($entitlementBlocked): ?>
                    <?php if ($cancelReturnPracticeId): ?>
                    <a href="api/select-practice.php?practice_id=<?php echo $cancelReturnPracticeId; ?>&redirect=1" class="btn btn-secondary"><?php echo t('onboarding.baa.back_to_practice'); ?></a>
                    <?php else: ?>
                    <a href="main.php" class="btn btn-secondary"><?php echo t('onboarding.baa.back_to_dashboard'); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($entitlement['upgrade_target']) && $upgradeUrl): ?>
                    <a href="<?php echo htmlspecialchars($upgradeUrl); ?>" class="btn btn-primary" id="upgradeBtn"><?php echo t('billing.upgrade.to_control', ['plan' => htmlspecialchars(getPlanDisplayName($entitlement['upgrade_target']))]); ?></a>
                    <?php else: ?>
                    <a href="mailto:feedback@dentatrak.com?subject=Practice%20limit%20reached" class="btn btn-primary" id="upgradeBtn"><?php echo t('onboarding.baa.contact_support'); ?></a>
                    <?php endif; ?>
                <?php elseif ($cancelReturnPracticeId): ?>
                <a href="api/select-practice.php?practice_id=<?php echo $cancelReturnPracticeId; ?>&redirect=1" class="btn btn-secondary"><?php echo t('common.cancel'); ?></a>
                <?php else: ?>
                <button type="button" class="btn btn-secondary" onclick="window.location.href='api/logout.php'"><?php echo t('onboarding.baa.sign_out'); ?></button>
                <?php endif; ?>
                <?php if (!$entitlementBlocked): ?>
                <button type="submit" form="baaForm" class="btn btn-primary" id="acceptBtn" disabled><?php echo t('onboarding.baa.accept_agreement'); ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="js/toast.js"></script>
    <?php require_once __DIR__ . '/api/auth-timeout-script.php'; ?>
    <script>
        var IS_CREATING_NEW_PRACTICE = <?php echo $isCreatingNewPractice ? 'true' : 'false'; ?>;
        var ENTITLEMENT_BLOCKED = <?php echo $entitlementBlocked ? 'true' : 'false'; ?>;
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Toast system
            if (typeof Toast !== 'undefined') {
                Toast.init();
            }
            if (ENTITLEMENT_BLOCKED) {
                // No form rendered in this state - nothing else to wire up.
                return;
            }
            const form = document.getElementById('baaForm');
            const acceptBtn = document.getElementById('acceptBtn');
            const authorizedCheckbox = document.getElementById('authorizedToBind');
            const errorMessage = document.getElementById('errorMessage');
            const successMessage = document.getElementById('successMessage');
            const loadingOverlay = document.getElementById('loadingOverlay');
            
            // Enable/disable accept button based on checkbox
            authorizedCheckbox.addEventListener('change', function() {
                acceptBtn.disabled = !this.checked;
            });
            
            // Clear error highlighting when users interact with fields
            const fieldIds = ['legalName', 'practiceAddress', 'signerName', 'signerTitle'];
            fieldIds.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                field.addEventListener('input', function() {
                    this.style.borderColor = '';
                    this.style.boxShadow = '';
                });
                field.addEventListener('focus', function() {
                    this.style.borderColor = '';
                    this.style.boxShadow = '';
                });
            });
            
            // Form validation with enhanced feedback
            function validateForm() {
                const fields = [
                    { id: 'legalName', name: t('onboarding.baa.practice_name_label'), value: document.getElementById('legalName').value.trim() },
                    { id: 'practiceAddress', name: t('onboarding.baa.practice_address_label'), value: document.getElementById('practiceAddress').value.trim() },
                    { id: 'signerName', name: t('onboarding.baa.signer_name_label'), value: document.getElementById('signerName').value.trim() },
                    { id: 'signerTitle', name: t('onboarding.baa.signer_title_label'), value: document.getElementById('signerTitle').value.trim() }
                ];
                
                // Clear previous error styles
                fields.forEach(field => {
                    const element = document.getElementById(field.id);
                    element.style.borderColor = '';
                    element.style.boxShadow = '';
                });
                
                // Check each required field
                for (const field of fields) {
                    if (!field.value) {
                        // Highlight the empty field
                        const element = document.getElementById(field.id);
                        element.style.borderColor = '#dc2626';
                        element.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.1)';
                        element.focus();
                        
                        return {
                            title: t('onboarding.baa.required_field_title'),
                            message: t('onboarding.baa.required_field_message', {field: field.name})
                        };
                    }
                }
                
                // Check authorization checkbox
                if (!authorizedCheckbox.checked) {
                    return {
                        title: t('onboarding.baa.authorization_required_title'),
                        message: t('onboarding.baa.authorization_required_message')
                    };
                }
                
                return null;
            }
            
            // Form submission
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const validationError = validateForm();
                if (validationError) {
                    if (typeof Toast !== 'undefined') {
                        Toast.error(validationError.title, validationError.message);
                    } else {
                        errorMessage.textContent = validationError.message;
                        errorMessage.style.display = 'block';
                    }
                    successMessage.style.display = 'none';
                    return;
                }
                
                errorMessage.style.display = 'none';
                loadingOverlay.style.display = 'flex';
                acceptBtn.disabled = true;
                
                const data = {
                    legalName: document.getElementById('legalName').value.trim(),
                    practiceAddress: document.getElementById('practiceAddress').value.trim(),
                    signerName: document.getElementById('signerName').value.trim(),
                    signerTitle: document.getElementById('signerTitle').value.trim(),
                    authorizedToBind: authorizedCheckbox.checked,
                    new: IS_CREATING_NEW_PRACTICE
                };
                
                try {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    const response = await fetch('api/accept-baa.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        if (typeof Toast !== 'undefined') {
                            Toast.success(t('onboarding.baa.accepted_toast_title'), t('onboarding.baa.accepted_toast_message'));
                        } else {
                            successMessage.textContent = t('onboarding.baa.accepted_toast_message');
                            successMessage.style.display = 'block';
                        }
                        
                        setTimeout(function() {
                            window.location.href = 'main.php';
                        }, 1500);
                    } else {
                        if (typeof Toast !== 'undefined') {
                            Toast.error(t('common.error'), result.message || t('common.unknown_error'));
                        } else {
                            errorMessage.textContent = result.message || t('common.unknown_error');
                            errorMessage.style.display = 'block';
                        }
                        loadingOverlay.style.display = 'none';
                        acceptBtn.disabled = !authorizedCheckbox.checked;
                    }
                } catch (error) {
                    console.error('Error:', error);
                    if (typeof Toast !== 'undefined') {
                        Toast.error(t('onboarding.baa.network_error_title'), t('onboarding.baa.network_error_message'));
                    } else {
                        errorMessage.textContent = t('onboarding.baa.network_error_message');
                        errorMessage.style.display = 'block';
                    }
                    loadingOverlay.style.display = 'none';
                    acceptBtn.disabled = !authorizedCheckbox.checked;
                }
            });
        });
    </script>
</body>
</html>
