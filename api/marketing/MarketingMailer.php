<?php
/**
 * Shared Gmail marketing sender.
 *
 * Single send path used by BOTH:
 *  - api/marketing-send.php   (token-authed endpoint for the MCP connector)
 *  - api/marketing-admin.php  (Platform Administration campaign sends)
 *
 * Guarantees:
 *  - Delivery control: MarketingSendingPolicy is checked before anything
 *    else; when sending is disabled or the recipient is not allowlisted no
 *    ledger row is written and no provider call is made.
 *  - Idempotency: every send has a durable key, sha256(campaign "\n"
 *    email), stored in marketing_sends.idempotency_key (UNIQUE alongside
 *    UNIQUE(campaign_ref,email_normalized)) and stamped on the message as
 *    X-DentaTrak-Send-Id. The ledger row is claimed BEFORE any provider
 *    call; every later transition is a compare-and-swap, so two concurrent
 *    requests can never both send.
 *  - Ledger states:
 *      running            claimed, provider call in flight
 *      sent               Gmail returned a message id (or reconciliation
 *                         found the message in Sent mail)
 *      failed             definitively NOT accepted (auth failure, 4xx);
 *                         safe to retry
 *      uncertain          outcome unknown (timeout, dropped connection,
 *                         5xx, 2xx without an id, or a stale 'running'
 *                         row from an interrupted process). NEVER resent
 *                         until reconciled.
 *      skipped_suppressed recipient was suppressed at send time
 *  - Reconciliation: before an uncertain row may be retried, Sent mail is
 *    searched for a message to that recipient carrying this send's
 *    X-DentaTrak-Send-Id. Found -> recorded as sent, no resend. Confirmed
 *    absent -> retry allowed. Lookup unavailable -> stays uncertain.
 *  - Suppression eligibility re-check immediately before send.
 *  - Recipient-specific unsubscribe token; branded MarketingFooter HTML;
 *    RFC 8058 List-Unsubscribe / List-Unsubscribe-Post headers.
 *  - Optional threading: Gmail threadId plus In-Reply-To / References.
 */
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/MarketingFooter.php';
require_once __DIR__ . '/MarketingSendingPolicy.php';

final class MarketingMailer
{
    /** A 'running' row older than this is an interrupted attempt. */
    public const STALE_RUNNING_SECONDS = 600;
    /** Minimum age before "not found in Sent mail" is trusted. */
    public const RECONCILE_MIN_AGE_SECONDS = 120;
    public const SEND_ID_HEADER = 'X-DentaTrak-Send-Id';

    private const GMAIL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private PDO $pdo;
    private SuppressionService $svc;
    private MarketingSendingPolicy $policy;
    private string $baseUrl;
    /** @var callable(string,string,array,?string,int): array{status:?int,body:string} */
    private $http;
    /** @var callable(): int */
    private $clock;
    /** @var callable(string): ?string */
    private $env;
    private ?string $token = null;

    /**
     * @param array $opts policy?: MarketingSendingPolicy, http?: callable,
     *                    clock?: callable, env?: callable (test seams)
     */
    public function __construct(PDO $pdo, string $baseUrl, array $opts = [])
    {
        $this->pdo = $pdo;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->svc = new SuppressionService($pdo);
        $this->env = $opts['env'] ?? static function (string $key): ?string {
            return function_exists('getEnvVar') ? getEnvVar($key) : (getenv($key) ?: null);
        };
        $this->policy = $opts['policy'] ?? new MarketingSendingPolicy($this->env);
        $this->http = $opts['http'] ?? self::curlTransport();
        $this->clock = $opts['clock'] ?? static fn(): int => time();
    }

    public static function idempotencyKey(string $campaign, string $email): string
    {
        return hash('sha256', $campaign . "\n" . SuppressionService::normalizeEmail($email));
    }

    public function policy(): MarketingSendingPolicy
    {
        return $this->policy;
    }

    /**
     * Send one marketing email.
     *
     * @param array $in to, subject, html, campaign, dry_run?,
     *                  thread?: {thread_id, in_reply_to, references},
     *                  thread_id? (legacy: Gmail thread id only)
     * @return array status is one of: sent | already_recorded |
     *               skipped_suppressed | failed | uncertain | in_progress |
     *               sending_disabled | recipient_not_allowlisted |
     *               dry_run | error
     */
    public function send(array $in): array
    {
        $to  = SuppressionService::normalizeEmail((string)($in['to'] ?? ''));
        $cmp = substr((string)($in['campaign'] ?? ''), 0, 160);
        $sub = self::headerSafe((string)($in['subject'] ?? ''));
        $html = (string)($in['html'] ?? '');

        if ($cmp === '') return ['status' => 'error', 'error' => 'campaign required'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'error' => 'invalid recipient', 'email' => $to];
        }

        // dry_run: eligibility plan only - no ledger write, no Gmail call
        if (!empty($in['dry_run'])) {
            return ['status' => 'dry_run', 'email' => $to,
                'eligible' => $this->svc->eligibility([$to])[0]['eligible'],
                'sending' => $this->policy->check($to)];
        }

        // Delivery control first: nothing is recorded or sent when blocked.
        $gate = $this->policy->check($to);
        if (!$gate['allowed']) {
            return ['status' => $gate['reason'], 'email' => $to, 'error' => $gate['reason']];
        }

        $key = self::idempotencyKey($cmp, $to);
        $blocked = $this->claim($cmp, $to, $key);
        if ($blocked !== null) return $blocked;

        // Eligibility re-check immediately before send.
        $elig = $this->svc->eligibility([$to])[0];
        if (empty($elig['eligible'])) {
            $this->transition($cmp, $to, 'running', 'skipped_suppressed', ['last_error' => 'suppressed']);
            return ['status' => 'skipped_suppressed', 'email' => $to];
        }

        try {
            $tok = $this->svc->issueToken($to, $cmp);
        } catch (Throwable $e) {
            return $this->fail($cmp, $to, 'unsubscribe token unavailable', 500);
        }
        $u = $this->baseUrl . '/unsubscribe.php?t=' . $tok;

        // Gmail OAuth. Any failure here is definitive: nothing was sent.
        if (!$this->hasGmailCredentials()) {
            return $this->fail($cmp, $to, 'gmail creds unset', 500);
        }
        $at = $this->accessToken();
        if (!$at) return $this->fail($cmp, $to, 'oauth failed', 502);

        $thread = self::normalizeThread($in);
        $payload = ['raw' => self::base64Url($this->buildMime($to, $sub, $html, $u, $key, $thread))];
        if ($thread['thread_id'] !== '') $payload['threadId'] = $thread['thread_id'];

        $resp = ($this->http)('POST', self::GMAIL . '/messages/send',
            ['Authorization: Bearer ' . $at, 'Content-Type: application/json'],
            json_encode($payload), 30);
        $status = $resp['status'] ?? null;
        $d = json_decode((string)($resp['body'] ?? ''), true);
        $d = is_array($d) ? $d : [];

        if ($status !== null && $status >= 200 && $status < 300 && !empty($d['id'])) {
            $msgId = (string)$d['id'];
            $threadId = (string)($d['threadId'] ?? '');
            $out = ['status' => 'sent', 'email' => $to, 'message_id' => $msgId, 'unsubscribe_url' => $u];
            if ($threadId !== '') $out['thread_id'] = $threadId;
            try {
                $this->transition($cmp, $to, 'running', 'sent', [
                    'gmail_message_id' => $msgId,
                    'gmail_thread_id' => $threadId !== '' ? $threadId : null,
                    'sent_at' => $this->now(), 'last_error' => null,
                ]);
            } catch (Throwable $e) {
                // Gmail accepted the message but the ledger write failed.
                // The row stays 'running', goes stale, and reconciliation
                // finds the message by its send id - it is never resent.
                $out['ledger_pending'] = true;
            }
            return $out;
        }

        // No response, a 5xx, or a 2xx without an id: the provider may have
        // accepted the message. Never retry until reconciled.
        if ($status === null || $status >= 500 || ($status >= 200 && $status < 300)) {
            $why = $status === null ? 'no_response' : 'provider_' . $status;
            return $this->markUncertain($cmp, $to, $why);
        }

        // 4xx: Gmail rejected the request; nothing was sent.
        return $this->fail($cmp, $to, substr('gmail_' . $status . ': ' . json_encode($d), 0, 200), 502);
    }

    /**
     * Reconcile an uncertain (or stale running) send against Gmail without
     * sending anything.
     * @return array{outcome: string, reason?: string, message_id?: string, thread_id?: string}
     *         outcome: sent | not_sent | unknown | not_applicable
     */
    public function reconcile(string $campaign, string $email): array
    {
        $email = SuppressionService::normalizeEmail($email);
        $row = $this->ledgerRow($campaign, $email);
        if (!$row) return ['outcome' => 'not_applicable', 'reason' => 'no_ledger_row'];
        if ($row['status'] === 'sent') {
            return ['outcome' => 'sent', 'message_id' => (string)$row['gmail_message_id'],
                'thread_id' => (string)($row['gmail_thread_id'] ?? '')];
        }
        if ($row['status'] === 'running') {
            if (!$this->isStale($row)) return ['outcome' => 'unknown', 'reason' => 'in_progress'];
            $this->transition($campaign, $email, 'running', 'uncertain', ['last_error' => 'interrupted']);
            $row['status'] = 'uncertain';
        }
        if ($row['status'] !== 'uncertain') return ['outcome' => 'not_applicable', 'reason' => $row['status']];

        $rec = $this->reconcileRow($row);
        if ($rec['outcome'] === 'not_sent') {
            // Confirmed absent from Sent mail: release for a normal retry.
            $this->transition($campaign, $email, 'uncertain', 'failed', ['last_error' => 'reconciled_not_sent']);
        }
        return $rec;
    }

    /**
     * Operator override for an uncertain send that Gmail lookup cannot
     * settle. 'not_sent' releases it for retry; 'sent' records it as
     * delivered (optionally with the Gmail message id). Callers audit this.
     */
    public function resolveUncertain(string $campaign, string $email, string $outcome, ?string $messageId = null): bool
    {
        $email = SuppressionService::normalizeEmail($email);
        if ($outcome === 'not_sent') {
            return $this->transition($campaign, $email, 'uncertain', 'failed',
                ['last_error' => 'operator_confirmed_not_sent']);
        }
        if ($outcome === 'sent') {
            $messageId = $messageId !== null && preg_match('/^[A-Za-z0-9_-]{6,80}$/', $messageId) ? $messageId : null;
            return $this->transition($campaign, $email, 'uncertain', 'sent', [
                'gmail_message_id' => $messageId, 'sent_at' => $this->now(),
                'last_error' => 'operator_confirmed_sent',
            ]);
        }
        return false;
    }

    /** Current ledger row for a send, or null. */
    public function ledgerRow(string $campaign, string $email): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM marketing_sends WHERE campaign_ref=? AND email_normalized=?');
        $s->execute([$campaign, SuppressionService::normalizeEmail($email)]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Authenticated Gmail GET for read-only lookups (thread resolution,
     * reconciliation). status is null when no response was received or no
     * access token could be obtained.
     * @return array{status: ?int, data: array}
     */
    public function gmailGet(string $path, array $query = []): array
    {
        $at = $this->accessToken();
        if (!$at) return ['status' => null, 'data' => []];
        $qs = [];
        foreach ($query as $k => $v) {
            foreach ((array)$v as $one) { $qs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$one); }
        }
        $resp = ($this->http)('GET', self::GMAIL . $path . ($qs ? '?' . implode('&', $qs) : ''),
            ['Authorization: Bearer ' . $at], null, 15);
        $data = json_decode((string)($resp['body'] ?? ''), true);
        return ['status' => $resp['status'] ?? null, 'data' => is_array($data) ? $data : []];
    }

    /** Gmail threadId for a message id; null on any failure. */
    public function fetchThreadId(string $messageId, ?string $accessToken = null): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $messageId)) return null;
        $r = $this->gmailGet('/messages/' . rawurlencode($messageId), ['format' => 'minimal']);
        $t = $r['data']['threadId'] ?? null;
        return $r['status'] === 200 && is_string($t) && $t !== '' ? $t : null;
    }

    /** OAuth access token (cached for this instance); null when unavailable. */
    public function accessToken(): ?string
    {
        if ($this->token !== null) return $this->token;
        if (!$this->hasGmailCredentials()) return null;
        $resp = ($this->http)('POST', 'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => ($this->env)('GOOGLE_CLIENT_ID'),
                'client_secret' => ($this->env)('GOOGLE_CLIENT_SECRET'),
                'refresh_token' => ($this->env)('GMAIL_REFRESH_TOKEN'),
                'grant_type' => 'refresh_token',
            ]), 15);
        $d = json_decode((string)($resp['body'] ?? ''), true);
        $t = is_array($d) ? ($d['access_token'] ?? null) : null;
        return $this->token = (is_string($t) && $t !== '' ? $t : null);
    }

    // ------------------------------------------------------------------
    // Ledger
    // ------------------------------------------------------------------

    /**
     * Claim the send slot. Returns null when this request owns the send,
     * or the response to return when it must not proceed.
     */
    private function claim(string $cmp, string $to, string $key): ?array
    {
        $now = $this->now();
        try {
            $this->pdo->prepare(
                "INSERT INTO marketing_sends (campaign_ref,email_normalized,status,idempotency_key,attempts,attempt_started_at)
                 VALUES (?,?,'running',?,1,?)"
            )->execute([$cmp, $to, $key, $now]);
            return null;
        } catch (PDOException $e) {
            if (!self::isDuplicate($e)) {
                // Ledger unusable (e.g. schema not migrated): fail closed.
                return ['status' => 'failed', 'email' => $to, 'error' => 'ledger unavailable', 'http' => 503];
            }
        }

        $row = $this->ledgerRow($cmp, $to);
        if (!$row) return ['status' => 'failed', 'email' => $to, 'error' => 'ledger unavailable', 'http' => 503];

        if ($row['status'] === 'sent') {
            return ['status' => 'already_recorded', 'email' => $to,
                'message_id' => (string)($row['gmail_message_id'] ?? ''),
                'thread_id' => (string)($row['gmail_thread_id'] ?? '')];
        }
        if ($row['status'] === 'running') {
            if (!$this->isStale($row)) return ['status' => 'in_progress', 'email' => $to];
            // Interrupted attempt: outcome unknown until reconciled.
            if (!$this->transition($cmp, $to, 'running', 'uncertain', ['last_error' => 'interrupted'])) {
                return ['status' => 'in_progress', 'email' => $to];
            }
            $row['status'] = 'uncertain';
        }
        if ($row['status'] === 'uncertain') {
            $rec = $this->reconcileRow($row);
            if ($rec['outcome'] === 'sent') {
                return ['status' => 'sent', 'email' => $to, 'reconciled' => true,
                    'message_id' => (string)($rec['message_id'] ?? ''), 'thread_id' => (string)($rec['thread_id'] ?? '')];
            }
            if ($rec['outcome'] !== 'not_sent') {
                return ['status' => 'uncertain', 'email' => $to,
                    'error' => 'reconcile_pending: ' . ($rec['reason'] ?? 'unknown')];
            }
        }
        // failed / skipped_suppressed / reconciled-not-sent: reclaim by CAS.
        $reclaimed = $this->transition($cmp, $to, (string)$row['status'], 'running', [
            'attempt_started_at' => $now, 'gmail_message_id' => null, 'last_error' => null,
        ], true, $key);
        return $reclaimed ? null : ['status' => 'in_progress', 'email' => $to];
    }

    /**
     * Compare-and-swap status transition. Returns true only when this call
     * moved the row from $from to $to.
     */
    private function transition(string $cmp, string $email, string $from, string $to, array $set = [], bool $bumpAttempts = false, ?string $key = null): bool
    {
        $allowed = ['gmail_message_id', 'gmail_thread_id', 'sent_at', 'last_error', 'attempt_started_at'];
        $sql = 'UPDATE marketing_sends SET status=?';
        $args = [$to];
        foreach ($set as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            $sql .= ", $col=?";
            $args[] = $val === null ? null : substr((string)$val, 0, 250);
        }
        if ($bumpAttempts) $sql .= ', attempts=attempts+1';
        if ($key !== null) { $sql .= ', idempotency_key=COALESCE(idempotency_key, ?)'; $args[] = $key; }
        $sql .= ' WHERE campaign_ref=? AND email_normalized=? AND status=?';
        array_push($args, $cmp, $email, $from);
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount() === 1;
    }

    private function fail(string $cmp, string $to, string $msg, int $http = 500): array
    {
        try { $this->transition($cmp, $to, 'running', 'failed', ['last_error' => $msg]); } catch (Throwable $e) {}
        return ['status' => 'failed', 'email' => $to, 'error' => $msg, 'http' => $http];
    }

    private function markUncertain(string $cmp, string $to, string $why): array
    {
        try { $this->transition($cmp, $to, 'running', 'uncertain', ['last_error' => $why]); } catch (Throwable $e) {}
        return ['status' => 'uncertain', 'email' => $to, 'error' => $why];
    }

    private function isStale(array $row): bool
    {
        $started = (string)($row['attempt_started_at'] ?? '') ?: (string)($row['created_at'] ?? '');
        $ts = $started !== '' ? strtotime($started . ' UTC') : false;
        return $ts === false || (($this->clock)() - $ts) >= self::STALE_RUNNING_SECONDS;
    }

    /**
     * Look for this send in Gmail Sent mail by its send-id header.
     * @return array{outcome: string, reason?: string, message_id?: string, thread_id?: string}
     */
    private function reconcileRow(array $row): array
    {
        $key = (string)($row['idempotency_key'] ?? '');
        if ($key === '' || (int)($row['attempts'] ?? 0) < 1) {
            // Row predates send-id stamping: Gmail cannot prove absence.
            return ['outcome' => 'unknown', 'reason' => 'legacy_row_unverifiable'];
        }
        $email = (string)$row['email_normalized'];
        $created = strtotime(((string)($row['created_at'] ?? '')) . ' UTC');
        $query = 'in:sent to:' . $email;
        if ($created !== false) $query .= ' after:' . max(0, $created - 300);

        $list = $this->gmailGet('/messages', ['q' => $query, 'maxResults' => 25]);
        if ($list['status'] !== 200) {
            return ['outcome' => 'unknown', 'reason' => 'gmail_search_' . ($list['status'] ?? 'no_response')];
        }
        foreach (($list['data']['messages'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') continue;
            $msg = $this->gmailGet('/messages/' . rawurlencode($id),
                ['format' => 'metadata', 'metadataHeaders' => self::SEND_ID_HEADER]);
            if ($msg['status'] !== 200) {
                return ['outcome' => 'unknown', 'reason' => 'gmail_lookup_' . ($msg['status'] ?? 'no_response')];
            }
            foreach (($msg['data']['payload']['headers'] ?? []) as $h) {
                if (strcasecmp((string)($h['name'] ?? ''), self::SEND_ID_HEADER) === 0
                    && hash_equals($key, trim((string)($h['value'] ?? '')))) {
                    $threadId = (string)($msg['data']['threadId'] ?? '');
                    $this->transition((string)$row['campaign_ref'], $email, 'uncertain', 'sent', [
                        'gmail_message_id' => $id, 'gmail_thread_id' => $threadId !== '' ? $threadId : null,
                        'sent_at' => $this->now(), 'last_error' => 'reconciled_sent',
                    ]);
                    return ['outcome' => 'sent', 'message_id' => $id, 'thread_id' => $threadId];
                }
            }
        }
        if (!empty($list['data']['nextPageToken'])) {
            return ['outcome' => 'unknown', 'reason' => 'too_many_candidates'];
        }
        $started = (string)($row['attempt_started_at'] ?? '') ?: (string)($row['created_at'] ?? '');
        $ts = strtotime($started . ' UTC');
        if ($ts === false || (($this->clock)() - $ts) < self::RECONCILE_MIN_AGE_SECONDS) {
            return ['outcome' => 'unknown', 'reason' => 'too_recent'];
        }
        return ['outcome' => 'not_sent'];
    }

    // ------------------------------------------------------------------
    // Message construction
    // ------------------------------------------------------------------

    private function buildMime(string $to, string $subject, string $html, string $unsubscribeUrl, string $key, array $thread): string
    {
        $hdr = MarketingFooter::headers($unsubscribeUrl);
        $head = [
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            self::SEND_ID_HEADER . ': ' . $key,
            'List-Unsubscribe: ' . $hdr['List-Unsubscribe'],
            'List-Unsubscribe-Post: ' . $hdr['List-Unsubscribe-Post'],
        ];
        if ($thread['in_reply_to'] !== '') $head[] = 'In-Reply-To: ' . $thread['in_reply_to'];
        if ($thread['references'] !== '') $head[] = 'References: ' . $thread['references'];

        // multipart/alternative: plain-text first (better deliverability
        // and accessibility), HTML second. The compliance footer and the
        // recipient unsubscribe link are appended to BOTH parts here at
        // send time - they can never be removed by editing campaign
        // content.
        $boundary = 'dtk_' . bin2hex(random_bytes(12));
        $textBody = self::htmlToText($html) . MarketingFooter::text($unsubscribeUrl);
        $htmlBody = $html . MarketingFooter::html($unsubscribeUrl);
        $mime = implode("\r\n", array_merge($head, [
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($textBody), 76, "\r\n"),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($htmlBody), 76, "\r\n"),
            '--' . $boundary . '--',
            '',
        ]));
        return $mime;
    }

    /** Best-effort HTML -> plain text for the multipart text part. */
    private static function htmlToText(string $html): string
    {
        $t = str_replace(['</p>', '</div>', '</li>', '<br>', '<br/>', '<br />'], "\n", $html);
        $t = preg_replace('#<li[^>]*>#i', "\n- ", $t);
        $t = preg_replace('#<li[^>]*>\s*#i', "- ", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\r\n?/", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t) . "\n";
    }

    /** Validate threading inputs; anything malformed is dropped. */
    private static function normalizeThread(array $in): array
    {
        $t = is_array($in['thread'] ?? null) ? $in['thread'] : [];
        $threadId = trim((string)($t['thread_id'] ?? ($in['thread_id'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $threadId)) $threadId = '';
        $inReplyTo = trim((string)($t['in_reply_to'] ?? ''));
        if (!preg_match('/^<[^<>\s]{1,300}>$/', $inReplyTo)) $inReplyTo = '';
        $refs = [];
        foreach (preg_split('/\s+/', trim((string)($t['references'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $r) {
            if (preg_match('/^<[^<>\s]{1,300}>$/', $r)) $refs[] = $r;
        }
        return ['thread_id' => $threadId, 'in_reply_to' => $inReplyTo,
            'references' => implode(' ', array_slice($refs, -20))];
    }

    /** Strip header-injection characters. */
    private static function headerSafe(string $v): string
    {
        return trim(preg_replace('/[\r\n\x00]+/', ' ', $v));
    }

    /** RFC 2047 encode a header value when it is not plain ASCII. */
    private static function encodeHeader(string $v): string
    {
        return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    private static function base64Url(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }

    private static function isDuplicate(PDOException $e): bool
    {
        return (string)$e->getCode() === '23000' || ($e->errorInfo[0] ?? '') === '23000';
    }

    private function hasGmailCredentials(): bool
    {
        return ($this->env)('GMAIL_REFRESH_TOKEN') && ($this->env)('GOOGLE_CLIENT_ID') && ($this->env)('GOOGLE_CLIENT_SECRET');
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }

    /** Default HTTP transport. status is null when no response arrived. */
    private static function curlTransport(): callable
    {
        return static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $r = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($r === false || $code === 0) return ['status' => null, 'body' => ''];
            return ['status' => $code, 'body' => (string)$r];
        };
    }
}
