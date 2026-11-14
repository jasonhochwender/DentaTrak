<?php
/**
 * Campaign-preparation tools for the private MCP server (ChatGPT connector).
 *
 * These tools operate on the SAME marketing_campaigns /
 * marketing_campaign_recipients records as the Marketing Outreach UI,
 * through the same services (ProspectCrmAdmin, MarketingMailer,
 * CampaignContentHash, MessageHtml). They cover draft preparation only:
 * campaign creation, recipient selection, personalized message drafts,
 * final-email preview, and readiness reporting.
 *
 * Nothing here approves, sends, or touches MARKETING_SENDING /
 * MARKETING_SEND_ALLOWLIST. Approval stays an explicit admin action in
 * the UI; sending stays behind the existing delivery controls.
 */
require_once __DIR__ . '/ProspectCrm.php';
require_once __DIR__ . '/ProspectCrmAdmin.php';
require_once __DIR__ . '/MarketingMailer.php';
require_once __DIR__ . '/MarketingSendingPolicy.php';
require_once __DIR__ . '/MarketingSignature.php';
require_once __DIR__ . '/CampaignContentHash.php';
require_once __DIR__ . '/MessageHtml.php';

final class McpCampaignTools
{
    /** Campaign capacity mirrors the admin endpoint default (500). */
    public const CAMPAIGN_MAX = 500;
    /** Per-call chunk limits keep MCP payloads bounded; callers loop. */
    public const MAX_CONTACTS_PER_CALL = 100;
    public const MAX_MESSAGES_PER_CALL = 50;
    public const RECIPIENTS_PAGE_MAX = 200;

    private PDO $pdo;
    private string $actor;
    private string $baseUrl;
    private ProspectCrmAdmin $admin;

    public function __construct(PDO $pdo, string $actor, string $baseUrl = '')
    {
        $this->pdo = $pdo;
        $this->actor = $actor;
        $this->baseUrl = $baseUrl;
        $this->admin = new ProspectCrmAdmin($pdo, $actor);
    }

    private function audit(string $action, ?string $campaignId, $detail = null): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO marketing_audit_log (actor_email,action,campaign_id,detail) VALUES (?,?,?,?)'
            )->execute([$this->actor, $action, $campaignId, $detail === null ? null : json_encode($detail)]);
        } catch (Throwable $e) { error_log('[mcp-campaign] audit: ' . $e->getMessage()); }
    }

    private static function requireConfirmation(array $a, string $op): void
    {
        if (($a['confirm'] ?? null) !== true) {
            throw new InvalidArgumentException(
                "$op requires confirm=true. Present the proposed change to the user, " .
                'get explicit approval, then retry with confirm=true.');
        }
    }

    private function campaignRow(string $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?');
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new InvalidArgumentException('campaign not found');
        return $c;
    }

    private function requireEditableCampaign(string $id): array
    {
        $c = $this->campaignRow($id);
        if (!in_array($c['status'], ['draft', 'ready'], true)) {
            throw new InvalidArgumentException('campaign is not editable (status: ' . $c['status'] . ')');
        }
        return $c;
    }

    private static function cleanKind($k): string
    {
        return $k === 'followup' ? 'followup' : 'initial';
    }

    /* ---------------------------------------------------------------- */
    /* Read tools                                                         */
    /* ---------------------------------------------------------------- */

    public function listCampaigns(array $a): array
    {
        $kind = self::cleanKind($a['kind'] ?? '');
        $status = trim((string)($a['status'] ?? ''));
        $where = '1=1'; $args = [];
        if (in_array($a['kind'] ?? '', ['initial', 'followup'], true)) { $where .= ' AND c.kind=?'; $args[] = $a['kind']; }
        if ($status !== '') {
            $allowed = ['draft', 'ready', 'approved', 'partial', 'sending', 'completed'];
            if (!in_array($status, $allowed, true)) throw new InvalidArgumentException('unknown status filter');
            $where .= ' AND c.status=?'; $args[] = $status;
        }
        $lim = min(100, max(1, (int)($a['limit'] ?? 50)));
        $st = $this->pdo->prepare(
            "SELECT c.id, c.name, c.kind, c.status, c.recipient_count, c.approved_by, c.approved_at,
                    c.created_by, c.created_at, c.updated_at,
                (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id) AS recipients,
                (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id AND r.status='sent') AS sent,
                (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id AND r.status IN ('approved','ready','draft')) AS pending
             FROM marketing_campaigns c WHERE $where ORDER BY c.created_at DESC LIMIT $lim"
        );
        $st->execute($args);
        return ['campaigns' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function getCampaign(array $a): array
    {
        $id = trim((string)($a['campaign_id'] ?? ''));
        if ($id === '') throw new InvalidArgumentException('campaign_id required');
        $camp = $this->campaignRow($id);
        // Recipients are paginated so a 500-recipient campaign never
        // returns one oversized payload. page/per mirror the admin API.
        $page = max(1, (int)($a['page'] ?? 1));
        $per = min(self::RECIPIENTS_PAGE_MAX, max(1, (int)($a['per_page'] ?? 50)));
        $tot = $this->pdo->prepare('SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=?');
        $tot->execute([$id]);
        $total = (int)$tot->fetchColumn();
        $pages = max(1, (int)ceil($total / $per));
        if ($page > $pages) $page = $pages;
        $off = ($page - 1) * $per;
        $st = $this->pdo->prepare(
            "SELECT id, contact_id, prospect_email, prospect_org, prospect_contact, subject, body_html,
                    status, skip_reason, is_edited, gmail_message_id, gmail_thread_id, error, sent_at,
                    thread_status, thread_reason, thread_source, thread_subject, thread_date,
                    sheet_sync_status, sheet_sync_error, sheet_synced_at
               FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id LIMIT $per OFFSET $off"
        );
        $st->execute([$id]);
        return [
            'campaign' => $camp,
            'recipients' => $st->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => $pages],
            'sending' => (new MarketingSendingPolicy())->summary(),
            'note' => 'Draft preparation only. Approval and sending remain admin actions in Marketing Outreach. Use page/per_page to page through large campaigns.',
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Write tools (confirm=true + audited; never approve or send)       */
    /* ---------------------------------------------------------------- */

    public function createCampaign(array $a): array
    {
        $name = trim((string)($a['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('name required');
        $kind = self::cleanKind($a['kind'] ?? 'initial');
        $desc = isset($a['description']) ? mb_substr((string)$a['description'], 0, 4000) : null;
        $tplId = isset($a['template_id']) && (int)$a['template_id'] > 0 ? (int)$a['template_id'] : null;

        if (!empty($a['dry_run'])) {
            $dups = $this->pdo->prepare(
                "SELECT id,name,status,created_at FROM marketing_campaigns
                 WHERE name=? AND kind=? AND created_by=? AND status IN ('draft','ready') LIMIT 5"
            );
            $dups->execute([$name, $kind, $this->actor]);
            return [
                'dry_run' => true,
                'would_create' => ['name' => $name, 'kind' => $kind, 'description' => $desc, 'template_id' => $tplId],
                'existing_draft_matches' => $dups->fetchAll(PDO::FETCH_ASSOC),
                'note' => 'No campaign was created. Review, then call create_campaign with confirm=true.',
            ];
        }
        self::requireConfirmation($a, 'create_campaign');

        // Retry safety: an identical open draft by the same actor is
        // returned instead of duplicated. Callers wanting a second campaign
        // must use a distinct name.
        $dups = $this->pdo->prepare(
            "SELECT id,name,kind,status,created_at FROM marketing_campaigns
             WHERE name=? AND kind=? AND created_by=? AND status IN ('draft','ready') ORDER BY created_at DESC LIMIT 1"
        );
        $dups->execute([$name, $kind, $this->actor]);
        $existing = $dups->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return ['campaign_id' => $existing['id'], 'existing' => true,
                'status' => $existing['status'], 'recipients' => $this->recipientCount($existing['id']),
                'note' => 'An open draft with this name/kind already exists; returned instead of creating a duplicate.'];
        }

        $d = ['name' => $name, 'kind' => $kind];
        if ($desc !== null) $d['description'] = $desc;
        if ($tplId !== null) $d['template_id'] = $tplId;
        $contactIds = array_values(array_unique(array_filter(array_map('intval', (array)($a['contact_ids'] ?? [])))));
        if (count($contactIds) > self::MAX_CONTACTS_PER_CALL) {
            throw new InvalidArgumentException('at most ' . self::MAX_CONTACTS_PER_CALL . ' contact_ids per call - add the rest with add_campaign_recipients');
        }
        if ($contactIds) $d['contact_ids'] = $contactIds;
        try {
            $r = $this->admin->createCampaign($d, self::CAMPAIGN_MAX);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('create failed: ' . $e->getMessage());
        }
        $this->audit('mcp_campaign_create', $r['campaign_id'],
            ['name' => $name, 'kind' => $kind, 'added' => $r['added'] ?? 0]);
        return ['campaign_id' => $r['campaign_id'], 'status' => 'draft',
            'added' => $r['added'] ?? 0, 'results' => $r['results'] ?? [],
            'note' => 'Draft created. It is NOT approved and cannot send until a human approves it in Marketing Outreach.'];
    }

    public function addCampaignRecipients(array $a): array
    {
        $cid = trim((string)($a['campaign_id'] ?? ''));
        if ($cid === '') throw new InvalidArgumentException('campaign_id required');
        $this->requireEditableCampaign($cid);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($a['contact_ids'] ?? [])))));
        if (!$ids) throw new InvalidArgumentException('contact_ids required');
        if (count($ids) > self::MAX_CONTACTS_PER_CALL) {
            throw new InvalidArgumentException('at most ' . self::MAX_CONTACTS_PER_CALL . ' contacts per call - repeat for larger sets');
        }
        $dryRun = !empty($a['dry_run']);
        if (!$dryRun) self::requireConfirmation($a, 'add_campaign_recipients');
        $camp = $this->campaignRow($cid);
        try {
            $r = $this->admin->addToCampaign($ids, $cid, null, self::cleanKind($camp['kind']), self::CAMPAIGN_MAX, $dryRun);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('add failed: ' . $e->getMessage());
        }
        if (!$dryRun) {
            $this->audit('mcp_campaign_add_recipients', $cid,
                ['contact_ids' => $ids, 'added' => $r['added'] ?? 0]);
        }
        return $r + ['note' => $dryRun
            ? 'Dry run - nothing was written. Retry with confirm=true to add.'
            : 'Recipients added as drafts. Suppressed/ineligible contacts are recorded as suppressed, never bypassed.'];
    }

    /**
     * Create/update individually personalized drafts for campaign
     * recipients. Mirrors the UI save_message rules: same sanitizer,
     * threaded-follow-up subject lock, approved-campaign protection.
     */
    public function saveCampaignMessages(array $a): array
    {
        $cid = trim((string)($a['campaign_id'] ?? ''));
        if ($cid === '') throw new InvalidArgumentException('campaign_id required');
        $camp = $this->requireEditableCampaign($cid);
        $msgs = (array)($a['messages'] ?? []);
        if (!$msgs || !is_array($msgs)) throw new InvalidArgumentException('messages required');
        if (count($msgs) > self::MAX_MESSAGES_PER_CALL) {
            throw new InvalidArgumentException('at most ' . self::MAX_MESSAGES_PER_CALL . ' messages per call - repeat for larger sets');
        }
        self::requireConfirmation($a, 'save_campaign_messages');

        $results = [];
        $saved = 0;
        foreach ($msgs as $i => $m) {
            $m = is_array($m) ? $m : [];
            $rid = (int)($m['recipient_id'] ?? 0);
            $cidContact = (int)($m['contact_id'] ?? 0);
            $subject = trim((string)($m['subject'] ?? ''));
            $body = MessageHtml::sanitize((string)($m['body_html'] ?? ''));
            $base = ['index' => $i, 'recipient_id' => $rid ?: null, 'contact_id' => $cidContact ?: null];
            if ($subject === '' || $body === '') {
                $results[] = $base + ['ok' => false, 'reason' => 'subject_and_body_required'];
                continue;
            }
            // Resolve the recipient row: stable recipient_id wins; contact_id
            // maps through the campaign's own membership.
            if ($rid > 0) {
                $st = $this->pdo->prepare('SELECT * FROM marketing_campaign_recipients WHERE id=? AND campaign_id=?');
                $st->execute([$rid, $cid]);
            } elseif ($cidContact > 0) {
                $st = $this->pdo->prepare('SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND contact_id=? ORDER BY id LIMIT 2');
                $st->execute([$cid, $cidContact]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > 1) {
                    $results[] = $base + ['ok' => false, 'reason' => 'ambiguous_contact_multiple_recipients'];
                    continue;
                }
                $st->execute([$cid, $cidContact]);
            } else {
                $results[] = $base + ['ok' => false, 'reason' => 'recipient_id_or_contact_id_required'];
                continue;
            }
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $results[] = $base + ['ok' => false, 'reason' => 'recipient_not_in_campaign'];
                continue;
            }
            if (in_array($row['status'], ['sent', 'sending', 'uncertain'], true)) {
                $results[] = $base + ['ok' => false, 'reason' => 'recipient_locked_' . $row['status']];
                continue;
            }
            // Threaded follow-ups keep the original subject so Gmail keeps
            // the reply in the same conversation (same rule as save_message).
            if (($row['thread_status'] ?? '') === 'resolved' && (string)($row['thread_subject'] ?? '') !== '') {
                $subject = (string)$row['thread_subject'];
            }
            $this->pdo->prepare(
                "UPDATE marketing_campaign_recipients SET subject=?, body_html=?, is_edited=1, status='ready' WHERE id=?"
            )->execute([substr($subject, 0, 500), $body, (int)$row['id']]);
            $saved++;
            $results[] = $base + ['ok' => true, 'recipient_id' => (int)$row['id'],
                'email' => $row['prospect_email'], 'subject_locked' => $subject === trim((string)($m['subject'] ?? '')) ? false : true];
        }

        // All recipients now have content -> campaign becomes reviewable.
        if ($camp['status'] === 'draft') {
            $rem = $this->pdo->prepare("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=? AND status='draft'");
            $rem->execute([$cid]);
            if ((int)$rem->fetchColumn() === 0) {
                $this->pdo->prepare("UPDATE marketing_campaigns SET status='ready' WHERE id=? AND status='draft'")->execute([$cid]);
            }
        }
        $this->audit('mcp_campaign_save_messages', $cid,
            ['saved' => $saved, 'requested' => count($msgs)]);
        return ['campaign_id' => $cid, 'saved' => $saved, 'results' => $results,
            'note' => 'Drafts saved for admin review. Signature/footer/unsubscribe are composed at send time. Nothing was approved or sent.'];
    }

    /**
     * Exact final-assembly preview for one recipient: same composeParts
     * the send path uses, with a clearly nonfunctional unsubscribe
     * placeholder (no token is issued, nothing is written).
     */
    public function previewCampaignMessage(array $a): array
    {
        $cid = trim((string)($a['campaign_id'] ?? ''));
        $rid = (int)($a['recipient_id'] ?? 0);
        if ($cid === '' || $rid <= 0) throw new InvalidArgumentException('campaign_id and recipient_id required');
        $this->campaignRow($cid);
        $st = $this->pdo->prepare(
            "SELECT id, prospect_email, prospect_contact, subject, body_html, status,
                    thread_status, thread_subject, thread_date, thread_reason
               FROM marketing_campaign_recipients WHERE id=? AND campaign_id=?"
        );
        $st->execute([$rid, $cid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('recipient not found in campaign');
        if (trim((string)$row['body_html']) === '') throw new InvalidArgumentException('recipient has no message content');

        $subject = array_key_exists('subject', $a)
            ? substr(trim((string)$a['subject']), 0, 500)
            : (string)$row['subject'];
        $body = array_key_exists('body_html', $a)
            ? MessageHtml::sanitize((string)$a['body_html'])
            : (string)$row['body_html'];

        $mailer = new MarketingMailer($this->pdo, $this->baseUrl);
        $placeholder = $this->baseUrl . '/unsubscribe.php?t=preview';
        $parts = $mailer->composeParts($body, $placeholder);

        $from = '';
        try {
            $prof = $mailer->gmailGet('/profile');
            $from = (string)($prof['data']['emailAddress'] ?? '');
        } catch (Throwable $e) { /* shown as unknown below */ }

        return [
            'from' => $from !== '' ? $from : 'connected Gmail account',
            'to_name' => (string)($row['prospect_contact'] ?? ''),
            'to_email' => (string)$row['prospect_email'],
            'subject' => $subject,
            'html' => $parts['html'],
            'text' => $parts['text'],
            'unsub_placeholder' => true,
            'unsub_note' => 'Preview link only - the real per-recipient unsubscribe token is generated at send time.',
            'signature' => MarketingSignature::load($this->pdo),
            'thread' => [
                'status' => (string)($row['thread_status'] ?? ''),
                'subject' => (string)($row['thread_subject'] ?? ''),
                'date' => (string)($row['thread_date'] ?? ''),
                'evidence' => ($row['thread_reason'] ?? '') === 'transmission_unverified' ? 'unverified' : 'verified',
            ],
            'recipient_status' => (string)$row['status'],
        ];
    }

    /**
     * Readiness report for admin review/approval. Surfaces every blocker
     * with an actionable reason; sends and approves nothing.
     */
    public function getCampaignReadiness(array $a): array
    {
        $cid = trim((string)($a['campaign_id'] ?? ''));
        if ($cid === '') throw new InvalidArgumentException('campaign_id required');
        $camp = $this->campaignRow($cid);
        $kind = self::cleanKind($camp['kind']);

        $st = $this->pdo->prepare(
            "SELECT id, contact_id, prospect_email, subject, body_html, status, skip_reason,
                    thread_status, is_edited, sent_at
               FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id"
        );
        $st->execute([$cid]);
        $recips = $st->fetchAll(PDO::FETCH_ASSOC);

        $byStatus = [];
        $issues = [];
        $emails = [];
        foreach ($recips as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
            $em = strtolower(trim((string)$r['prospect_email']));
            if ($em !== '') {
                if (isset($emails[$em])) $issues[] = ['code' => 'duplicate_email', 'detail' => $em, 'recipient_id' => (int)$r['id']];
                $emails[$em] = true;
            }
            if (in_array($r['status'], ['draft', 'ready'], true)) {
                if (trim((string)$r['subject']) === '' || trim((string)$r['body_html']) === '') {
                    $issues[] = ['code' => 'missing_content', 'recipient_id' => (int)$r['id'], 'email' => $r['prospect_email']];
                }
                if ($kind === 'followup' && ($r['thread_status'] ?? '') !== 'resolved') {
                    $issues[] = ['code' => 'thread_unresolved', 'recipient_id' => (int)$r['id'], 'email' => $r['prospect_email'],
                        'thread_status' => $r['thread_status'] ?? null];
                }
            }
            if ($r['status'] === 'suppressed') {
                $issues[] = ['code' => 'recipient_suppressed', 'recipient_id' => (int)$r['id'], 'email' => $r['prospect_email'],
                    'reason' => $r['skip_reason'] ?? null];
            }
        }

        // Live eligibility re-check for non-terminal recipients (same
        // annotated CRM evaluation the send path uses).
        $checkEmails = [];
        foreach ($recips as $r) {
            if (in_array($r['status'], ['draft', 'ready', 'approved'], true)) $checkEmails[] = $r['prospect_email'];
        }
        $ineligible = [];
        if ($checkEmails) {
            $crm = new ProspectCrm($this->pdo);
            $ann = $crm->findByEmails($checkEmails);
            foreach ($checkEmails as $e) {
                $p = $ann[strtolower(trim((string)$e))] ?? null;
                if ($p === null) { $ineligible[] = ['email' => $e, 'flags' => ['not_in_crm']]; continue; }
                $el = $p['eligibility'][$kind] ?? ['ok' => false, 'flags' => ['ineligible']];
                if (empty($el['ok'])) $ineligible[] = ['email' => $e, 'flags' => $el['flags'] ?? []];
            }
        }
        foreach ($ineligible as $i) {
            $issues[] = ['code' => 'currently_ineligible', 'email' => $i['email'], 'flags' => $i['flags']];
        }

        // Approval-hash integrity when already approved.
        $hash = null;
        if ($camp['status'] === 'approved' && !empty($camp['content_hash'])) {
            $hash = hash_equals((string)$camp['content_hash'], CampaignContentHash::compute($this->pdo, $cid)) ? 'match' : 'mismatch';
            if ($hash === 'mismatch') $issues[] = ['code' => 'content_hash_mismatch'];
        }

        $readyCount = (int)($byStatus['ready'] ?? 0) + (int)($byStatus['approved'] ?? 0);
        $missingContent = count(array_filter($issues, fn($i) => $i['code'] === 'missing_content'));
        $canReview = in_array($camp['status'], ['draft', 'ready'], true)
            && $readyCount > 0
            && $missingContent === 0;

        return [
            'campaign_id' => $cid,
            'name' => $camp['name'],
            'kind' => $camp['kind'],
            'status' => $camp['status'],
            'approved_by' => $camp['approved_by'] ?? null,
            'approved_at' => $camp['approved_at'] ?? null,
            'content_hash' => $hash,
            'recipient_counts' => $byStatus,
            'ready_for_admin_review' => $canReview,
            'blockers' => array_values(array_filter($issues, fn($i) => in_array($i['code'],
                ['missing_content', 'duplicate_email', 'content_hash_mismatch'], true))),
            'warnings' => array_values(array_filter($issues, fn($i) => !in_array($i['code'],
                ['missing_content', 'duplicate_email', 'content_hash_mismatch'], true))),
            'note' => 'Readiness only - approval and sending remain explicit admin actions in Marketing Outreach.',
        ];
    }

    private function recipientCount(string $campaignId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=?');
        $st->execute([$campaignId]);
        return (int)$st->fetchColumn();
    }
}
