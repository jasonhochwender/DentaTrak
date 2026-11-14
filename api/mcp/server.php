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
global $pdo, $appConfig;
header('Content-Type: application/json');

$auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($auth === '') $auth = trim((string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if ($auth === '' && function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $hName => $hValue) {
        if (strcasecmp($hName, 'authorization') === 0) { $auth = trim((string)$hValue); break; }
    }
}
if (!preg_match('/^Bearer\s+(.+)$/', $auth, $m)) { http_response_code(401); header('WWW-Authenticate: Bearer resource_metadata="https://dentatrak.com/.well-known/oauth-protected-resource"'); echo json_encode(['error'=>'unauthorized']); exit; }
$tok = $pdo->prepare("SELECT t.*, u.email FROM mcp_access_tokens t JOIN users u ON u.id=t.user_id
    WHERE t.token_hash=:h AND t.revoked_at IS NULL AND t.expires_at>NOW() LIMIT 1");
$tok->execute([':h'=>hash('sha256',$m[1])]);
$tokenRow = $tok->fetch(PDO::FETCH_ASSOC);
if (!$tokenRow || !isSuperUser($appConfig, (string)$tokenRow['email'])) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
$userId = (int)$tokenRow['user_id'];

$req = json_decode((string)file_get_contents('php://input'), true);
$rid = $req['id'] ?? null;
$method = $req['method'] ?? '';
$ok = function ($r) use ($rid) { echo json_encode(['jsonrpc'=>'2.0','id'=>$rid,'result'=>$r]); exit; };
$fail = function (string $m) use ($rid) { echo json_encode(['jsonrpc'=>'2.0','id'=>$rid,'error'=>['code'=>-32000,'message'=>$m]]); exit; };
if (strpos($method,'notifications/')===0) { http_response_code(202); exit; }
if ($method==='ping') $ok(new stdClass());
if ($method==='initialize') $ok(['protocolVersion'=>'2025-06-18','capabilities'=>['tools'=>new stdClass()],'serverInfo'=>['name'=>'dentatrak-marketing','version'=>'1.0.0']]);

$TOOLS = [
  'check_eligibility' => ['description'=>'Check marketing eligibility incl. org suppression (no sends)',
    'inputSchema'=>['type'=>'object','properties'=>['emails'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['emails']]],
  'prepare_batch' => ['description'=>'Preview a batch: eligibility map + binds recipients/subject/body. Sends nothing.',
    'inputSchema'=>['type'=>'object','properties'=>['campaign'=>['type'=>'string'],'subject'=>['type'=>'string'],'html'=>['type'=>'string'],'emails'=>['type'=>'array','items'=>['type'=>'string']]],'required'=>['campaign','subject','html','emails']]],
  'send_batch' => ['description'=>'Send a previously prepared batch (explicit approve required)',
    'inputSchema'=>['type'=>'object','properties'=>['batch_id'=>['type'=>'string'],'approve'=>['type'=>'boolean']],'required'=>['batch_id','approve']]],
  'get_batch' => ['description'=>'Batch + per-recipient results (status, gmail_message_id, errors)',
    'inputSchema'=>['type'=>'object','properties'=>['batch_id'=>['type'=>'string']],'required'=>['batch_id']]],
  // --- Prospect CRM (MySQL) -----------------------------------------
  'search_prospects' => ['description'=>'Search the prospect CRM by org/contact/email/domain, status, priority, org type, eligibility, review flag. Paginated; returns stable contact ids.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'q'=>['type'=>'string'],'org_type'=>['type'=>'string'],'status'=>['type'=>'string'],
      'priority'=>['type'=>'string'],'eligible'=>['type'=>'string','enum'=>['','initial','followup']],
      'flag'=>['type'=>'string'],'bucket'=>['type'=>'string'],'city'=>['type'=>'string'],
      'state'=>['type'=>'string','description'=>'US state name or abbreviation (e.g. Georgia or GA)'],
      'review'=>['type'=>'boolean'],
      'page'=>['type'=>'integer'],'per'=>['type'=>'integer']]]],
  'get_prospect' => ['description'=>'Full prospect detail: contact + org + eligibility + recent activity/events.',
    'inputSchema'=>['type'=>'object','properties'=>['contact_id'=>['type'=>'integer']],'required'=>['contact_id']]],
  'get_organization' => ['description'=>'Organization detail incl. parent/child DSOs and contact count.',
    'inputSchema'=>['type'=>'object','properties'=>['org_id'=>['type'=>'integer']],'required'=>['org_id']]],
  'list_organization_contacts' => ['description'=>'Contacts belonging to an organization.',
    'inputSchema'=>['type'=>'object','properties'=>['org_id'=>['type'=>'integer'],'page'=>['type'=>'integer'],'per'=>['type'=>'integer']],'required'=>['org_id']]],
  'check_prospect_duplicate' => ['description'=>'Check for existing contacts/orgs matching an email, org name, or contact name before creating records. Read-only.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'email'=>['type'=>'string'],'org_name'=>['type'=>'string'],'contact_name'=>['type'=>'string'],'org_id'=>['type'=>'integer']]]],
  'prepare_prospect' => ['description'=>'Validate a proposed prospect and preview duplicate matches. Writes nothing - review output, then call create_prospect.',
    'inputSchema'=>['type'=>'object','properties'=>['org_id'=>['type'=>'integer'],'new_org'=>['type'=>'object'],'contact_name'=>['type'=>'string'],'first_name'=>['type'=>'string'],'last_name'=>['type'=>'string'],'email'=>['type'=>'string'],'title'=>['type'=>'string'],'phone'=>['type'=>'string'],'status'=>['type'=>'string'],'priority'=>['type'=>'string'],'notes'=>['type'=>'string']]]],
  'create_prospect' => ['description'=>'Create a CRM contact (optionally under new_org). REQUIRES confirm=true - only set after the user explicitly approves this specific record. Returns contact_id, duplicate warnings, review reasons. Never sends email.',
    'inputSchema'=>['type'=>'object','properties'=>['org_id'=>['type'=>'integer'],'new_org'=>['type'=>'object'],'first_name'=>['type'=>'string'],'last_name'=>['type'=>'string'],'full_name'=>['type'=>'string'],'contact_name'=>['type'=>'string'],'email'=>['type'=>'string'],'title'=>['type'=>'string'],'phone'=>['type'=>'string'],'status'=>['type'=>'string'],'priority'=>['type'=>'string'],'fit'=>['type'=>'string'],'owner_email'=>['type'=>'string'],'source'=>['type'=>'string'],'tags'=>['type'=>'array','items'=>['type'=>'string']],'notes'=>['type'=>'string'],'contact_notes'=>['type'=>'string'],'followup_date'=>['type'=>'string'],'demo_status'=>['type'=>'string'],'trial_status'=>['type'=>'string'],'outcome'=>['type'=>'string'],'objection_reason'=>['type'=>'string'],'outreach_method'=>['type'=>'string'],'confirm'=>['type'=>'boolean']]]],
  'create_organization' => ['description'=>'Create a CRM organization (practice, DSO, etc.). Optional parent_org_id for DSO hierarchy. Set dry_run=true to preview duplicates without writing; REQUIRES confirm=true to actually create - only after explicit user approval.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'name'=>['type'=>'string'],'org_type'=>['type'=>'string'],'website'=>['type'=>'string'],'domain'=>['type'=>'string'],
      'location'=>['type'=>'string'],'phone'=>['type'=>'string'],'status'=>['type'=>'string'],
      'parent_org_id'=>['type'=>'integer'],'tags'=>['type'=>'array','items'=>['type'=>'string']],
      'dry_run'=>['type'=>'boolean'],'confirm'=>['type'=>'boolean']],'required'=>['name']]],
  'update_prospect' => ['description'=>'Update a CRM contact by stable id. Requires current version (optimistic concurrency) and confirm=true (explicit user approval of these exact fields). Absent fields are preserved; blank values are ignored so research cannot wipe verified data; suppression/DNC can only be strengthened, never cleared.',
    'inputSchema'=>['type'=>'object','properties'=>['contact_id'=>['type'=>'integer'],'version'=>['type'=>'integer'],'fields'=>['type'=>'object'],'confirm'=>['type'=>'boolean']],'required'=>['contact_id','version']]],
  'update_organization' => ['description'=>'Update a CRM organization by stable id. Requires confirm=true (explicit user approval). Absent fields are preserved; blank values are ignored.',
    'inputSchema'=>['type'=>'object','properties'=>['org_id'=>['type'=>'integer'],'fields'=>['type'=>'object'],'confirm'=>['type'=>'boolean']],'required'=>['org_id']]],
  'add_prospect_note' => ['description'=>'Append a dated research note to a prospect (sources + researched_at supported). REQUIRES confirm=true. Stored as research_note - distinct from calls/meetings/emails.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'contact_id'=>['type'=>'integer'],'text'=>['type'=>'string'],
      'sources'=>['type'=>'array','items'=>['type'=>'string']],'researched_at'=>['type'=>'string'],
      'confirm'=>['type'=>'boolean']],'required'=>['contact_id','text']]],
  // --- Campaign preparation (drafts only - never approves or sends) ---
  'list_campaigns' => ['description'=>'List Marketing Outreach campaigns with recipient/sent counts. Filter by kind (initial|followup) and status. Read-only.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'kind'=>['type'=>'string','enum'=>['initial','followup']],
      'status'=>['type'=>'string','enum'=>['draft','ready','approved','partial','sending','completed']],
      'limit'=>['type'=>'integer']]]],
  'get_campaign' => ['description'=>'Full campaign detail: metadata, every recipient with message status, content, thread resolution, and send state. Read-only.',
    'inputSchema'=>['type'=>'object','properties'=>['campaign_id'=>['type'=>'string']],'required'=>['campaign_id']]],
  'create_campaign' => ['description'=>'Create a DRAFT outreach campaign (never approves or sends). Optional contact_ids seeds recipients (max 10). Identical open drafts by the same actor are returned instead of duplicated - retries are safe. Set dry_run=true to preview first. REQUIRES confirm=true for the actual create.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'name'=>['type'=>'string'],'kind'=>['type'=>'string','enum'=>['initial','followup']],
      'description'=>['type'=>'string'],'template_id'=>['type'=>'integer'],
      'contact_ids'=>['type'=>'array','items'=>['type'=>'integer']],'limit'=>['type'=>'integer'],
      'dry_run'=>['type'=>'boolean'],'confirm'=>['type'=>'boolean']],'required'=>['name']]],
  'add_campaign_recipients' => ['description'=>'Add CRM contacts (stable contact_ids, max 10/call) to an editable draft campaign. Rechecks eligibility; suppressed/DNC contacts are recorded as suppressed, never bypassed. Returns per-contact results. Set dry_run=true to preview. REQUIRES confirm=true.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'campaign_id'=>['type'=>'string'],'contact_ids'=>['type'=>'array','items'=>['type'=>'integer']],'dry_run'=>['type'=>'boolean'],'confirm'=>['type'=>'boolean']],'required'=>['campaign_id','contact_ids']]],
  'save_campaign_messages' => ['description'=>'Save individually personalized email drafts (subject + HTML body) for up to 10 campaign recipients in one call. Each recipient keeps their own content - no shared template required. Same sanitizer/thread-subject rules as the admin UI; sent or in-flight recipients are refused. REQUIRES confirm=true. Never approves or sends.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'campaign_id'=>['type'=>'string'],
      'messages'=>['type'=>'array','maxItems'=>10,'items'=>['type'=>'object','properties'=>[
        'recipient_id'=>['type'=>'integer'],'contact_id'=>['type'=>'integer'],
        'subject'=>['type'=>'string'],'body_html'=>['type'=>'string'],'text'=>['type'=>'string']],
        'required'=>['subject','body_html']]],
      'confirm'=>['type'=>'boolean']],'required'=>['campaign_id','messages']]],
  'preview_campaign_message' => ['description'=>'Exact final-assembly preview for one recipient: From/To/subject, personalized body + sender signature + managed footer + unsubscribe PLACEHOLDER (nonfunctional - real token is per-send). Includes Gmail thread status. Read-only; no tokens issued, nothing written.',
    'inputSchema'=>['type'=>'object','properties'=>[
      'campaign_id'=>['type'=>'string'],'recipient_id'=>['type'=>'integer'],
      'subject'=>['type'=>'string'],'body_html'=>['type'=>'string']],'required'=>['campaign_id','recipient_id']]],
  'get_campaign_readiness' => ['description'=>'Report whether a campaign is ready for admin review/approval: missing drafts, duplicate recipients, live eligibility/suppression re-check, thread resolution, approval-hash integrity, and actionable blockers. Read-only.',
    'inputSchema'=>['type'=>'object','properties'=>['campaign_id'=>['type'=>'string']],'required'=>['campaign_id']]],
];
if ($method==='tools/list') $ok(['tools'=>array_map(function($n,$t){return ['name'=>$n]+$t;},array_keys($TOOLS),array_values($TOOLS))]);
if ($method!=='tools/call') $fail('unsupported method');

$name = $req['params']['name'] ?? ''; $a = $req['params']['arguments'] ?? [];
$svc = new SuppressionService($pdo);
if ($name==='check_eligibility') {
    $ok(['content'=>[['type'=>'text','text'=>json_encode($svc->eligibility(array_map('strval',$a['emails']??[])))]]]);
} elseif ($name==='prepare_batch') {
    $emails = array_values(array_unique(array_map(function($e) use ($svc){return $svc->normalizeEmail((string)$e);},$a['emails']??[])));
    sort($emails); // canonical order - send_batch recomputes with ORDER BY email
    if (!$emails) $fail('no recipients');
    $elig = $svc->eligibility($emails);
    $hash = hash('sha256', json_encode([$emails,$a['subject'],$a['html'],$a['campaign']]));
    $bid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    $pdo->prepare("INSERT INTO marketing_batches (id,user_id,campaign,subject,html,content_hash) VALUES (:id,:u,:c,:s,:h,:k)")
        ->execute([':id'=>$bid,':u'=>$userId,':c'=>$a['campaign'],':s'=>$a['subject'],':h'=>$a['html'],':k'=>$hash]);
    $byEmail = [];
    foreach ((array)$elig as $info) { $byEmail[strtolower($info['email'] ?? '')] = $info; }
    $ins = $pdo->prepare("INSERT INTO marketing_batch_recipients (batch_id,email,eligible,eligible_reason) VALUES (:b,:e,:g,:r)");
    foreach ($emails as $e) {
        $info = $byEmail[strtolower($e)] ?? ['eligible'=>false];
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
        $msgId = $res['message_id'] ?? null;
        // Reconcile 'already_recorded': only count Sent when the ledger
        // establishes status=sent AND a Gmail message ID exists.
        if ($st === 'already_recorded' || ($st === 'sent' && !$msgId)) {
            $led = $pdo->prepare("SELECT status, gmail_message_id FROM marketing_sends WHERE campaign_ref=:c AND email_normalized=:e LIMIT 1");
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
    // Durable sheet retry on read too: confirmed sends lacking
    // sheet_synced_at retry the sheet write (never resends email).
    require_once __DIR__ . '/../marketing/ProspectSheetSync.php';
    require_once __DIR__ . '/../marketing/McpSheetSync.php';
    try { $sheet = new McpSheetSync();
        foreach ($pdo->query("SELECT * FROM marketing_batch_recipients WHERE batch_id='".$batch['id']."' AND status='sent' AND sheet_synced_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r)
            if ($sheet->markSent($r['email'], $batch['campaign'], (string)$r['gmail_message_id']))
                $pdo->prepare("UPDATE marketing_batch_recipients SET sheet_synced_at=NOW() WHERE id=:id")->execute([':id'=>$r['id']]);
    } catch (Throwable $e) {}
    $rs = $pdo->query("SELECT email,eligible,eligible_reason,status,gmail_message_id,error,sheet_synced_at FROM marketing_batch_recipients WHERE batch_id='".$batch['id']."' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $ok(['content'=>[['type'=>'text','text'=>json_encode(['batch'=>$batch,'recipients'=>$rs])]]]);
} else {
    // Prospect CRM tools (MySQL-backed; no sheet access, no sends).
    $crmTools = [
        'search_prospects' => 'searchProspects', 'get_prospect' => 'getProspect',
        'get_organization' => 'getOrganization', 'list_organization_contacts' => 'listOrganizationContacts',
        'check_prospect_duplicate' => 'checkProspectDuplicate', 'prepare_prospect' => 'prepareProspect',
        'create_prospect' => 'createProspect', 'create_organization' => 'createOrganization',
        'update_prospect' => 'updateProspect', 'update_organization' => 'updateOrganization',
        'add_prospect_note' => 'addProspectNote',
    ];
    // Campaign-preparation tools share the same record set as the UI but
    // never approve or send - delivery stays behind the admin controls.
    $campaignTools = [
        'list_campaigns' => 'listCampaigns', 'get_campaign' => 'getCampaign',
        'create_campaign' => 'createCampaign', 'add_campaign_recipients' => 'addCampaignRecipients',
        'save_campaign_messages' => 'saveCampaignMessages', 'preview_campaign_message' => 'previewCampaignMessage',
        'get_campaign_readiness' => 'getCampaignReadiness',
    ];
    if (isset($campaignTools[$name])) {
        require_once __DIR__ . '/../marketing/McpCampaignTools.php';
        try {
            $ct = new McpCampaignTools($pdo, (string)$tokenRow['email'], (string)($appConfig['baseUrl'] ?? ''));
            $result = $ct->{$campaignTools[$name]}($a);
            $ok(['content'=>[['type'=>'text','text'=>json_encode($result)]]]);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        } catch (Throwable $e) {
            error_log('[mcp-campaign] ' . $name . ': ' . $e->getMessage());
            $fail('tool failed');
        }
    }
    if (!isset($crmTools[$name])) $fail('unknown tool');
    require_once __DIR__ . '/../marketing/McpCrmTools.php';
    try {
        $tools = new McpCrmTools($pdo, (string)$tokenRow['email']);
        $result = $tools->{$crmTools[$name]}($a);
        $ok(['content'=>[['type'=>'text','text'=>json_encode($result)]]]);
    } catch (InvalidArgumentException $e) {
        $fail($e->getMessage());
    } catch (Throwable $e) {
        error_log('[mcp-crm] ' . $name . ': ' . $e->getMessage());
        $fail('tool failed');
    }
}
