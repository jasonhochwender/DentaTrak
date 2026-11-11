<?php
/**
 * MarketingMailer lifecycle tests against the LOCAL development database.
 *
 * Uses injected http/env/clock seams - no real Gmail or network calls.
 * Creates and removes only 'test-mo-*' ledger/token/suppression rows.
 *
 * Requires the local development database (AGENTS.md: development env,
 * MySQL on port 3308) with the marketing migrations applied.
 *
 * Run: php tests/marketing-mailer-test.php
 */

if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/MarketingMailer.php';

global $pdo;
$envName = $appConfig['current_environment'] ?? 'production';
if (!isset($pdo) || !($pdo instanceof PDO) || $envName === 'production') {
    echo "SKIP: requires the local development database\n";
    exit(1);
}
// Schema guard: the hardened columns must exist.
try {
    $pdo->query('SELECT idempotency_key, attempts, attempt_started_at FROM marketing_sends LIMIT 0');
} catch (Throwable $e) {
    echo "SKIP: marketing send-hardening migration not applied to dev DB\n";
    exit(1);
}

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}

// ---- fixtures -------------------------------------------------------------
$CMP = 'test-mo-campaign';
$TO  = 'test-recipient@example.com';
$NOW = time();

// Fake transport. Routes by method+url; send behavior is scripted per test.
$calls = [];
$script = ['send' => ['status' => 200, 'body' => '{"id":"gm1","threadId":"gt1"}'],
           'list' => ['status' => 200, 'body' => '{"messages":[]}'],
           'msg'  => ['status' => 404, 'body' => '{}']];
$http = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$calls, &$script) {
    $calls[] = [$method, $url, $body];
    if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
        return ['status' => 200, 'body' => '{"access_token":"fake-at"}'];
    }
    if (strpos($url, '/messages/send') !== false) {
        $r = $script['send'];
        return ['status' => $r['status'], 'body' => $r['body']];
    }
    if (strpos($url, '/messages?') !== false || preg_match('#/messages(\?|$)#', $url)) {
        // list call (has query string with q=)
        if (strpos($url, 'q=') !== false) { $r = $script['list']; return ['status' => $r['status'], 'body' => $r['body']]; }
    }
    if (preg_match('#/messages/([^?]+)#', $url, $m)) {
        $r = is_callable($script['msg']) ? $script['msg']($m[1]) : $script['msg'];
        return ['status' => $r['status'], 'body' => $r['body']];
    }
    return ['status' => 500, 'body' => '{"error":"unmocked ' . $url . '"}'];
};

$envVars = [
    'MARKETING_SENDING' => 'true',
    'MARKETING_SEND_ALLOWLIST' => $TO,
    'GOOGLE_CLIENT_ID' => 'cid', 'GOOGLE_CLIENT_SECRET' => 'sec', 'GMAIL_REFRESH_TOKEN' => 'rtok',
];
$envFn = static function (string $k) use (&$envVars) { return $envVars[$k] ?? null; };
$policy = new MarketingSendingPolicy($envFn);
$clock = static function () use (&$NOW) { return $NOW; };

$mailer = fn() => new MarketingMailer($pdo, 'https://dentatrak.com', [
    'policy' => $policy, 'http' => $http, 'env' => $envFn, 'clock' => $clock,
]);
$sendCalls = function () use (&$calls) {
    return count(array_filter($calls, fn($c) => strpos($c[1], '/messages/send') !== false));
};
$ledger = function () use ($pdo, $CMP, $TO) {
    $s = $pdo->prepare('SELECT * FROM marketing_sends WHERE campaign_ref=? AND email_normalized=?');
    $s->execute([$CMP, $TO]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};
$reset = function () use ($pdo, $CMP) {
    $pdo->prepare("DELETE FROM marketing_sends WHERE campaign_ref LIKE 'test-mo-%'")->execute();
    $pdo->prepare("DELETE FROM marketing_unsub_tokens WHERE email_normalized LIKE '%@example.com' OR email_normalized LIKE '%@test.local'")->execute();
    $pdo->prepare("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%@example.com' OR email_normalized LIKE '%@test.local'")->execute();
};
$reset();

// ---- 1. delivery control ---------------------------------------------------
$envVars['MARKETING_SENDING'] = '';
$r = $mailer()->send(['to' => $TO, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP]);
ok('sending disabled: refused before ledger/provider', $r['status'] === 'sending_disabled' && $ledger() === null);
$envVars['MARKETING_SENDING'] = 'true'; $envVars['MARKETING_SEND_ALLOWLIST'] = 'other@example.com';
$r = $mailer()->send(['to' => $TO, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP]);
ok('non-allowlisted recipient refused, no ledger row', $r['status'] === 'recipient_not_allowlisted' && $ledger() === null);
$envVars['MARKETING_SEND_ALLOWLIST'] = $TO;

// ---- 2. happy path ----------------------------------------------------------
$r = $mailer()->send(['to' => $TO, 'subject' => 'Hello', 'html' => '<p>Body</p>', 'campaign' => $CMP]);
ok('allowlisted send succeeds', $r['status'] === 'sent' && ($r['message_id'] ?? '') === 'gm1');
$row = $ledger();
ok('ledger row marked sent with gmail ids', $row && $row['status'] === 'sent'
    && $row['gmail_message_id'] === 'gm1' && $row['gmail_thread_id'] === 'gt1');
ok('idempotency key stored = sha256(campaign\\nemail)',
    $row && $row['idempotency_key'] === hash('sha256', $CMP . "\n" . $TO));
// MIME inspection: send-id header, RFC8058, footer, no injection
$sendBody = null;
foreach ($calls as $c) { if (strpos($c[1], '/messages/send') !== false) $sendBody = $c[2]; }
$mime = json_decode((string)$sendBody, true)['raw'] ?? '';
$mime = base64_decode(strtr($mime, '-_', '+/'));
// The HTML part is itself base64 (Content-Transfer-Encoding); decode it.
$mimeBody = base64_decode(substr($mime, strpos($mime, "\r\n\r\n") + 4));
ok('MIME carries send-id header matching ledger key',
    strpos($mime, 'X-DentaTrak-Send-Id: ' . $row['idempotency_key']) !== false);
ok('MIME carries List-Unsubscribe + one-click post', strpos($mime, 'List-Unsubscribe: <https://dentatrak.com/unsubscribe.php?t=') !== false
    && strpos($mime, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') !== false);
ok('MIME footer appended (logo + unsubscribe here)', strpos($mimeBody, 'dentatrak-logo-standard') !== false
    && strpos($mimeBody, 'unsubscribe here') !== false);

$before = $sendCalls();
$r2 = $mailer()->send(['to' => $TO, 'subject' => 'Hello', 'html' => '<p>Body</p>', 'campaign' => $CMP]);
ok('repeat send returns already_recorded', $r2['status'] === 'already_recorded' && $sendCalls() === $before);

// ---- 3. threading ------------------------------------------------------------
$envVars['MARKETING_SEND_ALLOWLIST'] = implode(',', [$TO, 'thread@example.com', 'thread2@example.com',
    'uncertain@example.com', 'retry@example.com', 'fail@example.com', 'suppressed@example.com',
    'race@example.com', 'op@example.com']);
$CMP2 = 'test-mo-thread';
$r = $mailer()->send(['to' => 'thread@example.com', 'subject' => 'Re: Intro', 'html' => '<p>b</p>',
    'campaign' => $CMP2, 'thread' => ['thread_id' => 'gt9', 'in_reply_to' => '<orig@x.com>', 'references' => '<a@x> <orig@x.com>']]);
ok('threaded send succeeds', $r['status'] === 'sent');
$lastSend = end($calls)[2];
$payload = json_decode((string)$lastSend, true);
ok('threadId passed to Gmail', ($payload['threadId'] ?? '') === 'gt9');
$mimeOuter = base64_decode(strtr($payload['raw'], '-_', '+/'));
ok('MIME has In-Reply-To + References', strpos($mimeOuter, 'In-Reply-To: <orig@x.com>') !== false
    && strpos($mimeOuter, 'References: <a@x> <orig@x.com>') !== false);
$bad = $mailer()->send(['to' => 'thread2@example.com', 'subject' => 'x', 'html' => '<p>b</p>',
    'campaign' => 'test-mo-thread2', 'thread' => ['thread_id' => 'bad id!', 'in_reply_to' => 'no-brackets', 'references' => 'junk <ok@x>']]);
$lastSend = end($calls)[2];
$payload3 = json_decode((string)$lastSend, true);
$mime3 = base64_decode(strtr($payload3['raw'], '-_', '+/'));
ok('malformed threading values dropped', strpos($mime3, 'In-Reply-To') === false
    && strpos($mime3, 'no-brackets') === false
    && strpos($mime3, 'References: <ok@x>') !== false
    && !isset($payload3['threadId']));

// ---- 4. uncertain outcome + reconciliation -----------------------------------
$CMP3 = 'test-mo-uncertain'; $TO3 = 'uncertain@example.com';
$envVars['MARKETING_SEND_ALLOWLIST'] = $TO . ',' . $TO3 . ',thread@example.com,thread2@example.com';
$script['send'] = ['status' => null, 'body' => '']; // provider timeout
$before = $sendCalls();
$r = $mailer()->send(['to' => $TO3, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP3]);
ok('provider timeout -> uncertain', $r['status'] === 'uncertain' && $sendCalls() === $before + 1);
$s = $pdo->prepare('SELECT * FROM marketing_sends WHERE campaign_ref=? AND email_normalized=?');
$s->execute([$CMP3, $TO3]); $urow = $s->fetch(PDO::FETCH_ASSOC);
ok('ledger row is uncertain', $urow && $urow['status'] === 'uncertain' && $urow['last_error'] === 'no_response');

// immediate retry: too recent to trust absence -> stays uncertain, NO resend
$r = $mailer()->send(['to' => $TO3, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP3]);
ok('too-recent uncertain stays uncertain (no resend)', $r['status'] === 'uncertain' && $sendCalls() === $before + 1);

// age past reconcile minimum; gmail search finds the stamped message -> sent, no resend
$NOW += MarketingMailer::RECONCILE_MIN_AGE_SECONDS + 60;
$script['list'] = ['status' => 200, 'body' => '{"messages":[{"id":"gm-found","threadId":"gt-found"}]}'];
$script['msg'] = function ($id) use ($urow) {
    return ['status' => 200, 'body' => json_encode(['id' => $id, 'threadId' => 'gt-found',
        'payload' => ['headers' => [['name' => 'X-DentaTrak-Send-Id', 'value' => $urow['idempotency_key']]]]])];
};
$before = $sendCalls();
$r = $mailer()->send(['to' => $TO3, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP3]);
ok('reconciled send returns sent without resending', $r['status'] === 'sent' && $sendCalls() === $before);
$s->execute([$CMP3, $TO3]); $urow = $s->fetch(PDO::FETCH_ASSOC);
ok('ledger settled to sent via reconciliation', $urow['status'] === 'sent' && $urow['gmail_message_id'] === 'gm-found');

// ---- 5. uncertain -> confirmed absent -> safe retry ----------------------------
$CMP4 = 'test-mo-retry'; $TO4 = 'retry@example.com';
$envVars['MARKETING_SEND_ALLOWLIST'] .= ',' . $TO4;
$script['send'] = ['status' => null, 'body' => ''];
$before = $sendCalls();
$r = $mailer()->send(['to' => $TO4, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP4]);
ok('second uncertain recorded', $r['status'] === 'uncertain');
$NOW += MarketingMailer::RECONCILE_MIN_AGE_SECONDS + 60;
$script['list'] = ['status' => 200, 'body' => '{"messages":[]}']; // nothing in Sent mail
$script['send'] = ['status' => 200, 'body' => '{"id":"gm-retry","threadId":"gt-retry"}'];
$r = $mailer()->send(['to' => $TO4, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP4]);
ok('confirmed-absent uncertain retries and sends', $r['status'] === 'sent' && $r['message_id'] === 'gm-retry');
ok('exactly one resend after reconciliation', $sendCalls() === $before + 2);

// ---- 6. definitive failure then retry -----------------------------------------
$CMP5 = 'test-mo-fail'; $TO5 = 'fail@example.com';
$envVars['MARKETING_SEND_ALLOWLIST'] .= ',' . $TO5;
$script['send'] = ['status' => 400, 'body' => '{"error":{"message":"bad request"}}'];
$r = $mailer()->send(['to' => $TO5, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP5]);
ok('provider 4xx -> failed (safe to retry)', $r['status'] === 'failed');
$script['send'] = ['status' => 200, 'body' => '{"id":"gm-ok","threadId":"gt-ok"}'];
$r = $mailer()->send(['to' => $TO5, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP5]);
ok('failed send retries cleanly', $r['status'] === 'sent');

// ---- 7. suppression re-check ----------------------------------------------------
$TO6 = 'suppressed@example.com';
$envVars['MARKETING_SEND_ALLOWLIST'] .= ',' . $TO6;
$pdo->prepare("INSERT INTO marketing_suppressions (email_normalized,unsubscribed_at,source) VALUES (?,UTC_TIMESTAMP(),'test')")
    ->execute([$TO6]);
$r = $mailer()->send(['to' => $TO6, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => 'test-mo-supp']);
ok('suppressed recipient skipped before provider', $r['status'] === 'skipped_suppressed');
$pdo->prepare("DELETE FROM marketing_suppressions WHERE email_normalized=?")->execute([$TO6]);

// ---- 8. concurrent / interrupted claims -------------------------------------------
$NOW = time(); // realign fake clock with DB timestamps for staleness checks
$TO7 = 'race@example.com';
$envVars['MARKETING_SEND_ALLOWLIST'] .= ',' . $TO7;
// Fresh 'running' row owned by another request -> in_progress, no send
$pdo->prepare("INSERT INTO marketing_sends (campaign_ref,email_normalized,status,idempotency_key,attempts,attempt_started_at)
    VALUES ('test-mo-race',?,'running',?,1,UTC_TIMESTAMP())")->execute([$TO7, hash('sha256', 'test-mo-race' . "\n" . $TO7)]);
$before = $sendCalls();
$r = $mailer()->send(['to' => $TO7, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => 'test-mo-race']);
ok('fresh running row -> in_progress, no duplicate send', $r['status'] === 'in_progress' && $sendCalls() === $before);
// Stale running row -> interrupted -> uncertain -> reconcile -> absent -> retry
$pdo->prepare("UPDATE marketing_sends SET attempt_started_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR WHERE campaign_ref='test-mo-race'")->execute();
$NOW += MarketingMailer::STALE_RUNNING_SECONDS + 60;
$script['list'] = ['status' => 200, 'body' => '{"messages":[]}'];
$script['send'] = ['status' => 200, 'body' => '{"id":"gm-race","threadId":"gt-race"}'];
$r = $mailer()->send(['to' => $TO7, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => 'test-mo-race']);
ok('stale running row reconciled then resent once', $r['status'] === 'sent' && $sendCalls() === $before + 1);

// ---- 9. resolveUncertain operator override -----------------------------------------
$CMP8 = 'test-mo-op'; $TO8 = 'op@example.com';
$envVars['MARKETING_SEND_ALLOWLIST'] .= ',' . $TO8;
$script['send'] = ['status' => null, 'body' => ''];
$NOW += MarketingMailer::RECONCILE_MIN_AGE_SECONDS + 60;
$script['list'] = ['status' => 500, 'body' => '{}']; // Gmail lookup down -> stays unknown
$r = $mailer()->send(['to' => $TO8, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP8]);
// first call: claim + send -> uncertain; second call reconciles (lookup fails)
$r = $mailer()->send(['to' => $TO8, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP8]);
ok('gmail lookup outage keeps uncertain', $r['status'] === 'uncertain');
ok('operator marks not_sent -> retryable', $mailer()->resolveUncertain($CMP8, $TO8, 'not_sent'));
$script['send'] = ['status' => 200, 'body' => '{"id":"gm-op","threadId":"gt-op"}'];
$r = $mailer()->send(['to' => $TO8, 'subject' => 's', 'html' => '<p>h</p>', 'campaign' => $CMP8]);
ok('operator-cleared send retries', $r['status'] === 'sent');

// ---- cleanup ---------------------------------------------------------------------
$reset();
echo "\nmarketing-mailer-test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
