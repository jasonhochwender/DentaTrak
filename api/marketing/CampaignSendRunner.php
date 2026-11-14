<?php
/**
 * Campaign send batch runner - the single implementation of per-recipient
 * delivery used by BOTH the admin endpoint (one batch per request) and
 * the background worker (paced batches until the campaign drains).
 *
 * Every gate from the original send action is preserved per recipient:
 * policy allowlist, live suppression + org suppression, CRM/sheet
 * precheck, follow-up thread enforcement, ledger-idempotent send,
 * confirmed-send write-back, and uncertain-send reconciliation.
 */
require_once __DIR__ . '/CampaignSendSupport.php';
require_once __DIR__ . '/ProspectCrm.php';
require_once __DIR__ . '/ProspectCrmAdmin.php';
require_once __DIR__ . '/OutreachEligibility.php';

final class CampaignSendRunner
{
    private PDO $pdo;
    private string $baseUrl;
    private ?MarketingMailer $mailer;

    public function __construct(PDO $pdo, string $baseUrl, ?MarketingMailer $mailer = null)
    {
        $this->pdo = $pdo;
        $this->baseUrl = $baseUrl;
        $this->mailer = $mailer;
    }

    private function mailer(): MarketingMailer
    {
        return $this->mailer ??= new MarketingMailer($this->pdo, $this->baseUrl);
    }

    /**
     * Recipient rows eligible for an automatic (worker) batch: approved,
     * uncertain (ledger-reconciled, never blindly resent), and stale
     * 'sending' leftovers. 'failed' is deliberately excluded - permanent
     * failures must not retry forever; the admin Retry path handles them.
     */
    public static function autoSendableSql(string $alias = ''): string
    {
        return mo_auto_sendable_where($alias);
    }

    /**
     * Fetch the next batch of sendable recipient rows.
     * $onlyIds narrows to specific recipient ids (admin per-recipient /
     * pilot sends); null means the normal auto-sendable set plus
     * 'failed' rows so a manual retry batch still works.
     */
    public function nextBatch(string $campaignId, int $limit, ?array $onlyIds = null, bool $includeFailed = true): array
    {
        if ($onlyIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $onlyIds))));
            if (!$ids) return [];
            $in = implode(',', $ids);
            $st = $this->pdo->query(
                "SELECT * FROM marketing_campaign_recipients
                 WHERE campaign_id=" . $this->pdo->quote($campaignId) . "
                   AND id IN ($in)
                   AND status IN ('approved','failed','uncertain','sending')
                 ORDER BY id LIMIT " . max(1, $limit)
            );
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $failed = $includeFailed ? " OR status='failed'" : '';
        $st = $this->pdo->prepare(
            "SELECT * FROM marketing_campaign_recipients
             WHERE campaign_id=? AND (" . self::autoSendableSql() . "$failed)
             ORDER BY id LIMIT " . max(1, $limit)
        );
        $st->execute([$campaignId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Process one batch of recipient rows. Returns per-outcome counts and
     * per-recipient results. Never approves or changes campaign approval
     * state; rollup/status transitions are the caller's job.
     *
     * @param array $camp campaign row
     * @param array $recips recipient rows (already selected)
     * @param int $paceMicros delay between recipients (worker pacing; 0 = none)
     */
    public function runBatch(array $camp, array $recips, int $paceMicros = 0): array
    {
        $pdo = $this->pdo;
        $mailer = $this->mailer();
        $cid = (string)$camp['id'];
        $ref = mo_ref($cid);

        // CRM mode (MARKETING_CRM_READS): MySQL is authoritative for
        // send-time verification and confirmed-send write-back. Sheet mode
        // is the legacy fallback while the flag is off.
        $crmReads = false;
        try {
            require_once __DIR__ . '/../feature-flags.php';
        } catch (Throwable $e) { /* flags already loaded by caller normally */ }
        if (function_exists('isFeatureEnabled') && isFeatureEnabled('MARKETING_CRM_READS')) {
            $crmReads = true;
        }
        $crm = $crmReads ? new ProspectCrm($pdo) : null;
        if ($crmReads && !$crm->available()) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'uncertain' => 0,
                'blocked' => 0, 'sheet_failed' => 0, 'results' => [],
                'error' => 'crm_not_migrated'];
        }
        $admin = $crmReads ? new ProspectCrmAdmin($pdo, 'send-worker') : null;

        $sheet = null;
        if (!$crmReads) {
            try { $sheet = new McpSheetSync(); $sheet->sheet(); } catch (Throwable $e) {
                return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'uncertain' => 0,
                    'blocked' => 0, 'sheet_failed' => 0, 'results' => [],
                    'error' => 'prospect sheet unreachable - send aborted'];
            }
        }

        // Outstanding sheet write-backs from earlier runs first (no mail).
        $sheetRetry = $crmReads ? ['synced' => 0, 'failed' => 0] : mo_sheet_sync_pending($pdo, $sheet, $camp);

        // One batched CRM lookup for the whole send batch (no N+1).
        $crmByEmail = [];
        if ($crmReads) {
            $crmByEmail = $crm->findByEmails(array_map(static fn($r) => (string)$r['prospect_email'], $recips));
        }

        $svc = new SuppressionService($pdo);
        $sent = 0; $skipped = 0; $failed = 0; $uncertain = 0; $blocked = 0; $sheetFailed = 0; $results = [];
        foreach ($recips as $r) {
            if ($paceMicros > 0) usleep($paceMicros);
            // Claim the recipient row. Rows already 'sending' are leftovers
            // of an interrupted run that this request now owns.
            if ($r['status'] !== 'sending') {
                $cl = $pdo->prepare("UPDATE marketing_campaign_recipients SET status='sending' WHERE id=? AND status=?");
                $cl->execute([$r['id'], $r['status']]);
                if ($cl->rowCount() !== 1) { $skipped++; continue; }
            }
            $email = SuppressionService::normalizeEmail($r['prospect_email']);

            // 0) Recipient-level delivery control (test allowlist).
            $gate = $mailer->policy()->check($email);
            if (!$gate['allowed']) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='approved', skip_reason=? WHERE id=?")
                    ->execute([$gate['reason'], $r['id']]);
                $blocked++; $results[] = ['email' => $email, 'status' => 'blocked', 'reason' => $gate['reason']];
                continue;
            }

            // 1) Eligibility re-check immediately before send: suppression
            //    store, sheet Do Not Contact / unsubscribed / bounced, and
            //    the rule for this campaign's kind. A send already recorded
            //    for THIS campaign is not "prior outreach" for that rule.
            $ctx = mo_elig_ctx($pdo, $svc, $email, $ref);
            if ($ctx['suppressed'] || $ctx['org_suppressed']) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason='suppressed_recheck' WHERE id=?")->execute([$r['id']]);
                $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed'];
                continue;
            }
            // An uncertain/interrupted send must reach the ledger so it is
            // reconciled; the sheet may legitimately show it as contacted.
            // Permanent exclusions still stop it.
            $resuming = in_array($r['status'], ['uncertain', 'sending'], true);
            if ($crmReads) {
                // Database-backed gate: annotated CRM record + suppression
                // ctx. 'not_in_crm' mirrors the sheet's not_in_sheet.
                $gate = $admin->sendPrecheck($crmByEmail[$email] ?? null, $camp['kind'], $resuming);
                if ($gate['action'] === 'suppressed') {
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason=? WHERE id=?")
                        ->execute([substr((string)$gate['reason'], 0, 190), $r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed', 'reason' => $gate['reason']];
                    continue;
                }
                if ($gate['action'] !== 'send') {
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='skipped', skip_reason=?, error=? WHERE id=?")
                        ->execute([substr(implode(',', $gate['flags']), 0, 190), 'crm_precheck', $r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'skipped', 'reason' => implode(',', $gate['flags'])];
                    continue;
                }
            } else {
                $pre = $sheet->preCheck($email, $camp['kind'], $ctx);
                if (!$pre['ok'] && !($resuming && !$pre['hard'])) {
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='skipped', skip_reason=?, error=? WHERE id=?")
                        ->execute([substr(implode(',', $pre['flags']), 0, 190), 'sheet_precheck', $r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'skipped', 'reason' => implode(',', $pre['flags'])];
                    continue;
                }
            }

            // 2) Send via the shared mailer (ledger-idempotent, footer,
            //    RFC 8058). Follow-ups reply in the resolved conversation.
            $thread = null;
            if ($camp['kind'] === 'followup') {
                if (($r['thread_status'] ?? '') === 'resolved') {
                    $thread = ['thread_id' => (string)$r['gmail_thread_id'],
                        'in_reply_to' => (string)$r['thread_rfc_message_id'],
                        'references' => (string)$r['thread_references']];
                } elseif ((int)($camp['new_thread_ack'] ?? 0) !== 1) {
                    // Unthreaded follow-ups are a new conversation; they
                    // require the explicit approval-time acknowledgment.
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='skipped', skip_reason='new_thread_not_acknowledged', error='new_thread_not_acknowledged' WHERE id=?")
                        ->execute([$r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'skipped', 'reason' => 'new_thread_not_acknowledged'];
                    continue;
                }
            }
            $res = $mailer->send([
                'to' => $email, 'subject' => (string)$r['subject'],
                'html' => (string)$r['body_html'], 'campaign' => $ref,
                'thread' => $thread,
            ]);
            $st = $res['status'] ?? 'uncertain';
            // The ledger already holds a confirmed send for this recipient.
            if ($st === 'already_recorded') $st = 'sent';

            if ($st === 'sent') {
                $row = mo_mark_recipient_sent($pdo, $r, (string)($res['message_id'] ?? ''), (string)($res['thread_id'] ?? ''));
                // Confirmed-send write-back. CRM mode updates MySQL
                // synchronously (source of truth, no sheet required); a
                // failure is recorded on the recipient row, never silently.
                // Sheet mode queues the sheet write-back for later retry.
                if ($crmReads) {
                    if (!$admin->recordSend($row, $camp)) $sheetFailed++;
                } elseif (!mo_sheet_sync_one($pdo, $sheet, $row, $camp)) {
                    $sheetFailed++;
                }
                $sent++; $results[] = ['email' => $email, 'status' => 'sent', 'message_id' => $res['message_id'] ?? '',
                    'reconciled' => !empty($res['reconciled'])];
            } elseif ($st === 'skipped_suppressed') {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason='suppressed' WHERE id=?")->execute([$r['id']]);
                $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed'];
            } elseif ($st === 'sending_disabled' || $st === 'recipient_not_allowlisted') {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='approved', skip_reason=? WHERE id=?")->execute([$st, $r['id']]);
                $blocked++; $results[] = ['email' => $email, 'status' => 'blocked', 'reason' => $st];
            } elseif ($st === 'uncertain' || $st === 'in_progress') {
                // Outcome unknown: held for reconciliation, never resent blindly.
                $err = substr((string)($res['error'] ?? $st), 0, 250);
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='uncertain', error=? WHERE id=?")->execute([$err, $r['id']]);
                $uncertain++; $results[] = ['email' => $email, 'status' => 'uncertain', 'error' => $err];
            } else {
                $err = substr((string)($res['error'] ?? $st), 0, 250);
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='failed', error=? WHERE id=?")->execute([$err, $r['id']]);
                $failed++; $results[] = ['email' => $email, 'status' => 'failed', 'error' => $err];
            }
        }

        return [
            'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
            'uncertain' => $uncertain, 'blocked' => $blocked,
            'sheet_failed' => $sheetFailed + $sheetRetry['failed'],
            'sheet_retried' => $sheetRetry['synced'],
            'results' => $results,
        ];
    }
}
