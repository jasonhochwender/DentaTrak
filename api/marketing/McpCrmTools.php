<?php
/**
 * Prospect-CRM tools for the private MCP server (ChatGPT connector).
 *
 * Read tools query the MySQL CRM (ProspectCrm). Write tools go through
 * ProspectCrmAdmin so validation, duplicate warnings, optimistic
 * concurrency, review flags, DNC monotonicity and prospect events are
 * identical to the admin UI. Every mutation is audit-logged with the
 * token owner's email as actor. Nothing here sends email or touches
 * suppression/unsubscribe tables.
 */
require_once __DIR__ . '/ProspectCrm.php';
require_once __DIR__ . '/ProspectCrmAdmin.php';

final class McpCrmTools
{
    private PDO $pdo;
    private string $actor;
    private ProspectCrm $crm;
    private ProspectCrmAdmin $admin;

    public function __construct(PDO $pdo, string $actor)
    {
        $this->pdo = $pdo;
        $this->actor = $actor;
        $this->crm = new ProspectCrm($pdo);
        $this->admin = new ProspectCrmAdmin($pdo, $actor);
    }

    private function requireCrm(): void
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_contacts'"
            )->fetchColumn() === 1;
        }
        if (!$ok) throw new InvalidArgumentException('prospect CRM is not available in this environment');
    }

    private function audit(string $action, ?int $contactId, ?int $orgId, $detail = null): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO marketing_audit_log (actor_email,action,campaign_id,detail) VALUES (?,?,NULL,?)'
            )->execute([$this->actor, $action, $detail === null ? null : json_encode($detail)]);
        } catch (Throwable $e) { error_log('[mcp-crm] audit: ' . $e->getMessage()); }
    }

    private static function intArg(array $a, string $k): int
    {
        $v = $a[$k] ?? null;
        if ($v === null || $v === '' || !is_numeric($v) || (int)$v <= 0) {
            throw new InvalidArgumentException("$k (positive integer) required");
        }
        return (int)$v;
    }

    /** Extract the imported State abbreviation from extra_fields JSON. */
    private static function stateFromExtra($extra): ?string
    {
        if (is_string($extra)) $extra = json_decode($extra, true);
        $s = $extra['State'] ?? null;
        return is_string($s) && trim($s) !== '' ? strtoupper(trim($s)) : null;
    }

    /**
     * Write tools require confirm=true in the same call. ChatGPT must only
     * set it after the user explicitly approves that specific operation;
     * the flag is per-request so it cannot authorize unrelated changes.
     */
    private static function requireConfirmation(array $a, string $op): void
    {
        if (($a['confirm'] ?? null) !== true) {
            throw new InvalidArgumentException(
                "$op requires confirm=true. Present the proposed change to the user, " .
                'get explicit approval, then retry with confirm=true.');
        }
    }

    /**
     * Drop empty-string/null values from MCP update payloads. The admin
     * layer treats explicit '' as "clear this field"; connector updates
     * must never wipe verified data with missing research, so blanks are
     * ignored (field clearing remains available in the admin UI).
     */
    private static function stripBlankFields(array $fields): array
    {
        $dropped = [];
        foreach ($fields as $k => $v) {
            if ($v === '' || $v === null) { unset($fields[$k]); $dropped[] = $k; }
        }
        return [$fields, $dropped];
    }

    /* ---------------------------------------------------------------- */
    /* Read tools                                                         */
    /* ---------------------------------------------------------------- */

    public function searchProspects(array $a): array
    {
        $this->requireCrm();
        $o = [
            'q'         => mb_substr((string)($a['q'] ?? ''), 0, 200),
            'org_type'  => mb_substr((string)($a['org_type'] ?? ''), 0, 60),
            'status'    => mb_substr((string)($a['status'] ?? ''), 0, 60),
            'priority'  => mb_substr((string)($a['priority'] ?? ''), 0, 40),
            'eligible'  => in_array($a['eligible'] ?? '', ['initial', 'followup'], true) ? $a['eligible'] : '',
            'flag'      => mb_substr((string)($a['flag'] ?? ''), 0, 60),
            'bucket'    => mb_substr((string)($a['bucket'] ?? ''), 0, 40),
            'city'      => mb_substr((string)($a['city'] ?? ''), 0, 120),
            'state'     => mb_substr((string)($a['state'] ?? ''), 0, 40),
            'review'    => !empty($a['review']) ? 1 : 0,
            'page'      => max(1, (int)($a['page'] ?? 1)),
            'per'       => min(100, max(1, (int)($a['per'] ?? 25))),
        ];
        $r = $this->crm->search($o);
        $rows = array_map(static function ($p) {
            $f = $p['fields'] ?? [];
            return [
                'contact_id' => $p['id'] ?? null,
                'org'        => $f['org'] ?? '',
                'contact'    => $f['contact_name'] ?? '',
                'email'      => $f['email'] ?? '',
                'status'     => $f['status'] ?? '',
                'priority'   => $f['priority'] ?? '',
                'followup'   => $f['followup_date'] ?? '',
                'eligible'   => $p['eligible'] ?? false,
                'flags'      => $p['flags'] ?? [],
                'needs_review' => !empty($f['needs_review']),
                'location'   => $f['location'] ?? '',
                'state'      => self::stateFromExtra($f['extra_fields'] ?? null),
            ];
        }, $r['rows']);
        return ['total' => $r['total'], 'page' => $o['page'], 'per' => $o['per'], 'prospects' => $rows];
    }

    public function getProspect(array $a): array
    {
        $this->requireCrm();
        $id = self::intArg($a, 'contact_id');
        $p = $this->crm->findById($id);
        if ($p === null) throw new InvalidArgumentException('contact not found');
        $org = null;
        $orgId = $p['fields']['org_id'] ?? null;
        if ($orgId) {
            $st = $this->pdo->prepare('SELECT id,name,org_type,domain,website,location,parent_org_id,status,tags FROM marketing_orgs WHERE id=?');
            $st->execute([$orgId]);
            $org = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $ev = $this->pdo->prepare(
            'SELECT event_type, details, actor, source, created_at FROM marketing_prospect_events
             WHERE contact_id=? ORDER BY id DESC LIMIT 20'
        );
        $ev->execute([$id]);
        $events = array_map(static function ($e) {
            $e['details'] = json_decode((string)$e['details'], true);
            return $e;
        }, $ev->fetchAll(PDO::FETCH_ASSOC));
        return ['prospect' => $p, 'organization' => $org, 'recent_events' => $events];
    }

    public function getOrganization(array $a): array
    {
        $this->requireCrm();
        $id = self::intArg($a, 'org_id');
        $st = $this->pdo->prepare('SELECT * FROM marketing_orgs WHERE id=?');
        $st->execute([$id]);
        $org = $st->fetch(PDO::FETCH_ASSOC);
        if (!$org) throw new InvalidArgumentException('organization not found');
        $cc = $this->pdo->prepare('SELECT COUNT(*) FROM marketing_contacts WHERE org_id=?');
        $cc->execute([$id]);
        $ch = $this->pdo->prepare('SELECT id,name,org_type FROM marketing_orgs WHERE parent_org_id=?');
        $ch->execute([$id]);
        $par = null;
        if (!empty($org['parent_org_id'])) {
            $ps = $this->pdo->prepare('SELECT id,name FROM marketing_orgs WHERE id=?');
            $ps->execute([$org['parent_org_id']]);
            $par = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return [
            'organization' => $org,
            'contact_count' => (int)$cc->fetchColumn(),
            'child_organizations' => $ch->fetchAll(PDO::FETCH_ASSOC),
            'parent_organization' => $par,
        ];
    }

    public function listOrganizationContacts(array $a): array
    {
        $this->requireCrm();
        $id = self::intArg($a, 'org_id');
        $all = $this->crm->contactsForOrg($id);
        $per = min(200, max(1, (int)($a['per'] ?? 100)));
        $page = max(1, (int)($a['page'] ?? 1));
        return [
            'org_id' => $id,
            'total'  => count($all),
            'page'   => $page,
            'per'    => $per,
            'contacts' => array_slice($all, ($page - 1) * $per, $per),
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Duplicate check + prepare (no writes)                             */
    /* ---------------------------------------------------------------- */

    public function checkProspectDuplicate(array $a): array
    {
        $this->requireCrm();
        $out = ['email_matches' => [], 'org_matches' => [], 'contact_matches' => []];
        $email = strtolower(trim((string)($a['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $st = $this->pdo->prepare(
                'SELECT c.id, c.full_name, c.org_id, o.name AS org_name FROM marketing_contacts c
                 LEFT JOIN marketing_orgs o ON o.id=c.org_id
                 WHERE c.email_normalized=? LIMIT 10'
            );
            $st->execute([$email]);
            $out['email_matches'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $orgName = trim((string)($a['org_name'] ?? ''));
        if ($orgName !== '') {
            $st = $this->pdo->prepare('SELECT id,name,org_type,domain FROM marketing_orgs WHERE normalized_name=? LIMIT 10');
            $st->execute([mb_strtolower($orgName)]);
            $out['org_matches'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $orgId = !empty($a['org_id']) ? (int)$a['org_id'] : null;
        $contactName = trim((string)($a['contact_name'] ?? ''));
        if ($contactName !== '' && $orgId) {
            $st = $this->pdo->prepare('SELECT id,full_name,email FROM marketing_contacts WHERE org_id=? AND full_name=? LIMIT 10');
            $st->execute([$orgId, $contactName]);
            $out['contact_matches'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    /**
     * Validate a proposed prospect without writing anything. Returns the
     * normalized payload plus duplicate warnings for human review.
     */
    public function prepareProspect(array $a): array
    {
        $this->requireCrm();
        $issues = [];
        $email = strtolower(trim((string)($a['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'email is not a valid address';
        }
        if (empty($a['org_id']) && empty($a['new_org']['name'])) {
            $issues[] = 'org_id or new_org.name is required';
        }
        if (trim((string)($a['contact_name'] ?? $a['full_name'] ?? '')) === ''
            && trim((string)($a['first_name'] ?? '')) === ''
            && trim((string)($a['last_name'] ?? '')) === '') {
            $issues[] = 'a contact name is required (or the record will be flagged for review)';
        }
        $dup = $this->checkProspectDuplicate($a);
        $hasDup = $dup['email_matches'] || $dup['org_matches'] || $dup['contact_matches'];
        return [
            'valid' => $issues === [],
            'issues' => $issues,
            'duplicates' => $dup,
            'requires_review' => $hasDup || $email === '',
            'normalized' => [
                'org_id'   => !empty($a['org_id']) ? (int)$a['org_id'] : null,
                'new_org'  => $a['new_org'] ?? null,
                'email'    => $email,
                'contact_name' => trim((string)($a['contact_name'] ?? $a['full_name'] ?? '')),
                'status'   => trim((string)($a['status'] ?? 'Not Contacted')),
            ],
            'note' => 'No records were written. Review duplicates, then call create_prospect to persist.',
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Write tools (audited)                                             */
    /* ---------------------------------------------------------------- */

    public function createProspect(array $a): array
    {
        $this->requireCrm();
        self::requireConfirmation($a, 'create_prospect');
        $prep = $this->prepareProspect($a);
        // createContact performs its own validation; surface prep issues
        // as warnings rather than hard-blocking incomplete research drafts.
        $contact = ['source' => 'chatgpt_connector'];
        foreach (['org_id','new_org','first_name','last_name','full_name','contact_name',
                  'email','title','phone','status','priority','fit','owner_email','source',
                  'tags','notes','contact_notes','followup_date','demo_status','trial_status',
                  'outcome','objection_reason','outreach_method'] as $k) {
            if (array_key_exists($k, $a)) $contact[$k] = $a[$k];
        }
        if (isset($a['contact_name']) && !isset($a['full_name'])) $contact['full_name'] = $a['contact_name'];
        if (isset($contact['source']) && $contact['source'] === '') $contact['source'] = 'chatgpt_connector';
        try {
            $r = $this->admin->createContact($contact);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('create failed: ' . $e->getMessage());
        }
        $this->audit('mcp_prospect_create', $r['id'], $contact['org_id'] ?? null,
            ['warnings' => array_column($r['warnings'] ?? [], 'code'), 'prep_issues' => $prep['issues']]);
        return ['contact_id' => $r['id'], 'warnings' => $r['warnings'] ?? [],
                'review_reasons' => $r['review_reasons'] ?? [], 'prep_issues' => $prep['issues']];
    }

    public function createOrganization(array $a): array
    {
        $this->requireCrm();
        if (trim((string)($a['name'] ?? '')) === '') {
            throw new InvalidArgumentException('organization name required');
        }
        // dry_run previews validation + duplicate warnings without writing.
        if (!empty($a['dry_run'])) {
            $norm = mb_strtolower(trim((string)$a['name']));
            $st = $this->pdo->prepare('SELECT id,name,org_type,domain FROM marketing_orgs WHERE normalized_name=? LIMIT 10');
            $st->execute([$norm]);
            return [
                'dry_run' => true,
                'would_create' => ['name' => trim((string)$a['name']), 'org_type' => $a['org_type'] ?? null,
                                   'domain' => $a['domain'] ?? null, 'parent_org_id' => $a['parent_org_id'] ?? null],
                'duplicate_org_matches' => $st->fetchAll(PDO::FETCH_ASSOC),
                'note' => 'No records were written. Review, then call create_organization with confirm=true.',
            ];
        }
        self::requireConfirmation($a, 'create_organization');
        $r = $this->admin->createOrg($a);
        $this->audit('mcp_org_create', null, $r['id'], ['warnings' => array_column($r['warnings'] ?? [], 'code')]);
        return ['org_id' => $r['id'], 'warnings' => $r['warnings'] ?? []];
    }

    public function updateProspect(array $a): array
    {
        $this->requireCrm();
        $id = self::intArg($a, 'contact_id');
        $version = self::intArg($a, 'version');
        self::requireConfirmation($a, 'update_prospect');
        $fields = $a['fields'] ?? $a;
        unset($fields['contact_id'], $fields['version'], $fields['fields'], $fields['confirm']);
        [$fields, $dropped] = self::stripBlankFields($fields);
        try {
            $r = $this->admin->updateContact($id, $fields, $version);
        } catch (CrmConflict $e) {
            throw new InvalidArgumentException('version conflict: ' . $e->getMessage());
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('update failed: ' . $e->getMessage());
        }
        $this->audit('mcp_prospect_update', $id, null, ['changed' => $r['changed'] ?? [], 'ignored_blank_fields' => $dropped]);
        $r['ignored_blank_fields'] = $dropped;
        return $r;
    }

    public function updateOrganization(array $a): array
    {
        $this->requireCrm();
        $id = self::intArg($a, 'org_id');
        self::requireConfirmation($a, 'update_organization');
        $fields = $a['fields'] ?? $a;
        unset($fields['org_id'], $fields['fields'], $fields['confirm']);
        [$fields, $dropped] = self::stripBlankFields($fields);
        try {
            $r = $this->admin->updateOrg($id, $fields);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('update failed: ' . $e->getMessage());
        }
        $this->audit('mcp_org_update', null, $id, ['changed' => $r['changed'] ?? [], 'ignored_blank_fields' => $dropped]);
        $r['ignored_blank_fields'] = $dropped;
        return $r;
    }

    public function addProspectNote(array $a): array
    {
        $this->requireCrm();
        $id = self::intArg($a, 'contact_id');
        self::requireConfirmation($a, 'add_prospect_note');
        $text = trim((string)($a['text'] ?? ''));
        if ($text === '') throw new InvalidArgumentException('note text required');
        $meta = [];
        if (!empty($a['sources']) && is_array($a['sources'])) $meta['sources'] = $a['sources'];
        if (!empty($a['researched_at'])) $meta['researched_at'] = $a['researched_at'];
        $eventId = $this->admin->addResearchNote($id, $text, $meta);
        $this->audit('mcp_prospect_note', $id, null, null);
        return ['event_id' => $eventId, 'contact_id' => $id];
    }
}
