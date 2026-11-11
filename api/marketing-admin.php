<?php
/**
 * Platform Administration - Marketing Outreach API.
 *
 * Session + CSRF + super-user (or local development) gated backend for the
 * Marketing Outreach section. The Google Sheet remains the prospect source
 * of truth; campaigns/messages/results live in the marketing_* tables.
 *
 * Reads (GET ?action=...):
 *   overview | prospects | prospect | followups | campaigns | campaign |
 *   templates | history | audit
 *
 * Mutations (POST ?action=..., X-CSRF-Token required):
 *   create_campaign | generate | save_message | approve | send |
 *   template_save | template_delete
 *
 * Safety properties:
 *  - Feature-flagged: MARKETING_OUTREACH must be enabled or every action 404s.
 *  - Approval binds a SHA-256 content hash; send refuses edited content.
 *  - Per-recipient suppression + sheet pre-check re-run immediately before
 *    each send; sends go through MarketingMailer (ledger-idempotent Gmail).
 *  - Max 10 recipients per campaign (initial rollout limit).
 *  - Every mutation writes marketing_audit_log.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/ai-client.php';
require_once __DIR__ . '/marketing/SuppressionService.php';
require_once __DIR__ . '/marketing/ProspectDirectory.php';
require_once __DIR__ . '/marketing/McpSheetSync.php';
require_once __DIR__ . '/marketing/MarketingMailer.php';
setSecurityHeaders();
header('Content-Type: application/json');

const MO_MAX_RECIPIENTS = 10;

function mo_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function mo_fail(string $msg, int $code = 400): void { mo_out(['success' => false, 'error' => $msg], $code); }

// ---- AuthN / AuthZ / flag ----
if (empty($_SESSION['db_user_id'])) mo_fail('unauthenticated', 401);
$userEmail = (string)($_SESSION['user_email'] ?? '');
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
if (!isSuperUser($appConfig, $userEmail) && !$isDev) mo_fail('forbidden', 403);
if (!isFeatureEnabled('MARKETING_OUTREACH')) mo_fail('not_found', 404);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
    $csrf = (string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
        mo_fail('csrf', 403);
    }
}

$action = (string)($_GET['action'] ?? $in['action'] ?? '');
global $pdo;
$svc = new SuppressionService($pdo);
$baseUrl = (string)($appConfig['baseUrl'] ?? '');

function mo_audit(PDO $pdo, string $actor, string $action, ?string $campaignId, $detail = null): void {
    try {
        $pdo->prepare("INSERT INTO marketing_audit_log (actor_email,action,campaign_id,detail) VALUES (?,?,?,?)")
            ->execute([$actor, substr($action, 0, 60), $campaignId, $detail === null ? null : json_encode($detail)]);
    } catch (Throwable $e) { error_log('[marketing-admin] audit: ' . $e->getMessage()); }
}

function mo_uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

/** Lazily-built directory; sheet problems are reported, not fatal. */
function mo_directory(PDO $pdo): ProspectDirectory {
    return new ProspectDirectory($pdo);
}

/**
 * Load all prospects; returns [records, sheetError, directory|null].
 * Never throws - sheet/auth failures degrade to an empty list plus an
 * honest sheet_error flag so the UI can say data may be incomplete.
 */
function mo_load_prospects(PDO $pdo): array {
    try {
        $dir = mo_directory($pdo);
        return [$dir->prospects(), false, $dir];
    } catch (Throwable $e) {
        error_log('[marketing-admin] prospect load: ' . $e->getMessage());
        return [[], true, null];
    }
}

/** Content hash binding approval to exact per-recipient content. */
function mo_content_hash(PDO $pdo, string $campaignId): string {
    $rows = $pdo->prepare("SELECT id,subject,body_html FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
    $rows->execute([$campaignId]);
    return hash('sha256', json_encode($rows->fetchAll(PDO::FETCH_ASSOC)));
}

/** Render a template's placeholders for one prospect. */
function mo_render(string $tpl, array $prospect): string {
    $f = $prospect['fields'] ?? [];
    $map = [
        '{first_name}'    => $prospect['contact_name'] !== '' ? explode(' ', trim($prospect['contact_name']))[0] : '',
        '{contact_name}'  => $prospect['contact_name'] ?? '',
        '{practice_name}' => $f['org'] ?? '',
        '{org_name}'      => $f['org'] ?? '',
        '{parent_org}'    => $f['parent_org'] ?? '',
        '{location}'      => $f['location'] ?? '',
        '{website}'       => $f['website'] ?? '',
        '{title}'         => $f['title'] ?? '',
    ];
    return strtr($tpl, $map);
}

/** Strip anything unsafe from generated/edited HTML bodies. */
function mo_sanitize_html(string $html): string {
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i', '$1=$2#$2', $html);
    return trim((string)$html);
}

/** AI-generate one recipient's email; falls back to the template render. */
function mo_generate_one(PDO $pdo, array $appConfig, array $prospect, string $kind, ?string $templateBody, ?string $priorContext): array
{
    $f = $prospect['fields'] ?? [];
    $orgType = stripos($f['practice_type'] . ' ' . $f['parent_org'], 'dso') !== false ? 'DSO'
        : (trim($f['parent_org']) !== '' ? 'multi-location group' : 'individual dental practice');

    $provider = $appConfig['ai_provider'] ?? 'gemini';
    $aiConfig = $appConfig[$provider] ?? [];
    if (empty($aiConfig['api_key'])) {
        return mo_template_fallback($prospect, $kind, $templateBody);
    }

    $system = 'You are a concise, professional B2B copywriter for DentaTrak, dental lab case-tracking software. '
        . 'Write cold outreach email copy only. No medical claims, no patient data, no hype. '
        . 'Return ONLY a JSON object: {"subject": string, "body_html": string}. '
        . 'body_html is a short HTML fragment (p and ul/li allowed, inline styles only, no scripts, no footer, no unsubscribe link, no images). '
        . '2-4 short paragraphs max. Include one clear low-pressure call to action (a reply or a 15-minute demo). '
        . 'Personalize using ONLY the prospect facts provided; never invent facts.';

    $facts = [
        'organization' => $f['org'] ?? '',
        'parent_or_group' => $f['parent_org'] ?? '',
        'org_type' => $orgType,
        'contact_name' => $prospect['contact_name'] ?? '',
        'title' => $f['title'] ?? '',
        'location' => $f['location'] ?? '',
        'website' => $f['website'] ?? '',
        'fit' => $f['fit'] ?? '',
        'priority' => $f['priority'] ?? '',
        'outreach_status' => $f['status'] ?? '',
        'prior_notes' => mb_substr((string)($f['notes'] ?? ''), 0, 1200),
    ];
    if ($kind === 'followup') {
        $facts['is_followup'] = true;
        $facts['prior_outreach_excerpt'] = mb_substr((string)$priorContext, 0, 1500);
    }
    if ($templateBody) $facts['template_guidance'] = mb_substr($templateBody, 0, 2000);

    $user = "Prospect facts (JSON):\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\n\nWrite the " . ($kind === 'followup' ? 'follow-up' : 'initial outreach') . " email.";

    try {
        $raw = $provider === 'openai'
            ? callOpenAIAPI($aiConfig, $system, $user)
            : callGeminiAPI($aiConfig, $system, $user);
        $decoded = json_decode($raw, true);
        // Tolerate markdown fences
        if (!is_array($decoded)) {
            $clean = preg_replace('/^```(?:json)?|```$/m', '', trim($raw));
            $decoded = json_decode($clean, true);
        }
        $subject = trim((string)($decoded['subject'] ?? ''));
        $body = mo_sanitize_html((string)($decoded['body_html'] ?? ''));
        if ($subject !== '' && $body !== '') {
            return ['subject' => $subject, 'body_html' => $body, 'generated_by' => 'ai'];
        }
    } catch (Throwable $e) {
        error_log('[marketing-admin] AI generate failed: ' . $e->getMessage());
    }
    return mo_template_fallback($prospect, $kind, $templateBody);
}

function mo_template_fallback(array $prospect, string $kind, ?string $templateBody): array
{
    $first = trim(explode(' ', (string)($prospect['contact_name'] ?? ''))[0] ?? '');
    $greeting = $first !== '' ? "Hi {$first}," : 'Hello,';
    if ($templateBody) {
        $body = mo_render($templateBody, $prospect);
    } else {
        $body = '<p>' . htmlspecialchars($greeting) . '</p>'
            . '<p>I hope this note finds you well. I\'m reaching out from DentaTrak — '
            . 'dental lab case-tracking software built for practices like '
            . htmlspecialchars((string)($prospect['fields']['org'] ?? 'yours')) . '.</p>'
            . '<p>Would you be open to a 15-minute walkthrough? I\'d be glad to show you '
            . 'how teams keep cases, labs, and due dates in one place.</p>'
            . '<p>Best regards,<br>The DentaTrak Team</p>';
    }
    $subject = $kind === 'followup'
        ? 'Following up: dental case tracking for ' . ($prospect['fields']['org'] ?? 'your practice')
        : 'A better way to track dental cases at ' . ($prospect['fields']['org'] ?? 'your practice');
    return ['subject' => $subject, 'body_html' => $body, 'generated_by' => 'template'];
}

// ============================ READ ACTIONS ============================
if ($method === 'GET') {
    switch ($action) {
        case 'overview': {
            [$prospects, $sheetErr] = mo_load_prospects($pdo);
            $buckets = ['due_today'=>0,'overdue'=>0,'upcoming'=>0,'awaiting_response'=>0,'completed'=>0,'excluded'=>0];
            $eligible = 0; $flags = ['missing_contact'=>0,'do_not_contact'=>0,'duplicate_org'=>0];
            foreach ($prospects as $p) {
                $buckets[$p['bucket']] = ($buckets[$p['bucket']] ?? 0) + 1;
                if ($p['eligible']) $eligible++;
                foreach ($p['flags'] as $fl) $flags[$fl] = ($flags[$fl] ?? 0) + 1;
            }
            $campaigns = $pdo->query("SELECT status, COUNT(*) n FROM marketing_campaigns GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            $sent30 = (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE status='sent' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetchColumn();
            $suppressed = (int)$pdo->query("SELECT COUNT(*) FROM marketing_suppressions")->fetchColumn();
            mo_out(['success' => true, 'overview' => [
                'prospects_total' => count($prospects),
                'prospects_eligible' => $eligible,
                'flags' => $flags,
                'followup_buckets' => $buckets,
                'campaigns' => $campaigns,
                'sends_30d' => $sent30,
                'suppressions' => $suppressed,
                'sheet_error' => $sheetErr ? 'sheet_unavailable' : null,
            ]]);
        }
        case 'prospects': {
            $q = strtolower(trim((string)($_GET['q'] ?? '')));
            $flag = (string)($_GET['flag'] ?? '');
            $bucket = (string)($_GET['bucket'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per = 50;
            [$all, $sheetErr] = mo_load_prospects($pdo);
            $filtered = array_values(array_filter($all, function ($p) use ($q, $flag, $bucket) {
                if ($flag !== '' && !in_array($flag, $p['flags'], true)) return false;
                if ($bucket !== '' && $p['bucket'] !== $bucket) return false;
                if ($q !== '') {
                    $hay = strtolower(implode(' ', array_filter([$p['fields']['org'], $p['fields']['parent_org'],
                        $p['contact_name'], $p['fields']['email'], $p['fields']['title'], $p['fields']['location']])));
                    if (strpos($hay, $q) === false) return false;
                }
                return true;
            }));
            $total = count($filtered);
            $slice = array_slice($filtered, ($page - 1) * $per, $per);
            mo_out(['success' => true, 'prospects' => array_map(function ($p) {
                return [
                    'row' => $p['row'],
                    'email' => $p['fields']['email'],
                    'org' => $p['fields']['org'],
                    'parent_org' => $p['fields']['parent_org'],
                    'contact_name' => $p['contact_name'],
                    'title' => $p['fields']['title'],
                    'practice_type' => $p['fields']['practice_type'],
                    'fit' => $p['fields']['fit'],
                    'priority' => $p['fields']['priority'],
                    'status' => $p['fields']['status'],
                    'response' => $p['fields']['response'],
                    'last_contact' => $p['fields']['last_contact'],
                    'followup_date' => $p['followup_ts'] !== null ? gmdate('Y-m-d', $p['followup_ts']) : '',
                    'followup_ts' => $p['followup_ts'],
                    'campaign' => $p['fields']['campaign'],
                    'bucket' => $p['bucket'],
                    'flags' => $p['flags'],
                    'eligible' => $p['eligible'],
                ];
            }, $slice), 'total' => $total, 'page' => $page, 'per' => $per,
               'sheet_error' => $sheetErr ? 'sheet_unavailable' : null]);
        }
        case 'prospect': {
            $email = SuppressionService::normalizeEmail((string)($_GET['email'] ?? ''));
            if ($email === '') mo_fail('email required');
            [, $sheetErr, $dir] = mo_load_prospects($pdo);
            if ($dir === null) mo_fail('prospect sheet unreachable', 503);
            $p = $dir->findByEmail($email);
            if (!$p) mo_fail('not found', 404);
            $hist = $pdo->prepare("SELECT campaign_ref,status,gmail_message_id,sent_at,created_at FROM marketing_sends WHERE email_normalized=? ORDER BY id DESC LIMIT 50");
            $hist->execute([$email]);
            mo_out(['success' => true, 'prospect' => [
                'row' => $p['row'], 'fields' => $p['fields'], 'flags' => $p['flags'],
                'bucket' => $p['bucket'], 'eligible' => $p['eligible'],
                'followup_ts' => $p['followup_ts'],
            ], 'history' => $hist->fetchAll(PDO::FETCH_ASSOC)]);
        }
        case 'followups': {
            $bucket = (string)($_GET['bucket'] ?? '');
            [$all, $sheetErr] = mo_load_prospects($pdo);
            $all = array_values(array_filter($all, function ($p) use ($bucket) {
                return $bucket === '' ? in_array($p['bucket'], ['due_today','overdue','upcoming','awaiting_response'], true)
                                      : $p['bucket'] === $bucket;
            }));
            usort($all, function ($a, $b) {
                return ($a['followup_ts'] ?? PHP_INT_MAX) <=> ($b['followup_ts'] ?? PHP_INT_MAX);
            });
            mo_out(['success' => true, 'followups' => array_map(function ($p) {
                return [
                    'row' => $p['row'], 'email' => $p['fields']['email'], 'org' => $p['fields']['org'],
                    'contact_name' => $p['contact_name'], 'status' => $p['fields']['status'],
                    'response' => $p['fields']['response'], 'followup_ts' => $p['followup_ts'],
                    'last_contact' => $p['fields']['last_contact'], 'notes' => $p['fields']['notes'],
                    'bucket' => $p['bucket'], 'flags' => $p['flags'], 'eligible' => $p['eligible'],
                ];
            }, $all), 'sheet_error' => $sheetErr ? 'sheet_unavailable' : null]);
        }
        case 'campaigns': {
            $rows = $pdo->query("SELECT c.*, 
                (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id) recipients,
                (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id AND r.status='sent') sent
                FROM marketing_campaigns c ORDER BY c.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            mo_out(['success' => true, 'campaigns' => $rows]);
        }
        case 'campaign': {
            $id = (string)($_GET['id'] ?? '');
            $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
            $c->execute([$id]);
            $camp = $c->fetch(PDO::FETCH_ASSOC);
            if (!$camp) mo_fail('not found', 404);
            $r = $pdo->prepare("SELECT id,sheet_row,prospect_email,prospect_org,prospect_contact,subject,body_html,status,skip_reason,is_edited,gmail_message_id,gmail_thread_id,error,sent_at FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
            $r->execute([$id]);
            mo_out(['success' => true, 'campaign' => $camp, 'recipients' => $r->fetchAll(PDO::FETCH_ASSOC)]);
        }
        case 'templates': {
            $rows = $pdo->query("SELECT id,name,kind,subject_tpl,body_tpl,is_default,created_by,updated_at FROM marketing_templates ORDER BY kind, is_default DESC, name")->fetchAll(PDO::FETCH_ASSOC);
            mo_out(['success' => true, 'templates' => $rows]);
        }
        case 'history': {
            $rows = $pdo->query("SELECT s.id,s.campaign_ref,s.email_normalized,s.status,s.gmail_message_id,s.sent_at,s.created_at
                FROM marketing_sends s ORDER BY s.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            $audit = $pdo->query("SELECT actor_email,action,campaign_id,detail,created_at FROM marketing_audit_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            mo_out(['success' => true, 'sends' => $rows, 'audit' => $audit]);
        }
        default:
            mo_fail('unknown action', 404);
    }
}

// ============================ MUTATIONS ============================
if ($method !== 'POST') mo_fail('method', 405);

switch ($action) {
    case 'create_campaign': {
        $name = trim((string)($in['name'] ?? ''));
        $kind = ($in['kind'] ?? 'initial') === 'followup' ? 'followup' : 'initial';
        $emails = array_values(array_unique(array_filter(array_map(function ($e) use ($svc) {
            return SuppressionService::normalizeEmail((string)$e);
        }, (array)($in['emails'] ?? [])))));
        $emails = array_values(array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if ($name === '') mo_fail('name required');
        if (!$emails) mo_fail('no valid recipients');
        if (count($emails) > MO_MAX_RECIPIENTS) mo_fail('max ' . MO_MAX_RECIPIENTS . ' recipients per campaign');

        // Resolve each email against the sheet; ineligible prospects are
        // still recorded but flagged so the UI can show why.
        [$sheetProspects, $sheetErr] = mo_load_prospects($pdo);
        if ($sheetErr) mo_fail('prospect sheet unreachable', 503);
        $prospects = [];
        foreach ($sheetProspects as $p) $prospects[strtolower($p['fields']['email'])] = $p;

        $id = mo_uuid();
        $pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status,created_by,recipient_count) VALUES (?,?,?,'draft',?,?)")
            ->execute([$id, substr($name, 0, 160), $kind, $userEmail, count($emails)]);
        $ins = $pdo->prepare("INSERT INTO marketing_campaign_recipients
            (campaign_id,sheet_row,prospect_email,prospect_org,prospect_contact,status,skip_reason)
            VALUES (?,?,?,?,?,?,?)");
        foreach ($emails as $e) {
            $p = $prospects[$e] ?? null;
            $reason = null; $status = 'draft';
            if (!$p) { $status = 'suppressed'; $reason = 'not_in_sheet'; }
            elseif (!$p['eligible']) { $status = 'suppressed'; $reason = implode(',', $p['flags']); }
            $ins->execute([$id, $p['row'] ?? null, $e,
                substr((string)($p['fields']['org'] ?? ''), 0, 255),
                substr((string)($p['contact_name'] ?? ''), 0, 255),
                $status, $reason]);
        }
        mo_audit($pdo, $userEmail, 'campaign_create', $id, ['name' => $name, 'kind' => $kind, 'recipients' => count($emails)]);
        mo_out(['success' => true, 'campaign_id' => $id]);
    }

    case 'generate': {
        $cid = (string)($in['campaign_id'] ?? '');
        $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
        $c->execute([$cid]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) mo_fail('not found', 404);
        if (!in_array($camp['status'], ['draft', 'ready'], true)) mo_fail('campaign not editable');
        $ridFilter = array_map('intval', (array)($in['recipient_ids'] ?? []));

        // Optional template for this kind
        $tpl = $pdo->prepare("SELECT * FROM marketing_templates WHERE kind=? ORDER BY is_default DESC, id LIMIT 1");
        $tpl->execute([$camp['kind']]);
        $template = $tpl->fetch(PDO::FETCH_ASSOC) ?: null;

        [$sheetProspects, $sheetErr] = mo_load_prospects($pdo);
        if ($sheetErr) mo_fail('prospect sheet unreachable', 503);
        $byEmail = [];
        foreach ($sheetProspects as $p) $byEmail[strtolower($p['fields']['email'])] = $p;

        $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND status IN ('draft','ready','suppressed') ORDER BY id");
        $rs->execute([$cid]);
        $recips = $rs->fetchAll(PDO::FETCH_ASSOC);
        $done = 0; $failed = [];
        foreach ($recips as $r) {
            if ($ridFilter && !in_array((int)$r['id'], $ridFilter, true)) continue;
            if ($r['status'] === 'suppressed') continue; // never generate for suppressed
            $p = $byEmail[strtolower($r['prospect_email'])] ?? null;
            if (!$p) { $failed[] = $r['prospect_email']; continue; }
            // Prior outreach context for follow-ups: last sent body excerpt
            $prior = '';
            if ($camp['kind'] === 'followup') {
                $pr = $pdo->prepare("SELECT subject, body_html FROM marketing_campaign_recipients
                    WHERE prospect_email=? AND status='sent' ORDER BY sent_at DESC LIMIT 1");
                $pr->execute([$r['prospect_email']]);
                if ($pp = $pr->fetch(PDO::FETCH_ASSOC)) {
                    $prior = "Subject: {$pp['subject']}\n\n" . trim(strip_tags((string)$pp['body_html']));
                }
            }
            $gen = mo_generate_one($pdo, $appConfig, $p, $camp['kind'],
                $template['body_tpl'] ?? null, $prior);
            // Don't clobber manual edits on regenerate unless forced
            $force = !empty($in['force']);
            if ($force || $r['is_edited'] == 0) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET subject=?, body_html=?, status='ready' WHERE id=?")
                    ->execute([$gen['subject'], $gen['body_html'], $r['id']]);
                $done++;
            }
        }
        // Campaign becomes 'ready' once every non-suppressed recipient has content
        $remaining = $pdo->prepare("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=? AND status='draft'");
        $remaining->execute([$cid]);
        if ((int)$remaining->fetchColumn() === 0) {
            $pdo->prepare("UPDATE marketing_campaigns SET status='ready' WHERE id=? AND status='draft'")->execute([$cid]);
        }
        mo_audit($pdo, $userEmail, 'campaign_generate', $cid, ['generated' => $done, 'failed' => count($failed)]);
        mo_out(['success' => true, 'generated' => $done, 'failed' => $failed]);
    }

    case 'save_message': {
        $cid = (string)($in['campaign_id'] ?? '');
        $rid = (int)($in['recipient_id'] ?? 0);
        $subject = trim((string)($in['subject'] ?? ''));
        $body = mo_sanitize_html((string)($in['body_html'] ?? ''));
        if ($subject === '' || $body === '') mo_fail('subject and body required');
        $r = $pdo->prepare("SELECT r.*, c.status cstatus FROM marketing_campaign_recipients r JOIN marketing_campaigns c ON c.id=r.campaign_id WHERE r.id=? AND r.campaign_id=?");
        $r->execute([$rid, $cid]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) mo_fail('not found', 404);
        if (!in_array($row['cstatus'], ['draft', 'ready'], true)) mo_fail('campaign locked');
        $pdo->prepare("UPDATE marketing_campaign_recipients SET subject=?, body_html=?, is_edited=1, status='ready' WHERE id=?")
            ->execute([substr($subject, 0, 500), $body, $rid]);
        // Editing after approval invalidates it
        $pdo->prepare("UPDATE marketing_campaigns SET status='ready', approved_by=NULL, approved_at=NULL, content_hash=NULL WHERE id=? AND status='approved'")
            ->execute([$cid]);
        mo_audit($pdo, $userEmail, 'message_edit', $cid, ['recipient_id' => $rid]);
        mo_out(['success' => true]);
    }

    case 'approve': {
        $cid = (string)($in['campaign_id'] ?? '');
        $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
        $c->execute([$cid]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) mo_fail('not found', 404);
        if (!in_array($camp['status'], ['draft', 'ready'], true)) mo_fail('campaign not approvable');
        // Every non-suppressed recipient must have content; cap enforced
        $rs = $pdo->prepare("SELECT status, COUNT(*) n FROM marketing_campaign_recipients WHERE campaign_id=? GROUP BY status");
        $rs->execute([$cid]);
        $counts = $rs->fetchAll(PDO::FETCH_KEY_PAIR);
        $sendable = (int)($counts['ready'] ?? 0);
        if ($sendable === 0) mo_fail('no generated messages to approve');
        if ($sendable > MO_MAX_RECIPIENTS) mo_fail('approval limited to ' . MO_MAX_RECIPIENTS . ' recipients');
        $hash = mo_content_hash($pdo, $cid);
        $pdo->prepare("UPDATE marketing_campaigns SET status='approved', approved_by=?, approved_at=UTC_TIMESTAMP(), content_hash=? WHERE id=?")
            ->execute([$userEmail, $hash, $cid]);
        $pdo->prepare("UPDATE marketing_campaign_recipients SET status='approved' WHERE campaign_id=? AND status='ready'")
            ->execute([$cid]);
        mo_audit($pdo, $userEmail, 'campaign_approve', $cid, ['recipients' => $sendable]);
        mo_out(['success' => true, 'approved' => $sendable]);
    }

    case 'send': {
        $cid = (string)($in['campaign_id'] ?? '');
        $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
        $c->execute([$cid]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) mo_fail('not found', 404);
        if (!in_array($camp['status'], ['approved', 'partial'], true)) mo_fail('campaign not approved');
        // Content must match what was approved - no unapproved edits send.
        if (!hash_equals((string)$camp['content_hash'], mo_content_hash($pdo, $cid))) {
            mo_fail('content changed since approval - re-approve required');
        }
        // Atomic claim so two admins cannot send the same campaign twice.
        $claim = $pdo->prepare("UPDATE marketing_campaigns SET status='sending' WHERE id=? AND status IN ('approved','partial')");
        $claim->execute([$cid]);
        if ($claim->rowCount() !== 1) mo_fail('send already in progress', 409);

        $mailer = new MarketingMailer($pdo, $baseUrl);
        $sheet = null;
        try { $sheet = new McpSheetSync(); } catch (Throwable $e) {
            // Sheet unreachable: still allow sends? MCP fails closed. We do
            // the same - abort before any send so status write-back is honest.
            $pdo->prepare("UPDATE marketing_campaigns SET status='approved' WHERE id=?")->execute([$cid]);
            mo_fail('prospect sheet unreachable - send aborted', 502);
        }

        $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients
            WHERE campaign_id=? AND status IN ('approved','failed') ORDER BY id LIMIT " . MO_MAX_RECIPIENTS);
        $rs->execute([$cid]);
        $recips = $rs->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0; $skipped = 0; $failed = 0; $results = [];
        foreach ($recips as $r) {
            // Claim the recipient row
            $cl = $pdo->prepare("UPDATE marketing_campaign_recipients SET status='sending' WHERE id=? AND status IN ('approved','failed')");
            $cl->execute([$r['id']]);
            if ($cl->rowCount() !== 1) { $skipped++; continue; }

            // 1) Authoritative suppression re-check
            $email = SuppressionService::normalizeEmail($r['prospect_email']);
            $orgRef = SuppressionService::orgRefForEmail($email);
            if ($svc->isSuppressed($email) || ($orgRef && $svc->isOrgSuppressed($orgRef))) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason='suppressed_recheck' WHERE id=?")->execute([$r['id']]);
                $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed'];
                continue;
            }
            // 2) Sheet pre-check (DNC / already-contacted / duplicate org)
            $pre = $sheet->preCheck($email);
            if (!$pre['ok']) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='skipped', skip_reason=?, error=? WHERE id=?")
                    ->execute([implode(',', $pre['flags']), 'sheet_precheck', $r['id']]);
                $skipped++; $results[] = ['email' => $email, 'status' => 'skipped', 'reason' => implode(',', $pre['flags'])];
                continue;
            }
            // 3) Send via shared mailer (ledger-idempotent, footer, RFC8058)
            $threadId = $r['gmail_thread_id'] ?? '';
            if ($threadId === '' && $camp['kind'] === 'followup') {
                // Reuse the thread of the most recent prior send to this email
                $th = $pdo->prepare("SELECT gmail_thread_id, gmail_message_id FROM marketing_campaign_recipients
                    WHERE prospect_email=? AND gmail_message_id IS NOT NULL ORDER BY sent_at DESC LIMIT 1");
                $th->execute([$email]);
                if ($t = $th->fetch(PDO::FETCH_ASSOC)) {
                    $threadId = $t['gmail_thread_id'] ?: '';
                    if ($threadId === '' && $t['gmail_message_id']) {
                        $threadId = (string)($mailer->fetchThreadId($t['gmail_message_id']) ?? '');
                    }
                }
            }
            $res = $mailer->send([
                'to' => $email, 'subject' => (string)$r['subject'],
                'html' => (string)$r['body_html'], 'campaign' => 'mo:' . $cid,
                'thread_id' => $threadId !== '' ? $threadId : null,
            ]);
            $st = $res['status'] ?? 'uncertain';
            if ($st === 'already_recorded') {
                // Reconcile against the ledger like the MCP path does
                $led = $pdo->prepare("SELECT status, gmail_message_id FROM marketing_sends WHERE campaign_ref=? AND email_normalized=?");
                $led->execute(['mo:' . $cid, $email]);
                $l = $led->fetch(PDO::FETCH_ASSOC);
                if ($l && $l['status'] === 'sent' && !empty($l['gmail_message_id'])) {
                    $st = 'sent'; $res['message_id'] = $l['gmail_message_id'];
                } else { $st = 'uncertain'; }
            }
            if ($st === 'sent') {
                $msgId = (string)($res['message_id'] ?? '');
                $tid = (string)($res['thread_id'] ?? $threadId);
                if ($tid === '' && $msgId !== '') $tid = (string)($mailer->fetchThreadId($msgId) ?? '');
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='sent', gmail_message_id=?, gmail_thread_id=?, sent_at=UTC_TIMESTAMP(), error=NULL WHERE id=?")
                    ->execute([$msgId, $tid !== '' ? $tid : null, $r['id']]);
                // Sheet write-back: confirmed sends only
                try {
                    mo_record_sheet_sent($sheet, $r, $camp, $msgId);
                } catch (Throwable $e) { error_log('[marketing-admin] sheet write-back: ' . $e->getMessage()); }
                $sent++; $results[] = ['email' => $email, 'status' => 'sent', 'message_id' => $msgId];
            } elseif ($st === 'skipped_suppressed') {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason='suppressed' WHERE id=?")->execute([$r['id']]);
                $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed'];
            } else {
                $err = substr((string)($res['error'] ?? $st), 0, 250);
                $newStatus = ($st === 'uncertain') ? 'failed' : 'failed';
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status=?, error=? WHERE id=?")
                    ->execute([$newStatus, $err, $r['id']]);
                $failed++; $results[] = ['email' => $email, 'status' => 'failed', 'error' => $err];
            }
        }

        // Roll up campaign status
        $cnt = $pdo->prepare("SELECT status, COUNT(*) n FROM marketing_campaign_recipients WHERE campaign_id=? GROUP BY status");
        $cnt->execute([$cid]);
        $c2 = [];
        foreach ($cnt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $c2[$k] = (int)$v;
        $remaining = ($c2['approved'] ?? 0) + ($c2['failed'] ?? 0);
        $final = $remaining > 0 ? 'partial' : 'completed';
        $pdo->prepare("UPDATE marketing_campaigns SET status=?, sent_count=?, skipped_count=?, failed_count=?, updated_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$final, $c2['sent'] ?? 0, ($c2['skipped'] ?? 0) + ($c2['suppressed'] ?? 0), $c2['failed'] ?? 0, $cid]);

        mo_audit($pdo, $userEmail, 'campaign_send', $cid, ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed]);
        mo_out(['success' => true, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
            'results' => $results, 'campaign_status' => $final]);
    }

    case 'template_save': {
        $tid = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $kind = ($in['kind'] ?? 'initial') === 'followup' ? 'followup' : 'initial';
        $subject = trim((string)($in['subject_tpl'] ?? ''));
        $body = mo_sanitize_html((string)($in['body_tpl'] ?? ''));
        $isDefault = !empty($in['is_default']) ? 1 : 0;
        if ($name === '' || $subject === '' || $body === '') mo_fail('name, subject and body required');
        if ($isDefault) {
            $pdo->prepare("UPDATE marketing_templates SET is_default=0 WHERE kind=?")->execute([$kind]);
        }
        if ($tid > 0) {
            $pdo->prepare("UPDATE marketing_templates SET name=?,kind=?,subject_tpl=?,body_tpl=?,is_default=? WHERE id=?")
                ->execute([substr($name,0,120), $kind, substr($subject,0,255), $body, $isDefault, $tid]);
        } else {
            $pdo->prepare("INSERT INTO marketing_templates (name,kind,subject_tpl,body_tpl,is_default,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([substr($name,0,120), $kind, substr($subject,0,255), $body, $isDefault, $userEmail]);
        }
        mo_audit($pdo, $userEmail, 'template_save', null, ['id' => $tid, 'name' => $name]);
        mo_out(['success' => true]);
    }

    case 'template_delete': {
        $tid = (int)($in['id'] ?? 0);
        $pdo->prepare("DELETE FROM marketing_templates WHERE id=?")->execute([$tid]);
        mo_audit($pdo, $userEmail, 'template_delete', null, ['id' => $tid]);
        mo_out(['success' => true]);
    }

    default:
        mo_fail('unknown action', 404);
}

/**
 * Sheet write-back after a confirmed send. Initial outreach marks the row
 * Sent with first/last contact dates; follow-ups update last-contact +
 * notes only (status stays truthful about the conversation state).
 * Never resends mail; failures are logged and retried on the next send.
 */
function mo_record_sheet_sent(McpSheetSync $sheet, array $recipient, array $campaign, string $msgId): void
{
    $row = (int)($recipient['sheet_row'] ?? 0);
    if ($row <= 0) return; // prospect not in sheet - nothing to update
    $today = gmdate('Y-m-d');

    // Preserve existing outreach notes: read the current sheet row first.
    $notes = '';
    try {
        $dir = new ProspectDirectory($GLOBALS['pdo']);
        $p = $dir->findByEmail((string)$recipient['prospect_email']);
        $notes = trim((string)($p['fields']['notes'] ?? ''));
    } catch (Throwable $e) { /* notes stay empty on lookup failure */ }
    $note = 'Gmail ' . $msgId . ($campaign['kind'] === 'followup' ? ' (follow-up)' : '');
    $fields = [
        'Last Contact Date' => $today,
        'Campaign' => (string)$campaign['name'],
        'Outreach Notes' => $notes !== '' ? $notes . ' | ' . $note : $note,
    ];
    if ($campaign['kind'] !== 'followup') {
        $fields['Outreach Status'] = 'Sent';
        $fields['First Contact Date'] = $today;
        $fields['Outreach Method'] = 'Email';
    }
    $sheet->updateRow($row, $fields);
}
