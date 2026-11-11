<?php
/**
 * Outreach eligibility rules for the Prospects sheet. Pure: operates on a
 * sheet snapshot plus facts supplied by the caller (suppression store,
 * send ledger), so the same rules serve the prospect list, campaign
 * creation, and the re-check immediately before each send.
 *
 * Exclusions that apply to EVERY campaign kind (never overridable):
 *   invalid_email, suppressed, org_suppressed, do_not_contact,
 *   unsubscribed, bounced, invalid_address
 *
 * Initial outreach additionally refuses:
 *   already_contacted - this prospect has prior outreach (sheet status
 *                       Sent/Contacted/Replied, or a confirmed ledger send)
 *   duplicate_org     - another row at the same email domain or parent
 *                       organization has already been contacted
 *
 * Follow-up additionally refuses:
 *   no_prior_outreach - no valid prior outreach record. A valid record is
 *                       a confirmed send in the ledger, or a sheet status
 *                       of Sent/Contacted backed by a contact date or a
 *                       recorded Gmail message id.
 *   replied           - the prospect answered; that conversation belongs
 *                       to a person, not a campaign.
 *
 * Follow-ups are NOT blocked by already_contacted or duplicate_org: being
 * previously contacted is the precondition, not a disqualifier.
 */
final class OutreachEligibility
{
    public const KIND_INITIAL = 'initial';
    public const KIND_FOLLOWUP = 'followup';

    /** Flags that exclude a recipient regardless of campaign kind. */
    public const HARD_FLAGS = [
        'invalid_email', 'suppressed', 'org_suppressed', 'do_not_contact',
        'unsubscribed', 'bounced', 'invalid_address', 'not_in_sheet',
    ];

    private const CONTACTED = ['sent', 'contacted'];
    private const REPLIED = ['replied'];
    private const STATUS_FLAGS = [
        'do not contact' => 'do_not_contact',
        'do-not-contact' => 'do_not_contact',
        'dnc'            => 'do_not_contact',
        'unsubscribed'   => 'unsubscribed',
        'opted out'      => 'unsubscribed',
        'bounced'        => 'bounced',
        'bounce'         => 'bounced',
        'invalid'        => 'invalid_address',
        'invalid email'  => 'invalid_address',
    ];
    private const REPLIED_RESPONSES = ['replied', 'interested', 'not interested', 'meeting booked', 'declined'];
    private const FALSY = ['', 'no', 'n', 'false', '0'];

    private const COLS = [
        'email'         => ['Public Email', 'Email', 'Contact Email'],
        'status'        => ['Outreach Status', 'Status'],
        'dnc'           => ['Do Not Contact', 'DNC'],
        'parent'        => ['Parent Organization', 'Parent Org'],
        'first_contact' => ['First Contact Date', 'First Contact'],
        'last_contact'  => ['Last Contact Date', 'Last Contact'],
        'notes'         => ['Outreach Notes', 'Notes'],
        'response'      => ['Response Status', 'Response', 'Reply Status'],
    ];

    /** Emails listed in one sheet cell (cells may hold several). */
    public static function cellEmails(string $cell): array
    {
        return preg_split('/[\s,;]+/', strtolower(trim($cell)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Gmail message ids recorded in an Outreach Notes cell ("Gmail <id>"). */
    public static function gmailIdsFromNotes(string $notes): array
    {
        preg_match_all('/\bGmail\s+([A-Za-z0-9_-]{10,})\b/', $notes, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Build a lookup index over a sheet snapshot.
     * @param array $header header row
     * @param array $rows   data rows (row 0 = sheet row 2)
     */
    public static function index(array $header, array $rows): array
    {
        $lower = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
        $col = [];
        foreach (self::COLS as $key => $names) {
            $col[$key] = null;
            foreach ($names as $n) {
                $i = array_search(strtolower($n), $lower, true);
                if ($i !== false) { $col[$key] = $i; break; }
            }
        }
        $cell = static function (array $row, ?int $c): string {
            return $c === null ? '' : trim((string)($row[$c] ?? ''));
        };

        $byEmail = [];            // email => list of row summaries
        $contactedDomains = [];   // domain => [email => true]
        $contactedParents = [];   // parent => [email => true]
        foreach ($rows as $i => $row) {
            $status = strtolower($cell($row, $col['status']));
            $summary = [
                'row'           => $i + 2,
                'status'        => $status,
                'dnc'           => strtolower($cell($row, $col['dnc'])),
                'parent'        => strtolower($cell($row, $col['parent'])),
                'first_contact' => $cell($row, $col['first_contact']),
                'last_contact'  => $cell($row, $col['last_contact']),
                'notes'         => $cell($row, $col['notes']),
                'response'      => strtolower($cell($row, $col['response'])),
            ];
            $emails = self::cellEmails($cell($row, $col['email']));
            $contacted = in_array($status, self::CONTACTED, true) || in_array($status, self::REPLIED, true);
            foreach ($emails as $e) {
                $byEmail[$e][] = $summary;
                if ($contacted) {
                    $at = strrpos($e, '@');
                    if ($at !== false) { $contactedDomains[substr($e, $at + 1)][$e] = true; }
                    if ($summary['parent'] !== '') { $contactedParents[$summary['parent']][$e] = true; }
                }
            }
        }
        return ['byEmail' => $byEmail, 'contactedDomains' => $contactedDomains, 'contactedParents' => $contactedParents];
    }

    /**
     * Evaluate one recipient for one campaign kind.
     *
     * @param array  $index from index()
     * @param string $kind  initial | followup
     * @param array  $ctx   suppressed?: bool, org_suppressed?: bool,
     *                      ledger_sent?: bool (a confirmed prior send exists),
     *                      require_in_sheet?: bool
     * @return array{ok: bool, flags: string[], hard: bool, in_sheet: bool, row: ?int, prior_outreach: bool}
     */
    public static function evaluate(array $index, string $email, string $kind, array $ctx = []): array
    {
        $email = strtolower(trim($email));
        $kind = $kind === self::KIND_FOLLOWUP ? self::KIND_FOLLOWUP : self::KIND_INITIAL;
        $flags = [];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'flags' => ['invalid_email'], 'hard' => true,
                'in_sheet' => false, 'row' => null, 'prior_outreach' => false];
        }
        if (!empty($ctx['suppressed'])) { $flags[] = 'suppressed'; }
        if (!empty($ctx['org_suppressed'])) { $flags[] = 'org_suppressed'; }

        $rows = $index['byEmail'][$email] ?? [];
        $inSheet = !empty($rows);
        if (!$inSheet && !empty($ctx['require_in_sheet'])) { $flags[] = 'not_in_sheet'; }

        $contacted = false; $replied = false; $evidence = false; $parents = [];
        foreach ($rows as $r) {
            if (!in_array($r['dnc'], self::FALSY, true)) { $flags[] = 'do_not_contact'; }
            if (isset(self::STATUS_FLAGS[$r['status']])) {
                $flags[] = self::STATUS_FLAGS[$r['status']];
            } elseif (strpos($r['status'], 'do not contact') !== false) {
                $flags[] = 'do_not_contact';
            }
            if (in_array($r['status'], self::CONTACTED, true)) {
                $contacted = true;
                if ($r['first_contact'] !== '' || $r['last_contact'] !== '' || self::gmailIdsFromNotes($r['notes'])) {
                    $evidence = true;
                }
            }
            if (in_array($r['status'], self::REPLIED, true) || in_array($r['response'], self::REPLIED_RESPONSES, true)) {
                $replied = true;
            }
            if ($r['parent'] !== '') { $parents[$r['parent']] = true; }
        }
        $ledgerSent = !empty($ctx['ledger_sent']);
        $priorOutreach = $ledgerSent || ($contacted && $evidence);

        if ($kind === self::KIND_INITIAL) {
            if ($contacted || $replied || $ledgerSent) {
                $flags[] = 'already_contacted';
            } else {
                $at = strrpos($email, '@');
                $domain = $at === false ? '' : substr($email, $at + 1);
                $others = $index['contactedDomains'][$domain] ?? [];
                foreach (array_keys($parents) as $p) { $others += ($index['contactedParents'][$p] ?? []); }
                unset($others[$email]);
                if ($others) { $flags[] = 'duplicate_org'; }
            }
        } else {
            if ($replied) { $flags[] = 'replied'; }
            if (!$priorOutreach) { $flags[] = 'no_prior_outreach'; }
        }

        $flags = array_values(array_unique($flags));
        return [
            'ok' => empty($flags),
            'flags' => $flags,
            'hard' => (bool)array_intersect($flags, self::HARD_FLAGS),
            'in_sheet' => $inSheet,
            'row' => $rows[0]['row'] ?? null,
            'prior_outreach' => $priorOutreach,
        ];
    }
}
