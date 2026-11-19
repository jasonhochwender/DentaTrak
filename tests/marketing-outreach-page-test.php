<?php
/**
 * Static regression test for the Marketing Outreach admin page shell.
 *
 * Regression: `json_encode(ma('generated_ok')).replace('{n}', '%s')` was
 * emitted inside a <?php ?> block - PHP concatenated the JSON string with a
 * call to the nonexistent replace(), a fatal error that killed page render
 * and left every tab dead.
 *
 * Verifies:
 *  - no PHP segment uses JS-style .replace(/->replace( calls
 *  - every <?php ?> interpolation inside <script> emits json_encode()
 *  - the generated_ok substitution happens in JavaScript
 *  - all six tabs + panes exist and the page self-initializes
 *  - API failures surface through showErr()
 *
 * No database or browser required.
 */

declare(strict_types=1);

$pagePath = __DIR__ . '/../marketing-outreach.php';
$failures = [];
$passes = 0;

function check(bool $cond, string $message): void
{
    global $failures, $passes;
    if ($cond) { $passes++; } else { $failures[] = $message; }
}

$html = @file_get_contents($pagePath);
check($html !== false, 'marketing-outreach.php readable');
if ($html === false) {
    echo "marketing-outreach-page-test: $passes passed, " . count($failures) . " failed\n";
    foreach ($failures as $f) echo "  FAIL: $f\n";
    exit(1);
}

// ---- 1. PHP segments must not contain JS-style method calls ----
preg_match_all('/<\?php(.*?)\?>/s', $html, $phpBlocks);
$badPhp = [];
foreach ($phpBlocks[1] as $i => $code) {
    if (preg_match('/\.\s*replace\s*\(|->\s*replace\s*\(|\.\s*sprintf\s*\(/', $code)) {
        $badPhp[] = "block $i: " . trim(preg_replace('/\s+/', ' ', $code));
    }
}
check($badPhp === [], 'no JS-style .replace(/->replace( inside PHP blocks'
    . ($badPhp ? ' -> ' . implode(' | ', array_slice($badPhp, 0, 3)) : ''));

// ---- 2. Script-section interpolations must be json_encode ----
$scriptStart = strpos($html, '<script>');
$scriptEnd = strrpos($html, '</script>');
check($scriptStart !== false && $scriptEnd !== false && $scriptEnd > $scriptStart, 'inline script block present');
$script = substr($html, $scriptStart, $scriptEnd - $scriptStart);
preg_match_all('/<\?php(.*?)\?>/s', $script, $scriptPhp);
$badScriptPhp = [];
foreach ($scriptPhp[1] as $code) {
    if (!preg_match('/json_encode\s*\(/', $code)) {
        $badScriptPhp[] = trim(preg_replace('/\s+/', ' ', $code));
    }
}
check($badScriptPhp === [], 'every PHP interpolation in <script> uses json_encode()'
    . ($badScriptPhp ? ' -> ' . implode(' | ', array_slice($badScriptPhp, 0, 3)) : ''));

// ---- 3. {n} substitution happens in JavaScript ----
// Generation is background-drained; the completion summary substitutes
// counts client-side (genDone carries {n}/{f} placeholders).
check(strpos($html, "STR.genDone.replace('{n}'") !== false
    && strpos($html, 'gen_done') !== false,
    'gen_done {n}/{f} substitution performed in JS, not PHP');

// ---- 4. Seven tabs, panes, and self-init ----
foreach (['overview', 'prospects', 'campaigns', 'followups', 'replies', 'templates', 'history'] as $tab) {
    check(strpos($html, 'data-tab="' . $tab . '"') !== false, "tab button for $tab");
    check(strpos($html, 'id="moTab-' . $tab . '"') !== false, "pane for $tab");
}
check(substr_count($html, '<button class="mo-tab') === 7, 'exactly seven tab buttons');
check(strpos($html, "loadTab('overview')") !== false, 'page self-initializes overview tab');

// ---- 5. API failure path surfaces an error ----
check(strpos($html, 'function showErr(') !== false, 'showErr helper present');
check(preg_match('/apiGet\(\'overview\'\)[\s\S]{0,200}showErr/', $script) === 1
    || strpos($script, '.catch(function () { showErr(STR.loadFailed); })') !== false,
    'API failure calls showErr');
check(strpos($script, "showErr(d.error || STR.loadFailed)") !== false, 'API error payload shown');

// ---- 6. Redundant sending notices removed; bounce actions present ----
check(strpos($html, 'sending_unrestricted_banner') === false && strpos($html, 'sendingUnrestricted') === false,
    'live-sending banner string not referenced');
check(strpos($html, 'approved_not_sent') === false && strpos($html, 'approvedNotSent') === false,
    'misleading approved-not-sent message not referenced');
check(preg_match("/sending\.mode === 'unrestricted'\) \{\s*el\.textContent = '';\s*el\.className = 'mo-sending mo-hidden';/", $html) === 1,
    'unrestricted sending renders no banner');
check(strpos($html, "STR.approvedReady.replace('{n}', d.approved || 0)") !== false, 'approval feedback uses the server-reported count');
$crmJs = (string)@file_get_contents(__DIR__ . '/../js/marketing-crm.js');
check(strpos($crmJs, 'approvedNotSent') === false && strpos($crmJs, "S('approvedReady')") !== false, 'CRM approve feedback updated');
foreach (glob(__DIR__ . '/../locales/*.json') as $lf) {
    $raw = (string)file_get_contents($lf);
    $loc = basename($lf);
    check(json_decode($raw) !== null, "$loc parses");
    check(strpos($raw, '"sending_unrestricted_banner"') === false && strpos($raw, '"approved_not_sent"') === false, "$loc: removed notices gone");
    foreach (['approved_ready', 'reply_act_suppress', 'reply_act_suppress_review', 'reply_tip_review', 'reply_tip_suppress',
              'reply_tip_suppress_review', 'reply_bounce_help', 'reply_confirm_suppress'] as $k) {
        check(strpos($raw, '"' . $k . '"') !== false, "$loc: $k present");
    }
}
check(strpos($raw = (string)file_get_contents(__DIR__ . '/../locales/en-US.json'), 'Live sending is ON') === false
    && strpos($raw, 'Nothing was sent because sending is disabled') === false, 'en-US: removed notice text gone');
foreach (['data-bulk="review"', 'data-bulk="suppress"', 'data-bulk="suppress_review"', "apiPost('reply_action'",
          'data-ract="review"', 'data-ract="suppress"', 'data-ract="suppress_review"', 'mo-reply-sel'] as $needle) {
    check(strpos($html, $needle) !== false, "replies UI has $needle");
}
$api = (string)file_get_contents(__DIR__ . '/../api/marketing-admin.php');
check(strpos($api, "case 'reply_action':") !== false && strpos($api, "mo_audit(\$pdo, \$userEmail, 'reply_action'") !== false,
    'reply_action endpoint exists and is audited');
// ---- 7. Organization-level Do Not Contact ----
foreach (["case 'org_dnc_set':", "case 'org_dnc_clear':", "mo_audit(\$pdo, \$userEmail, 'org_dnc_set'", "mo_audit(\$pdo, \$userEmail, 'org_dnc_clear'",
          "'org_do_not_contact'", "'dnc_scope'"] as $needle) {
    check(strpos($api, $needle) !== false, "admin API has $needle");
}
check(preg_match("/case 'approve': \{[\s\S]{0,1500}isOrgBlocked/", $api) === 1, 'approval rechecks organization suppression');
foreach (["apiPost('org_dnc_set'", "apiPost('org_dnc_clear'", 'confirm_name', 'function flagBadges', "id=\"moOrgDnc\"", "S('orgDncExplain')"] as $needle) {
    check(strpos($crmJs, $needle) !== false, "CRM UI has $needle");
}
check(strpos($html, 'value="org_do_not_contact"') !== false, 'organization DNC flag filter option');
foreach (glob(__DIR__ . '/../locales/*.json') as $lf) {
    $raw = (string)file_get_contents($lf);
    foreach (['org_dnc_action', 'org_dnc_badge', 'contact_dnc_badge', 'org_dnc_explain', 'org_dnc_remove_explain', 'flag_org_do_not_contact'] as $k) {
        check(strpos($raw, '"' . $k . '"') !== false, basename($lf) . ": $k present");
    }
}
echo "marketing-outreach-page-test: $passes passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "  FAIL: $f\n";
exit($failures ? 1 : 0);
