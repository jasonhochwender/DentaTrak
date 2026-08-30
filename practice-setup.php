<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/user-manager.php';
require_once __DIR__ . '/api/security-headers.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set security headers
setSecurityHeaders();

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    header('Location: index.php');
    exit;
}

// NEW: Redirect new users directly to BAA acceptance page
// The BAA acceptance page now handles practice creation
$userId = $_SESSION['db_user_id'];

// Check if user has any practices. The BAA represents CREATING a practice,
// never merely joining one someone else already created and accepted BAA
// for - so this check must only ever redirect into the BAA flow for (a) a
// user with no practices at all (creating their first practice), or (b)
// a practice the user actually OWNS that was created but never finished
// BAA acceptance. Being a MEMBER of another practice whose BAA is already
// accepted must fall through to the practice chooser below instead.
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.baa_accepted, pu.is_owner
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE pu.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $allMemberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If user has no practices at all, they need to create their first one.
    if (empty($allMemberships)) {
        header('Location: baa-acceptance.php');
        exit;
    }
    
    // Resume BAA acceptance only for a practice this user OWNS and hasn't
    // finished accepting the BAA for - never for a practice they merely
    // belong to as a member/admin of someone else's already-accepted practice.
    foreach ($allMemberships as $membership) {
        if (!empty($membership['is_owner']) && empty($membership['baa_accepted'])) {
            $_SESSION['current_practice_id'] = $membership['id'];
            header('Location: baa-acceptance.php');
            exit;
        }
    }
} catch (PDOException $e) {
    // If baa_accepted column doesn't exist, continue with normal flow
    // This handles the case before migration is run
    if (strpos($e->getMessage(), 'baa_accepted') === false) {
        error_log("Error checking BAA status: " . $e->getMessage());
    }
}

// Add redirect loop prevention counter
if (!isset($_SESSION['practice_setup_visits'])) {
    $_SESSION['practice_setup_visits'] = 1;
} else {
    $_SESSION['practice_setup_visits']++;
}

// If we detect a redirect loop (more than 3 visits), reset flags and show setup page
if ($_SESSION['practice_setup_visits'] > 3) {
    // Reset problematic flags
    $_SESSION['needs_practice_setup'] = true;
    $_SESSION['needs_practice_selection'] = false;
    $_SESSION['has_multiple_practices'] = false;
    unset($_SESSION['current_practice_id']);
    
    // Log the redirect loop detection
    error_log("Detected possible redirect loop in practice setup. Resetting flags.");
}

// Get user information
$userId = $_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$userName = $_SESSION['user_name'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'user';
$isAdmin = ($userRole === 'admin');

// Check if this is their first login
$isFirstLogin = isset($_SESSION['first_login']) && $_SESSION['first_login'];

// Check if they are in admin lists
global $appConfig;
$inAdminList = false;
if ($userEmail) {
    $powerUsers = $appConfig['powerUsers'] ?? [];
    $admins = $appConfig['admins'] ?? [];
    $inAdminList = in_array($userEmail, $powerUsers) || in_array($userEmail, $admins);
}

// Get the user's practices
try {
    // Deterministic ordering: owned practices first, then alphabetically by
    // name - matches resolveLoginPracticeSelection() in user-manager.php.
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.practice_id as uuid, pu.role, pu.is_owner
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE pu.user_id = :user_id
        ORDER BY pu.is_owner DESC, p.practice_name ASC
    ");
    $stmt->execute(['user_id' => $userId]);
    $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $practices = [];
    error_log("Error fetching practices: " . $e->getMessage());
}

// Check if user has been invited to any practices
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.practice_id as uuid, 
               u.email as owner_email, u.first_name as owner_first_name, u.last_name as owner_last_name
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        JOIN users u ON p.created_by = u.id
        WHERE pu.user_id = :user_id AND pu.is_owner = FALSE
        ORDER BY p.practice_name ASC
    ");
    $stmt->execute(['user_id' => $userId]);
    $invitedPractices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $invitedPractices = [];
    error_log("Error fetching invited practices: " . $e->getMessage());
}

$hasPractices = !empty($practices);
$isInvited = !empty($invitedPractices);
$showPracticeChoice = $isInvited || count($practices) > 1;

// A user can be a MEMBER of a practice (invited by someone else) without
// ever having OWNED a practice of their own. Those are independent
// concepts: being invited into Verrillo Dental should never by itself
// prevent someone from also creating and owning their own practice. When
// the only practice a user belongs to is one they don't own, tailor the
// copy below to make both options ("continue" vs "create your own")
// equally visible, rather than reusing generic multi-practice wording.
$ownsAnyPractice = false;
foreach ($practices as $p) {
    if (!empty($p['is_owner'])) {
        $ownsAnyPractice = true;
        break;
    }
}
$isSingleUnownedMembership = ($showPracticeChoice && count($practices) === 1 && !$ownsAnyPractice);

// Determine environment for visual cues
$envValue = $appConfig['environment'] ?? 'production';
$envClass = ($envValue === 'production') ? 'env-prod' : 'env-dev';
$appName = $appConfig['appName'];
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo t('onboarding.practice.page_title'); ?> - <?php echo htmlspecialchars($appName); ?></title>

    <!-- Favicon / App Icons -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/practice-setup.css">
    <script>
        window.__i18n = <?php echo getTranslationsJsonForJs(); ?>;
    </script>
    <script src="js/i18n.js"></script>
</head>
<body class="practice-setup-body <?php echo $envClass; ?>">
    <!-- Animated Background -->
    <div class="setup-bg">
        <div class="bg-shape bg-shape-1"></div>
        <div class="bg-shape bg-shape-2"></div>
        <div class="bg-shape bg-shape-3"></div>
    </div>
    
    <div class="setup-wrapper">
        <div class="setup-container">
            <?php
            // Global language selector (hidden until a second locale is enabled)
            echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false);
            ?>
            <!-- Header -->
            <div class="setup-header">
                <div class="setup-icon">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
                <?php if ($isSingleUnownedMembership): ?>
                    <h1 class="setup-title"><?php echo t('onboarding.practice.welcome_title'); ?></h1>
                    <p class="setup-subtitle"><?php echo t('onboarding.practice.welcome_subtitle_single_unowned'); ?></p>
                <?php elseif ($showPracticeChoice): ?>
                    <h1 class="setup-title"><?php echo t('onboarding.practice.welcome_title'); ?></h1>
                    <p class="setup-subtitle"><?php echo t('onboarding.practice.welcome_subtitle_multiple'); ?></p>
                <?php else: ?>
                    <h1 class="setup-title"><?php echo t('auth.login.welcome'); ?></h1>
                    <p class="setup-subtitle"><?php echo t('onboarding.practice.welcome_subtitle_new'); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="setup-content">
        <?php if ($showPracticeChoice): ?>
            <?php 
            // Get all practices the user is a part of
            $userPractices = $_SESSION['available_practices'] ?? $practices;
            
            // Check if user has practices they own vs ones they're invited to
            $ownedPractices = [];
            $memberPractices = [];
            
            foreach ($userPractices as $practice) {
                if (isset($practice['is_owner']) && $practice['is_owner']) {
                    $ownedPractices[] = $practice;
                } else {
                    $memberPractices[] = $practice;
                }
            }
            
            $hasOwnPractice = !empty($ownedPractices);
            $hasMemberPractice = !empty($memberPractices);
            ?>
            
            <?php if ($hasMemberPractice || $hasOwnPractice): ?>
                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 16v-4"/>
                        <path d="M12 8h.01"/>
                    </svg>
                    <?php if ($isSingleUnownedMembership && !empty($memberPractices)): ?>
                    <p><?php echo t('onboarding.practice.welcome_banner_name', ['name' => htmlspecialchars($userName ?: 'there')]); ?> <?php echo t('onboarding.practice.single_unowned_continue', ['practice' => htmlspecialchars($memberPractices[0]['practice_name'])]); ?></p>
                    <?php else: ?>
                    <p><?php echo t('onboarding.practice.welcome_back_name', ['name' => htmlspecialchars($userName ?: 'there')]); ?> <?php echo t('onboarding.practice.select_today'); ?></p>
                    <?php endif; ?>
                </div>
                
                <!-- Owned Practices -->
                <?php if ($hasOwnPractice): ?>
                    <div class="section-header">
                        <h2><?php echo t('onboarding.practice.your_practices'); ?></h2>
                        <span class="count-badge"><?php echo count($ownedPractices); ?></span>
                    </div>
                    <?php foreach ($ownedPractices as $practice): ?>
                        <div class="practice-card">
                            <div class="practice-card-header">
                                <div>
                                    <h3><?php echo htmlspecialchars($practice['practice_name']); ?></h3>
                                </div>
                                <span class="role-badge owner">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
                                    <?php echo t('onboarding.practice.role_owner'); ?>
                                </span>
                            </div>
                            <div class="practice-card-actions">
                                <button class="select-btn" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    <?php echo t('onboarding.practice.select_this_practice'); ?>
                                </button>
                                <div class="remember-choice">
                                    <input type="checkbox" id="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>" 
                                           class="remember-choice-checkbox" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <label for="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>"><?php echo t('onboarding.practice.always_use_this_practice'); ?></label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Member Practices -->
                <?php if ($hasMemberPractice): ?>
                    <?php if (!$isSingleUnownedMembership): ?>
                    <div class="section-header" <?php echo $hasOwnPractice ? 'style="margin-top: 24px;"' : ''; ?>>
                        <h2><?php echo $hasOwnPractice ? t('onboarding.practice.other_practices') : t('onboarding.practice.your_practices'); ?></h2>
                        <span class="count-badge"><?php echo count($memberPractices); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($memberPractices as $practice): ?>
                        <div class="practice-card">
                            <div class="practice-card-header">
                                <div>
                                    <h3><?php echo htmlspecialchars($practice['practice_name']); ?></h3>
                                </div>
                                <span class="role-badge member"><?php echo htmlspecialchars($practice['role'] ?? t('onboarding.practice.role_member')); ?></span>
                            </div>
                            <div class="practice-card-actions">
                                <button class="select-btn" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    <?php echo $isSingleUnownedMembership
                                        ? t('onboarding.practice.continue_to_practice', ['practice' => htmlspecialchars($practice['practice_name'])])
                                        : t('onboarding.practice.select_this_practice'); ?>
                                </button>
                                <div class="remember-choice">
                                    <input type="checkbox" id="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>" 
                                           class="remember-choice-checkbox" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <label for="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>"><?php echo t('onboarding.practice.always_use_this_practice'); ?></label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Create New Practice Option -->
                <div class="or-divider">
                    <span><?php echo $isSingleUnownedMembership ? t('onboarding.practice.or_create_your_own') : t('onboarding.practice.or_create_new'); ?></span>
                </div>
                
                <div class="practice-card create-practice-card">
                    <div class="card-intro">
                        <div class="card-intro-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="16"/>
                                <line x1="8" y1="12" x2="16" y2="12"/>
                            </svg>
                        </div>
                        <p><?php echo t('onboarding.practice.create_practice_intro'); ?></p>
                    </div>
                    <!-- Creating a practice always goes through the BAA flow (baa-acceptance.php
                         collects the practice's legal name and creates it atomically with BAA
                         acceptance) - the same flow a brand new user goes through, never the
                         bare update-practice.php shortcut. ?new=1 ensures this always starts a
                         fresh practice even if a stale current_practice_id lingers in session. -->
                    <a href="baa-acceptance.php?new=1" class="submit-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        <?php echo $isSingleUnownedMembership ? t('onboarding.practice.create_my_own_practice') : t('onboarding.practice.create_new_practice'); ?>
                    </a>
                </div>
            
            <?php elseif ($isInvited && count($invitedPractices) > 0): ?>
                <!-- Invited to practices -->
                <div class="welcome-banner">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <p><?php echo t('onboarding.practice.invited_message'); ?></p>
                </div>
                
                <div class="section-header">
                    <h2><?php echo t('onboarding.practice.invitations'); ?></h2>
                    <span class="count-badge"><?php echo count($invitedPractices); ?></span>
                </div>
                
                <?php foreach ($invitedPractices as $practice): ?>
                    <div class="practice-card">
                        <div class="practice-card-header">
                            <div>
                                <h3><?php echo htmlspecialchars($practice['practice_name']); ?></h3>
                                <p class="owner-info"><?php echo t('onboarding.practice.owned_by', ['name' => htmlspecialchars($practice['owner_first_name'] . ' ' . $practice['owner_last_name'])]); ?></p>
                            </div>
                            <span class="role-badge member"><?php echo htmlspecialchars($practice['role'] ?? t('onboarding.practice.role_member')); ?></span>
                        </div>
                        <div class="practice-card-actions">
                            <button class="select-btn" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <?php echo t('onboarding.practice.join_this_practice'); ?>
                            </button>
                            <div class="remember-choice">
                                <input type="checkbox" id="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>" 
                                       class="remember-choice-checkbox" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                <label for="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>"><?php echo t('onboarding.practice.always_use_this_practice'); ?></label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="or-divider">
                    <span><?php echo t('onboarding.practice.or_create_your_own'); ?></span>
                </div>
                
                <div class="practice-card create-practice-card">
                    <div class="card-intro">
                        <div class="card-intro-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="16"/>
                                <line x1="8" y1="12" x2="16" y2="12"/>
                            </svg>
                        </div>
                        <p><?php echo t('onboarding.practice.create_practice_intro'); ?></p>
                    </div>
                    <!-- Practice creation always goes through the BAA flow - see the
                         "Create New Practice Option" comment above. Never
                         api/update-practice.php, which no longer creates practices. -->
                    <a href="baa-acceptance.php?new=1" class="submit-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        <?php echo t('onboarding.practice.create_my_practice'); ?>
                    </a>
                </div>
                
            <?php else: ?>
                <!-- First time user - create practice -->
                <div class="welcome-banner">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <p><?php echo t('onboarding.practice.create_practice_info'); ?></p>
                </div>
                
                <a href="baa-acceptance.php?new=1" class="submit-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    <?php echo t('onboarding.practice.create_my_practice'); ?>
                </a>
            <?php endif; ?>
                
        <?php else: ?>
            <!-- No practices - show create form. In practice this branch is
                 unreachable: the redirect at the top of this file already
                 sends a user with zero practices straight to
                 baa-acceptance.php. Kept as a safety-net link (never a form
                 posting to api/update-practice.php, which no longer creates
                 practices) in case that redirect's precondition ever changes. -->
            <div class="welcome-banner">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 16v-4"/>
                    <path d="M12 8h.01"/>
                </svg>
                <p>
                    <?php if ($isAdmin || $inAdminList): ?>
                        <?php echo t('onboarding.practice.admin_create_practice_info'); ?>
                    <?php else: ?>
                        <?php echo t('onboarding.practice.create_practice_info'); ?>
                    <?php endif; ?>
                </p>
            </div>
            
            <a href="baa-acceptance.php?new=1" class="submit-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                <?php echo t('onboarding.practice.create_practice'); ?>
            </a>
        <?php endif; ?>
            </div>
            
            <!-- Footer -->
            <div class="setup-footer">
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.</p>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Practice creation is now a plain link to baa-acceptance.php?new=1
            // (see the markup above) rather than a form posted to
            // api/update-practice.php, so there is no practice-creation
            // submit handler here anymore.
            
            // Handle practice selection
            const selectButtons = document.querySelectorAll('.select-btn');
            
            selectButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const practiceId = this.getAttribute('data-practice-id');
                    const checkboxId = 'remember_choice_' + practiceId;
                    const rememberCheckbox = document.getElementById(checkboxId);
                    const savePreference = rememberCheckbox && rememberCheckbox.checked;
                    
                    // Show loading state
                    const originalText = this.innerHTML;
                    this.disabled = true;
                    this.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><circle cx="12" cy="12" r="10"/></svg> ' + t('onboarding.practice.please_wait');
                    
                    selectPractice(practiceId, savePreference);
                });
            });
            
            function selectPractice(practiceId, savePreference) {
                console.log('Selecting practice ID:', practiceId, 'Save preference:', savePreference);
                
                // Direct redirect approach - more reliable
                if (practiceId) {
                    window.location.href = `api/select-practice.php?practice_id=${practiceId}&remember=${savePreference ? 1 : 0}&redirect=1`;
                    return;
                }
                
                // Fetch API approach - as fallback
                fetch('api/select-practice.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        practice_id: practiceId,
                        remember_preference: savePreference
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        window.location.href = 'main.php';
                    } else {
                        alert(t('onboarding.practice.could_not_select') + (data.message ? ': ' + data.message : ''));
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error in practice selection:', error);
                    if (practiceId) {
                        window.location.href = 'main.php';
                    } else {
                        alert(t('onboarding.practice.select_error_message', {message: error.message}));
                        location.reload();
                    }
                });
            }
        });
    </script>
</body>
</html>
