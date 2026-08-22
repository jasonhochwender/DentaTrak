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
 * @param int|null $practiceId  Practice ID for locale resolution.
 * @return void
 */
function sendPracticeInviteEmail(string $toEmail, ?string $firstName, string $practiceName, array $appConfig, ?int $practiceId = null): void {
    $locale = resolveEmailLocale(null, $practiceId, null);
    $appName = $appConfig['appName'] ?? 'DentaTrak';
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $safeSupportEmail = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');
    $userGuideUrl = $appConfig['user_guide_url'] ?? '#';
    $appBaseUrl   = rtrim($appConfig['app_base_url'] ?? 'https://dentatrak.com', '/');
    $loginUrl     = $appBaseUrl . '/login.php';

    $htmlFirstName = $firstName ? htmlspecialchars(trim($firstName), ENT_QUOTES, 'UTF-8') : '';
    $greetingHtml = $htmlFirstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => $htmlFirstName])
        : tForLocale($locale, 'email.common.greeting_generic');
    $greetingText = $firstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => $firstName])
        : tForLocale($locale, 'email.common.greeting_generic');

    $htmlPracticeName = htmlspecialchars($practiceName, ENT_QUOTES, 'UTF-8');
    $subject = tForLocale($locale, 'email.practice_invite.subject', ['appName' => $appName, 'practiceName' => $practiceName]);
    $existingIntroHtml = tForLocale($locale, 'email.practice_invite.existing_user_intro', ['appName' => $appName, 'practiceName' => $htmlPracticeName]);
    $existingIntroText = tForLocale($locale, 'email.practice_invite.existing_user_intro', ['appName' => $appName, 'practiceName' => $practiceName]);
    $newIntroHtml = tForLocale($locale, 'email.practice_invite.new_user_intro', ['appName' => $appName, 'practiceName' => $htmlPracticeName]);
    $newIntroText = tForLocale($locale, 'email.practice_invite.new_user_intro', ['appName' => $appName, 'practiceName' => $practiceName]);
    $openDentatrak = tForLocale($locale, 'email.common.open_dentatrak', ['appName' => $appName]);
    $viewUserGuide = tForLocale($locale, 'email.common.view_user_guide');
    $helpPlain = tForLocale($locale, 'email.practice_invite.help', ['supportEmail' => $supportEmail]);
    $helpHtml = str_replace(
        $safeSupportEmail,
        "<a href='mailto:{$safeSupportEmail}'>{$safeSupportEmail}</a>",
        tForLocale($locale, 'email.practice_invite.help', ['supportEmail' => $safeSupportEmail])
    );
    $thanks = tForLocale($locale, 'email.common.thanks');
    $team = tForLocale($locale, 'email.common.team_signature', ['appName' => $appName]);

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greetingHtml}</p>
  <p>{$existingIntroHtml}</p>
  <p>{$newIntroHtml}</p>
  <p><a href="{$loginUrl}" target="_blank" rel="noopener noreferrer">{$openDentatrak}</a></p>
  <p>{$appName} User Guide<br><a href="{$userGuideUrl}" target="_blank" rel="noopener noreferrer">{$viewUserGuide}</a></p>
  <p>{$helpHtml}</p>
  <p>{$thanks}<br>{$team}</p>
</body>
</html>
HTML;

    $textBody = $greetingText . "\n\n" .
        $existingIntroText . "\n\n" .
        $newIntroText . "\n\n" .
        $openDentatrak . ": {$loginUrl}\n\n" .
        $appName . " User Guide: {$userGuideUrl}\n\n" .
        $helpPlain . "\n\n" .
        $thanks . "\n" .
        $team;

    $result = sendAppEmail($toEmail, $subject, $htmlBody, $textBody, $supportEmail);

    if (empty($result['success'])) {
        error_log('[practice-invite] Failed to send practice invite email to ' . $toEmail . ': ' . ($result['error'] ?? 'unknown error'));
    }
}
