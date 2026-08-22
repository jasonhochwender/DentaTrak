<?php
/**
 * Internal new-user signup notification email.
 *
 * Notifies the configured internal address whenever a new DentaTrak account
 * is successfully created. This is intentionally separate from user-facing
 * registration, verification, and welcome emails.
 */

require_once __DIR__ . '/email-sender.php';

const SIGNUP_NOTIFICATION_RECIPIENT = 'jason.hochwender@dentatrak.com';

/**
 * Send an internal signup notification email.
 *
 * @param PDO   $pdo      Database connection.
 * @param int   $userId   Newly created user ID.
 * @param array $appConfig Application configuration.
 * @return void
 */
function sendSignupNotificationEmail(PDO $pdo, int $userId, array $appConfig): void {
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';

    try {
        $userStmt = $pdo->prepare("SELECT first_name, last_name, email, created_at FROM users WHERE id = :id");
        $userStmt->execute(['id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log('[signup-notification] Could not find user ' . $userId . ' for signup notification');
            return;
        }

        $locale = resolveEmailLocale(null, null, 'en-US');
        $appName = $appConfig['appName'] ?? 'DentaTrak';

        $firstName = trim($user['first_name'] ?? '');
        $lastName  = trim($user['last_name'] ?? '');
        $fullName  = trim($firstName . ' ' . $lastName);
        if ($fullName === '') {
            $fullName = 'Not provided';
        }

        $email     = trim($user['email'] ?? '');
        $timestamp = !empty($user['created_at']) ? formatDateTime($user['created_at'], 'medium') : formatDateTime(time(), 'medium');

        $practiceStmt = $pdo->prepare("
            SELECT p.display_name, p.practice_name
            FROM practices p
            JOIN practice_users pu ON pu.practice_id = p.id
            WHERE pu.user_id = :user_id
            LIMIT 1
        ");
        $practiceStmt->execute(['user_id' => $userId]);
        $practice = $practiceStmt->fetch(PDO::FETCH_ASSOC);

        $practiceName = 'Not yet associated with a practice';
        if ($practice) {
            $practiceName = trim($practice['display_name'] ?? $practice['practice_name'] ?? '');
            if ($practiceName === '') {
                $practiceName = 'Not yet associated with a practice';
            }
        }

        $htmlFullName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $htmlEmail    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $htmlPractice = htmlspecialchars($practiceName, ENT_QUOTES, 'UTF-8');

        $subject = tForLocale($locale, 'email.admin_notification.subject', ['appName' => $appName]);
        $htmlBody = tForLocale($locale, 'email.admin_notification.body_html', [
            'appName' => $appName,
            'fullName' => $htmlFullName,
            'email' => $htmlEmail,
            'timestamp' => $timestamp,
            'practiceName' => $htmlPractice
        ]);
        $textBody = tForLocale($locale, 'email.admin_notification.body_text', [
            'appName' => $appName,
            'fullName' => $fullName,
            'email' => $email,
            'timestamp' => $timestamp,
            'practiceName' => $practiceName
        ]);

        $result = sendAppEmail(SIGNUP_NOTIFICATION_RECIPIENT, $subject, $htmlBody, $textBody, $supportEmail);

        if (empty($result['success'])) {
            error_log('[signup-notification] Failed to send signup notification for user ' . $userId . ': ' . ($result['error'] ?? 'unknown'));
        }
    } catch (Exception $e) {
        error_log('[signup-notification] Exception sending signup notification for user ' . $userId . ': ' . $e->getMessage());
    }
}
