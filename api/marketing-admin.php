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
 *   resolve_threads | reconcile | resolve_uncertain | retry_sheet_sync |
 *   template_save | template_delete
 *
 * Safety properties:
 *  - Feature-flagged: MARKETING_OUTREACH (Marketing Administration) must be
 *    enabled or every action 404s. It grants the interface only.
 *  - Delivery is a separate control: `send` additionally requires
 *    MARKETING_SENDING, and each recipient must pass the
 *    MARKETING_SEND_ALLOWLIST (see MarketingSendingPolicy). Everything
 *    else - review, generate, edit, approve - works with sending off.
 *  - Eligibility is kind-aware (OutreachEligibility): initial campaigns
 *    refuse previously contacted prospects, follow-ups require a valid
 *    prior outreach record; permanent exclusions apply to both.
 *  - reconcile / resolve_uncertain / retry_sheet_sync never send mail.
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
require_once __DIR__ . '/marketing/ProspectCrm.php';
require_once __DIR__ . '/marketing/ProspectCrmAdmin.php';
require_once __DIR__ . '/marketing/McpSheetSync.php';
require_once __DIR__ . '/marketing/MarketingMailer.php';
require_once __DIR__ . '/marketing/MarketingSendingPolicy.php';
require_once __DIR__ . '/marketing/OutreachEligibility.php';
require_once __DIR__ . '/marketing/OutreachThreadResolver.php';
require_once __DIR__ . '/marketing/MarketingSignature.php';
require_once __DIR__ . '/marketing/ReplyTracker.php';
require_once __DIR__ . '/marketing/SheetSyncException.php';
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
$sendingPolicy = new MarketingSendingPolicy();

// Both marketing migrations must have run; fail closed with a clear
// message rather than half-working against an old schema.
try {
    $pdo->query('SELECT thread_status, sheet_sync_status, thread_date FROM marketing_campaign_recipients LIMIT 0');
    $pdo->query('SELECT idempotency_key, attempts FROM marketing_sends LIMIT 0');
} catch (Throwable $e) {
    mo_fail('schema_not_migrated: run migrations/2026_11_03_marketing_admin.php and 2026_11_12_marketing_send_hardening.php', 503);
}

/** Ledger reference for a campaign's sends. */
function mo_ref(string $campaignId): string { return 'mo:' . $campaignId; }

/** Suppression + ledger facts for the eligibility rules. */
function mo_elig_ctx(PDO $pdo, SuppressionService $svc, string $email, ?string $excludeRef = null): array {
    $orgRef = SuppressionService::orgRefForEmail($email);
    $sql = "SELECT 1 FROM marketing_sends WHERE email_normalized=? AND status='sent'";
    $args = [$email];
    if ($excludeRef !== null) { $sql .= ' AND campaign_ref<>?'; $args[] = $excludeRef; }
    $st = $pdo->prepare($sql . ' LIMIT 1');
    $st->execute($args);
    return [
        'suppressed' => $svc->isSuppressed($email),
        'org_suppressed' => $orgRef !== null && $svc->isOrgSuppressed($orgRef),
        'ledger_sent' => $st->fetchColumn() !== false,
        'require_in_sheet' => true,
    ];
}

/**
 * Resolve and persist the original Gmail conversation for one follow-up
 * recipient. When resolved, the subject is set to the reply subject Gmail
 * needs to keep the message in that conversation.
 */
function mo_resolve_thread(PDO $pdo, MarketingMailer $mailer, array $recipient, string $sheetNotes, string $campaignId): array {
    $resolver = new OutreachThreadResolver($pdo, [$mailer, 'gmailGet']);
    $t = $resolver->resolve((string)$recipient['prospect_email'],
        OutreachEligibility::gmailIdsFromNotes($sheetNotes), mo_ref($campaignId));
    $ok = $t['status'] === 'resolved';
    $sql = 'UPDATE marketing_campaign_recipients SET thread_status=?, thread_reason=?, thread_source=?,
        thread_subject=?, thread_date=?, thread_rfc_message_id=?, thread_references=?, gmail_thread_id=?';
    $args = [$t['status'], $t['reason'] !== '' ? substr($t['reason'], 0, 80) : null, $ok ? $t['source'] : null,
        $ok ? substr($t['subject'], 0, 500) : null, $ok ? substr((string)($t['date'] ?? ''), 0, 40) : null,
        $ok ? $t['rfc_message_id'] : null,
        $ok ? $t['references'] : null, $ok ? $t['thread_id'] : null];
    if ($ok) { $sql .= ', subject=?'; $args[] = substr($t['subject'], 0, 500); }
    $args[] = $recipient['id'];
    $pdo->prepare($sql . ' WHERE id=?')->execute($args);
    return $t;
}

/** Recompute campaign status + counters from its recipients. */
function mo_rollup(PDO $pdo, string $campaignId): array {
    $cnt = $pdo->prepare('SELECT status, COUNT(*) n FROM marketing_campaign_recipients WHERE campaign_id=? GROUP BY status');
    $cnt->execute([$campaignId]);
    $c = [];
    foreach ($cnt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $c[$k] = (int)$v;
    $remaining = ($c['approved'] ?? 0) + ($c['failed'] ?? 0) + ($c['uncertain'] ?? 0) + ($c['sending'] ?? 0);
    $final = $remaining > 0 ? 'partial' : 'completed';
    $pdo->prepare('UPDATE marketing_campaigns SET status=?, sent_count=?, skipped_count=?, failed_count=? WHERE id=?')
        ->execute([$final, $c['sent'] ?? 0, ($c['skipped'] ?? 0) + ($c['suppressed'] ?? 0),
            ($c['failed'] ?? 0) + ($c['uncertain'] ?? 0), $campaignId]);
    return ['status' => $final, 'counts' => $c];
}

/**
 * Write one confirmed send back to the prospect sheet and record the
 * outcome durably. Never sends mail; safe to repeat (the Gmail note is
 * appended once, the other cells are idempotent values).
 */
function mo_sheet_sync_one(PDO $pdo, McpSheetSync $sheet, array $recipient, array $campaign): bool {
    $msgId = (string)($recipient['gmail_message_id'] ?? '');
    $date = substr((string)($recipient['sent_at'] ?? ''), 0, 10) ?: gmdate('Y-m-d');
    $followup = $campaign['kind'] === 'followup';
    $fields = ['Last Contact Date' => $date, 'Campaign' => (string)$campaign['name']];
    if (!$followup) {
        // Follow-ups leave Outreach Status as it is: the row already
        // records that the prospect was contacted.
        $fields['Outreach Status'] = 'Sent';
        $fields['First Contact Date'] = $date;
        $fields['Outreach Method'] = 'Email';
    }
    $note = $msgId !== '' ? 'Gmail ' . $msgId . ($followup ? ' (follow-up)' : '') : '';
    try {
        $sheet->recordSend((string)$recipient['prospect_email'], $fields, $note);
        $pdo->prepare("UPDATE marketing_campaign_recipients SET sheet_sync_status='synced', sheet_sync_attempts=sheet_sync_attempts+1,
            sheet_sync_error=NULL, sheet_synced_at=UTC_TIMESTAMP() WHERE id=?")->execute([$recipient['id']]);
        return true;
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE marketing_campaign_recipients SET sheet_sync_status='failed', sheet_sync_attempts=sheet_sync_attempts+1,
            sheet_sync_error=? WHERE id=?")->execute([substr($e->getMessage(), 0, 250), $recipient['id']]);
        error_log('[marketing-admin] sheet write-back: ' . $e->getMessage());
        return false;
    }
}

/** Retry every outstanding sheet write-back for a campaign. No mail. */
function mo_sheet_sync_pending(PDO $pdo, McpSheetSync $sheet, array $campaign): array {
    $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND status='sent'
        AND sheet_sync_status IN ('pending','failed') ORDER BY id");
    $rs->execute([$campaign['id']]);
    $ok = 0; $bad = 0;
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        mo_sheet_sync_one($pdo, $sheet, $r, $campaign) ? $ok++ : $bad++;
    }
    return ['synced' => $ok, 'failed' => $bad];
}

/** Mark a recipient delivered and queue its sheet write-back. */
function mo_mark_recipient_sent(PDO $pdo, array $recipient, string $msgId, string $threadId): array {
    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='sent', gmail_message_id=?, gmail_thread_id=?,
        sent_at=COALESCE(sent_at, UTC_TIMESTAMP()), error=NULL, skip_reason=NULL, sheet_sync_status='pending' WHERE id=?")
        ->execute([$msgId !== '' ? $msgId : null, $threadId !== '' ? $threadId : ($recipient['gmail_thread_id'] ?? null), $recipient['id']]);
    $r = $pdo->prepare('SELECT * FROM marketing_campaign_recipients WHERE id=?');
    $r->execute([$recipient['id']]);
    return $r->fetch(PDO::FETCH_ASSOC) ?: $recipient;
}

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
 * CRM (MySQL) prospect source - Phase 2. Enabled only by the
 * MARKETING_CRM_READS env flag. When the flag is on but the CRM tables
 * are missing the request fails closed (503) rather than silently
 * falling back to the sheet, so a partial migration can never produce
 * split-brain reads.
 */
function mo_crm(PDO $pdo): ?ProspectCrm {
    if (!isFeatureEnabled('MARKETING_CRM_READS')) return null;
    $crm = new ProspectCrm($pdo);
    if (!$crm->available()) {
        mo_fail('crm_not_migrated: run migrations/2026_11_13_marketing_crm.php and 2026_11_14_marketing_crm_phase2.php', 503);
    }
    return $crm;
}

/**
 * CRM write layer (Phase 3). Only available when MARKETING_CRM_READS is
 * on AND the Phase 3 migration has run; otherwise 503. Mutations are
 * MySQL-only by design - the Sheet is never written here.
 */
function mo_crm_admin(PDO $pdo, string $actor): ProspectCrmAdmin {
    $crm = mo_crm($pdo); // fails closed when flag on but tables missing
    if ($crm === null) {
        mo_fail('crm_disabled: MARKETING_CRM_READS is not enabled', 403);
    }
    try {
        $pdo->query('SELECT version FROM marketing_contacts LIMIT 0');
        $pdo->query('SELECT contact_id FROM marketing_campaign_recipients LIMIT 0');
    } catch (Throwable $e) {
        mo_fail('schema_not_migrated: run migrations/2026_11_15_marketing_crm_phase3.php', 503);
    }
    return new ProspectCrmAdmin($pdo, $actor);
}

/** Public prospect-list item shape (shared by sheet and CRM reads). */
function mo_prospect_item(array $p): array {
    return [
        'row' => $p['row'],
        // Phase 3: full fields map carries stable ids (contact_id/org_id),
        // version, review flags and editable columns for the CRM UI.
        'fields' => $p['fields'],
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
        'eligible_initial' => !empty($p['eligibility']['initial']['ok']),
        'initial_flags' => $p['eligibility']['initial']['flags'] ?? [],
        'eligible_followup' => !empty($p['eligibility']['followup']['ok']),
        'followup_flags' => $p['eligibility']['followup']['flags'] ?? [],
    ];
}

/**
 * Load all prospects; returns [records, ?errorCode, directory|null].
 * Never throws - sheet/auth/db failures degrade to an empty list plus a
 * stable error-code string so the UI can distinguish "no prospects" from
 * "the sheet could not be read" and show the right message. Codes are
 * non-sensitive (see SheetSyncException); details go to error_log only.
 */
function mo_load_prospects(PDO $pdo): array {
    try {
        $dir = mo_directory($pdo);
        return [$dir->prospects(), null, $dir];
    } catch (Throwable $e) {
        $code = SheetSyncException::UNKNOWN;
        if ($e instanceof SheetSyncException) $code = $e->errorCode;
        elseif ($e instanceof PDOException) $code = SheetSyncException::DB;
        error_log('[marketing-admin] prospect load [' . $code . ']: ' . $e->getMessage());
        return [[], $code, null];
    }
}

/**
 * Content hash binding approval to exact campaign content. Covers the
 * campaign identity (name/kind - both appear on sent mail and sheet
 * write-backs), every recipient row's membership + rendered content,
 * and resolved thread references. Any add/remove/edit changes it.
 */
function mo_content_hash(PDO $pdo, string $campaignId): string {
    $c = $pdo->prepare("SELECT name,kind FROM marketing_campaigns WHERE id=?");
    $c->execute([$campaignId]);
    $meta = $c->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'kind' => ''];
    $rows = $pdo->prepare("SELECT id,contact_id,sheet_row,prospect_email,subject,body_html,thread_status,gmail_thread_id,thread_rfc_message_id,thread_references,thread_subject,thread_date FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
    $rows->execute([$campaignId]);
    // Material delivery content is part of what was approved: the sender
    // signature config and the managed footer template. Dynamic
    // unsubscribe tokens are per-send and intentionally excluded.
    $env = [
        MarketingSignature::fingerprint(MarketingSignature::load($pdo)),
        hash('sha256', MarketingFooter::html('__preview__')),
    ];
    return hash('sha256', json_encode([$meta['name'], $meta['kind'], $rows->fetchAll(PDO::FETCH_ASSOC), $env]));
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

    $kindLabel = $kind === 'followup' ? 'follow-up' : 'initial outreach';
    $system = 'You are a concise, professional B2B copywriter for DentaTrak, dental lab case-tracking software. '
        . 'Write ' . $kindLabel . ' email copy only. No medical claims, no patient data, no hype. '
        . 'Return ONLY a JSON object: {"subject": string, "body_html": string}. '
        . 'body_html is a short HTML fragment (p and ul/li allowed, inline styles only, no scripts, no footer, no unsubscribe link, no images). '
        . '2-4 short paragraphs max. Include one clear low-pressure call to action (a reply or a 15-minute demo). '
        . 'Personalize using ONLY the prospect facts provided; never invent facts, locations, practice details, or operational problems. '
        . 'Tone: natural, professional, conversational - like a note from a knowledgeable colleague, not a sales pitch. '
        . 'Position DentaTrak as helping improve visibility, coordination, and efficiency for dental labs and practices; '
        . 'it complements practice-management software rather than replacing it. '
        . 'Do NOT claim DentaTrak eliminates manual work, guarantees on-time case delivery, or produces specific outcomes. '
        . 'Do NOT assume the recipient has a problem; acknowledge they may already use other software. '
        . ($kind === 'followup'
            ? 'This is a FOLLOW-UP: briefly reference the earlier note without pressure, add one small new reason to reply, '
              . 'and keep it shorter than the initial email. Do not repeat the full pitch. '
            : 'This is INITIAL outreach: introduce DentaTrak briefly and ask for a reply or short demo. ');

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
            $crm = mo_crm($pdo);
            if ($crm !== null) {
                $oc = $crm->overviewCounts();
                $campaigns = $pdo->query("SELECT status, COUNT(*) n FROM marketing_campaigns GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
                $sent30 = (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE status='sent' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetchColumn();
                $suppressed = (int)$pdo->query("SELECT COUNT(*) FROM marketing_suppressions")->fetchColumn();
                mo_out(['success' => true, 'overview' => [
                    'prospects_total' => $oc['prospects_total'],
                    'prospects_eligible' => $oc['prospects_eligible'],
                    'eligible_initial' => $oc['eligible_initial'],
                    'eligible_followup' => $oc['eligible_followup'],
                    'sending' => $sendingPolicy->summary(),
                    'flags' => $oc['flags'],
                    'followup_buckets' => $oc['followup_buckets'],
                    'campaigns' => $campaigns,
                    'sends_30d' => $sent30,
                    'suppressions' => $suppressed,
                    'sheet_error' => null,
                    'source' => 'crm',
                ]]);
            }
            [$prospects, $sheetErr] = mo_load_prospects($pdo);
            $buckets = ['due_today'=>0,'overdue'=>0,'upcoming'=>0,'awaiting_response'=>0,'not_contacted'=>0,'completed'=>0,'excluded'=>0];
            $eligible = 0; $flags = ['missing_contact'=>0,'do_not_contact'=>0,'duplicate_org'=>0];
            $eligInitial = 0; $eligFollowup = 0;
            foreach ($prospects as $p) {
                $buckets[$p['bucket']] = ($buckets[$p['bucket']] ?? 0) + 1;
                if ($p['eligible']) $eligible++;
                if (!empty($p['eligibility']['initial']['ok'])) $eligInitial++;
                if (!empty($p['eligibility']['followup']['ok'])) $eligFollowup++;
                foreach ($p['flags'] as $fl) $flags[$fl] = ($flags[$fl] ?? 0) + 1;
            }
            $campaigns = $pdo->query("SELECT status, COUNT(*) n FROM marketing_campaigns GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            $sent30 = (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE status='sent' AND sent_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY")->fetchColumn();
            $suppressed = (int)$pdo->query("SELECT COUNT(*) FROM marketing_suppressions")->fetchColumn();
            mo_out(['success' => true, 'overview' => [
                'prospects_total' => count($prospects),
                'prospects_eligible' => $eligible,
                'eligible_initial' => $eligInitial,
                'eligible_followup' => $eligFollowup,
                'sending' => $sendingPolicy->summary(),
                'flags' => $flags,
                'followup_buckets' => $buckets,
                'campaigns' => $campaigns,
                'sends_30d' => $sent30,
                'suppressions' => $suppressed,
                'sheet_error' => $sheetErr,
            ]]);
        }
        case 'dashboard': {
            // Performance dashboard. Prospect-side aggregates come from
            // ProspectCrm::dashboard (all-time inventory over the filtered
            // set); send/campaign figures come from the send ledger and
            // campaign tables (date-scoped where a range is given).
            $crm = mo_crm($pdo);
            if ($crm === null) mo_fail('crm_unavailable', 503);

            // ---- date range (UTC; sent_at is stored UTC) ----
            $today = gmdate('Y-m-d');
            $preset = (string)($_GET['days'] ?? '30');
            $from = trim((string)($_GET['from'] ?? ''));
            $to   = trim((string)($_GET['to'] ?? ''));
            if ($from === '' || $to === '') {
                $n = in_array($preset, ['7', '30', '90'], true) ? (int)$preset : 30;
                $to = $today;
                $from = gmdate('Y-m-d', strtotime($today . ' -' . ($n - 1) . ' days'));
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
                || $from > $to || $to > $today) {
                mo_fail('invalid date range');
            }
            // Cap unbounded ranges at ~2 years of daily buckets.
            if (strtotime($to) - strtotime($from) > 730 * 86400) mo_fail('date range too large');
            $fromTs = $from . ' 00:00:00';
            $toTs   = gmdate('Y-m-d H:i:s', strtotime($to . ' +1 day'));
            $days   = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
            // Prior equivalent period for delta comparisons.
            $priorFrom = gmdate('Y-m-d', strtotime($from) - $days * 86400) . ' 00:00:00';
            $priorTo   = $fromTs;

            $cid  = trim((string)($_GET['campaign_id'] ?? ''));
            $kind = in_array(($_GET['kind'] ?? ''), ['initial', 'followup'], true) ? (string)$_GET['kind'] : '';
            $campRef = $cid !== '' ? 'mo:' . $cid : null;

            // ---- verified sends from the ledger ----
            $sendArgs = [$fromTs, $toTs];
            $sendWhere = "status='sent' AND sent_at>=? AND sent_at<?";
            if ($campRef !== null) { $sendWhere .= ' AND campaign_ref=?'; $sendArgs[] = $campRef; }
            $st = $pdo->prepare("SELECT COUNT(*) FROM marketing_sends WHERE $sendWhere");
            $st->execute($sendArgs);
            $sendsPeriod = (int)$st->fetchColumn();

            $st = $pdo->prepare("SELECT COUNT(*) FROM marketing_sends WHERE status='sent' AND sent_at>=? AND sent_at<?"
                . ($campRef !== null ? ' AND campaign_ref=?' : ''));
            $st->execute($campRef !== null ? [$priorFrom, $priorTo, $campRef] : [$priorFrom, $priorTo]);
            $sendsPrior = (int)$st->fetchColumn();

            // Daily series: verified sends plus failed/uncertain attempts.
            $serArgs = [$fromTs, $toTs];
            $serWhere = "sent_at>=? AND sent_at<? AND status IN ('sent','failed','uncertain')";
            if ($campRef !== null) { $serWhere .= ' AND campaign_ref=?'; $serArgs[] = $campRef; }
            $st = $pdo->prepare("SELECT DATE(sent_at) d, status, COUNT(*) n FROM marketing_sends
                                 WHERE $serWhere GROUP BY DATE(sent_at), status");
            $st->execute($serArgs);
            $series = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $series[$r['d']][$r['status']] = (int)$r['n'];
            }

            $ledgerAll = (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE status='sent'")->fetchColumn();
            $ledgerProblems = (int)$pdo->query("SELECT COUNT(*) FROM marketing_sends WHERE status IN ('failed','uncertain')")->fetchColumn();

            // ---- campaign performance ----
            $campSql = "SELECT c.id, c.name, c.kind, c.status, c.created_at, c.recipient_count,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id) AS recipients,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id AND r.status='sent') AS sent,
                    (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id AND r.status IN ('failed','uncertain')) AS problems,
                    (SELECT MAX(r.sent_at) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id) AS last_sent_at,
                    (SELECT MAX(r.updated_at) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id) AS last_activity_at
                FROM marketing_campaigns c";
            $campArgs = [];
            $campWhere = [];
            if ($cid !== '') { $campWhere[] = 'c.id=?'; $campArgs[] = $cid; }
            if ($kind !== '') { $campWhere[] = 'c.kind=?'; $campArgs[] = $kind; }
            if ($campWhere) $campSql .= ' WHERE ' . implode(' AND ', $campWhere);
            $campSql .= ' ORDER BY c.created_at DESC LIMIT 100';
            $st = $pdo->prepare($campSql);
            $st->execute($campArgs);
            $campaignsPerf = $st->fetchAll(PDO::FETCH_ASSOC);

            // Campaign-side attention counts (respect kind filter).
            $attSql = "SELECT status, COUNT(*) n FROM marketing_campaigns" . ($kind !== '' ? ' WHERE kind=?' : '') . ' GROUP BY status';
            $st = $pdo->prepare($attSql);
            $st->execute($kind !== '' ? [$kind] : []);
            $campaignStatus = $st->fetchAll(PDO::FETCH_KEY_PAIR);

            // ---- detected replies (Gmail reply tracking) ----
            // Numerator/denominator for response rate share the same period:
            // human replies received in range / verified sends in range.
            $replyWhere = 'r.gmail_internal_date>=? AND r.gmail_internal_date<?';
            $replyArgs = [$from . ' 00:00:00', $to . ' 23:59:59'];
            $replyJoin = '';
            if ($cid !== '') { $replyWhere .= ' AND r.campaign_id=?'; $replyArgs[] = $cid; }
            if ($kind !== '') { $replyJoin = ' JOIN marketing_campaigns k ON k.id=r.campaign_id'; $replyWhere .= ' AND k.kind=?'; $replyArgs[] = $kind; }
            $st = $pdo->prepare("SELECT r.classification, COUNT(*) n,
                    SUM(r.review_status='new') new_n, MAX(r.gmail_internal_date) last_at
                 FROM marketing_reply_events r $replyJoin WHERE $replyWhere GROUP BY r.classification");
            $st->execute($replyArgs);
            $replyCounts = ['human' => 0, 'auto_response' => 0, 'delivery_failure' => 0,
                            'unsubscribe_request' => 0, 'uncertain' => 0];
            $replyNew = 0; $lastReplyAt = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cls = (string)$r['classification'];
                if (isset($replyCounts[$cls])) $replyCounts[$cls] = (int)$r['n'];
                $replyNew += (int)$r['new_n'];
                if ($r['last_at'] && (!$lastReplyAt || $r['last_at'] > $lastReplyAt)) $lastReplyAt = $r['last_at'];
            }
            // Prior-period human replies for delta.
            $st = $pdo->prepare("SELECT COUNT(*) FROM marketing_reply_events r $replyJoin
                 WHERE r.classification='human' AND r.gmail_internal_date>=? AND r.gmail_internal_date<?"
                . ($cid !== '' ? ' AND r.campaign_id=?' : '')
                . ($kind !== '' ? ' AND k.kind=?' : ''));
            $priorArgs = [$priorFrom, $priorTo];
            if ($cid !== '') $priorArgs[] = $cid;
            if ($kind !== '') $priorArgs[] = $kind;
            $st->execute($priorArgs);
            $repliesPrior = (int)$st->fetchColumn();
            // Daily human-reply series for the activity chart.
            $st = $pdo->prepare("SELECT DATE(r.gmail_internal_date) d, COUNT(*) n
                 FROM marketing_reply_events r $replyJoin
                 WHERE r.classification='human' AND r.gmail_internal_date>=? AND r.gmail_internal_date<?"
                . ($cid !== '' ? ' AND r.campaign_id=?' : '')
                . ($kind !== '' ? ' AND k.kind=?' : '')
                . ' GROUP BY DATE(r.gmail_internal_date)');
            $st->execute($priorArgs = array_merge([$from . ' 00:00:00', $to . ' 23:59:59'],
                $cid !== '' ? [$cid] : [], $kind !== '' ? [$kind] : []));
            $replySeries = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $replySeries[$r['d']] = (int)$r['n'];
            // Per-campaign reply rollup for the performance table.
            $st = $pdo->query("SELECT campaign_id, classification, COUNT(*) n
                 FROM marketing_reply_events WHERE campaign_id IS NOT NULL GROUP BY campaign_id, classification");
            $replyPerCampaign = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $replyPerCampaign[$r['campaign_id']][$r['classification']] = (int)$r['n'];
            }
            // Cohort numerator: distinct recipients with >=1 human reply per
            // campaign (period-independent; immune to repeat replies).
            $st = $pdo->query("SELECT campaign_id,
                    COUNT(DISTINCT COALESCE(campaign_recipient_id, contact_id, gmail_message_id)) n
                 FROM marketing_reply_events
                 WHERE campaign_id IS NOT NULL AND classification='human'
                 GROUP BY campaign_id");
            $cohortReplies = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            // Verified sends per campaign from the send ledger (authoritative;
            // imported 'Sent' statuses are not counted).
            $st = $pdo->query("SELECT campaign_ref, COUNT(*) n FROM marketing_sends
                 WHERE status='sent' AND campaign_ref LIKE 'mo:%' GROUP BY campaign_ref");
            $verifiedByCamp = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $verifiedByCamp[substr($r['campaign_ref'], 3)] = (int)$r['n'];
            }
            // Bounce sub-kinds + suppressed-contact count for KPIs.
            $st = $pdo->query("SELECT bounce_kind, COUNT(*) n FROM marketing_reply_events
                 WHERE classification='delivery_failure' GROUP BY bounce_kind");
            $bounceKinds = ['permanent' => 0, 'temporary' => 0, 'ambiguous' => 0];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string)($r['bounce_kind'] ?? 'ambiguous');
                if (isset($bounceKinds[$k])) $bounceKinds[$k] = (int)$r['n'];
                else $bounceKinds['ambiguous'] += (int)$r['n'];
            }
            $suppressedContacts = (int)$pdo->query(
                "SELECT COUNT(DISTINCT c.id) FROM marketing_reply_events r
                 JOIN marketing_contacts c ON c.id = r.contact_id
                 JOIN marketing_suppressions ms ON ms.email_normalized = c.email_normalized")->fetchColumn();
            $campaignsPerf = array_map(function ($c) use ($replyPerCampaign, $cohortReplies, $verifiedByCamp) {
                $rc = $replyPerCampaign[$c['id']] ?? [];
                $c['replies_human'] = (int)($rc['human'] ?? 0);
                $c['replies_other'] = (int)($rc['auto_response'] ?? 0) + (int)($rc['delivery_failure'] ?? 0)
                    + (int)($rc['unsubscribe_request'] ?? 0) + (int)($rc['uncertain'] ?? 0);
                // Cohort response rate: distinct replying recipients /
                // verified ledger sends for this campaign. Null when there
                // are no verified sends (rate unavailable, not zero).
                $c['verified_sends'] = (int)($verifiedByCamp[$c['id']] ?? 0);
                $c['cohort_replies'] = (int)($cohortReplies[$c['id']] ?? 0);
                $c['cohort_rate'] = $c['verified_sends'] > 0
                    ? round($c['cohort_replies'] / $c['verified_sends'] * 100, 1) : null;
                return $c;
            }, $campaignsPerf);
            // New-review counts + last reply date per campaign.
            $st = $pdo->query("SELECT campaign_id, COUNT(*) n FROM marketing_reply_events
                 WHERE review_status='new' AND campaign_id IS NOT NULL GROUP BY campaign_id");
            $newByCamp = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            $st = $pdo->query("SELECT campaign_id, MAX(gmail_internal_date) d FROM marketing_reply_events
                 WHERE campaign_id IS NOT NULL GROUP BY campaign_id");
            $lastByCamp = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($campaignsPerf as &$cp) {
                $cp['replies_new'] = (int)($newByCamp[$cp['id']] ?? 0);
                $cp['last_reply_at'] = $lastByCamp[$cp['id']] ?? null;
            }
            unset($cp);

            // Sync health for the UI.
            $replySync = ['tracked' => true];
            try {
                $rt = new ReplyTracker($pdo, function () { return ['status' => null, 'data' => []]; }, 'dashboard');
                $replySync = array_merge($replySync, $rt->syncStatus());
            } catch (Throwable $e) { $replySync['error'] = 'unavailable'; }

            // Filter dropdown options come from the unfiltered prospect set.
            $prospect = $crm->dashboard([
                'org_type' => (string)($_GET['org_type'] ?? ''),
                'location' => (string)($_GET['location'] ?? ''),
            ]);
            $allOpts = $crm->dashboard([]);

            mo_out(['success' => true, 'dashboard' => [
                'sending' => $sendingPolicy->summary(),
                'range' => ['from' => $from, 'to' => $to, 'days' => $days],
                'filters' => ['campaign_id' => $cid, 'kind' => $kind,
                    'org_type' => (string)($_GET['org_type'] ?? ''), 'location' => (string)($_GET['location'] ?? '')],
                'sends' => [
                    'period' => $sendsPeriod, 'prior' => $sendsPrior,
                    'ledger_total' => $ledgerAll, 'ledger_problems' => $ledgerProblems,
                    'series' => $series,
                ],
                'replies' => [
                    'tracked' => true,
                    'counts' => $replyCounts,
                    'bounce_kinds' => $bounceKinds,
                    'suppressed_contacts' => $suppressedContacts,
                    'period_human' => $replyCounts['human'],
                    'prior_human' => $repliesPrior,
                    'new_review' => $replyNew,
                    'last_reply_at' => $lastReplyAt,
                    'series' => $replySeries,
                    'sync' => $replySync,
                ],
                'campaigns' => $campaignsPerf,
                'campaign_status' => $campaignStatus,
                'prospect' => $prospect,
                'filter_options' => $allOpts['filter_options'],
                'campaign_options' => $pdo->query("SELECT id, name, kind FROM marketing_campaigns ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC),
            ]]);
        }
        case 'replies': {
            // Detected Gmail replies awaiting/undergoing review.
            if (!isFeatureEnabled('MARKETING_CRM_READS')) mo_fail('crm unavailable', 503);
            $rt = new ReplyTracker($pdo, function () { return ['status' => null, 'data' => []]; }, 'admin');
            $rows = $rt->listReplies([
                'status' => (string)($_GET['status'] ?? ''),
                'class' => (string)($_GET['class'] ?? ''),
                'contact_id' => (int)($_GET['contact_id'] ?? 0) ?: null,
                'limit' => (int)($_GET['limit'] ?? 100),
            ]);
            mo_out(['success' => true, 'replies' => $rows, 'sync' => $rt->syncStatus()]);
        }
        case 'prospects': {
            $crm = mo_crm($pdo);
            if ($crm !== null) {
                // Server-side search/filter/sort/pagination in SQL.
                $res = $crm->search([
                    'q' => $_GET['q'] ?? '', 'flag' => $_GET['flag'] ?? '', 'bucket' => $_GET['bucket'] ?? '',
                    'status' => $_GET['status'] ?? '', 'priority' => $_GET['priority'] ?? '',
                    'org_type' => $_GET['org_type'] ?? '', 'eligible' => $_GET['eligible'] ?? '',
                    'review' => $_GET['review'] ?? '',
                    'sort' => $_GET['sort'] ?? '', 'dir' => $_GET['dir'] ?? '',
                    'page' => $_GET['page'] ?? 1, 'per' => $_GET['per'] ?? 50,
                ]);
                mo_out(['success' => true,
                    'prospects' => array_map('mo_prospect_item', $res['rows']),
                    'total' => $res['total'], 'page' => max(1, (int)($_GET['page'] ?? 1)), 'per' => min(200, max(1, (int)($_GET['per'] ?? 50))),
                    'sheet_error' => null, 'source' => 'crm']);
            }
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
            mo_out(['success' => true, 'prospects' => array_map('mo_prospect_item', $slice), 'total' => $total, 'page' => $page, 'per' => $per,
               'sheet_error' => $sheetErr]);
        }
        case 'prospect': {
            $crm = mo_crm($pdo);
            if ($crm !== null) {
                // Phase 3: detail by contact id (stable identity) or email.
                $cid = (int)($_GET['id'] ?? 0);
                $reqEmail = SuppressionService::normalizeEmail((string)($_GET['email'] ?? ''));
                if ($cid <= 0 && $reqEmail === '') mo_fail('email or id required');
                $p = $cid > 0 ? $crm->findById($cid) : $crm->findByEmail($reqEmail);
                if (!$p) mo_fail('not found', 404);
                $contactId = (int)($p['fields']['contact_id'] ?? 0);
                $email = SuppressionService::normalizeEmail((string)$p['fields']['email']);
                $hist = $pdo->prepare("SELECT campaign_ref,status,gmail_message_id,sent_at,created_at FROM marketing_sends WHERE email_normalized=? ORDER BY id DESC LIMIT 50");
                $hist->execute([$email]);
                // Activity timeline (CRM events) - manual entries are
                // labeled distinctly from verified sends.
                $ev = $pdo->prepare("SELECT event_type, details, actor, source, created_at FROM marketing_prospect_events
                                     WHERE contact_id=? ORDER BY id DESC LIMIT 100");
                $ev->execute([$contactId]);
                $events = $ev->fetchAll(PDO::FETCH_ASSOC);
                // Campaign memberships via stable contact_id (Phase 3) or
                // legacy email match for sheet-era recipients.
                $mc = $pdo->prepare("SELECT cr.campaign_id, c.name, c.kind, c.status, cr.status AS recipient_status, cr.sent_at
                                     FROM marketing_campaign_recipients cr
                                     JOIN marketing_campaigns c ON c.id=cr.campaign_id
                                     WHERE cr.contact_id=? OR (cr.contact_id IS NULL AND cr.prospect_email=?)
                                     ORDER BY cr.id DESC LIMIT 50");
                $mc->execute([$contactId, $email]);
                // Org detail for the drawer.
                $orgId = $p['fields']['org_id'] ?? null;
                $org = null;
                if ($orgId) {
                    $og = $pdo->prepare('SELECT id,name,org_type,domain,location,website,parent_org_id,status,tags FROM marketing_orgs WHERE id=?');
                    $og->execute([(int)$orgId]);
                    $org = $og->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                mo_out(['success' => true, 'prospect' => $p, 'org' => $org,
                    'events' => $events, 'campaigns' => $mc->fetchAll(PDO::FETCH_ASSOC),
                    'history' => $hist->fetchAll(PDO::FETCH_ASSOC), 'source' => 'crm']);
            }
            $email = SuppressionService::normalizeEmail((string)($_GET['email'] ?? ''));
            if ($email === '') mo_fail('email required');
            [, $sheetErr, $dir] = mo_load_prospects($pdo);
            if ($dir === null) mo_fail('prospect sheet unavailable: ' . $sheetErr, 503);
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
            $crm = mo_crm($pdo);
            if ($crm !== null) {
                $all = $crm->followups($bucket);
                mo_out(['success' => true, 'followups' => array_map(function ($p) {
                    return [
                        'row' => $p['row'], 'id' => $p['id'] ?? null,
                        'email' => $p['fields']['email'], 'org' => $p['fields']['org'],
                        'contact_name' => $p['contact_name'], 'status' => $p['fields']['status'],
                        'response' => $p['fields']['response'], 'followup_ts' => $p['followup_ts'],
                        'last_contact' => $p['fields']['last_contact'], 'notes' => $p['fields']['notes'],
                        'bucket' => $p['bucket'], 'flags' => $p['flags'],
                        'eligible' => !empty($p['eligibility']['followup']['ok']),
                        'followup_flags' => $p['eligibility']['followup']['flags'] ?? [],
                    ];
                }, $all), 'sheet_error' => null, 'source' => 'crm']);
            }
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
                    'row' => $p['row'], 'id' => $p['id'] ?? null,
                    'email' => $p['fields']['email'], 'org' => $p['fields']['org'],
                    'contact_name' => $p['contact_name'], 'status' => $p['fields']['status'],
                    'response' => $p['fields']['response'], 'followup_ts' => $p['followup_ts'],
                    'last_contact' => $p['fields']['last_contact'], 'notes' => $p['fields']['notes'],
                    'bucket' => $p['bucket'], 'flags' => $p['flags'],
                    // Follow-up list: eligibility is the follow-up rule.
                    'eligible' => !empty($p['eligibility']['followup']['ok']),
                    'followup_flags' => $p['eligibility']['followup']['flags'] ?? [],
                ];
            }, $all), 'sheet_error' => $sheetErr]);
        }
        case 'orgs': {
            $crm = mo_crm($pdo);
            if ($crm === null) mo_fail('crm_disabled', 403);
            $admin = new ProspectCrmAdmin($pdo, $userEmail);
            $res = $admin->listOrgs([
                'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? 1, 'per' => $_GET['per'] ?? 50,
            ]);
            mo_out(['success' => true, 'orgs' => $res['rows'], 'total' => $res['total'],
                'page' => max(1, (int)($_GET['page'] ?? 1)), 'source' => 'crm']);
        }
        case 'org': {
            $crm = mo_crm($pdo);
            if ($crm === null) mo_fail('crm_disabled', 403);
            $id = (int)($_GET['id'] ?? 0);
            $org = $crm->orgById($id);
            if (!$org) mo_fail('not found', 404);
            // Parent + children for relationship display.
            $parent = $org['parent_org_id'] ? $crm->orgById((int)$org['parent_org_id']) : null;
            $ch = $pdo->prepare('SELECT id,name,org_type FROM marketing_orgs WHERE parent_org_id=? ORDER BY name');
            $ch->execute([$id]);
            mo_out(['success' => true, 'org' => $org, 'parent' => $parent,
                'children' => $ch->fetchAll(PDO::FETCH_ASSOC),
                'contacts' => array_map('mo_prospect_item', $crm->contactsForOrg($id)),
                'source' => 'crm']);
        }
        case 'export': {
            // CSV export - streams text/csv, not JSON. CRM flag required.
            $crm = mo_crm($pdo);
            if ($crm === null) mo_fail('crm_disabled', 403);
            $admin = new ProspectCrmAdmin($pdo, $userEmail);
            $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
            $filters = [
                'q' => $_GET['q'] ?? '', 'flag' => $_GET['flag'] ?? '', 'bucket' => $_GET['bucket'] ?? '',
                'status' => $_GET['status'] ?? '', 'priority' => $_GET['priority'] ?? '',
                'org_type' => $_GET['org_type'] ?? '', 'eligible' => $_GET['eligible'] ?? '',
            ];
            $rows = $admin->exportRows($filters, $ids ?: null);
            mo_audit($pdo, $userEmail, 'prospect_export', null,
                ['count' => count($rows), 'ids' => $ids ? count($ids) : null, 'filters' => $filters]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="prospects-' . gmdate('Ymd-His') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $r) fputcsv($out, $r);
            }
            fclose($out);
            exit;
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
            $r = $pdo->prepare("SELECT id,contact_id,sheet_row,prospect_email,prospect_org,prospect_contact,subject,body_html,status,skip_reason,is_edited,gmail_message_id,gmail_thread_id,error,sent_at,thread_status,thread_reason,thread_source,thread_subject,thread_date,sheet_sync_status,sheet_sync_error,sheet_synced_at FROM marketing_campaign_recipients WHERE campaign_id=? ORDER BY id");
            $r->execute([$id]);
            mo_out(['success' => true, 'campaign' => $camp, 'recipients' => $r->fetchAll(PDO::FETCH_ASSOC),
                'signature' => MarketingSignature::load($pdo),
                'sending' => $sendingPolicy->summary()]);
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

        // Resolve each email against the prospect source (CRM when enabled,
        // otherwise the sheet); ineligible prospects are still recorded but
        // flagged so the UI can show why.
        $crm = mo_crm($pdo);
        if ($crm !== null) {
            $crmMap = $crm->findByEmails($emails);
            $sheetErr = null;
        } else {
            [$sheetProspects, $sheetErr] = mo_load_prospects($pdo);
            if ($sheetErr) mo_fail('prospect sheet unavailable: ' . $sheetErr, 503);
            $crmMap = [];
            foreach ($sheetProspects as $p) $crmMap[strtolower($p['fields']['email'])] = $p;
        }
        $prospects = $crmMap;

        $id = mo_uuid();
        $pdo->prepare("INSERT INTO marketing_campaigns (id,name,kind,status,created_by,recipient_count) VALUES (?,?,?,'draft',?,?)")
            ->execute([$id, substr($name, 0, 160), $kind, $userEmail, count($emails)]);
        $ins = $pdo->prepare("INSERT INTO marketing_campaign_recipients
            (campaign_id,contact_id,sheet_row,prospect_email,prospect_org,prospect_contact,status,skip_reason)
            VALUES (?,?,?,?,?,?,?,?)");
        foreach ($emails as $e) {
            $p = $prospects[$e] ?? null;
            $reason = null; $status = 'draft';
            if (!$p) { $status = 'suppressed'; $reason = 'not_in_sheet'; }
            elseif (in_array('missing_contact', $p['flags'], true)) { $status = 'suppressed'; $reason = 'missing_contact'; }
            elseif (empty($p['eligibility'][$kind]['ok'])) {
                // Initial: already contacted / duplicate org. Follow-up: no
                // prior outreach record / replied. Both: permanent exclusions.
                $status = 'suppressed';
                $reason = substr(implode(',', $p['eligibility'][$kind]['flags'] ?? ['ineligible']), 0, 190);
            }
            $ins->execute([$id, isset($p['id']) ? (int)$p['id'] : null, $p['row'] ?? null, $e,
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

        // Optional template: per-campaign override wins, else kind default.
        if (!empty($camp['template_id'])) {
            $tpl = $pdo->prepare("SELECT * FROM marketing_templates WHERE id=? AND kind=?");
            $tpl->execute([(int)$camp['template_id'], $camp['kind']]);
            $template = $tpl->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $tpl = $pdo->prepare("SELECT * FROM marketing_templates WHERE kind=? ORDER BY is_default DESC, id LIMIT 1");
            $tpl->execute([$camp['kind']]);
            $template = $tpl->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $crm = mo_crm($pdo);
        if ($crm !== null) {
            $byEmail = null; // resolved per-campaign below
            $sheetErr = null;
        } else {
            [$sheetProspects, $sheetErr] = mo_load_prospects($pdo);
            if ($sheetErr) mo_fail('prospect sheet unavailable: ' . $sheetErr, 503);
        }

        $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND status IN ('draft','ready','suppressed') ORDER BY id");
        $rs->execute([$cid]);
        $recips = $rs->fetchAll(PDO::FETCH_ASSOC);
        if ($crm !== null) {
            // One batch lookup for the whole campaign (last row wins on
            // duplicate emails, matching the sheet map semantics).
            $byEmail = $crm->findByEmails(array_map(static fn($r) => (string)$r['prospect_email'], $recips));
        } else {
            $byEmail = [];
            foreach ($sheetProspects as $p) $byEmail[strtolower($p['fields']['email'])] = $p;
        }
        $done = 0; $failed = []; $threads = [];
        $threadMailer = $camp['kind'] === 'followup' ? new MarketingMailer($pdo, $baseUrl) : null;
        foreach ($recips as $r) {
            if ($ridFilter && !in_array((int)$r['id'], $ridFilter, true)) continue;
            if ($r['status'] === 'suppressed') continue; // never generate for suppressed
            // Stable contact_id is authoritative when present; email map is
            // the sheet-mode fallback (and covers legacy recipients).
            $p = null;
            if ($crm !== null && !empty($r['contact_id'])) {
                $p = $crm->findById((int)$r['contact_id']);
            }
            if ($p === null) {
                $p = $byEmail[SuppressionService::normalizeEmail((string)$r['prospect_email'])] ?? null;
            }
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
            if ($threadMailer !== null) {
                // Follow-ups reply inside the original conversation when it
                // can be identified; otherwise the limitation is recorded
                // and shown, never guessed.
                try {
                    $t = mo_resolve_thread($pdo, $threadMailer, $r, (string)($p['fields']['notes'] ?? ''), $cid);
                    $threads[$t['status']] = ($threads[$t['status']] ?? 0) + 1;
                } catch (Throwable $e) {
                    error_log('[marketing-admin] thread resolve: ' . $e->getMessage());
                }
            }
        }
        // Campaign becomes 'ready' once every non-suppressed recipient has content
        $remaining = $pdo->prepare("SELECT COUNT(*) FROM marketing_campaign_recipients WHERE campaign_id=? AND status='draft'");
        $remaining->execute([$cid]);
        if ((int)$remaining->fetchColumn() === 0) {
            $pdo->prepare("UPDATE marketing_campaigns SET status='ready' WHERE id=? AND status='draft'")->execute([$cid]);
        }
        mo_audit($pdo, $userEmail, 'campaign_generate', $cid, ['generated' => $done, 'failed' => count($failed), 'threads' => $threads]);
        mo_out(['success' => true, 'generated' => $done, 'failed' => $failed, 'threads' => $threads]);
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
        if (($row['thread_status'] ?? '') === 'resolved' && (string)$row['thread_subject'] !== '') {
            // Gmail only keeps a reply in its conversation when the subject
            // matches, so the subject of a threaded follow-up is fixed.
            $subject = (string)$row['thread_subject'];
        }
        $pdo->prepare("UPDATE marketing_campaign_recipients SET subject=?, body_html=?, is_edited=1, status='ready' WHERE id=?")
            ->execute([substr($subject, 0, 500), $body, $rid]);
        // Editing after approval invalidates it
        $pdo->prepare("UPDATE marketing_campaigns SET status='ready', approved_by=NULL, approved_at=NULL, content_hash=NULL WHERE id=? AND status='approved'")
            ->execute([$cid]);
        mo_audit($pdo, $userEmail, 'message_edit', $cid, ['recipient_id' => $rid]);
        mo_out(['success' => true]);
    }

    case 'preview_message': {
        // Read-only: assembles the EXACT parts send() would produce -
        // signature + managed footer - using a clearly nonfunctional
        // unsubscribe placeholder. No tokens are issued, nothing is
        // written, and approval state is untouched.
        $cid = (string)($in['campaign_id'] ?? '');
        $rid = (int)($in['recipient_id'] ?? 0);
        $r = $pdo->prepare("SELECT id,campaign_id,prospect_email,prospect_contact,subject,body_html,status,
            thread_status,thread_subject,thread_date FROM marketing_campaign_recipients WHERE id=? AND campaign_id=?");
        $r->execute([$rid, $cid]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) mo_fail('not found', 404);

        // Optional unsaved editor content may be previewed; it goes through
        // the same sanitizer as save_message.
        $subject = array_key_exists('subject', $in)
            ? substr(trim((string)$in['subject']), 0, 500)
            : (string)$row['subject'];
        $body = array_key_exists('body_html', $in)
            ? mo_sanitize_html((string)$in['body_html'])
            : (string)$row['body_html'];
        if ($body === '') mo_fail('no message content');

        $mailer = new MarketingMailer($pdo, $baseUrl);
        $placeholder = $baseUrl . '/unsubscribe.php?t=preview';
        $parts = $mailer->composeParts($body, $placeholder);

        // Sender identity comes from the connected Gmail account; the
        // display name is whatever that account sends as (not settable
        // via the API), so show the address honestly.
        $from = '';
        try {
            $prof = $mailer->gmailGet('/profile');
            $from = (string)($prof['data']['emailAddress'] ?? '');
        } catch (Throwable $e) { /* shown as unknown below */ }

        mo_out(['success' => true,
            'from' => $from !== '' ? $from : 'connected Gmail account',
            'to_name' => (string)($row['prospect_contact'] ?? ''),
            'to_email' => (string)$row['prospect_email'],
            'subject' => $subject,
            'html' => $parts['html'],
            'text' => $parts['text'],
            'unsub_placeholder' => true,
            'signature' => MarketingSignature::load($pdo),
            'thread' => [
                'status' => (string)($row['thread_status'] ?? ''),
                'subject' => (string)($row['thread_subject'] ?? ''),
                'date' => (string)($row['thread_date'] ?? ''),
                'evidence' => ($row['thread_reason'] ?? '') === 'transmission_unverified' ? 'unverified' : 'verified',
            ]]);
    }

    case 'save_signature': {
        $sig = MarketingSignature::save($pdo, [
            'name'   => (string)($in['name'] ?? ''),
            'title'  => (string)($in['title'] ?? ''),
            'detail' => (string)($in['detail'] ?? ''),
        ]);
        mo_audit($pdo, $userEmail, 'signature_save', null, $sig);
        mo_out(['success' => true, 'signature' => $sig]);
    }

    case 'reply_mark_reviewed': {
        $id = (int)($in['reply_id'] ?? 0);
        if ($id <= 0) mo_fail('reply_id required');
        $rt = new ReplyTracker($pdo, function () { return ['status' => null, 'data' => []]; }, 'admin');
        if (!$rt->markReviewed($id, (string)$userEmail, !empty($in['dismissed']))) mo_fail('not found', 404);
        mo_audit($pdo, $userEmail, 'reply_review', (string)$id, ['dismissed' => !empty($in['dismissed'])]);
        mo_out(['success' => true]);
    }

    case 'reply_sync_now': {
        // Admin-triggered bounded sync pass (same code path as the scheduler).
        $mailer = new MarketingMailer($pdo, $baseUrl);
        $rt = new ReplyTracker($pdo, function (string $path, array $q = []) use ($mailer) {
            return $mailer->gmailGet($path, $q);
        }, 'admin-manual');
        $r = $rt->sync();
        mo_audit($pdo, $userEmail, 'reply_sync', null, ['ok' => !empty($r['ok']), 'processed' => $r['processed'] ?? 0]);
        mo_out(['success' => !empty($r['ok']), 'result' => $r]);
    }

    case 'reply_backfill': {
        // Controlled historical scan. Dry-run by default; requires explicit
        // apply=1 plus days<=30. Never automatic.
        $days = max(1, min(30, (int)($in['days'] ?? 14)));
        $apply = !empty($in['apply']);
        $mailer = new MarketingMailer($pdo, $baseUrl);
        $rt = new ReplyTracker($pdo, function (string $path, array $q = []) use ($mailer) {
            return $mailer->gmailGet($path, $q);
        }, 'admin-backfill');
        $r = $rt->backfill($days, !$apply);
        mo_audit($pdo, $userEmail, 'reply_backfill', null, ['days' => $days, 'apply' => $apply, 'matched' => $r['matched'] ?? 0]);
        mo_out(['success' => !empty($r['ok']), 'report' => $r]);
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
        if ($camp['kind'] === 'followup') {
            // Every follow-up gets a thread decision before the content
            // hash is taken, so what is approved is what is sent.
            $pend = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND status='ready' AND thread_status IS NULL");
            $pend->execute([$cid]);
            $pending = $pend->fetchAll(PDO::FETCH_ASSOC);
            if ($pending) {
                $crm = mo_crm($pdo);
                $notesByEmail = [];
                if ($crm !== null) {
                    foreach ($crm->findByEmails(array_map(static fn($pr) => (string)$pr['prospect_email'], $pending)) as $e => $pp) {
                        $notesByEmail[$e] = (string)($pp['fields']['notes'] ?? '');
                    }
                } else {
                    [, , $dirForNotes] = mo_load_prospects($pdo);
                    if ($dirForNotes !== null) {
                        foreach ($pending as $pr) {
                            $pp = $dirForNotes->findByEmail((string)$pr['prospect_email']);
                            if ($pp) $notesByEmail[SuppressionService::normalizeEmail((string)$pr['prospect_email'])] = (string)($pp['fields']['notes'] ?? '');
                        }
                    }
                }
                $tm = new MarketingMailer($pdo, $baseUrl);
                foreach ($pending as $pr) {
                    $notes = $notesByEmail[SuppressionService::normalizeEmail((string)$pr['prospect_email'])] ?? '';
                    try { mo_resolve_thread($pdo, $tm, $pr, $notes, $cid); }
                    catch (Throwable $e) { error_log('[marketing-admin] thread resolve: ' . $e->getMessage()); }
                }
            }
        }
        // Follow-ups without a verified original Gmail thread would start
        // NEW conversations. That fallback must be an explicit decision:
        // approval requires allow_new_threads=true and records it on the
        // campaign so the send path can enforce it durably.
        $newThreads = 0;
        if ($camp['kind'] === 'followup') {
            $nt = $pdo->prepare("SELECT COUNT(*) FROM marketing_campaign_recipients
                WHERE campaign_id=? AND status='ready' AND (thread_status IS NULL OR thread_status<>'resolved')");
            $nt->execute([$cid]);
            $newThreads = (int)$nt->fetchColumn();
        }
        if ($newThreads > 0 && empty($in['allow_new_threads'])) {
            mo_fail('unresolved_threads: ' . $newThreads . ' follow-up(s) have no verified original '
                . 'conversation and would start a new email thread. Review them, then approve again '
                . 'with allow_new_threads=true to confirm.', 409);
        }
        if ($newThreads > 0) {
            $pdo->prepare('UPDATE marketing_campaigns SET new_thread_ack=1 WHERE id=?')->execute([$cid]);
        }
        $hash = mo_content_hash($pdo, $cid);
        $pdo->prepare("UPDATE marketing_campaigns SET status='approved', approved_by=?, approved_at=UTC_TIMESTAMP(), content_hash=? WHERE id=?")
            ->execute([$userEmail, $hash, $cid]);
        $pdo->prepare("UPDATE marketing_campaign_recipients SET status='approved' WHERE campaign_id=? AND status='ready'")
            ->execute([$cid]);
        mo_audit($pdo, $userEmail, 'campaign_approve', $cid,
            ['recipients' => $sendable, 'new_threads' => $newThreads,
             'allow_new_threads' => $newThreads > 0 ? !empty($in['allow_new_threads']) : null]);
        mo_out(['success' => true, 'approved' => $sendable, 'new_threads' => $newThreads]);
    }

    case 'send': {
        $cid = (string)($in['campaign_id'] ?? '');
        $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
        $c->execute([$cid]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) mo_fail('not found', 404);

        // Delivery control: interface access never implies sending.
        $mailer = new MarketingMailer($pdo, $baseUrl);
        if (!$mailer->policy()->enabled()) mo_fail('sending_disabled', 403);

        if (!in_array($camp['status'], ['approved', 'partial', 'sending'], true)) mo_fail('campaign not approved');
        // Content must match what was approved - no unapproved edits send.
        if (!hash_equals((string)$camp['content_hash'], mo_content_hash($pdo, $cid))) {
            mo_fail('content changed since approval - re-approve required');
        }
        // Atomic claim so two admins cannot send the same campaign twice.
        // A 'sending' claim older than the stale window belongs to an
        // interrupted run and may be taken over; its in-flight recipients
        // are reconciled through the ledger, not blindly resent.
        $claim = $pdo->prepare("UPDATE marketing_campaigns SET status='sending', updated_at=NOW() WHERE id=? AND (
            status IN ('approved','partial')
            OR (status='sending' AND updated_at < NOW() - INTERVAL " . (int)MarketingMailer::STALE_RUNNING_SECONDS . " SECOND))");
        $claim->execute([$cid]);
        if ($claim->rowCount() !== 1) mo_fail('send already in progress', 409);
        $restoreStatus = $camp['status'] === 'approved' ? 'approved' : 'partial';

        // CRM mode (MARKETING_CRM_READS): MySQL is authoritative for
        // send-time verification and confirmed-send write-back. mo_crm()
        // fails closed when the flag is on but the schema is missing, so a
        // half-migrated CRM can never send unverified. Sheet mode is
        // unchanged while the flag is off.
        $crm = mo_crm($pdo);
        $crmMode = $crm !== null;
        $admin = $crmMode ? mo_crm_admin($pdo, $userEmail) : null;

        $sheet = null;
        if (!$crmMode) {
            try { $sheet = new McpSheetSync(); $sheet->sheet(); } catch (Throwable $e) {
                // Sheet unreachable: fail closed before any send so eligibility
                // and status write-back stay honest.
                $pdo->prepare("UPDATE marketing_campaigns SET status=? WHERE id=?")->execute([$restoreStatus, $cid]);
                mo_fail('prospect sheet unreachable - send aborted', 502);
            }
        }

        // Outstanding sheet write-backs from earlier runs first (no mail).
        // In CRM mode there are none: recordSend() completes the write-back
        // synchronously, so 'pending' rows are a sheet-era leftover only.
        $sheetRetry = $crmMode ? ['synced' => 0, 'failed' => 0] : mo_sheet_sync_pending($pdo, $sheet, $camp);

        $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients
            WHERE campaign_id=? AND status IN ('approved','failed','uncertain','sending') ORDER BY id LIMIT " . MO_MAX_RECIPIENTS);
        $rs->execute([$cid]);
        $recips = $rs->fetchAll(PDO::FETCH_ASSOC);
        $ref = mo_ref($cid);

        // One batched CRM lookup for the whole send batch (no N+1).
        $crmByEmail = [];
        if ($crmMode) {
            $crmByEmail = $crm->findByEmails(array_map(static fn($r) => (string)$r['prospect_email'], $recips));
        }

        $sent = 0; $skipped = 0; $failed = 0; $uncertain = 0; $blocked = 0; $sheetFailed = 0; $results = [];
        foreach ($recips as $r) {
            // Claim the recipient row. Rows already 'sending' are leftovers
            // of an interrupted run that this request now owns.
            if ($r['status'] !== 'sending') {
                $cl = $pdo->prepare("UPDATE marketing_campaign_recipients SET status='sending' WHERE id=? AND status=?");
                $cl->execute([$r['id'], $r['status']]);
                if ($cl->rowCount() !== 1) { $skipped++; continue; }
            }
            $email = SuppressionService::normalizeEmail($r['prospect_email']);

            // 0) Recipient-level delivery control (test allowlist).
            $gate = $mailer->policy()->check($email);
            if (!$gate['allowed']) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='approved', skip_reason=? WHERE id=?")
                    ->execute([$gate['reason'], $r['id']]);
                $blocked++; $results[] = ['email' => $email, 'status' => 'blocked', 'reason' => $gate['reason']];
                continue;
            }

            // 1) Eligibility re-check immediately before send: suppression
            //    store, sheet Do Not Contact / unsubscribed / bounced, and
            //    the rule for this campaign's kind. A send already recorded
            //    for THIS campaign is not "prior outreach" for that rule.
            $ctx = mo_elig_ctx($pdo, $svc, $email, $ref);
            if ($ctx['suppressed'] || $ctx['org_suppressed']) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason='suppressed_recheck' WHERE id=?")->execute([$r['id']]);
                $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed'];
                continue;
            }
            // An uncertain/interrupted send must reach the ledger so it is
            // reconciled; the sheet may legitimately show it as contacted.
            // Permanent exclusions still stop it.
            $resuming = in_array($r['status'], ['uncertain', 'sending'], true);
            if ($crmMode) {
                // Database-backed gate: annotated CRM record + suppression
                // ctx. 'not_in_crm' mirrors the sheet's not_in_sheet.
                $gate = $admin->sendPrecheck($crmByEmail[$email] ?? null, $camp['kind'], $resuming);
                if ($gate['action'] === 'suppressed') {
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason=? WHERE id=?")
                        ->execute([substr((string)$gate['reason'], 0, 190), $r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed', 'reason' => $gate['reason']];
                    continue;
                }
                if ($gate['action'] !== 'send') {
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='skipped', skip_reason=?, error=? WHERE id=?")
                        ->execute([substr(implode(',', $gate['flags']), 0, 190), 'crm_precheck', $r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'skipped', 'reason' => implode(',', $gate['flags'])];
                    continue;
                }
            } else {
                $pre = $sheet->preCheck($email, $camp['kind'], $ctx);
                if (!$pre['ok'] && !($resuming && !$pre['hard'])) {
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='skipped', skip_reason=?, error=? WHERE id=?")
                        ->execute([substr(implode(',', $pre['flags']), 0, 190), 'sheet_precheck', $r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'skipped', 'reason' => implode(',', $pre['flags'])];
                    continue;
                }
            }

            // 2) Send via the shared mailer (ledger-idempotent, footer,
            //    RFC 8058). Follow-ups reply in the resolved conversation.
            $thread = null;
            if ($camp['kind'] === 'followup') {
                if (($r['thread_status'] ?? '') === 'resolved') {
                    $thread = ['thread_id' => (string)$r['gmail_thread_id'],
                        'in_reply_to' => (string)$r['thread_rfc_message_id'],
                        'references' => (string)$r['thread_references']];
                } elseif ((int)($camp['new_thread_ack'] ?? 0) !== 1) {
                    // Unthreaded follow-ups are a new conversation; they
                    // require the explicit approval-time acknowledgment.
                    $pdo->prepare("UPDATE marketing_campaign_recipients SET status='skipped', skip_reason='new_thread_not_acknowledged', error='new_thread_not_acknowledged' WHERE id=?")
                        ->execute([$r['id']]);
                    $skipped++; $results[] = ['email' => $email, 'status' => 'skipped', 'reason' => 'new_thread_not_acknowledged'];
                    continue;
                }
            }
            $res = $mailer->send([
                'to' => $email, 'subject' => (string)$r['subject'],
                'html' => (string)$r['body_html'], 'campaign' => $ref,
                'thread' => $thread,
            ]);
            $st = $res['status'] ?? 'uncertain';
            // The ledger already holds a confirmed send for this recipient.
            if ($st === 'already_recorded') $st = 'sent';

            if ($st === 'sent') {
                $row = mo_mark_recipient_sent($pdo, $r, (string)($res['message_id'] ?? ''), (string)($res['thread_id'] ?? ''));
                // Confirmed-send write-back. CRM mode updates MySQL
                // synchronously (source of truth, no sheet required); a
                // failure is recorded on the recipient row, never silently.
                // Sheet mode queues the sheet write-back for later retry.
                if ($crmMode) {
                    if (!$admin->recordSend($row, $camp)) $sheetFailed++;
                } elseif (!mo_sheet_sync_one($pdo, $sheet, $row, $camp)) {
                    $sheetFailed++;
                }
                $sent++; $results[] = ['email' => $email, 'status' => 'sent', 'message_id' => $res['message_id'] ?? '',
                    'reconciled' => !empty($res['reconciled'])];
            } elseif ($st === 'skipped_suppressed') {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='suppressed', skip_reason='suppressed' WHERE id=?")->execute([$r['id']]);
                $skipped++; $results[] = ['email' => $email, 'status' => 'suppressed'];
            } elseif ($st === 'sending_disabled' || $st === 'recipient_not_allowlisted') {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='approved', skip_reason=? WHERE id=?")->execute([$st, $r['id']]);
                $blocked++; $results[] = ['email' => $email, 'status' => 'blocked', 'reason' => $st];
            } elseif ($st === 'uncertain' || $st === 'in_progress') {
                // Outcome unknown: held for reconciliation, never resent blindly.
                $err = substr((string)($res['error'] ?? $st), 0, 250);
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='uncertain', error=? WHERE id=?")->execute([$err, $r['id']]);
                $uncertain++; $results[] = ['email' => $email, 'status' => 'uncertain', 'error' => $err];
            } else {
                $err = substr((string)($res['error'] ?? $st), 0, 250);
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='failed', error=? WHERE id=?")->execute([$err, $r['id']]);
                $failed++; $results[] = ['email' => $email, 'status' => 'failed', 'error' => $err];
            }
        }

        $roll = mo_rollup($pdo, $cid);
        mo_audit($pdo, $userEmail, 'campaign_send', $cid, ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
            'uncertain' => $uncertain, 'blocked' => $blocked, 'sheet_failed' => $sheetFailed,
            'mode' => $mailer->policy()->mode()]);
        mo_out(['success' => true, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
            'uncertain' => $uncertain, 'blocked' => $blocked,
            'sheet_failed' => $sheetFailed + $sheetRetry['failed'], 'sheet_retried' => $sheetRetry['synced'],
            'results' => $results, 'campaign_status' => $roll['status']]);
    }

    case 'resolve_threads': {
        // Re-run original-conversation lookup for a follow-up campaign.
        $cid = (string)($in['campaign_id'] ?? '');
        $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
        $c->execute([$cid]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) mo_fail('not found', 404);
        if ($camp['kind'] !== 'followup') mo_fail('threading applies to follow-up campaigns only');
        if (!in_array($camp['status'], ['draft', 'ready'], true)) mo_fail('campaign locked');
        $crm = mo_crm($pdo);
        $notesByEmail = [];
        $dir = null;
        $sheetErr2 = null;
        if ($crm !== null) {
            $rs0 = $pdo->prepare("SELECT prospect_email FROM marketing_campaign_recipients WHERE campaign_id=? AND status IN ('draft','ready')");
            $rs0->execute([$cid]);
            foreach ($crm->findByEmails($rs0->fetchAll(PDO::FETCH_COLUMN)) as $e => $pp) {
                $notesByEmail[$e] = (string)($pp['fields']['notes'] ?? '');
            }
        } else {
            [, $sheetErr2, $dir] = mo_load_prospects($pdo);
            if ($dir !== null) {
                $rs0 = $pdo->prepare("SELECT prospect_email FROM marketing_campaign_recipients WHERE campaign_id=? AND status IN ('draft','ready')");
                $rs0->execute([$cid]);
                foreach ($rs0->fetchAll(PDO::FETCH_COLUMN) as $pe) {
                    $pp = $dir->findByEmail((string)$pe);
                    if ($pp) $notesByEmail[SuppressionService::normalizeEmail((string)$pe)] = (string)($pp['fields']['notes'] ?? '');
                }
            }
        }
        $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND status IN ('draft','ready') ORDER BY id");
        $rs->execute([$cid]);
        $tm = new MarketingMailer($pdo, $baseUrl);
        $counts = [];
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $notes = $notesByEmail[SuppressionService::normalizeEmail((string)$r['prospect_email'])] ?? '';
            $t = mo_resolve_thread($pdo, $tm, $r, $notes, $cid);
            $counts[$t['status']] = ($counts[$t['status']] ?? 0) + 1;
        }
        mo_audit($pdo, $userEmail, 'campaign_resolve_threads', $cid, $counts);
        mo_out(['success' => true, 'threads' => $counts, 'sheet_error' => $dir === null ? $sheetErr2 : null]);
    }

    case 'reconcile': {
        // Settle uncertain sends against Gmail Sent mail. Sends nothing.
        $cid = (string)($in['campaign_id'] ?? '');
        $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
        $c->execute([$cid]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) mo_fail('not found', 404);
        $mailer = new MarketingMailer($pdo, $baseUrl);
        $rs = $pdo->prepare("SELECT * FROM marketing_campaign_recipients WHERE campaign_id=? AND status='uncertain' ORDER BY id");
        $rs->execute([$cid]);
        $sheet = null;
        $crmMode = mo_crm($pdo) !== null;
        $admin = $crmMode ? mo_crm_admin($pdo, $userEmail) : null;
        $out = ['sent' => 0, 'not_sent' => 0, 'unknown' => 0]; $detail = [];
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rec = $mailer->reconcile(mo_ref($cid), (string)$r['prospect_email']);
            if ($rec['outcome'] === 'sent') {
                $row = mo_mark_recipient_sent($pdo, $r, (string)($rec['message_id'] ?? ''), (string)($rec['thread_id'] ?? ''));
                if ($crmMode) {
                    try { $admin->recordSend($row, $camp); }
                    catch (Throwable $e) { /* stays 'pending'; safe to retry */ }
                } else {
                    try { $sheet = $sheet ?? new McpSheetSync(); mo_sheet_sync_one($pdo, $sheet, $row, $camp); }
                    catch (Throwable $e) { /* stays 'pending'; retry_sheet_sync recovers it */ }
                }
                $out['sent']++;
            } elseif ($rec['outcome'] === 'not_sent' || ($rec['outcome'] === 'not_applicable' && ($rec['reason'] ?? '') === 'failed')) {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET status='failed', error='reconciled_not_sent' WHERE id=?")->execute([$r['id']]);
                $out['not_sent']++;
            } else {
                $pdo->prepare("UPDATE marketing_campaign_recipients SET error=? WHERE id=?")
                    ->execute([substr('reconcile_pending: ' . ($rec['reason'] ?? 'unknown'), 0, 250), $r['id']]);
                $out['unknown']++;
            }
            $detail[] = ['email' => $r['prospect_email'], 'outcome' => $rec['outcome'], 'reason' => $rec['reason'] ?? ''];
        }
        if (in_array($camp['status'], ['partial', 'completed'], true)) mo_rollup($pdo, $cid);
        mo_audit($pdo, $userEmail, 'campaign_reconcile', $cid, $out);
        mo_out(['success' => true] + $out + ['detail' => $detail]);
    }

    case 'resolve_uncertain': {
        // Operator decision for a send Gmail lookup cannot settle.
        $cid = (string)($in['campaign_id'] ?? '');
        $rid = (int)($in['recipient_id'] ?? 0);
        $outcome = (string)($in['outcome'] ?? '');
        if (!in_array($outcome, ['sent', 'not_sent'], true)) mo_fail('outcome must be sent or not_sent');
        $r = $pdo->prepare("SELECT r.*, c.status cstatus FROM marketing_campaign_recipients r
            JOIN marketing_campaigns c ON c.id=r.campaign_id WHERE r.id=? AND r.campaign_id=?");
        $r->execute([$rid, $cid]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) mo_fail('not found', 404);
        if ($row['status'] !== 'uncertain') mo_fail('recipient is not uncertain');
        $mailer = new MarketingMailer($pdo, $baseUrl);
        $msgId = trim((string)($in['message_id'] ?? ''));
        // The ledger row may already be settled (e.g. by reconcile).
        $mailer->resolveUncertain(mo_ref($cid), (string)$row['prospect_email'], $outcome, $msgId !== '' ? $msgId : null);
        if ($outcome === 'sent') {
            $sentRow = mo_mark_recipient_sent($pdo, $row, preg_match('/^[A-Za-z0-9_-]{6,80}$/', $msgId) ? $msgId : '', '');
            if (mo_crm($pdo) !== null) {
                $cc = $pdo->prepare("SELECT id,name,kind FROM marketing_campaigns WHERE id=?");
                $cc->execute([$cid]);
                $campRow = $cc->fetch(PDO::FETCH_ASSOC) ?: ['id' => $cid, 'name' => '', 'kind' => 'initial'];
                try { mo_crm_admin($pdo, $userEmail)->recordSend($sentRow, $campRow); }
                catch (Throwable $e) { error_log('[marketing-admin] crm recordSend: ' . $e->getMessage()); }
            }
        } else {
            $pdo->prepare("UPDATE marketing_campaign_recipients SET status='failed', error='operator_confirmed_not_sent' WHERE id=?")->execute([$rid]);
        }
        if (in_array($row['cstatus'], ['partial', 'completed'], true)) mo_rollup($pdo, $cid);
        mo_audit($pdo, $userEmail, 'recipient_resolve_uncertain', $cid, ['recipient_id' => $rid, 'outcome' => $outcome]);
        mo_out(['success' => true]);
    }

    case 'retry_sheet_sync': {
        // Re-attempt Google Sheet write-backs for confirmed sends. No mail.
        $cid = (string)($in['campaign_id'] ?? '');
        $c = $pdo->prepare("SELECT * FROM marketing_campaigns WHERE id=?");
        $c->execute([$cid]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) mo_fail('not found', 404);
        if (mo_crm($pdo) !== null) {
            // CRM mode writes back to MySQL synchronously at send time;
            // there is no sheet queue to retry.
            mo_audit($pdo, $userEmail, 'campaign_sheet_sync', $cid, ['synced' => 0, 'failed' => 0, 'crm' => true]);
            mo_out(['success' => true, 'synced' => 0, 'failed' => 0, 'crm' => true]);
        }
        try { $sheet = new McpSheetSync(); $sheet->sheet(); } catch (Throwable $e) { mo_fail('prospect sheet unreachable', 502); }
        $res = mo_sheet_sync_pending($pdo, $sheet, $camp);
        mo_audit($pdo, $userEmail, 'campaign_sheet_sync', $cid, $res);
        mo_out(['success' => true] + $res);
    }

    /* ------------------------------------------------------------------ */
    /* Phase 3: CRM prospect/organization management (MySQL only)          */
    /* ------------------------------------------------------------------ */

    case 'prospect_match_emails': {
        // Read-only batch match for bulk campaign entry. Accepts an
        // 'emails' array or a 'text' blob (any separators - addresses are
        // extracted by pattern). Returns EVERY CRM contact per address so
        // the UI can flag ambiguous shared-email matches; nothing is
        // selected or written here.
        $crm = mo_crm($pdo);
        if ($crm === null) mo_fail('crm_unavailable', 503);
        $raw = [];
        if (isset($in['text']) && is_string($in['text'])) {
            preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $in['text'], $mm);
            $raw = $mm[0] ?? [];
        }
        foreach ((array)($in['emails'] ?? []) as $e) $raw[] = (string)$e;
        if (count($raw) > 200) mo_fail('too many addresses (max 200)');
        $kind = ($in['kind'] ?? 'initial') === 'followup' ? 'followup' : 'initial';
        $matched = $crm->matchEmails($raw);

        // Emails already recipients of the target campaign (when given).
        $inCampaign = [];
        $cid = trim((string)($in['campaign_id'] ?? ''));
        if ($cid !== '') {
            $ex = $pdo->prepare('SELECT prospect_email FROM marketing_campaign_recipients WHERE campaign_id=?');
            $ex->execute([$cid]);
            foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $e) $inCampaign[strtolower((string)$e)] = true;
        }

        $out = [];
        foreach ($matched as $email => $contacts) {
            $rows = [];
            foreach ($contacts as $p) {
                $f = $p['fields'] ?? [];
                $rows[] = [
                    'id'         => (int)($p['id'] ?? 0),
                    'contact_id' => (int)($f['contact_id'] ?? $p['id'] ?? 0),
                    'name'       => (string)($p['contact_name'] ?? ''),
                    'org'        => (string)($f['org'] ?? ''),
                    'email'      => $email,
                    'eligible'   => !empty($p['eligibility'][$kind]['ok'] ?? false),
                    'flags'      => array_values((array)($p['flags'] ?? [])),
                    'in_campaign' => isset($inCampaign[$email]),
                ];
            }
            $out[] = ['email' => $email, 'matches' => $rows];
        }
        // Preserve submission order of first appearance for unmatched too:
        // matchEmails only returns normalized valid addresses; report the
        // count of unique valid addresses submitted.
        mo_out(['success' => true, 'kind' => $kind, 'results' => $out,
            'matched_count' => count(array_filter($out, fn($r) => $r['matches'] !== []))]);
    }

    case 'prospect_create': {
        $admin = mo_crm_admin($pdo, $userEmail);
        try {
            $r = $admin->createContact($in);
        } catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'prospect_create', null, ['contact_id' => $r['id']]);
        mo_out(['success' => true, 'contact_id' => $r['id'], 'warnings' => $r['warnings'],
            'review_reasons' => $r['review_reasons']]);
    }

    case 'prospect_update': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $id = (int)($in['contact_id'] ?? 0);
        $version = (int)($in['version'] ?? -1);
        if ($id <= 0) mo_fail('contact_id required');
        if ($version < 0) mo_fail('version required');
        try {
            $r = $admin->updateContact($id, $in, $version);
        } catch (CrmConflict $e) { mo_fail($e->getMessage(), 409); }
          catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'prospect_update', null, ['contact_id' => $id, 'changed' => $r['changed']]);
        mo_out(['success' => true, 'warnings' => $r['warnings'], 'changed' => $r['changed'], 'version' => $r['version']]);
    }

    case 'prospect_activity': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $id = (int)($in['contact_id'] ?? 0);
        $type = (string)($in['type'] ?? '');
        $details = (string)($in['details'] ?? '');
        if ($id <= 0) mo_fail('contact_id required');
        try {
            $admin->addActivity($id, $type, $details);
        } catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'prospect_activity', null, ['contact_id' => $id, 'type' => $type]);
        mo_out(['success' => true]);
    }

    case 'prospect_review_resolve': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $id = (int)($in['contact_id'] ?? 0);
        if ($id <= 0) mo_fail('contact_id required');
        try { $admin->resolveReview($id); }
        catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'prospect_review_resolve', null, ['contact_id' => $id]);
        mo_out(['success' => true]);
    }

    case 'org_create': {
        $admin = mo_crm_admin($pdo, $userEmail);
        try { $r = $admin->createOrg($in); }
        catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'org_create', null, ['org_id' => $r['id']]);
        mo_out(['success' => true, 'org_id' => $r['id'], 'warnings' => $r['warnings']]);
    }

    case 'org_update': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $id = (int)($in['org_id'] ?? 0);
        if ($id <= 0) mo_fail('org_id required');
        try { $r = $admin->updateOrg($id, $in); }
        catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'org_update', null, ['org_id' => $id, 'changed' => $r['changed']]);
        mo_out(['success' => true, 'warnings' => $r['warnings'], 'changed' => $r['changed']]);
    }

    case 'bulk_update': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $ids = (array)($in['contact_ids'] ?? []);
        $fields = (array)($in['fields'] ?? []);
        try { $r = $admin->bulkUpdate($ids, $fields); }
        catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'bulk_update', null, ['count' => $r['updated'], 'fields' => array_keys($fields)]);
        mo_out(['success' => true] + $r);
    }

    case 'campaign_add_recipients': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $ids = (array)($in['contact_ids'] ?? []);
        $cid = trim((string)($in['campaign_id'] ?? ''));
        $newName = trim((string)($in['new_campaign_name'] ?? ''));
        $kind = ($in['kind'] ?? 'initial') === 'followup' ? 'followup' : 'initial';
        $dryRun = !empty($in['dry_run']);
        try {
            $r = $admin->addToCampaign($ids, $cid !== '' ? $cid : null,
                $newName !== '' ? $newName : null, $kind, MO_MAX_RECIPIENTS, $dryRun);
        } catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        if (!$dryRun) {
            mo_audit($pdo, $userEmail, 'campaign_add_recipients', $r['campaign_id'],
                ['requested' => count($ids), 'added' => $r['added']]);
        }
        mo_out(['success' => true, 'campaign_id' => $r['campaign_id'], 'added' => $r['added'], 'results' => $r['results']]);
    }

    /* Phase 4: campaign wizard + management (CRM mode only). */

    case 'campaign_create': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $ids = array_values(array_filter(array_map('intval', (array)($in['contact_ids'] ?? [])), fn($i) => $i > 0));
        try {
            $r = $admin->createCampaign([
                'name' => (string)($in['name'] ?? ''),
                'kind' => (string)($in['kind'] ?? 'initial'),
                'description' => $in['description'] ?? null,
                'template_id' => $in['template_id'] ?? null,
                'contact_ids' => $ids,
            ], MO_MAX_RECIPIENTS);
        } catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'campaign_create', $r['campaign_id'],
            ['name' => (string)($in['name'] ?? ''), 'kind' => (string)($in['kind'] ?? 'initial'), 'recipients' => $r['added']]);
        mo_out(['success' => true, 'campaign_id' => $r['campaign_id'], 'added' => $r['added'], 'results' => $r['results']]);
    }

    case 'campaign_update': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $cid = trim((string)($in['campaign_id'] ?? ''));
        if ($cid === '') mo_fail('campaign_id required');
        try { $r = $admin->updateCampaignMeta($cid, $in); }
        catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'campaign_update', $cid, ['changed' => $r['changed']]);
        mo_out(['success' => true, 'changed' => $r['changed']]);
    }

    case 'campaign_recipient_remove': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $cid = trim((string)($in['campaign_id'] ?? ''));
        $rid = (int)($in['recipient_id'] ?? 0);
        if ($cid === '' || $rid <= 0) mo_fail('campaign_id and recipient_id required');
        try { $r = $admin->removeRecipient($cid, $rid); }
        catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_audit($pdo, $userEmail, 'campaign_recipient_remove', $cid, ['recipient_id' => $rid]);
        mo_out(['success' => true] + $r);
    }

    case 'import_preview': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $csv = (string)($in['csv'] ?? '');
        if ($csv === '') mo_fail('csv required');
        try { $rows = $admin->previewImport($csv); }
        catch (InvalidArgumentException $e) { mo_fail($e->getMessage()); }
        mo_out(['success' => true, 'rows' => $rows, 'unmapped_columns' => $admin->unmappedColumns()]);
    }

    case 'import_commit': {
        $admin = mo_crm_admin($pdo, $userEmail);
        $records = (array)($in['records'] ?? []);
        if (!$records) mo_fail('records required');
        if (count($records) > ProspectCrmAdmin::MAX_IMPORT_ROWS) mo_fail('too many records');
        try { $r = $admin->commitImport($records); }
        catch (Throwable $e) { mo_fail('import failed: ' . $e->getMessage(), 500); }
        mo_audit($pdo, $userEmail, 'import_commit', null, $r);
        mo_out(['success' => true] + $r);
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
