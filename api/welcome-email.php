<?php
/**
 * Welcome email helper for new practice owners.
 */

require_once __DIR__ . '/email-sender.php';

/**
 * Send a welcome email to the owner of a newly created first practice.
 *
 * @param PDO   $pdo
 * @param int   $userId
 * @param string $practiceName
 * @param array $appConfig
 * @return void
 */
function sendWelcomeEmail(PDO $pdo, int $userId, string $practiceName, array $appConfig): void {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['email'])) {
        error_log('[welcome-email] No user or email found for user ' . $userId);
        return;
    }

    $firstName = trim($user['first_name'] ?? '');
    $toEmail = $user['email'];
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $userGuideUrl = $appConfig['user_guide_url'] ?? '#';

    $greeting = $firstName ? "Hi {$firstName}," : "Hi there,";
    $subject = 'Welcome to DentaTrak';

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greeting}</p>
  <p>Welcome to <strong>DentaTrak</strong>! Your practice <strong>{$practiceName}</strong> has been set up and is ready to use.</p>
  <p>Get started with the <a href="{$userGuideUrl}" target="_blank" rel="noopener noreferrer">DentaTrak User Guide</a>.</p>
  <p>If you have any questions, reach out to us at <a href="mailto:{$supportEmail}">{$supportEmail}</a>.</p>
  <p>Thanks,<br>The DentaTrak Team</p>
</body>
</html>
HTML;

    $textBody = <<<TEXT
{$greeting}

Welcome to DentaTrak! Your practice "{$practiceName}" has been set up and is ready to use.

Get started with the User Guide here: {$userGuideUrl}

If you have any questions, reach out to us at {$supportEmail}.

Thanks,
The DentaTrak Team
TEXT;

    $result = sendAppEmail($toEmail, $subject, $htmlBody, $textBody, $supportEmail);

    if (empty($result['success'])) {
        error_log('[welcome-email] Failed to send welcome email to ' . $toEmail . ': ' . ($result['error'] ?? 'unknown error'));
    }
}
