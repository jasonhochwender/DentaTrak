<?php
/**
 * Focused regression tests for comment mention notifications and feature gating.
 *
 * These tests do not require a logged-in HTTP session or a database; they exercise
 * the notification preference gating and PHI-free email rendering helpers directly.
 * Full end-to-end mention flow (autocomplete, in-app notification, email queue) must
 * be validated manually or through the Playwright/HTTP integration suite.
 */

// Force the environment variable so isFeatureEnabled('SHOW_COMMENTS') is true for this process.
putenv('SHOW_COMMENTS=true');
$_ENV['SHOW_COMMENTS'] = 'true';

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/notification-preferences.php';
require_once __DIR__ . '/../api/notification-email-renderer.php';
require_once __DIR__ . '/../api/practice-security.php';

$pass = 0;
$fail = 0;

function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$message}\n";
    } else {
        $fail++;
        echo "FAIL: {$message}\n";
    }
}

// 1. Mention is only a supported email event type when Comments is enabled.
$supported = getSupportedEmailEventTypes();
assertTrue(in_array('mention', $supported, true), 'mention is in supported email event types when SHOW_COMMENTS=true');

// 2. Rendered mention email is generic and never embeds comment/case/PHI.
$rendered = renderNotificationEmail('en-US', 'notifications.email.subject', 'notifications.email.body', [
    'from' => 'Dr. Sender',
    'event_type' => 'mention',
], 123, 'http://localhost/DentaTrak');

$dangerousSubstrings = [
    'patient', 'Patient',
    'crown', 'Crown', // generic; using as a proxy for PHI
    'veneer', 'Veneer',
    'comment text',
    'Hello @',
    'attachment',
    'due date',
    'Smith',
];
$hasPhi = false;
foreach ($dangerousSubstrings as $bad) {
    if (stripos($rendered['subject'] . $rendered['html'] . $rendered['text'], $bad) !== false) {
        $hasPhi = true;
        break;
    }
}
assertTrue(!$hasPhi, 'Mention email subject/body contains no comment text or patient-like terms');
assertTrue(strpos($rendered['html'], 'notification_id=123') !== false, 'Mention email deep link uses notification_id only');

// 3. Event type strings are the expected keys and not falling back to the key itself.
$mentionEvent = tForLocale('en-US', 'notifications.email.event.mention', ['from' => 'Dr. Sender']);
$mentionPref = tForLocale('en-US', 'preferences.events.mention', []);
assertTrue($mentionEvent !== 'notifications.email.event.mention', 'Mention event translation has a value');
assertTrue(stripos($mentionEvent, 'mention') !== false, 'Mention event translation text contains the generic word');
assertTrue($mentionPref !== 'preferences.events.mention', 'Mention preference translation has a value');
assertTrue(stripos($mentionPref, 'mention') !== false || stripos($mentionPref, '@') !== false, 'Mention preference translation describes @mentions');

// 4. The security helpers that enforce exact mention identity and worker revalidation are available.
assertTrue(function_exists('getUserEmail'), 'getUserEmail helper exists for worker revalidation');
assertTrue(function_exists('canUserAccessCase'), 'canUserAccessCase case access helper exists');
$reflect = new ReflectionFunction('canUserAccessCase');
assertTrue($reflect->getNumberOfParameters() >= 3, 'canUserAccessCase accepts an explicit userId for worker revalidation');
assertTrue(function_exists('getCaseAuthorizedUsers'), 'getCaseAuthorizedUsers helper exists for server-side mention validation');

// 5. Notification deep-link endpoint enforces CSRF.
$destinationSource = file_get_contents(__DIR__ . '/../api/notification-destination.php');
assertTrue(strpos($destinationSource, 'requireCsrfToken()') !== false, 'notification-destination.php requires CSRF token');

echo "\n";
echo "{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}
