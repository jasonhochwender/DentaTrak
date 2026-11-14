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
check(strpos($html, "json_encode(ma('generated_ok')); ?>.replace('{n}', d.generated)") !== false,
    'generated_ok {n} substitution performed in JS, not PHP');

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

echo "marketing-outreach-page-test: $passes passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "  FAIL: $f\n";
exit($failures ? 1 : 0);
