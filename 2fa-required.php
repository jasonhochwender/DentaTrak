<?php
/**
 * Practice 2FA Required - Blocking Enrollment/Challenge Page
 *
 * Landing page for authenticated users who tried to enter a practice that
 * requires two-factor authentication while their session has not satisfied
 * the requirement. Two flows live here:
 *
 *  - ENROLLMENT: the user has no authenticator configured -> run the
 *    existing TOTP setup (api/2fa-setup.php) inline.
 *  - CHALLENGE: the user has an authenticator but this session has not
 *    passed it (e.g. Remember Me restore, pre-existing session) -> verify
 *    a code via api/2fa-challenge.php.
 *
 * This page intentionally requires only an authenticated session - NOT a
 * valid practice context - so it cannot be blocked by the very enforcement
 * it exists to satisfy.
 */

require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
require_once __DIR__ . '/api/practice-security.php';
require_once __DIR__ . '/api/user-manager.php';
setSecurityHeaders();

$csrfToken = generateCsrfToken();

if (!isset($_SESSION['db_user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['db_user_id'];
$appName = $appConfig['appName'] ?? 'DentaTrak';

/**
 * Is the user an active member of an active practice? (read-only - does
 * NOT mutate session practice context; activation happens only after the
 * 2FA proof exists.)
 */
function twoFaRequiredMembershipOk($practiceId, $userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM practice_users pu
            JOIN practices p ON p.id = pu.practice_id
            JOIN users u ON u.id = pu.user_id
            WHERE pu.practice_id = :practice_id AND pu.user_id = :user_id
              AND u.is_active = 1
              AND (p.is_active = 1 OR p.is_active IS NULL)
            LIMIT 1
        ");
        $stmt->execute(['practice_id' => (int)$practiceId, 'user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[2fa-required] membership check failed: ' . $e->getMessage());
        return false;
    }
}

// An explicit ?practice_id=N target (chooser form, switch error, API
// redirect). Membership is verified server-side; a practice that does not
// require 2FA is activated immediately so this page never becomes an
// unnecessary step.
if (isset($_GET['practice_id']) && preg_match('/^[1-9][0-9]*$/D', (string)$_GET['practice_id'])) {
    $requestedPracticeId = (int)$_GET['practice_id'];
    if (twoFaRequiredMembershipOk($requestedPracticeId, $userId)) {
        if (practiceRequires2FA($requestedPracticeId)) {
            $_SESSION['pending_2fa_practice_id'] = $requestedPracticeId;
        } else {
            $activation = activatePracticeSession($requestedPracticeId);
            if ($activation['success']) {
                header('Location: main.php');
                exit;
            }
        }
    }
}

$pendingPracticeId = (int)($_SESSION['pending_2fa_practice_id'] ?? 0);

// Stale pending state: practice dropped the requirement or membership was
// removed - clear it so the user isn't trapped on this page.
if ($pendingPracticeId && (!practiceRequires2FA($pendingPracticeId) || !twoFaRequiredMembershipOk($pendingPracticeId, $userId))) {
    unset($_SESSION['pending_2fa_practice_id']);
    $pendingPracticeId = 0;
    if (!practiceRequires2FA((int)($_SESSION['current_practice_id'] ?? 0))) {
        header('Location: main.php');
        exit;
    }
}

// Session already satisfied - finish routing: activate the held practice
// if there is one, otherwise land wherever the session belongs.
if (session2FASatisfied()) {
    if ($pendingPracticeId) {
        $activation = activatePracticeSession($pendingPracticeId);
        unset($_SESSION['pending_2fa_practice_id']);
        if ($activation['success']) {
            header('Location: main.php');
            exit;
        }
        header('Location: practice-setup.php');
        exit;
    }
    header('Location: main.php');
    exit;
}

// Resolve display context for the page body.
$pendingPracticeName = '';
if ($pendingPracticeId) {
    try {
        $nameStmt = $pdo->prepare("SELECT COALESCE(NULLIF(display_name, ''), practice_name) FROM practices WHERE id = :id");
        $nameStmt->execute(['id' => $pendingPracticeId]);
        $pendingPracticeName = (string)$nameStmt->fetchColumn();
    } catch (PDOException $e) {
        $pendingPracticeName = '';
    }
}

// "Choose a different practice" only helps if the user actually has other
// practices to choose from.
$practiceCount = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM practice_users WHERE user_id = :user_id");
    $countStmt->execute(['user_id' => $userId]);
    $practiceCount = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    $practiceCount = 0;
}

$needsEnrollment = !userHas2FAConfigured($userId);

// Strings consumed by the inline script stay localized through t().
$pageStrings = [
    'verifying' => t('two_fa_required.verifying'),
    'unknown_error' => t('two_fa_required.errors.unknown'),
    'setup_failed' => t('two_fa_required.errors.setup_failed')
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getResolvedLocale() ?? 'en'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title><?php echo t('two_fa_required.title'); ?> - <?php echo htmlspecialchars($appName); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="stylesheet" href="css/app.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 50%, #1e3a5f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .tfa-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            max-width: 560px;
            width: 100%;
            overflow: hidden;
        }
        .tfa-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a87 100%);
            color: white;
            padding: 28px 32px;
            text-align: center;
        }
        .tfa-header .shield-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
        }
        .tfa-header .shield-icon svg { width: 28px; height: 28px; }
        .tfa-header h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 6px; }
        .tfa-header p { opacity: 0.9; font-size: 0.9rem; }
        .tfa-content { padding: 28px 32px 32px; }
        .tfa-intro {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 22px;
            color: #0369a1;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        .tfa-qr {
            display: flex;
            justify-content: center;
            margin: 16px 0;
        }
        .tfa-qr svg { max-width: 220px; height: auto; }
        .manual-entry {
            font-size: 0.85rem;
            color: #475569;
            text-align: center;
            word-break: break-all;
        }
        .manual-entry code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
        }
        .tfa-step { color: #334155; font-size: 0.92rem; line-height: 1.6; margin: 14px 0 8px; }
        .code-row {
            display: flex;
            gap: 10px;
            margin-top: 8px;
        }
        .code-row input {
            flex: 1;
            min-width: 0;
            padding: 12px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 1.1rem;
            letter-spacing: 4px;
            text-align: center;
            font-family: inherit;
        }
        .code-row input:focus {
            outline: none;
            border-color: #2d5a87;
            box-shadow: 0 0 0 3px rgba(45, 90, 135, 0.15);
        }
        .btn-primary {
            padding: 12px 20px;
            background: #2d5a87;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            font-family: inherit;
        }
        .btn-primary:hover { background: #1e3a5f; }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        .tfa-error {
            display: none;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.88rem;
            margin-top: 14px;
        }
        .tfa-footer {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px 20px;
            justify-content: center;
        }
        .tfa-footer a {
            color: #2d5a87;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .tfa-footer a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
            .tfa-header { padding: 22px 20px; }
            .tfa-content { padding: 22px 20px 26px; }
            .code-row { flex-direction: column; }
            .code-row input { text-align: center; }
        }
    </style>
</head>
<body>
    <div class="tfa-container">
        <div class="tfa-header">
            <div class="shield-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h1><?php echo t('two_fa_required.title'); ?></h1>
            <p><?php echo $pendingPracticeName !== ''
                ? t('two_fa_required.subtitle_practice', ['practice' => htmlspecialchars($pendingPracticeName)])
                : t('two_fa_required.subtitle'); ?></p>
        </div>
        <div class="tfa-content">
            <div class="tfa-intro">
                <?php echo $needsEnrollment
                    ? t('two_fa_required.intro_enroll')
                    : t('two_fa_required.intro_challenge'); ?>
            </div>

            <?php if ($needsEnrollment): ?>
            <div id="enrollFlow">
                <div id="enrollStart">
                    <p class="tfa-step"><?php echo t('two_fa_required.enroll.description'); ?></p>
                    <button type="button" id="enrollBeginBtn" class="btn-primary" style="width:100%;">
                        <?php echo t('two_fa_required.enroll.begin'); ?>
                    </button>
                </div>
                <div id="enrollVerify" style="display:none;">
                    <p class="tfa-step"><?php echo t('two_fa_required.enroll.step1'); ?></p>
                    <div class="tfa-qr" id="enrollQr"></div>
                    <p class="manual-entry"><?php echo t('two_fa_required.enroll.manual'); ?> <code id="enrollSecret"></code></p>
                    <p class="tfa-step"><?php echo t('two_fa_required.enroll.step2'); ?></p>
                    <div class="code-row">
                        <input type="text" id="enrollCode" maxlength="6" pattern="[0-9]*" inputmode="numeric"
                               autocomplete="one-time-code"
                               placeholder="<?php echo t('settings.security.two_factor.setup.placeholder'); ?>"
                               aria-label="<?php echo t('two_fa_required.enroll.code_label'); ?>">
                        <button type="button" id="enrollVerifyBtn" class="btn-primary">
                            <?php echo t('two_fa_required.enroll.verify'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div id="challengeFlow">
                <p class="tfa-step"><?php echo t('two_fa_required.challenge.description'); ?></p>
                <div class="code-row">
                    <input type="text" id="challengeCode" maxlength="6" pattern="[0-9]*" inputmode="numeric"
                           autocomplete="one-time-code"
                           placeholder="<?php echo t('settings.security.two_factor.setup.placeholder'); ?>"
                           aria-label="<?php echo t('two_fa_required.challenge.code_label'); ?>" autofocus>
                    <button type="button" id="challengeVerifyBtn" class="btn-primary">
                        <?php echo t('two_fa_required.challenge.verify'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div id="tfaError" class="tfa-error" role="alert"></div>

            <div class="tfa-footer">
                <?php if ($practiceCount > 1): ?>
                <a href="practice-setup.php"><?php echo t('two_fa_required.choose_practice'); ?></a>
                <?php endif; ?>
                <a href="api/logout.php"><?php echo t('two_fa_required.sign_out'); ?></a>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var strings = <?php echo json_encode($pageStrings, JSON_UNESCAPED_SLASHES); ?>;
        var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var errorBox = document.getElementById('tfaError');

        function showError(msg) {
            errorBox.textContent = msg || strings.unknown_error;
            errorBox.style.display = 'block';
        }
        function clearError() { errorBox.style.display = 'none'; }

        function postJson(url, body) {
            body = body || {};
            body.csrf_token = csrfToken;
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function(r) { return r.json(); });
        }

        // Enrollment: start -> QR/secret -> verify
        var enrollBeginBtn = document.getElementById('enrollBeginBtn');
        if (enrollBeginBtn) {
            enrollBeginBtn.addEventListener('click', function() {
                clearError();
                enrollBeginBtn.disabled = true;
                enrollBeginBtn.textContent = strings.verifying;
                postJson('api/2fa-setup.php?action=setup').then(function(data) {
                    if (data.success) {
                        document.getElementById('enrollStart').style.display = 'none';
                        document.getElementById('enrollVerify').style.display = 'block';
                        document.getElementById('enrollQr').innerHTML = data.qrCode || '';
                        document.getElementById('enrollSecret').textContent = data.secret || '';
                        document.getElementById('enrollCode').focus();
                    } else {
                        enrollBeginBtn.disabled = false;
                        enrollBeginBtn.textContent = <?php echo json_encode(t('two_fa_required.enroll.begin')); ?>;
                        showError(data.message || strings.setup_failed);
                    }
                }).catch(function() {
                    enrollBeginBtn.disabled = false;
                    showError(strings.unknown_error);
                });
            });

            var enrollVerifyBtn = document.getElementById('enrollVerifyBtn');
            var enrollCode = document.getElementById('enrollCode');
            function submitEnroll() {
                clearError();
                enrollVerifyBtn.disabled = true;
                postJson('api/2fa-setup.php?action=verify', { code: enrollCode.value.trim() }).then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        enrollVerifyBtn.disabled = false;
                        showError(data.message || strings.unknown_error);
                    }
                }).catch(function() {
                    enrollVerifyBtn.disabled = false;
                    showError(strings.unknown_error);
                });
            }
            enrollVerifyBtn.addEventListener('click', submitEnroll);
            enrollCode.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); submitEnroll(); }
            });
        }

        // Challenge: verify a code for this session
        var challengeBtn = document.getElementById('challengeVerifyBtn');
        if (challengeBtn) {
            var challengeCode = document.getElementById('challengeCode');
            function submitChallenge() {
                clearError();
                challengeBtn.disabled = true;
                postJson('api/2fa-challenge.php', { code: challengeCode.value.trim() }).then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        challengeBtn.disabled = false;
                        showError(data.message || strings.unknown_error);
                    }
                }).catch(function() {
                    challengeBtn.disabled = false;
                    showError(strings.unknown_error);
                });
            }
            challengeBtn.addEventListener('click', submitChallenge);
            challengeCode.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); submitChallenge(); }
            });
        }
    })();
    </script>
</body>
</html>
