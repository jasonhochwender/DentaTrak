<?php
/**
 * Shared helpers for the two-factor recovery flow.
 *
 * A 2FA reset token proves mailbox control only. Completing a reset also
 * requires identity re-verification (current password, or a fresh Google
 * sign-in for Google-only accounts) - see api/2fa-recovery.php. An admin
 * can trigger this flow for a member, but the token still requires the
 * member's own verification; nothing here disables 2FA on its own.
 *
 * Security notes:
 * - Only the SHA-256 hash of the emailed token is stored.
 * - Issuing a token invalidates all prior active tokens for that user.
 * - Tokens never carry TOTP secrets and are never logged.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/email-sender.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/unified-identity.php';
require_once __DIR__ . '/practice-security.php';

/**
 * Ensure the token table exists (auto-migration parity with
 * password_reset_tokens; the migrations/ file is the deployment path).
 */
function ensure2FAResetTokensTable(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS two_factor_reset_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                requested_by_user_id INT UNSIGNED NULL,
                expires_at DATETIME NOT NULL,
                used BOOLEAN NOT NULL DEFAULT FALSE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table may already exist
    }
}

/**
 * Issue a new single-use recovery token for a user.
 * Invalidates every outstanding token for that user first.
 *
 * @param int $userId
 * @param int|null $requestedByUserId Admin/owner who initiated, or null for self-service
 * @return string|null The raw token to embed in the email link (never stored)
 */
function issue2FAResetToken(int $userId, ?int $requestedByUserId = null): ?string {
    global $pdo;
    if (!$pdo || !$userId) {
        return null;
    }

    ensure2FAResetTokensTable($pdo);

    try {
        $stmt = $pdo->prepare(
            "UPDATE two_factor_reset_tokens SET used = 1 WHERE user_id = :user_id AND used = 0"
        );
        $stmt->execute(['user_id' => $userId]);

        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("
            INSERT INTO two_factor_reset_tokens (user_id, token_hash, requested_by_user_id, expires_at)
            VALUES (:user_id, :token_hash, :requested_by, :expires_at)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'requested_by' => $requestedByUserId,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ]);

        return $token;
    } catch (PDOException $e) {
        error_log('[2fa-recovery] Token issue failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Look up an active (unused, unexpired) token row by its presented value.
 *
 * @param string $token Raw token from the recovery link
 * @return array|null Token row or null
 */
function find2FAResetToken(string $token): ?array {
    global $pdo;
    if (!$pdo || $token === '') {
        return null;
    }

    ensure2FAResetTokensTable($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, requested_by_user_id, expires_at, used
            FROM two_factor_reset_tokens
            WHERE token_hash = :hash
        ");
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['used'] || strtotime($row['expires_at']) < time()) {
            return null;
        }
        return $row;
    } catch (PDOException $e) {
        error_log('[2fa-recovery] Token lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Does any practice this user belongs to require 2FA?
 * Drives the "re-enrollment required" guidance after a reset - the practice
 * boundary itself re-checks the policy on every activation regardless.
 *
 * @param int $userId
 * @return bool
 */
function userIn2FARequiredPractice(int $userId): bool {
    global $pdo;
    if (!$pdo || !$userId || !function_exists('practiceRequires2FA')) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT practice_id FROM practice_users WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $practiceId) {
            if (practiceRequires2FA((int)$practiceId)) {
                return true;
            }
        }
    } catch (PDOException $e) {
        error_log('[2fa-recovery] Required-practice lookup failed: ' . $e->getMessage());
    }
    return false;
}

/**
 * Send the recovery-link email (localized, no secrets, no PHI).
 *
 * @param array $user users row (needs id, email, first_name)
 * @param string $token Raw token for the link
 * @param bool $adminInitiated Whether a practice admin triggered the request
 * @return bool Whether the email was accepted for delivery
 */
function send2FARecoveryEmail(array $user, string $token, bool $adminInitiated = false): bool {
    global $appConfig;

    $baseUrl = rtrim(($appConfig['baseUrl'] ?? ''), '/');
    if (!$baseUrl) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = "{$protocol}://{$host}";
    }
    $resetUrl = $baseUrl . '/2fa-reset.php?token=' . urlencode($token);

    $locale = resolveEmailLocale($user['id'], null, null);
    $appName = $appConfig['appName'] ?? 'App';
    $firstName = $user['first_name'] ?? '';

    $subject = tForLocale($locale, 'email.2fa_recovery.subject', ['appName' => $appName]);
    $heading = tForLocale($locale, 'email.2fa_recovery.heading');
    $greeting = $firstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8')])
        : tForLocale($locale, 'email.common.greeting_no_name');
    $intro = $adminInitiated
        ? tForLocale($locale, 'email.2fa_recovery.intro_admin', ['appName' => $appName])
        : tForLocale($locale, 'email.2fa_recovery.intro', ['appName' => $appName]);
    $cta = tForLocale($locale, 'email.2fa_recovery.cta');
    $copyLink = tForLocale($locale, 'email.common.copy_link');
    $expiry = tForLocale($locale, 'email.2fa_recovery.expiry', ['count' => 60]);
    $verifyNote = tForLocale($locale, 'email.2fa_recovery.verify_note');
    $ignore = tForLocale($locale, 'email.common.ignore_unsolicited');
    $footer = tForLocale($locale, 'email.common.footer', ['appName' => $appName]);

    $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .button { display: inline-block; padding: 12px 24px; background-color: #3b82f6; color: #ffffff !important; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: bold; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>{$heading}</h2>
                <p>{$greeting}</p>
                <p>{$intro}</p>
                <p><a href='{$resetUrl}' class='button' style='display: inline-block; padding: 12px 24px; background-color: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold;'>{$cta}</a></p>
                <p>{$copyLink}</p>
                <p style='word-break: break-all;'>{$resetUrl}</p>
                <p>{$expiry}</p>
                <p>{$verifyNote}</p>
                <p>{$ignore}</p>
                <div class='footer'>
                    <p>{$footer}</p>
                </div>
            </div>
        </body>
        </html>
    ";

    $result = sendAppEmail($user['email'], $subject, $message);
    return !empty($result['success']);
}

/**
 * Send the post-reset security notification (localized).
 *
 * @param array $user users row (needs id, email, first_name)
 * @return bool Whether the email was accepted for delivery
 */
function send2FAResetNotificationEmail(array $user): bool {
    global $appConfig;

    $locale = resolveEmailLocale($user['id'], null, null);
    $appName = $appConfig['appName'] ?? 'App';
    $firstName = $user['first_name'] ?? '';

    $subject = tForLocale($locale, 'email.2fa_reset_notice.subject', ['appName' => $appName]);
    $heading = tForLocale($locale, 'email.2fa_reset_notice.heading');
    $greeting = $firstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8')])
        : tForLocale($locale, 'email.common.greeting_no_name');
    $intro = tForLocale($locale, 'email.2fa_reset_notice.intro', ['appName' => $appName]);
    $action = tForLocale($locale, 'email.2fa_reset_notice.action_needed');
    $footer = tForLocale($locale, 'email.common.footer', ['appName' => $appName]);

    $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>{$heading}</h2>
                <p>{$greeting}</p>
                <p>{$intro}</p>
                <p>{$action}</p>
                <div class='footer'>
                    <p>{$footer}</p>
                </div>
            </div>
        </body>
        </html>
    ";

    $result = sendAppEmail($user['email'], $subject, $message);
    return !empty($result['success']);
}
