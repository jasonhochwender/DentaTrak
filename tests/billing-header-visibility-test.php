<?php
/**
 * Billing header visibility test.
 *
 * Verifies that the user header Billing link and user-menu Billing item are
 * rendered with the correct server-side authorization from the first paint,
 * that the placeholder text is hidden until the billing tier is known, and
 * that the JavaScript loader reveals the link only after it sets final text.
 */

$base = __DIR__ . '/..';

require_once "{$base}/api/feature-flags.php";
require_once "{$base}/api/billing-bypass.php";

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

// Replicate the visibility expression used in main.php.
function billingHeaderVisible(bool $billingEnabled, bool $showBilling, bool $isAdmin, string $email): bool {
    return $billingEnabled && $showBilling && $isAdmin && shouldShowBillingUI($email);
}

// Feature flags are read from $_ENV; configure them for the tests.
$_ENV['BILLING_ENABLED'] = 'true';
$_ENV['SHOW_BILLING'] = 'true';

// 1. Authorization scenarios
assertTrue('Admin with billing enabled and show-billing flag sees Billing',
    billingHeaderVisible(true, true, true, 'admin@example.com'));

assertTrue('Non-admin with billing enabled does not see Billing',
    !billingHeaderVisible(true, true, false, 'admin@example.com'));

assertTrue('Admin with billing feature disabled does not see Billing',
    !billingHeaderVisible(false, true, true, 'admin@example.com'));

assertTrue('Admin with SHOW_BILLING disabled does not see Billing',
    !billingHeaderVisible(true, false, true, 'admin@example.com'));

assertTrue('Bypass user (partner practice) does not see Billing even as admin',
    !billingHeaderVisible(true, true, true, 'owner@premierimplantsanddentures.com'));

// 2. Billing-bypass helpers are consistent with api/billing.php
assertTrue('shouldShowBillingUI returns true for normal email',
    shouldShowBillingUI('admin@example.com') === true);

assertTrue('shouldShowBillingUI returns false for bypass email',
    shouldShowBillingUI('owner@premierimplantsanddentures.com') === false);

// 3. Source-level checks in main.php
$mainSource = file_get_contents("{$base}/main.php");

assertTrue('main.php computes $showBillingHeader using BILLING_ENABLED, SHOW_BILLING, admin and shouldShowBillingUI',
    strpos($mainSource, '$showBillingHeader = isFeatureEnabled(\'BILLING_ENABLED\')') !== false
    && strpos($mainSource, "isFeatureEnabled('SHOW_BILLING')") !== false
    && strpos($mainSource, '$isCurrentUserPracticeAdmin') !== false
    && strpos($mainSource, 'shouldShowBillingUI($user[\'email\'] ?? \'\')') !== false);

assertTrue('main.php uses $showBillingHeader for the header Billing link',
    strpos($mainSource, '<?php if ($showBillingHeader): ?>') !== false
    && preg_match('/id="userBillingTier"[^>]*style="visibility:\s*hidden;"/', $mainSource) === 1);

assertTrue('main.php uses $showBillingHeader for the user-menu Billing item',
    strpos($mainSource, 'id="billingMenuItem"') !== false
    && preg_match('/<\?php if \(\$showBillingHeader\): \?>[\s\S]{0,300}id="billingMenuItem"/', $mainSource) === 1);

assertTrue('main.php user-info Billing link uses $showBillingHeader',
    // The #userBillingTier anchor must be inside a PHP block conditioned on $showBillingHeader.
    preg_match('/<\?php if \(\$showBillingHeader\): \?>[\s\S]{0,80}id="userBillingTier"/', $mainSource) === 1
    // And the old over-permissive condition must not be within the same anchor block.
    && preg_match('/id="userBillingTier"[\s\S]{0,80}BILLING_ENABLED/', $mainSource) === 0);

// 4. Source-level checks in js/app.js
$appSource = file_get_contents("{$base}/js/app.js");

assertTrue('app.js sets billing tier text before revealing the link',
    strpos($appSource, "billingTierElement.textContent = displayText;") !== false
    && strpos($appSource, "billingTierElement.style.visibility = 'visible';") !== false);

assertTrue('app.js reveals the link in the error fallback as well',
    preg_match('/\.catch\s*\(\s*error\s*=>\s*\{.*?billingTierElement\.style\.visibility\s*=\s*[\'"]visible[\'"];/s', $appSource) === 1);

// 5. Make sure the fallback still hides the link if it somehow reaches the page
assertTrue('app.js fallback still hides billing UI when hide_billing_ui is true',
    strpos($appSource, 'if (data.hide_billing_ui)') !== false
    && strpos($appSource, "billingTierElement.style.display = 'none'") !== false);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
