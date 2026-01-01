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

// Check if user has any practices
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.baa_accepted 
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE pu.user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId]);
    $existingPractice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If user has no practices, redirect to BAA acceptance
    if (!$existingPractice) {
        header('Location: baa-acceptance.php');
        exit;
    }
    
    // If user has a practice but BAA not accepted, redirect to BAA acceptance
    if ($existingPractice && !$existingPractice['baa_accepted']) {
        $_SESSION['current_practice_id'] = $existingPractice['id'];
        header('Location: baa-acceptance.php');
        exit;
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
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.practice_id as uuid, pu.role, pu.is_owner
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE pu.user_id = :user_id
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

// Determine environment for visual cues
$envValue = $appConfig['environment'] ?? 'production';
$envClass = ($envValue === 'production') ? 'env-prod' : 'env-dev';
$appName = $appConfig['appName'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Practice Setup - <?php echo htmlspecialchars($appName); ?></title>
    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/practice-setup.css">
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
            <!-- Header -->
            <div class="setup-header">
                <div class="setup-icon">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
                <?php if ($showPracticeChoice): ?>
                    <h1 class="setup-title">You're Part of Multiple Practices</h1>
                    <p class="setup-subtitle">Select which practice you'd like to work with</p>
                <?php else: ?>
                    <h1 class="setup-title">Welcome!</h1>
                    <p class="setup-subtitle">Let's set up your dental practice</p>
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
                    <p>Welcome back, <strong><?php echo htmlspecialchars($userName ?: 'there'); ?></strong>! Select which practice you'd like to work with today.</p>
                </div>
                
                <!-- Owned Practices -->
                <?php if ($hasOwnPractice): ?>
                    <div class="section-header">
                        <h2>Your Practices</h2>
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
                                    Owner
                                </span>
                            </div>
                            <div class="practice-card-actions">
                                <button class="select-btn" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    Select This Practice
                                </button>
                                <div class="remember-choice">
                                    <input type="checkbox" id="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>" 
                                           class="remember-choice-checkbox" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <label for="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>">Always use this practice</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Member Practices -->
                <?php if ($hasMemberPractice): ?>
                    <div class="section-header" <?php echo $hasOwnPractice ? 'style="margin-top: 24px;"' : ''; ?>>
                        <h2><?php echo $hasOwnPractice ? 'Other Practices' : 'Your Practices'; ?></h2>
                        <span class="count-badge"><?php echo count($memberPractices); ?></span>
                    </div>
                    <?php foreach ($memberPractices as $practice): ?>
                        <div class="practice-card">
                            <div class="practice-card-header">
                                <div>
                                    <h3><?php echo htmlspecialchars($practice['practice_name']); ?></h3>
                                </div>
                                <span class="role-badge member"><?php echo htmlspecialchars($practice['role'] ?? 'Member'); ?></span>
                            </div>
                            <div class="practice-card-actions">
                                <button class="select-btn" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    Select This Practice
                                </button>
                                <div class="remember-choice">
                                    <input type="checkbox" id="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>" 
                                           class="remember-choice-checkbox" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                    <label for="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>">Always use this practice</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Create New Practice Option -->
                <div class="or-divider">
                    <span>Or create new</span>
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
                        <p>Start fresh with a new practice where you'll be the administrator.</p>
                    </div>
                    <form id="practiceSetupForm" class="setup-form">
                        <div class="form-group">
                            <label for="practiceName">Practice Name</label>
                            <input type="text" id="practiceName" name="practiceName" placeholder="e.g., Sunshine Dental Lab" required>
                        </div>
                        <button type="submit" class="submit-btn">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            Create New Practice
                        </button>
                    </form>
                </div>
            
            <?php elseif ($isInvited && count($invitedPractices) > 0): ?>
                <!-- Invited to practices -->
                <div class="welcome-banner">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <p>You've been invited to join a dental practice! Select it below or create your own.</p>
                </div>
                
                <div class="section-header">
                    <h2>Invitations</h2>
                    <span class="count-badge"><?php echo count($invitedPractices); ?></span>
                </div>
                
                <?php foreach ($invitedPractices as $practice): ?>
                    <div class="practice-card">
                        <div class="practice-card-header">
                            <div>
                                <h3><?php echo htmlspecialchars($practice['practice_name']); ?></h3>
                                <p class="owner-info">Owned by <?php echo htmlspecialchars($practice['owner_first_name'] . ' ' . $practice['owner_last_name']); ?></p>
                            </div>
                            <span class="role-badge member"><?php echo htmlspecialchars($practice['role'] ?? 'Member'); ?></span>
                        </div>
                        <div class="practice-card-actions">
                            <button class="select-btn" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                Join This Practice
                            </button>
                            <div class="remember-choice">
                                <input type="checkbox" id="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>" 
                                       class="remember-choice-checkbox" data-practice-id="<?php echo htmlspecialchars($practice['id']); ?>">
                                <label for="remember_choice_<?php echo htmlspecialchars($practice['id']); ?>">Always use this practice</label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="or-divider">
                    <span>Or create your own</span>
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
                        <p>Create your own practice where you'll be the administrator.</p>
                    </div>
                    <form id="practiceSetupForm" class="setup-form">
                        <div class="form-group">
                            <label for="practiceName">Practice Name</label>
                            <input type="text" id="practiceName" name="practiceName" placeholder="e.g., Sunshine Dental Lab" required>
                        </div>
                        <button type="submit" class="submit-btn">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            Create My Practice
                        </button>
                    </form>
                </div>
                
            <?php else: ?>
                <!-- First time user - create practice -->
                <div class="welcome-banner">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <p>You'll be the administrator of your practice and can invite team members later.</p>
                </div>
                
                <form id="practiceSetupForm" class="setup-form">
                    <div class="form-group">
                        <label for="practiceName">Practice Name</label>
                        <input type="text" id="practiceName" name="practiceName" placeholder="e.g., Sunshine Dental Lab" required autofocus>
                    </div>
                    <button type="submit" class="submit-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        Create My Practice
                    </button>
                </form>
            <?php endif; ?>
                
        <?php else: ?>
            <!-- No practices - show create form -->
            <div class="welcome-banner">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 16v-4"/>
                    <path d="M12 8h.01"/>
                </svg>
                <p>
                    <?php if ($isAdmin || $inAdminList): ?>
                        As an administrator, your practice name will be visible to all users in your organization.
                    <?php else: ?>
                        You'll be the administrator of your practice and can invite team members later.
                    <?php endif; ?>
                </p>
            </div>
            
            <form id="practiceSetupForm" class="setup-form">
                <div class="form-group">
                    <label for="practiceName">Dental Practice Name</label>
                    <input type="text" id="practiceName" name="practiceName" placeholder="e.g., Sunshine Dental Lab" required autofocus>
                </div>
                <button type="submit" class="submit-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    Create Practice
                </button>
            </form>
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
            // Handle practice setup form submission
            const practiceSetupForm = document.getElementById('practiceSetupForm');
            if (practiceSetupForm) {
                practiceSetupForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const practiceName = document.getElementById('practiceName').value.trim();
                    if (!practiceName) {
                        alert('Please enter a practice name');
                        return;
                    }
                    
                    // Show loading state
                    const submitBtn = practiceSetupForm.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><circle cx="12" cy="12" r="10"/></svg> Creating...';
                    
                    // Send API request to create practice
                    fetch('api/update-practice.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            practice_name: practiceName
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Select this practice automatically
                            selectPractice(data.practice.id);
                        } else {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                        alert('Error creating practice: ' + error);
                    });
                });
            }
            
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
                    this.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><circle cx="12" cy="12" r="10"/></svg> Please wait...';
                    
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
                        alert('Error: ' + (data.message || 'Could not select practice'));
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error in practice selection:', error);
                    if (practiceId) {
                        window.location.href = 'main.php';
                    } else {
                        alert('Error selecting practice. Please try again: ' + error.message);
                        location.reload();
                    }
                });
            }
        });
    </script>
</body>
</html>
