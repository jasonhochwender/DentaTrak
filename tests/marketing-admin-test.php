<?php
/**
 * Static regression test for Marketing Outreach administration.
 *
 * Verifies:
 *  - MARKETING_OUTREACH flag exists and defaults off
 *  - marketing-outreach.php uses the shared admin shell and is gated by
 *    super-user/dev AND the feature flag
 *  - admin nav only shows Marketing when the flag is enabled
 *  - api/marketing-admin.php enforces session, super-user/dev, flag,
 *    and CSRF before any action
 *  - approval binds a content hash; send re-checks suppression and caps
 *    recipients at 10
 *  - marketing-send.php still delegates to the shared mailer and keeps
 *    its token contract
 *  - footer/mailer preserve unsubscribe + suppression mechanics
 *  - locale coverage for marketing_admin in every locale
 *
 * No database or browser required.
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';
$failures = [];
$passes = 0;

function assertContains(string $path, string $pattern, string $message): void
{
    global $failures, $passes;
    $content = @file_get_contents($path);
    if ($content === false || !preg_match($pattern, $content)) {
        $failures[] = $message;
    } else {
        $passes++;
    }
}

function assertNotContains(string $path, string $pattern, string $message): void
{
    global $failures, $passes;
    $content = @file_get_contents($path);
    if ($content === false || preg_match($pattern, $content)) {
        $failures[] = $message;
    } else {
        $passes++;
    }
}

$api = $baseDir . '/api/marketing-admin.php';
$page = $baseDir . '/marketing-outreach.php';
$flags = $baseDir . '/api/feature-flags.php';
$layout = $baseDir . '/admin-layout.php';
$mailer = $baseDir . '/api/marketing/MarketingMailer.php';
$sendEp = $baseDir . '/api/marketing-send.php';
$footer = $baseDir . '/api/marketing/MarketingFooter.php';
$dir = $baseDir . '/api/marketing/ProspectDirectory.php';
$migration = $baseDir . '/migrations/2026_11_03_marketing_admin.php';

// ---- 1. Feature flag ----
assertContains($flags, '/MARKETING_OUTREACH.\s*=>\s*false/', 'MARKETING_OUTREACH must default to false');

// ---- 2. Admin page gating + shell ----
assertContains($page, '/isSuperUser\(\$appConfig, \$userEmail\) \|\| \$isDev/', 'page must gate on super-user or dev');
assertContains($page, '/isFeatureEnabled\(\'MARKETING_OUTREACH\'\)/', 'page must require MARKETING_OUTREACH flag');
assertContains($page, '/paAdminChrome\(\'marketing\'/', 'page must render inside the shared admin shell');
assertContains($page, '/paAdminChromeEnd\(\)/', 'page must close the shared admin shell');
assertContains($page, '/meta name="csrf-token"/', 'page must expose the CSRF token');
assertContains($page, '/api\/marketing-admin\.php/', 'page must call the marketing admin API');

// ---- 3. Nav integration ----
assertContains($layout, '/marketing-outreach\.php/', 'admin nav must link marketing-outreach.php');
assertContains($layout, '/isFeatureEnabled\(\'MARKETING_OUTREACH\'\)/', 'nav item must be flag-gated');

// ---- 4. API gates ----
assertContains($api, '/empty\(\$_SESSION\[\'db_user_id\'\]\)/', 'API must require an authenticated session');
assertContains($api, '/!isSuperUser\(\$appConfig, \$userEmail\) && !\$isDev/', 'API must 403 non-super-users outside dev');
assertContains($api, '/isFeatureEnabled\(\'MARKETING_OUTREACH\'\)/', 'API must 404 when the flag is off');
assertContains($api, '/hash_equals\(\(string\)\(\$_SESSION\[\'csrf_token\'\]/', 'POST mutations must verify the session CSRF token');
assertContains($api, '/mo_fail\(\'unauthenticated\', 401\)/', 'API must 401 unauthenticated requests');
assertContains($api, '/mo_fail\(\'forbidden\', 403\)/', 'API must 403 unauthorized requests');
assertContains($api, '/mo_fail\(\'not_found\', 404\)/', 'API must 404 when flag disabled');

// ---- 5. Send safety ----
assertContains($api, '/const MO_MAX_RECIPIENTS = 10/', 'campaign cap must be 10 recipients');
assertContains($api, '/content_hash/', 'approval must bind a content hash');
assertContains($api, '/content changed since approval/', 'send must refuse content edited after approval');
assertContains($api, '/isSuppressed\(\$email\)/', 'send must re-check email suppression');
assertContains($api, '/isOrgSuppressed\(\$orgRef\)/', 'send must re-check org suppression');
assertContains($api, '/preCheck\(\$email\)/', 'send must run the sheet pre-check');
assertContains($api, '/mo_audit\(/', 'mutations must write the audit log');
assertContains($api, '/status=\'sending\' WHERE id=\? AND status IN/', 'send must atomically claim the campaign');

// ---- 6. Shared mailer + endpoint contract ----
assertContains($sendEp, '/new MarketingMailer\(/', 'marketing-send.php must delegate to the shared mailer');
assertContains($sendEp, '/HTTP_X_OUTREACH_TOKEN/', 'marketing-send.php must keep token auth');
assertContains($mailer, '/marketing_sends/', 'mailer must use the send ledger for idempotency');
assertContains($mailer, '/eligibility\(\[\$to\]\)/', 'mailer must re-check eligibility before send');
assertContains($mailer, '/issueToken\(/', 'mailer must issue per-recipient unsubscribe tokens');
assertContains($mailer, '/List-Unsubscribe/', 'mailer must emit RFC 8058 headers');
assertContains($mailer, '/thread_id/', 'mailer must support Gmail thread_id for follow-ups');

// ---- 7. Footer ----
assertContains($footer, '/PO Box 1/', 'footer must carry the postal address');
assertContains($footer, '/Tryon, NC 28782/', 'footer must carry the city/state/zip');
assertContains($footer, '/dentatrak\.com/', 'footer must link to the site');
assertContains($footer, '/unsubscribe/', 'footer must include an unsubscribe link');
assertContains($footer, '/brandAssetPublicUrl\(\'logo_standard\'\)/', 'footer logo must reference the central Standard Logo asset');

// ---- 8. Prospect directory ----
assertContains($dir, '/missing_contact/', 'directory must flag missing contact details');
assertContains($dir, '/do_not_contact/', 'directory must flag do-not-contact');
assertContains($dir, '/duplicate_org/', 'directory must flag duplicate organizations');
assertContains($dir, '/parseSheetDate/', 'directory must parse mixed sheet date formats');
assertContains($dir, '/followup_ts/', 'directory must expose follow-up timestamps');

// ---- 9. Migration tables ----
foreach (['marketing_templates', 'marketing_campaigns', 'marketing_campaign_recipients', 'marketing_audit_log'] as $t) {
    assertContains($migration, '/CREATE TABLE IF NOT EXISTS ' . $t . '/', "migration must create $t");
}

// ---- 10. Locale coverage ----
$required = ['title','tab_prospects','tab_campaigns','tab_followups','tab_templates','tab_history',
    'confirm_send','approve_warn','send_result','retry_failed','new_campaign','generate_emails'];
foreach (glob($baseDir . '/locales/*.json') as $f) {
    $loc = basename($f);
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || !isset($j['marketing_admin']) || !is_array($j['marketing_admin'])) {
        $failures[] = "$loc missing marketing_admin section";
        continue;
    }
    foreach ($required as $k) {
        if (!isset($j['marketing_admin'][$k]) || $j['marketing_admin'][$k] === '') {
            $failures[] = "$loc marketing_admin.$k missing";
        } else { $passes++; }
    }
    if (!isset($j['admin_nav']['marketing']) || $j['admin_nav']['marketing'] === '') {
        $failures[] = "$loc admin_nav.marketing missing";
    } else { $passes++; }
}

echo "marketing-admin-test: $passes passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "  FAIL: $f\n";
exit($failures ? 1 : 0);
