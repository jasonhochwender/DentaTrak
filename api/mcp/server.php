<?php
/**
 * Private MCP server (Streamable HTTP, JSON-RPC 2.0) for the ChatGPT
 * Work connector. Bearer access token minted via oauth-authorize/token
 * (superuser-only). Tools reuse the existing marketing services - no
 * credentials are ever returned, logged, or exposed.
 */
require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/../dev-tools-access.php';
require_once __DIR__ . '/../marketing/SuppressionService.php';
global $pdo;
header('Content-Type: application/json');

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/', $auth, $m)) { http_response_code(401); header('WWW-Authenticate: Bearer resource_metadata="https://dentatrak.com/.well-known/oauth-protected-resource"'); echo json_encode(['error'=>'unauthorized']); exit; }
$tok = $pdo->prepare("SELECT t.*, u.email FROM mcp_access_tokens t JOIN users u ON u.id=t.user_id
    WHERE t.token_hash=:h AND t.revoked_at IS NULL AND t.expires_at>NOW() LIMIT 1");
$tok->execute([':h'=>hash('sha256',$m[1])]);
$tokenRow = $tok->fetch(PDO::FETCH_ASSOC);
if (!$tokenRow || !isSuperUser((string)$tokenRow['email'])) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
$userId = (int)$tokenRow['user_id'];

$req = json_decode((string)file_get_contents('php://input'), true);
$rid = $req['id'] ?? null;
$method = $req['method'] ?? '';
$ok = function ($r) use ($rid) { echo json_encode(['jsonrpc'=>'2.0','id'=>$rid,'result'=>$r]); exit; };
$fail = function (string $m) use ($rid) { echo json_encode(['jsonrpc'=>'2.0','id'=>$rid,'error'=>['code'=>-32000,'message'=>$m]]); exit; };
if (strpos($method,'notifications/')===0 || $method==='ping') { http_response_code(202); exit; }
if ($method==='initialize') $ok(['protocolVersion'=>'2025-06-18','capabilities'=>['tools'=>[]],'serverInfo'=>['name'=>'dentatrak-marketing','version'=>'1.0.0']]);

$TOOLS = [
  'check_eligibility' => ['description'=>'Check marketing eligibility incl. org suppression (no sends)',
    'inputSchema'=>['type'=>'object','properties'=>['emails'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['emails']]],
  'prepare_batch' => ['description'=>'Preview a batch: eligibility map + binds recipients/subject/body. Sends nothing.',
    'inputSchema'=>['type'=>'object','properties'=>['campaign'=>['type'=>'string'],'subject'=>['type'=>'string'],'html'=>['type'=>'string'],'emails'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['campaign','subject','html','emails']]],
  'send_batch' => ['description'=>'Send a previously prepared batch (explicit approve required)',
    'inputSchema'=>['type'=>'object','properties'=>['batch_id'=>['type'=>'string'],'approve'=>['type'=>'boolean']],'required'=>['batch_id','approve']]],
  'get_batch' => ['description'=>'Batch + per-recipient results (status, gmail_message_id, errors)',
    'inputSchema'=>['type'=>'object','properties'=>['batch_id'=>['type'=>'string']],'required'=>['batch_id']]],
];
if ($method==='tools/list') $ok(['tools'=>array_map(function($n,$t){return ['name'=>$n]+$t;},array_keys($TOOLS),array_values($TOOLS))]);
if ($method!=='tools/call') $fail('unsupported method');

$name = $req['params']['name'] ?? ''; $a = $req['params']['arguments'] ?? [];
$svc = new SuppressionService($pdo);
if ($name==='check_eligibility') {
    $ok(['content'=>[['type'=>'text','text'=>json_encode($svc->eligibility(array_map('strval',$a['emails']??[])))]]]);
} elseif ($name==='prepare_batch') {
    $emails = array_values(array_unique(array_map(function($e) use ($svc){return $svc->normalizeEmail((string)$e);},$a['emails']??[])));
    if (!$emails) $fail('no recipients');
    $elig = $svc->eligibility($emails);
    $hash = hash('sha256', json_encode([$emails,$a['subject'],$a['html'],$a['campaign']]));
    $bid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    $pdo->prepare("INSERT INTO marketing_batches (id,user_id,campaign,subject,html,content_hash) VALUES (:id,:u,:c,:s,:h,:k)")
        ->execute([':id'=>$bid,':u'=>$userId,':c'=>$a['campaign'],':s'=>$a['subject'],':h'=>$a['html'],':k'=>$hash]);
    $ins = $pdo->prepare("INSERT INTO marketing_batch_recipients (batch_id,email,eligible,eligible_reason) VALUES (:b,:e,:g,:r)");
    foreach ($emails as $e) {
        $info = $elig[$e] ?? ['eligible'=>false];
        $ins->execute([':b'=>$bid,':e'=>$e,':g'=>!empty($info['eligible'])?1:0,':r'=>!empty($info['eligible'])?null:($info['reason']??'suppressed')]);
    }
    $ok(['content'=>[['type'=>'text','text'=>json_encode(['batch_id'=>$bid,'emails'=>$emails,'eligibility'=>$elig,'sent'=>false])]]]);
} elseif ($name==='send_batch') {
    if (empty($a['approve'])) $fail('approve=true required');
    $b = $pdo->prepare("SELECT * FROM marketing_batches WHERE id=:id AND user_id=:u"); $b->execute([':id'=>$a['batch_id'],':u'=>$userId]); $batch=$b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) $fail('batch not found');
    if ($batch['status']==='sent') $fail('already sent');
    $expected = hash('sha256', json_encode([$pdo->query("SELECT email FROM marketing_batch_recipients WHERE batch_id='".$batch['id']."' ORDER BY email")->fetchAll(PDO::FETCH_COLUMN),$batch['subject'],$batch['html'],$batch['campaign']]));
    if (!hash_equals($batch['content_hash'], $expected)) $fail('batch content changed since approval - prepare a new batch');
    $token = getEnvVar('OUTREACH_API_TOKEN');
    if (!$token) $fail('outreach token unavailable - fail closed');
    // Authoritative Prospects-sheet pre-checks. Fail closed when the
    // sheet cannot be read - never send on an unchecked list.
    require_once __DIR__ . '/../marketing/ProspectSheetSync.php';
    require_once __DIR__ . '/../marketing/McpSheetSync.php';
    try { $sheet = new McpSheetSync(); } catch (Throwable $e) { $fail('sheet unreachable - fail closed'); }
    $sent=0;$skipped=0;$errors=[];
    $fail('send_batch is disabled pending noninteractive verification');
    // Claims: 'uncertain' excluded - it must reconcile the send ledger
    // (below) before another attempt is ever allowed.
    $claim = $pdo->prepare("UPDATE marketing_batch_recipients SET status='claiming' WHERE id=:id AND status IN ('pending','failed') AND eligible=1");
    $rs = $pdo->query("SELECT * FROM marketing_batch_recipients WHERE batch_id='".$batch['id']."' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rs as $r) {
        if (!$r['eligible']) { $skipped++; continue; }
        if ($r['status']==='sent') { $skipped++; continue; }
        $claim->execute([':id'=>$r['id']]);
        if ($claim->rowCount() !== 1) { $skipped++; continue; } // claimed elsewhere
        // Per-recipient suppression recheck immediately before send.
        if ($svc->isSuppressed($r['email']) || ($o = SuppressionService::orgRefForEmail($r['email'])) && $svc->isOrgSuppressed($o)) {
            $pdo->prepare("UPDATE marketing_batch_recipients SET status='skipped',error='suppressed_recheck' WHERE id=:id")->execute([':id'=>$r['id']]);
            $skipped++; continue;
        }
        $pre = $sheet->preCheck($r['email']);
        if (!$pre['ok']) {
            $pdo->prepare("UPDATE marketing_batch_recipients SET status='skipped',error=:e WHERE id=:id")->execute([':e'=>implode(',',$pre['flags']),':id'=>$r['id']]);
            $skipped++; continue;
        }
        $res = @json_decode((string)@file_get_contents('https://dentatrak.com/api/marketing-send.php', false, stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-Outreach-Token: $token\r\n",'content'=>json_encode(['to'=>$r['email'],'subject'=>$batch['subject'],'html'=>$batch['html'],'campaign'=>$batch['campaign']]),'ignore_errors'=>true,'timeout'=>30]])), true);
        $st = $res['status'] ?? 'uncertain';
        $msgId = $res['gmail_message_id'] ?? null;
        // Reconcile 'already_recorded': only count Sent when the ledger
        // establishes status=sent AND a Gmail message ID exists.
        if ($st === 'already_recorded' || ($st === 'sent' && !$msgId)) {
            $led = $pdo->prepare("SELECT status, gmail_message_id FROM marketing_sends WHERE campaign=:c AND email=:e LIMIT 1");
            $led->execute([':c'=>$batch['campaign'],':e'=>$r['email']]);
            $l = $led->fetch(PDO::FETCH_ASSOC);
            if ($l && $l['status']==='sent' && !empty($l['gmail_message_id'])) { $st='sent'; $msgId=$l['gmail_message_id']; }
            else $st='uncertain';
        }
        if ($st === 'sent') {
            $sheetOk = false;
            try { $sheetOk = $sheet->markSent($r['email'], $batch['campaign'], (string)$msgId); } catch (Throwable $e) {}
            $pdo->prepare("UPDATE marketing_batch_recipients SET status='sent',gmail_message_id=:m,error=NULL,sheet_synced_at=:w WHERE id=:id")
                ->execute([':m'=>$msgId,':w'=>$sheetOk?date('Y-m-d H:i:s'):null,':id'=>$r['id']]);
            $sent++;
        } elseif ($st === 'skipped_suppressed') {
            $pdo->prepare("UPDATE marketing_batch_recipients SET status='skipped',error='suppressed' WHERE id=:id")->execute([':id'=>$r['id']]);
            $skipped++;
        } else {
            // Uncertain/failed: never resend blindly; leave reconcilable.
            $pdo->prepare("UPDATE marketing_batch_recipients SET status=:s,error=:e WHERE id=:id")
                ->execute([':s'=>$st==='uncertain'?'uncertain':'failed',':e'=>substr((string)($res['error']??$st),0,160),':id'=>$r['id']]);
            if ($st!=='uncertain') $errors[] = $r['email'].':'.($res['error']??'send failed');
        }
    }
    // Durable sheet-write retries for confirmed sends never resend mail.
    foreach ($pdo->query("SELECT * FROM marketing_batch_recipients WHERE batch_id='".$batch['id']."' AND status='sent' AND sheet_synced_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try { if ($sheet->markSent($r['email'], $batch['campaign'], (string)$r['gmail_message_id']))
            $pdo->prepare("UPDATE marketing_batch_recipients SET sheet_synced_at=NOW() WHERE id=:id")->execute([':id'=>$r['id']]); } catch (Throwable $e) {}
    }
    $pdo->prepare("UPDATE marketing_batches SET status='sent',updated_at=NOW() WHERE id=:id")->execute([':id'=>$batch['id']]);
    $ok(['content'=>[['type'=>'text','text'=>json_encode(['sent'=>$sent,'skipped'=>$skipped,'errors'=>$errors])]]]);
} elseif ($name==='get_batch') {
    $b = $pdo->prepare("SELECT id,campaign,subject,status,created_at FROM marketing_batches WHERE id=:id AND user_id=:u"); $b->execute([':id'=>$a['batch_id'],':u'=>$userId]); $batch=$b->fetch(PDO::FETCH_ASSOC);
    if (!$batch) $fail('batch not found');
    $rs = $pdo->query("SELECT email,eligible,eligible_reason,status,gmail_message_id,error FROM marketing_batch_recipients WHERE batch_id='".$batch['id']."' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $ok(['content'=>[['type'=>'text','text'=>json_encode(['batch'=>$batch,'recipients'=>$rs])]]]);
} else $fail('unknown tool');
