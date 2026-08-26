<?php
/**
 * Notification Email Renderer
 *
 * Builds a generic, PHI-free notification email for a specific locale.
 *
 * No patient information, case values, filenames, status, dates, or other
 * clinical/operational data is ever rendered. Only a non-PHI event
 * description and a notification_id-only View case link are emitted.
 */

require_once __DIR__ . '/i18n.php';

/**
 * Render a notification email.
 *
 * @param string $locale        Recipient locale
 * @param string $subjectKey    Translation key for subject
 * @param string $bodyKey       Translation key for body
 * @param array  $templateData  Safe data: from (actor name), event_type
 * @param int    $notificationId
 * @param string $baseUrl       Application base URL
 * @return array [subject, html, text]
 */
function renderNotificationEmail($locale, $subjectKey, $bodyKey, array $templateData, $notificationId, $baseUrl) {
    $safeBaseUrl = rtrim($baseUrl, '/');
    $viewUrl = $safeBaseUrl . '/main.php?notification_id=' . (int)$notificationId;
    $preferencesUrl = $safeBaseUrl . '/main.php';

    $actorName = $templateData['from'] ?? '';
    $eventType = $templateData['event_type'] ?? 'case_details_changed';

    $eventDescription = tForLocale($locale, 'notifications.email.event.' . $eventType, [
        'from' => $actorName,
    ]);

    $params = [
        'appName' => tForLocale($locale, 'app.name'),
        'eventDescription' => $eventDescription,
        'viewCaseUrl' => $viewUrl,
        'viewCaseLabel' => tForLocale($locale, 'notifications.email.view_case'),
        'preferencesUrl' => $preferencesUrl,
        'preferencesLabel' => tForLocale($locale, 'preferences.title'),
        'signInPrompt' => tForLocale($locale, 'notifications.email.sign_in_prompt'),
        'preferenceExplanation' => tForLocale($locale, 'notifications.email.preference_explanation'),
        'footerSupport' => tForLocale($locale, 'notifications.email.footer_support'),
    ];

    $subject = tForLocale($locale, $subjectKey, $params);
    $html = tForLocale($locale, $bodyKey, $params);

    // Generate a plain-text fallback from the HTML-safe body
    $text = strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\n\s*\n/', "\n\n", $text);
    $text = trim($text);

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
