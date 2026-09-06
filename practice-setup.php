<?php
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/user-manager.php';
require_once __DIR__ . '/api/security-headers.php';
require_once __DIR__ . '/api/csrf.php';

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
$userStatus = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
$userStatus->execute([$userId]);
if (!$userStatus->fetchColumn()) {
    header('Location: api/logout.php');
    exit;
}
$csrfToken = generateCsrfToken();

// Check if user has any practices. The BAA represents CREATING a practice,
// never merely joining one someone else already created and accepted BAA
// for - so this check must only ever redirect into the BAA flow for (a) a
// user with no practices at all (creating their first practice), or (b)
// a practice the user actually OWNS that was created but never finished
// BAA acceptance. Being a MEMBER of another practice whose BAA is already
// accepted must fall through to the practice chooser below instead.
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.baa_accepted, p.is_active, pu.is_owner
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
        if (($membership['is_active'] ?? true) && !empty($membership['is_owner']) && empty($membership['baa_accepted'])) {
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

// Get the user's practices
$practiceLoadFailed = false;
try {
    // Deterministic ordering: owned practices first, then alphabetically by
    // name - matches resolveLoginPracticeSelection() in user-manager.php.
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.practice_id as uuid, pu.role, pu.is_owner,
               IFNULL(pu.limited_visibility, 0) AS limited_visibility
        FROM practices p
        JOIN practice_users pu ON p.id = pu.practice_id
        WHERE pu.user_id = :user_id AND (p.is_active = 1 OR p.is_active IS NULL)
        ORDER BY pu.is_owner DESC, p.practice_name ASC
    ");
    $stmt->execute(['user_id' => $userId]);
    $practices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $practices = [];
    $practiceLoadFailed = true;
    error_log("Error fetching practices: " . $e->getMessage());
}

// Check if user has practices they own vs ones they're invited to
$practiceGroups = ['owned' => [], 'shared' => []];
foreach ($practices as $practice) {
    $practiceGroups[!empty($practice['is_owner']) ? 'owned' : 'shared'][] = $practice;
}

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
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo t('onboarding.practice.choose_title'); ?> - <?php echo htmlspecialchars($appName); ?></title>

    <!-- Favicon / App Icons -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/practice-setup.css?v=20260906a">
    <?php require_once __DIR__ . '/api/auth-timeout-script.php'; ?>
</head>
<body class="practice-setup-body <?php echo $envClass; ?>">
    <!-- Animated Background -->
    <div class="setup-bg" aria-hidden="true">
        <div class="bg-shape bg-shape-1"></div>
        <div class="bg-shape bg-shape-2"></div>
        <div class="bg-shape bg-shape-3"></div>
    </div>

    <main class="setup-wrapper">
        <div class="setup-container">
            <?php
            // Global language selector (hidden until a second locale is enabled)
            echo renderLanguageSelector('api/set-session-locale.php', getResolvedLocale(), false);
            ?>
            <!-- Header -->
            <div class="setup-header">
                <img class="setup-logo" src="images/main.png" alt="" aria-hidden="true" width="140" height="48">
                <h1 class="setup-title"><?php echo t('onboarding.practice.choose_title'); ?></h1>
                <p class="setup-subtitle"><?php echo t('onboarding.practice.choose_subtitle'); ?></p>
            </div>

            <div class="setup-content">
                <form id="practiceSelectionForm" action="api/select-practice.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($practices): ?>
                    <label class="remember-choice" for="rememberPractice">
                        <input type="checkbox" id="rememberPractice" name="remember_preference" value="1">
                        <span><?php echo t('onboarding.practice.remember_next'); ?></span>
                    </label>
                    <?php endif; ?>
                    <p id="selectionStatus" class="selection-status" role="status" aria-live="polite" aria-atomic="true"></p>
                    <p id="selectionError" class="selection-error" role="alert" aria-atomic="true"><?php echo $practiceLoadFailed ? t('onboarding.practice.load_error') : ''; ?></p>
                    <div class="practice-list">
                        <?php foreach ($practiceGroups as $group => $groupPractices): ?>
                        <?php if (!$groupPractices) continue; ?>
                        <section class="practice-group" aria-labelledby="<?php echo $group; ?>-heading">
                            <h2 id="<?php echo $group; ?>-heading"><?php echo t('onboarding.practice.group_' . $group); ?></h2>
                            <ul>
                                <?php foreach ($groupPractices as $practice): ?>
                                <?php
                                $role = $practice['role'];
                                $roleLabel = !empty($practice['is_owner']) ? t('onboarding.practice.role_owner')
                                    : ($role === 'admin' ? t('onboarding.practice.role_administrator')
                                    : ($role === 'user' ? t('onboarding.practice.role_user') : ucfirst(str_replace('_', ' ', $role))));
                                if (!empty($practice['limited_visibility'])) {
                                    $assignedOnly = t('onboarding.practice.role_assigned_only');
                                    $roleLabel = $role === 'user' && empty($practice['is_owner']) ? $assignedOnly : $roleLabel . ' · ' . $assignedOnly;
                                }
                                ?>
                                <li>
                                    <button type="submit" class="practice-row" name="practice_id" value="<?php echo (int)$practice['id']; ?>">
                                        <span class="practice-name"><?php echo htmlspecialchars($practice['practice_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="role-badge"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <svg class="practice-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg>
                                    </button>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                        <?php endforeach; ?>
                        <?php if (!$practices && !$practiceLoadFailed): ?>
                        <p class="empty-practices"><?php echo t('onboarding.practice.no_practices_title'); ?></p>
                        <?php endif; ?>
                    </div>
                </form>
                <!-- Create New Practice Option -->
                <div class="create-practice-action">
                    <!-- Creating a practice always goes through the BAA flow (baa-acceptance.php
                         collects the practice's legal name and creates it atomically with BAA
                         acceptance) - the same flow a brand new user goes through, never the
                         bare update-practice.php shortcut. ?new=1 ensures this always starts a
                         fresh practice even if a stale current_practice_id lingers in session. -->
                    <a href="baa-acceptance.php?new=1" class="create-practice-link">
                        <span aria-hidden="true">+</span>
                        <?php echo t('onboarding.practice.create_new_action'); ?>
                    </a>
                </div>
            </div>

            <!-- Footer -->
            <div class="setup-footer">
                <a href="api/logout.php" class="sign-out-link"><?php echo t('onboarding.practice.sign_out'); ?></a>
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.</p>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Practice creation is now a plain link to baa-acceptance.php?new=1
            // (see the markup above) rather than a form posted to
            // api/update-practice.php, so there is no practice-creation
            // submit handler here anymore.

            // Handle practice selection
            const form = document.getElementById('practiceSelectionForm');
            const checkbox = document.getElementById('rememberPractice');
            const status = document.getElementById('selectionStatus');
            const error = document.getElementById('selectionError');
            const rows = form.querySelectorAll('.practice-row');
            let selecting = false;

            form.addEventListener('submit', async function(event) {
                event.preventDefault();
                const button = event.submitter;
                if (selecting || !button || !button.matches('.practice-row')) return;
                const remember = !!checkbox?.checked;

                // Show loading state
                selecting = true;
                error.textContent = '';
                status.textContent = <?php echo json_encode(t('onboarding.practice.please_wait')); ?>;
                form.setAttribute('aria-busy', 'true');
                rows.forEach(row => { row.disabled = true; });
                if (checkbox) checkbox.disabled = true;

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-Token': form.elements.csrf_token.value
                        },
                        body: JSON.stringify({ practice_id: button.value, remember_preference: remember })
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success || (remember && data.preference_saved !== true)) {
                        throw new Error(data.message || <?php echo json_encode(t('onboarding.practice.selection_failed')); ?>);
                    }
                    window.location.assign('main.php');
                } catch (failure) {
                    status.textContent = '';
                    error.textContent = failure instanceof SyntaxError || failure instanceof TypeError
                        ? <?php echo json_encode(t('onboarding.practice.selection_failed')); ?> : failure.message;
                    selecting = false;
                    form.removeAttribute('aria-busy');
                    rows.forEach(row => { row.disabled = false; });
                    if (checkbox) checkbox.disabled = false;
                    button.focus();
                }
            });
        });
    </script>
</body>
</html>
