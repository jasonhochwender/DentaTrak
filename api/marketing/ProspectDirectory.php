<?php
/**
 * Prospect directory over the DentaTrak Prospects Google Sheet.
 *
 * The sheet is the source of truth for prospects; this class only reads
 * (and, via McpSheetSync::updateRow, writes back status fields after a
 * confirmed send). It never invents columns: every accessor resolves
 * headers by name with documented fallbacks and tolerates missing ones.
 *
 * Flags computed per prospect:
 *  - missing_contact : no usable email address
 *  - do_not_contact   : sheet Do Not Contact / DNC cell is set, or the
 *                       email/org is in the authoritative suppression store
 *  - duplicate_org    : another sheet row shares the same email domain or
 *                       parent organization AND already has outreach status
 *                       Sent/Contacted/Replied
 *  - overdue_followup : follow-up date is in the past and the record is not
 *                       Replied / Do Not Contact / bounced
 *
 * Each prospect also carries `eligibility` => ['initial' => ..., 'followup'
 * => ...] from OutreachEligibility, so campaign creation can apply the rule
 * for its own kind. `eligible` remains the kind-independent answer: the
 * prospect has a usable address and no permanent exclusion.
 */
require_once __DIR__ . '/McpSheetSync.php';
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/OutreachEligibility.php';

final class ProspectDirectory
{
    private McpSheetSync $sheet;
    private ?SuppressionService $suppression;
    private array $problems = [];
    private PDO $pdo;
    private ?array $ledgerSent = null;

    public function __construct(PDO $pdo, ?McpSheetSync $sheet = null, ?SuppressionService $suppression = null)
    {
        $this->pdo = $pdo;
        $this->sheet = $sheet ?? new McpSheetSync();
        $this->suppression = $suppression ?? new SuppressionService($pdo);
    }

    /** Header-name candidates, first match wins (case-insensitive). */
    private const COLS = [
        'email'          => ['Public Email', 'Email', 'Contact Email'],
        'org'            => ['Practice Name', 'Organization', 'Org', 'Company'],
        'parent_org'     => ['Parent Organization', 'Parent Org', 'DSO', 'Group'],
        'contact_name'   => ['Contact Name', 'Name', 'Full Name'],
        'contact_first'  => ['First Name'],
        'contact_last'   => ['Last Name'],
        'title'          => ['Title', 'Contact Title', 'Role', 'Position'],
        'practice_type'  => ['Practice Type', 'Org Type', 'Type', 'Segment'],
        'fit'            => ['Fit', 'Fit Score', 'Lead Score'],
        'priority'       => ['Priority'],
        'status'         => ['Outreach Status', 'Status'],
        'last_contact'   => ['Last Contact Date', 'Last Contact'],
        'first_contact'  => ['First Contact Date', 'First Contact'],
        'followup_date'  => ['Follow-up Date', 'Follow Up Date', 'Next Follow-up', 'Next Follow Up', 'Followup Date'],
        'response'       => ['Response Status', 'Response', 'Reply Status'],
        'campaign'       => ['Campaign'],
        'notes'          => ['Outreach Notes', 'Notes', 'Research Notes'],
        'method'         => ['Outreach Method'],
        'dnc'            => ['Do Not Contact', 'DNC'],
        'location'       => ['Location', 'City', 'State', 'Address'],
        'website'        => ['Website', 'URL', 'Web'],
        'phone'          => ['Phone', 'Phone Number'],
    ];

    /** All prospects, normalized. Each item: row (sheet row num), fields map, flags. */
    public function prospects(): array
    {
        $snap = $this->sheet->sheet();
        $header = $snap['header'];
        $idx = [];
        foreach (self::COLS as $key => $names) {
            $idx[$key] = $this->sheet->colAny($names);
        }
        $out = [];
        foreach ($snap['rows'] as $i => $row) {
            $rec = ['row' => $i + 2, 'fields' => [], 'raw' => $row];
            foreach (self::COLS as $key => $names) {
                $c = $idx[$key];
                $rec['fields'][$key] = $c === null ? '' : trim((string)($row[$c] ?? ''));
            }
            $out[] = $rec;
        }
        return $this->annotate($out);
    }

    /** Emails with a confirmed send in the ledger (prior outreach record). */
    private function ledgerSentEmails(): array
    {
        if ($this->ledgerSent !== null) return $this->ledgerSent;
        $this->ledgerSent = [];
        try {
            $rows = $this->pdo->query("SELECT DISTINCT email_normalized FROM marketing_sends WHERE status='sent'")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $e) $this->ledgerSent[strtolower((string)$e)] = true;
        } catch (Throwable $e) {
            $this->problems[] = 'send_ledger_unavailable';
        }
        return $this->ledgerSent;
    }

    /** Add computed flags + display helpers to raw prospect records. */
    private function annotate(array $records): array
    {
        // Index for duplicate-org detection: domain + parent org -> contacted rows
        $contactedDomains = [];
        $contactedParents = [];
        foreach ($records as $r) {
            $f = $r['fields'];
            $st = strtolower($f['status']);
            $contacted = in_array($st, ['sent', 'contacted', 'replied', 'do not contact'], true);
            if (!$contacted) continue;
            $email = strtolower($f['email']);
            if ($email !== '' && ($p = strrpos($email, '@')) !== false) {
                $contactedDomains[substr($email, $p + 1)] = true;
            }
            $parent = strtolower(trim($f['parent_org']));
            if ($parent !== '') $contactedParents[$parent] = true;
        }

        $index = $this->sheet->eligibilityIndex();
        $ledger = $this->ledgerSentEmails();
        foreach ($records as &$r) {
            $f = &$r['fields'];
            $email = strtolower(trim($f['email']));
            $flags = [];

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flags[] = 'missing_contact';
            } else {
                // Sheet-level DNC
                if (strtolower($f['dnc']) !== '' || stripos($f['status'], 'do not contact') !== false) {
                    $flags[] = 'do_not_contact';
                }
                // Authoritative suppression store (email + org domain)
                if ($this->suppression->isSuppressed($email)) {
                    $flags[] = 'do_not_contact';
                } else {
                    $orgRef = SuppressionService::orgRefForEmail($email);
                    if ($orgRef !== null && $this->suppression->isOrgSuppressed($orgRef)) {
                        $flags[] = 'do_not_contact';
                    }
                }
                // Duplicate org: another row shares this email domain or
                // parent organization and has already been contacted.
                $dup = false;
                if ($email !== '' && ($p = strrpos($email, '@')) !== false) {
                    $dup = isset($contactedDomains[substr($email, $p + 1)])
                        && !$this->isContactedStatus($f['status']);
                }
                $parent = strtolower(trim($f['parent_org']));
                if (!$dup && $parent !== '' && isset($contactedParents[$parent])
                    && !$this->isContactedStatus($f['status'])) {
                    $dup = true;
                }
                if ($dup) $flags[] = 'duplicate_org';
            }

            // Follow-up bucketing
            $fu = $this->parseSheetDate($f['followup_date']);
            $r['followup_ts'] = $fu;
            $r['bucket'] = $this->bucketFor($f, $fu);
            $r['flags'] = $flags;
            $r['contact_name'] = $f['contact_name'] !== '' ? $f['contact_name']
                : trim($f['contact_first'] . ' ' . $f['contact_last']);
            // Per-kind eligibility from the shared rules.
            $suppressed = $email !== '' && $this->suppression->isSuppressed($email);
            $orgRef = $email !== '' ? SuppressionService::orgRefForEmail($email) : null;
            $ctx = [
                'suppressed' => $suppressed,
                'org_suppressed' => !$suppressed && $orgRef !== null && $this->suppression->isOrgSuppressed($orgRef),
                'ledger_sent' => isset($ledger[$email]),
            ];
            $r['eligibility'] = [
                'initial' => OutreachEligibility::evaluate($index, $email, OutreachEligibility::KIND_INITIAL, $ctx),
                'followup' => OutreachEligibility::evaluate($index, $email, OutreachEligibility::KIND_FOLLOWUP, $ctx),
            ];
            if ($r['eligibility']['initial']['hard'] && !in_array('missing_contact', $flags, true)
                && !in_array('do_not_contact', $flags, true)) {
                // unsubscribed / bounced / invalid sheet statuses are
                // permanent exclusions too.
                $flags[] = 'do_not_contact';
                $r['flags'] = $flags;
            }
            $r['eligible'] = !in_array('missing_contact', $flags, true)
                && !in_array('do_not_contact', $flags, true);
        }
        unset($r);
        return $records;
    }

    private function isContactedStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['sent', 'contacted', 'replied'], true);
    }

    /**
     * Follow-up buckets:
     *  due_today | overdue | upcoming | awaiting_response | completed | excluded
     */
    private function bucketFor(array $f, ?int $fuTs): string
    {
        $status = strtolower(trim($f['status']));
        $dnc = strtolower(trim($f['dnc'])) !== '' || strpos($status, 'do not contact') !== false;
        if ($dnc || in_array($status, ['replied', 'unsubscribed', 'bounced', 'invalid'], true)) {
            return 'excluded';
        }
        $response = strtolower(trim($f['response']));
        if ($response !== '' && $response !== 'none' && $response !== 'no response') {
            return in_array($response, ['replied', 'interested', 'meeting booked'], true) ? 'completed' : 'awaiting_response';
        }
        if ($fuTs === null) {
            // No follow-up date: awaiting response only if prior outreach exists
            return $this->isContactedStatus($status) ? 'awaiting_response' : 'upcoming';
        }
        $today = strtotime('today');
        if ($fuTs < $today) return 'overdue';
        if ($fuTs === $today) return 'due_today';
        return 'upcoming';
    }

    /**
     * Tolerant date parser for mixed Sheets formats:
     *  - 'M/D/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD', 'Mon D, YYYY', serial numbers.
     * Returns unix timestamp at midnight UTC or null.
     */
    public function parseSheetDate(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        // Sheets serial date (days since 1899-12-30)
        if (is_numeric($raw) && (float)$raw > 20000 && (float)$raw < 80000) {
            return (int)round(((float)$raw - 25569) * 86400);
        }
        $ts = strtotime($raw . ' UTC');
        if ($ts === false) $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }

    public function formatDate(?int $ts): string
    {
        return $ts === null ? '' : gmdate('Y-m-d', $ts);
    }

    /** Find a prospect record by normalized email. */
    public function findByEmail(string $email): ?array
    {
        $target = strtolower(trim($email));
        foreach ($this->prospects() as $r) {
            if (strtolower($r['fields']['email']) === $target) return $r;
        }
        return null;
    }

    /** Sheet errors encountered while loading (for honest UI reporting). */
    public function problems(): array { return $this->problems; }
}
