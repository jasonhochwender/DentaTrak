<?php
/**
 * Static regression test for js/marketing-crm.js recipient rendering.
 *
 * Regression: renderCampMessages() wired the rich-text editor unconditionally.
 * For non-editable recipients (approved/sent campaigns) there is no
 * .mo-body-edit element, so `bodyEdit.addEventListener` threw a TypeError
 * inside the forEach. The exception aborted the loop before the
 * button[data-act] handlers were attached - "Preview Final Email" did
 * nothing - and propagated to openCampaign()'s .catch(), which showed the
 * global "Could not load marketing data." banner even though the campaign
 * API had succeeded.
 *
 * Verifies:
 *  - editor wiring is guarded by a null check on .mo-body-edit
 *  - previewEmail() surfaces transport failures via showErr
 *  - previewEmail() has a .catch() so render errors cannot fail silently
 *  - the non-editable branch still renders a Preview Final Email button
 *
 * No database or browser required.
 */

declare(strict_types=1);

$jsPath = __DIR__ . '/../js/marketing-crm.js';
$failures = [];
$passes = 0;

function check(bool $cond, string $message): void
{
    global $failures, $passes;
    if ($cond) { $passes++; } else { $failures[] = $message; }
}

$js = @file_get_contents($jsPath);
check($js !== false, 'marketing-crm.js readable');
if ($js === false) {
    echo "marketing-crm-render-test: $passes passed, " . count($failures) . " failed\n";
    foreach ($failures as $f) echo "  FAIL: $f\n";
    exit(1);
}

// The paste/fmt wiring must sit inside a `if (bodyEdit)` guard: the
// listener call must appear after the guard and before the guard's close.
$guardPos = strpos($js, 'if (bodyEdit) {');
$pastePos = strpos($js, "addEventListener('paste'");
check($guardPos !== false && $pastePos !== false && $pastePos > $guardPos,
    'editor paste listener guarded by if (bodyEdit)');
// No unguarded use: every bodyEdit.addEventListener must follow the guard.
check(!preg_match('/var bodyEdit = div\.querySelector\(\'.mo-body-edit\'\);\s*\n\s*bodyEdit\.addEventListener/', $js),
    'no unguarded bodyEdit.addEventListener remains');

// previewEmail must show a meaningful error on failure and never reject silently.
$pvStart = strpos($js, 'function previewEmail(');
check($pvStart !== false, 'previewEmail() exists');
if ($pvStart !== false) {
    $pvEnd = strpos($js, '\n    }', $pvStart);
    $pvBody = substr($js, $pvStart, $pvEnd === false ? 4000 : $pvEnd - $pvStart);
    check(strpos($pvBody, "'preview_message'") !== false, 'previewEmail posts preview_message');
    check(strpos($pvBody, 'showErr(') !== false, 'previewEmail shows an error on failure');
    check(strpos($pvBody, '.catch(') !== false, 'previewEmail has a .catch() handler');
}

// Both editable and read-only recipient branches offer the preview button.
check(substr_count($js, 'data-act="preview"') >= 2, 'preview button rendered for editable and read-only recipients');

echo "marketing-crm-render-test: $passes passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "  FAIL: $f\n";
exit($failures ? 1 : 0);
