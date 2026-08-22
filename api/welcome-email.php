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
 * @param int|null $practiceId
 * @return void
 */
function sendWelcomeEmail(PDO $pdo, int $userId, string $practiceName, array $appConfig, ?int $practiceId = null): void {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['email'])) {
        error_log('[welcome-email] No user or email found for user ' . $userId);
        return;
    }

    $locale = resolveEmailLocale($userId, $practiceId, null);
    $appName = $appConfig['appName'] ?? 'DentaTrak';
    $firstName = trim($user['first_name'] ?? '');
    $toEmail = $user['email'];
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $safeSupportEmail = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');
    $userGuideUrl = $appConfig['user_guide_url'] ?? '#';

    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safePracticeName = htmlspecialchars($practiceName, ENT_QUOTES, 'UTF-8');

    $greetingHtml = $firstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => $safeFirstName])
        : tForLocale($locale, 'email.common.greeting_no_name');
    $greetingText = $firstName
        ? tForLocale($locale, 'email.common.greeting_with_name', ['name' => $firstName])
        : tForLocale($locale, 'email.common.greeting_no_name');
    $subject = tForLocale($locale, 'email.welcome.subject', ['appName' => $appName]);
    $introHtml = tForLocale($locale, 'email.welcome.intro', ['appName' => $appName, 'practiceName' => $safePracticeName]);
    $introText = tForLocale($locale, 'email.welcome.intro', ['appName' => $appName, 'practiceName' => $practiceName]);
    $cta = tForLocale($locale, 'email.welcome.cta');
    $footerText = tForLocale($locale, 'email.welcome.footer', ['supportEmail' => $supportEmail]);
    $footerHtml = str_replace(
        $safeSupportEmail,
        "<a href='mailto:{$safeSupportEmail}'>{$safeSupportEmail}</a>",
        tForLocale($locale, 'email.welcome.footer', ['supportEmail' => $safeSupportEmail])
    );
    $thanks = tForLocale($locale, 'email.common.thanks');
    $team = tForLocale($locale, 'email.common.team_signature', ['appName' => $appName]);

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greetingHtml}</p>
  <p>{$introHtml}</p>
  <p>Get started with the <a href="{$userGuideUrl}" target="_blank" rel="noopener noreferrer">{$cta}</a>.</p>
  <p>{$footerHtml}</p>
  <p>{$thanks}<br>{$team}</p>
</body>
</html>
HTML;

    $textBody = <<<TEXT
{$greetingText}

{$introText}

{$cta}: {$userGuideUrl}

{$footerText}

{$thanks}
{$team}
TEXT;

    $result = sendAppEmail($toEmail, $subject, $htmlBody, $textBody, $supportEmail);

    if (empty($result['success'])) {
        error_log('[welcome-email] Failed to send welcome email to ' . $toEmail . ': ' . ($result['error'] ?? 'unknown error'));
    }
}
