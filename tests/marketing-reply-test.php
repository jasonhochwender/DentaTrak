<?php
/**
 * Marketing reply-tracking tests (ReplyTracker).
 *
 * Covers classification rules, thread/RFC/email matching, ambiguity,
 * idempotent recording, CRM side-effects (responded flag, review flags,
 * prospect events), sync checkpoint behavior, and dashboard stats.
 *
 * Runs against the LOCAL development database (port 3308 via .env_mode).
 * Synthetic fixtures only (@replytest.* / REPLYTEST% names) and self-cleaning.
 * Gmail is fully mocked — no network, no sends.
 *
 * Run: php tests/marketing-reply-test.php
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/..';
require_once $baseDir . '/api/appConfig.php';
require_once $baseDir . '/api/feature-flags.php';
require_once $baseDir . '/api/marketing/ProspectCrmAdmin.php';
require_once $baseDir . '/api/marketing/ReplyTracker.php';

if (($appConfig['current_environment'] ?? '') !== 'development') {
    fwrite(STDERR, "REFUSING: not a development environment.\n");
    exit(1);
}
global $pdo;

require_once $baseDir . '/migrations/2026_11_20_marketing_reply_tracking.php';
require_once $baseDir . '/migrations/2026_11_21_marketing_reply_suppression.php';
require_once $baseDir . '/migrations/2026_11_29_marketing_bounce_holds.php';
require_once $baseDir . '/api/marketing/ProspectCrm.php';
require_once $baseDir . '/api/marketing/OutreachEligibility.php';
require_once $baseDir . '/api/marketing/MarketingMailer.php';
require_once $baseDir . '/api/marketing/CampaignSendSupport.php';
require_once $baseDir . '/api/marketing/CampaignSendRunner.php';
require_once $baseDir . '/api/marketing/MarketingSendingPolicy.php';

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}
$cnt = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

/* ---------------- cleanup ---------------- */
function cleanupReply(): void {
    global $pdo;
    $pdo->exec("DELETE FROM marketing_reply_events WHERE from_email LIKE '%@replytest%' OR gmail_message_id LIKE 'rt%' OR gmail_message_id LIKE 'm-rec-%' OR gmail_message_id LIKE 'm1%' OR gmail_message_id LIKE 'm-%'");
    $pdo->exec("DELETE r FROM marketing_campaign_recipients r JOIN marketing_campaigns c ON c.id=r.campaign_id WHERE c.name LIKE 'REPLYTEST%'");
    $pdo->exec("DELETE FROM marketing_campaigns WHERE name LIKE 'REPLYTEST%'");
    $m = "c.email_normalized LIKE '%@replytest%' OR c.full_name LIKE 'REPLYTEST%' OR o.name LIKE 'REPLYTEST%'";
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id LEFT JOIN marketing_orgs o ON o.id=c.org_id WHERE $m");
    $pdo->exec("DELETE e FROM marketing_prospect_events e JOIN marketing_orgs o ON o.id=e.org_id WHERE o.name LIKE 'REPLYTEST%'");
    $pdo->exec("UPDATE marketing_import_rows ir JOIN marketing_contacts c ON c.id=ir.contact_id LEFT JOIN marketing_orgs o ON o.id=c.org_id SET ir.contact_id=NULL, ir.org_id=NULL WHERE $m");
    $pdo->exec("UPDATE marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id LEFT JOIN marketing_orgs o ON o.id=c.org_id SET e.contact_id=NULL, e.org_id=NULL WHERE $m");
    $pdo->exec("UPDATE marketing_campaign_recipients r JOIN marketing_contacts c ON c.id=r.contact_id LEFT JOIN marketing_orgs o ON o.id=c.org_id SET r.contact_id=NULL WHERE $m");
    $pdo->exec("DELETE c FROM marketing_contacts c LEFT JOIN marketing_orgs o ON o.id=c.org_id WHERE $m");
    $pdo->exec("UPDATE marketing_orgs SET parent_org_id=NULL WHERE name LIKE 'REPLYTEST%'");
    $pdo->exec("DELETE FROM marketing_orgs WHERE name LIKE 'REPLYTEST%'");
    $pdo->exec("DELETE q FROM marketing_sync_queue q JOIN marketing_suppressions s ON s.id=q.suppression_id WHERE s.email_normalized LIKE '%@replytest%'");
    $pdo->exec("DELETE FROM marketing_suppressions WHERE email_normalized LIKE '%@replytest%'");
    $pdo->exec("DELETE FROM marketing_email_holds WHERE email_normalized LIKE '%@replytest%'");
    $pdo->exec("DELETE FROM marketing_sends WHERE email_normalized LIKE '%@replytest%'");
    $pdo->exec("DELETE FROM marketing_settings WHERE sk LIKE 'reply_sync%'");
}
cleanupReply();

/* ---------------- fixtures ---------------- */
$admin = new ProspectCrmAdmin($pdo, 'replytest-admin@example.com');
$org = $admin->createOrg(['name' => 'REPLYTEST Dental', 'org_type' => 'dental practice']);
$org2 = $admin->createOrg(['name' => 'REPLYTEST Lab', 'org_type' => 'dental lab']);

$cHuman = $admin->createContact(['full_name' => 'REPLYTEST Human', 'email' => 'human@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
$cAuto   = $admin->createContact(['full_name' => 'REPLYTEST Auto', 'email' => 'auto@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
$cBounce = $admin->createContact(['full_name' => 'REPLYTEST Bounce', 'email' => 'bounce@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
$cUnsub  = $admin->createContact(['full_name' => 'REPLYTEST Unsub', 'email' => 'unsub@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
// Shared email: two contacts, same address -> ambiguous.
$cSharedA = $admin->createContact(['full_name' => 'REPLYTEST Shared A', 'email' => 'shared@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
$cSharedB = $admin->createContact(['full_name' => 'REPLYTEST Shared B', 'email' => 'shared@replytest.example',
    'org_id' => $org2['id'], 'status' => 'Contacted']);
$cTemp = $admin->createContact(['full_name' => 'REPLYTEST Temp', 'email' => 'temp@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
$cAmb  = $admin->createContact(['full_name' => 'REPLYTEST Amb', 'email' => 'amb@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
// Already-responded contact: response must be preserved.
$cDone = $admin->createContact(['full_name' => 'REPLYTEST Done', 'email' => 'done@replytest.example',
    'org_id' => $org['id'], 'status' => 'Contacted']);
$pdo->prepare("UPDATE marketing_contacts SET response='Interested' WHERE id=?")->execute([$cDone['id']]);

// Campaign + recipients with thread identifiers.
$cid = 'rt-campaign-0001-0000-000000000001';
$pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status,created_by,recipient_count)
    VALUES (?, 'REPLYTEST Campaign','initial','completed','replytest',4)")->execute([$cid]);
$mkRecip = function (string $email, ?int $contactId, ?string $thread, ?string $rfc) use ($pdo, $cid) {
    $pdo->prepare("INSERT INTO marketing_campaign_recipients
        (campaign_id, prospect_email, contact_id, status, gmail_thread_id, thread_rfc_message_id, sent_at)
        VALUES (?,?,?,'sent',?,?,UTC_TIMESTAMP())")
        ->execute([$cid, $email, $contactId, $thread, $rfc]);
};
$mkRecip('human@replytest.example', $cHuman['id'], 'rt-thread-human', '<orig-human@x>');
$mkRecip('auto@replytest.example', $cAuto['id'], 'rt-thread-auto', '<orig-auto@x>');
$mkRecip('bounce@replytest.example', $cBounce['id'], 'rt-thread-bounce', '<orig-bounce@x>');
$mkRecip('shared@replytest.example', $cSharedA['id'], 'rt-thread-shared', '<orig-shared@x>');

/* ---------------- fake Gmail ---------------- */
$msgs = [];
$history = ['historyId' => '200', 'history' => []];
$profile = ['status' => 200, 'data' => ['emailAddress' => 'jason.hochwender@dentatrak.com', 'historyId' => '100']];

function gmailMsg(string $id, string $threadId, array $headers, string $text = '', array $labels = ['INBOX']): array {
    $hs = [];
    foreach ($headers as $k => $v) $hs[] = ['name' => $k, 'value' => $v];
    return ['id' => $id, 'threadId' => $threadId, 'labelIds' => $labels,
        'internalDate' => (string)(time() * 1000),
        'payload' => ['mimeType' => 'text/plain',
            'headers' => $hs,
            'body' => ['data' => base64_encode(strtr($text, '+/', '-_'))]]];
}

$addMsg = function (string $id, string $threadId, string $from, string $subject, string $text = '', array $extra = [], array $labels = ['INBOX']) use (&$msgs) {
    $h = ['From' => $from, 'To' => 'jason.hochwender@dentatrak.com', 'Subject' => $subject,
          'Date' => 'Thu, 20 Nov 2026 10:00:00 +0000', 'Message-ID' => '<' . $id . '@reply>'] + $extra;
    $msgs[$id] = gmailMsg($id, $threadId, $h, $text, $labels);
};
$addHistory = function (string $id, string $threadId) use (&$history) {
    $history['history'][] = ['messagesAdded' => [['message' => ['id' => $id, 'threadId' => $threadId]]]];
};

$gmailGet = function (string $path, array $q = []) use (&$msgs, &$history, &$profile) {
    if ($path === '/profile') return $profile;
    if ($path === '/history') return ['status' => 200, 'data' => $history];
    if ($path === '/messages') {
        return ['status' => 200, 'data' => ['messages' => array_map(
            fn($id) => ['id' => $id, 'threadId' => $msgs[$id]['threadId'] ?? ''], array_keys($msgs))]];
    }
    if (preg_match('#^/messages/([A-Za-z0-9_-]+)$#', $path, $m)) {
        $id = $m[1];
        if (isset($msgs[$id])) return ['status' => 200, 'data' => $msgs[$id]];
        return ['status' => 404, 'data' => []];
    }
    return ['status' => 404, 'data' => []];
};

$tracker = new ReplyTracker($pdo, $gmailGet, 'replytest');

/* ---------------- 1. classification (pure) ---------------- */
ok('classify human', $tracker->classify(['from_email' => 'a@b.com', 'subject' => 'Re: Hi', 'text' => 'Sounds good, tell me more.']) === 'human');
ok('classify OOO via Auto-Submitted', $tracker->classify(['from_email' => 'a@b.com', 'subject' => 'Re: Hi', 'auto_submitted' => 'auto-replied', 'text' => 'I am out']) === 'auto_response');
ok('classify OOO via subject', $tracker->classify(['from_email' => 'a@b.com', 'subject' => 'Automatic reply: Out of Office', 'text' => '']) === 'auto_response');
ok('classify bounce via mailer-daemon', $tracker->classify(['from_email' => 'mailer-daemon@google.com', 'subject' => 'Delivery Status Notification', 'text' => '']) === 'delivery_failure');
ok('classify bounce via subject', $tracker->classify(['from_email' => 'noreply@x.com', 'subject' => 'Undeliverable: your message', 'text' => '']) === 'delivery_failure');
ok('classify unsubscribe via body', $tracker->classify(['from_email' => 'a@b.com', 'subject' => 'Re: Hi', 'text' => 'Please unsubscribe me from these emails.']) === 'unsubscribe_request');
ok('classify unsubscribe via subject', $tracker->classify(['from_email' => 'a@b.com', 'subject' => 'Stop emailing me', 'text' => '']) === 'unsubscribe_request');

/* ---------------- 2. matching ---------------- */
$msgHuman = $tracker->fetchMessage ?? null; // fetchMessage needs gmailGet; build via process path below.
// Build normalized messages directly for matchMessage tests.
$norm = function (string $id, string $thread, string $from, array $extra = [], array $labels = ['INBOX']) {
    return ['id' => $id, 'threadId' => $thread, 'labels' => $labels, 'from_email' => $from,
        'from_name' => null, 'subject' => 'Re: x', 'text' => '', 'internal_ts' => time() * 1000] + $extra;
};

$mh = $tracker->matchMessage($norm('m1', 'rt-thread-human', 'human@replytest.example'));
ok('thread match -> contact+campaign', $mh['matched'] && $mh['contact_id'] === $cHuman['id'] && $mh['campaign_id'] === $cid && $mh['via'] === 'thread_id');
$mr = $tracker->matchMessage($norm('m2', 'other-thread', 'someone@replytest.example', ['in_reply_to' => '<orig-human@x>']));
ok('rfc header match', $mr['matched'] && $mr['contact_id'] === $cHuman['id'] && $mr['via'] === 'rfc_header');
$me = $tracker->matchMessage($norm('m3', 'unknown-thread', 'done@replytest.example'));
ok('email match fallback', $me['matched'] && $me['contact_id'] === $cDone['id'] && $me['via'] === 'email');
$ms = $tracker->matchMessage($norm('m4', 'unknown-thread', 'shared@replytest.example'));
ok('shared email -> ambiguous, no contact', $ms['matched'] && $ms['ambiguous'] && $ms['contact_id'] === null);
$md = $tracker->matchMessage($norm('m5', 'rt-thread-human', 'human@replytest.example', [], ['DRAFT']));
ok('draft never matches', !$md['matched']);
$mo = $tracker->matchMessage($norm('m6', 'rt-thread-human', 'jason.hochwender@dentatrak.com'));
ok('own address never matches', !$mo['matched']);
$mu = $tracker->matchMessage($norm('m7', 'random', 'stranger@example.com'));
ok('unknown sender never matches', !$mu['matched']);

/* ---------------- 3. record + idempotency + CRM effects ---------------- */
$before = $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE event_type='reply_received'");
$rec = $tracker->record($norm('m-rec-1', 'rt-thread-human', 'human@replytest.example',
    ['subject' => 'Re: your note', 'text' => 'Yes, interested — send details.', 'internal_ts' => time() * 1000]),
    $mh, 'human');
ok('record inserts', $rec === true);
ok('record idempotent', $tracker->record($norm('m-rec-1', 'rt-thread-human', 'human@replytest.example'), $mh, 'human') === false);
ok('reply event row exists', $cnt("SELECT COUNT(*) FROM marketing_reply_events WHERE gmail_message_id='m-rec-1'") === 1);
$resp = $pdo->query("SELECT response FROM marketing_contacts WHERE id=" . $cHuman['id'])->fetchColumn();
ok('human reply sets response=replied', $resp === 'replied');
$ev = $cnt("SELECT COUNT(*) FROM marketing_prospect_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE e.event_type='reply_received' AND c.email_normalized='human@replytest.example'");
ok('reply_received event recorded once', $ev === 1);

// OOO does not mark responded.
$tracker->record($norm('m-rec-2', 'rt-thread-auto', 'auto@replytest.example'),
    $tracker->matchMessage($norm('m-rec-2', 'rt-thread-auto', 'auto@replytest.example')), 'auto_response');
$respAuto = $pdo->query("SELECT response FROM marketing_contacts WHERE id=" . $cAuto['id'])->fetchColumn();
ok('auto-reply does not set responded', ($respAuto === null || $respAuto === '' || $respAuto === 'none' || $respAuto === 'No Response'));

// Bounce flags review.
$tracker->record($norm('m-rec-3', 'rt-thread-bounce', 'mailer-daemon@google.com', ['subject' => 'Delivery Status Notification']),
    ['matched' => true, 'via' => 'thread_id', 'contact_id' => $cBounce['id'], 'campaign_id' => $cid,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'delivery_failure');
$nr = $pdo->query("SELECT needs_review FROM marketing_contacts WHERE id=" . $cBounce['id'])->fetchColumn();
ok('bounce flags needs_review', (int)$nr === 1);

// Unsubscribe flags review AND auto-suppresses (high-confidence, own address).
$tracker->record($norm('m-rec-4', 'x', 'unsub@replytest.example', ['subject' => 'Re: hi', 'text' => 'unsubscribe me please']),
    ['matched' => true, 'via' => 'email', 'contact_id' => $cUnsub['id'], 'campaign_id' => null,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'unsubscribe_request',
    ['class' => 'unsubscribe_request', 'bounce_kind' => null, 'unsubscribe_confidence' => 'high',
     'failed_recipient' => null]);
$nrU = $pdo->query("SELECT needs_review, review_reason FROM marketing_contacts WHERE id=" . $cUnsub['id'])->fetch(PDO::FETCH_ASSOC);
ok('unsubscribe flags needs_review', (int)$nrU['needs_review'] === 1 && str_contains((string)$nrU['review_reason'], 'unsubscribe'));
$sup = $pdo->query("SELECT source FROM marketing_suppressions WHERE email_normalized='unsub@replytest.example'")->fetchColumn();
ok('high-confidence unsubscribe auto-suppresses', $sup === 'reply_unsubscribe');
$act = $pdo->query("SELECT action_taken FROM marketing_reply_events WHERE gmail_message_id='m-rec-4'")->fetchColumn();
ok('action_taken=suppressed recorded', $act === 'suppressed');
// Idempotent: a second unsubscribe event does not duplicate the suppression.
$tracker->record($norm('m-rec-4b', 'x', 'unsub@replytest.example', ['subject' => 'Re: hi', 'text' => 'unsubscribe me']),
    ['matched' => true, 'via' => 'email', 'contact_id' => $cUnsub['id'], 'campaign_id' => null,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'unsubscribe_request',
    ['class' => 'unsubscribe_request', 'bounce_kind' => null, 'unsubscribe_confidence' => 'high',
     'failed_recipient' => null]);
ok('suppression idempotent', $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized='unsub@replytest.example'") === 1);

// Existing response preserved.
$tracker->record($norm('m-rec-5', 'x', 'done@replytest.example'),
    ['matched' => true, 'via' => 'email', 'contact_id' => $cDone['id'], 'campaign_id' => null,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'human');
$respDone = $pdo->query("SELECT response FROM marketing_contacts WHERE id=" . $cDone['id'])->fetchColumn();
ok('existing response preserved', $respDone === 'Interested');

/* ---------------- 3b. unsubscribe confidence + forwarded sender ---------------- */
ok('unsub confidence high', $tracker->unsubscribeConfidence(
    ['subject' => 'Re: hi', 'text' => 'Please unsubscribe me from this list.']) === 'high');
ok('unsub confidence review (question)', $tracker->unsubscribeConfidence(
    ['subject' => 'Re: hi', 'text' => 'How do I unsubscribe from these emails?']) === 'review');
ok('unsub confidence review (negation)', $tracker->unsubscribeConfidence(
    ['subject' => 'Re: hi', 'text' => 'Please don\'t stop sending me information.']) === 'review');
// Third-party/forwarded sender: high-confidence words but From != contact email.
$tracker->record($norm('m-rec-6', 'x', 'assistant@other-example.com',
    ['subject' => 'Fwd: unsubscribe me', 'text' => 'Forwarding: they asked me to unsubscribe me']),
    ['matched' => true, 'via' => 'thread_id', 'contact_id' => $cAuto['id'], 'campaign_id' => null,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'unsubscribe_request',
    ['class' => 'unsubscribe_request', 'bounce_kind' => null, 'unsubscribe_confidence' => 'high',
     'failed_recipient' => null]);
ok('forwarded unsubscribe NOT suppressed', $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized='auto@replytest.example'") === 0);
ok('forwarded unsubscribe action=review', $pdo->query("SELECT action_taken FROM marketing_reply_events WHERE gmail_message_id='m-rec-6'")->fetchColumn() === 'review');

/* ---------------- 3c. bounce kinds + suppression ---------------- */
ok('bounceKind permanent 5.1.1', $tracker->bounceKind(['text' => "Diagnostic-Code: smtp; 550 5.1.1 user unknown"]) === 'permanent');
ok('bounceKind temporary 4.2.2', $tracker->bounceKind(['text' => "Diagnostic-Code: smtp; 452 4.2.2 mailbox full"]) === 'temporary');
ok('bounceKind temp phrase', $tracker->bounceKind(['text' => 'The mailbox is full, try again later']) === 'temporary');
ok('bounceKind ambiguous', $tracker->bounceKind(['text' => 'Delivery delayed']) === 'ambiguous');
ok('failedRecipient extracts', $tracker->failedRecipient(['text' => 'Final-Recipient: rfc822;bad@replytest.example']) === 'bad@replytest.example');

// Permanent bounce with identified recipient = contact email -> suppress.
$tracker->record($norm('m-rec-7', 'rt-thread-bounce', 'mailer-daemon@google.com',
    ['subject' => 'Delivery Status Notification', 'text' => "550 5.1.1 user unknown\nFinal-Recipient: rfc822;bounce@replytest.example"]),
    ['matched' => true, 'via' => 'thread_id', 'contact_id' => $cBounce['id'], 'campaign_id' => $cid,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'delivery_failure',
    ['class' => 'delivery_failure', 'bounce_kind' => 'permanent', 'unsubscribe_confidence' => null,
     'failed_recipient' => 'bounce@replytest.example']);
$bsup = $pdo->query("SELECT source FROM marketing_suppressions WHERE email_normalized='bounce@replytest.example'")->fetchColumn();
ok('permanent bounce suppresses', $bsup === 'bounce_permanent');
ok('permanent bounce action=suppressed', $pdo->query("SELECT action_taken FROM marketing_reply_events WHERE gmail_message_id='m-rec-7'")->fetchColumn() === 'suppressed');
ok('bounce_kind persisted', $pdo->query("SELECT bounce_kind FROM marketing_reply_events WHERE gmail_message_id='m-rec-7'")->fetchColumn() === 'permanent');

// Temporary bounce -> expiring hold, never the permanent store.
$tracker->record($norm('m-rec-8', 'x', 'mailer-daemon@google.com',
    ['subject' => 'Delivery Status Notification', 'text' => "452 4.2.2 mailbox full\nFinal-Recipient: rfc822;temp@replytest.example"]),
    ['matched' => true, 'via' => 'email', 'contact_id' => $cTemp['id'], 'campaign_id' => null,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'delivery_failure',
    ['class' => 'delivery_failure', 'bounce_kind' => 'temporary', 'unsubscribe_confidence' => null,
     'failed_recipient' => 'temp@replytest.example']);
$tsup = $pdo->query("SELECT source FROM marketing_suppressions WHERE email_normalized='temp@replytest.example'")->fetchColumn();
ok('temporary bounce creates NO permanent suppression', $tsup === false);
ok('temporary bounce creates expiring hold', $pdo->query("SELECT source FROM marketing_email_holds WHERE email_normalized='temp@replytest.example' AND hold_until > UTC_TIMESTAMP()")->fetchColumn() === 'bounce_temporary');

// Ambiguous bounce -> review only, no suppression.
$tracker->record($norm('m-rec-9', 'x', 'mailer-daemon@google.com',
    ['subject' => 'Delivery Status Notification', 'text' => 'Delivery delayed']),
    ['matched' => true, 'via' => 'email', 'contact_id' => $cAmb['id'], 'campaign_id' => null,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'delivery_failure',
    ['class' => 'delivery_failure', 'bounce_kind' => 'ambiguous', 'unsubscribe_confidence' => null,
     'failed_recipient' => null]);
ok('ambiguous bounce NOT suppressed', $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized='amb@replytest.example'") === 0);
ok('ambiguous bounce action=review', $pdo->query("SELECT action_taken FROM marketing_reply_events WHERE gmail_message_id='m-rec-9'")->fetchColumn() === 'review');

/* ---------------- 4. sync() checkpoint + history ---------------- */
// First run: initializes checkpoint, processes nothing.
$history['history'] = []; $history['historyId'] = '150';
$r1 = $tracker->sync();
ok('first sync initializes', !empty($r1['ok']) && !empty($r1['initialized']));
ok('checkpoint stored', $pdo->query("SELECT sval FROM marketing_settings WHERE sk='reply_sync_history_id'")->fetchColumn() === '100');

// Second run: history adds two messages (human reply + OOO).
$addMsg('rt-msg-1', 'rt-thread-human', 'human@replytest.example', 'Re: Checking in', 'Great, send me pricing.');
$addMsg('rt-msg-2', 'rt-thread-auto', 'auto@replytest.example', 'Automatic reply: Out of Office', '', ['Auto-Submitted' => 'auto-replied']);
$addHistory('rt-msg-1', 'rt-thread-human');
$addHistory('rt-msg-2', 'rt-thread-auto');
$history['historyId'] = '200';
$r2 = $tracker->sync();
ok('second sync processes', !empty($r2['ok']) && ($r2['processed'] ?? 0) === 2);
ok('checkpoint advanced', $pdo->query("SELECT sval FROM marketing_settings WHERE sk='reply_sync_history_id'")->fetchColumn() === '200');
$cls = $pdo->query("SELECT classification FROM marketing_reply_events WHERE gmail_message_id='rt-msg-1'")->fetchColumn();
ok('rt-msg-1 classified human', $cls === 'human');
$cls2 = $pdo->query("SELECT classification FROM marketing_reply_events WHERE gmail_message_id='rt-msg-2'")->fetchColumn();
ok('rt-msg-2 classified auto_response', $cls2 === 'auto_response');
ok('gmail url stored', (bool)$pdo->query("SELECT gmail_url FROM marketing_reply_events WHERE gmail_message_id='rt-msg-1'")->fetchColumn());

// Re-run same history -> idempotent, no duplicates.
$before2 = $cnt("SELECT COUNT(*) FROM marketing_reply_events WHERE gmail_message_id LIKE 'rt-msg-%'");
$history['history'] = []; // history.list with same startHistoryId returns new records only in reality;
// simulate a retry by replaying the same history records:
$addHistory('rt-msg-1', 'rt-thread-human'); $addHistory('rt-msg-2', 'rt-thread-auto');
$r3 = $tracker->sync();
$after2 = $cnt("SELECT COUNT(*) FROM marketing_reply_events WHERE gmail_message_id LIKE 'rt-msg-%'");
ok('re-sync does not duplicate', $after2 === $before2 && $after2 === 2);

/* ---------------- 5. dashboardStats ---------------- */
$stats = $tracker->dashboardStats(gmdate('Y-m-d', strtotime('-1 day')), gmdate('Y-m-d', strtotime('+1 day')));
ok('dashboardStats human count', ($stats['human'] ?? 0) >= 2);
ok('dashboardStats auto count', ($stats['auto_response'] ?? 0) >= 1);
ok('dashboardStats new_review', ($stats['new_review'] ?? 0) >= 4);
ok('dashboardStats per_campaign', isset($stats['per_campaign'][$cid]['human']));
ok('dashboardStats cohort replies', ($stats['cohort_replies'][$cid] ?? 0) >= 1);
ok('dashboardStats bounce kinds', ($stats['bounce_permanent'] ?? 0) >= 1 && ($stats['bounce_temporary'] ?? 0) >= 1);
ok('dashboardStats suppressed contacts', ($stats['suppressed_contacts'] ?? 0) >= 2);

/* ---------------- 5b. backfill dry-run purity ---------------- */
// Add a permanent-bounce message to the fake mailbox so the dry-run has a
// suppression proposal to report.
$addMsg('rt-msg-3', 'rt-thread-bounce', 'mailer-daemon@google.com', 'Delivery Status Notification',
    "550 5.1.1 user unknown\nFinal-Recipient: rfc822;bounce@replytest.example");
$beforeBackfill = $cnt("SELECT COUNT(*) FROM marketing_reply_events");
$beforeSup = $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized LIKE '%@replytest%'");
$bf = $tracker->backfill(14, true);
ok('backfill dry-run ok', !empty($bf['ok']) && !empty($bf['dry_run']));
ok('backfill scanned messages', ($bf['scanned'] ?? 0) > 0);
ok('backfill proposes suppressions', count($bf['proposed_suppressions'] ?? []) >= 1);
ok('dry-run writes no reply events', $cnt("SELECT COUNT(*) FROM marketing_reply_events") === $beforeBackfill);
ok('dry-run writes no suppressions', $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized LIKE '%@replytest%'") === $beforeSup);
ok('dry-run reports duplicates', isset($bf['already_tracked']));

/* ---------------- 6. review workflow ---------------- */
$rid = (int)$pdo->query("SELECT id FROM marketing_reply_events WHERE gmail_message_id='rt-msg-1'")->fetchColumn();
ok('markReviewed works', $tracker->markReviewed($rid, 'replytest-admin@example.com'));
$st = $pdo->query("SELECT review_status, reviewed_by FROM marketing_reply_events WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
ok('review status persisted', $st['review_status'] === 'reviewed' && $st['reviewed_by'] === 'replytest-admin@example.com');
$list = $tracker->listReplies(['status' => 'reviewed']);
ok('listReplies filters', count(array_filter($list, fn($r) => (int)$r['id'] === $rid)) === 1);

/* ---------------- 6b. automatic bounce handling ---------------- */
$svcT = fn() => new SuppressionService($pdo);
$evRow = fn(string $mid) => $pdo->query("SELECT * FROM marketing_reply_events WHERE gmail_message_id=" . $pdo->quote($mid))->fetch(PDO::FETCH_ASSOC);
$contact = fn(int $id) => $pdo->query("SELECT * FROM marketing_contacts WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
$bounceMatch = fn(int $cidX) => ['matched' => true, 'via' => 'email', 'contact_id' => $cidX, 'campaign_id' => null,
    'campaign_recipient_id' => null, 'ambiguous' => false];
$recBounce = function (string $mid, int $cidX, string $kind, ?string $failed, string $text = 'x') use ($tracker, $norm, $bounceMatch) {
    return $tracker->record($norm($mid, 'x', 'mailer-daemon@google.com', ['subject' => 'Delivery Status Notification', 'text' => $text]),
        $bounceMatch($cidX), 'delivery_failure',
        ['class' => 'delivery_failure', 'bounce_kind' => $kind, 'unsubscribe_confidence' => null, 'failed_recipient' => $failed]);
};

// Classification hardening.
ok('5.2.2 mailbox full is temporary, not permanent', $tracker->bounceKind(['text' => '552 5.2.2 The email account is over quota']) === 'temporary');
ok('5.7.1 policy block is not a recipient failure', $tracker->bounceKind(['text' => '550 5.7.1 Message rejected as spam; user unknown permanently']) === 'ambiguous');
ok('5.7.26 auth failure is not a recipient failure', $tracker->bounceKind(['text' => '550 5.7.26 unauthenticated mail, failed permanently']) === 'ambiguous');
ok('5.1.1 still permanent', $tracker->bounceKind(['text' => '550 5.1.1 The email account that you tried to reach does not exist']) === 'permanent');

// Permanent (m-rec-7 above): auto-resolved, CRM synced, org untouched.
$e7 = $evRow('m-rec-7');
ok('permanent bounce auto-resolved', $e7['review_status'] === 'reviewed' && str_starts_with((string)$e7['reviewed_by'], 'auto'));
ok('permanent bounce marked processed', $e7['bounce_processed_at'] !== null);
$cb = $contact((int)$cBounce['id']);
ok('CRM contact marked Bounced + dnc', $cb['status'] === 'Bounced' && (int)$cb['dnc'] === 1);
ok('CRM contact keeps org link', (int)$cb['org_id'] === (int)$org['id']);
ok('diagnostics preserved', $e7['failed_recipient'] === 'bounce@replytest.example' && $e7['bounce_kind'] === 'permanent');
ok('other contacts at org NOT suppressed', !$svcT()->isSuppressed('human@replytest.example') && (int)$contact((int)$cHuman['id'])['dnc'] === 0);
ok('no org-level suppression', $cnt("SELECT COUNT(*) FROM marketing_org_suppressions WHERE org_ref='replytest.example'") === 0);
ok('bounce audit event recorded', $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE contact_id=" . (int)$cBounce['id'] . " AND event_type='email_bounced'") === 1);
ok('suppression date + reason recorded', (bool)$pdo->query("SELECT unsubscribed_at IS NOT NULL AND source='bounce_permanent' FROM marketing_suppressions WHERE email_normalized='bounce@replytest.example'")->fetchColumn());

// Temporary (m-rec-8): hold only.
$e8 = $evRow('m-rec-8');
ok('temporary: no permanent suppression', $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized='temp@replytest.example'") === 0);
ok('temporary: send-blocked while held', $svcT()->isHeld('temp@replytest.example') && $svcT()->isBlocked('temp@replytest.example'));
ok('temporary: one-off auto-resolved', $e8['review_status'] === 'reviewed' && $e8['action_taken'] === 'held');
ok('temporary: contact not marked bounced', $contact((int)$cTemp['id'])['status'] === 'Contacted' && (int)$contact((int)$cTemp['id'])['dnc'] === 0);
$ctxH = mo_elig_ctx($pdo, $svcT(), 'temp@replytest.example');
ok('send ctx reports hold, not suppression', !empty($ctxH['held']) && empty($ctxH['suppressed']));
$elH = OutreachEligibility::evaluate(['byEmail' => []], 'temp@replytest.example', 'followup', $ctxH + ['require_in_sheet' => false]);
ok('eligibility: temp_hold blocks (hard)', !$elH['ok'] && in_array('temp_hold', $elH['flags'], true) && $elH['hard']);
ok('mailer-level eligibility blocks held address', empty($svcT()->eligibility(['temp@replytest.example'])[0]['eligible']));
// Expiry restores eligibility.
$pdo->exec("UPDATE marketing_email_holds SET hold_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE email_normalized='temp@replytest.example'");
ok('expired hold no longer blocks', !$svcT()->isHeld('temp@replytest.example') && !empty($svcT()->eligibility(['temp@replytest.example'])[0]['eligible']));
ok('expiry sweep marks expired', $svcT()->releaseExpiredHolds() >= 1
    && $pdo->query("SELECT status FROM marketing_email_holds WHERE email_normalized='temp@replytest.example'")->fetchColumn() === 'expired');
// Repeated temporary failures escalate.
$recBounce('m-rec-t2', (int)$cTemp['id'], 'temporary', 'temp@replytest.example');
ok('2nd temp failure: held again, not escalated', $svcT()->isHeld('temp@replytest.example') && $evRow('m-rec-t2')['review_status'] === 'reviewed');
$recBounce('m-rec-t3', (int)$cTemp['id'], 'temporary', 'temp@replytest.example');
$hr = $pdo->query("SELECT * FROM marketing_email_holds WHERE email_normalized='temp@replytest.example'")->fetch(PDO::FETCH_ASSOC);
ok('3rd temp failure escalates', $hr['status'] === 'escalated' && (int)$hr['fail_count'] === 3);
ok('escalated event stays in review queue', $evRow('m-rec-t3')['review_status'] === 'new');
ok('escalated hold is longer', strtotime($hr['hold_until']) - time() > 20 * 86400);
ok('escalation still not a permanent suppression', $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized='temp@replytest.example'") === 0);
ok('escalation flags contact for review', (int)$contact((int)$cTemp['id'])['needs_review'] === 1);

// Ambiguous with a known mailbox: review + hold, no suppression.
$recBounce('m-rec-a2', (int)$cAmb['id'], 'ambiguous', 'amb@replytest.example');
$ea = $evRow('m-rec-a2');
ok('ambiguous: not suppressed', !$svcT()->isSuppressed('amb@replytest.example'));
ok('ambiguous: needs review', $ea['review_status'] === 'new' && $ea['action_taken'] === 'review');
ok('ambiguous: held while unresolved', $svcT()->isHeld('amb@replytest.example'));
// Failed recipient that is NOT this contact: no action on anyone.
$recBounce('m-rec-a3', (int)$cAmb['id'], 'permanent', 'human@replytest.example');
ok('mismatched failed recipient: nothing suppressed', !$svcT()->isSuppressed('human@replytest.example') && $evRow('m-rec-a3')['review_status'] === 'new');

// Mark Reviewed: acknowledges only.
$supBefore = $cnt("SELECT COUNT(*) FROM marketing_suppressions");
ok('markReviewed ok', $tracker->markReviewed((int)$ea['id'], 'replytest-admin@example.com'));
ok('Mark Reviewed does not suppress', $cnt("SELECT COUNT(*) FROM marketing_suppressions") === $supBefore && !$svcT()->isSuppressed('amb@replytest.example'));
ok('Mark Reviewed leaves contact status alone', $contact((int)$cAmb['id'])['status'] === 'Contacted' && (int)$contact((int)$cAmb['id'])['dnc'] === 0);
ok('hold kept while another bounce for the address is unresolved', $svcT()->isHeld('amb@replytest.example'));
$tracker->markReviewed((int)$evRow('m-rec-9')['id'], 'replytest-admin@example.com');
ok('Mark Reviewed ends the review hold', !$svcT()->isHeld('amb@replytest.example'));
// ...but never a temporary-failure hold.
$tracker->markReviewed((int)$evRow('m-rec-t3')['id'], 'replytest-admin@example.com');
ok('Mark Reviewed keeps temporary hold', $svcT()->isHeld('temp@replytest.example'));

// Suppress Address (no review) then Suppress + Review.
$recBounce('m-rec-a4', (int)$cAmb['id'], 'ambiguous', 'amb@replytest.example');
$e4 = $evRow('m-rec-a4');
$r4 = $tracker->suppressFromEvent((int)$e4['id'], 'replytest-admin@example.com', false);
ok('Suppress Address suppresses', !empty($r4['ok']) && $svcT()->isSuppressed('amb@replytest.example') && empty($r4['already_suppressed']));
ok('Suppress Address source recorded', $pdo->query("SELECT source FROM marketing_suppressions WHERE email_normalized='amb@replytest.example'")->fetchColumn() === 'bounce_manual');
ok('Suppress Address syncs CRM', $contact((int)$cAmb['id'])['status'] === 'Bounced' && (int)$contact((int)$cAmb['id'])['dnc'] === 1);
ok('Suppress Address leaves review status', $evRow('m-rec-a4')['review_status'] === 'new');
ok('Suppress Address clears the hold', $cnt("SELECT COUNT(*) FROM marketing_email_holds WHERE email_normalized='amb@replytest.example' AND status IN ('active','escalated')") === 0);
$r4b = $tracker->suppressFromEvent((int)$e4['id'], 'replytest-admin@example.com', true);
ok('Suppress + Review is idempotent and reviews', !empty($r4b['ok']) && !empty($r4b['already_suppressed']) && !empty($r4b['reviewed'])
    && $evRow('m-rec-a4')['review_status'] === 'reviewed');
ok('no duplicate suppression rows', $cnt("SELECT COUNT(*) FROM marketing_suppressions WHERE email_normalized='amb@replytest.example'") === 1);
ok('no duplicate bounce audit events', $cnt("SELECT COUNT(*) FROM marketing_prospect_events WHERE contact_id=" . (int)$cAmb['id'] . " AND event_type='email_bounced'") === 1);
// A bounce with no identified recipient never suppresses the mail system.
$pdo->exec("UPDATE marketing_reply_events SET failed_recipient=NULL, contact_id=NULL WHERE gmail_message_id='m-rec-a3'");
$rNo = $tracker->suppressFromEvent((int)$evRow('m-rec-a3')['id'], 'replytest-admin@example.com', true);
ok('no address -> refused, mailer-daemon never suppressed', empty($rNo['ok']) && $rNo['error'] === 'no_address'
    && !$svcT()->isSuppressed('mailer-daemon@google.com'));

// listReplies exposes suppression + hold state for the failed address.
$lr = $tracker->listReplies(['ids' => [(int)$e7['id'], (int)$evRow('m-rec-t3')['id']]]);
$by = []; foreach ($lr as $x) $by[$x['gmail_message_id']] = $x;
ok('listReplies: suppressed state + source + date', ($by['m-rec-7']['suppressed_email'] ?? '') === 'bounce@replytest.example'
    && $by['m-rec-7']['suppression_source'] === 'bounce_permanent' && !empty($by['m-rec-7']['suppressed_at']));
ok('listReplies: hold state', !empty($by['m-rec-t3']['hold_until']) && (int)$by['m-rec-t3']['hold_escalated'] === 1 && empty($by['m-rec-t3']['suppressed_email']));

// CRM annotation: suppressed + held addresses are ineligible for every kind.
$crmE = new ProspectCrm($pdo);
foreach (['bounce@replytest.example' => 'suppressed', 'temp@replytest.example' => 'held'] as $em => $why) {
    $p = $crmE->findByEmail($em);
    ok("CRM eligibility excludes $why address", $p !== null && empty($p['eligibility']['initial']['ok']) && empty($p['eligibility']['followup']['ok']));
}

/* ---------------- 6c. historical reconciliation ---------------- */
$cH1 = $admin->createContact(['full_name' => 'REPLYTEST Hist Perm', 'email' => 'histperm@replytest.example', 'org_id' => $org['id'], 'status' => 'Contacted']);
$cH2 = $admin->createContact(['full_name' => 'REPLYTEST Hist Temp', 'email' => 'histtemp@replytest.example', 'org_id' => $org['id'], 'status' => 'Contacted']);
$cH3 = $admin->createContact(['full_name' => 'REPLYTEST Hist Amb', 'email' => 'histamb@replytest.example', 'org_id' => $org['id'], 'status' => 'Contacted']);
$cH4 = $admin->createContact(['full_name' => 'REPLYTEST Hist Signed', 'email' => 'histsigned@replytest.example', 'org_id' => $org['id'], 'status' => 'Signed Up']);
$hist = $pdo->prepare("INSERT INTO marketing_reply_events
    (gmail_message_id, gmail_thread_id, contact_id, matched_via, classification, review_status, from_email, subject, snippet,
     gmail_internal_date, bounce_kind, failed_recipient, action_taken)
    VALUES (?, 'x', ?, 'email', 'delivery_failure', ?, 'mailer-daemon@google.com', 'Delivery Status Notification', ?, ?, ?, ?, ?)");
$old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
$recent = gmdate('Y-m-d H:i:s', time() - 86400);
// Pre-automation permanent bounce: flagged but never suppressed.
$hist->execute(['m-hist-1', $cH1['id'], 'new', 'x', $old, 'permanent', 'histperm@replytest.example', 'review']);
// Legacy event with no stored analysis: classified from the snippet.
$hist->execute(['m-hist-2', $cH4['id'], 'new', "550 5.1.1 user unknown Final-Recipient: rfc822; histsigned@replytest.example", $old, null, null, null]);
$hist->execute(['m-hist-3', $cH2['id'], 'new', 'x', $old, 'temporary', 'histtemp@replytest.example', 'held']);
$hist->execute(['m-hist-4', $cH2['id'], 'new', 'x', $recent, 'temporary', 'histtemp@replytest.example', 'held']);
$hist->execute(['m-hist-5', $cH3['id'], 'new', 'x', $recent, 'ambiguous', null, 'review']);
$hist->execute(['m-hist-6', null, 'new', 'x', $recent, 'permanent', 'nobody@replytest.example', null]);
$pdo->exec("UPDATE marketing_reply_events SET bounce_processed_at=NULL WHERE gmail_message_id LIKE 'm-hist-%'");
$pdo->exec("UPDATE marketing_reply_events SET bounce_processed_at=UTC_TIMESTAMP() WHERE classification='delivery_failure' AND bounce_processed_at IS NULL AND gmail_message_id NOT LIKE 'm-hist-%' AND (gmail_message_id LIKE 'm-%' OR gmail_message_id LIKE 'rt%')");
$scope = "gmail_message_id LIKE 'm-hist-%'";
$otherUnprocessed = $cnt("SELECT COUNT(*) FROM marketing_reply_events WHERE classification='delivery_failure' AND bounce_processed_at IS NULL AND NOT ($scope)");
if ($otherUnprocessed > 0) {
    echo "SKIP: reconciliation tests (local DB has $otherUnprocessed unrelated unprocessed bounce events)\n";
} else {
    $supB = $cnt("SELECT COUNT(*) FROM marketing_suppressions");
    $dry = $tracker->reconcileBounces(false);
    ok('dry-run counts', $dry['reviewed'] === 6 && $dry['newly_suppressed'] === 2 && $dry['temporary'] === 2 && $dry['ambiguous'] === 1 && $dry['unmatched'] === 1);
    ok('dry-run writes nothing', $cnt("SELECT COUNT(*) FROM marketing_suppressions") === $supB
        && $cnt("SELECT COUNT(*) FROM marketing_reply_events WHERE $scope AND bounce_processed_at IS NOT NULL") === 0);
    $ap = $tracker->reconcileBounces(true);
    ok('apply counts', $ap['reviewed'] === 6 && $ap['newly_suppressed'] === 2 && $ap['temporary'] === 2 && $ap['ambiguous'] === 1
        && $ap['unmatched'] === 1 && $ap['errors'] === 0);
    ok('historical permanent suppressed + CRM + resolved', $svcT()->isSuppressed('histperm@replytest.example')
        && $contact((int)$cH1['id'])['status'] === 'Bounced' && $evRow('m-hist-1')['review_status'] === 'reviewed');
    ok('legacy event classified from snippet', $evRow('m-hist-2')['bounce_kind'] === 'permanent' && $svcT()->isSuppressed('histsigned@replytest.example'));
    ok('customer status preserved, still do-not-contact', $contact((int)$cH4['id'])['status'] === 'Signed Up' && (int)$contact((int)$cH4['id'])['dnc'] === 1);
    ok('historical temporary: never permanent', !$svcT()->isSuppressed('histtemp@replytest.example'));
    ok('hold anchored to failure date (recent one still active)', $svcT()->isHeld('histtemp@replytest.example') && $ap['holds_active'] === 1);
    ok('historical temporary resolved', $evRow('m-hist-3')['review_status'] === 'reviewed' && $evRow('m-hist-4')['review_status'] === 'reviewed');
    ok('historical ambiguous stays in review, unsuppressed', $evRow('m-hist-5')['review_status'] === 'new' && !$svcT()->isSuppressed('histamb@replytest.example'));
    ok('unmatched stays in review, nothing suppressed', $evRow('m-hist-6')['review_status'] === 'new' && !$svcT()->isSuppressed('nobody@replytest.example'));
    $evB = $cnt("SELECT COUNT(*) FROM marketing_prospect_events");
    $holdB = $pdo->query("SELECT fail_count FROM marketing_email_holds WHERE email_normalized='histtemp@replytest.example'")->fetchColumn();
    $again = $tracker->reconcileBounces(true);
    ok('rerun is a no-op', $again['reviewed'] === 0 && $cnt("SELECT COUNT(*) FROM marketing_suppressions") === $supB + 2
        && $cnt("SELECT COUNT(*) FROM marketing_prospect_events") === $evB
        && $pdo->query("SELECT fail_count FROM marketing_email_holds WHERE email_normalized='histtemp@replytest.example'")->fetchColumn() === $holdB);
}

/* ---------------- 6d. send-time gate (no mail leaves the test) ---------------- */
$sendCalls = 0;
$httpFake = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$sendCalls) {
    if (strpos($url, 'oauth2.googleapis.com/token') !== false) return ['status' => 200, 'body' => '{"access_token":"fake-at"}'];
    if (strpos($url, '/messages/send') !== false) { $sendCalls++; return ['status' => 200, 'body' => '{"id":"gm-rt","threadId":"gt-rt"}']; }
    if (strpos($url, '/messages?') !== false) return ['status' => 200, 'body' => '{"messages":[]}'];
    return ['status' => 404, 'body' => '{}'];
};
$envFn = static fn(string $k) => ['MARKETING_SENDING' => 'true', 'MARKETING_SEND_ALLOWLIST' => '*',
    'GOOGLE_CLIENT_ID' => 'cid', 'GOOGLE_CLIENT_SECRET' => 'sec', 'GMAIL_REFRESH_TOKEN' => 'rtok'][$k] ?? null;
$gateMailer = new MarketingMailer($pdo, 'https://dentatrak.test', [
    'policy' => new MarketingSendingPolicy($envFn), 'http' => $httpFake, 'env' => $envFn, 'clock' => fn() => time()]);
$gid = 'rt-campaign-0001-0000-000000000002';
$pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status,created_by,recipient_count)
    VALUES (?, 'REPLYTEST Gate','followup','approved','replytest',2)")->execute([$gid]);
foreach ([['temp@replytest.example', $cTemp['id']], ['bounce@replytest.example', $cBounce['id']]] as [$em, $cidX]) {
    $pdo->prepare("INSERT INTO marketing_campaign_recipients (campaign_id, prospect_email, contact_id, status, subject, body_html)
        VALUES (?,?,?,'approved','Hi','<p>x</p>')")->execute([$gid, $em, $cidX]);
}
putenv('MARKETING_CRM_READS=true');
$gateRunner = new CampaignSendRunner($pdo, 'https://dentatrak.test', $gateMailer);
$gateCamp = $pdo->query("SELECT * FROM marketing_campaigns WHERE id=" . $pdo->quote($gid))->fetch(PDO::FETCH_ASSOC);
$gres = $gateRunner->runBatch($gateCamp, $gateRunner->nextBatch($gid, 10, null, true));
$grow = fn(string $em) => $pdo->query("SELECT status, skip_reason FROM marketing_campaign_recipients WHERE campaign_id=" . $pdo->quote($gid) . " AND prospect_email=" . $pdo->quote($em))->fetch(PDO::FETCH_ASSOC);
ok('send gate: held address skipped as temp_hold (row preserved)', $grow('temp@replytest.example') == ['status' => 'skipped', 'skip_reason' => 'temp_hold'], json_encode($gres['results'] ?? null));
ok('send gate: suppressed address marked suppressed', $grow('bounce@replytest.example')['status'] === 'suppressed');
ok('send gate: nothing was sent', $sendCalls === 0 && (int)($gres['sent'] ?? 0) === 0);
// The mailer itself refuses a held address even if a caller skips the runner gate.
$direct = $gateMailer->send(['to' => 'temp@replytest.example', 'subject' => 'Hi', 'html' => '<p>x</p>', 'campaign' => 'replytest-direct']);
putenv('MARKETING_CRM_READS');
ok('mailer refuses held address directly', ($direct['status'] ?? '') === 'skipped_suppressed' && $sendCalls === 0, json_encode($direct));

/* ---------------- 7. lock behavior ---------------- */
// Named locks are per-connection; hold the lock on a SECOND PDO so the
// tracker's own connection cannot reacquire it.
$pdo2 = new PDO(
    'mysql:host=' . $appConfig['db_host'] . ';port=' . $appConfig['db_port'] . ';dbname=' . $appConfig['db_name'] . ';charset=utf8mb4',
    $appConfig['db_user'], $appConfig['db_password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo2->query("SELECT GET_LOCK('marketing_reply_sync',10)");
$locked = $tracker->sync();
ok('overlapping sync refused', !empty($locked['error']) && $locked['error'] === 'locked');
$pdo2->query("SELECT RELEASE_LOCK('marketing_reply_sync')");
unset($pdo2);

cleanupReply();
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
