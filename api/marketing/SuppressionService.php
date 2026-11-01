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
    public function __construct(private PDO $pdo) {}

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
        return $this->row($email) !== null;
    }

    /** Bounded batch eligibility check — only eligible:true may send. */
    public function eligibility(array $emails): array
    {
        $out = [];
        foreach (array_slice($emails, 0, 200) as $e) {
            $n = self::normalizeEmail((string) $e);
            $eligible = $n !== '' && filter_var($n, FILTER_VALIDATE_EMAIL) && !$this->isSuppressed($n);
            $out[] = ['email' => $n, 'eligible' => $eligible, 'reason' => $eligible ? '' : 'suppressed_or_invalid'];
        }
        return $out;
    }
}
