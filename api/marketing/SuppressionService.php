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
    /** Bounded batch eligibility check — only eligible:true may send. */
    public function eligibility(array $emails): array
    {
        $out = [];
        foreach (array_slice($emails, 0, 200) as $e) {
            $n = self::normalizeEmail((string) $e);
            $valid = $n !== '' && filter_var($n, FILTER_VALIDATE_EMAIL);
            $orgRef = $valid ? self::orgRefForEmail($n) : null;
            $eligible = $valid && !$this->isBlocked($n) && !($orgRef !== null && $this->isOrgSuppressed($orgRef));
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
