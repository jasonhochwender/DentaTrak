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
assertContains($api, '/preCheck\(\$email, \$camp\[\'kind\'\], \$ctx\)/', 'send must run the kind-aware sheet pre-check');
assertContains($api, '/mo_audit\(/', 'mutations must write the audit log');
assertContains($api, '/SET status=\'sending\', updated_at=NOW\(\) WHERE id=\? AND \(/', 'send must atomically claim the campaign');
assertContains($api, '/send already in progress/', 'concurrent send must be refused');

// ---- 5b. Pre-launch hardening ----
$elig = $baseDir . '/api/marketing/OutreachEligibility.php';
$policy = $baseDir . '/api/marketing/MarketingSendingPolicy.php';
$thread = $baseDir . '/api/marketing/OutreachThreadResolver.php';
$hardening = $baseDir . '/migrations/2026_11_12_marketing_send_hardening.php';
$schemaCheck = $baseDir . '/scripts/marketing-schema-check.php';

assertContains($flags, '/MARKETING_SENDING.\s*=>\s*false/', 'MARKETING_SENDING must default to false');
assertContains($flags, '/MARKETING_SEND_ALLOWLIST/', 'allowlist env var must be documented in flags');
// Administration vs sending separation
assertContains($api, '/sending_disabled/', 'admin send must refuse when sending is disabled');
assertContains($api, '/new MarketingSendingPolicy\(\)/', 'admin API must build the sending policy');
assertContains($sendEp, '/sending_disabled|recipient_not_allowlisted/', 'token endpoint must surface delivery-control blocks');
// Kind-aware eligibility
assertContains($elig, '/KIND_INITIAL/', 'eligibility must define campaign kinds');
assertContains($elig, '/no_prior_outreach/', 'follow-ups must require prior outreach');
assertContains($elig, '/already_contacted/', 'initial outreach must refuse contacted prospects');
assertContains($elig, '/do_not_contact/', 'eligibility must enforce Do Not Contact');
assertContains($api, '/mo_elig_ctx\(/', 'send must build per-recipient eligibility context');
// Threading
assertContains($thread, '/campaign_record/', 'thread resolver must use campaign records');
assertContains($thread, '/send_ledger/', 'thread resolver must use the send ledger');
assertContains($thread, '/sheet_notes/', 'thread resolver must use sheet notes');
assertContains($thread, '/ambiguous/', 'thread resolver must refuse ambiguous threads');
assertContains($api, '/resolve_threads/', 'admin API must expose thread re-resolution');
// Uncertain/reconciliation + sheet write-back
assertContains($api, '/resolve_uncertain/', 'admin API must expose operator resolution');
assertContains($api, '/reconcile/', 'admin API must expose ledger reconciliation');
assertContains($api, '/retry_sheet_sync/', 'admin API must expose sheet write-back retry');
assertContains($api, '/sheet_sync_status/', 'recipients must track sheet write-back state');
assertContains($mailer, '/uncertain/', 'mailer must model uncertain outcomes');
assertContains($mailer, '/X-DentaTrak-Send-Id/', 'mailer must stamp sends for reconciliation');
assertContains($mailer, '/idempotency_key/', 'mailer must persist the idempotency key');
// Recovery actions must never send mail: they are separate cases that do
// not call MarketingMailer::send (checked structurally below).
$apiSrc = (string)@file_get_contents($api);
foreach (['reconcile', 'resolve_uncertain', 'retry_sheet_sync', 'resolve_threads'] as $act) {
    if (preg_match('/case \'' . $act . '\'\s*:\s*\{(.*?)\n    \}/s', $apiSrc, $m)) {
        ok2(!str_contains($m[1], "->send("), "action '$act' must not send mail");
    }
}
function ok2(bool $cond, string $msg): void {
    global $failures, $passes;
    if ($cond) $passes++; else $failures[] = $msg;
}
// Migrations + schema check
assertContains($hardening, '/idempotency_key/', 'hardening migration must add the idempotency key');
assertContains($hardening, '/uncertain/', 'hardening migration must allow the uncertain status');
assertContains($schemaCheck, '/--post/', 'schema check must support post verification');

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

// ---- 11. New-conversation acknowledgment + editor + MIME ----
// Follow-ups that cannot be matched to an original Gmail thread must be
// explicitly acknowledged at approval; the send path enforces the flag.
assertContains($api, '/allow_new_threads/', 'approve must require explicit new-thread acknowledgment');
assertContains($api, '/unresolved_threads/', 'approve must fail closed when unthreaded follow-ups are unacknowledged');
assertContains($api, '/new_thread_ack/', 'campaign must persist the new-thread acknowledgment');
assertContains($api, '/new_thread_not_acknowledged/', 'send must skip unacknowledged unthreaded follow-ups');
$mig17 = $baseDir . '/migrations/2026_11_17_marketing_new_thread_ack.php';
assertContains($mig17, '/new_thread_ack/', 'migration must add new_thread_ack column');
// Outbound mail is multipart/alternative with a plain-text part so the
// compliance footer and unsubscribe link reach every client.
assertContains($mailer, '/multipart\/alternative/', 'mailer must send multipart/alternative');
assertContains($mailer, '/text\/plain/', 'mailer must include a plain-text part');
assertContains($mailer, '/MarketingFooter::text\(/', 'plain-text part must carry the text footer');
assertContains($mailer, '/MarketingFooter::html\(/', 'html part must carry the html footer');
// AI prompt must forbid outcome guarantees and invented facts.
assertContains($api, '/Do NOT claim DentaTrak eliminates manual work/', 'AI prompt must ban elimination claims');
assertContains($api, '/never invent facts/', 'AI prompt must forbid invented facts');
assertContains($api, '/complements practice-management software/', 'AI prompt must position as complementary');
// Editor: rich-text surface + plain-text paste + server-side sanitize on save.
assertContains($page, '/mo-body-edit/', 'campaign editor must use the rich-text surface');
assertContains($baseDir . '/js/marketing-crm.js', '/contenteditable="true"/', 'editor must be contenteditable');
assertContains($baseDir . '/js/marketing-crm.js', '/insertText/', 'editor must paste as plain text');
assertContains($api, '/mo_sanitize_html\(\(string\)\(\$in\[\'body_html\'\]/', 'save_message must sanitize HTML');
// Thread status reasons must be explainable in the UI.
foreach (['threadReasonNone','threadReasonAmbiguous','threadReasonUnavailable','threadReasonUnverified','threadReasonInvalid','threadReasonAction','newThreadsWarn','newThreadsAck'] as $k) {
    assertContains($page, '/' . $k . ':/', "page must pass $k to the CRM UI");
}

echo "marketing-admin-test: $passes passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "  FAIL: $f\n";
exit($failures ? 1 : 0);
