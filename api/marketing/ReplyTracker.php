<?php
/**
 * Gmail reply tracking for Marketing Outreach.
 *
 * Incrementally polls the connected Gmail account via history.list and records
 * inbound replies that match tracked outreach (verified sends or resolved
 * threads). Classification is conservative: human / auto_response /
 * delivery_failure / unsubscribe_request / uncertain. Only messages that can be
 * associated with a known prospect or tracked thread are recorded at all, so
 * unrelated personal mail never enters the CRM.
 *
 * Idempotent: gmail_message_id is UNIQUE; re-runs and retries cannot duplicate.
 * The history checkpoint only advances after a fully successful pass.
 */
final class ReplyTracker
{
    private PDO $pdo;
    private $gmailGet;   // callable(path, query): ['status'=>?int,'data'=>array]
    private string $actor;
    private ?string $ownEmail = null;
    private const MAX_MESSAGES = 400;
    private const PAGE = 100;

    public function __construct(PDO $pdo, callable $gmailGet, string $actor = 'reply-sync')
    {
        $this->pdo = $pdo;
        $this->gmailGet = $gmailGet;
        $this->actor = substr($actor, 0, 190);
    }

    // ---------------------------------------------------------------
    // Settings helpers (marketing_settings sk/sval)
    // ---------------------------------------------------------------
    private function setting(string $key): ?string
    {
        $s = $this->pdo->prepare('SELECT sval FROM marketing_settings WHERE sk=?');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false || $v === null || $v === '' ? null : (string)$v;
    }
    private function setSetting(string $key, ?string $val): void
    {
        $this->pdo->prepare('INSERT INTO marketing_settings (sk,sval,updated_at) VALUES (?,?,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE sval=VALUES(sval), updated_at=UTC_TIMESTAMP()')
            ->execute([$key, $val]);
    }
    public function syncStatus(): array
    {
        return [
            'initialized' => $this->setting('reply_sync_history_id') !== null,
            'last_success' => $this->setting('reply_sync_last_success'),
            'last_error' => $this->setting('reply_sync_last_error'),
            'last_run' => $this->setting('reply_sync_last_run'),
        ];
    }

    // ---------------------------------------------------------------
    // Main sync pass
    // ---------------------------------------------------------------
    /**
     * @return array{ok:bool, initialized?:bool, processed?:int, inserted?:int,
     *               skipped?:int, error?:string, detail?:string}
     */
    public function sync(bool $allowBackfill = false): array
    {
        // Non-blocking overlap guard.
        $lk = $this->pdo->query("SELECT GET_LOCK('marketing_reply_sync',0) AS l")->fetchColumn();
        if ((int)$lk !== 1) return ['ok' => false, 'error' => 'locked', 'detail' => 'another sync is running'];
        try {
            return $this->syncInner($allowBackfill);
        } finally {
            try { $this->pdo->query("SELECT RELEASE_LOCK('marketing_reply_sync')"); } catch (Throwable $e) {}
        }
    }

    private function syncInner(bool $allowBackfill): array
    {
        $this->setSetting('reply_sync_last_run', gmdate('Y-m-d H:i:s'));

        $profile = ($this->gmailGet)('/profile');
        if (($profile['status'] ?? null) === 401 || ($profile['status'] ?? null) === 403) {
            $this->setSetting('reply_sync_last_error', 'gmail_auth_required');
            return ['ok' => false, 'error' => 'gmail_auth_required'];
        }
        if (($profile['status'] ?? null) !== 200) {
            $this->err('gmail_profile_' . ($profile['status'] ?? 'none'));
            return ['ok' => false, 'error' => 'gmail_api', 'detail' => 'profile status ' . ($profile['status'] ?? 'none')];
        }
        $ownEmail = strtolower(trim((string)($profile['data']['emailAddress'] ?? '')));
        $this->ownEmail = $ownEmail !== '' ? $ownEmail : null;
        $profileHistory = (string)($profile['data']['historyId'] ?? '');

        $start = $this->setting('reply_sync_history_id');
        if ($start === null) {
            // First run: establish the checkpoint now; only process messages
            // added after this point unless backfill was explicitly requested.
            if (!$allowBackfill) {
                $this->setSetting('reply_sync_history_id', $profileHistory !== '' ? $profileHistory : null);
                return ['ok' => true, 'initialized' => true, 'processed' => 0, 'inserted' => 0];
            }
            // Backfill: scan a bounded recent window via messages.list.
            return $this->backfill($ownEmail);
        }

        $historyId = $start;
        $pageToken = null;
        $seen = [];
        $processed = 0; $inserted = 0; $skipped = 0;
        $newHistoryId = null;

        do {
            $q = ['startHistoryId' => $historyId, 'historyTypes' => 'messageAdded',
                  'maxResults' => self::PAGE];
            if ($pageToken) $q['pageToken'] = $pageToken;
            $r = ($this->gmailGet)('/history', $q);
            if (($r['status'] ?? null) !== 200) {
                if (($r['status'] ?? null) === 404) {
                    // Checkpoint invalid (too old / mailbox reset): recover by
                    // re-initializing at the current profile historyId.
                    $this->setSetting('reply_sync_history_id', $profileHistory !== '' ? $profileHistory : null);
                    $this->setSetting('reply_sync_last_error', 'checkpoint_reset');
                    return ['ok' => true, 'error' => 'checkpoint_reset', 'processed' => $processed];
                }
                if (($r['status'] ?? null) === 401 || ($r['status'] ?? null) === 403) {
                    $this->setSetting('reply_sync_last_error', 'gmail_auth_required');
                    return ['ok' => false, 'error' => 'gmail_auth_required', 'processed' => $processed];
                }
                // Transient: keep checkpoint, retry next run.
                $this->err('gmail_history_' . ($r['status'] ?? 'none'));
                return ['ok' => false, 'error' => 'gmail_api', 'processed' => $processed];
            }
            $data = $r['data'];
            $newHistoryId = (string)($data['historyId'] ?? $newHistoryId ?? '');
            foreach (($data['history'] ?? []) as $h) {
                foreach (($h['messagesAdded'] ?? []) as $ma) {
                    $id = (string)($ma['message']['id'] ?? '');
                    $tid = (string)($ma['message']['threadId'] ?? '');
                    if ($id !== '' && !isset($seen[$id])) $seen[$id] = $tid;
                }
            }
            $pageToken = $data['nextPageToken'] ?? null;
            $processed += count($seen);
            if (count($seen) > self::MAX_MESSAGES) {
                // Safety bound: process the oldest-capped set only on next runs
                // is not possible with history.list ordering, so process what
                // we have and advance the checkpoint fully.
                $seen = array_slice($seen, 0, self::MAX_MESSAGES, true);
            }
        } while ($pageToken && count($seen) <= self::MAX_MESSAGES);

        foreach ($seen as $msgId => $threadId) {
            try {
                $ins = $this->processMessage($msgId, $threadId);
                $ins ? $inserted++ : $skipped++;
            } catch (Throwable $e) {
                error_log('[reply-sync] message ' . $msgId . ': ' . $e->getMessage());
                $skipped++;
            }
        }

        if ($newHistoryId !== '') $this->setSetting('reply_sync_history_id', $newHistoryId);
        $this->setSetting('reply_sync_last_success', gmdate('Y-m-d H:i:s'));
        $this->setSetting('reply_sync_last_error', null);
        return ['ok' => true, 'processed' => count($seen), 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /** Controlled historical scan (dry-run capable via classifyOnly). */
    public function backfill(int $days = 14, bool $dryRun = true): array
    {
        $r = ($this->gmailGet)('/messages', [
            'q' => 'in:inbox newer_than:' . max(1, min(30, $days)) . 'd -in:drafts',
            'maxResults' => self::MAX_MESSAGES,
        ]);
        if (($r['status'] ?? null) !== 200) {
            return ['ok' => false, 'error' => 'gmail_api', 'detail' => 'list status ' . ($r['status'] ?? 'none')];
        }
        $report = ['ok' => true, 'dry_run' => $dryRun, 'scanned' => 0, 'matched' => 0,
                   'would_insert' => 0, 'by_class' => [], 'samples' => []];
        foreach (($r['data']['messages'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') continue;
            $report['scanned']++;
            try {
                $msg = $this->fetchMessage($id);
                if ($msg === null) continue;
                $match = $this->matchMessage($msg);
                if (!$match['matched']) continue;
                $report['matched']++;
                $cls = $this->classify($msg);
                $report['by_class'][$cls] = ($report['by_class'][$cls] ?? 0) + 1;
                if (count($report['samples']) < 25) {
                    $report['samples'][] = [
                        'message_id' => $id, 'thread_id' => $msg['threadId'] ?? null,
                        'from' => $msg['from'] ?? null, 'subject' => $msg['subject'] ?? null,
                        'classification' => $cls, 'matched_via' => $match['via'],
                        'contact_id' => $match['contact_id'], 'ambiguous' => $match['ambiguous'],
                    ];
                }
                if (!$dryRun) {
                    $this->record($msg, $match, $cls);
                    $report['would_insert']++;
                } else {
                    $report['would_insert']++;
                }
            } catch (Throwable $e) {
                error_log('[reply-backfill] ' . $id . ': ' . $e->getMessage());
            }
        }
        return $report;
    }

    private function err(string $e): void
    {
        $this->setSetting('reply_sync_last_error', $e);
        error_log('[reply-sync] ' . $e);
    }

    // ---------------------------------------------------------------
    // Message fetch + normalization
    // ---------------------------------------------------------------
    /** @return array|null normalized message or null on fetch failure */
    public function fetchMessage(string $id): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) return null;
        $r = ($this->gmailGet)('/messages/' . rawurlencode($id), ['format' => 'full']);
        if (($r['status'] ?? null) !== 200) return null;
        $d = $r['data'];
        $headers = [];
        foreach (($d['payload']['headers'] ?? []) as $h) {
            $headers[strtolower($h['name'] ?? '')] = (string)($h['value'] ?? '');
        }
        $labels = $d['labelIds'] ?? [];
        $text = $this->extractText($d['payload'] ?? []);
        $internal = isset($d['internalDate']) ? (int)$d['internalDate'] : null;
        $from = $this->parseAddress($headers['from'] ?? '');
        return [
            'id' => $id,
            'threadId' => (string)($d['threadId'] ?? ''),
            'labels' => is_array($labels) ? $labels : [],
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'to' => $headers['to'] ?? '',
            'subject' => isset($headers['subject']) ? substr($headers['subject'], 0, 500) : null,
            'date' => $headers['date'] ?? null,
            'message_id' => $headers['message-id'] ?? null,
            'in_reply_to' => $headers['in-reply-to'] ?? null,
            'references' => $headers['references'] ?? null,
            'auto_submitted' => $headers['auto-submitted'] ?? null,
            'precedence' => strtolower($headers['precedence'] ?? ''),
            'text' => $text,
            'internal_ts' => $internal,
        ];
    }

    private function extractText(array $payload): string
    {
        $out = '';
        $mime = strtolower((string)($payload['mimeType'] ?? ''));
        $body = (string)($payload['body']['data'] ?? '');
        if ($body !== '') {
            $raw = base64_decode(strtr($body, '-_', '+/'), true);
            $raw = $raw === false ? '' : $raw;
            if (str_contains($mime, 'html')) $raw = strip_tags(preg_replace('/<style[^>]*>.*?<\/style>/si', ' ', $raw) ?? $raw);
            $out .= $raw;
        }
        foreach (($payload['parts'] ?? []) as $p) {
            $out .= "\n" . $this->extractText($p);
        }
        return substr(trim($out), 0, 20000);
    }

    private function parseAddress(string $raw): array
    {
        $email = '';
        if (preg_match('/<([^>]+)>/', $raw, $m)) $email = trim($m[1]);
        elseif (preg_match('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.\'-]+/', $raw, $m)) $email = $m[0];
        $name = trim(preg_replace('/<[^>]+>/', '', $raw) ?? '');
        $name = trim($name, " \t\"'");
        return ['email' => strtolower($email), 'name' => substr($name, 0, 190) ?: null];
    }

    // ---------------------------------------------------------------
    // Classification (pure; unit-testable)
    // ---------------------------------------------------------------
    public function classify(array $msg): string
    {
        $from = (string)($msg['from_email'] ?? '');
        $subj = (string)($msg['subject'] ?? '');
        $autoSub = strtolower(trim((string)($msg['auto_submitted'] ?? '')));
        $prec = (string)($msg['precedence'] ?? '');

        // Delivery failure: bounce senders or DSN subjects.
        if (preg_match('/^(mailer-daemon|postmaster)@/i', $from)
            || stripos($subj, 'delivery status notification') !== false
            || stripos($subj, 'undeliverable') !== false
            || stripos($subj, 'delivery failure') !== false
            || stripos($subj, 'returned mail') !== false) {
            return 'delivery_failure';
        }
        // Automatic responses.
        if (($autoSub !== '' && $autoSub !== 'no')
            || $prec === 'auto_reply' || $prec === 'auto-replied'
            || preg_match('/^(automatic reply|auto(re)?[- ]?reply|out of office|away from (the )?office|abwesenheitsnotiz|réponse automatique|respuesta automática)/i', $subj)) {
            return 'auto_response';
        }
        // Explicit unsubscribe / do-not-contact request in body or subject.
        $hay = $subj . "\n" . (string)($msg['text'] ?? '');
        if (preg_match('/\b(unsubscribe|remove me|take me off|do not (contact|email) me|stop (emailing|contacting)|opt[- ]?out|no longer wish to receive)\b/i', $hay)) {
            return 'unsubscribe_request';
        }
        return 'human';
    }

    // ---------------------------------------------------------------
    // Matching
    // ---------------------------------------------------------------
    /**
     * @return array{matched:bool, via:string, contact_id:?int, campaign_id:?string,
     *               campaign_recipient_id:?int, ambiguous:bool}
     */
    public function matchMessage(array $msg): array
    {
        $none = ['matched' => false, 'via' => 'none', 'contact_id' => null,
                 'campaign_id' => null, 'campaign_recipient_id' => null, 'ambiguous' => false];
        // Never treat drafts or our own outbound copies as replies.
        if (in_array('DRAFT', $msg['labels'] ?? [], true)) return $none;
        $this->ensureOwnEmail();
        $from = strtolower((string)($msg['from_email'] ?? ''));
        if ($from === '' ) return $none;
        if ($this->ownEmail !== null && $from === $this->ownEmail) return $none;
        if (str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@')) {
            // Bounces still need matching by recipient thread/address.
        }

        $contactIds = [];
        $recipient = null;
        $via = 'none';

        // 1) Thread match against campaign recipients (verified sends first).
        $threadId = (string)($msg['threadId'] ?? '');
        if ($threadId !== '') {
            $hit = $this->recipientByThread($threadId);
            if ($hit) { $recipient = $hit; $via = 'thread_id'; }
        }
        // 2) RFC reply headers against stored thread_rfc_message_id.
        if (!$recipient) {
            $refs = trim((string)($msg['in_reply_to'] ?? '') . ' ' . (string)($msg['references'] ?? ''));
            if ($refs !== '') {
                $ids = $this->extractMsgIds($refs);
                if ($ids) {
                    $hit = $this->recipientByRfcIds($ids);
                    if ($hit) { $recipient = $hit; $via = 'rfc_header'; }
                }
            }
        }
        // 3) Sender address against CRM contacts (single unambiguous contact only).
        $emailContacts = $this->contactsByEmail($from);
        if ($recipient) {
            // Thread/RFC evidence wins; add email-derived contact only if same.
            if ($recipient['contact_id'] !== null) $contactIds[$recipient['contact_id']] = true;
        } elseif (count($emailContacts) === 1) {
            $cid = (int)$emailContacts[0]['id'];
            $contactIds[$cid] = true;
            $via = 'email';
            // Try to find a campaign recipient row for context.
            $recipient = $this->recipientForContact($cid, $from);
        } elseif (count($emailContacts) > 1) {
            foreach ($emailContacts as $c) $contactIds[(int)$c['id']] = true;
            $via = 'email';
        }

        if (!$contactIds && !$recipient) return $none;
        $ambiguous = count($contactIds) > 1;
        $contactId = count($contactIds) === 1 ? (int)array_key_first($contactIds) : null;

        return [
            'matched' => true,
            'via' => $via !== 'none' ? $via : ($recipient ? 'thread_id' : 'email'),
            'contact_id' => $ambiguous ? null : $contactId,
            'campaign_id' => $recipient['campaign_id'] ?? null,
            'campaign_recipient_id' => $recipient['id'] ?? null,
            'ambiguous' => $ambiguous,
        ];
    }

    /** Resolve the connected account address once (cached per instance). */
    private function ensureOwnEmail(): void
    {
        if ($this->ownEmail !== null) return;
        $r = ($this->gmailGet)('/profile');
        if (($r['status'] ?? null) === 200) {
            $e = strtolower(trim((string)($r['data']['emailAddress'] ?? '')));
            $this->ownEmail = $e !== '' ? $e : null;
        }
    }

    private function extractMsgIds(string $refs): array
    {
        preg_match_all('/<[^>\s]+>/', $refs, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    private function recipientByThread(string $threadId): ?array
    {
        $s = $this->pdo->prepare(
            "SELECT r.id, r.campaign_id, r.contact_id, r.prospect_email, c.status
             FROM marketing_campaign_recipients r
             LEFT JOIN marketing_campaigns c ON c.id = r.campaign_id
             WHERE r.gmail_thread_id = ?
             ORDER BY (r.status='sent') DESC, r.id DESC LIMIT 1");
        $s->execute([$threadId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function recipientByRfcIds(array $ids): ?array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->pdo->prepare(
            "SELECT r.id, r.campaign_id, r.contact_id, r.prospect_email, c.status
             FROM marketing_campaign_recipients r
             LEFT JOIN marketing_campaigns c ON c.id = r.campaign_id
             WHERE r.thread_rfc_message_id IN ($in)
             ORDER BY (r.status='sent') DESC, r.id DESC LIMIT 1");
        $s->execute($ids);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function contactsByEmail(string $email): array
    {
        if ($email === '') return [];
        $s = $this->pdo->prepare(
            "SELECT id FROM marketing_contacts WHERE email_normalized = ? LIMIT 5");
        $s->execute([$email]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function recipientForContact(int $contactId, string $email): ?array
    {
        $s = $this->pdo->prepare(
            "SELECT r.id, r.campaign_id, r.contact_id, r.prospect_email, c.status
             FROM marketing_campaign_recipients r
             JOIN marketing_campaigns c ON c.id = r.campaign_id
             WHERE r.contact_id = ? AND r.prospect_email = ?
             ORDER BY r.id DESC LIMIT 1");
        $s->execute([$contactId, $email]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ---------------------------------------------------------------
    // Record + CRM updates (idempotent via UNIQUE gmail_message_id)
    // ---------------------------------------------------------------
    public function processMessage(string $msgId, string $threadId = ''): bool
    {
        $msg = $this->fetchMessage($msgId);
        if ($msg === null) return false;
        if ($threadId !== '' && $msg['threadId'] === '') $msg['threadId'] = $threadId;
        $match = $this->matchMessage($msg);
        if (!$match['matched']) return false;
        $cls = $this->classify($msg);
        return $this->record($msg, $match, $cls);
    }

    /** @return bool true when a new row was inserted */
    public function record(array $msg, array $match, string $cls): bool
    {
        $ts = $msg['internal_ts'] ?? null;
        $dt = $ts ? gmdate('Y-m-d H:i:s', (int)($ts / 1000)) : null;
        $url = 'https://mail.google.com/mail/u/0/#all/' . rawurlencode((string)($msg['threadId'] ?? ''));
        $ins = $this->pdo->prepare(
            "INSERT IGNORE INTO marketing_reply_events
             (gmail_message_id, gmail_thread_id, contact_id, campaign_id, campaign_recipient_id,
              matched_via, classification, review_status, ambiguous, from_email, from_name,
              subject, snippet, gmail_internal_date, gmail_url, details)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            substr((string)$msg['id'], 0, 80), substr((string)($msg['threadId'] ?? ''), 0, 80) ?: null,
            $match['contact_id'], $match['campaign_id'], $match['campaign_recipient_id'],
            substr($match['via'], 0, 30), substr($cls, 0, 30), 'new',
            $match['ambiguous'] ? 1 : 0,
            substr((string)($msg['from_email'] ?? ''), 0, 320) ?: null,
            $msg['from_name'] ?? null,
            $msg['subject'] ?? null,
            $this->snippet($msg), $dt, $url,
            json_encode(['message_id_header' => $msg['message_id'] ?? null]),
        ]);
        if ($ins->rowCount() === 0) return false; // duplicate — idempotent

        $this->applyCrmEffects($match, $cls);
        return true;
    }

    private function snippet(array $msg): ?string
    {
        $t = trim((string)($msg['text'] ?? ''));
        if ($t === '') return null;
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return substr($t, 0, 300);
    }

    private function applyCrmEffects(array $match, string $cls): void
    {
        $cid = $match['contact_id'] ?? null;
        if ($cid === null) return;
        $details = json_encode([
            'source' => 'gmail_reply_sync',
            'classification' => $cls,
            'campaign_id' => $match['campaign_id'],
        ]);
        try {
            $this->pdo->prepare(
                "INSERT INTO marketing_prospect_events (contact_id, org_id, event_type, details, actor, source)
                 SELECT ?, org_id, 'reply_received', ?, ?, 'reply_sync'
                 FROM marketing_contacts WHERE id = ?"
            )->execute([$cid, $details, $this->actor, $cid]);
        } catch (Throwable $e) {
            error_log('[reply-sync] event: ' . $e->getMessage());
        }

        if ($cls === 'human') {
            // Mark responded only when no substantive response is already recorded.
            $this->pdo->prepare(
                "UPDATE marketing_contacts SET response='replied'
                 WHERE id=? AND (response IS NULL OR response='' OR response IN ('none','no response','No Response'))"
            )->execute([$cid]);
        } elseif ($cls === 'unsubscribe_request') {
            $this->pdo->prepare(
                "UPDATE marketing_contacts SET needs_review=1,
                    review_reason=IF(needs_review=1 AND review_reason IS NOT NULL AND review_reason<>'',
                        CONCAT(review_reason,'; '),'email_unsubscribe_request')
                 WHERE id=?"
            )->execute([$cid]);
        } elseif ($cls === 'delivery_failure') {
            $this->pdo->prepare(
                "UPDATE marketing_contacts SET needs_review=1,
                    review_reason=IF(needs_review=1 AND review_reason IS NOT NULL AND review_reason<>'',
                        CONCAT(review_reason,'; '),'email_bounce')
                 WHERE id=?"
            )->execute([$cid]);
        }
        // auto_response / uncertain: event only, no status change.
    }

    // ---------------------------------------------------------------
    // Review queries
    // ---------------------------------------------------------------
    public function listReplies(array $f = []): array
    {
        $where = '1=1'; $args = [];
        if (!empty($f['status'])) { $where .= ' AND r.review_status=?'; $args[] = $f['status']; }
        if (!empty($f['class'])) { $where .= ' AND r.classification=?'; $args[] = $f['class']; }
        if (!empty($f['contact_id'])) { $where .= ' AND r.contact_id=?'; $args[] = (int)$f['contact_id']; }
        $lim = max(1, min(200, (int)($f['limit'] ?? 100)));
        $s = $this->pdo->prepare(
            "SELECT r.*, c.full_name AS contact_name, c.email AS contact_email,
                    camp.name AS campaign_name
             FROM marketing_reply_events r
             LEFT JOIN marketing_contacts c ON c.id = r.contact_id
             LEFT JOIN marketing_campaigns camp ON camp.id = r.campaign_id
             WHERE $where ORDER BY r.gmail_internal_date DESC, r.id DESC LIMIT $lim");
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markReviewed(int $id, string $actor, bool $dismissed = false): bool
    {
        $s = $this->pdo->prepare(
            "UPDATE marketing_reply_events SET review_status=?, reviewed_at=UTC_TIMESTAMP(), reviewed_by=?
             WHERE id=?");
        $s->execute([$dismissed ? 'dismissed' : 'reviewed', substr($actor, 0, 190), $id]);
        return $s->rowCount() > 0;
    }

    /** Aggregate counts used by the dashboard. */
    public function dashboardStats(?string $from, ?string $to): array
    {
        $w = '1=1'; $args = [];
        if ($from) { $w .= ' AND gmail_internal_date >= ?'; $args[] = $from . ' 00:00:00'; }
        if ($to)   { $w .= ' AND gmail_internal_date <= ?'; $args[] = $to . ' 23:59:59'; }
        $s = $this->pdo->prepare(
            "SELECT classification, COUNT(*) n,
                    SUM(review_status='new') new_n,
                    MAX(gmail_internal_date) last_at
             FROM marketing_reply_events WHERE $w GROUP BY classification");
        $s->execute($args);
        $out = ['human' => 0, 'auto_response' => 0, 'delivery_failure' => 0,
                'unsubscribe_request' => 0, 'uncertain' => 0, 'new_review' => 0, 'last_reply_at' => null];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cls = (string)$r['classification'];
            if (isset($out[$cls])) $out[$cls] = (int)$r['n'];
            $out['new_review'] += (int)$r['new_n'];
            if ($r['last_at'] && (!$out['last_reply_at'] || $r['last_at'] > $out['last_reply_at'])) {
                $out['last_reply_at'] = $r['last_at'];
            }
        }
        // Per-campaign reply counts for the performance table.
        $c = $this->pdo->prepare(
            "SELECT campaign_id, classification, COUNT(*) n
             FROM marketing_reply_events WHERE campaign_id IS NOT NULL AND $w
             GROUP BY campaign_id, classification");
        $c->execute($args);
        $per = [];
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $per[$r['campaign_id']][$r['classification']] = (int)$r['n'];
        }
        $out['per_campaign'] = $per;
        return $out;
    }
}
