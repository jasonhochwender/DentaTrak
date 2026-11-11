<?php
/**
 * Marketing Outreach pre-launch unit tests. No database or network.
 *
 * Covers:
 *  - OutreachEligibility: initial vs follow-up rules and every permanent
 *    exclusion (DNC, unsubscribed, bounced, invalid, suppressed contexts)
 *  - McpSheetSync::preCheck with an injected snapshot (kind-aware)
 *  - MarketingSendingPolicy: disabled / allowlist / unrestricted modes
 *  - MarketingFooter: approved element order, logo, unsubscribe, headers
 *
 * Run: php tests/marketing-eligibility-test.php
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';
require_once $baseDir . '/api/marketing/OutreachEligibility.php';
require_once $baseDir . '/api/marketing/MarketingSendingPolicy.php';
require_once $baseDir . '/api/marketing/McpSheetSync.php';
require_once $baseDir . '/api/marketing/MarketingFooter.php';

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}

// ---------------------------------------------------------------- sheet fixture
$header = ['Public Email', 'Practice Name', 'Parent Organization', 'Outreach Status',
    'Do Not Contact', 'First Contact Date', 'Last Contact Date', 'Outreach Notes',
    'Response Status', 'Follow-up Date'];
$rows = [
    // 0 -> sheet row 2: fresh prospect, never contacted
    ['new@practice-a.com', 'Practice A', '', 'New', '', '', '', '', '', ''],
    // 1 -> row 3: contacted earlier with evidence (contact date)
    ['sent@practice-b.com', 'Practice B', '', 'Sent', '', '2026-10-01', '2026-10-01', '', '', '2026-11-01'],
    // 2 -> row 4: contacted via ledger-only evidence is covered separately;
    //          this row is 'Contacted' with a Gmail id in notes
    ['notes@practice-c.com', 'Practice C', '', 'Contacted', '', '', '', 'Gmail abcdef123456 sent intro', '', ''],
    // 3 -> row 5: replied -> hard exclusion for follow-ups
    ['replied@practice-d.com', 'Practice D', '', 'Replied', '', '2026-09-01', '', '', 'Replied', ''],
    // 4 -> row 6: Do Not Contact flag
    ['dnc@practice-e.com', 'Practice E', '', 'New', 'Yes', '', '', '', '', ''],
    // 5 -> row 7: status literally "Do Not Contact"
    ['dncstatus@practice-f.com', 'Practice F', '', 'Do Not Contact', '', '', '', '', '', ''],
    // 6 -> row 8: unsubscribed status
    ['unsub@practice-g.com', 'Practice G', '', 'Unsubscribed', '', '', '', '', '', ''],
    // 7 -> row 9: bounced status
    ['bounced@practice-h.com', 'Practice H', '', 'Bounced', '', '', '', '', '', ''],
    // 8 -> row 10: invalid status
    ['bad@practice-i.com', 'Practice I', '', 'Invalid', '', '', '', '', '', ''],
    // 9 -> row 11: second contact at same domain as row 1 (sent@practice-b.com)
    ['other@practice-b.com', 'Practice B West', '', 'New', '', '', '', '', '', ''],
    // 10 -> row 12: same parent org as row 1
    ['sibling@practice-j.com', 'Practice J', 'Alpha Dental Group', '', 'New', '', '', '', '', ''],
    // 11 -> row 13: contacted row sharing that parent org
    ['lead@alpha-group.com', 'Alpha Lead', 'Alpha Dental Group', 'Sent', '', '2026-09-15', '', '', '', ''],
    // 12 -> row 14: 'Sent' with NO evidence (no dates, no gmail id)
    ['noevidence@practice-k.com', 'Practice K', '', 'Sent', '', '', '', '', '', ''],
];
$idx = OutreachEligibility::index($header, $rows);

// ---- initial campaigns ----
$r = OutreachEligibility::evaluate($idx, 'new@practice-a.com', 'initial');
ok('initial: fresh prospect eligible', $r['ok'] && $r['flags'] === []);

$r = OutreachEligibility::evaluate($idx, 'sent@practice-b.com', 'initial');
ok('initial: previously sent prospect refused (already_contacted)',
    !$r['ok'] && in_array('already_contacted', $r['flags'], true));

$r = OutreachEligibility::evaluate($idx, 'notes@practice-c.com', 'initial');
ok('initial: contacted-with-gmail-note refused', !$r['ok'] && in_array('already_contacted', $r['flags'], true));

$r = OutreachEligibility::evaluate($idx, 'other@practice-b.com', 'initial');
ok('initial: same-domain-as-contacted refused (duplicate_org)',
    !$r['ok'] && in_array('duplicate_org', $r['flags'], true));

$r = OutreachEligibility::evaluate($idx, 'sibling@practice-j.com', 'initial');
ok('initial: same-parent-org-as-contacted refused (duplicate_org)',
    !$r['ok'] && in_array('duplicate_org', $r['flags'], true));

// ---- follow-up campaigns ----
$r = OutreachEligibility::evaluate($idx, 'sent@practice-b.com', 'followup');
ok('followup: valid prior outreach (contact date) permitted', $r['ok'] && $r['prior_outreach']);

$r = OutreachEligibility::evaluate($idx, 'notes@practice-c.com', 'followup');
ok('followup: prior outreach proven by Gmail note permitted', $r['ok'] && $r['prior_outreach']);

$r = OutreachEligibility::evaluate($idx, 'noevidence@practice-k.com', 'followup');
ok('followup: Sent status without evidence refused (no_prior_outreach)',
    !$r['ok'] && in_array('no_prior_outreach', $r['flags'], true) && !$r['prior_outreach']);

$r = OutreachEligibility::evaluate($idx, 'new@practice-a.com', 'followup');
ok('followup: never-contacted refused (no_prior_outreach)',
    !$r['ok'] && in_array('no_prior_outreach', $r['flags'], true));

$r = OutreachEligibility::evaluate($idx, 'replied@practice-d.com', 'followup');
ok('followup: replied prospect refused', !$r['ok'] && in_array('replied', $r['flags'], true));

// ledger evidence counts as a valid prior outreach record
$r = OutreachEligibility::evaluate($idx, 'new@practice-a.com', 'followup', ['ledger_sent' => true]);
ok('followup: ledger-confirmed send counts as prior outreach', $r['ok'] && $r['prior_outreach']);
$r = OutreachEligibility::evaluate($idx, 'new@practice-a.com', 'initial', ['ledger_sent' => true]);
ok('initial: ledger-confirmed send refused (already_contacted)',
    !$r['ok'] && in_array('already_contacted', $r['flags'], true));

// ---- permanent exclusions apply to BOTH kinds ----
foreach (['initial', 'followup'] as $kind) {
    foreach ([
        'dnc@practice-e.com'      => 'do_not_contact flag',
        'dncstatus@practice-f.com'=> 'Do Not Contact status',
        'unsub@practice-g.com'    => 'unsubscribed status',
        'bounced@practice-h.com'  => 'bounced status',
        'bad@practice-i.com'      => 'invalid status',
    ] as $email => $label) {
        $r = OutreachEligibility::evaluate($idx, $email, $kind);
        ok("$kind: $label excluded", !$r['ok'] && $r['hard']);
    }
    $r = OutreachEligibility::evaluate($idx, 'sent@practice-b.com', $kind,
        ['suppressed' => true, 'org_suppressed' => true]);
    ok("$kind: suppressed + org-suppressed excluded", !$r['ok']
        && in_array('suppressed', $r['flags'], true)
        && in_array('org_suppressed', $r['flags'], true));
    $r = OutreachEligibility::evaluate($idx, 'ghost@nowhere.com', $kind, ['require_in_sheet' => true]);
    ok("$kind: not-in-sheet excluded when required", !$r['ok']
        && in_array('not_in_sheet', $r['flags'], true));
    $r = OutreachEligibility::evaluate($idx, 'not-an-email', $kind);
    ok("$kind: malformed address excluded", !$r['ok'] && in_array('invalid_email', $r['flags'], true));
}

// response status variants also mark replied
$rows2 = $rows; $rows2[1][8] = 'Meeting Booked'; // Response Status column
$idx2 = OutreachEligibility::index($header, $rows2);
$r = OutreachEligibility::evaluate($idx2, 'sent@practice-b.com', 'followup');
ok('followup: Meeting Booked response treated as replied', !$r['ok'] && in_array('replied', $r['flags'], true));

// helpers
ok('cellEmails splits multi-address cells',
    OutreachEligibility::cellEmails('A@x.com, b@y.com; c@z.com') === ['a@x.com', 'b@y.com', 'c@z.com']);
ok('gmailIdsFromNotes extracts Gmail ids',
    OutreachEligibility::gmailIdsFromNotes('intro sent | Gmail abcdef123456 | follow-up Gmail xyz789abcde') === ['abcdef123456', 'xyz789abcde']);

// ---------------------------------------------------------------- McpSheetSync snapshot
$sheet = new McpSheetSync(['header' => $header, 'rows' => $rows]);
$p = $sheet->preCheck('sent@practice-b.com', 'initial');
ok('preCheck initial refuses contacted row', !$p['ok'] && in_array('already_contacted', $p['flags'], true));
$p = $sheet->preCheck('sent@practice-b.com', 'followup');
ok('preCheck followup permits contacted row', $p['ok']);
$p = $sheet->preCheck('dncstatus@practice-f.com', 'followup');
ok('preCheck followup still refuses Do Not Contact', !$p['ok'] && $p['hard']);
$p = $sheet->preCheck('new@practice-a.com', 'followup');
ok('preCheck followup refuses no-prior-outreach', !$p['ok'] && in_array('no_prior_outreach', $p['flags'], true));

// ---------------------------------------------------------------- sending policy
$env = function (array $vars) {
    return static function (string $k) use ($vars) { return $vars[$k] ?? null; };
};
$pol = new MarketingSendingPolicy($env([]));
ok('policy: unset flag = disabled', $pol->mode() === 'disabled' && !$pol->enabled());
ok('policy: disabled blocks any recipient', $pol->check('anyone@x.com')['reason'] === 'sending_disabled');

$pol = new MarketingSendingPolicy($env(['MARKETING_SENDING' => 'true', 'MARKETING_SEND_ALLOWLIST' => 'Test@Example.com, @lab.internal']));
ok('policy: enabled without "*" = allowlist mode', $pol->mode() === 'allowlist');
ok('policy: exact allowlisted address permitted', $pol->check('test@example.com')['allowed']);
ok('policy: allowlisted domain permitted', $pol->check('anyone@lab.internal')['allowed']);
ok('policy: non-allowlisted refused', $pol->check('prospect@realpractice.com')['reason'] === 'recipient_not_allowlisted');

$pol = new MarketingSendingPolicy($env(['MARKETING_SENDING' => '1', 'MARKETING_SEND_ALLOWLIST' => '*']));
ok('policy: "*" allowlist = unrestricted', $pol->mode() === 'unrestricted' && $pol->check('prospect@realpractice.com')['allowed']);

$pol = new MarketingSendingPolicy($env(['MARKETING_SENDING' => 'false', 'MARKETING_SEND_ALLOWLIST' => '*']));
ok('policy: flag off beats "*" allowlist', $pol->mode() === 'disabled');

$pol = new MarketingSendingPolicy($env(['MARKETING_SENDING' => 'true']));
ok('policy: empty allowlist permits nobody', $pol->mode() === 'allowlist' && !$pol->check('a@b.com')['allowed']);
$s = $pol->summary();
ok('policy summary hides addresses', $s['enabled'] === true && $s['mode'] === 'allowlist' && $s['allowlist_count'] === 0);

// ---------------------------------------------------------------- footer
$html = MarketingFooter::html('https://dentatrak.com/unsubscribe.php?t=abc123');
$order = [
    strpos($html, 'Dental Lab Case Tracking'),
    strpos($html, 'Privacy Policy'),
    strpos($html, 'Contact Us'),
    strpos($html, 'All rights reserved'),
    strpos($html, 'PO Box 1'),
    strpos($html, 'Tryon, NC 28782'),
    strpos($html, MarketingFooter::logoUrl()),
    strpos($html, 'unsubscribe here'),
];
$ordered = !in_array(false, $order, true);
for ($i = 1; $ordered && $i < count($order); $i++) { $ordered = $order[$i] > $order[$i - 1]; }
ok('footer: all required elements present in approved order', $ordered);
ok('footer: copyright holder + year', strpos($html, 'DentaTrak LLC') !== false && strpos($html, 'All rights reserved') !== false);
ok('footer: logo is standard asset linked to site', strpos($html, 'href="https://dentatrak.com"') !== false
    && strpos($html, 'dentatrak-logo-standard') !== false);
ok('footer: unsubscribe is a link, not mailto',
    strpos($html, '<a href="https://dentatrak.com/unsubscribe.php?t=abc123"') !== false
    && preg_match('/mailto:[^"\'<]*unsubscribe/i', $html) === 0);
$h = MarketingFooter::headers('https://dentatrak.com/unsubscribe.php?t=abc123');
ok('footer: RFC 8058 headers', $h['List-Unsubscribe'] === '<https://dentatrak.com/unsubscribe.php?t=abc123>'
    && $h['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click');

echo "\nmarketing-eligibility-test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
