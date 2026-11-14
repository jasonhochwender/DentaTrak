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

// Temporary bounce -> hold suppression.
$tracker->record($norm('m-rec-8', 'x', 'mailer-daemon@google.com',
    ['subject' => 'Delivery Status Notification', 'text' => "452 4.2.2 mailbox full\nFinal-Recipient: rfc822;temp@replytest.example"]),
    ['matched' => true, 'via' => 'email', 'contact_id' => $cTemp['id'], 'campaign_id' => null,
     'campaign_recipient_id' => null, 'ambiguous' => false], 'delivery_failure',
    ['class' => 'delivery_failure', 'bounce_kind' => 'temporary', 'unsubscribe_confidence' => null,
     'failed_recipient' => 'temp@replytest.example']);
$tsup = $pdo->query("SELECT source FROM marketing_suppressions WHERE email_normalized='temp@replytest.example'")->fetchColumn();
ok('temporary bounce creates hold', $tsup === 'bounce_temp_hold');

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
ok('dashboardStats suppressed contacts', ($stats['suppressed_contacts'] ?? 0) >= 3);

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
