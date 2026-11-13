<?php
/**
 * Marketing Outreach CRM Phase 3: write layer.
 *
 * All prospect/organization mutations for the CRM interface live here.
 * Reads stay in ProspectCrm; this class owns create/update/bulk/activity/
 * import/export/campaign-recipient operations against the Phase 1/2/3
 * MySQL tables only - it never touches Google Sheets and never weakens
 * suppression or do-not-contact rules.
 *
 * Safety properties:
 *  - Every mutation writes marketing_prospect_events (activity timeline)
 *    and is audit-logged by the caller (marketing-admin.php).
 *  - dnc/suppression only ever strengthen: an edit can set do-not-contact
 *    but can never clear an existing dnc flag, and suppression tables are
 *    never modified here.
 *  - Prospect edits use optimistic concurrency (contacts.version).
 *  - Campaign additions are draft-only, capped at MO_MAX_RECIPIENTS,
 *    deduplicated by normalized email within the campaign, and never
 *    generate/approve/send anything.
 */
require_once __DIR__ . '/ProspectCrm.php';
require_once __DIR__ . '/ProspectDirectory.php';
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/OutreachEligibility.php';

final class CrmConflict extends RuntimeException {}

final class ProspectCrmAdmin
{
    public const MAX_IMPORT_ROWS = 500;
    public const MAX_EXPORT_ROWS = 5000;

    private PDO $pdo;
    private string $actor;
    private array $lastUnmapped = [];

    public function __construct(PDO $pdo, string $actor = 'system')
    {
        $this->pdo = $pdo;
        $this->actor = $actor !== '' ? $actor : 'system';
    }

    /* ------------------------------------------------------------------ */
    /* Events                                                              */
    /* ------------------------------------------------------------------ */

    private function event(?int $contactId, ?int $orgId, string $type, array $details = []): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $contactId, $orgId, substr($type, 0, 40),
                $details === [] ? null : json_encode($details),
                substr($this->actor, 0, 190), 'crm_ui',
            ]);
        } catch (Throwable $e) {
            error_log('[crm-admin] event: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Validation helpers                                                  */
    /* ------------------------------------------------------------------ */

    private function normEmail(string $v): string
    {
        return SuppressionService::normalizeEmail($v);
    }

    private function cleanString($v, int $max): string
    {
        return mb_substr(trim((string)$v), 0, $max);
    }

    private function cleanDate($v): ?string
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $ts = strtotime($v);
        return $ts === false ? null : gmdate('Y-m-d', $ts);
    }

    private function cleanTags($v): ?string
    {
        if (is_string($v)) {
            $v = preg_split('/[,;]+/', $v) ?: [];
        }
        if (!is_array($v)) return null;
        $tags = [];
        foreach ($v as $t) {
            $t = $this->cleanString($t, 60);
            if ($t !== '' && !in_array($t, $tags, true)) $tags[] = $t;
            if (count($tags) >= 25) break;
        }
        return $tags === [] ? null : json_encode($tags);
    }

    private function statusSetsDnc(string $status): bool
    {
        return stripos($status, 'do not contact') !== false;
    }

    private function orgRow(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_orgs WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function contactRow(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_contacts WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** Review reasons recomputed from current field values. */
    private function reviewReasonsFor(array $f): array
    {
        $reasons = [];
        $email = $this->normEmail((string)($f['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $reasons[] = 'missing_or_invalid_email';
        }
        if (trim((string)($f['org_name'] ?? '')) === '' && (int)($f['org_id'] ?? 0) === 0) {
            $reasons[] = 'missing_org';
        }
        $name = trim((string)($f['full_name'] ?? $f['contact_name'] ?? ''));
        if ($name === '' && $email === '') {
            $reasons[] = 'missing_contact';
        }
        return $reasons;
    }

    /* ------------------------------------------------------------------ */
    /* Organizations                                                       */
    /* ------------------------------------------------------------------ */

    /** Org list for pickers/management. q filters name/domain; dup flag marks
     *  normalized names shared by >1 org (never auto-merged). */
    public function listOrgs(array $o = []): array
    {
        $where = ['1=1']; $args = [];
        $q = trim((string)($o['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(o.name LIKE ? OR o.domain LIKE ?)';
            $like = '%' . $q . '%';
            $args[] = $like; $args[] = $like;
        }
        $from = 'FROM marketing_orgs o WHERE ' . implode(' AND ', $where);
        $cnt = $this->pdo->prepare("SELECT COUNT(*) $from");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();
        $page = max(1, (int)($o['page'] ?? 1));
        $per = min(200, max(1, (int)($o['per'] ?? 50)));
        $st = $this->pdo->prepare(
            "SELECT o.id, o.name, o.normalized_name, o.parent_org_id, o.org_type, o.domain,
                    o.location, o.website, o.phone, o.status, o.tags, o.needs_review, o.review_reason,
                    (SELECT COUNT(*) FROM marketing_contacts c WHERE c.org_id=o.id) AS contact_count,
                    (SELECT COUNT(*) FROM marketing_orgs ch WHERE ch.parent_org_id=o.id) AS child_count,
                    (SELECT COUNT(*) FROM marketing_orgs d WHERE d.normalized_name=o.normalized_name) AS name_dupes
               $from ORDER BY o.name ASC LIMIT $per OFFSET " . (($page - 1) * $per)
        );
        $st->execute($args);
        return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    private function orgFieldsFromInput(array $d): array
    {
        return [
            'name'          => $this->cleanString($d['name'] ?? '', 255),
            'org_type'      => $this->cleanString($d['org_type'] ?? '', 60),
            'website'       => $this->cleanString($d['website'] ?? '', 500),
            'domain'        => $this->cleanString($d['domain'] ?? '', 255),
            'location'      => $this->cleanString($d['location'] ?? '', 255),
            'phone'         => $this->cleanString($d['phone'] ?? '', 60),
            'status'        => $this->cleanString($d['status'] ?? 'active', 40) ?: 'active',
            'parent_org_id' => isset($d['parent_org_id']) && (int)$d['parent_org_id'] > 0 ? (int)$d['parent_org_id'] : null,
            'tags'          => $this->cleanTags($d['tags'] ?? null),
        ];
    }

    private function orgWarnings(array $f, ?int $excludeId = null): array
    {
        $warnings = [];
        $norm = mb_strtolower(trim($f['name']));
        if ($norm !== '') {
            $sql = 'SELECT id, name FROM marketing_orgs WHERE normalized_name=?';
            $args = [$norm];
            if ($excludeId !== null) { $sql .= ' AND id<>?'; $args[] = $excludeId; }
            $st = $this->pdo->prepare($sql . ' LIMIT 5');
            $st->execute($args);
            $dups = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($dups) {
                $warnings[] = ['code' => 'possible_duplicate_org',
                    'message' => 'An organization with this name already exists',
                    'matches' => $dups];
            }
        }
        if ($f['parent_org_id'] !== null && $excludeId !== null && $f['parent_org_id'] === $excludeId) {
            $warnings[] = ['code' => 'self_parent', 'message' => 'An organization cannot be its own parent'];
        }
        if ($f['parent_org_id'] !== null && !$this->orgRow($f['parent_org_id'])) {
            $warnings[] = ['code' => 'parent_not_found', 'message' => 'Parent organization does not exist'];
        }
        return $warnings;
    }

    public function createOrg(array $d): array
    {
        $f = $this->orgFieldsFromInput($d);
        if ($f['name'] === '') throw new InvalidArgumentException('org name required');
        $warnings = $this->orgWarnings($f);
        // Domain defaults from website host when not supplied.
        $domain = $f['domain'];
        if ($domain === '' && $f['website'] !== '') {
            $h = parse_url($f['website'], PHP_URL_HOST);
            if (is_string($h) && $h !== '') $domain = mb_strtolower($h);
        }
        $this->pdo->prepare(
            'INSERT INTO marketing_orgs (name, normalized_name, parent_org_id, org_type, website, domain, location, phone, status, source, tags)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $f['name'], mb_strtolower($f['name']), $f['parent_org_id'], $f['org_type'] ?: null,
            $f['website'] ?: null, $domain ?: null, $f['location'] ?: null, $f['phone'] ?: null,
            $f['status'], 'crm_ui', $f['tags'],
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->event(null, $id, 'org_created', ['name' => $f['name']]);
        return ['id' => $id, 'warnings' => $warnings];
    }

    public function updateOrg(int $id, array $d): array
    {
        $cur = $this->orgRow($id);
        if (!$cur) throw new InvalidArgumentException('org not found');
        // Partial-update semantics: keys absent from $d keep current values
        // so callers cannot accidentally clear parent/type/tags.
        foreach (['name','org_type','website','domain','location','phone','status','parent_org_id'] as $k) {
            if (!array_key_exists($k, $d)) $d[$k] = $cur[$k];
        }
        if (!array_key_exists('tags', $d)) {
            $t = json_decode((string)($cur['tags'] ?? ''), true);
            $d['tags'] = is_array($t) ? $t : null;
        }
        $f = $this->orgFieldsFromInput($d);
        if ($f['name'] === '') throw new InvalidArgumentException('org name required');
        $warnings = $this->orgWarnings($f, $id);
        $domain = $f['domain'];
        if ($domain === '' && $f['website'] !== '') {
            $h = parse_url($f['website'], PHP_URL_HOST);
            if (is_string($h) && $h !== '') $domain = mb_strtolower($h);
        }
        $changed = [];
        $sets = []; $params = [];
        foreach (['name','org_type','website','domain','location','phone','status','parent_org_id','tags'] as $col) {
            $new = $col === 'domain' ? ($domain ?: null) : ($f[$col] === '' ? null : $f[$col]);
            if ($col === 'tags') $new = $f['tags'];
            $old = $cur[$col] ?? null;
            if ((string)$old !== (string)$new) {
                $changed[$col] = ['old' => $old, 'new' => $new];
                $sets[] = "$col=?"; $params[] = $new;
            }
        }
        if ($changed || (string)$cur['normalized_name'] !== mb_strtolower($f['name'])) {
            $sets[] = 'normalized_name=?';
            $sets[] = 'updated_at=UTC_TIMESTAMP()';
            $params[] = mb_strtolower($f['name']);
            $params[] = $id;
            $this->pdo->prepare('UPDATE marketing_orgs SET ' . implode(',', $sets) . ' WHERE id=?')
                ->execute($params);
        }
        $this->event(null, $id, 'org_updated', ['changed' => $changed]);
        return ['warnings' => $warnings, 'changed' => array_keys($changed)];
    }

    /* ------------------------------------------------------------------ */
    /* Contacts                                                            */
    /* ------------------------------------------------------------------ */

    private function contactFieldsFromInput(array $d, ?array $cur = null): array
    {
        $f = [];
        $f['first_name']   = $this->cleanString($d['first_name'] ?? '', 120);
        $f['last_name']    = $this->cleanString($d['last_name'] ?? '', 120);
        $f['full_name']    = $this->cleanString($d['full_name'] ?? '', 255);
        if ($f['full_name'] === '') {
            $f['full_name'] = trim($f['first_name'] . ' ' . $f['last_name']);
        }
        $f['email']        = $this->cleanString($d['email'] ?? '', 320);
        $f['email_normalized'] = $this->normEmail($f['email']);
        $f['title']        = $this->cleanString($d['title'] ?? '', 120);
        $f['phone']        = $this->cleanString($d['phone'] ?? '', 60);
        $f['status']       = $this->cleanString($d['status'] ?? '', 60);
        $f['priority']     = $this->cleanString($d['priority'] ?? '', 40);
        $f['fit']          = $this->cleanString($d['fit'] ?? '', 40);
        $f['owner_email']  = $this->cleanString($d['owner_email'] ?? '', 190);
        if ($f['owner_email'] !== '' && !filter_var($f['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $f['owner_email'] = '';
        }
        $f['source']       = $this->cleanString($d['source'] ?? '', 60);
        $f['tags']         = $this->cleanTags($d['tags'] ?? null);
        $f['notes']        = isset($d['notes']) ? mb_substr((string)$d['notes'], 0, 60000) : null;
        $f['contact_notes'] = isset($d['contact_notes']) ? mb_substr((string)$d['contact_notes'], 0, 60000) : null;
        $f['followup_date'] = array_key_exists('followup_date', $d) ? $this->cleanDate($d['followup_date']) : 'unset';
        $f['first_contact_date'] = array_key_exists('first_contact_date', $d) ? $this->cleanDate($d['first_contact_date']) : 'unset';
        $f['last_contact_date'] = array_key_exists('last_contact_date', $d) ? $this->cleanDate($d['last_contact_date']) : 'unset';
        $f['demo_status']  = array_key_exists('demo_status', $d) ? $this->cleanString($d['demo_status'], 60) : 'unset';
        $f['trial_status'] = array_key_exists('trial_status', $d) ? $this->cleanString($d['trial_status'], 60) : 'unset';
        $f['outcome']      = array_key_exists('outcome', $d) ? $this->cleanString($d['outcome'], 120) : 'unset';
        $f['objection_reason'] = array_key_exists('objection_reason', $d) ? $this->cleanString($d['objection_reason'], 255) : 'unset';
        $f['outreach_method'] = array_key_exists('outreach_method', $d) ? $this->cleanString($d['outreach_method'], 60) : 'unset';
        $f['org_id']       = isset($d['org_id']) && (int)$d['org_id'] > 0 ? (int)$d['org_id'] : null;
        $f['_new_org']     = is_array($d['new_org'] ?? null) ? $d['new_org'] : null;
        $f['_dnc_input']   = !empty($d['dnc']);
        return $f;
    }

    /** Warnings for duplicate org/contact/email - never blocks, never merges. */
    private function contactWarnings(array $f, ?int $excludeId = null): array
    {
        $warnings = [];
        $email = $f['email_normalized'];
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $sql = 'SELECT id, full_name, org_id FROM marketing_contacts WHERE email_normalized=?';
            $args = [$email];
            if ($excludeId !== null) { $sql .= ' AND id<>?'; $args[] = $excludeId; }
            $st = $this->pdo->prepare($sql . ' LIMIT 10');
            $st->execute($args);
            $others = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($others) {
                $warnings[] = ['code' => 'email_in_use',
                    'message' => 'This email is already used by ' . count($others) . ' other contact(s); a separate contact will be created',
                    'matches' => array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => $r['full_name']], $others)];
            }
        }
        if ($f['full_name'] !== '' && $f['org_id']) {
            $st = $this->pdo->prepare(
                'SELECT id, full_name FROM marketing_contacts WHERE org_id=? AND full_name=?'
                . ($excludeId !== null ? ' AND id<>?' : '') . ' LIMIT 5'
            );
            $args = [$f['org_id'], $f['full_name']];
            if ($excludeId !== null) $args[] = $excludeId;
            $st->execute($args);
            $dups = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($dups) {
                $warnings[] = ['code' => 'possible_duplicate_contact',
                    'message' => 'A contact with this name already exists in this organization',
                    'matches' => array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => $r['full_name']], $dups)];
            }
        }
        if ($f['_new_org']) {
            $ow = $this->orgWarnings($this->orgFieldsFromInput($f['_new_org']));
            foreach ($ow as $w) $warnings[] = $w;
        }
        return $warnings;
    }

    /** Resolve org_id: explicit id, else create from new_org payload. */
    private function resolveOrgId(array $f): ?int
    {
        if ($f['org_id'] !== null) {
            if (!$this->orgRow($f['org_id'])) throw new InvalidArgumentException('org not found');
            return $f['org_id'];
        }
        if ($f['_new_org'] && trim((string)($f['_new_org']['name'] ?? '')) !== '') {
            $r = $this->createOrg($f['_new_org']);
            return $r['id'];
        }
        return null;
    }

    public function createContact(array $d): array
    {
        $f = $this->contactFieldsFromInput($d);
        $warnings = $this->contactWarnings($f);
        $orgId = $this->resolveOrgId($f);
        $dnc = ($f['_dnc_input'] || $this->statusSetsDnc($f['status'])) ? 1 : 0;
        $reasons = $this->reviewReasonsFor(array_merge($f, ['org_id' => $orgId]));
        // Shared-email warning also flags review until a human confirms.
        foreach ($warnings as $w) {
            if ($w['code'] === 'email_in_use' && !in_array('shared_email', $reasons, true)) {
                $reasons[] = 'shared_email';
            }
        }
        $this->pdo->prepare(
            'INSERT INTO marketing_contacts
             (org_id, first_name, last_name, full_name, email, email_normalized, title, phone,
              status, priority, fit, owner_email, source, tags, notes, contact_notes,
              followup_date, first_contact_date, last_contact_date, dnc, outreach_method,
              demo_status, trial_status, outcome, objection_reason, needs_review, review_reason, sheet_row, version)
             VALUES (' . implode(',', array_fill(0, 27, '?')) . ',NULL,1)'
        )->execute([
            $orgId, $f['first_name'] ?: null, $f['last_name'] ?: null,
            $f['full_name'] !== '' ? $f['full_name'] : null,
            $f['email'] !== '' ? $f['email'] : null,
            $f['email_normalized'] !== '' ? $f['email_normalized'] : null,
            $f['title'] ?: null, $f['phone'] ?: null,
            $f['status'] !== '' ? $f['status'] : 'Not Contacted',
            $f['priority'] ?: null, $f['fit'] ?: null, $f['owner_email'] ?: null,
            $f['source'] ?: 'manual', $f['tags'],
            $f['notes'], $f['contact_notes'],
            $f['followup_date'] === 'unset' ? null : $f['followup_date'],
            in_array($f['first_contact_date'], ['unset', ''], true) ? null : $f['first_contact_date'],
            in_array($f['last_contact_date'], ['unset', ''], true) ? null : $f['last_contact_date'],
            $dnc, $f['outreach_method'] === 'unset' ? null : ($f['outreach_method'] ?: null),
            in_array($f['demo_status'], ['unset', ''], true) ? null : $f['demo_status'],
            in_array($f['trial_status'], ['unset', ''], true) ? null : $f['trial_status'],
            in_array($f['outcome'], ['unset', ''], true) ? null : $f['outcome'],
            in_array($f['objection_reason'], ['unset', ''], true) ? null : $f['objection_reason'],
            $reasons === [] ? 0 : 1, $reasons === [] ? null : implode(',', $reasons),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->event($id, $orgId, 'contact_created', ['warnings' => array_column($warnings, 'code')]);
        return ['id' => $id, 'warnings' => $warnings, 'review_reasons' => $reasons];
    }

    /**
     * Update a contact with optimistic concurrency.
     * $expectedVersion must equal contacts.version or CrmConflict is thrown.
     * dnc can only go 0->1 here; an existing 1 is never cleared.
     */
    public function updateContact(int $id, array $d, int $expectedVersion): array
    {
        $cur = $this->contactRow($id);
        if (!$cur) throw new InvalidArgumentException('contact not found');
        if ((int)$cur['version'] !== $expectedVersion) {
            throw new CrmConflict('contact was modified by another session (version mismatch)');
        }
        // Partial-update semantics: keys absent from $d keep current
        // values so callers cannot accidentally clear email/org/dates.
        // Explicit '' still clears a field (cleanX('') -> null).
        foreach (['first_name','last_name','full_name','email','title','phone','status',
                  'priority','fit','owner_email','source','notes','contact_notes',
                  'followup_date','first_contact_date','last_contact_date',
                  'demo_status','trial_status','outcome','objection_reason',
                  'outreach_method','org_id','dnc'] as $k) {
            if (!array_key_exists($k, $d)) $d[$k] = $cur[$k] ?? null;
        }
        if (!array_key_exists('tags', $d)) {
            $t = json_decode((string)($cur['tags'] ?? ''), true);
            $d['tags'] = is_array($t) ? $t : null;
        }
        $f = $this->contactFieldsFromInput($d, $cur);
        $warnings = $this->contactWarnings($f, $id);
        $orgId = $this->resolveOrgId($f);

        // dnc monotonic: existing 1 stays 1; new 1 only strengthens.
        $dnc = ((int)$cur['dnc'] === 1 || $f['_dnc_input'] || $this->statusSetsDnc($f['status'])) ? 1 : 0;

        $newEmail = $f['email'] !== '' ? $f['email'] : null;
        $newEmailNorm = $f['email_normalized'] !== '' ? $f['email_normalized'] : null;

        // Review reasons: recompute field-driven reasons, preserve
        // import-specific ones that edits cannot resolve.
        $fieldReasons = $this->reviewReasonsFor(array_merge($f, ['org_id' => $orgId]));
        $preserved = [];
        foreach (explode(',', (string)$cur['review_reason']) as $r) {
            $r = trim($r);
            if ($r === '') continue;
            if (in_array($r, ['missing_or_invalid_email', 'missing_org', 'missing_contact', 'shared_email'], true)) continue;
            $preserved[] = $r;
        }
        foreach ($warnings as $w) {
            if ($w['code'] === 'email_in_use' && !in_array('shared_email', $fieldReasons, true)) {
                $fieldReasons[] = 'shared_email';
            }
        }
        $reasons = array_values(array_unique(array_merge($preserved, $fieldReasons)));

        $sets = [
            'org_id' => $orgId,
            'first_name' => $f['first_name'] !== '' ? $f['first_name'] : null,
            'last_name' => $f['last_name'] !== '' ? $f['last_name'] : null,
            'full_name' => $f['full_name'] !== '' ? $f['full_name'] : null,
            'email' => $newEmail,
            'email_normalized' => $newEmailNorm,
            'title' => $f['title'] !== '' ? $f['title'] : null,
            'phone' => $f['phone'] !== '' ? $f['phone'] : null,
            'status' => $f['status'] !== '' ? $f['status'] : ($cur['status'] ?? 'Not Contacted'),
            'priority' => $f['priority'] !== '' ? $f['priority'] : null,
            'fit' => $f['fit'] !== '' ? $f['fit'] : null,
            'owner_email' => $f['owner_email'] !== '' ? $f['owner_email'] : null,
            'source' => $f['source'] !== '' ? $f['source'] : $cur['source'],
            'tags' => $f['tags'],
            'dnc' => $dnc,
            'needs_review' => $reasons === [] ? 0 : 1,
            'review_reason' => $reasons === [] ? null : implode(',', $reasons),
        ];
        if ($f['notes'] !== null) $sets['notes'] = $f['notes'];
        if ($f['contact_notes'] !== null) $sets['contact_notes'] = $f['contact_notes'];
        if ($f['followup_date'] !== 'unset') $sets['followup_date'] = $f['followup_date'];
        if ($f['first_contact_date'] !== 'unset') $sets['first_contact_date'] = $f['first_contact_date'];
        if ($f['last_contact_date'] !== 'unset') $sets['last_contact_date'] = $f['last_contact_date'];
        foreach (['demo_status','trial_status','outcome','objection_reason','outreach_method'] as $col) {
            if ($f[$col] !== 'unset') $sets[$col] = $f[$col] !== '' ? $f[$col] : null;
        }

        $changed = [];
        $sqlSets = []; $params = [];
        foreach ($sets as $col => $val) {
            $old = $cur[$col] ?? null;
            // JSON columns compare as strings
            if ((string)$old !== (string)$val) {
                $changed[$col] = ['old' => is_string($old) ? mb_substr($old, 0, 120) : $old,
                                  'new' => is_string($val) ? mb_substr($val, 0, 120) : $val];
                $sqlSets[] = "$col=?"; $params[] = $val;
            }
        }
        if (!$changed) {
            return ['id' => $id, 'warnings' => $warnings, 'changed' => [], 'version' => $expectedVersion];
        }
        $sqlSets[] = 'version=version+1';
        $sqlSets[] = 'updated_at=UTC_TIMESTAMP()';
        $params[] = $id; $params[] = $expectedVersion;
        $st = $this->pdo->prepare(
            'UPDATE marketing_contacts SET ' . implode(',', $sqlSets) . ' WHERE id=? AND version=?'
        );
        $st->execute($params);
        if ($st->rowCount() === 0) {
            throw new CrmConflict('contact was modified by another session (version mismatch)');
        }
        $this->event($id, $orgId, 'contact_updated', ['changed' => $changed]);
        return ['id' => $id, 'warnings' => $warnings, 'changed' => array_keys($changed),
                'version' => $expectedVersion + 1];
    }

    /** Explicit review resolution - never automatic on open/edit. */
    public function resolveReview(int $id): void
    {
        $cur = $this->contactRow($id);
        if (!$cur) throw new InvalidArgumentException('contact not found');
        $this->pdo->prepare('UPDATE marketing_contacts SET needs_review=0, review_reason=NULL, updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$id]);
        $this->event($id, $cur['org_id'] !== null ? (int)$cur['org_id'] : null, 'review_resolved',
            ['was' => (string)$cur['review_reason']]);
    }

    /**
     * Add an activity entry. 'manual_email' is explicitly NOT delivery
     * evidence - it records that a human logged an email touch.
     */
    public function addActivity(int $contactId, string $type, string $details): int
    {
        $allowed = ['note', 'call', 'meeting', 'manual_email', 'status_update'];
        if (!in_array($type, $allowed, true)) throw new InvalidArgumentException('invalid activity type');
        $cur = $this->contactRow($contactId);
        if (!$cur) throw new InvalidArgumentException('contact not found');
        $details = trim($details);
        if ($details === '') throw new InvalidArgumentException('details required');
        $this->event($contactId, $cur['org_id'] !== null ? (int)$cur['org_id'] : null, 'activity_' . $type,
            ['text' => mb_substr($details, 0, 4000)]);
        return 1;
    }

    /**
     * Append a research note (e.g. from the MCP/ChatGPT connector).
     * Stored as event_type 'research_note' - explicitly NOT an activity
     * type, so it can never be mistaken for a call/meeting/manual email.
     */
    public function addResearchNote(int $contactId, string $text, array $meta = []): int
    {
        $cur = $this->contactRow($contactId);
        if (!$cur) throw new InvalidArgumentException('contact not found');
        $text = trim($text);
        if ($text === '') throw new InvalidArgumentException('note text required');
        $details = ['text' => mb_substr($text, 0, 8000), 'kind' => 'research'];
        if (!empty($meta['sources']) && is_array($meta['sources'])) {
            $details['sources'] = array_values(array_slice(array_map('strval', $meta['sources']), 0, 20));
        }
        if (!empty($meta['researched_at'])) {
            $details['researched_at'] = mb_substr((string)$meta['researched_at'], 0, 40);
        }
        $this->pdo->prepare(
            'INSERT INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $contactId, $cur['org_id'] !== null ? (int)$cur['org_id'] : null,
            'research_note', json_encode($details),
            substr($this->actor, 0, 190), 'mcp',
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Bulk update: priority, tags (append), owner. Returns per-id results. */
    public function bulkUpdate(array $ids, array $fields): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) return ['updated' => 0, 'results' => []];
        $allowed = ['priority', 'owner_email', 'tags_add'];
        $sets = []; $params = [];
        if (isset($fields['priority'])) {
            $v = $this->cleanString($fields['priority'], 40);
            $sets[] = 'priority=?'; $params[] = $v !== '' ? $v : null;
        }
        if (isset($fields['owner_email'])) {
            $v = $this->cleanString($fields['owner_email'], 190);
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $v = '';
            $sets[] = 'owner_email=?'; $params[] = $v !== '' ? $v : null;
        }
        $tagsAdd = $this->cleanTags($fields['tags_add'] ?? null);
        if (!$sets && !$tagsAdd) throw new InvalidArgumentException('no bulk fields');

        $results = []; $updated = 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sel = $this->pdo->prepare("SELECT id, org_id, tags FROM marketing_contacts WHERE id IN ($ph)");
        $sel->execute($ids);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $rowSets = $sets; $rowParams = $params;
            if ($tagsAdd) {
                $existing = json_decode((string)($r['tags'] ?? ''), true);
                $existing = is_array($existing) ? $existing : [];
                $merged = array_values(array_unique(array_merge($existing, json_decode($tagsAdd, true) ?: [])));
                $rowSets[] = 'tags=?'; $rowParams[] = json_encode($merged);
            }
            $rowSets[] = 'version=version+1';
            $rowSets[] = 'updated_at=UTC_TIMESTAMP()';
            $rowParams[] = $id;
            $this->pdo->prepare('UPDATE marketing_contacts SET ' . implode(',', $rowSets) . ' WHERE id=?')
                ->execute($rowParams);
            $this->event($id, $r['org_id'] !== null ? (int)$r['org_id'] : null, 'bulk_update',
                ['fields' => array_keys($fields)]);
            $updated++;
            $results[] = ['id' => $id, 'ok' => true];
        }
        $missing = array_diff($ids, array_map(static fn($r) => (int)$r['id'], $rows));
        foreach ($missing as $m) $results[] = ['id' => $m, 'ok' => false, 'error' => 'not_found'];
        return ['updated' => $updated, 'results' => $results];
    }

    /* ------------------------------------------------------------------ */
    /* Campaign recipients (draft-only)                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Add selected contacts to a draft campaign (or create a new draft).
     * Dedupes by normalized email within the campaign, enforces the
     * recipient cap, records eligibility exactly like create_campaign,
     * and stores contact_id so membership never depends on email lookup.
     */
    public function addToCampaign(array $contactIds, ?string $campaignId, ?string $newName, string $kind, int $maxRecipients, bool $dryRun = false): array
    {
        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), fn($i) => $i > 0)));
        if (!$contactIds) throw new InvalidArgumentException('no contacts selected');
        $kind = $kind === 'followup' ? 'followup' : 'initial';

        $camp = null;
        if ($campaignId !== null && $campaignId !== '') {
            $st = $this->pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?');
            $st->execute([$campaignId]);
            $camp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$camp) throw new InvalidArgumentException('campaign not found');
            if (!in_array($camp['status'], ['draft', 'ready', 'approved'], true)) throw new InvalidArgumentException('only editable campaigns accept recipients');
            $kind = $camp['kind'];
            // New recipients need generation and re-approval: 'ready'
            // goes back to 'draft', 'approved' loses its approval entirely.
            if ($camp['status'] === 'ready') {
                $this->pdo->prepare("UPDATE marketing_campaigns SET status='draft', updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$campaignId]);
            } elseif ($camp['status'] === 'approved') {
                $this->pdo->prepare("UPDATE marketing_campaigns SET status='draft', approved_by=NULL, approved_at=NULL, content_hash=NULL, updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$campaignId]);
            }
        } elseif ($dryRun) {
            // Preview for a not-yet-created draft: no campaign row, no
            // existing recipients, full cap available.
            $campaignId = '';
            $camp = ['id' => '', 'kind' => $kind, 'name' => trim((string)$newName)];
        } else {
            $name = trim((string)$newName);
            if ($name === '') throw new InvalidArgumentException('campaign name required');
            $cid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000,
                mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
            $this->pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status,created_by,recipient_count) VALUES (?,?,?,'draft',?,0)")
                ->execute([$cid, mb_substr($name, 0, 160), $kind, $this->actor]);
            $campaignId = $cid;
            $camp = ['id' => $cid, 'kind' => $kind, 'name' => $name];
        }

        // Load selected contacts + org names.
        $ph = implode(',', array_fill(0, count($contactIds), '?'));
        $st = $this->pdo->prepare(
            "SELECT c.id, c.sheet_row, c.email_normalized, c.full_name, c.org_id, o.name AS org_name
               FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id=c.org_id
              WHERE c.id IN ($ph) ORDER BY c.id"
        );
        $st->execute($contactIds);
        $contacts = $st->fetchAll(PDO::FETCH_ASSOC);

        // Existing recipients: emails already in campaign + current count.
        // Dry-run for a not-yet-created campaign has no rows to check.
        $existingEmails = []; $slots = $maxRecipients;
        if ($campaignId !== '') {
            $ex = $this->pdo->prepare('SELECT prospect_email FROM marketing_campaign_recipients WHERE campaign_id=?');
            $ex->execute([$campaignId]);
            foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $e) $existingEmails[strtolower((string)$e)] = true;
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=?');
            $count->execute([$campaignId]);
            $slots = max(0, $maxRecipients - (int)$count->fetchColumn());
        }

        // Eligibility per unique email (email-level rules are identical
        // for contacts sharing an address).
        $emails = [];
        foreach ($contacts as $c) {
            $e = strtolower(trim((string)$c['email_normalized']));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[$e] = true;
        }
        $elig = new ProspectCrm($this->pdo);
        $annotated = $elig->findByEmails(array_keys($emails));

        $ins = $this->pdo->prepare(
            "INSERT INTO marketing_campaign_recipients
             (campaign_id, contact_id, sheet_row, prospect_email, prospect_org, prospect_contact, status, skip_reason)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $results = []; $added = 0; $seenEmail = [];
        foreach ($contacts as $c) {
            $id = (int)$c['id'];
            $email = strtolower(trim((string)$c['email_normalized']));
            $base = ['id' => $id, 'email' => $email,
                'contact_name' => (string)($c['full_name'] ?? ''), 'org' => (string)($c['org_name'] ?? '')];
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[] = $base + ['ok' => false, 'reason' => 'missing_or_invalid_email'];
                continue;
            }
            if (isset($existingEmails[$email]) || isset($seenEmail[$email])) {
                $results[] = $base + ['ok' => false, 'reason' => 'duplicate_email_in_campaign'];
                continue;
            }
            if ($added >= $slots) {
                $results[] = $base + ['ok' => false, 'reason' => 'campaign_full'];
                continue;
            }
            $p = $annotated[$email] ?? null;
            $reason = null; $status = 'draft';
            if (!$p) { $status = 'suppressed'; $reason = 'not_in_crm'; }
            elseif (in_array('missing_contact', $p['flags'] ?? [], true)) { $status = 'suppressed'; $reason = 'missing_contact'; }
            elseif (empty($p['eligibility'][$kind]['ok'])) {
                $status = 'suppressed';
                $reason = substr(implode(',', $p['eligibility'][$kind]['flags'] ?? ['ineligible']), 0, 190);
            }
            if (!$dryRun) {
                $ins->execute([$campaignId, $id, $c['sheet_row'] !== null ? (int)$c['sheet_row'] : null,
                    $email, mb_substr((string)($c['org_name'] ?? ''), 0, 255),
                    mb_substr((string)($c['full_name'] ?? ''), 0, 255), $status, $reason]);
                $this->event($id, $c['org_id'] !== null ? (int)$c['org_id'] : null, 'campaign_recipient_added',
                    ['campaign_id' => $campaignId, 'status' => $status]);
            }
            $seenEmail[$email] = true;
            $added++;
            $results[] = $base + ['ok' => true, 'status' => $status, 'reason' => $reason];
        }
        // Contacts requested but not found.
        $found = array_map(static fn($c) => (int)$c['id'], $contacts);
        foreach (array_diff($contactIds, $found) as $m) {
            $results[] = ['id' => $m, 'ok' => false, 'reason' => 'not_found'];
        }
        if (!$dryRun) {
            $this->pdo->prepare('UPDATE marketing_campaigns SET recipient_count=(SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=?) WHERE id=?')
                ->execute([$campaignId, $campaignId]);
        }
        return ['campaign_id' => $campaignId, 'added' => $added, 'results' => $results, 'dry_run' => $dryRun];
    }

    /* ------------------------------------------------------------------ */
    /* CSV import / export                                                 */
    /* ------------------------------------------------------------------ */

    /** Map a CSV header row to CRM field keys using sheet aliases. */
    public static function csvHeaderMap(array $header): array
    {
        $map = [];
        foreach (ProspectDirectory::cols() as $key => $names) {
            foreach ($names as $n) {
                $map[mb_strtolower(trim($n))] = $key;
            }
        }
        // Phase-3/4-only headers (CRM columns that have no sheet column).
        $extra = [
            'tags' => 'tags', 'owner' => 'owner_email', 'owner email' => 'owner_email',
            'source' => 'source', 'contact notes' => 'contact_notes', 'notes (contact)' => 'contact_notes',
            'demo status' => 'demo_status', 'demo' => 'demo_status', 'demoed' => 'demo_status',
            'trial status' => 'trial_status', 'trial' => 'trial_status',
            'outcome' => 'outcome', 'result' => 'outcome',
            'objection' => 'objection_reason', 'objection reason' => 'objection_reason',
            'domain' => 'domain',
        ];
        foreach ($extra as $h => $key) $map[$h] = $key;
        return $map;
    }

    /** Headers seen by the last previewImport() call that mapped to no field. */
    public function unmappedColumns(): array
    {
        return $this->lastUnmapped ?? [];
    }

    /**
     * Parse + validate CSV text. Returns normalized records with per-row
     * disposition (new|update|invalid|duplicate_review) - writes nothing.
     */
    public function previewImport(string $csvText): array
    {
        $rows = $this->parseCsv($csvText);
        if (!$rows) throw new InvalidArgumentException('no data rows');
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw new InvalidArgumentException('too many rows (max ' . self::MAX_IMPORT_ROWS . ')');
        }
        $header = array_shift($rows);
        $hmap = self::csvHeaderMap($header);
        $this->lastUnmapped = [];
        foreach ($header as $h) {
            $hn = mb_strtolower(trim((string)$h));
            if ($hn !== '' && !isset($hmap[$hn])) $this->lastUnmapped[] = (string)$h;
        }
        $out = [];
        foreach ($rows as $i => $r) {
            $rec = [];
            foreach ($header as $ci => $h) {
                $key = $hmap[mb_strtolower(trim((string)$h))] ?? null;
                if ($key !== null) $rec[$key] = $r[$ci] ?? '';
            }
            $out[] = $this->previewRow($rec, $i + 2);
        }
        return $out;
    }

    private function parseCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // BOM
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    private function previewRow(array $rec, int $lineNo): array
    {
        $email = $this->normEmail((string)($rec['email'] ?? ''));
        $valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
        $row = [
            'line' => $lineNo,
            'org' => trim((string)($rec['org'] ?? '')),
            'contact_name' => trim((string)($rec['contact_name'] ?? '')),
            'email' => $email,
            'status' => trim((string)($rec['status'] ?? '')),
            'priority' => trim((string)($rec['priority'] ?? '')),
            'disposition' => 'new',
            'warnings' => [],
            'contact_id' => null,
            'fields' => $rec,
        ];
        if (!$valid && trim((string)($rec['contact_name'] ?? '')) === '' && trim((string)($rec['org'] ?? '')) === '') {
            $row['disposition'] = 'invalid';
            $row['warnings'][] = 'empty_or_unidentifiable_row';
            return $row;
        }
        if ($valid) {
            $st = $this->pdo->prepare('SELECT id, full_name FROM marketing_contacts WHERE email_normalized=? ORDER BY id LIMIT 5');
            $st->execute([$email]);
            $existing = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($existing) {
                $row['disposition'] = 'update';
                $row['contact_id'] = (int)$existing[0]['id'];
                if (count($existing) > 1) $row['warnings'][] = 'email_shared_by_multiple_contacts';
            }
        }
        return $row;
    }

    /**
     * Commit previously-previewed rows. 'update' rows fill only EMPTY
     * contact fields (non-destructive); 'new' rows go through
     * createContact. dnc/suppression can only strengthen.
     */
    public function commitImport(array $records): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $this->pdo->beginTransaction();
        try {
            foreach ($records as $rec) {
                $disp = (string)($rec['disposition'] ?? '');
                $fields = is_array($rec['fields'] ?? null) ? $rec['fields'] : [];
                try {
                    if ($disp === 'new') {
                        $this->createContact($this->csvFieldsToContactInput($fields));
                        $summary['created']++;
                    } elseif ($disp === 'update' && (int)($rec['contact_id'] ?? 0) > 0) {
                        $this->importUpdate((int)$rec['contact_id'], $fields);
                        $summary['updated']++;
                    } else {
                        $summary['skipped']++;
                    }
                } catch (Throwable $e) {
                    $summary['skipped']++;
                    $summary['errors'][] = ['line' => $rec['line'] ?? 0, 'error' => $e->getMessage()];
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->event(null, null, 'import_commit', ['summary' => $summary]);
        return $summary;
    }

    /**
     * Translate preview-row field keys (ProspectDirectory::COLS) into the
     * createContact input shape. The 'org'/'parent_org' CSV values are
     * NAMES: an exact normalized-name match reuses the org, otherwise a
     * new org is created (same behavior as the Phase 1 sheet importer).
     * Orgs are never merged or overwritten by import.
     */
    private function csvFieldsToContactInput(array $rec): array
    {
        $in = [
            'first_name' => $rec['contact_first'] ?? '', 'last_name' => $rec['contact_last'] ?? '',
            'full_name' => $rec['contact_name'] ?? '', 'email' => $rec['email'] ?? '',
            'title' => $rec['title'] ?? '', 'phone' => $rec['phone'] ?? '',
            'status' => $rec['status'] ?? '', 'priority' => $rec['priority'] ?? '',
            'fit' => $rec['fit'] ?? '', 'notes' => $rec['notes'] ?? '',
            'contact_notes' => $rec['contact_notes'] ?? '',
            'followup_date' => $rec['followup_date'] ?? '',
            'first_contact_date' => $rec['first_contact'] ?? '',
            'last_contact_date' => $rec['last_contact'] ?? '',
            'owner_email' => $rec['owner_email'] ?? '', 'source' => $rec['source'] ?? 'csv_import',
            'tags' => $rec['tags'] ?? null, 'outreach_method' => $rec['method'] ?? '',
            'demo_status' => $rec['demo_status'] ?? '', 'trial_status' => $rec['trial_status'] ?? '',
            'outcome' => $rec['outcome'] ?? '', 'objection_reason' => $rec['objection_reason'] ?? '',
            'dnc' => !empty($rec['dnc']),
        ];
        $orgName = trim((string)($rec['org'] ?? ''));
        if ($orgName !== '') {
            $st = $this->pdo->prepare('SELECT id FROM marketing_orgs WHERE normalized_name=? ORDER BY id LIMIT 1');
            $st->execute([mb_strtolower($orgName)]);
            $oid = $st->fetchColumn();
            if ($oid !== false) {
                $in['org_id'] = (int)$oid;
            } else {
                $newOrg = ['name' => $orgName, 'org_type' => (string)($rec['practice_type'] ?? ''),
                    'website' => (string)($rec['website'] ?? ''), 'domain' => (string)($rec['domain'] ?? ''),
                    'location' => (string)($rec['location'] ?? ''), 'phone' => (string)($rec['phone'] ?? '')];
                $parentName = trim((string)($rec['parent_org'] ?? ''));
                if ($parentName !== '') {
                    $ps = $this->pdo->prepare('SELECT id FROM marketing_orgs WHERE normalized_name=? ORDER BY id LIMIT 1');
                    $ps->execute([mb_strtolower($parentName)]);
                    $pid = $ps->fetchColumn();
                    if ($pid !== false) $newOrg['parent_org_id'] = (int)$pid;
                }
                $in['new_org'] = $newOrg;
            }
        }
        return $in;
    }

    /** Non-destructive merge for import 'update' rows: only empty fields fill. */
    private function importUpdate(int $contactId, array $fields): void
    {
        $cur = $this->contactRow($contactId);
        if (!$cur) return;
        $map = [
            'title' => 'title', 'phone' => 'phone', 'status' => 'status', 'priority' => 'priority',
            'fit' => 'fit', 'notes' => 'notes', 'first_name' => 'first_name', 'last_name' => 'last_name',
            'full_name' => 'full_name', 'contact_notes' => 'contact_notes',
            'demo_status' => 'demo_status', 'trial_status' => 'trial_status',
            'outcome' => 'outcome', 'objection_reason' => 'objection_reason',
            'owner_email' => 'owner_email', 'source' => 'source',
        ];
        $sets = []; $params = []; $changed = [];
        foreach ($map as $inKey => $col) {
            $inc = trim((string)($fields[$inKey] ?? ''));
            if ($inc === '') continue;
            $old = trim((string)($cur[$col] ?? ''));
            if ($old === '') {
                $sets[] = "$col=?"; $params[] = $inc;
                $changed[$col] = ['old' => null, 'new' => mb_substr($inc, 0, 80)];
            }
        }
        // follow-up date: fill only when currently empty.
        $fu = trim((string)($fields['followup_date'] ?? ''));
        if ($fu !== '' && trim((string)($cur['followup_date'] ?? '')) === '') {
            $ts = strtotime($fu);
            if ($ts !== false) {
                $sets[] = 'followup_date=?'; $params[] = gmdate('Y-m-d', $ts);
                $changed['followup_date'] = ['old' => null, 'new' => gmdate('Y-m-d', $ts)];
            }
        }
        // contact dates: fill only when currently empty.
        foreach (['first_contact' => 'first_contact_date', 'last_contact' => 'last_contact_date'] as $inKey => $col) {
            $inc = trim((string)($fields[$inKey] ?? ''));
            if ($inc === '' || trim((string)($cur[$col] ?? '')) !== '') continue;
            $ts = strtotime($inc);
            if ($ts !== false) {
                $sets[] = "$col=?"; $params[] = gmdate('Y-m-d', $ts);
                $changed[$col] = ['old' => null, 'new' => gmdate('Y-m-d', $ts)];
            }
        }
        // dnc can only strengthen.
        $incStatus = strtolower(trim((string)($fields['status'] ?? '')));
        if ((int)$cur['dnc'] === 0 && (strpos($incStatus, 'do not contact') !== false)) {
            $sets[] = 'dnc=1';
            $changed['dnc'] = ['old' => 0, 'new' => 1];
        }
        if (!$sets) return;
        $sets[] = 'version=version+1';
        $sets[] = 'updated_at=UTC_TIMESTAMP()';
        $params[] = $contactId;
        $this->pdo->prepare('UPDATE marketing_contacts SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
        $this->event($contactId, $cur['org_id'] !== null ? (int)$cur['org_id'] : null, 'import_update',
            ['changed' => $changed]);
    }

    /**
     * Rows for CSV export. $ids selects specific contacts; otherwise the
     * same filter params as ProspectCrm::search apply. Returns field maps.
     */
    public function exportRows(array $filters, ?array $ids = null): array
    {
        $out = [];
        if ($ids) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
            foreach (array_chunk($ids, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $this->pdo->prepare(
                    "SELECT c.*, o.name AS org_name, o.org_type, po.name AS parent_name
                       FROM marketing_contacts c
                       LEFT JOIN marketing_orgs o ON o.id=c.org_id
                       LEFT JOIN marketing_orgs po ON po.id=o.parent_org_id
                      WHERE c.id IN ($ph) ORDER BY c.id"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $this->exportRow($r);
            }
            return $out;
        }
        $crm = new ProspectCrm($this->pdo);
        $page = 1;
        do {
            $res = $crm->search($filters + ['per' => 200, 'page' => $page]);
            foreach ($res['rows'] as $p) {
                $f = $p['fields'];
                $out[] = [
                    'contact_id' => $f['contact_id'] ?? null,
                    'org' => $f['org'] ?? '', 'parent_org' => $f['parent_org'] ?? '',
                    'contact_name' => $p['contact_name'] ?? '', 'first_name' => $f['contact_first'] ?? '',
                    'last_name' => $f['contact_last'] ?? '', 'email' => $f['email'] ?? '',
                    'title' => $f['title'] ?? '', 'phone' => $f['phone'] ?? '',
                    'status' => $f['status'] ?? '', 'priority' => $f['priority'] ?? '',
                    'fit' => $f['fit'] ?? '', 'practice_type' => $f['practice_type'] ?? '',
                    'last_contact' => $f['last_contact'] ?? '', 'first_contact' => $f['first_contact'] ?? '',
                    'followup_date' => $f['followup_date'] ?? '', 'response' => $f['response'] ?? '',
                    'campaign' => $f['campaign'] ?? '', 'method' => $f['method'] ?? '',
                    'owner' => $f['owner'] ?? '', 'source' => $f['source'] ?? '',
                    'tags' => is_string($f['tags'] ?? null) ? implode('|', json_decode($f['tags'], true) ?: []) : '',
                    'notes' => $f['notes'] ?? '', 'contact_notes' => $f['contact_notes'] ?? '',
                    'demo_status' => $f['demo_status'] ?? '', 'trial_status' => $f['trial_status'] ?? '',
                    'outcome' => $f['outcome'] ?? '', 'objection_reason' => $f['objection_reason'] ?? '',
                    'needs_review' => !empty($f['needs_review']) ? 'yes' : '',
                    'review_reason' => $f['review_reason'] ?? '',
                    'bucket' => $p['bucket'] ?? '', 'flags' => implode('|', $p['flags'] ?? []),
                    'eligible' => !empty($p['eligible']) ? 'yes' : 'no',
                    'sheet_row' => $p['row'] ?? null,
                ];
            }
            if (count($res['rows']) < 200 || count($out) >= self::MAX_EXPORT_ROWS) break;
            $page++;
        } while (true);
        return array_slice($out, 0, self::MAX_EXPORT_ROWS);
    }

    /* ------------------------------------------------------------------ */
    /* Phase 4: campaign management                                        */
    /* ------------------------------------------------------------------ */

    private function campaignRow(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** Draft wizard: create campaign + recipients in one call. */
    public function createCampaign(array $d, int $maxRecipients): array
    {
        $name = $this->cleanString($d['name'] ?? '', 160);
        if ($name === '') throw new InvalidArgumentException('name required');
        $kind = ($d['kind'] ?? 'initial') === 'followup' ? 'followup' : 'initial';
        $desc = isset($d['description']) ? mb_substr((string)$d['description'], 0, 4000) : null;
        $tplId = isset($d['template_id']) && (int)$d['template_id'] > 0 ? (int)$d['template_id'] : null;
        if ($tplId !== null) {
            $t = $this->pdo->prepare('SELECT kind FROM marketing_templates WHERE id=?');
            $t->execute([$tplId]);
            $tk = $t->fetchColumn();
            if ($tk === false) throw new InvalidArgumentException('template not found');
            if ($tk !== $kind) throw new InvalidArgumentException('template kind does not match campaign kind');
        }
        $cid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000,
            mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
        $this->pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status,created_by,recipient_count,template_id,description) VALUES (?,?,?,'draft',?,0,?,?)")
            ->execute([$cid, $name, $kind, $this->actor, $tplId, $desc]);
        $this->event(null, null, 'campaign_created', ['campaign_id' => $cid, 'name' => $name, 'kind' => $kind]);
        $ids = (array)($d['contact_ids'] ?? []);
        if ($ids) {
            $r = $this->addToCampaign($ids, $cid, null, $kind, $maxRecipients);
            return ['campaign_id' => $cid, 'results' => $r['results'], 'added' => $r['added']];
        }
        return ['campaign_id' => $cid, 'results' => [], 'added' => 0];
    }

    /** Edit draft metadata. Kind is locked once recipients exist. */
    public function updateCampaignMeta(string $id, array $d): array
    {
        $camp = $this->campaignRow($id);
        if (!$camp) throw new InvalidArgumentException('campaign not found');
        if (!in_array($camp['status'], ['draft', 'ready', 'approved'], true)) {
            throw new InvalidArgumentException('campaign locked');
        }
        $sets = []; $params = []; $changed = [];
        if (array_key_exists('name', $d)) {
            $v = $this->cleanString($d['name'], 160);
            if ($v === '') throw new InvalidArgumentException('name required');
            if ($v !== $camp['name']) { $sets[] = 'name=?'; $params[] = $v; $changed[] = 'name'; }
        }
        if (array_key_exists('description', $d)) {
            $v = isset($d['description']) ? mb_substr((string)$d['description'], 0, 4000) : null;
            if ((string)$v !== (string)$camp['description']) { $sets[] = 'description=?'; $params[] = $v; $changed[] = 'description'; }
        }
        if (array_key_exists('template_id', $d)) {
            $v = (int)($d['template_id'] ?? 0) ?: null;
            if ($v !== null) {
                $t = $this->pdo->prepare('SELECT kind FROM marketing_templates WHERE id=?');
                $t->execute([$v]);
                $tk = $t->fetchColumn();
                if ($tk === false) throw new InvalidArgumentException('template not found');
                if ($tk !== $camp['kind']) throw new InvalidArgumentException('template kind does not match campaign kind');
            }
            if ($v !== ($camp['template_id'] !== null ? (int)$camp['template_id'] : null)) {
                $sets[] = 'template_id=?'; $params[] = $v; $changed[] = 'template_id';
            }
        }
        if (array_key_exists('kind', $d) && $d['kind'] !== $camp['kind']) {
            $n = (int)$this->pdo->query("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=" . $this->pdo->quote($id))->fetchColumn();
            if ($n > 0) throw new InvalidArgumentException('kind cannot change after recipients are added');
            $kind = $d['kind'] === 'followup' ? 'followup' : 'initial';
            $sets[] = 'kind=?'; $params[] = $kind; $changed[] = 'kind';
        }
        if (!$sets) return ['changed' => []];
        $sets[] = 'updated_at=UTC_TIMESTAMP()';
        $params[] = $id;
        $this->pdo->prepare('UPDATE marketing_campaigns SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
        // Material metadata changes invalidate approval: name feeds the
        // content hash, and any edit forces a fresh review regardless.
        if ($camp['status'] === 'approved') {
            $this->pdo->prepare("UPDATE marketing_campaigns SET status='ready', approved_by=NULL, approved_at=NULL, content_hash=NULL WHERE id=?")
                ->execute([$id]);
        }
        $this->event(null, null, 'campaign_updated', ['campaign_id' => $id, 'changed' => $changed]);
        return ['changed' => $changed];
    }

    /**
     * Remove a recipient from an editable campaign. Sent/sending/uncertain
     * rows and finished campaigns are never touched. Any removal downgrades
     * an approved campaign back to 'ready' (content hash changes anyway).
     */
    public function removeRecipient(string $campaignId, int $recipientId): array
    {
        $camp = $this->campaignRow($campaignId);
        if (!$camp) throw new InvalidArgumentException('campaign not found');
        if (!in_array($camp['status'], ['draft', 'ready', 'approved'], true)) {
            throw new InvalidArgumentException('campaign is not editable');
        }
        $st = $this->pdo->prepare('SELECT * FROM marketing_campaign_recipients WHERE id=? AND campaign_id=?');
        $st->execute([$recipientId, $campaignId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new InvalidArgumentException('recipient not found');
        if (in_array($r['status'], ['sent', 'sending', 'uncertain'], true)) {
            throw new InvalidArgumentException('cannot remove a sent or in-flight recipient');
        }
        $this->pdo->prepare('DELETE FROM marketing_campaign_recipients WHERE id=?')->execute([$recipientId]);
        if (in_array($camp['status'], ['approved'], true)) {
            $this->pdo->prepare("UPDATE marketing_campaigns SET status='ready', approved_by=NULL, approved_at=NULL, content_hash=NULL WHERE id=?")
                ->execute([$campaignId]);
        }
        $this->pdo->prepare('UPDATE marketing_campaigns SET recipient_count=(SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=?) WHERE id=?')
            ->execute([$campaignId, $campaignId]);
        $this->event($r['contact_id'] !== null ? (int)$r['contact_id'] : null, null, 'campaign_recipient_removed',
            ['campaign_id' => $campaignId, 'email' => $r['prospect_email']]);
        return ['removed' => $recipientId];
    }

    /**
     * Send-time gate for one campaign recipient in CRM mode. Mirrors the
     * sheet preCheck semantics using the annotated CRM record: permanent
     * exclusions always block; non-hard failures may pass only when
     * resuming an interrupted send.
     *
     * @param array|null $prospect annotated record from ProspectCrm::findByEmails
     * @return array{action: string, reason: ?string, flags: string[]}
     */
    public function sendPrecheck(?array $prospect, string $kind, bool $resuming): array
    {
        if ($prospect === null) {
            return ['action' => 'skip', 'reason' => 'not_in_crm', 'flags' => ['not_in_crm']];
        }
        $flags = $prospect['flags'] ?? [];
        if (in_array('missing_contact', $flags, true)) {
            return ['action' => 'suppressed', 'reason' => 'missing_contact', 'flags' => $flags];
        }
        $elig = $prospect['eligibility'][$kind] ?? ['ok' => false, 'flags' => ['ineligible'], 'hard' => true];
        if (empty($elig['ok']) && !($resuming && empty($elig['hard']))) {
            return ['action' => 'skip', 'reason' => substr(implode(',', $elig['flags'] ?? ['ineligible']), 0, 190), 'flags' => $elig['flags'] ?? []];
        }
        return ['action' => 'send', 'reason' => null, 'flags' => []];
    }

    /**
     * Confirmed-send write-back for CRM mode: updates every contact
     * sharing the recipient's normalized email (sheet parity - one sheet
     * row per email), records a prospect event per contact, and marks the
     * recipient's sheet_sync_status 'synced' (the CRM update IS the
     * write-back; no sheet call is made or queued).
     */
    public function recordSend(array $recipient, array $campaign): bool
    {
        $email = SuppressionService::normalizeEmail((string)$recipient['prospect_email']);
        if ($email === '') return false;
        $date = substr((string)($recipient['sent_at'] ?? ''), 0, 10) ?: gmdate('Y-m-d');
        $followup = $campaign['kind'] === 'followup';
        $msgId = (string)($recipient['gmail_message_id'] ?? '');
        $note = 'Gmail ' . ($msgId !== '' ? $msgId : 'send') . ($followup ? ' (follow-up)' : '');
        $st = $this->pdo->prepare('SELECT id, notes FROM marketing_contacts WHERE email_normalized=?');
        $st->execute([$email]);
        $contacts = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$contacts) return false;
        $upd = $this->pdo->prepare(
            'UPDATE marketing_contacts SET
               status = IF(?, "Sent", status),
               first_contact_date = COALESCE(first_contact_date, ?),
               last_contact_date = ?,
               campaign_ref = ?,
               outreach_method = IF(outreach_method IS NULL OR outreach_method="", "Email", outreach_method),
               notes = ?,
               updated_at = UTC_TIMESTAMP()
             WHERE id=?'
        );
        foreach ($contacts as $c) {
            $notes = (string)($c['notes'] ?? '');
            // Append the Gmail note once per message id.
            $newNotes = $notes;
            if ($msgId !== '' && strpos($notes, 'Gmail ' . $msgId) === false) {
                $newNotes = ($notes !== '' ? $notes . ' | ' : '') . $note;
            }
            $upd->execute([
                $followup ? 0 : 1, $date, $date,
                mb_substr((string)$campaign['name'], 0, 120),
                $newNotes !== '' ? mb_substr($newNotes, 0, 60000) : null,
                (int)$c['id'],
            ]);
            $this->event((int)$c['id'], null, 'send_recorded',
                ['campaign_id' => $campaign['id'], 'kind' => $campaign['kind'], 'gmail' => $msgId]);
        }
        $this->pdo->prepare("UPDATE marketing_campaign_recipients SET sheet_sync_status='synced', sheet_sync_attempts=sheet_sync_attempts+1, sheet_sync_error=NULL, sheet_synced_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([(int)$recipient['id']]);
        return true;
    }

    private function exportRow(array $r): array
    {
        return [
            'contact_id' => (int)$r['id'], 'org' => $r['org_name'] ?? '',
            'parent_org' => $r['parent_name'] ?? '', 'contact_name' => $r['full_name'] ?? '',
            'first_name' => $r['first_name'] ?? '', 'last_name' => $r['last_name'] ?? '',
            'email' => $r['email_normalized'] ?? $r['email'] ?? '', 'title' => $r['title'] ?? '',
            'phone' => $r['phone'] ?? '', 'status' => $r['status'] ?? '', 'priority' => $r['priority'] ?? '',
            'fit' => $r['fit'] ?? '', 'practice_type' => $r['org_type'] ?? '',
            'last_contact' => $r['last_contact_date'] ?? '', 'first_contact' => $r['first_contact_date'] ?? '',
            'followup_date' => $r['followup_date'] ?? '', 'response' => $r['response'] ?? '',
            'campaign' => $r['campaign_ref'] ?? '', 'method' => $r['outreach_method'] ?? '',
            'owner' => $r['owner_email'] ?? '', 'source' => $r['source'] ?? '',
            'tags' => is_string($r['tags'] ?? null) ? implode('|', json_decode($r['tags'], true) ?: []) : '',
            'notes' => $r['notes'] ?? '', 'contact_notes' => $r['contact_notes'] ?? '',
            'demo_status' => $r['demo_status'] ?? '', 'trial_status' => $r['trial_status'] ?? '',
            'outcome' => $r['outcome'] ?? '', 'objection_reason' => $r['objection_reason'] ?? '',
            'needs_review' => (int)($r['needs_review'] ?? 0) === 1 ? 'yes' : '',
            'review_reason' => $r['review_reason'] ?? '', 'sheet_row' => $r['sheet_row'] ?? null,
        ];
    }
}
