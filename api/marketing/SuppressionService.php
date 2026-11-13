<?php
/**
 * Authoritative marketing-suppression store (ported from SiteRecord).
 *
 * - Normalization: trim + case-fold; plus/dots kept — suppresses the exact
 *   mailbox, never guesses.
 * - Suppression is idempotent and permanent; transactional mail is never
 *   routed through this store.
 * - Sheets sync is a durable outbox: suppression succeeds even when the
 *   spreadsheet is unavailable; pending rows are retried by the worker.
 */
final class SuppressionService
{
    /** Lazily-loaded full suppression sets (one query each per instance). */
    private ?array $suppressedSet = null;
    private ?array $orgSuppressedSet = null;

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
            $eligible = $valid && !$this->isSuppressed($n) && !($orgRef !== null && $this->isOrgSuppressed($orgRef));
            $out[] = ['email' => $n, 'eligible' => $eligible, 'reason' => $eligible ? '' : 'suppressed_or_invalid'];
        }
        return $out;
    }
}
