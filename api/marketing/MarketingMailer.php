<?php
/**
 * Shared Gmail marketing sender.
 *
 * Single send path used by BOTH:
 *  - api/marketing-send.php   (token-authed endpoint for the MCP connector)
 *  - api/marketing-admin.php  (Platform Administration campaign sends)
 *
 * Guarantees (unchanged from the original endpoint):
 *  - marketing_sends ledger UNIQUE(campaign_ref,email_normalized) claim
 *    BEFORE any provider call = idempotent sends; 'sent'/'running' rows
 *    are never resent, 'failed'/'skipped_suppressed' are reclaimable.
 *  - Suppression eligibility re-check immediately before send.
 *  - Recipient-specific unsubscribe token; branded MarketingFooter HTML;
 *    RFC 8058 List-Unsubscribe / List-Unsubscribe-Post headers.
 *  - Optional Gmail threadId for follow-up threading.
 *  - 'sent' is only reported after Gmail returns a message id.
 */
require_once __DIR__ . '/SuppressionService.php';
require_once __DIR__ . '/MarketingFooter.php';

final class MarketingMailer
{
    private PDO $pdo;
    private SuppressionService $svc;
    private string $baseUrl;

    public function __construct(PDO $pdo, string $baseUrl)
    {
        $this->pdo = $pdo;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->svc = new SuppressionService($pdo);
    }

    /**
     * Send one marketing email.
     *
     * @param array $in keys: to, subject, html, text?, campaign, dry_run?, thread_id?
     * @return array status payload (see marketing-send.php contract)
     */
    public function send(array $in): array
    {
        $to  = SuppressionService::normalizeEmail((string)($in['to'] ?? ''));
        $cmp = substr((string)($in['campaign'] ?? ''), 0, 160);
        $sub = (string)($in['subject'] ?? '');
        $html = (string)($in['html'] ?? '');
        $text = (string)($in['text'] ?? '');

        if ($cmp === '') return ['status' => 'error', 'error' => 'campaign required'];

        // dry_run: eligibility plan only - no ledger write, no Gmail call
        if (!empty($in['dry_run'])) {
            return ['status' => 'dry_run', 'email' => $to,
                'eligible' => $this->svc->eligibility([$to])[0]['eligible']];
        }

        // Idempotency: claim the slot first.
        try {
            $this->pdo->prepare("INSERT INTO marketing_sends (campaign_ref,email_normalized,status) VALUES (?,?, 'running')")
                ->execute([$cmp, $to]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) throw $e;
            $chk = $this->pdo->prepare("SELECT status FROM marketing_sends WHERE campaign_ref=? AND email_normalized=?");
            $chk->execute([$cmp, $to]);
            $st = $chk->fetchColumn();
            if ($st === 'sent' || $st === 'running') {
                return ['status' => 'already_recorded', 'email' => $to];
            }
            // failed/skipped_suppressed are retryable: reclaim the row
            $this->pdo->prepare("UPDATE marketing_sends SET status='running', gmail_message_id=NULL WHERE campaign_ref=? AND email_normalized=?")
                ->execute([$cmp, $to]);
        }

        $fail = function (string $msg, int $http = 500) use ($cmp, $to) {
            $this->pdo->prepare("UPDATE marketing_sends SET status='failed' WHERE campaign_ref=? AND email_normalized=?")
                ->execute([$cmp, $to]);
            return ['status' => 'failed', 'email' => $to, 'error' => $msg, 'http' => $http];
        };

        // Eligibility re-check immediately before send.
        $elig = $this->svc->eligibility([$to])[0];
        if (empty($elig['eligible'])) {
            $this->pdo->prepare("UPDATE marketing_sends SET status='skipped_suppressed' WHERE campaign_ref=? AND email_normalized=?")
                ->execute([$cmp, $to]);
            return ['status' => 'skipped_suppressed', 'email' => $to];
        }

        $tok = $this->svc->issueToken($to, $cmp);
        $u   = $this->baseUrl . '/unsubscribe.php?t=' . $tok;

        // Gmail OAuth
        $rt = getEnvVar('GMAIL_REFRESH_TOKEN');
        $cid = getEnvVar('GOOGLE_CLIENT_ID');
        $csec = getEnvVar('GOOGLE_CLIENT_SECRET');
        if (!$rt || !$cid || !$csec) {
            return $fail('gmail creds unset');
        }
        $tokR = json_decode((string)@file_get_contents('https://oauth2.googleapis.com/token', false,
            stream_context_create(['http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'client_id' => $cid, 'client_secret' => $csec,
                    'refresh_token' => $rt, 'grant_type' => 'refresh_token',
                ]),
            ]])), true);
        $at = $tokR['access_token'] ?? null;
        if (!$at) return $fail('oauth failed', 502);

        // MIME: footer + RFC 8058 headers (+ optional thread)
        $hdr = MarketingFooter::headers($u);
        $mime = "To: $to\r\nSubject: $sub\r\nMIME-Version: 1.0\r\n"
            . "List-Unsubscribe: {$hdr['List-Unsubscribe']}\r\n"
            . "List-Unsubscribe-Post: {$hdr['List-Unsubscribe-Post']}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $html . MarketingFooter::html($u);
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        $payload = ['raw' => $raw];
        $threadId = trim((string)($in['thread_id'] ?? ''));
        if ($threadId !== '') $payload['threadId'] = $threadId;

        $r = @file_get_contents('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', false,
            stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer $at\r\nContent-Type: application/json",
                'content' => json_encode($payload),
                'ignore_errors' => true,
                'timeout' => 30,
            ]]));
        $d = json_decode((string)$r, true) ?? [];

        if (isset($d['id'])) {
            $this->pdo->prepare("UPDATE marketing_sends SET status='sent',gmail_message_id=?,sent_at=UTC_TIMESTAMP() WHERE campaign_ref=? AND email_normalized=?")
                ->execute([$d['id'], $cmp, $to]);
            $out = ['status' => 'sent', 'email' => $to, 'message_id' => $d['id'], 'unsubscribe_url' => $u];
            if (!empty($d['threadId'])) $out['thread_id'] = $d['threadId'];
            return $out;
        }
        return $fail(substr(json_encode($d), 0, 200), 502);
    }

    /**
     * Fetch the Gmail threadId for a previously sent message id (used to
     * thread follow-ups into the original conversation). Returns null on
     * any failure - threading is best-effort, never blocks a send.
     */
    public function fetchThreadId(string $messageId, ?string $accessToken = null): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $messageId)) return null;
        $at = $accessToken ?? $this->accessToken();
        if (!$at) return null;
        $r = @file_get_contents(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($messageId) . '?format=minimal',
            false,
            stream_context_create(['http' => [
                'header' => "Authorization: Bearer $at\r\n",
                'timeout' => 15,
            ]]));
        $d = json_decode((string)$r, true);
        $t = $d['threadId'] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    /** OAuth access token for read calls (thread lookup). */
    public function accessToken(): ?string
    {
        $rt = getEnvVar('GMAIL_REFRESH_TOKEN');
        $cid = getEnvVar('GOOGLE_CLIENT_ID');
        $csec = getEnvVar('GOOGLE_CLIENT_SECRET');
        if (!$rt || !$cid || !$csec) return null;
        $tokR = json_decode((string)@file_get_contents('https://oauth2.googleapis.com/token', false,
            stream_context_create(['http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'client_id' => $cid, 'client_secret' => $csec,
                    'refresh_token' => $rt, 'grant_type' => 'refresh_token',
                ]),
                'timeout' => 15,
            ]])), true);
        return $tokR['access_token'] ?? null;
    }
}
