<?php
/**
 * CRM-backed prospect directory (Phase 2).
 *
 * Reads prospects from marketing_contacts + marketing_orgs instead of the
 * Google Sheet. Record shape and flag/bucket/eligibility semantics are
 * IDENTICAL to ProspectDirectory: annotation is delegated to the shared
 * ProspectDirectory::annotateRecords() so both sources compute the same
 * answers from the same rules.
 *
 * Performance model:
 *  - One slim context query loads the columns annotation needs for every
 *    contact (id, emails, status, dates, notes, org/parent names).
 *  - Page-sized detail queries fetch display fields only for the rows
 *    being returned; search/filter/sort/pagination run in SQL.
 *  - Suppression lookups are served from SuppressionService's batched
 *    in-memory sets (one query each), not per-row queries.
 *  - The Google Sheets API is never touched by this class.
 */
require_once __DIR__ . '/ProspectDirectory.php';
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/OutreachEligibility.php';

final class ProspectCrm
{
    private PDO $pdo;
    private SuppressionService $suppression;
    private ?array $context = null;

    public function __construct(PDO $pdo, ?SuppressionService $suppression = null)
    {
        $this->pdo = $pdo;
        $this->suppression = $suppression ?? new SuppressionService($pdo);
    }

    /** True when the Phase 1/2 CRM tables exist. */
    public function available(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM marketing_contacts LIMIT 0');
            $this->pdo->query('SELECT 1 FROM marketing_orgs LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Context: slim rows + shared annotation + lookup indexes             */
    /* ------------------------------------------------------------------ */

    private function context(): array
    {
        if ($this->context !== null) return $this->context;

        $rows = $this->pdo->query(
            "SELECT c.id, c.sheet_row, c.org_id,
                    o.name AS org_name, po.name AS parent_name,
                    c.email, c.email_normalized, c.status, c.dnc,
                    c.first_name, c.last_name, c.full_name,
                    c.first_contact_date, c.last_contact_date, c.followup_date,
                    c.response, c.notes
               FROM marketing_contacts c
               LEFT JOIN marketing_orgs o  ON o.id  = c.org_id
               LEFT JOIN marketing_orgs po ON po.id = o.parent_org_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $records = [];
        $eligByEmail = [];       // email => list of OutreachEligibility summaries
        $contactedDomains = [];  // domain => [email => true]  (contacted statuses)
        $contactedParents = [];  // parent => [email => true]
        $eligContacted = ['sent' => true, 'contacted' => true, 'replied' => true];

        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $status = (string)($r['status'] ?? '');
            $statusL = strtolower(trim($status));
            $email = strtolower(trim((string)($r['email_normalized'] ?? $r['email'] ?? '')));
            $parent = strtolower(trim((string)($r['parent_name'] ?? '')));
            $dncFlag = ((int)($r['dnc'] ?? 0) === 1) ? 'yes' : '';

            $fields = [
                'email'          => $email,
                'org'            => (string)($r['org_name'] ?? ''),
                'parent_org'     => (string)($r['parent_name'] ?? ''),
                'contact_name'   => (string)($r['full_name'] ?? ''),
                'contact_first'  => (string)($r['first_name'] ?? ''),
                'contact_last'   => (string)($r['last_name'] ?? ''),
                'status'         => $status,
                'dnc'            => $dncFlag,
                'first_contact'  => (string)($r['first_contact_date'] ?? ''),
                'last_contact'   => (string)($r['last_contact_date'] ?? ''),
                'followup_date'  => (string)($r['followup_date'] ?? ''),
                'response'       => (string)($r['response'] ?? ''),
                'notes'          => (string)($r['notes'] ?? ''),
            ];
            $records[] = ['id' => $id, 'row' => (int)($r['sheet_row'] ?? 0), 'fields' => $fields, 'raw' => []];

            // OutreachEligibility index summary (same shape index() builds).
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $summary = [
                    'row'           => (int)($r['sheet_row'] ?? 0),
                    'status'        => $statusL,
                    'dnc'           => $dncFlag,
                    'parent'        => $parent,
                    'first_contact' => $fields['first_contact'],
                    'last_contact'  => $fields['last_contact'],
                    'notes'         => $fields['notes'],
                    'response'      => strtolower(trim($fields['response'])),
                ];
                $eligByEmail[$email][] = $summary;
                if (isset($eligContacted[$statusL])) {
                    $at = strrpos($email, '@');
                    if ($at !== false) $contactedDomains[substr($email, $at + 1)][$email] = true;
                    if ($parent !== '') $contactedParents[$parent][$email] = true;
                }
            }
        }

        $eligIndex = ['byEmail' => $eligByEmail, 'contactedDomains' => $contactedDomains, 'contactedParents' => $contactedParents];

        // Ledger of confirmed sends (same source as the sheet directory).
        $ledger = [];
        try {
            foreach ($this->pdo->query("SELECT DISTINCT email_normalized FROM marketing_sends WHERE status='sent'")
                         ->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $ledger[strtolower((string)$e)] = true;
            }
        } catch (Throwable $e) {
            // send_ledger_unavailable mirrors ProspectDirectory behavior
        }

        $annotated = ProspectDirectory::annotateRecords(
            array_map(static fn($r) => ['row' => $r['row'], 'fields' => $r['fields'], 'raw' => $r['raw']], $records),
            $eligIndex, $this->suppression, $ledger
        );

        // Re-attach ids and build filter lookup maps.
        $byId = []; $flagIds = []; $bucketIds = []; $kindOk = ['initial' => [], 'followup' => []];
        foreach ($records as $i => $r) {
            $id = $r['id'];
            $a = $annotated[$i];
            $a['id'] = $id;
            $byId[$id] = $a;
            foreach ($a['flags'] as $fl) $flagIds[$fl][$id] = true;
            $bucketIds[$a['bucket']][$id] = true;
            if (!empty($a['eligibility']['initial']['ok'])) $kindOk['initial'][$id] = true;
            if (!empty($a['eligibility']['followup']['ok'])) $kindOk['followup'][$id] = true;
        }

        return $this->context = [
            'byId'      => $byId,
            'flagIds'   => $flagIds,
            'bucketIds' => $bucketIds,
            'kindOk'    => $kindOk,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Record assembly                                                     */
    /* ------------------------------------------------------------------ */

    /** Display fields for a set of contact ids (single IN query). */
    private function detailFields(array $ids): array
    {
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT c.id, c.sheet_row, c.org_id,
                    o.name AS org_name, o.org_type, o.domain, o.location, o.website, o.phone AS org_phone,
                    po.name AS parent_name,
                    c.first_name, c.last_name, c.full_name, c.email, c.email_normalized,
                    c.title, c.phone, c.status, c.priority, c.fit,
                    c.first_contact_date, c.last_contact_date, c.followup_date,
                    c.response, c.campaign_ref, c.notes, c.contact_notes,
                    c.outreach_method, c.dnc, c.demo_status, c.trial_status,
                    c.outcome, c.objection_reason, c.unsubscribed_at, c.unsubscribe_source,
                    c.owner_email, c.source, c.tags,
                    c.needs_review, c.review_reason, c.version, c.extra_fields
               FROM marketing_contacts c
               LEFT JOIN marketing_orgs o  ON o.id  = c.org_id
               LEFT JOIN marketing_orgs po ON po.id = o.parent_org_id
              WHERE c.id IN ($ph)"
        );
        $st->execute(array_values($ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $this->fieldsFromDetail($r);
        }
        return $out;
    }

    /** Full 'fields' map (ProspectDirectory::COLS keys) from a detail row. */
    private function fieldsFromDetail(array $r): array
    {
        $email = (string)($r['email_normalized'] ?? '') !== '' ? (string)$r['email_normalized'] : (string)($r['email'] ?? '');
        return [
            'email'          => $email,
            'org'            => (string)($r['org_name'] ?? ''),
            'parent_org'     => (string)($r['parent_name'] ?? ''),
            'contact_name'   => (string)($r['full_name'] ?? ''),
            'contact_first'  => (string)($r['first_name'] ?? ''),
            'contact_last'   => (string)($r['last_name'] ?? ''),
            'title'          => (string)($r['title'] ?? ''),
            // Sheet "Practice Type" specialty is stored per-org (org_type).
            'practice_type'  => (string)($r['org_type'] ?? ''),
            'fit'            => (string)($r['fit'] ?? ''),
            'priority'       => (string)($r['priority'] ?? ''),
            'status'         => (string)($r['status'] ?? ''),
            'last_contact'   => (string)($r['last_contact_date'] ?? ''),
            'first_contact'  => (string)($r['first_contact_date'] ?? ''),
            'followup_date'  => (string)($r['followup_date'] ?? ''),
            'response'       => (string)($r['response'] ?? ''),
            'campaign'       => (string)($r['campaign_ref'] ?? ''),
            'notes'          => (string)($r['notes'] ?? ''),
            'method'         => (string)($r['outreach_method'] ?? ''),
            'dnc'            => ((int)($r['dnc'] ?? 0) === 1) ? 'yes' : '',
            'location'       => (string)($r['location'] ?? ''),
            'website'        => (string)($r['website'] ?? ''),
            'phone'          => (string)($r['phone'] ?? '') !== '' ? (string)$r['phone'] : (string)($r['org_phone'] ?? ''),
            // Phase 3 editable/detail fields
            'contact_id'     => (int)($r['id'] ?? 0),
            'org_id'         => $r['org_id'] !== null ? (int)$r['org_id'] : null,
            'owner'          => (string)($r['owner_email'] ?? ''),
            'source'         => (string)($r['source'] ?? ''),
            'tags'           => $r['tags'] ?? null,
            'contact_notes'  => (string)($r['contact_notes'] ?? ''),
            'demo_status'    => (string)($r['demo_status'] ?? ''),
            'trial_status'   => (string)($r['trial_status'] ?? ''),
            'outcome'        => (string)($r['outcome'] ?? ''),
            'objection_reason' => (string)($r['objection_reason'] ?? ''),
            'unsubscribed_at'  => (string)($r['unsubscribed_at'] ?? ''),
            'unsubscribe_source' => (string)($r['unsubscribe_source'] ?? ''),
            'needs_review'   => (int)($r['needs_review'] ?? 0) === 1,
            'review_reason'  => (string)($r['review_reason'] ?? ''),
            'version'        => (int)($r['version'] ?? 1),
            'extra_fields'   => $r['extra_fields'] ?? null,
        ];
    }

    /** Merge annotation (context) + detail fields into the public record. */
    private function present(int $id, array $details): ?array
    {
        $ctx = $this->context();
        $a = $ctx['byId'][$id] ?? null;
        if ($a === null) return null;
        $fields = $details[$id] ?? $a['fields'];
        // contact_name display helper (same rule as the sheet directory).
        $a['fields'] = $fields;
        $a['contact_name'] = $fields['contact_name'] !== '' ? $fields['contact_name']
            : trim($fields['contact_first'] . ' ' . $fields['contact_last']);
        $a['followup_ts'] = ProspectDirectory::parseSheetDate($fields['followup_date']);
        return $a;
    }

    /* ------------------------------------------------------------------ */
    /* Public reads                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Paginated, filtered, sorted prospect search.
     *
     * @param array $o q, flag, bucket, status, priority, org_type,
     *               eligible (initial|followup), sort, dir, page, per
     * @return array{rows: array, total: int}
     */
    public function search(array $o): array
    {
        $ctx = $this->context();
        $where = ['1=1'];
        $args = [];

        $q = trim((string)($o['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(o.name LIKE ? OR po.name LIKE ? OR c.full_name LIKE ? OR c.first_name LIKE ?
                         OR c.last_name LIKE ? OR c.email_normalized LIKE ? OR c.email LIKE ? OR o.domain LIKE ?
                         OR c.title LIKE ? OR o.location LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        foreach (['status' => 'c.status', 'priority' => 'c.priority', 'fit' => 'c.fit'] as $param => $col) {
            $v = trim((string)($o[$param] ?? ''));
            if ($v !== '') { $where[] = "LOWER($col) = LOWER(?)"; $args[] = $v; }
        }
        $orgType = trim((string)($o['org_type'] ?? ''));
        if ($orgType !== '') { $where[] = 'LOWER(o.org_type) = LOWER(?)'; $args[] = $orgType; }
        // Geography: city matches the org location/metro text; state
        // matches the imported per-contact State abbreviation (extra_fields).
        $city = trim((string)($o['city'] ?? ''));
        if ($city !== '') { $where[] = 'o.location LIKE ?'; $args[] = '%' . $city . '%'; }
        $state = self::normalizeUsState((string)($o['state'] ?? ''));
        if ($state !== null) {
            $where[] = "UPPER(JSON_UNQUOTE(JSON_EXTRACT(c.extra_fields,'$.State'))) = ?";
            $args[] = $state;
        }
        if (!empty($o['review'])) { $where[] = 'c.needs_review = 1'; }

        // Computed filters resolve to contact-id sets from the shared
        // annotation, so flag/bucket/eligibility semantics match exactly.
        foreach (['flag' => 'flagIds', 'bucket' => 'bucketIds'] as $param => $map) {
            $v = trim((string)($o[$param] ?? ''));
            if ($v === '') continue;
            $ids = array_keys($ctx[$map][$v] ?? []);
            if (!$ids) { $where[] = '1=0'; }
            else { $where[] = 'c.id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
        }
        $kind = trim((string)($o['eligible'] ?? ''));
        if ($kind === 'initial' || $kind === 'followup') {
            $ids = array_keys($ctx['kindOk'][$kind]);
            if (!$ids) { $where[] = '1=0'; }
            else { $where[] = 'c.id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
        }

        $from = 'FROM marketing_contacts c
                 LEFT JOIN marketing_orgs o  ON o.id  = c.org_id
                 LEFT JOIN marketing_orgs po ON po.id = o.parent_org_id
                 WHERE ' . implode(' AND ', $where);

        $cnt = $this->pdo->prepare("SELECT COUNT(*) $from");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();

        $sortMap = [
            'org'       => 'o.name',
            'contact'   => 'c.full_name',
            'email'     => 'c.email_normalized',
            'status'    => 'c.status',
            'priority'  => 'c.priority',
            'fit'       => 'c.fit',
            'followup'  => 'c.followup_date',
            'sheet_row' => 'c.sheet_row',
        ];
        $sort = $sortMap[$o['sort'] ?? ''] ?? 'c.sheet_row';
        $dir = strtolower((string)($o['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $orderBy = $sort === 'c.followup_date'
            ? "c.followup_date IS NULL, c.followup_date $dir, c.id ASC"
            : "$sort $dir, c.id ASC";

        $page = max(1, (int)($o['page'] ?? 1));
        $per  = min(200, max(1, (int)($o['per'] ?? 50)));
        $st = $this->pdo->prepare("SELECT c.id $from ORDER BY $orderBy LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($args);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $details = $this->detailFields($ids);
        $rows = [];
        foreach ($ids as $id) {
            $p = $this->present($id, $details);
            if ($p !== null) $rows[] = $p;
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** First contact matching an email (sheet findByEmail parity). */
    public function findByEmail(string $email): ?array
    {
        $email = SuppressionService::normalizeEmail($email);
        if ($email === '') return null;
        $st = $this->pdo->prepare('SELECT id FROM marketing_contacts WHERE email_normalized=? ORDER BY id LIMIT 1');
        $st->execute([$email]);
        $id = $st->fetchColumn();
        if ($id === false) return null;
        return $this->present((int)$id, $this->detailFields([(int)$id]));
    }

    /** Single contact by primary key (Phase 3 editing/detail). */
    public function findById(int $id): ?array
    {
        if ($id <= 0) return null;
        $st = $this->pdo->prepare('SELECT id FROM marketing_contacts WHERE id=?');
        $st->execute([$id]);
        $cid = $st->fetchColumn();
        if ($cid === false) return null;
        return $this->present((int)$cid, $this->detailFields([(int)$cid]));
    }

    /**
     * All contacts belonging to one organization, annotated. Used by the
     * org detail view; bounded by org size (small in practice).
     */
    public function contactsForOrg(int $orgId): array
    {
        if ($orgId <= 0) return [];
        $st = $this->pdo->prepare('SELECT id FROM marketing_contacts WHERE org_id=? ORDER BY full_name, id');
        $st->execute([$orgId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return [];
        $details = $this->detailFields($ids);
        $out = [];
        foreach ($ids as $id) {
            $p = $this->present($id, $details);
            if ($p !== null) $out[] = $p;
        }
        return $out;
    }

    /** Organization row by id (raw columns for editing). */
    public function orgById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_orgs WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** Contact row by id (raw columns for editing/version checks). */
    public function contactRowById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_contacts WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * Map normalized email => annotated record for a set of emails.
     * Later rows win on duplicate emails, matching the sheet path's
     * `$prospects[$email] = $p` overwrite semantics.
     */
    public function findByEmails(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn($e) => SuppressionService::normalizeEmail((string)$e), $emails))));
        if (!$emails) return [];
        $ph = implode(',', array_fill(0, count($emails), '?'));
        $st = $this->pdo->prepare("SELECT id, email_normalized FROM marketing_contacts
                                    WHERE email_normalized IN ($ph) ORDER BY id");
        $st->execute($emails);
        $pairs = $st->fetchAll(PDO::FETCH_ASSOC);
        $details = $this->detailFields(array_map(static fn($r) => (int)$r['id'], $pairs));
        $out = [];
        foreach ($pairs as $r) {
            $p = $this->present((int)$r['id'], $details);
            if ($p !== null) $out[(string)$r['email_normalized']] = $p;
        }
        return $out;
    }

    /**
     * Batch email -> contact matching for bulk campaign entry.
     * Returns normalized email => list of FULL present() records (every
     * CRM contact sharing the address - callers must handle >1 as
     * ambiguous, never silently pick one).
     * @return array<string, array<int, array>>
     */
    public function matchEmails(array $emails): array
    {
        $seen = []; $norm = [];
        foreach ($emails as $e) {
            $e = trim((string)$e);
            if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $e)) continue;
            $n = SuppressionService::normalizeEmail($e);
            if ($n !== '' && !isset($seen[$n])) { $seen[$n] = true; $norm[] = $n; }
        }
        if (!$norm) return [];
        $ph = implode(',', array_fill(0, count($norm), '?'));
        $st = $this->pdo->prepare("SELECT id, email_normalized FROM marketing_contacts
                                    WHERE email_normalized IN ($ph) ORDER BY id");
        $st->execute($norm);
        $byEmail = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byEmail[(string)$r['email_normalized']][] = (int)$r['id'];
        }
        $ids = [];
        foreach ($byEmail as $list) foreach ($list as $id) $ids[$id] = true;
        $ids = array_keys($ids);
        $details = $this->detailFields($ids);
        $out = [];
        foreach ($norm as $n) {
            $ms = [];
            foreach ($byEmail[$n] ?? [] as $id) {
                $p = $this->present($id, $details);
                if ($p !== null) $ms[] = $p;
            }
            $out[$n] = $ms;
        }
        return $out;
    }

    /**
     * Follow-up pipeline list. Default bucket set matches the sheet
     * behavior (actionable follow-ups only); pass bucket=not_contacted etc.
     * to see other buckets. Sorted by follow-up date, nulls last.
     */
    public function followups(string $bucket = ''): array
    {
        $ctx = $this->context();
        $default = ['due_today' => true, 'overdue' => true, 'upcoming' => true, 'awaiting_response' => true];
        $ids = [];
        if ($bucket !== '') {
            $ids = array_keys($ctx['bucketIds'][$bucket] ?? []);
        } else {
            foreach ($default as $b => $_) {
                foreach (array_keys($ctx['bucketIds'][$b] ?? []) as $id) $ids[$id] = true;
            }
            $ids = array_keys($ids);
        }
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT c.id, c.followup_date FROM marketing_contacts c WHERE c.id IN ($ph)
             ORDER BY c.followup_date IS NULL, c.followup_date ASC, c.id ASC"
        );
        $st->execute(array_values($ids));
        $ordered = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $details = $this->detailFields($ordered);
        $out = [];
        foreach ($ordered as $id) {
            $p = $this->present($id, $details);
            if ($p !== null) $out[] = $p;
        }
        return $out;
    }

    /** Aggregate counts for the overview card (same semantics as sheet). */
    public function overviewCounts(): array
    {
        $ctx = $this->context();
        $buckets = ['due_today' => 0, 'overdue' => 0, 'upcoming' => 0, 'awaiting_response' => 0,
                    'not_contacted' => 0, 'completed' => 0, 'excluded' => 0];
        $flags = ['missing_contact' => 0, 'do_not_contact' => 0, 'duplicate_org' => 0];
        $eligible = 0; $eligInitial = 0; $eligFollowup = 0;
        foreach ($ctx['byId'] as $a) {
            $b = $a['bucket'];
            $buckets[$b] = ($buckets[$b] ?? 0) + 1;
            if ($a['eligible']) $eligible++;
            if (!empty($a['eligibility']['initial']['ok'])) $eligInitial++;
            if (!empty($a['eligibility']['followup']['ok'])) $eligFollowup++;
            foreach ($a['flags'] as $fl) $flags[$fl] = ($flags[$fl] ?? 0) + 1;
        }
        return [
            'prospects_total'   => count($ctx['byId']),
            'prospects_eligible' => $eligible,
            'eligible_initial'  => $eligInitial,
            'eligible_followup' => $eligFollowup,
            'flags'             => $flags,
            'followup_buckets'  => $buckets,
        ];
    }

    /**
     * Normalize a US state name or abbreviation to its uppercase
     * abbreviation. Returns null for empty input; unrecognized values are
     * returned uppercased so they simply match nothing.
     */
    public static function normalizeUsState(string $v): ?string
    {
        $v = strtoupper(trim($v));
        if ($v === '') return null;
        static $map = [
            'ALABAMA'=>'AL','ALASKA'=>'AK','ARIZONA'=>'AZ','ARKANSAS'=>'AR','CALIFORNIA'=>'CA',
            'COLORADO'=>'CO','CONNECTICUT'=>'CT','DELAWARE'=>'DE','DISTRICT OF COLUMBIA'=>'DC',
            'FLORIDA'=>'FL','GEORGIA'=>'GA','HAWAII'=>'HI','IDAHO'=>'ID','ILLINOIS'=>'IL',
            'INDIANA'=>'IN','IOWA'=>'IA','KANSAS'=>'KS','KENTUCKY'=>'KY','LOUISIANA'=>'LA',
            'MAINE'=>'ME','MARYLAND'=>'MD','MASSACHUSETTS'=>'MA','MICHIGAN'=>'MI','MINNESOTA'=>'MN',
            'MISSISSIPPI'=>'MS','MISSOURI'=>'MO','MONTANA'=>'MT','NEBRASKA'=>'NE','NEVADA'=>'NV',
            'NEW HAMPSHIRE'=>'NH','NEW JERSEY'=>'NJ','NEW MEXICO'=>'NM','NEW YORK'=>'NY',
            'NORTH CAROLINA'=>'NC','NORTH DAKOTA'=>'ND','OHIO'=>'OH','OKLAHOMA'=>'OK',
            'OREGON'=>'OR','PENNSYLVANIA'=>'PA','RHODE ISLAND'=>'RI','SOUTH CAROLINA'=>'SC',
            'SOUTH DAKOTA'=>'SD','TENNESSEE'=>'TN','TEXAS'=>'TX','UTAH'=>'UT','VERMONT'=>'VT',
            'VIRGINIA'=>'VA','WASHINGTON'=>'WA','WEST VIRGINIA'=>'WV','WISCONSIN'=>'WI','WYOMING'=>'WY',
        ];
        return $map[$v] ?? $v;
    }
}
