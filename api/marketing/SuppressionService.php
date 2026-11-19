<?php
/**
 * Authoritative marketing-suppression store (ported from SiteRecord).
 *
 * - Normalization: trim + case-fold; plus/dots kept — suppresses the exact
 *   mailbox, never guesses.
 * - Suppression is idempotent and permanent; transactional mail is never
 *   routed through this store.
 * - Temporary delivery problems use marketing_email_holds instead: an
 *   expiring send hold that is never written to the permanent store.
 * - Sheets sync is a durable outbox: suppression succeeds even when the
 *   spreadsheet is unavailable; pending rows are retried by the worker.
 */
final class SuppressionService
{
    /** Lazily-loaded full suppression sets (one query each per instance). */
    private ?array $suppressedSet = null;
    private ?array $orgSuppressedSet = null;
    private ?array $holdSet = null;

    /** Repeated temporary failures escalate to a longer, reviewable hold. */
    private const HOLD_ESCALATE_AFTER = 3;
    private const HOLD_ESCALATED_TTL = 2592000; // 30 days

    public function __construct(private PDO $pdo) {}

    private function suppressedSet(): array
    {
        if ($this->suppressedSet === null) {
            $this->suppressedSet = [];
            foreach ($this->pdo->query('SELECT email_normalized FROM marketing_suppressions')->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $this->suppressedSet[strtolower((string)$e)] = true;
            }
        }
        return $this->suppressedSet;
    }

    private function orgSuppressedSet(): array
    {
        if ($this->orgSuppressedSet === null) {
            $this->orgSuppressedSet = [];
            foreach ($this->pdo->query('SELECT org_ref FROM marketing_org_suppressions')->fetchAll(PDO::FETCH_COLUMN) as $r) {
                $this->orgSuppressedSet[strtolower((string)$r)] = true;
            }
        }
        return $this->orgSuppressedSet;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Issue an opaque unsubscribe token; only its SHA-256 hash is stored. */
    public function issueToken(string $email, ?string $campaignRef = null): string
    {
        $email = self::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email');
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO marketing_unsub_tokens (token_hash,email_normalized,campaign_ref) VALUES (?,?,?)'
        )->execute([hash('sha256', $token), $email, $campaignRef]);
        return $token;
    }

    /** Resolve a presented token to its email; null when unknown/revoked. */
    public function resolveToken(string $token): ?string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
        $s = $this->pdo->prepare(
            'SELECT email_normalized FROM marketing_unsub_tokens WHERE token_hash=? AND revoked_at IS NULL'
        );
        $s->execute([hash('sha256', $token)]);
        $r = $s->fetchColumn();
        return $r ?: null;
    }

    /** Record suppression. Idempotent; enqueues the sheet-sync outbox row. */
    public function suppress(string $email, string $source = 'email_unsubscribe', ?string $campaignRef = null): array
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required.');
        }
        $existing = $this->row($email);
        if ($existing) {
            return ['email' => $email, 'already_suppressed' => true, 'suppression' => $existing];
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO marketing_suppressions (email_normalized,unsubscribed_at,source,campaign_ref)
                 VALUES (?,UTC_TIMESTAMP(),?,?)'
            )->execute([$email, substr($source, 0, 60), $campaignRef !== null ? substr($campaignRef, 0, 160) : null]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) throw $e; // concurrent suppress: return winner
        }
        $row = $this->row($email);
        if ($this->suppressedSet !== null) $this->suppressedSet[$email] = true;
        $this->enqueueSync((int) $row['id']);
        return ['email' => $email, 'already_suppressed' => false, 'suppression' => $row];
    }

    private function enqueueSync(int $suppressionId): void
    {
        $this->pdo->prepare(
            'INSERT INTO marketing_sync_queue (suppression_id,next_attempt_at) VALUES (?,UTC_TIMESTAMP())'
        )->execute([$suppressionId]);
        $this->pdo->prepare("UPDATE marketing_suppressions SET sync_status='pending' WHERE id=?")->execute([$suppressionId]);
    }

    public function row(string $email): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM marketing_suppressions WHERE email_normalized=?');
        $s->execute([self::normalizeEmail($email)]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function isSuppressed(string $email): bool
    {
        return isset($this->suppressedSet()[self::normalizeEmail($email)]);
    }

    /** Stable org id = normalized email domain (e.g. "sagedentalnj.com"). */
    public static function orgRefForEmail(string $email): ?string
    {
        $e = self::normalizeEmail($email);
        $d = strrpos($e, '@');
        return $d === false ? null : substr($e, $d + 1);
    }

    /** Suppress an entire org by stable domain ref. Idempotent. */
    public function suppressOrg(string $orgRef, string $source = 'email_unsubscribe', ?string $reason = null): array
    {
        $orgRef = strtolower(trim($orgRef));
        if ($orgRef === '') throw new RuntimeException('org_ref required');
        if (self::isSharedDomain($orgRef)) throw new RuntimeException('shared email provider cannot be suppressed as an organization');
        $s = $this->pdo->prepare('SELECT * FROM marketing_org_suppressions WHERE org_ref=?');
        $s->execute([$orgRef]);
        $existing = $s->fetch(PDO::FETCH_ASSOC);
        if ($existing) return ['org_ref' => $orgRef, 'already_suppressed' => true, 'suppression' => $existing];
        $this->pdo->prepare(
            'INSERT INTO marketing_org_suppressions (org_ref,source,reason) VALUES (?,?,?)'
        )->execute([$orgRef, substr($source, 0, 60), $reason !== null ? substr($reason, 0, 200) : null]);
        if ($this->orgSuppressedSet !== null) $this->orgSuppressedSet[$orgRef] = true;
        $s->execute([$orgRef]);
        return ['org_ref' => $orgRef, 'already_suppressed' => false, 'suppression' => $s->fetch(PDO::FETCH_ASSOC)];
    }

    public function isOrgSuppressed(string $orgRef): bool
    {
        return isset($this->orgSuppressedSet()[strtolower(trim($orgRef))]);
    }

    /* ---------------- organization-level Do Not Contact ---------------- */

    /**
     * Shared mailbox providers. A domain here never identifies an
     * organization, so it can never be suppressed as one.
     */
    public const SHARED_DOMAINS = [
        'gmail.com','googlemail.com','yahoo.com','ymail.com','rocketmail.com','hotmail.com','outlook.com',
        'live.com','msn.com','aol.com','icloud.com','me.com','mac.com','comcast.net',
        'att.net','verizon.net','sbcglobal.net','bellsouth.net','earthlink.net',
        'charter.net','spectrum.net','cox.net','frontier.com','windstream.net','roadrunner.com',
        'rr.com','netzero.net','juno.com','embarqmail.com','centurylink.net','optonline.net',
        'mail.com','protonmail.com','proton.me','pm.me','gmx.com','gmx.net','zoho.com',
        'yandex.com','inbox.com','lavabit.com','fastmail.com','hey.com','tutanota.com',
    ];

    public static function isSharedDomain(string $domain): bool
    {
        return in_array(self::cleanDomain($domain), self::SHARED_DOMAINS, true);
    }

    /** Bare lowercase host: strips scheme, path, port and a leading "www.". */
    public static function cleanDomain(string $raw): string
    {
        $d = strtolower(trim($raw));
        if ($d === '') return '';
        if (strpos($d, '@') !== false) $d = substr($d, strrpos($d, '@') + 1);
        if (strpos($d, '://') !== false) $d = (string)parse_url($d, PHP_URL_HOST);
        $d = preg_replace('#[/:?\#].*$#', '', $d) ?? $d;
        if (str_starts_with($d, 'www.')) $d = substr($d, 4);
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d) ? $d : '';
    }

    private ?array $orgDnc = null;

    /**
     * Do-Not-Contact organizations and the addresses of their contacts.
     * Empty until the org-DNC migration has run.
     * @return array{orgs: array<int,true>, emails: array<string,int>}
     */
    private function orgDnc(): array
    {
        if ($this->orgDnc === null) {
            $this->orgDnc = ['orgs' => [], 'emails' => []];
            try {
                foreach ($this->pdo->query(
                    "SELECT o.id, c.email_normalized FROM marketing_orgs o
                     LEFT JOIN marketing_contacts c ON c.org_id = o.id WHERE o.dnc = 1"
                )->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $this->orgDnc['orgs'][(int)$r['id']] = true;
                    $e = strtolower(trim((string)($r['email_normalized'] ?? '')));
                    if ($e !== '') $this->orgDnc['emails'][$e] = (int)$r['id'];
                }
            } catch (Throwable $e) { /* column not migrated yet */ }
        }
        return $this->orgDnc;
    }

    public function isOrgDnc(int $orgId): bool
    {
        return isset($this->orgDnc()['orgs'][$orgId]);
    }

    /**
     * Organization-level block for an address: its domain is suppressed,
     * or it belongs to a contact of a Do-Not-Contact organization (this is
     * what covers staff who use a shared provider such as gmail.com).
     */
    public function isOrgBlocked(string $email): bool
    {
        $email = self::normalizeEmail($email);
        if ($email === '') return false;
        $ref = self::orgRefForEmail($email);
        return ($ref !== null && $this->isOrgSuppressed($ref)) || isset($this->orgDnc()['emails'][$email]);
    }

    /** Why an address is blocked at organization level, for display. */
    public function orgBlockInfo(string $email): ?array
    {
        $email = self::normalizeEmail($email);
        $ref = self::orgRefForEmail($email);
        if ($ref !== null && $this->isOrgSuppressed($ref)) {
            $s = $this->pdo->prepare('SELECT * FROM marketing_org_suppressions WHERE org_ref=?');
            $s->execute([$ref]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['via' => 'domain', 'domain' => $ref, 'org_id' => isset($row['org_id']) ? (int)$row['org_id'] ?: null : null,
                    'reason' => $row['reason'] ?? null, 'source' => $row['source'] ?? null, 'at' => $row['created_at'] ?? null];
        }
        $oid = $this->orgDnc()['emails'][$email] ?? null;
        if ($oid === null) return null;
        $s = $this->pdo->prepare('SELECT dnc_reason, dnc_at, dnc_by FROM marketing_orgs WHERE id=?');
        $s->execute([$oid]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['via' => 'organization', 'domain' => null, 'org_id' => $oid, 'reason' => $row['dnc_reason'] ?? null,
                'source' => 'admin_org_dnc', 'at' => $row['dnc_at'] ?? null];
    }
    /**
     * What marking an organization Do Not Contact would cover. Read-only.
     * A domain is suppressed only when it is recorded on the organization
     * (domain or website), is not a shared provider, and is not also used
     * by contacts of an unrelated organization; everything else is listed
     * under skipped_domains and is covered per-contact instead.
     */
    public function orgDncPlan(int $orgId): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM marketing_orgs WHERE id=?');
        $s->execute([$orgId]);
        $org = $s->fetch(PDO::FETCH_ASSOC);
        if (!$org) return null;
        $c = $this->pdo->prepare('SELECT id, email_normalized, dnc FROM marketing_contacts WHERE org_id=?');
        $c->execute([$orgId]);
        $contacts = $c->fetchAll(PDO::FETCH_ASSOC);

        $candidates = [];
        foreach ([(string)($org['domain'] ?? ''), (string)($org['website'] ?? '')] as $raw) {
            $d = self::cleanDomain($raw);
            if ($d !== '') $candidates[$d] = true;
        }
        $domains = []; $skipped = [];
        foreach (array_keys($candidates) as $d) {
            if (self::isSharedDomain($d)) { $skipped[] = ['domain' => $d, 'reason' => 'shared_provider']; continue; }
            // Same domain used by contacts of an unrelated organization
            // (not this org, its parent, or its children): not ours alone.
            $o = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT c.org_id) FROM marketing_contacts c
                 JOIN marketing_orgs x ON x.id = c.org_id
                 WHERE SUBSTRING_INDEX(c.email_normalized, '@', -1) = ?
                   AND c.org_id <> ? AND COALESCE(x.parent_org_id, 0) <> ? AND x.id <> ?");
            $o->execute([$d, $orgId, $orgId, (int)($org['parent_org_id'] ?? 0)]);
            if ((int)$o->fetchColumn() > 0) { $skipped[] = ['domain' => $d, 'reason' => 'used_by_other_organization']; continue; }
            $domains[] = $d;
        }
        // Contact address domains that are NOT verified for the org are
        // reported so the admin can see they are covered per contact only.
        foreach ($contacts as $ct) {
            $d = self::orgRefForEmail((string)$ct['email_normalized']);
            if ($d === null || $d === '' || in_array($d, $domains, true)) continue;
            $known = array_column($skipped, 'domain');
            if (!in_array($d, $known, true)) {
                $skipped[] = ['domain' => $d, 'reason' => self::isSharedDomain($d) ? 'shared_provider' : 'not_verified_for_organization'];
            }
        }
        $pending = 0;
        try {
            $emails = array_values(array_filter(array_map(fn($x) => (string)$x['email_normalized'], $contacts)));
            $where = []; $args = [];
            if ($emails) { $where[] = 'r.prospect_email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')'; $args = $emails; }
            foreach ($domains as $d) { $where[] = "SUBSTRING_INDEX(r.prospect_email, '@', -1) = ?"; $args[] = $d; }
            if ($where) {
                $p = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM marketing_campaign_recipients r
                     WHERE r.status NOT IN ('sent','suppressed','skipped') AND (" . implode(' OR ', $where) . ')');
                $p->execute($args);
                $pending = (int)$p->fetchColumn();
            }
        } catch (Throwable $e) { /* campaign tables absent */ }
        return [
            'org' => ['id' => (int)$org['id'], 'name' => (string)$org['name'], 'dnc' => (int)($org['dnc'] ?? 0) === 1,
                      'dnc_at' => $org['dnc_at'] ?? null, 'dnc_by' => $org['dnc_by'] ?? null, 'dnc_reason' => $org['dnc_reason'] ?? null],
            'domains' => $domains, 'skipped_domains' => $skipped,
            'contacts' => count($contacts), 'pending_recipients' => $pending,
            'suppressed_domains' => $this->orgDomainRows($orgId),
        ];
    }

    /** Domain suppressions recorded for this organization. */
    public function orgDomainRows(int $orgId): array
    {
        try {
            $s = $this->pdo->prepare('SELECT org_ref, source, reason, created_at, created_by FROM marketing_org_suppressions WHERE org_id=? ORDER BY org_ref');
            $s->execute([$orgId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /**
     * Mark an organization Do Not Contact: blocks every current and future
     * contact of the organization and suppresses its verified domain(s).
     * Contacts, notes, campaign history and contact-level status are left
     * exactly as they are. Idempotent.
     */
    public function suppressOrganization(int $orgId, string $actor, string $reason, string $source = 'admin_org_dnc'): array
    {
        $plan = $this->orgDncPlan($orgId);
        if ($plan === null) throw new RuntimeException('organization not found');
        $reason = trim($reason);
        if ($reason === '') throw new RuntimeException('reason required');
        $actor = substr($actor, 0, 190);
        $already = $plan['org']['dnc'];
        $newDomains = [];
        $this->pdo->beginTransaction();
        try {
            if (!$already) {
                $this->pdo->prepare(
                    'UPDATE marketing_orgs SET dnc=1, dnc_at=UTC_TIMESTAMP(), dnc_by=?, dnc_reason=?, updated_at=UTC_TIMESTAMP() WHERE id=? AND dnc=0'
                )->execute([$actor, mb_substr($reason, 0, 255), $orgId]);
            }
            foreach ($plan['domains'] as $d) {
                $r = $this->suppressOrg($d, $source, mb_substr($reason, 0, 200));
                // A domain that was already suppressed (for example by an
                // earlier unsubscribe) keeps its own origin: it is not
                // linked here, so removing this organization's Do Not
                // Contact later can never lift it.
                if (!empty($r['already_suppressed'])) continue;
                $newDomains[] = $d;
                $this->pdo->prepare(
                    'UPDATE marketing_org_suppressions SET org_id=?, created_by=? WHERE org_ref=? AND org_id IS NULL'
                )->execute([$orgId, $actor, $d]);
            }
            if (!$already || $newDomains) {
                $this->pdo->prepare(
                    "INSERT INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source)
                     VALUES (NULL, ?, 'org_dnc_set', ?, ?, 'crm')"
                )->execute([$orgId, json_encode(['reason' => $reason, 'domains' => $plan['domains'],
                    'new_domains' => $newDomains, 'skipped_domains' => $plan['skipped_domains'],
                    'contacts' => $plan['contacts'], 'source' => $source]), $actor]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->orgSuppressedSet = null;
            throw $e;
        }
        $this->orgDnc = null;
        return ['org_id' => $orgId, 'already_dnc' => $already, 'domains' => $plan['domains'], 'new_domains' => $newDomains,
                'skipped_domains' => $plan['skipped_domains'], 'contacts' => $plan['contacts'],
                'pending_recipients' => $plan['pending_recipients']];
    }

    /**
     * Remove organization-level Do Not Contact. Deliberate: the caller must
     * supply the organization's exact name and a reason. Lifts only the
     * org flag and the domain rows that belong to this organization;
     * contact-level suppression and do-not-contact flags are never touched.
     */
    public function unsuppressOrganization(int $orgId, string $actor, string $confirmName, string $reason): array
    {
        $s = $this->pdo->prepare('SELECT id, name, dnc, dnc_reason, dnc_at, dnc_by FROM marketing_orgs WHERE id=?');
        $s->execute([$orgId]);
        $org = $s->fetch(PDO::FETCH_ASSOC);
        if (!$org) throw new RuntimeException('organization not found');
        if (trim($reason) === '') throw new RuntimeException('reason required');
        if (trim($confirmName) === '' || mb_strtolower(trim($confirmName)) !== mb_strtolower(trim((string)$org['name']))) {
            throw new RuntimeException('confirmation does not match the organization name');
        }
        $domains = array_column($this->orgDomainRows($orgId), 'org_ref');
        if ((int)$org['dnc'] !== 1 && !$domains) return ['org_id' => $orgId, 'was_dnc' => false, 'domains_removed' => []];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE marketing_orgs SET dnc=0, dnc_at=NULL, dnc_by=NULL, dnc_reason=NULL, updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$orgId]);
            $this->pdo->prepare('DELETE FROM marketing_org_suppressions WHERE org_id=?')->execute([$orgId]);
            $this->pdo->prepare(
                "INSERT INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source)
                 VALUES (NULL, ?, 'org_dnc_removed', ?, ?, 'crm')"
            )->execute([$orgId, json_encode(['reason' => trim($reason), 'domains_removed' => $domains,
                'previous' => ['reason' => $org['dnc_reason'], 'at' => $org['dnc_at'], 'by' => $org['dnc_by']]]), substr($actor, 0, 190)]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->orgDnc = null; $this->orgSuppressedSet = null;
        return ['org_id' => $orgId, 'was_dnc' => (int)$org['dnc'] === 1, 'domains_removed' => $domains];
    }

    /** Bounded batch eligibility check — only eligible:true may send. */
    public function eligibility(array $emails): array
    {
        $out = [];
        foreach (array_slice($emails, 0, 200) as $e) {
            $n = self::normalizeEmail((string) $e);
            $valid = $n !== '' && filter_var($n, FILTER_VALIDATE_EMAIL);
            $orgRef = $valid ? self::orgRefForEmail($n) : null;
            $eligible = $valid && !$this->isBlocked($n) && !$this->isOrgBlocked($n);
            $out[] = ['email' => $n, 'eligible' => $eligible, 'reason' => $eligible ? '' : 'suppressed_or_invalid'];
        }
        return $out;
    }

    /* ---------------- temporary holds (non-permanent) ---------------- */

    /** False until the holds migration has run. */
    public function holdsAvailable(): bool { return $this->hasHoldsTable(); }

    private function hasHoldsTable(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = (bool)$this->pdo->query(
                    "SELECT 1 FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketing_email_holds' LIMIT 1"
                )->fetchColumn();
            } catch (Throwable $e) { $has = false; }
        }
        return $has;
    }

    private function holdSet(): array
    {
        if ($this->holdSet === null) {
            $this->holdSet = [];
            if ($this->hasHoldsTable()) {
                try {
                    $now = gmdate('Y-m-d H:i:s');
                    foreach ($this->pdo->query(
                        "SELECT email_normalized FROM marketing_email_holds
                          WHERE status IN ('active','escalated') AND hold_until > '$now'"
                    )->fetchAll(PDO::FETCH_COLUMN) as $e) {
                        $this->holdSet[strtolower((string)$e)] = true;
                    }
                } catch (Throwable $e) { /* table mid-migration: no holds */ }
            }
        }
        return $this->holdSet;
    }

    /**
     * Place/extend a temporary sending hold. NOT a permanent suppression:
     * the address is send-blocked until hold_until, then eligible again
     * unless another failure extends it. Repeated failures
     * (>= HOLD_ESCALATE_AFTER) escalate: longer hold + 'escalated' status
     * for human review. $eventTs anchors the hold to when the failure
     * actually happened, so reconciling an old failure does not create a
     * fresh hold today.
     */
    public function holdEmail(string $email, string $source = 'bounce_temporary',
                              int $ttlSeconds = 604800, ?string $reason = null, ?int $eventTs = null): array
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required.');
        }
        if (!$this->hasHoldsTable()) throw new RuntimeException('marketing_email_holds table missing');
        $now = time();
        $at = $eventTs !== null && $eventTs > 0 && $eventTs <= $now ? $eventTs : $now;
        $atSql = gmdate('Y-m-d H:i:s', $at);
        $existing = $this->holdRow($email);
        $fails = $existing ? ((int)$existing['fail_count'] + 1) : 1;
        $first = $existing ? min((string)$existing['first_seen_at'], $atSql) : $atSql;
        $last = $existing ? max((string)$existing['last_seen_at'], $atSql) : $atSql;
        // Failure streak escalates only for genuine temporary failures.
        $escalated = $source === 'bounce_temporary' && $fails >= self::HOLD_ESCALATE_AFTER;
        $ttl = $escalated ? max($ttlSeconds, self::HOLD_ESCALATED_TTL) : $ttlSeconds;
        $until = gmdate('Y-m-d H:i:s', $at + $ttl);
        if ($existing && in_array($existing['status'], ['active', 'escalated'], true)
            && (string)$existing['hold_until'] > $until) {
            $until = (string)$existing['hold_until'];
        }
        $active = strtotime($until . ' UTC') > $now;
        $status = !$active ? 'expired' : ($escalated ? 'escalated' : 'active');
        $this->pdo->prepare(
            "INSERT INTO marketing_email_holds
             (email_normalized, source, reason, fail_count, first_seen_at, last_seen_at,
              hold_until, status)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               source=VALUES(source), reason=VALUES(reason),
               fail_count=VALUES(fail_count), first_seen_at=VALUES(first_seen_at),
               last_seen_at=VALUES(last_seen_at),
               hold_until=VALUES(hold_until), status=VALUES(status)"
        )->execute([
            $email, substr($source, 0, 60), $reason !== null ? substr($reason, 0, 200) : null,
            $fails, $first, $last, $until, $status,
        ]);
        if ($this->holdSet !== null) {
            if ($active) $this->holdSet[$email] = true; else unset($this->holdSet[$email]);
        }
        return ['held' => $active, 'fail_count' => $fails, 'escalated' => $escalated && $active,
                'hold_until' => $until, 'status' => $status];
    }

    private function holdRow(string $email): ?array
    {
        if (!$this->hasHoldsTable()) return null;
        try {
            $s = $this->pdo->prepare('SELECT * FROM marketing_email_holds WHERE email_normalized=?');
            $s->execute([self::normalizeEmail($email)]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }
    }

    /** Active (unexpired) hold row for display, or null. */
    public function holdInfo(string $email): ?array
    {
        $r = $this->holdRow($email);
        if (!$r) return null;
        if (!in_array($r['status'], ['active', 'escalated'], true)) return null;
        if (strtotime((string)$r['hold_until'] . ' UTC') <= time()) return null;
        return $r;
    }

    public function isHeld(string $email): bool
    {
        return isset($this->holdSet()[self::normalizeEmail($email)]);
    }

    /** Marketing-blocked = permanent suppression OR active temporary hold. */
    public function isBlocked(string $email): bool
    {
        return $this->isSuppressed($email) || $this->isHeld($email);
    }

    /** Mark lapsed holds 'expired'. Idempotent sweep (bookkeeping only: an
     *  elapsed hold_until already stops blocking on its own). */
    public function releaseExpiredHolds(): int
    {
        if (!$this->hasHoldsTable()) return 0;
        $st = $this->pdo->prepare(
            "UPDATE marketing_email_holds SET status='expired'
              WHERE status IN ('active','escalated') AND hold_until <= UTC_TIMESTAMP()"
        );
        $st->execute();
        $n = $st->rowCount();
        if ($n > 0 && $this->holdSet !== null) $this->holdSet = null;
        return $n;
    }

    /** Clear a hold manually (admin release action). */
    public function releaseHold(string $email): bool
    {
        if (!$this->hasHoldsTable()) return false;
        $st = $this->pdo->prepare(
            "UPDATE marketing_email_holds SET status='released' WHERE email_normalized=? AND status IN ('active','escalated')");
        $st->execute([self::normalizeEmail($email)]);
        if ($this->holdSet !== null) unset($this->holdSet[self::normalizeEmail($email)]);
        return $st->rowCount() > 0;
    }
}
