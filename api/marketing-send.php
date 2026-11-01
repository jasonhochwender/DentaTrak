<?php
/**
 * Gated Gmail marketing send. X-Outreach-Token auth (fail closed).
 * POST {to, subject, html, text?, campaign}
 * - eligibility check immediately before send (email + org domain)
 * - per-recipient unsubscribe token + 10pt footer + RFC 8058 headers
 * - marketing_sends ledger: UNIQUE(campaign,email) = idempotent
 * LIMITATION: only guards sends through THIS endpoint; a manual Gmail
 * "Send" cannot be intercepted - drafts prepared elsewhere must be
 * sent via this path or not at all.
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/marketing/SuppressionService.php';
require_once __DIR__ . '/marketing/MarketingFooter.php';
header('Content-Type: application/json');
$expected = getEnvVar('OUTREACH_API_TOKEN');
if (!$expected || !hash_equals($expected, (string)($_SERVER['HTTP_X_OUTREACH_TOKEN'] ?? ''))) { http_response_code(403); exit('{}'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
global $pdo;
$in  = json_decode(file_get_contents('php://input'), true) ?? [];
$to  = SuppressionService::normalizeEmail((string)($in['to'] ?? ''));
$cmp = substr((string)($in['campaign'] ?? ''), 0, 160);
$sub = (string)($in['subject'] ?? ''); $html = (string)($in['html'] ?? ''); $text = (string)($in['text'] ?? '');
$svc = new SuppressionService($pdo);

if ($cmp === '') { http_response_code(400); echo json_encode(['error'=>'campaign required']); exit; }
// idempotency: claim the slot first
try {
    $pdo->prepare("INSERT INTO marketing_sends (campaign_ref,email_normalized,status) VALUES (?,?, 'sent')")
        ->execute([$cmp, $to]);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1062) { echo json_encode(['status'=>'already_recorded','email'=>$to]); exit; }
    throw $e;
}
$elig = $svc->eligibility([$to])[0];
if (!$elig['eligible']) {
    $pdo->prepare("UPDATE marketing_sends SET status='skipped_suppressed' WHERE campaign_ref=? AND email_normalized=?")->execute([$cmp,$to]);
    echo json_encode(['status'=>'skipped_suppressed','email'=>$to]); exit;
}
$tok = $svc->issueToken($to, $cmp);
$u   = rtrim($appConfig['baseUrl'], '/') . '/unsubscribe.php?t=' . $tok;
// gmail oauth
$rt = getEnvVar('GMAIL_REFRESH_TOKEN'); $cid = getEnvVar('GOOGLE_CLIENT_ID'); $csec = getEnvVar('GOOGLE_CLIENT_SECRET');
if (!$rt || !$cid || !$csec) { $pdo->prepare("UPDATE marketing_sends SET status='failed',gmail_message_id=NULL WHERE campaign_ref=? AND email_normalized=?")->execute([$cmp,$to]); http_response_code(500); exit('{"error":"gmail creds unset"}'); }
$tokR = json_decode(file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query(['client_id'=>$cid,'client_secret'=>$csec,'refresh_token'=>$rt,'grant_type'=>'refresh_token'])]])), true);
$at = $tokR['access_token'] ?? null;
if (!$at) { $pdo->prepare("UPDATE marketing_sends SET status='failed' WHERE campaign_ref=? AND email_normalized=?")->execute([$cmp,$to]); http_response_code(502); exit('{"error":"oauth failed"}'); }
// MIME with footer + List-Unsubscribe
$hdr = MarketingFooter::headers($u);
$mime = "To: $to\r\nSubject: $sub\r\nMIME-Version: 1.0\r\nList-Unsubscribe: {$hdr['List-Unsubscribe']}\r\nList-Unsubscribe-Post: {$hdr['List-Unsubscribe-Post']}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
      . $html . MarketingFooter::html($u);
$raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
$r = file_get_contents('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', false, stream_context_create(['http'=>['method'=>'POST','header'=>"Authorization: Bearer $at\r\nContent-Type: application/json",'content'=>json_encode(['raw'=>$raw]),'ignore_errors'=>true]]));
$d = json_decode((string)$r, true) ?? [];
if (isset($d['id'])) {
    $pdo->prepare("UPDATE marketing_sends SET status='sent',gmail_message_id=?,sent_at=UTC_TIMESTAMP() WHERE campaign_ref=? AND email_normalized=?")->execute([$d['id'],$cmp,$to]);
    echo json_encode(['status'=>'sent','email'=>$to,'message_id'=>$d['id'],'unsubscribe_url'=>$u]); 
} else {
    $pdo->prepare("UPDATE marketing_sends SET status='failed' WHERE campaign_ref=? AND email_normalized=?")->execute([$cmp,$to]);
    http_response_code(502); echo json_encode(['status'=>'failed','error'=>substr(json_encode($d),0,200)]);
}
