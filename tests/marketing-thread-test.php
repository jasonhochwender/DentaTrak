<?php
/**
 * OutreachThreadResolver tests against the LOCAL development database.
 *
 * gmailGet is a fake callable - no network. Uses 'test-mo-thread-*'
 * fixtures in marketing_campaign_recipients / marketing_sends and removes
 * them afterwards.
 *
 * Run: php tests/marketing-thread-test.php
 */

if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/OutreachThreadResolver.php';

global $pdo;
$envName = $appConfig['current_environment'] ?? 'production';
if (!isset($pdo) || !($pdo instanceof PDO) || $envName === 'production') {
    echo "SKIP: requires the local development database\n";
    exit(1);
}

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}

$EMAIL = 'threadtest@example.com';
$CMP = 'test-mo-thread-campaign';
$EXCL = 'test-mo-thread-excluded';

// fixtures: one sent campaign recipient + one ledger row + one excluded ref
$pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status) VALUES (?,'t','followup','completed')")
    ->execute([$CMP]);
$pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status) VALUES (?,'t2','initial','completed')")
    ->execute([$EXCL]);
$pdo->prepare("INSERT INTO marketing_campaign_recipients
    (campaign_id,prospect_email,status,gmail_message_id,gmail_thread_id,sent_at)
    VALUES (?,?,'sent','m-campaign','t-campaign',UTC_TIMESTAMP())")->execute([$CMP, $EMAIL]);
$pdo->prepare("INSERT INTO marketing_sends (campaign_ref,email_normalized,status,idempotency_key,gmail_message_id,gmail_thread_id,sent_at,attempts)
    VALUES (?,?,'sent',?, 'm-ledger','t-ledger',UTC_TIMESTAMP(),1)")->execute([$CMP, $EMAIL, hash('sha256', 'x')]);
$pdo->prepare("INSERT INTO marketing_sends (campaign_ref,email_normalized,status,idempotency_key,gmail_message_id,gmail_thread_id,sent_at,attempts)
    VALUES (?,?,'sent',?, 'm-excluded','t-excluded',UTC_TIMESTAMP(),1)")->execute([$EXCL, $EMAIL, hash('sha256', 'y')]);

// Fake Gmail: /messages/{id} returns per-id metadata; /messages list returns $listBody.
$gmail = function (array $msgMeta, array $listBody, ?int $listStatus = 200) {
    return static function (string $path, array $query = []) use ($msgMeta, $listBody, $listStatus) {
        if (preg_match('#/messages/([^?]+)#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if (!isset($msgMeta[$id])) return ['status' => 404, 'data' => []];
            return ['status' => 200, 'data' => $msgMeta[$id]];
        }
        return ['status' => $listStatus, 'data' => $listBody];
    };
};
$goodMeta = function (string $threadId, string $rfcId, string $subject, string $to = 'threadtest@example.com') {
    return ['threadId' => $threadId, 'payload' => ['headers' => [
        ['name' => 'To', 'value' => "Lab <{$to}>"],
        ['name' => 'Subject', 'value' => $subject],
        ['name' => 'Message-ID', 'value' => $rfcId],
        ['name' => 'References', 'value' => '<older@x.com>'],
    ]]];
};

// ---- 1. resolves from campaign record -------------------------------------
$resolver = new OutreachThreadResolver($pdo, $gmail([
    'm-campaign' => $goodMeta('t-campaign', '<c1@x.com>', 'Intro to DentaTrak'),
    'm-ledger'   => $goodMeta('t-ledger', '<l1@x.com>', 'Intro to DentaTrak'),
    'm-excluded' => $goodMeta('t-excluded', '<e1@x.com>', 'Other campaign'),
], ['messages' => []]));
$r = $resolver->resolve($EMAIL);
ok('resolved via campaign_record', $r['status'] === 'resolved' && $r['source'] === 'campaign_record');
ok('thread fields populated', $r['thread_id'] === 't-campaign'
    && $r['rfc_message_id'] === '<c1@x.com>'
    && strpos($r['references'], '<c1@x.com>') !== false
    && strpos($r['references'], '<older@x.com>') !== false);
ok('subject becomes Re: original', $r['subject'] === 'Re: Intro to DentaTrak');

// ---- 2. falls through to send ledger when campaign msg gone ----------------
$resolver = new OutreachThreadResolver($pdo, $gmail([
    'm-ledger' => $goodMeta('t-ledger', '<l1@x.com>', 'Ledger intro'),
    // m-excluded intentionally absent -> 404, so m-ledger wins
], ['messages' => []]));
$r = $resolver->resolve($EMAIL);
if (getenv('MO_DEBUG')) var_export($r);
ok('falls back to send_ledger source', $r['status'] === 'resolved' && $r['source'] === 'send_ledger'
    && $r['thread_id'] === 't-ledger');

// ---- 3. excluded campaign ref is not "prior" -------------------------------
$pdo->prepare("DELETE FROM marketing_campaign_recipients WHERE campaign_id=?")->execute([$CMP]);
$pdo->prepare("DELETE FROM marketing_sends WHERE campaign_ref=?")->execute([$CMP]);
$resolver = new OutreachThreadResolver($pdo, $gmail([
    'm-excluded' => $goodMeta('t-excluded', '<e1@x.com>', 'Other'),
], ['messages' => [['id' => 'm-search', 'threadId' => 't-search']]]));
// list returns m-search whose lookup is not in msgMeta -> 404 -> unverifiable
$r = $resolver->resolve($EMAIL, [], $EXCL);
ok('excluded ref skipped; unverified search -> unverifiable', $r['status'] === 'unverifiable');

// ---- 4. sheet notes ids are candidates --------------------------------------
$resolver = new OutreachThreadResolver($pdo, $gmail([
    'm-sheet' => $goodMeta('t-sheet', '<s1@x.com>', 'Sheet outreach'),
], ['messages' => []]));
$r = $resolver->resolve($EMAIL, ['m-sheet']);
ok('resolved via sheet_notes', $r['status'] === 'resolved' && $r['source'] === 'sheet_notes'
    && $r['thread_id'] === 't-sheet');

// ---- 5. recipient mismatch -> not selected ----------------------------------
$resolver = new OutreachThreadResolver($pdo, $gmail([
    'm-other' => $goodMeta('t-other', '<o1@x.com>', 'Wrong person', 'someoneelse@example.com'),
], ['messages' => []]));
$r = $resolver->resolve($EMAIL, ['m-other'], $EXCL);
ok('message to a different recipient rejected', $r['status'] === 'unverifiable'
    && $r['reason'] === 'prior_message_recipient_mismatch');

// ---- 6. gmail search single thread -> resolved ------------------------------
$meta = ['m-search' => $goodMeta('t-search', '<s9@x.com>', 'Found via search')];
$resolver = new OutreachThreadResolver($pdo, $gmail($meta, [
    'messages' => [['id' => 'm-search', 'threadId' => 't-search']],
]));
$r = $resolver->resolve($EMAIL);
ok('single sent-mail thread resolved via gmail_search', $r['status'] === 'resolved'
    && $r['source'] === 'gmail_search' && $r['thread_id'] === 't-search');

// ---- 7. multiple threads -> ambiguous, none selected --------------------------
$resolver = new OutreachThreadResolver($pdo, $gmail($meta, [
    'messages' => [['id' => 'm-search', 'threadId' => 't-search'],
                   ['id' => 'm-other2', 'threadId' => 't-other2']],
]));
$r = $resolver->resolve($EMAIL);
ok('multiple conversations -> ambiguous (no guess)', $r['status'] === 'ambiguous'
    && $r['thread_id'] === '');

// ---- 8. lookup outage -> unverifiable, never a guess ---------------------------
$resolver = new OutreachThreadResolver($pdo, $gmail([], [], null));
$r = $resolver->resolve($EMAIL);
ok('gmail outage -> unverifiable/unresolved, no thread chosen',
    in_array($r['status'], ['unverifiable', 'unresolved'], true) && $r['thread_id'] === '');

// ---- 9. no history at all -> unresolved ----------------------------------------
$resolver = new OutreachThreadResolver($pdo, $gmail([], ['messages' => []]));
$r = $resolver->resolve('never-contacted@example.com');
ok('no prior outreach -> unresolved', $r['status'] === 'unresolved' && $r['reason'] === 'no_prior_message_found');

// ---- 10. replySubject helper ----------------------------------------------------
ok('replySubject adds Re: once', OutreachThreadResolver::replySubject('Hello') === 'Re: Hello'
    && OutreachThreadResolver::replySubject('re: Hello') === 're: Hello'
    && OutreachThreadResolver::replySubject("Hi\r\nthere") === 'Re: Hi there');

// cleanup
$pdo->prepare("DELETE FROM marketing_campaign_recipients WHERE campaign_id=?")->execute([$CMP]);
$pdo->prepare("DELETE FROM marketing_sends WHERE campaign_ref IN (?,?)")->execute([$CMP, $EXCL]);
$pdo->prepare("DELETE FROM marketing_campaigns WHERE id IN (?,?)")->execute([$CMP, $EXCL]);

echo "\nmarketing-thread-test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
