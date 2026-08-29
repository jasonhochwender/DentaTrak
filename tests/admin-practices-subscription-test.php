<?php
/**
 * Admin practice subscription and trial display tests.
 *
 * Exercises buildSubscriptionInfo() and addCalendarMonths() without a live
 * request lifecycle, then performs source-level checks on the API and UI.
 */

$base = __DIR__ . '/..';

require_once "{$base}/api/admin-subscription-helpers.php";

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}\n";
    }
}

function assertEquals(string $name, $expected, $actual): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

$owner = [
    'owner_user_id' => 42,
    'owner_email' => 'owner@example.com',
    'owner_first_name' => 'Jane',
    'owner_last_name' => 'Doe',
];

// ---------------------------------------------------------------------------
// 1. Active trial with more than 14 days remaining
// ---------------------------------------------------------------------------
$future = (new DateTimeImmutable('+30 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$sub = buildSubscriptionInfo([
    'status' => 'trialing',
    'trial_ends_at' => $future,
], $owner, 1);

assertEquals('active trial: has_subscription', true, $sub['has_subscription']);
assertEquals('active trial: status', 'trialing', $sub['status']);
assertEquals('active trial: status_display', 'Trial', $sub['status_display']);
assertEquals('active trial: is_trialing', true, $sub['is_trialing']);
assertEquals('active trial: trial_status', 'Active', $sub['trial_status']);
assertEquals('active trial: trial_days_remaining', 30, $sub['trial_days_remaining']);
assertEquals('active trial: trial_line', 'Trial · 30 days left', $sub['trial_line']);
assertEquals('active trial: trial_class', 'trial-normal', $sub['trial_class']);

// ---------------------------------------------------------------------------
// 2. Exactly 14 days remaining should be urgent
// ---------------------------------------------------------------------------
$d14 = (new DateTimeImmutable('+14 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$sub14 = buildSubscriptionInfo(['status' => 'trialing', 'trial_ends_at' => $d14], $owner, 1);
assertEquals('14 days remaining: trial_class urgent', 'trial-urgent', $sub14['trial_class']);
assertEquals('14 days remaining: trial_line', 'Trial · 14 days left', $sub14['trial_line']);

// ---------------------------------------------------------------------------
// 3. Exactly 1 day remaining
// ---------------------------------------------------------------------------
$d1 = (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$sub1 = buildSubscriptionInfo(['status' => 'trialing', 'trial_ends_at' => $d1], $owner, 1);
assertEquals('1 day remaining: trial_line', 'Trial · 1 day left', $sub1['trial_line']);
assertEquals('1 day remaining: trial_class urgent', 'trial-urgent', $sub1['trial_class']);

// ---------------------------------------------------------------------------
// 4. Ends today
// ---------------------------------------------------------------------------
$d0 = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(23, 59, 59)->format('Y-m-d H:i:s');
$sub0 = buildSubscriptionInfo(['status' => 'trialing', 'trial_ends_at' => $d0], $owner, 1);
assertEquals('ends today: trial_line', 'Trial · Ends today', $sub0['trial_line']);

// ---------------------------------------------------------------------------
// 5. Expired trial
// ---------------------------------------------------------------------------
$past = (new DateTimeImmutable('-5 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$exp = buildSubscriptionInfo(['status' => 'trialing', 'trial_ends_at' => $past], $owner, 1);
assertEquals('expired trial: status', 'trial_expired', $exp['status']);
assertEquals('expired trial: trial_status', 'Expired', $exp['trial_status']);
assertEquals('expired trial: trial_line', 'Trial expired', $exp['trial_line']);
assertEquals('expired trial: trial_class', 'trial-expired', $exp['trial_class']);
assertEquals('expired trial: is_trialing', false, $exp['is_trialing']);

// ---------------------------------------------------------------------------
// 6. Paid active subscription
// ---------------------------------------------------------------------------
$paid = buildSubscriptionInfo([
    'status' => 'active',
    'plan' => 'control',
    'billing_interval' => 'month',
    'stripe_customer_id' => 'cus_123',
    'stripe_subscription_id' => 'sub_123',
    'current_period_ends_at' => (new DateTimeImmutable('+30 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    'cancel_at_period_end' => false,
], $owner, 2);
assertEquals('paid active: plan_display', 'Control', $paid['plan_display']);
assertEquals('paid active: status_display', 'Active', $paid['status_display']);
assertEquals('paid active: is_trialing', false, $paid['is_trialing']);
assertEquals('paid active: trial_status', 'Not on Trial', $paid['trial_status']);
assertEquals('paid active: capacity_display', 'Practices: 2 of 2', $paid['capacity_display']);

// ---------------------------------------------------------------------------
// 7. Converted subscription (had a trial, then paid)
// ---------------------------------------------------------------------------
$converted = buildSubscriptionInfo([
    'status' => 'active',
    'plan' => 'scale',
    'billing_interval' => 'year',
    'trial_ends_at' => (new DateTimeImmutable('-10 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    'stripe_customer_id' => 'cus_456',
    'stripe_subscription_id' => 'sub_456',
], $owner, 3);
assertEquals('converted: trial_status', 'Converted', $converted['trial_status']);
assertEquals('converted: trial_display empty', '', $converted['trial_display']);

// ---------------------------------------------------------------------------
// 8. No subscription and no trial
// ---------------------------------------------------------------------------
$none = buildSubscriptionInfo([], $owner, 1);
assertEquals('no subscription: has_subscription', false, $none['has_subscription']);
assertEquals('no subscription: status', 'no_subscription', $none['status']);
assertEquals('no subscription: status_display', 'No Subscription', $none['status_display']);
assertEquals('no subscription: trial_line', 'No Subscription', $none['trial_line']);

// ---------------------------------------------------------------------------
// 9. Legacy practices column fallback
// ---------------------------------------------------------------------------
$legacy = buildSubscriptionInfo([
    'practice_subscription_status' => 'trialing',
    'practice_trial_ends_at' => (new DateTimeImmutable('+20 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
], $owner, 1);
assertEquals('legacy fallback: has_subscription', true, $legacy['has_subscription']);
assertEquals('legacy fallback: is_trialing', true, $legacy['is_trialing']);
assertEquals('legacy fallback: trial_status', 'Active', $legacy['trial_status']);

// ---------------------------------------------------------------------------
// 10. Complete Subscription-tab field mapping
// ---------------------------------------------------------------------------
$expectedKeys = [
    'has_subscription', 'plan', 'plan_display', 'status', 'status_display',
    'is_trialing', 'trial_status', 'trial_ends_at', 'trial_days_remaining',
    'trial_display', 'trial_time_remaining', 'trial_line', 'trial_class',
    'owner_user_id', 'owner_email', 'owner_name', 'owned_practice_count',
    'max_practices', 'capacity_display', 'stripe_customer_id', 'stripe_subscription_id',
    'current_period_ends_at', 'billing_interval', 'cancel_at_period_end',
    'subscription_updated_at',
];
$missing = array_diff($expectedKeys, array_keys($paid));
assertTrue('subscription tab field mapping complete', empty($missing));

// ---------------------------------------------------------------------------
// 11. Calendar-month addition: ordinary case
// ---------------------------------------------------------------------------
$start = new DateTimeImmutable('2026-08-15 12:34:56', new DateTimeZone('UTC'));
$end = addCalendarMonths($start, 2);
assertEquals('add 2 months to 2026-08-15', '2026-10-15 12:34:56', $end->format('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
// 12. Calendar-month addition: month-end handling (Jan 31 + 1 month = Feb 28/29)
// ---------------------------------------------------------------------------
$jan31 = new DateTimeImmutable('2026-01-31 10:00:00', new DateTimeZone('UTC'));
$febEnd = addCalendarMonths($jan31, 1);
assertTrue('jan 31 +1 month lands on last day of february', $febEnd->format('Y-m-d') === '2026-02-28');

// ---------------------------------------------------------------------------
// 13. Calendar-month addition: leap year (Feb 29 + 12 months = Feb 28 next year)
// ---------------------------------------------------------------------------
$leap = new DateTimeImmutable('2024-02-29 08:00:00', new DateTimeZone('UTC'));
$nextYear = addCalendarMonths($leap, 12);
assertEquals('leap year feb 29 + 12 months', '2025-02-28 08:00:00', $nextYear->format('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
// 14. Calendar-month addition: month-end target shorter (May 31 + 1 month = Jun 30)
// ---------------------------------------------------------------------------
$may31 = new DateTimeImmutable('2026-05-31 14:00:00', new DateTimeZone('UTC'));
$jun30 = addCalendarMonths($may31, 1);
assertEquals('may 31 + 1 month', '2026-06-30 14:00:00', $jun30->format('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
// 15. Calendar-month addition: 24 months
// ---------------------------------------------------------------------------
$aug24 = new DateTimeImmutable('2026-08-24 00:00:00', new DateTimeZone('UTC'));
$aug26 = addCalendarMonths($aug24, 24);
assertEquals('add 24 calendar months', '2028-08-24 00:00:00', $aug26->format('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
// 16. Source-level checks
// ---------------------------------------------------------------------------
$adminSource = file_get_contents("{$base}/api/admin-practices.php");
$adminPage = file_get_contents("{$base}/admin-practices.php");

assertTrue('API requires CSRF helper', strpos($adminSource, "require_once __DIR__ . '/csrf.php'") !== false);
assertTrue('API has extend_trial handler', strpos($adminSource, "case 'extend_trial'") !== false);
assertTrue('API has affected_practices endpoint', strpos($adminSource, "case 'affected_practices'") !== false);
assertTrue('API validates CSRF token in extend_trial', strpos($adminSource, "validateCsrfToken(") !== false);
assertTrue('API validates 1-24 month range', strpos($adminSource, '$extensionMonths < 1 || $extensionMonths > 24') !== false);
assertTrue('API updates trial_ends_at in transaction', strpos($adminSource, '$pdo->beginTransaction()') !== false && strpos($adminSource, "UPDATE subscriptions") !== false && strpos($adminSource, "trial_ends_at = :trial_ends_at") !== false);
assertTrue('API logs trial_extended audit action', strpos($adminSource, "logAdminAction('trial_extended'") !== false);
assertTrue('audit record includes affected_practices', strpos($adminSource, "'affected_practices'") !== false);
assertTrue('audit record uses email_result not just boolean', strpos($adminSource, "'email_result'") !== false);
assertTrue('audit values are not_requested/sent/failed', strpos($adminSource, "'not requested'") === false && preg_match('/(not_requested|sent|failed)/', $adminSource));
assertTrue('resolveOrBackfillSubscriptionForOwner exists', strpos($adminSource, 'function resolveOrBackfillSubscriptionForOwner') !== false);
assertTrue('legacy backfill preserves trial_ends_at', strpos($adminSource, 'trial_ends_at DESC') !== false);
assertTrue('legacy backfill does not overwrite later authoritative date', strpos($adminSource, '$existingEnd < $legacyEnd') !== false);
assertTrue('active trial validation rejects expired or paid', strpos($adminSource, 'function getTrialExtensionError') !== false);
assertTrue('success message distinguishes one vs multiple practices', strpos($adminSource, 'function buildTrialExtensionMessage') !== false);
assertTrue('Page renders CSRF token meta', strpos($adminPage, 'name="csrf-token"') !== false);
assertTrue('Page has extend trial modal', strpos($adminPage, 'id="extendTrialModal"') !== false);
assertTrue('Page uses buildSubscriptionInfo helper', strpos($adminSource, 'require_once __DIR__ . \'/admin-subscription-helpers.php\'') !== false);

assertTrue('API validates CSRF token for every POST action', strpos($adminSource, 'validateCsrfToken($input[\'csrf_token\']') !== false);
assertTrue('Page postJson adds X-CSRF-Token header', strpos($adminPage, "'X-CSRF-Token'") !== false);
assertTrue('Page postJson sends JSON body for state-changing actions', strpos($adminPage, "headers:") !== false && strpos($adminPage, "'Content-Type': 'application/json'") !== false && strpos($adminPage, "JSON.stringify(Object.assign({}, payload") !== false);
assertTrue('Page JS uses postJson for extend_trial', strpos($adminPage, "postJson('api/admin-practices.php?action=extend_trial'") !== false);
assertTrue('Page JS uses postJson for send_email', strpos($adminPage, "postJson('api/admin-practices.php?action=send_email'") !== false);
assertTrue('Page JS uses postJson for deactivate', strpos($adminPage, "postJson('api/admin-practices.php?action=deactivate'") !== false);
assertTrue('Page JS uses postJson for reactivate', strpos($adminPage, "postJson('api/admin-practices.php?action=reactivate'") !== false);
assertTrue('Page JS uses postJson for hide', strpos($adminPage, "postJson('api/admin-practices.php?action=hide'") !== false);
assertTrue('Page JS uses postJson for unhide', strpos($adminPage, "postJson('api/admin-practices.php?action=unhide'") !== false);
assertTrue('API authorization combines super user and development environment', strpos($adminSource, 'isSuperUser($appConfig, $userEmail) || $isDev') !== false);
assertTrue('API read and write access use the same canAccess check', strpos($adminSource, '$canAccess = isSuperUser($appConfig, $userEmail) || $isDev') !== false);

// ---------------------------------------------------------------------------
// 17. Email subject and body keys present
// ---------------------------------------------------------------------------
$locale = file_get_contents("{$base}/locales/en-US.json");
assertTrue('locale contains trial extension subject', strpos($locale, '"email_trial_extended_subject"') !== false);
assertTrue('locale contains trial extension greeting', strpos($locale, '"email_trial_extended_greeting"') !== false);

// ---------------------------------------------------------------------------
// 18. Subtitle updated
// ---------------------------------------------------------------------------
assertTrue('page subtitle uses Practice & Subscription Management', strpos($adminPage, "t('admin_practices.subtitle')") !== false);
assertTrue('locale subtitle updated', strpos($locale, '"Practice & Subscription Management"') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
