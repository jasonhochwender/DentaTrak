<?php
/**
 * Practice invitation email helper.
 *
 * Notifies a user that they have been added to a DentaTrak practice.
 * This helper is intentionally free of database access so it can be called
 * from the save flow or from a future "Resend invitation" action.
 */

require_once __DIR__ . '/email-sender.php';

/**
 * Send a practice invitation / added-to-practice notification email.
 *
 * @param string   $toEmail     Recipient email address.
 * @param string|null $firstName Recipient first name, or null/empty if unknown.
 * @param string   $practiceName Display name of the practice the user was added to.
 * @param array    $appConfig   Application configuration (must include app_base_url, user_guide_url, support_email).
 * @return void
 */
function sendPracticeInviteEmail(string $toEmail, ?string $firstName, string $practiceName, array $appConfig): void {
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $userGuideUrl = $appConfig['user_guide_url'] ?? '#';
    $appBaseUrl   = rtrim($appConfig['app_base_url'] ?? 'https://dentatrak.com', '/');
    $loginUrl     = $appBaseUrl . '/login.php';

    $greeting = !empty($firstName) ? 'Hi ' . trim($firstName) . ',' : 'Hello,';
    $subject  = "You've been added to {$practiceName} in DentaTrak";

    $htmlPracticeName = htmlspecialchars($practiceName, ENT_QUOTES, 'UTF-8');
    $htmlFirstName    = $firstName ? htmlspecialchars(trim($firstName), ENT_QUOTES, 'UTF-8') : '';
    $htmlGreeting     = !empty($firstName) ? 'Hi ' . $htmlFirstName . ',' : 'Hello,';
    $htmlLoginUrl     = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $htmlUserGuideUrl = htmlspecialchars($userGuideUrl, ENT_QUOTES, 'UTF-8');
    $htmlSupportEmail = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$htmlGreeting}</p>
  <p>You've been added to the <strong>{$htmlPracticeName}</strong> dental practice in DentaTrak.</p>
  <p>If you already have a DentaTrak account, sign in using the email address that received this message. The <strong>{$htmlPracticeName}</strong> dental practice will be available when you sign in.</p>
  <p>If you don't have a DentaTrak account yet, create an account using this same email address. Once registration is complete, you'll be able to access the <strong>{$htmlPracticeName}</strong> dental practice.</p>
  <p><a href="{$htmlLoginUrl}" target="_blank" rel="noopener noreferrer">Open DentaTrak</a></p>
  <p>DentaTrak User Guide<br><a href="{$htmlUserGuideUrl}" target="_blank" rel="noopener noreferrer">View the User Guide</a></p>
  <p>If you have questions or need help, contact us at <a href="mailto:{$htmlSupportEmail}">{$htmlSupportEmail}</a>.</p>
  <p>Thanks,<br>The DentaTrak Team</p>
</body>
</html>
HTML;

    $textBody = $greeting . "\n\n" .
        "You've been added to the \"{$practiceName}\" dental practice in DentaTrak.\n\n" .
        "If you already have a DentaTrak account, sign in using the email address that received this message. The \"{$practiceName}\" dental practice will be available when you sign in.\n\n" .
        "If you don't have a DentaTrak account yet, create an account using this same email address. Once registration is complete, you'll be able to access the \"{$practiceName}\" dental practice.\n\n" .
        "Open DentaTrak: {$loginUrl}\n\n" .
        "DentaTrak User Guide: {$userGuideUrl}\n\n" .
        "If you have questions or need help, contact us at {$supportEmail}.\n\n" .
        "Thanks,\nThe DentaTrak Team";

    $result = sendAppEmail($toEmail, $subject, $htmlBody, $textBody, $supportEmail);

    if (empty($result['success'])) {
        error_log('[practice-invite] Failed to send practice invite email to ' . $toEmail . ': ' . ($result['error'] ?? 'unknown error'));
    }
}
