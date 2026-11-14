<?php
/**
 * Shared helpers for marketing campaign sending. Used by both the admin
 * endpoint (api/marketing-admin.php, one synchronous batch per request)
 * and the background send worker (api/marketing-send-worker.php, paced
 * batches until the campaign drains). Single source of truth so both
 * paths enforce identical gates.
 */
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/McpSheetSync.php';
require_once __DIR__ . '/MarketingMailer.php';

/** Stable campaign reference used in ledgers, sheet notes, suppressions. */
function mo_ref(string $campaignId): string { return 'mo:' . $campaignId; }

// Send-batch bounds shared by the admin endpoint and the worker.
const MO_SEND_BATCH_DEFAULT = 15;
const MO_SEND_BATCH_MAX = 50;

/** Recipients processed per synchronous send request / worker batch. */
function mo_send_batch_size(): int {
    $v = (int)(getenv('MARKETING_SEND_BATCH') ?: 0);
    if ($v <= 0) return MO_SEND_BATCH_DEFAULT;
    return max(1, min(MO_SEND_BATCH_MAX, $v));
}

/**
 * Live eligibility context immediately before a send: suppression store,
 * org-level suppression, and whether this email already has a confirmed
 * send outside the current campaign.
 */
function mo_elig_ctx(PDO $pdo, SuppressionService $svc, string $email, ?string $excludeRef = null): array {
    $orgRef = SuppressionService::orgRefForEmail($email);
    $sql = "SELECT 1 FROM marketing_sends WHERE email_normalized=? AND status='sent'";
    $args = [$email];
    if ($excludeRef !== null) { $sql .= ' AND campaign_ref<>?'; $args[] = $excludeRef; }
    $st = $pdo->prepare($sql . ' LIMIT 1');
    $st->execute($args);
    return [
        'suppressed' => $svc->isSuppressed($email),
        'org_suppressed' => $orgRef !== null && $svc->isOrgSuppressed($orgRef),
        'ledger_sent' => $st->fetchColumn() !== false,
        'require_in_sheet' => true,
    ];
}

/** Recipient statuses the background worker may pick up automatically. */
function mo_auto_sendable_where(string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    $stale = (int)MarketingMailer::STALE_RUNNING_SECONDS;
    return "({$p}status='approved' OR {$p}status='uncertain'
        OR ({$p}status='sending' AND {$p}updated_at < NOW() - INTERVAL $stale SECOND))";
}

/** Count recipients a background pass could still attempt. */
function mo_sendable_remaining(PDO $pdo, string $campaignId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=? AND ' . mo_auto_sendable_where());
    $st->execute([$campaignId]);
    return (int)$st->fetchColumn();
}

/** Recompute campaign status + counters from its recipients. */
function mo_rollup(PDO $pdo, string $campaignId): array {
    $cnt = $pdo->prepare('SELECT status, COUNT(*) n FROM marketing_campaign_recipients WHERE campaign_id=? GROUP BY status');
    $cnt->execute([$campaignId]);
    $c = [];
    foreach ($cnt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $c[$k] = (int)$v;
    $remaining = ($c['approved'] ?? 0) + ($c['failed'] ?? 0) + ($c['uncertain'] ?? 0) + ($c['sending'] ?? 0);
    $final = $remaining > 0 ? 'partial' : 'completed';
    $pdo->prepare('UPDATE marketing_campaigns SET status=?, sent_count=?, skipped_count=?, failed_count=? WHERE id=?')
        ->execute([$final, $c['sent'] ?? 0, ($c['skipped'] ?? 0) + ($c['suppressed'] ?? 0),
            ($c['failed'] ?? 0) + ($c['uncertain'] ?? 0), $campaignId]);
    return ['status' => $final, 'counts' => $c];
}

/**
 * Write one confirmed send back to the prospect sheet and record the
 * outcome durably. Never sends mail; safe to repeat (the Gmail note is
 * appended once, the other cells are idempotent values).
 */
function mo_sheet_sync_one(PDO $pdo, McpSheetSync $sheet, array $recipient, array $campaign): bool {
    $msgId = (string)($recipient['gmail_message_id'] ?? '');
    $date = substr((string)($recipient['sent_at'] ?? ''), 0, 10) ?: gmdate('Y-m-d');
    $followup = $campaign['kind'] === 'followup';
    $fields = ['Last Contact Date' => $date, 'Campaign' => (string)$campaign['name']];
    if (!$followup) {
        // Follow-ups leave Outreach Status as it is: the row already
        // records that the prospect was contacted.
        $fields['Outreach Status'] = 'Sent';
        $fields['First Contact Date'] = $date;
        $fields['Outreach Method'] = 'Email';
    }
    $note = $msgId !== '' ? 'Gmail ' . $msgId . ($followup ? ' (follow-up)' : '') : '';
    try {
        $sheet->recordSend((string)$recipient['prospect_email'], $fields, $note);
        $pdo->prepare("UPDATE marketing_campaign_recipients SET sheet_sync_status='synced', sheet_sync_attempts=sheet_sync_attempts+1,
            sheet_sync_error=NULL, sheet_synced_at=UTC_TIMESTAMP() WHERE id=?")->execute([$recipient['id']]);
        return true;
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE marketing_campaign_recipients SET sheet_sync_status='failed', sheet_sync_attempts=sheet_sync_attempts+1,
            sheet_sync_error=? WHERE id=?")->execute([substr($e->getMessage(), 0, 250), $recipient['id']]);
        error_log('[marketing-admin] sheet write-back: ' . $e->getMessage());
        return false;
    }
}

/** Retry every outstanding sheet write-back for a campaign. No mail. */
function mo_sheet_sync_pending(PDO $pdo, McpSheetSync $sheet, array $campaign): array {
    $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND status='sent'
        AND sheet_sync_status IN ('pending','failed') ORDER BY id");
    $rs->execute([$campaign['id']]);
    $ok = 0; $bad = 0;
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        mo_sheet_sync_one($pdo, $sheet, $r, $campaign) ? $ok++ : $bad++;
    }
    return ['synced' => $ok, 'failed' => $bad];
}

/** Mark a recipient delivered and queue its sheet write-back. */
function mo_mark_recipient_sent(PDO $pdo, array $recipient, string $msgId, string $threadId): array {
    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='sent', gmail_message_id=?, gmail_thread_id=?,
        sent_at=COALESCE(sent_at, UTC_TIMESTAMP()), error=NULL, skip_reason=NULL, sheet_sync_status='pending' WHERE id=?")
        ->execute([$msgId !== '' ? $msgId : null, $threadId !== '' ? $threadId : ($recipient['gmail_thread_id'] ?? null), $recipient['id']]);
    $r = $pdo->prepare('SELECT * FROM marketing_campaign_recipients WHERE id=?');
    $r->execute([$recipient['id']]);
    return $r->fetch(PDO::FETCH_ASSOC) ?: $recipient;
}

function mo_audit(PDO $pdo, string $actor, string $action, ?string $campaignId, $detail = null): void {
    try {
        $pdo->prepare("INSERT INTO marketing_audit_log (actor_email,action,campaign_id,detail) VALUES (?,?,?,?)")
            ->execute([$actor, substr($action, 0, 60), $campaignId, $detail === null ? null : json_encode($detail)]);
    } catch (Throwable $e) { error_log('[marketing-admin] audit: ' . $e->getMessage()); }
}
