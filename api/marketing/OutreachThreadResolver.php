<?php
/**
 * Finds the original Gmail conversation for a follow-up.
 *
 * Candidate prior messages, most reliable first:
 *   1. campaign_record - Gmail ids stored on earlier campaign recipients
 *   2. send_ledger     - Gmail ids in the marketing_sends ledger (covers
 *                        sends made through the token endpoint / MCP)
 *   3. sheet_notes     - "Gmail <id>" entries written to the prospect's
 *                        Outreach Notes by earlier outreach
 *   4. gmail_search    - Sent mail addressed to the recipient, used only
 *                        when it yields exactly one conversation
 *
 * Every candidate is verified against Gmail before use: the message must
 * exist and must have been addressed to this recipient. A verified message
 * supplies what Gmail needs to thread a reply: the thread id, the original
 * Message-ID (for In-Reply-To / References), and the original subject.
 *
 * Result status:
 *   resolved     - thread identified; send as a reply in that conversation
 *   unresolved   - no prior message to this recipient could be found
 *   ambiguous    - several unrelated conversations match; none is chosen
 *   unverifiable - a prior message is on record but Gmail could not confirm
 *                  it (lookup unavailable, message gone, or addressed to
 *                  someone else)
 * Anything other than 'resolved' means the follow-up would start a NEW
 * conversation; callers surface that rather than guess a thread.
 */
final class OutreachThreadResolver
{
    private PDO $pdo;
    /** @var callable(string, array): array{status: ?int, data: array} */
    private $gmailGet;

    public function __construct(PDO $pdo, callable $gmailGet)
    {
        $this->pdo = $pdo;
        $this->gmailGet = $gmailGet;
    }

    /**
     * @param string   $email       recipient
     * @param string[] $sheetMsgIds Gmail ids parsed from the sheet notes
     * @param ?string  $excludeCampaignRef ledger ref of the campaign being
     *                 prepared (its own sends are not "prior" outreach)
     */
    public function resolve(string $email, array $sheetMsgIds = [], ?string $excludeCampaignRef = null): array
    {
        $email = strtolower(trim($email));
        $none = ['thread_id' => '', 'rfc_message_id' => '', 'references' => '', 'subject' => '', 'source' => ''];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'unresolved', 'reason' => 'invalid_recipient'] + $none;
        }

        $failures = [];
        foreach ($this->candidates($email, $sheetMsgIds, $excludeCampaignRef) as [$messageId, $source]) {
            $v = $this->verify($messageId, $email);
            if ($v['ok']) {
                return ['status' => 'resolved', 'reason' => '', 'source' => $source] + $v['thread'];
            }
            $failures[] = $v['reason'];
        }

        // Stored ids could not be confirmed. A lookup outage must not be
        // papered over by picking some other conversation.
        if (in_array('gmail_lookup_unavailable', $failures, true)) {
            return ['status' => 'unverifiable', 'reason' => 'gmail_lookup_unavailable'] + $none;
        }

        // Fallback: recipient history in Sent mail, only when unambiguous.
        $list = ($this->gmailGet)('/messages', ['q' => 'in:sent to:' . $email, 'maxResults' => 20]);
        if ($list['status'] !== 200) {
            return ['status' => $failures ? 'unverifiable' : 'unresolved',
                'reason' => $failures ? $failures[0] : 'gmail_search_unavailable'] + $none;
        }
        $byThread = [];
        foreach (($list['data']['messages'] ?? []) as $m) {
            $tid = (string)($m['threadId'] ?? '');
            $mid = (string)($m['id'] ?? '');
            if ($tid !== '' && $mid !== '') $byThread[$tid][] = $mid; // newest first
        }
        if (!$byThread) {
            return ['status' => $failures ? 'unverifiable' : 'unresolved',
                'reason' => $failures ? $failures[0] : 'no_prior_message_found'] + $none;
        }
        if (count($byThread) > 1 || !empty($list['data']['nextPageToken'])) {
            return ['status' => 'ambiguous', 'reason' => 'multiple_conversations_found'] + $none;
        }
        $v = $this->verify(reset($byThread)[0], $email);
        if ($v['ok']) {
            return ['status' => 'resolved', 'reason' => '', 'source' => 'gmail_search'] + $v['thread'];
        }
        return ['status' => 'unverifiable', 'reason' => $v['reason']] + $none;
    }

    /** Subject for a reply in the original conversation ("Re: " once). */
    public static function replySubject(string $originalSubject): string
    {
        $s = trim(preg_replace('/[\r\n]+/', ' ', $originalSubject));
        return preg_match('/^re:/i', $s) ? $s : 'Re: ' . $s;
    }

    /** @return array<int, array{0: string, 1: string}> [messageId, source], newest first per source */
    private function candidates(string $email, array $sheetMsgIds, ?string $excludeCampaignRef): array
    {
        $out = []; $seen = [];
        $add = static function (string $id, string $source) use (&$out, &$seen): void {
            if ($id === '' || isset($seen[$id]) || !preg_match('/^[A-Za-z0-9_-]{6,80}$/', $id)) return;
            $seen[$id] = true;
            $out[] = [$id, $source];
        };
        try {
            $s = $this->pdo->prepare(
                "SELECT gmail_message_id FROM marketing_campaign_recipients
                  WHERE prospect_email=? AND status='sent' AND gmail_message_id IS NOT NULL
                  ORDER BY sent_at DESC, id DESC LIMIT 10");
            $s->execute([$email]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $add((string)$id, 'campaign_record');
        } catch (Throwable $e) { /* table absent: other sources still apply */ }
        try {
            $sql = "SELECT gmail_message_id FROM marketing_sends
                     WHERE email_normalized=? AND status='sent' AND gmail_message_id IS NOT NULL";
            $args = [$email];
            if ($excludeCampaignRef !== null) { $sql .= ' AND campaign_ref<>?'; $args[] = $excludeCampaignRef; }
            $s = $this->pdo->prepare($sql . ' ORDER BY sent_at DESC, id DESC LIMIT 10');
            $s->execute($args);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $add((string)$id, 'send_ledger');
        } catch (Throwable $e) {}
        foreach (array_reverse(array_values($sheetMsgIds)) as $id) $add((string)$id, 'sheet_notes');
        return $out;
    }

    /** Confirm a message exists and was addressed to the recipient. */
    private function verify(string $messageId, string $email): array
    {
        $r = ($this->gmailGet)('/messages/' . rawurlencode($messageId), [
            'format' => 'metadata',
            'metadataHeaders' => ['To', 'Subject', 'Message-ID', 'References'],
        ]);
        if ($r['status'] === 404) return ['ok' => false, 'reason' => 'prior_message_not_found'];
        if ($r['status'] !== 200) return ['ok' => false, 'reason' => 'gmail_lookup_unavailable'];

        $h = [];
        foreach (($r['data']['payload']['headers'] ?? []) as $hdr) {
            $h[strtolower((string)($hdr['name'] ?? ''))] = (string)($hdr['value'] ?? '');
        }
        $to = strtolower($h['to'] ?? '');
        if ($to === '' || !preg_match('/(^|[\s<,;"])' . preg_quote($email, '/') . '($|[\s>,;"])/', $to)) {
            return ['ok' => false, 'reason' => 'prior_message_recipient_mismatch'];
        }
        $rfcId = trim($h['message-id'] ?? '');
        $threadId = (string)($r['data']['threadId'] ?? '');
        if ($threadId === '' || !preg_match('/^<[^<>\s]+>$/', $rfcId)) {
            return ['ok' => false, 'reason' => 'prior_message_headers_incomplete'];
        }
        $refs = trim(trim($h['references'] ?? '') . ' ' . $rfcId);
        return ['ok' => true, 'reason' => '', 'thread' => [
            'thread_id' => $threadId,
            'rfc_message_id' => $rfcId,
            'references' => $refs,
            'subject' => self::replySubject($h['subject'] ?? ''),
        ]];
    }
}
