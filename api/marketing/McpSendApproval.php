<?php
/**
 * Human approval gate for MCP `send_batch` deliveries.
 *
 * An MCP access token can never approve a send: the only approval path is
 * an interactive admin session + CSRF through api/marketing-admin.php.
 *
 * Lifecycle:
 *   pending  -> created by send_batch(approve=true) with no approval_id,
 *               bound to batch_id + content_hash, expires after TTL.
 *   approved -> a superuser approves in Marketing Outreach; single-use.
 *   consumed -> atomically claimed by send_batch(approval_id) immediately
 *               before delivery; the existing per-recipient claims and
 *               send-ledger reconciliation still guard every send.
 *   denied   -> admin explicitly rejected the request.
 *
 * Content binding: the approval stores the batch content_hash (canonical
 * recipient list + subject + html + campaign). The send path recomputes
 * the hash from live rows and requires hash_equals() against BOTH the
 * batch row and the approval row, so any post-approval edit invalidates
 * the approval and fails closed.
 */
class McpSendApproval {
    public const TTL_MINUTES = 30;

    /** Recompute the canonical batch content hash (must match server.php). */
    public static function batchContentHash(PDO $pdo, string $batchId): string {
        $emails = $pdo->query(
            "SELECT email FROM marketing_batch_recipients WHERE batch_id=" . $pdo->quote($batchId) . " ORDER BY email"
        )->fetchAll(PDO::FETCH_COLUMN);
        $b = $pdo->prepare("SELECT subject, html, campaign FROM marketing_batches WHERE id=?");
        $b->execute([$batchId]);
        $batch = $b->fetch(PDO::FETCH_ASSOC);
        if (!$batch) return '';
        return hash('sha256', json_encode([$emails, $batch['subject'], $batch['html'], $batch['campaign']]));
    }

    public static function ttlMinutes(): int {
        $v = (int)(getenv('MCP_SEND_APPROVAL_TTL_MIN') ?: 0);
        return $v > 0 ? min(240, $v) : self::TTL_MINUTES;
    }

    /**
     * Create (or reuse an existing live) pending approval for a batch.
     * Idempotent for agent retries: an unexpired pending approval for the
     * same batch + content hash is returned instead of duplicated.
     */
    public static function request(PDO $pdo, array $batch, string $contentHash, int $userId, string $email): array {
        $existing = $pdo->prepare(
            "SELECT id, expires_at FROM marketing_send_approvals
             WHERE batch_id=? AND content_hash=? AND status='pending' AND expires_at>NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $existing->execute([$batch['id'], $contentHash]);
        if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
            return ['approval_id' => $row['id'], 'expires_at' => $row['expires_at'], 'reused' => true];
        }
        $counts = $pdo->prepare(
            "SELECT COUNT(*) total, COALESCE(SUM(eligible),0) eligible
             FROM marketing_batch_recipients WHERE batch_id=?"
        );
        $counts->execute([$batch['id']]);
        $c = $counts->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'eligible' => 0];
        $id = self::uuid();
        $expires = date('Y-m-d H:i:s', time() + self::ttlMinutes() * 60);
        $pdo->prepare(
            "INSERT INTO marketing_send_approvals
             (id, batch_id, content_hash, recipient_count, eligible_count,
              requested_by_user_id, requested_by_email, status, expires_at)
             VALUES (?,?,?,?,?,?,?,'pending',?)"
        )->execute([$id, $batch['id'], $contentHash, (int)$c['total'], (int)$c['eligible'],
                    $userId, substr($email, 0, 255), $expires]);
        return ['approval_id' => $id, 'expires_at' => $expires, 'reused' => false];
    }

    /**
     * Atomically consume an approved, unexpired, content-matching approval.
     * Returns ['ok'=>true] or ['ok'=>false,'error'=>...] - never throws for
     * expected denials so the caller can return a clean tool error.
     */
    public static function consume(PDO $pdo, string $approvalId, string $batchId, string $expectedHash): array {
        $s = $pdo->prepare("SELECT * FROM marketing_send_approvals WHERE id=? AND batch_id=?");
        $s->execute([$approvalId, $batchId]);
        $ap = $s->fetch(PDO::FETCH_ASSOC);
        if (!$ap) return ['ok' => false, 'error' => 'approval not found for this batch'];
        if ($ap['status'] !== 'approved' || $ap['consumed_at'] !== null) {
            return ['ok' => false, 'error' => 'approval is not approved or was already used (status=' . $ap['status'] . ')'];
        }
        if (strtotime((string)$ap['expires_at']) <= time()) {
            return ['ok' => false, 'error' => 'approval expired - request a new one'];
        }
        if (!hash_equals((string)$ap['content_hash'], $expectedHash)) {
            return ['ok' => false, 'error' => 'batch content changed since approval - prepare a new batch'];
        }
        // Single-use atomic claim: exactly one concurrent caller wins.
        $u = $pdo->prepare(
            "UPDATE marketing_send_approvals SET status='consumed', consumed_at=NOW()
             WHERE id=? AND status='approved' AND consumed_at IS NULL AND expires_at>NOW()"
        );
        $u->execute([$approvalId]);
        if ($u->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'approval already used or expired'];
        }
        return ['ok' => true];
    }

    /** Admin list: pending first, then recent terminal states. */
    public static function listForAdmin(PDO $pdo, int $limit = 50): array {
        return $pdo->query(
            "SELECT a.id, a.batch_id, a.content_hash, a.recipient_count, a.eligible_count,
                    a.requested_by_email, a.status, a.expires_at, a.approved_by_email,
                    a.approved_at, a.consumed_at, a.denied_by_email, a.created_at,
                    b.campaign, b.subject, b.status AS batch_status
             FROM marketing_send_approvals a
             JOIN marketing_batches b ON b.id = a.batch_id
             ORDER BY (a.status='pending' AND a.expires_at>NOW()) DESC, a.created_at DESC
             LIMIT " . max(1, min(200, $limit))
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Full detail for the admin review UI: approval + batch content +
     * per-recipient rows + live hash verification. content_hash_match
     * tells the UI whether the batch still matches what was requested.
     */
    public static function detailForAdmin(PDO $pdo, string $approvalId): ?array {
        $s = $pdo->prepare(
            "SELECT a.*, b.campaign, b.subject, b.html, b.content_hash AS batch_hash, b.status AS batch_status
             FROM marketing_send_approvals a JOIN marketing_batches b ON b.id = a.batch_id
             WHERE a.id=?"
        );
        $s->execute([$approvalId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $recips = $pdo->prepare(
            "SELECT email, eligible, eligible_reason, status FROM marketing_batch_recipients
             WHERE batch_id=? ORDER BY email"
        );
        $recips->execute([$row['batch_id']]);
        $row['recipients'] = $recips->fetchAll(PDO::FETCH_ASSOC);
        $row['live_hash'] = self::batchContentHash($pdo, (string)$row['batch_id']);
        $row['content_hash_match'] = hash_equals((string)$row['content_hash'], (string)$row['live_hash'])
            && hash_equals((string)$row['content_hash'], (string)$row['batch_hash']);
        return $row;
    }

    /**
     * Human approval - only callable behind an interactive admin session
     * + CSRF (marketing-admin.php enforces both before dispatch). Binds
     * approval to the exact current content hash; any drift fails closed.
     */
    public static function approve(PDO $pdo, string $approvalId, int $userId, string $email): array {
        $detail = self::detailForAdmin($pdo, $approvalId);
        if (!$detail) return ['ok' => false, 'error' => 'not found'];
        if ($detail['status'] !== 'pending') return ['ok' => false, 'error' => 'not pending (status=' . $detail['status'] . ')'];
        if (strtotime((string)$detail['expires_at']) <= time()) {
            return ['ok' => false, 'error' => 'expired - ask the requester to submit again'];
        }
        if (empty($detail['content_hash_match'])) {
            return ['ok' => false, 'error' => 'batch content changed since the request - cannot approve stale content'];
        }
        if ((int)$detail['eligible_count'] < 1) {
            return ['ok' => false, 'error' => 'no eligible recipients'];
        }
        $u = $pdo->prepare(
            "UPDATE marketing_send_approvals
             SET status='approved', approved_by_user_id=?, approved_by_email=?, approved_at=NOW()
             WHERE id=? AND status='pending' AND expires_at>NOW()"
        );
        $u->execute([$userId, substr($email, 0, 255), $approvalId]);
        if ($u->rowCount() !== 1) return ['ok' => false, 'error' => 'already decided or expired'];
        return ['ok' => true, 'batch_id' => (string)$detail['batch_id']];
    }

    public static function deny(PDO $pdo, string $approvalId, string $email): array {
        $u = $pdo->prepare(
            "UPDATE marketing_send_approvals
             SET status='denied', denied_by_email=?, denied_at=NOW()
             WHERE id=? AND status='pending'"
        );
        $u->execute([substr($email, 0, 255), $approvalId]);
        if ($u->rowCount() !== 1) return ['ok' => false, 'error' => 'not pending or not found'];
        return ['ok' => true];
    }

    private static function uuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}
