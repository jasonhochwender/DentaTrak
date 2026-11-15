<?php
/**
 * Campaign message generation - shared by the admin endpoint (bounded
 * synchronous batches / single-recipient regen) and the send worker
 * (durable background drain via marketing_campaigns.gen_requested).
 *
 * Safety properties:
 * - Default scope 'missing' only touches status='draft' rows, so bulk
 *   generation can never regenerate or overwrite existing content.
 * - 'replace_unedited' additionally covers status='ready' rows with
 *   is_edited=0 - an explicit admin choice, still never touching
 *   manually edited, approved, sending, sent, suppressed, or uncertain
 *   recipients.
 * - Per-recipient failures are durable (gen_attempts/gen_error) so a
 *   bad prospect or AI outage cannot loop forever and admins can see
 *   and retry exactly what failed.
 * - Generation never approves, authorizes, or sends anything.
 */
require_once __DIR__ . '/../ai-client.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/ProspectDirectory.php';
require_once __DIR__ . '/ProspectCrm.php';
require_once __DIR__ . '/MarketingMailer.php';
require_once __DIR__ . '/OutreachEligibility.php';
require_once __DIR__ . '/OutreachThreadResolver.php';
require_once __DIR__ . '/MessageHtml.php';
require_once __DIR__ . '/CampaignSendSupport.php';

class CampaignGenerator
{
    /** Stop auto-retrying a recipient after this many failed attempts. */
    const MAX_ATTEMPTS = 3;
    const SCOPES = ['missing', 'replace_unedited', 'retry_failed'];

    private PDO $pdo;
    private array $appConfig;
    private string $baseUrl;
    /** @var callable|null test seam forwarded to MarketingMailer */
    private $http;
    private ?MarketingMailer $threadMailer = null;
    private bool $threadMailerMade = false;
    private ?array $sheetProspects = null;
    private bool $sheetLoaded = false;
    private ?string $sourceError = null;
    private array $byEmail = [];

    /**
     * @param array $opts http?: callable - test seam for Gmail/AI-free
     *                    paths; env?: callable for feature flags.
     */
    public function __construct(PDO $pdo, array $appConfig, string $baseUrl, array $opts = [])
    {
        $this->pdo = $pdo;
        $this->appConfig = $appConfig;
        $this->baseUrl = $baseUrl;
        $this->http = $opts['http'] ?? null;
    }

    // ------------------------------------------------------------------
    // Scope / queue state
    // ------------------------------------------------------------------

    /** SQL predicate (on alias r) for recipients a scope may generate. */
    private function scopeSql(string $scope): string
    {
        $attempts = self::MAX_ATTEMPTS;
        return match ($scope) {
            'replace_unedited' => "r.status IN ('draft','ready') AND r.is_edited=0 AND r.gen_attempts < $attempts",
            // 'missing' fills only untouched drafts - a manually edited
            // row is never auto-overwritten even if its status is draft.
            default            => "r.status='draft' AND r.is_edited=0 AND r.gen_attempts < $attempts",
        };
    }

    /** Actionable (still generatable) recipient count for a scope. */
    public function actionableCount(string $campaignId, string $scope = 'missing'): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=? AND ' . $this->scopeSql($scope));
        $st->execute([$campaignId]);
        return (int)$st->fetchColumn();
    }

    /** Recipients that exhausted attempts and still have no content. */
    public function failedCount(string $campaignId): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM marketing_campaign_recipients
             WHERE campaign_id=? AND status='draft' AND gen_error IS NOT NULL AND gen_attempts >= " . self::MAX_ATTEMPTS);
        $st->execute([$campaignId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Queue background generation for a campaign. Idempotent: repeated
     * calls just re-affirm the request. Returns queue stats.
     */
    public function request(string $campaignId, string $scope = 'missing', ?string $actor = null): array
    {
        $camp = $this->campaign($campaignId);
        if (!$camp) return ['success' => false, 'error' => 'not found'];
        if (!in_array($camp['status'], ['draft', 'ready'], true)) {
            return ['success' => false, 'error' => 'campaign not editable'];
        }
        if (!in_array($scope, self::SCOPES, true)) {
            return ['success' => false, 'error' => 'unknown scope'];
        }
        if ($scope === 'retry_failed') {
            // Give permanently-failed drafts a fresh set of attempts.
            $this->pdo->prepare(
                "UPDATE marketing_campaign_recipients SET gen_attempts=0, gen_error=NULL
                 WHERE campaign_id=? AND status='draft' AND gen_error IS NOT NULL")
                ->execute([$campaignId]);
            $scope = 'missing';
        }
        $this->pdo->prepare('UPDATE marketing_campaigns SET gen_requested=1, gen_scope=? WHERE id=?')
            ->execute([$scope, $campaignId]);
        if ($actor !== null) {
            $this->audit($actor, 'campaign_generate_requested', $campaignId,
                ['scope' => $scope, 'pending' => $this->actionableCount($campaignId, $scope)]);
        }
        return [
            'success' => true,
            'requested' => true,
            'scope' => $scope,
            'pending' => $this->actionableCount($campaignId, $scope),
        ];
    }

    /** Cancel a queued/running generation request. */
    public function cancel(string $campaignId, ?string $actor = null): void
    {
        $this->pdo->prepare('UPDATE marketing_campaigns SET gen_requested=0 WHERE id=?')->execute([$campaignId]);
        if ($actor !== null) $this->audit($actor, 'campaign_generate_cancelled', $campaignId, null);
    }

    public function isRequested(string $campaignId): bool
    {
        $st = $this->pdo->prepare('SELECT gen_requested FROM marketing_campaigns WHERE id=?');
        $st->execute([$campaignId]);
        return (int)$st->fetchColumn() === 1;
    }

    // ------------------------------------------------------------------
    // Batch generation
    // ------------------------------------------------------------------

    /**
     * Generate up to $limit recipients for one campaign.
     *
     * @param array $opts recipient_ids?: int[] - restrict to these rows
     *                    (per-recipient regen; force bypasses is_edited)
     *                    force?: bool, scope?: string
     * @return array stats: processed, generated, skipped_edited, failed
     *               [{email,reason}], remaining (actionable after batch),
     *               exhausted (failedCount), source_error
     */
    public function runBatch(string $campaignId, int $limit, array $opts = []): array
    {
        $camp = $this->campaign($campaignId);
        if (!$camp) return ['error' => 'not found'];
        if (!in_array($camp['status'], ['draft', 'ready'], true)) {
            return ['error' => 'campaign not editable'];
        }
        $limit = max(1, min(200, $limit));
        $force = !empty($opts['force']);
        $ridFilter = array_values(array_unique(array_map('intval', (array)($opts['recipient_ids'] ?? []))));
        $scope = (string)($opts['scope'] ?? ($camp['gen_scope'] ?? 'missing'));
        if (!in_array($scope, self::SCOPES, true) || $scope === 'retry_failed') $scope = 'missing';

        $where = 'r.campaign_id = ? AND ' . $this->scopeSql($scope);
        $args = [$campaignId];
        if ($ridFilter) {
            // Explicit per-recipient regen: honor the same edit guard.
            $ph = implode(',', array_fill(0, count($ridFilter), '?'));
            $where = "r.campaign_id = ? AND r.id IN ($ph) AND r.status IN ('draft','ready')";
            $args = array_merge([$campaignId], $ridFilter);
            $limit = count($ridFilter);
        }
        $st = $this->pdo->prepare(
            "SELECT * FROM marketing_campaign_recipients r WHERE $where ORDER BY r.id LIMIT $limit");
        $st->execute($args);
        $recips = $st->fetchAll(PDO::FETCH_ASSOC);

        $tpl = $this->templateFor($camp);
        $src = $this->prospectSource($recips);
        $stats = ['processed' => 0, 'generated' => 0, 'skipped_edited' => 0,
                  'failed' => [], 'remaining' => 0, 'exhausted' => 0];
        if ($src['error'] !== null) {
            $stats['source_error'] = $src['error'];
            $stats['remaining'] = $this->actionableCount($campaignId, $scope);
            return $stats;
        }

        foreach ($recips as $r) {
            $stats['processed']++;
            $rid = (int)$r['id'];
            try {
                $p = $this->resolveProspect($r, $src);
                if ($p === null) {
                    $this->recordFailure($rid, 'prospect_not_found');
                    $stats['failed'][] = ['email' => $r['prospect_email'], 'reason' => 'prospect_not_found'];
                    continue;
                }
                $prior = '';
                if ($camp['kind'] === 'followup') {
                    $prior = $this->priorOutreach($r['prospect_email']);
                }
                $gen = $this->generateOne($p, (string)$camp['kind'],
                    $tpl['body_tpl'] ?? null, $prior);
                if ($camp['kind'] === 'followup') {
                    // Thread resolution failure must not fail the message
                    // itself - it is retried by Re-check conversations.
                    try {
                        $this->resolveThread($r, (string)($p['fields']['notes'] ?? ''), $campaignId);
                    } catch (Throwable $e) {
                        error_log('[campaign-generator] thread resolve: ' . $e->getMessage());
                    }
                }
                if (!$force && (int)$r['is_edited'] === 1) {
                    $stats['skipped_edited']++;
                    continue;
                }
                $this->pdo->prepare(
                    "UPDATE marketing_campaign_recipients
                     SET subject=?, body_html=?, status='ready', gen_error=NULL, gen_attempts=0
                     WHERE id=?")
                    ->execute([$gen['subject'], $gen['body_html'], $rid]);
                $stats['generated']++;
            } catch (Throwable $e) {
                error_log('[campaign-generator] recipient ' . $rid . ': ' . $e->getMessage());
                $this->recordFailure($rid, 'generation_error');
                $stats['failed'][] = ['email' => $r['prospect_email'], 'reason' => 'generation_error'];
            }
        }

        // Campaign flips draft->ready once no draft rows remain at all.
        $drafts = (int)$this->pdo->query(
            'SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id='
            . $this->pdo->quote($campaignId) . " AND status='draft'")->fetchColumn();
        if ($drafts === 0) {
            $this->pdo->prepare("UPDATE marketing_campaigns SET status='ready' WHERE id=? AND status='draft'")
                ->execute([$campaignId]);
        }
        $stats['remaining'] = $this->actionableCount($campaignId, $scope);
        $stats['exhausted'] = $this->failedCount($campaignId);
        return $stats;
    }

    /**
     * Worker entry point: drain one campaign until nothing actionable
     * remains or the time budget is exhausted. Returns cumulative stats.
     */
    public function drain(string $campaignId, int $budgetSec, int $batchSize, ?string $actor = null): array
    {
        $deadline = time() + max(10, $budgetSec);
        $totals = ['processed' => 0, 'generated' => 0, 'skipped_edited' => 0,
                   'failed' => 0, 'remaining' => 0, 'batches' => 0];
        $failEmails = [];
        while (time() < $deadline) {
            // Re-check the request flag before every batch: a cancelled
            // or completed request must not start another batch.
            if (!$this->isRequested($campaignId)) break;
            $camp = $this->campaign($campaignId);
            if (!$camp || !in_array($camp['status'], ['draft', 'ready'], true)) break;
            $scope = (string)($camp['gen_scope'] ?? 'missing');
            if ($this->actionableCount($campaignId, $scope) === 0) break;
            $r = $this->runBatch($campaignId, $batchSize, ['scope' => $scope]);
            if (isset($r['error'])) break;
            $totals['batches']++;
            $totals['processed'] += $r['processed'];
            $totals['generated'] += $r['generated'];
            $totals['skipped_edited'] += $r['skipped_edited'];
            $totals['failed'] += count($r['failed']);
            foreach (array_slice($r['failed'], 0, 50) as $f) $failEmails[] = $f;
            $totals['remaining'] = $r['remaining'];
            if ($r['processed'] === 0) break; // nothing actionable right now
        }
        $done = $totals['remaining'] === 0;
        if ($done || !$this->isRequested($campaignId)) {
            // Request fulfilled (or campaign left editable state): release.
            $this->pdo->prepare('UPDATE marketing_campaigns SET gen_requested=0 WHERE id=?')
                ->execute([$campaignId]);
        }
        if ($actor !== null) {
            $this->audit($actor, 'campaign_generate_worker', $campaignId,
                $totals + ['failed_emails' => array_slice($failEmails, 0, 50)]);
        }
        return $totals + ['done' => $done, 'failed_details' => $failEmails];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function campaign(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM marketing_campaigns WHERE id=?');
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        return $c ?: null;
    }

    private function audit(string $actor, string $action, string $cid, $detail): void
    {
        $this->pdo->prepare('INSERT INTO marketing_audit_log (actor_email,action,campaign_id,detail) VALUES (?,?,?,?)')
            ->execute([$actor, $action, $cid, $detail === null ? null : json_encode($detail)]);
    }

    private function recordFailure(int $recipientId, string $reason): void
    {
        $this->pdo->prepare(
            'UPDATE marketing_campaign_recipients
             SET gen_attempts = gen_attempts + 1, gen_error = ?
             WHERE id=?')
            ->execute([substr($reason, 0, 255), $recipientId]);
    }

    private function templateFor(array $camp): ?array
    {
        if (!empty($camp['template_id'])) {
            $st = $this->pdo->prepare('SELECT * FROM marketing_templates WHERE id=? AND kind=?');
            $st->execute([(int)$camp['template_id'], $camp['kind']]);
            $tpl = $st->fetch(PDO::FETCH_ASSOC);
            if ($tpl) return $tpl;
        }
        $st = $this->pdo->prepare('SELECT * FROM marketing_templates WHERE kind=? ORDER BY is_default DESC, id LIMIT 1');
        $st->execute([$camp['kind']]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Ensure the prospect source (CRM or sheet) is loaded once per
     * generator instance, then build the per-batch email map. Returns
     * ['crm'=>?ProspectCrm,'byEmail'=>map,'error'=>?string].
     */
    private function prospectSource(array $recips): array
    {
        // ProspectCrm::context() memoizes the whole contact set per
        // instance, so a fresh instance per batch keeps long drains
        // correct when contacts change mid-run. The sheet directory is
        // expensive to reload and is cached for the drain instead.
        $crm = null;
        if (isFeatureEnabled('MARKETING_CRM_READS')) {
            $candidate = new ProspectCrm($this->pdo);
            if (!$candidate->available()) {
                $this->sourceError = 'crm_not_migrated';
            } else {
                $crm = $candidate;
            }
        } elseif (!$this->sheetLoaded) {
            $this->sheetLoaded = true;
            try {
                $dir = new ProspectDirectory($this->pdo);
                $this->sheetProspects = $dir->prospects();
            } catch (Throwable $e) {
                $code = $e instanceof \SheetSyncException ? $e->errorCode : 'sheet_unavailable';
                error_log('[campaign-generator] prospect load [' . $code . ']: ' . $e->getMessage());
                $this->sourceError = $code;
            }
        }
        $this->byEmail = [];
        if ($crm !== null) {
            $emails = array_map(static fn($r) => SuppressionService::normalizeEmail((string)$r['prospect_email']), $recips);
            $this->byEmail = $crm->findByEmails($emails);
        } elseif ($this->sheetProspects !== null) {
            foreach ($this->sheetProspects as $p) {
                $this->byEmail[SuppressionService::normalizeEmail((string)($p['fields']['email'] ?? ''))] = $p;
            }
        }
        return ['crm' => $crm, 'byEmail' => $this->byEmail, 'error' => $this->sourceError];
    }

    private function resolveProspect(array $r, array $src): ?array
    {
        if ($src['crm'] !== null && !empty($r['contact_id'])) {
            $p = $src['crm']->findById((int)$r['contact_id']);
            if ($p !== null) return $p;
        }
        return $src['byEmail'][SuppressionService::normalizeEmail((string)$r['prospect_email'])] ?? null;
    }

    private function priorOutreach(string $email): string
    {
        $st = $this->pdo->prepare(
            "SELECT subject, body_html FROM marketing_campaign_recipients
             WHERE prospect_email=? AND status='sent' ORDER BY sent_at DESC LIMIT 1");
        $st->execute([$email]);
        if ($pp = $st->fetch(PDO::FETCH_ASSOC)) {
            return "Subject: {$pp['subject']}\n\n" . trim(strip_tags((string)$pp['body_html']));
        }
        return '';
    }

    private function threadMailer(): MarketingMailer
    {
        if (!$this->threadMailerMade) {
            $opts = $this->http !== null ? ['http' => $this->http] : [];
            $this->threadMailer = new MarketingMailer($this->pdo, $this->baseUrl, $opts);
            $this->threadMailerMade = true;
        }
        return $this->threadMailer;
    }

    /** Persist the resolved Gmail thread for one follow-up recipient. */
    private function resolveThread(array $recipient, string $notes, string $campaignId): void
    {
        $resolver = new OutreachThreadResolver($this->pdo, [$this->threadMailer(), 'gmailGet']);
        $t = $resolver->resolve((string)$recipient['prospect_email'],
            OutreachEligibility::gmailIdsFromNotes($notes), mo_ref($campaignId));
        $ok = $t['status'] === 'resolved';
        $sql = 'UPDATE marketing_campaign_recipients SET thread_status=?, thread_reason=?, thread_source=?,
            thread_subject=?, thread_date=?, thread_rfc_message_id=?, thread_references=?, gmail_thread_id=?';
        $args = [$t['status'], $t['reason'] !== '' ? substr($t['reason'], 0, 80) : null,
            $ok ? $t['source'] : null, $ok ? substr($t['subject'], 0, 500) : null,
            $ok ? substr((string)($t['date'] ?? ''), 0, 40) : null,
            $ok ? $t['rfc_message_id'] : null, $ok ? $t['references'] : null,
            $ok ? $t['thread_id'] : null];
        if ($ok) { $sql .= ', subject=?'; $args[] = substr($t['subject'], 0, 500); }
        $args[] = $recipient['id'];
        $this->pdo->prepare($sql . ' WHERE id=?')->execute($args);
    }

    // ------------------------------------------------------------------
    // Copy generation (moved from marketing-admin.php; template fallback
    // keeps generation working with no AI key configured)
    // ------------------------------------------------------------------

    private function render(string $tpl, array $prospect): string
    {
        $f = $prospect['fields'] ?? [];
        $map = [
            '{first_name}'    => $prospect['contact_name'] !== '' ? explode(' ', trim($prospect['contact_name']))[0] : '',
            '{contact_name}'  => $prospect['contact_name'] ?? '',
            '{practice_name}' => $f['org'] ?? '',
            '{org_name}'      => $f['org'] ?? '',
            '{parent_org}'    => $f['parent_org'] ?? '',
            '{location}'      => $f['location'] ?? '',
            '{website}'       => $f['website'] ?? '',
            '{title}'         => $f['title'] ?? '',
        ];
        return strtr($tpl, $map);
    }

    private function sanitizeHtml(string $html): string
    {
        return MessageHtml::sanitize($html);
    }

    private function generateOne(array $prospect, string $kind, ?string $templateBody, string $priorContext): array
    {
        $f = $prospect['fields'] ?? [];
        $orgType = stripos($f['practice_type'] . ' ' . $f['parent_org'], 'dso') !== false ? 'DSO'
            : (trim($f['parent_org']) !== '' ? 'multi-location group' : 'individual dental practice');

        $provider = $this->appConfig['ai_provider'] ?? 'gemini';
        $aiConfig = $this->appConfig[$provider] ?? [];
        if (empty($aiConfig['api_key'])) {
            return $this->templateFallback($prospect, $kind, $templateBody);
        }

        $kindLabel = $kind === 'followup' ? 'follow-up' : 'initial outreach';
        $system = 'You are a concise, professional B2B copywriter for DentaTrak, dental lab case-tracking software. '
            . 'Write ' . $kindLabel . ' email copy only. No medical claims, no patient data, no hype. '
            . 'Return ONLY a JSON object: {"subject": string, "body_html": string}. '
            . 'body_html is a short HTML fragment (p and ul/li allowed, inline styles only, no scripts, no footer, no unsubscribe link, no images). '
            . '2-4 short paragraphs max. Include one clear low-pressure call to action (a reply or a 15-minute demo). '
            . 'Personalize using ONLY the prospect facts provided; never invent facts, locations, practice details, or operational problems. '
            . 'Tone: natural, professional, conversational - like a note from a knowledgeable colleague, not a sales pitch. '
            . 'Position DentaTrak as helping improve visibility, coordination, and efficiency for dental labs and practices; '
            . 'it complements practice-management software rather than replacing it. '
            . 'Do NOT claim DentaTrak eliminates manual work, guarantees on-time case delivery, or produces specific outcomes. '
            . 'Do NOT assume the recipient has a problem; acknowledge they may already use other software. '
            . ($kind === 'followup'
                ? 'This is a FOLLOW-UP: briefly reference the earlier note without pressure, add one small new reason to reply, '
                  . 'and keep it shorter than the initial email. Do not repeat the full pitch. '
                : 'This is INITIAL outreach: introduce DentaTrak briefly and ask for a reply or short demo. ');

        $facts = [
            'organization' => $f['org'] ?? '',
            'parent_or_group' => $f['parent_org'] ?? '',
            'org_type' => $orgType,
            'contact_name' => $prospect['contact_name'] ?? '',
            'title' => $f['title'] ?? '',
            'location' => $f['location'] ?? '',
            'website' => $f['website'] ?? '',
            'fit' => $f['fit'] ?? '',
            'priority' => $f['priority'] ?? '',
            'outreach_status' => $f['status'] ?? '',
            'prior_notes' => mb_substr((string)($f['notes'] ?? ''), 0, 1200),
        ];
        if ($kind === 'followup') {
            $facts['is_followup'] = true;
            $facts['prior_outreach_excerpt'] = mb_substr((string)$priorContext, 0, 1500);
        }
        if ($templateBody) $facts['template_guidance'] = mb_substr($templateBody, 0, 2000);

        $user = "Prospect facts (JSON):\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nWrite the " . ($kind === 'followup' ? 'follow-up' : 'initial outreach') . " email.";

        try {
            $raw = $provider === 'openai'
                ? callOpenAIAPI($aiConfig, $system, $user)
                : callGeminiAPI($aiConfig, $system, $user);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $clean = preg_replace('/^```(?:json)?|```$/m', '', trim($raw));
                $decoded = json_decode($clean, true);
            }
            $subject = trim((string)($decoded['subject'] ?? ''));
            $body = $this->sanitizeHtml((string)($decoded['body_html'] ?? ''));
            if ($subject !== '' && $body !== '') {
                return ['subject' => $subject, 'body_html' => $body, 'generated_by' => 'ai'];
            }
        } catch (Throwable $e) {
            error_log('[campaign-generator] AI generate failed: ' . $e->getMessage());
        }
        return $this->templateFallback($prospect, $kind, $templateBody);
    }

    private function templateFallback(array $prospect, string $kind, ?string $templateBody): array
    {
        $first = trim(explode(' ', (string)($prospect['contact_name'] ?? ''))[0] ?? '');
        $greeting = $first !== '' ? "Hi {$first}," : 'Hello,';
        if ($templateBody) {
            $body = $this->render($templateBody, $prospect);
        } else {
            $body = '<p>' . htmlspecialchars($greeting) . '</p>'
                . '<p>I hope this note finds you well. I\'m reaching out from DentaTrak — '
                . 'dental lab case-tracking software built for practices like '
                . htmlspecialchars((string)($prospect['fields']['org'] ?? 'yours')) . '.</p>'
                . '<p>Would you be open to a 15-minute walkthrough? I\'d be glad to show you '
                . 'how teams keep cases, labs, and due dates in one place.</p>'
                . '<p>Best regards,<br>The DentaTrak Team</p>';
        }
        $subject = $kind === 'followup'
            ? 'Following up: dental case tracking for ' . ($prospect['fields']['org'] ?? 'your practice')
            : 'A better way to track dental cases at ' . ($prospect['fields']['org'] ?? 'your practice');
        return ['subject' => $subject, 'body_html' => $body, 'generated_by' => 'template'];
    }
}
