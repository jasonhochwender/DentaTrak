<?php
/**
 * Admin practice-user email composition, preview context, delivery and
 * audit logging for Practice Administration. Extracted from
 * api/admin-practices.php so the composer is unit-testable; the API
 * router requires this file.
 */

require_once __DIR__ . '/subscription-owner.php';
require_once __DIR__ . '/plan-entitlements.php';
require_once __DIR__ . '/email-sender.php';

/**
 * Record an admin email action to the audit log. The admin_email_log table must
 * already exist (created by migrations/2026_09_01_admin_email_log.php).
 */
function logAdminEmail($adminUserId, $adminEmail, $recipientUserId, $recipientEmail, $practiceId, $emailType, $subject, $success, $provider = null, $error = null) {
    global $pdo;

    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_email_log (admin_user_id, admin_email, recipient_user_id, recipient_email, practice_id, email_type, email_subject, success, provider, error_message)
            VALUES (:admin_user_id, :admin_email, :recipient_user_id, :recipient_email, :practice_id, :email_type, :email_subject, :success, :provider, :error_message)
        ");
        $stmt->execute([
            ':admin_user_id' => $adminUserId,
            ':admin_email' => $adminEmail,
            ':recipient_user_id' => $recipientUserId,
            ':recipient_email' => $recipientEmail,
            ':practice_id' => $practiceId,
            ':email_type' => $emailType,
            ':email_subject' => $subject,
            ':success' => $success ? 1 : 0,
            ':provider' => $provider,
            ':error_message' => $error,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error logging admin email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Admin practice-email types. 'custom' requires an admin-composed
 * subject and body; every other type ships a default subject/body that
 * the admin may edit before sending. 'user_guide' was retired - the
 * standalone guide is no longer maintained.
 */
function adminPracticeEmailTypes(): array {
    return ['getting_started', 'work_with_lab', 'explore_insights', 'connect_tools',
            'whats_new', 'feedback', 'trial_reminder', 'custom'];
}

/**
 * Default subject + plain-text body for a template type. $ctx carries
 * firstName, practiceName, loginUrl, supportEmail, trialDays,
 * trialEndDate and planDisplay. Returns null for 'custom' (no default).
 */
function adminPracticeEmailTemplate(string $emailType, array $ctx): ?array {
    $first = $ctx['firstName'] !== '' ? $ctx['firstName'] : 'there';
    $practice = $ctx['practiceName'];
    $login = $ctx['loginUrl'];
    switch ($emailType) {
        case 'getting_started':
            return [
                'subject' => 'Getting started with DentaTrak',
                'body' => "DentaTrak helps {$practice} track cases from start to finish - who is working on what, where each case stands, and when it is due.\n\n"
                    . "The fastest way to see the value is to create your first case: add the patient, choose the services, and DentaTrak keeps the status and reminders organized for you.\n\n"
                    . "Sign in here when you are ready: {$login}",
            ];
        case 'work_with_lab':
            return [
                'subject' => 'Keeping lab work visible in DentaTrak',
                'body' => "One of the easiest wins with DentaTrak is lab coordination. Every case carries its own status and due date, so you can see at a glance which restorations are at the lab, which are ready for pickup, and which need a follow-up - without another spreadsheet or a round of phone calls.\n\n"
                    . "If your lab partner is also on DentaTrak, updates stay in sync automatically. Either way, the case timeline keeps everyone oriented.\n\n"
                    . "Happy to walk your team through it - just reply to this email.",
            ];
        case 'explore_insights':
            return [
                'subject' => 'See what Practice Insights can tell you',
                'body' => "DentaTrak's Practice Insights turns your case history into answers: how many cases you are moving each month, turnaround times by procedure, and where work tends to stall.\n\n"
                    . "The data is already being collected as you use the platform, so there is nothing extra to set up. Take a look at Insights and see what your numbers say.\n\n"
                    . "If a metric looks off or you wish it measured something else, tell me - that feedback shapes what we build next.",
            ];
        case 'connect_tools':
            return [
                'subject' => 'Connecting DentaTrak to your existing tools',
                'body' => "DentaTrak works alongside the tools you already use. If your practice runs Open Dental, the integration can keep case information in sync automatically. Practices using DS Core or Medit Link scanners can bring digital impressions straight into the matching case.\n\n"
                    . "If you would like help connecting yours, just reply and I will set it up with you.",
            ];
        case 'whats_new':
            return [
                'subject' => "What's new in DentaTrak",
                'body' => "A couple of recent additions worth knowing about: Practice Insights now surfaces richer turnaround and performance detail, and the data export lets you download your practice's full history whenever you need it.\n\n"
                    . "Both are already available in your account. If you have not looked at Insights lately, it is a good place to start.",
            ];
        case 'feedback':
            return [
                'subject' => 'Quick question from the DentaTrak team',
                'body' => "I am Jason, co-founder of DentaTrak. I would genuinely love your honest take: what is missing, what slows your team down, or what would make DentaTrak useful every single day?\n\n"
                    . "Even one sentence of feedback helps us decide what to build next. Just reply to this email - it comes straight to me.",
            ];
        case 'trial_reminder':
            $remaining = $ctx['trialDays'] > 1 ? "{$ctx['trialDays']} days remaining"
                : ($ctx['trialDays'] === 1 ? '1 day remaining'
                : ($ctx['trialDays'] === 0 ? 'ends today' : 'has ended'));
            $end = $ctx['trialEndDate'] !== '' ? " on {$ctx['trialEndDate']}" : '';
            return [
                'subject' => 'Making the most of your DentaTrak trial',
                'body' => "Your DentaTrak trial for {$practice} ({$ctx['planDisplay']} plan) {$remaining}{$end}.\n\n"
                    . "There is no pressure - but the trial is the best way to see whether DentaTrak fits how your practice actually works. If you get value from tracking just a few cases, that is a good sign.\n\n"
                    . "If now is not the right time, just let me know and we can talk options.",
            ];
        default:
            return null;
    }
}

/**
 * Compose (but do not send) an admin practice email. Returns the final
 * subject/html/text plus display context, or an error array.
 *
 * Subject/body overrides apply to every type: a non-empty override
 * replaces the template default, so the admin's edits are what sends.
 * 'custom' requires both.
 */
function composeAdminPracticeEmail(PDO $pdo, int $practiceId, int $recipientUserId, string $emailType, string $subjectOverride = '', string $bodyOverride = ''): array {
    global $appConfig;

    if (!in_array($emailType, adminPracticeEmailTypes(), true)) {
        return ['success' => false, 'message' => 'Invalid email type.', 'provider' => null, 'error' => null];
    }

    // Verify the recipient actually belongs to the selected practice
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.first_name, u.last_name, p.practice_name
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        JOIN practices p ON p.id = pu.practice_id
        WHERE pu.practice_id = :practice_id AND u.id = :user_id
        LIMIT 1
    ");
    $stmt->execute(['practice_id' => $practiceId, 'user_id' => $recipientUserId]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipient) {
        return ['success' => false, 'message' => 'Recipient not found or does not belong to this practice.', 'provider' => null, 'error' => null];
    }

    $toEmail = $recipient['email'];
    $firstName = trim((string)($recipient['first_name'] ?? ''));
    $practiceName = $recipient['practice_name'] ?? 'Your Practice';
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $baseUrl = rtrim($appConfig['app_base_url'] ?? 'https://dentatrak.com', '/');
    $loginUrl = $baseUrl . '/login.php';

    // Trial context (only needed by trial_reminder, cheap to resolve).
    $trialDays = null; $trialEndDate = ''; $planDisplay = '—';
    if ($emailType === 'trial_reminder') {
        $ownerUserId = getSubscriptionOwnerUserId($pdo, $practiceId);
        $sub = $ownerUserId ? getSubscriptionForOwner($pdo, $ownerUserId) : [];
        if (empty($sub) || ($sub['status'] ?? '') !== 'trialing' || empty($sub['trial_ends_at'])) {
            return ['success' => false, 'message' => 'This practice is not currently in a trial.', 'provider' => null, 'error' => null];
        }
        try {
            $end = new DateTimeImmutable($sub['trial_ends_at'], new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $trialDays = (int)$now->diff($end)->format('%r%a');
            $trialEndDate = $end->format('M j, Y');
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not parse trial end date.', 'provider' => null, 'error' => $e->getMessage()];
        }
        $storedPlan = $sub['plan'] ?? null;
        $planDisplay = !empty($storedPlan) ? getPlanDisplayName($storedPlan) : '—';
    }

    $ctx = [
        'firstName' => $firstName, 'practiceName' => $practiceName,
        'loginUrl' => $loginUrl, 'supportEmail' => $supportEmail,
        'trialDays' => $trialDays ?? 0, 'trialEndDate' => $trialEndDate,
        'planDisplay' => $planDisplay,
    ];
    $tpl = $emailType === 'custom' ? null : adminPracticeEmailTemplate($emailType, $ctx);

    $subject = trim($subjectOverride);
    $body = trim($bodyOverride);
    if ($subject === '' && $tpl !== null) $subject = $tpl['subject'];
    if ($body === '' && $tpl !== null) $body = $tpl['body'];
    if ($emailType === 'custom' && ($subject === '' || $body === '')) {
        return ['success' => false, 'message' => 'Custom email subject and message are required.', 'provider' => null, 'error' => null];
    }
    if ($subject === '' || $body === '') {
        return ['success' => false, 'message' => 'Email subject and message are required.', 'provider' => null, 'error' => null];
    }
    if (mb_strlen($subject) > 255 || mb_strlen($body) > 20000) {
        return ['success' => false, 'message' => 'Email subject or message is too long.', 'provider' => null, 'error' => null];
    }

    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $greeting = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    $safeBody = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    // Blank-line separated paragraphs become <p> blocks; single newlines <br>.
    $paras = preg_split('/\n\s*\n/', trim($safeBody));
    $bodyHtml = '';
    foreach ($paras as $p) {
        if (trim($p) === '') continue;
        $bodyHtml .= '<p>' . nl2br($p, false) . '</p>' . "\n  ";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greeting}</p>
  {$bodyHtml}
  <p>If you have any questions, just reply to this email or reach us at <a href="mailto:{$supportEmail}">{$supportEmail}</a>.</p>
  <p>Jason<br>Co-Founder, DentaTrak</p>
</body>
</html>
HTML;

    $textBody = $greeting . "\n\n" . $body . "\n\n"
        . "If you have any questions, just reply to this email or reach us at {$supportEmail}.\n\n"
        . "Jason\nCo-Founder, DentaTrak";

    return [
        'success' => true, 'subject' => $subject, 'html' => $html, 'text' => $textBody,
        'to_email' => $toEmail, 'recipient_user_id' => $recipientUserId,
        'template_subject' => $tpl['subject'] ?? '', 'template_body' => $tpl['body'] ?? '',
    ];
}

/**
 * Context + appropriateness warnings for the email dialog: practice
 * case count, trial timing, and the most recent admin email to this
 * recipient (any type) plus any same-type send in the last 14 days.
 */
function adminEmailContext(PDO $pdo, int $practiceId, int $recipientUserId, string $emailType): array {
    $warnings = [];
    $caseCount = 0;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM cases_cache WHERE practice_id = ?');
        $st->execute([$practiceId]);
        $caseCount = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* cases_cache optional */ }
    if ($emailType === 'explore_insights' && $caseCount === 0) {
        $warnings[] = 'This practice has no cases yet - Practice Insights may not be relevant.';
    }

    $lastEmail = null; $recentSameType = null;
    try {
        $st = $pdo->prepare(
            "SELECT email_type, email_subject, sent_at, success FROM admin_email_log
             WHERE practice_id = ? AND recipient_user_id = ?
             ORDER BY sent_at DESC, id DESC LIMIT 10"
        );
        $st->execute([$practiceId, $recipientUserId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($lastEmail === null) $lastEmail = $row;
            if ($recentSameType === null && $row['email_type'] === $emailType) $recentSameType = $row;
        }
    } catch (Throwable $e) { /* log table optional pre-migration */ }
    if ($recentSameType !== null) {
        $warnings[] = 'A "' . $recentSameType['email_type'] . '" email was already sent to this recipient on '
            . substr((string)$recentSameType['sent_at'], 0, 10) . '.';
    }

    if ($emailType === 'trial_reminder') {
        $ownerUserId = getSubscriptionOwnerUserId($pdo, $practiceId);
        $sub = $ownerUserId ? getSubscriptionForOwner($pdo, $ownerUserId) : [];
        if (empty($sub) || ($sub['status'] ?? '') !== 'trialing' || empty($sub['trial_ends_at'])) {
            $warnings[] = 'This practice does not appear to be in an active trial.';
        } else {
            try {
                $days = (int)(new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->diff(new DateTimeImmutable($sub['trial_ends_at'], new DateTimeZone('UTC')))->format('%r%a');
                if ($days > 14) {
                    $warnings[] = "The trial ends in {$days} days - a reminder may be premature.";
                }
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    return ['warnings' => $warnings, 'case_count' => $caseCount, 'last_email' => $lastEmail];
}

/**
 * Compose and send an admin-triggered practice-user email.
 *
 * @return array { success: bool, message: string, provider: ?string, error: ?string }
 */
function sendAdminPracticeEmail(PDO $pdo, int $adminUserId, string $adminEmail, int $practiceId, int $recipientUserId, string $emailType, string $subjectOverride = '', string $bodyOverride = ''): array {
    global $appConfig;

    $c = composeAdminPracticeEmail($pdo, $practiceId, $recipientUserId, $emailType, $subjectOverride, $bodyOverride);
    if (empty($c['success'])) {
        return ['success' => false, 'message' => $c['message'] ?? 'Could not compose email.', 'provider' => null, 'error' => $c['error'] ?? null];
    }

    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $result = sendAppEmail($c['to_email'], $c['subject'], $c['html'], $c['text'], $supportEmail);

    $success = !empty($result['success']);
    logAdminEmail(
        $adminUserId, $adminEmail, $recipientUserId, $c['to_email'],
        $practiceId, $emailType, $c['subject'], $success,
        $result['provider'] ?? null, $result['error'] ?? null
    );

    if ($success) {
        return ['success' => true, 'message' => 'Email sent to ' . $c['to_email'], 'provider' => $result['provider'] ?? null, 'error' => null];
    }
    return ['success' => false, 'message' => 'Failed to send email. ' . (($result['error'] ?? '') ?: 'Unknown error'), 'provider' => $result['provider'] ?? null, 'error' => $result['error'] ?? null];
}

