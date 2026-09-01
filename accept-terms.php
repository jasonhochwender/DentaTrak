<?php
/**
 * Updated Terms Acceptance Page
 *
 * Shown to existing Practice owners and administrators on their next
 * authenticated visit when the current Terms of Service have not yet been
 * accepted. Non-administrator invited users are not blocked from urgent case
 * access by this page.
 */

require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/practice-security.php';
require_once __DIR__ . '/api/security-headers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

setSecurityHeaders();

$loginUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/login.php';
$acceptApiUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/api/accept-terms.php';
$mainUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/main.php';
$termsUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/terms.php';
$privacyUrl = rtrim($appConfig['baseUrl'] ?? '', '/') . '/privacy.php';
$termsVersion = currentTermsVersion();
$termsDisplayDate = date('F j, Y', strtotime($termsVersion));

function getSafeReturnUrl(string $returnTo, string $baseUrl): string {
    $main = rtrim($baseUrl, '/') . '/main.php';
    if (empty($returnTo)) {
        return $main;
    }
    $decoded = rawurldecode($returnTo);
    if (str_starts_with($decoded, '//') || preg_match('#^[a-zA-Z]+://#', $decoded) || preg_match('#^https?:#i', $decoded)) {
        return $main;
    }
    $parsed = parse_url($returnTo);
    if (!empty($parsed['host'])) {
        $baseParsed = parse_url($baseUrl);
        $allowedHost = $baseParsed['host'] ?? '';
        if (($parsed['host'] ?? '') !== $allowedHost) {
            return $main;
        }
    }
    $path = $parsed['path'] ?? $returnTo;
    if (str_starts_with($path, '/')) {
        return rtrim($baseUrl, '/') . $path . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    }
    return rtrim($baseUrl, '/') . '/' . $path . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
}

$returnTo = getSafeReturnUrl($_GET['return_to'] ?? '', $appConfig['baseUrl'] ?? '');

// Preserve login, logout, terms, privacy, support, and acceptance endpoint access.
$safePages = ['logout', 'terms', 'privacy', 'support', 'accept-terms'];
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$currentBase = basename($currentPath);

if (empty($_SESSION['db_user_id'])) {
    header('Location: ' . $loginUrl);
    exit;
}

$userId = (int)$_SESSION['db_user_id'];

// Only owners and administrators must re-accept updated Terms.
if (!isOwnerOrAdminOfAnyPractice($userId)) {
    header('Location: main.php');
    exit;
}

if (hasAcceptedCurrentTerms($userId)) {
    $alreadyAccepted = true;
} else {
    $alreadyAccepted = false;
    $csrfToken = generateCsrfToken();
}

$appName = $appConfig['appName'] ?? 'DentaTrak';
?><!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
    <meta name="terms-version" content="<?php echo htmlspecialchars($termsVersion); ?>">
    <meta name="terms-accept-api" content="<?php echo htmlspecialchars($acceptApiUrl); ?>">
    <meta name="terms-return-to" content="<?php echo htmlspecialchars($returnTo); ?>">
    <title><?php echo htmlspecialchars(t('terms.page_title')) . ' - ' . htmlspecialchars($appName); ?></title>
    <link rel="stylesheet" href="css/app.css">
    <script>
        window.__i18n = <?php echo getTranslationsJsonForJs(); ?>;
    </script>
    <script src="js/i18n.js"></script>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f7fa;
            margin: 0;
            padding: 0;
        }
        .terms-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .terms-card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .terms-card h1 {
            color: #2563eb;
            margin-top: 0;
        }
        .terms-card p {
            margin-bottom: 16px;
        }
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            margin: 24px 0;
        }
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
        }
        .checkbox-group label {
            font-size: 0.95rem;
            color: #92400e;
            cursor: pointer;
            line-height: 1.5;
        }
        .actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 24px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
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
            display: none;
        }
        .success-message {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: none;
        }
    </style>
</head>
<body>
    <div class="terms-container">
        <div class="terms-card">
            <?php if ($alreadyAccepted): ?>
                <h1><?php echo t('terms.already_accepted_title'); ?></h1>
                <p><?php echo t('terms.already_accepted_message', ['date' => $termsDisplayDate, 'terms' => t('terms.review_terms'), 'privacy' => t('terms.review_privacy')]); ?></p>
                <div class="actions">
                    <a href="<?php echo htmlspecialchars($returnTo); ?>" class="btn btn-primary"><?php echo t('terms.continue'); ?></a>
                    <a href="logout.php" class="btn btn-secondary"><?php echo t('terms.sign_out'); ?></a>
                </div>
            <?php else: ?>
                <h1><?php echo t('terms.updated_title'); ?></h1>
                <p><?php echo t('terms.updated_intro'); ?></p>
                <ul>
                    <li><a href="<?php echo htmlspecialchars($termsUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo t('terms.review_terms'); ?></a> (<?php echo t('terms.effective', ['date' => $termsDisplayDate]); ?>)</li>
                    <li><a href="<?php echo htmlspecialchars($privacyUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo t('terms.review_privacy'); ?></a> (<?php echo t('terms.effective', ['date' => $termsDisplayDate]); ?>)</li>
                </ul>
                <p><?php echo t('terms.baa_note'); ?></p>

                <div id="errorMessage" class="error-message"></div>
                <div id="successMessage" class="success-message"></div>

                <div class="checkbox-group">
                    <input type="checkbox" id="termsAccepted" value="1">
                    <label for="termsAccepted">
                        <?php echo t('terms.checkbox_label', ['terms' => '<a href="' . htmlspecialchars($termsUrl) . '" target="_blank" rel="noopener noreferrer">' . t('terms.review_terms') . '</a>', 'privacy' => '<a href="' . htmlspecialchars($privacyUrl) . '" target="_blank" rel="noopener noreferrer">' . t('terms.review_privacy') . '</a>']); ?>
                    </label>
                </div>

                <div class="actions">
                    <a href="logout.php" class="btn btn-secondary"><?php echo t('terms.sign_out'); ?></a>
                    <button id="acceptBtn" class="btn btn-primary" disabled><?php echo t('terms.accept_button'); ?></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$alreadyAccepted): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkbox = document.getElementById('termsAccepted');
            const acceptBtn = document.getElementById('acceptBtn');
            const errorMessage = document.getElementById('errorMessage');
            const successMessage = document.getElementById('successMessage');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const apiUrl = document.querySelector('meta[name="terms-accept-api"]')?.content || 'api/accept-terms.php';
            const termsVersion = document.querySelector('meta[name="terms-version"]')?.content || '';
            const returnTo = document.querySelector('meta[name="terms-return-to"]')?.content || 'main.php';

            checkbox.addEventListener('change', function() {
                acceptBtn.disabled = !this.checked;
            });

            acceptBtn.addEventListener('click', async function() {
                if (!checkbox.checked) {
                    return;
                }

                acceptBtn.disabled = true;

                try {
                    const response = await fetch(apiUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({ accepted: true, terms_version: termsVersion })
                    });

                    const result = await response.json();

                    if (result.success) {
                        successMessage.textContent = t('terms.success');
                        successMessage.style.display = 'block';
                        errorMessage.style.display = 'none';
                        window.location.href = returnTo;
                    } else {
                        errorMessage.textContent = result.message || t('terms.generic_error');
                        errorMessage.style.display = 'block';
                        acceptBtn.disabled = false;
                    }
                } catch (err) {
                    errorMessage.textContent = t('terms.network_error');
                    errorMessage.style.display = 'block';
                    acceptBtn.disabled = false;
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
