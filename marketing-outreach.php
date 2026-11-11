<?php
/**
 * Platform Administration - Marketing Outreach
 *
 * Single-page admin surface with six internal tabs: Overview, Prospects,
 * Campaigns, Follow-ups, Email Templates, Outreach History. All data and
 * mutations go through api/marketing-admin.php (session + CSRF +
 * super-user/dev gate + MARKETING_OUTREACH flag).
 *
 * Access: same rule as the other administration pages, PLUS the
 * MARKETING_OUTREACH feature flag must be enabled.
 */

require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

$csrfToken = generateCsrfToken();

if (empty($_SESSION['db_user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/api/dev-tools-access.php';
require_once __DIR__ . '/api/feature-flags.php';
require_once __DIR__ . '/api/asset-url.php';
require_once __DIR__ . '/admin-layout.php';

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = (isSuperUser($appConfig, $userEmail) || $isDev) && isFeatureEnabled('MARKETING_OUTREACH');

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ma($k) { return t('marketing_admin.' . $k); }
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title><?php echo h(ma('title')); ?> - <?php echo h($appConfig['appName']); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= appAssetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= appAssetUrl('css/admin.css') ?>">
    <style>
        .mo-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 18px; overflow-x: auto; }
        .mo-tab { background: none; border: none; border-bottom: 2px solid transparent; padding: 10px 14px;
                  font-size: 0.88rem; font-weight: 600; color: #6b7280; cursor: pointer; white-space: nowrap; }
        .mo-tab:hover { color: #1f2937; }
        .mo-tab.is-active { color: #1e40af; border-bottom-color: #1e40af; }
        .mo-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; background: #fff; border-radius: 8px; overflow: hidden; }
        .mo-table th { text-align: left; padding: 9px 12px; background: #f9fafb; font-weight: 600; color: #374151; white-space: nowrap; }
        .mo-table td { padding: 8px 12px; border-top: 1px solid #f3f4f6; vertical-align: top; }
        .mo-table tr.clickable { cursor: pointer; }
        .mo-table tr.clickable:hover td { background: #f8fafc; }
        .mo-flag { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 0.68rem; margin: 1px 3px 1px 0; white-space: nowrap; }
        .mo-flag.missing_contact { background: #fee2e2; color: #991b1b; }
        .mo-flag.do_not_contact { background: #1f2937; color: #fff; }
        .mo-flag.duplicate_org { background: #fef3c7; color: #92400e; }
        .mo-pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 0.72rem; white-space: nowrap; background: #f3f4f6; color: #374151; }
        .mo-pill.sent, .mo-pill.completed, .mo-pill.eligible { background: #d1fae5; color: #065f46; }
        .mo-pill.failed, .mo-pill.overdue { background: #fee2e2; color: #991b1b; }
        .mo-pill.approved, .mo-pill.ready, .mo-pill.due_today, .mo-pill.upcoming { background: #dbeafe; color: #1e40af; }
        .mo-pill.suppressed, .mo-pill.skipped, .mo-pill.excluded { background: #e5e7eb; color: #4b5563; }
        .mo-pill.partial, .mo-pill.awaiting_response { background: #fef3c7; color: #92400e; }
        .mo-pill.draft { background: #f3f4f6; color: #6b7280; }
        .mo-toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
        .mo-toolbar input[type="search"], .mo-toolbar select, .mo-toolbar input[type="text"] {
            padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; }
        .mo-toolbar input[type="search"] { flex: 1 1 240px; min-width: 0; }
        .mo-btn { padding: 8px 16px; border: 0; border-radius: 6px; font-size: 0.85rem; font-weight: 600;
                  cursor: pointer; background: #1e40af; color: #fff; }
        .mo-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .mo-btn.secondary { background: #fff; color: #1e40af; border: 1px solid #1e40af; }
        .mo-btn.danger { background: #b91c1c; }
        .mo-btn.small { padding: 5px 10px; font-size: 0.78rem; }
        .mo-msg-list { display: flex; flex-direction: column; gap: 10px; }
        .mo-msg { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; }
        .mo-msg-head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: baseline; }
        .mo-msg-head strong { font-size: 0.9rem; }
        .mo-msg-sub { font-size: 0.78rem; color: #6b7280; margin: 2px 0 8px; word-break: break-all; }
        .mo-msg textarea, .mo-msg input[type="text"] {
            width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d1d5db;
            border-radius: 6px; font-size: 0.85rem; font-family: inherit; }
        .mo-msg textarea { min-height: 220px; font-family: ui-monospace, monospace; font-size: 0.8rem; }
        .mo-preview { border: 1px dashed #d1d5db; border-radius: 8px; padding: 14px; background: #fafafa;
                      font-size: 0.85rem; max-height: 320px; overflow: auto; }
        .mo-detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .mo-detail-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; }
        .mo-detail-item .k { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
        .mo-detail-item .v { font-size: 0.88rem; font-weight: 600; margin-top: 2px; word-break: break-word; }
        .mo-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 14px; }
        .mo-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 14px; }
        .mo-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 14px; }
        .mo-hidden { display: none; }
        .mo-pager { display: flex; gap: 8px; align-items: center; margin-top: 12px; font-size: 0.82rem; color: #6b7280; }
        .mo-step-note { font-size: 0.78rem; color: #6b7280; margin-top: 6px; }
        @media (max-width: 720px) {
            .mo-table { display: block; overflow-x: auto; }
            .mo-msg textarea { min-height: 160px; }
        }
    </style>
</head>
<?php paAdminChrome('marketing', ma('title'), ma('subtitle')); ?>

<div id="moGlobalError" class="mo-error mo-hidden"></div>
<div id="moGlobalOk" class="mo-ok mo-hidden"></div>

<div class="mo-tabs" role="tablist">
    <button class="mo-tab is-active" data-tab="overview" role="tab"><?php echo h(ma('tab_overview')); ?></button>
    <button class="mo-tab" data-tab="prospects" role="tab"><?php echo h(ma('tab_prospects')); ?></button>
    <button class="mo-tab" data-tab="campaigns" role="tab"><?php echo h(ma('tab_campaigns')); ?></button>
    <button class="mo-tab" data-tab="followups" role="tab"><?php echo h(ma('tab_followups')); ?></button>
    <button class="mo-tab" data-tab="templates" role="tab"><?php echo h(ma('tab_templates')); ?></button>
    <button class="mo-tab" data-tab="history" role="tab"><?php echo h(ma('tab_history')); ?></button>
</div>

<!-- ============ OVERVIEW ============ -->
<section id="moTab-overview" class="mo-pane">
    <div class="pa-grid" id="moOvMetrics"></div>
    <div class="pa-card">
        <h2><?php echo h(ma('ov_followups_title')); ?></h2>
        <div id="moOvBuckets" class="mo-toolbar"></div>
    </div>
    <div class="pa-card">
        <h2><?php echo h(ma('ov_flags_title')); ?></h2>
        <div id="moOvFlags"></div>
        <p class="mo-step-note" id="moOvSheetNote"></p>
    </div>
</section>

<!-- ============ PROSPECTS ============ -->
<section id="moTab-prospects" class="mo-pane mo-hidden">
    <div class="mo-toolbar">
        <input type="search" id="moProspectQ" placeholder="<?php echo h(ma('search_placeholder')); ?>">
        <select id="moProspectFlag">
            <option value=""><?php echo h(ma('filter_all')); ?></option>
            <option value="missing_contact"><?php echo h(ma('flag_missing_contact')); ?></option>
            <option value="do_not_contact"><?php echo h(ma('flag_do_not_contact')); ?></option>
            <option value="duplicate_org"><?php echo h(ma('flag_duplicate_org')); ?></option>
        </select>
        <select id="moProspectBucket">
            <option value=""><?php echo h(ma('bucket_all')); ?></option>
            <option value="due_today"><?php echo h(ma('bucket_due_today')); ?></option>
            <option value="overdue"><?php echo h(ma('bucket_overdue')); ?></option>
            <option value="upcoming"><?php echo h(ma('bucket_upcoming')); ?></option>
            <option value="awaiting_response"><?php echo h(ma('bucket_awaiting_response')); ?></option>
            <option value="completed"><?php echo h(ma('bucket_completed')); ?></option>
            <option value="excluded"><?php echo h(ma('bucket_excluded')); ?></option>
        </select>
        <button class="mo-btn secondary" id="moProspectSearch"><?php echo h(ma('search')); ?></button>
    </div>
    <div id="moProspectList"></div>
    <div class="mo-pager" id="moProspectPager"></div>
</section>

<!-- ============ CAMPAIGNS ============ -->
<section id="moTab-campaigns" class="mo-pane mo-hidden">
    <div class="mo-toolbar">
        <button class="mo-btn" id="moNewCampaign"><?php echo h(ma('new_campaign')); ?></button>
        <span class="mo-step-note"><?php echo h(ma('campaign_limit_note')); ?></span>
    </div>
    <div id="moCampaignList"></div>
    <div id="moCampaignDetail" class="mo-hidden">
        <div class="pa-card" style="margin-top:16px;">
            <h2 id="moCampTitle"></h2>
            <p class="mo-step-note" id="moCampMeta"></p>
            <div class="mo-warn mo-hidden" id="moCampApproveWarn"></div>
            <div class="mo-toolbar">
                <button class="mo-btn secondary" id="moCampBack"><?php echo h(ma('back_to_list')); ?></button>
                <button class="mo-btn" id="moCampGenerate"><?php echo h(ma('generate_emails')); ?></button>
                <button class="mo-btn secondary" id="moCampApprove"><?php echo h(ma('approve_and_send')); ?></button>
                <button class="mo-btn danger mo-hidden" id="moCampRetry"><?php echo h(ma('retry_failed')); ?></button>
                <span class="mo-step-note" id="moCampStep"></span>
            </div>
            <div id="moCampMessages" class="mo-msg-list"></div>
        </div>
    </div>
</section>

<!-- ============ FOLLOW-UPS ============ -->
<section id="moTab-followups" class="mo-pane mo-hidden">
    <div class="mo-toolbar">
        <select id="moFuBucket">
            <option value=""><?php echo h(ma('fu_actionable')); ?></option>
            <option value="due_today"><?php echo h(ma('bucket_due_today')); ?></option>
            <option value="overdue"><?php echo h(ma('bucket_overdue')); ?></option>
            <option value="upcoming"><?php echo h(ma('bucket_upcoming')); ?></option>
            <option value="awaiting_response"><?php echo h(ma('bucket_awaiting_response')); ?></option>
            <option value="completed"><?php echo h(ma('bucket_completed')); ?></option>
            <option value="excluded"><?php echo h(ma('bucket_excluded')); ?></option>
        </select>
        <button class="mo-btn secondary" id="moFuLoad"><?php echo h(ma('load')); ?></button>
        <button class="mo-btn" id="moFuCampaign"><?php echo h(ma('new_followup_campaign')); ?></button>
    </div>
    <div id="moFuList"></div>
</section>

<!-- ============ TEMPLATES ============ -->
<section id="moTab-templates" class="mo-pane mo-hidden">
    <div class="mo-toolbar">
        <button class="mo-btn" id="moTplNew"><?php echo h(ma('new_template')); ?></button>
    </div>
    <div id="moTplList"></div>
    <div class="pa-card mo-hidden" id="moTplEditor" style="margin-top:16px;">
        <h2 id="moTplEditorTitle"></h2>
        <div class="mo-toolbar" style="flex-direction:column;align-items:stretch;">
            <input type="text" id="moTplName" placeholder="<?php echo h(ma('tpl_name')); ?>">
            <select id="moTplKind">
                <option value="initial"><?php echo h(ma('tpl_kind_initial')); ?></option>
                <option value="followup"><?php echo h(ma('tpl_kind_followup')); ?></option>
            </select>
            <input type="text" id="moTplSubject" placeholder="<?php echo h(ma('tpl_subject')); ?>">
            <textarea id="moTplBody" rows="10" placeholder="<?php echo h(ma('tpl_body')); ?>"></textarea>
            <label style="font-size:0.85rem;"><input type="checkbox" id="moTplDefault"> <?php echo h(ma('tpl_default')); ?></label>
            <div>
                <button class="mo-btn" id="moTplSave"><?php echo h(ma('save')); ?></button>
                <button class="mo-btn secondary" id="moTplCancel"><?php echo h(ma('cancel')); ?></button>
                <button class="mo-btn danger mo-hidden" id="moTplDelete"><?php echo h(ma('delete')); ?></button>
            </div>
            <p class="mo-step-note"><?php echo h(ma('tpl_placeholders')); ?></p>
        </div>
    </div>
</section>

<!-- ============ HISTORY ============ -->
<section id="moTab-history" class="mo-pane mo-hidden">
    <div class="pa-card">
        <h2><?php echo h(ma('hist_sends_title')); ?></h2>
        <div id="moHistSends"></div>
    </div>
    <div class="pa-card" style="margin-top:16px;">
        <h2><?php echo h(ma('hist_audit_title')); ?></h2>
        <div id="moHistAudit"></div>
    </div>
</section>

<script>
(function () {
    'use strict';
    var CSRF = <?php echo json_encode($csrfToken); ?>;
    var API = 'api/marketing-admin.php';
    var STR = {
        loadFailed: <?php echo json_encode(ma('load_failed')); ?>,
        sheetDown: <?php echo json_encode(ma('sheet_unavailable')); ?>,
        noProspects: <?php echo json_encode(ma('no_prospects')); ?>,
        noCampaigns: <?php echo json_encode(ma('no_campaigns')); ?>,
        noFollowups: <?php echo json_encode(ma('no_followups')); ?>,
        noTemplates: <?php echo json_encode(ma('no_templates')); ?>,
        noSends: <?php echo json_encode(ma('no_sends')); ?>,
        confirmSend: <?php echo json_encode(ma('confirm_send')); ?>,
        working: <?php echo json_encode(ma('working')); ?>,
        cancelled: <?php echo json_encode(ma('cancelled')); ?>
    };
    var state = { prospectPage: 1, currentCampaign: null, templates: [] };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function showErr(m) { var e = document.getElementById('moGlobalError'); e.textContent = m; e.classList.remove('mo-hidden'); }
    function showOk(m) { var e = document.getElementById('moGlobalOk'); e.textContent = m; e.classList.remove('mo-hidden'); setTimeout(function(){ e.classList.add('mo-hidden'); }, 6000); }
    function clearMsgs() { document.getElementById('moGlobalError').classList.add('mo-hidden'); document.getElementById('moGlobalOk').classList.add('mo-hidden'); }

    function apiGet(action, params) {
        var qs = '?action=' + encodeURIComponent(action);
        if (params) Object.keys(params).forEach(function (k) { if (params[k] !== '' && params[k] != null) qs += '&' + k + '=' + encodeURIComponent(params[k]); });
        return fetch(API + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function apiPost(action, payload) {
        payload = payload || {}; payload.action = action; payload.csrf_token = CSRF;
        return fetch(API, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(function (r) { return r.json(); });
    }
    function fmtDate(ts) { return ts ? new Date(ts * 1000).toISOString().slice(0, 10) : ''; }
    function fmtDT(s) { if (!s) return ''; var d = new Date(s.replace(' ', 'T') + 'Z'); return isNaN(d) ? s : d.toISOString().slice(0, 16).replace('T', ' '); }

    // ---------- tabs ----------
    document.querySelectorAll('.mo-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.mo-tab').forEach(function (t) { t.classList.remove('is-active'); });
            tab.classList.add('is-active');
            document.querySelectorAll('.mo-pane').forEach(function (p) { p.classList.add('mo-hidden'); });
            document.getElementById('moTab-' + tab.dataset.tab).classList.remove('mo-hidden');
            loadTab(tab.dataset.tab);
        });
    });
    function loadTab(name) {
        clearMsgs();
        if (name === 'overview') loadOverview();
        else if (name === 'prospects') loadProspects();
        else if (name === 'campaigns') loadCampaigns();
        else if (name === 'followups') loadFollowups('');
        else if (name === 'templates') loadTemplates();
        else if (name === 'history') loadHistory();
    }

    // ---------- overview ----------
    function loadOverview() {
        apiGet('overview').then(function (d) {
            if (!d.success) { showErr(STR.loadFailed); return; }
            var o = d.overview, html = '';
            var metrics = [
                ['Prospects', o.prospects_total], ['Eligible', o.prospects_eligible],
                ['Due today', o.followup_buckets.due_today], ['Overdue', o.followup_buckets.overdue],
                ['Upcoming', o.followup_buckets.upcoming], ['Awaiting response', o.followup_buckets.awaiting_response],
                ['Campaigns', Object.keys(o.campaigns || {}).reduce(function (a, k) { return a + o.campaigns[k]; }, 0)],
                ['Sends (30d)', o.sends_30d], ['Suppressions', o.suppressions]
            ];
            metrics.forEach(function (m) {
                html += '<div class="pa-metric"><div class="pa-metric-value">' + esc(m[1]) + '</div><div class="pa-metric-label">' + esc(m[0]) + '</div></div>';
            });
            document.getElementById('moOvMetrics').innerHTML = html;
            var buckets = '';
            ['due_today','overdue','upcoming','awaiting_response','completed','excluded'].forEach(function (b) {
                buckets += '<span class="mo-pill ' + b + '">' + esc(b.replace(/_/g, ' ')) + ': ' + (o.followup_buckets[b] || 0) + '</span> ';
            });
            document.getElementById('moOvBuckets').innerHTML = buckets;
            var fl = '';
            ['missing_contact','do_not_contact','duplicate_org'].forEach(function (f) {
                fl += '<span class="mo-flag ' + f + '">' + esc(f.replace(/_/g, ' ')) + ': ' + (o.flags[f] || 0) + '</span> ';
            });
            document.getElementById('moOvFlags').innerHTML = fl || '—';
            document.getElementById('moOvSheetNote').textContent = o.sheet_error ? STR.sheetDown : '';
        }).catch(function () { showErr(STR.loadFailed); });
    }

    // ---------- prospects ----------
    function loadProspects() {
        var q = document.getElementById('moProspectQ').value;
        var flag = document.getElementById('moProspectFlag').value;
        var bucket = document.getElementById('moProspectBucket').value;
        apiGet('prospects', { q: q, flag: flag, bucket: bucket, page: state.prospectPage }).then(function (d) {
            var host = document.getElementById('moProspectList');
            if (!d.success) { showErr(d.error || STR.loadFailed); host.innerHTML = ''; return; }
            if (d.sheet_error) showErr(STR.sheetDown);
            if (!d.prospects.length) { host.innerHTML = '<div class="pa-card">' + esc(STR.noProspects) + '</div>'; return; }
            var html = '<table class="mo-table"><thead><tr>' +
                '<th>' + esc(<?php echo json_encode(ma('col_org')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_contact')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_type_fit')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_status')) ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_followup')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_flags')); ?>) + '</th></tr></thead><tbody>';
            d.prospects.forEach(function (p) {
                var flags = (p.flags || []).map(function (f) { return '<span class="mo-flag ' + f + '">' + esc(f.replace(/_/g, ' ')) + '</span>'; }).join('');
                html += '<tr class="clickable" data-email="' + esc(p.email) + '">' +
                    '<td><strong>' + esc(p.org || '(no org)') + '</strong>' +
                    (p.parent_org ? '<br><span style="color:#6b7280;font-size:0.75rem;">' + esc(p.parent_org) + '</span>' : '') + '</td>' +
                    '<td>' + esc(p.contact_name || '—') + '<br><span style="color:#6b7280;font-size:0.75rem;">' + esc(p.email || '(missing)') + '</span>' +
                    (p.title ? '<br><span style="color:#6b7280;font-size:0.72rem;">' + esc(p.title) + '</span>' : '') + '</td>' +
                    '<td>' + esc(p.practice_type || '—') + '<br><span style="color:#6b7280;font-size:0.75rem;">fit: ' + esc(p.fit || '—') + ' · ' + esc(p.priority || '—') + '</span></td>' +
                    '<td><span class="mo-pill">' + esc(p.status || '—') + '</span>' +
                    (p.response ? '<br><span style="color:#6b7280;font-size:0.72rem;">' + esc(p.response) + '</span>' : '') + '</td>' +
                    '<td>' + esc(fmtDate(p.followup_ts)) + '<br><span class="mo-pill ' + p.bucket + '">' + esc(p.bucket.replace(/_/g, ' ')) + '</span></td>' +
                    '<td>' + flags + '</td></tr>';
            });
            html += '</tbody></table>';
            host.innerHTML = html;
            host.querySelectorAll('tr.clickable').forEach(function (tr) {
                tr.addEventListener('click', function () { prospectDetail(tr.dataset.email); });
            });
            var pages = Math.ceil(d.total / d.per);
            document.getElementById('moProspectPager').innerHTML =
                '<button class="mo-btn secondary small" id="moPgPrev" ' + (state.prospectPage <= 1 ? 'disabled' : '') + '>←</button>' +
                '<span>' + state.prospectPage + ' / ' + pages + ' (' + d.total + ')</span>' +
                '<button class="mo-btn secondary small" id="moPgNext" ' + (state.prospectPage >= pages ? 'disabled' : '') + '>→</button>';
            document.getElementById('moPgPrev').onclick = function () { state.prospectPage--; loadProspects(); };
            document.getElementById('moPgNext').onclick = function () { state.prospectPage++; loadProspects(); };
        }).catch(function () { showErr(STR.loadFailed); });
    }
    document.getElementById('moProspectSearch').addEventListener('click', function () { state.prospectPage = 1; loadProspects(); });
    document.getElementById('moProspectQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { state.prospectPage = 1; loadProspects(); } });

    function prospectDetail(email) {
        apiGet('prospect', { email: email }).then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            var p = d.prospect, f = p.fields || {};
            var rows = [
                ['Organization', f.org], ['Parent org', f.parent_org], ['Type', f.practice_type],
                ['Contact', p.contact ? '' : ''], ['Name', (f.contact_first || '') + ' ' + (f.contact_last || '') || ''],
                ['Email', f.email], ['Title', f.title], ['Location', f.location], ['Website', f.website],
                ['Fit', f.fit], ['Priority', f.priority], ['Status', f.status], ['Response', f.response],
                ['Last contact', f.last_contact], ['Follow-up', fmtDate(p.followup_ts)],
                ['Campaign', f.campaign], ['Method', f.method]
            ];
            var html = '<div class="pa-card" style="margin-top:14px;"><h2>' + esc(f.org || email) + '</h2>' +
                '<div class="mo-detail-grid">';
            rows.forEach(function (r) {
                if (r[1] === '' || r[1] == null) return;
                html += '<div class="mo-detail-item"><div class="k">' + esc(r[0]) + '</div><div class="v">' + esc(r[1]) + '</div></div>';
            });
            html += '</div>';
            if ((p.flags || []).length) {
                html += '<p style="margin-top:10px;">' + p.flags.map(function (fl) { return '<span class="mo-flag ' + fl + '">' + esc(fl.replace(/_/g, ' ')) + '</span>'; }).join('') + '</p>';
            }
            if (f.notes) html += '<p class="mo-step-note" style="white-space:pre-wrap;">' + esc(f.notes) + '</p>';
            if (d.history && d.history.length) {
                html += '<h3 style="margin-top:14px;font-size:0.9rem;">' + esc(<?php echo json_encode(ma('hist_for_prospect')); ?>) + '</h3><table class="mo-table"><tbody>';
                d.history.forEach(function (h) {
                    html += '<tr><td>' + esc(h.campaign_ref) + '</td><td><span class="mo-pill ' + h.status + '">' + esc(h.status) + '</span></td><td>' + esc(fmtDT(h.sent_at || h.created_at)) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            html += '<div style="margin-top:10px;"><button class="mo-btn secondary small" id="moDetailClose">' + esc(<?php echo json_encode(ma('close')); ?>) + '</button></div></div>';
            var host = document.getElementById('moProspectList');
            var tmp = document.createElement('div'); tmp.innerHTML = html;
            host.parentNode.insertBefore(tmp.firstChild, host);
            document.getElementById('moDetailClose').onclick = function () { this.closest('.pa-card').remove(); };
        }).catch(function () { showErr(STR.loadFailed); });
    }

    // ---------- campaigns ----------
    function loadCampaigns() {
        state.currentCampaign = null;
        document.getElementById('moCampaignDetail').classList.add('mo-hidden');
        apiGet('campaigns').then(function (d) {
            var host = document.getElementById('moCampaignList');
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            if (!d.campaigns.length) { host.innerHTML = '<div class="pa-card">' + esc(STR.noCampaigns) + '</div>'; return; }
            var html = '<table class="mo-table"><thead><tr><th>' + esc(<?php echo json_encode(ma('col_name')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_kind')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_status')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_recipients')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_sent')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_created')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_by')); ?>) + '</th></tr></thead><tbody>';
            d.campaigns.forEach(function (c) {
                html += '<tr class="clickable" data-id="' + esc(c.id) + '"><td><strong>' + esc(c.name) + '</strong></td>' +
                    '<td>' + esc(c.kind) + '</td><td><span class="mo-pill ' + c.status + '">' + esc(c.status) + '</span></td>' +
                    '<td>' + c.recipients + '</td><td>' + c.sent + '</td><td>' + esc(fmtDT(c.created_at)) + '</td><td>' + esc(c.created_by || '') + '</td></tr>';
            });
            host.innerHTML = html + '</tbody></table>';
            host.querySelectorAll('tr.clickable').forEach(function (tr) {
                tr.addEventListener('click', function () { openCampaign(tr.dataset.id); });
            });
        }).catch(function () { showErr(STR.loadFailed); });
    }
    document.getElementById('moNewCampaign').addEventListener('click', function () {
        var emails = prompt(<?php echo json_encode(ma('recipient_emails_prompt')); ?>);
        if (!emails) return;
        var name = prompt(<?php echo json_encode(ma('campaign_name_prompt')); ?>, 'Campaign ' + new Date().toISOString().slice(0, 10));
        if (!name) return;
        var list = emails.split(/[\s,;]+/).filter(Boolean);
        apiPost('create_campaign', { name: name, kind: 'initial', emails: list }).then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            showOk(<?php echo json_encode(ma('campaign_created')); ?>);
            openCampaign(d.campaign_id);
        });
    });

    function openCampaign(id) {
        state.currentCampaign = id;
        document.getElementById('moCampaignList').classList.add('mo-hidden');
        document.getElementById('moCampaignDetail').classList.remove('mo-hidden');
        apiGet('campaign', { id: id }).then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            var c = d.campaign;
            document.getElementById('moCampTitle').textContent = c.name;
            document.getElementById('moCampMeta').textContent =
                c.kind + ' · ' + c.status + (c.created_by ? ' · ' + c.created_by : '') +
                (c.approved_by ? ' · approved by ' + c.approved_by : '');
            var warn = document.getElementById('moCampApproveWarn');
            if (c.status === 'approved' || c.status === 'partial') {
                warn.textContent = <?php echo json_encode(ma('approve_warn')); ?>;
                warn.classList.remove('mo-hidden');
            } else warn.classList.add('mo-hidden');
            var editable = (c.status === 'draft' || c.status === 'ready');
            var retryable = (c.status === 'partial');
            document.getElementById('moCampGenerate').disabled = !editable;
            document.getElementById('moCampApprove').disabled = !(c.status === 'draft' || c.status === 'ready');
            document.getElementById('moCampApprove').classList.toggle('mo-hidden', retryable);
            document.getElementById('moCampRetry').classList.toggle('mo-hidden', !retryable);
            document.getElementById('moCampStep').textContent = editable ? <?php echo json_encode(ma('step_edit')); ?> : '';
            renderMessages(d.recipients, editable);
        }).catch(function () { showErr(STR.loadFailed); });
    }

    function renderMessages(recipients, editable) {
        var host = document.getElementById('moCampMessages');
        host.innerHTML = '';
        recipients.forEach(function (r) {
            var div = document.createElement('div');
            div.className = 'mo-msg';
            var flags = (r.status === 'suppressed' || r.status === 'skipped')
                ? '<span class="mo-pill ' + r.status + '">' + esc(r.status + (r.skip_reason ? ': ' + r.skip_reason : '')) + '</span>' : '';
            var canEdit = editable && r.status !== 'suppressed';
            div.innerHTML =
                '<div class="mo-msg-head"><strong>' + esc(r.prospect_contact || r.prospect_email) + '</strong>' +
                '<span>' + flags + '<span class="mo-pill ' + r.status + '">' + esc(r.status) + '</span>' +
                (r.is_edited == 1 ? ' <span class="mo-pill">' + esc(<?php echo json_encode(ma('edited')); ?>) + '</span>' : '') + '</span></div>' +
                '<div class="mo-msg-sub">' + esc(r.prospect_org || '') + ' · ' + esc(r.prospect_email) +
                (r.error ? ' · <span style="color:#b91c1c;">' + esc(r.error) + '</span>' : '') + '</div>' +
                (canEdit
                    ? '<input type="text" class="mo-subj" value="' + esc(r.subject || '') + '" placeholder="' + esc(<?php echo json_encode(ma('subject_ph')); ?>) + '">' +
                      '<textarea class="mo-body">' + esc(r.body_html || '') + '</textarea>' +
                      '<div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">' +
                      '<button class="mo-btn small" data-act="save">' + esc(<?php echo json_encode(ma('save')); ?>) + '</button>' +
                      '<button class="mo-btn secondary small" data-act="preview">' + esc(<?php echo json_encode(ma('preview')); ?>) + '</button></div>' +
                      '<div class="mo-preview mo-hidden" style="margin-top:8px;"></div>'
                    : '<div class="mo-preview">' + (r.body_html || '<em>' + esc(<?php echo json_encode(ma('no_content')) ?>) + '</em>') + '</div>');
            host.appendChild(div);
            div.querySelectorAll('button[data-act]').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (b.dataset.act === 'preview') {
                        var pv = div.querySelector('.mo-preview');
                        pv.classList.toggle('mo-hidden');
                        if (!pv.classList.contains('mo-hidden')) pv.innerHTML = div.querySelector('.mo-body').value || '';
                    } else {
                        apiPost('save_message', { campaign_id: state.currentCampaign, recipient_id: r.id,
                            subject: div.querySelector('.mo-subj').value, body_html: div.querySelector('.mo-body').value })
                            .then(function (d2) { if (d2.success) { showOk(<?php echo json_encode(ma('saved')); ?>); openCampaign(state.currentCampaign); } else showErr(d2.error); });
                    }
                });
            });
        });
    }

    document.getElementById('moCampBack').addEventListener('click', loadCampaigns);
    document.getElementById('moCampGenerate').addEventListener('click', function () {
        var btn = this; btn.disabled = true; btn.textContent = STR.working;
        apiPost('generate', { campaign_id: state.currentCampaign }).then(function (d) {
            btn.disabled = false; btn.textContent = <?php echo json_encode(ma('generate_emails')); ?>;
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            showOk(<?php echo json_encode(ma('generated_ok')).replace('{n}', '%s'); ?>.replace('%s', d.generated));
            openCampaign(state.currentCampaign);
        });
    });
    document.getElementById('moCampRetry').addEventListener('click', function () {
        var btn = this;
        if (!window.confirm(STR.confirmSend)) return;
        btn.disabled = true; btn.textContent = STR.working;
        apiPost('send', { campaign_id: state.currentCampaign }).then(function (d2) {
            btn.disabled = false; btn.textContent = <?php echo json_encode(ma('retry_failed')); ?>;
            if (!d2.success) { showErr(d2.error || STR.loadFailed); openCampaign(state.currentCampaign); return; }
            var ok = <?php echo json_encode(ma('send_result')); ?>;
            showOk(ok.replace('{sent}', d2.sent).replace('{skipped}', d2.skipped).replace('{failed}', d2.failed));
            openCampaign(state.currentCampaign);
        });
    });
    document.getElementById('moCampApprove').addEventListener('click', function () {
        var btn = this;
        if (!window.confirm(STR.confirmSend)) return;
        btn.disabled = true; btn.textContent = STR.working;
        apiPost('approve', { campaign_id: state.currentCampaign }).then(function (d) {
            if (!d.success) { btn.disabled = false; btn.textContent = <?php echo json_encode(ma('approve_and_send')); ?>; showErr(d.error || STR.loadFailed); return; }
            // approved -> now send
            apiPost('send', { campaign_id: state.currentCampaign }).then(function (d2) {
                btn.disabled = false; btn.textContent = <?php echo json_encode(ma('approve_and_send')); ?>;
                if (!d2.success) { showErr(d2.error || STR.loadFailed); openCampaign(state.currentCampaign); return; }
                var ok = <?php echo json_encode(ma('send_result')); ?>;
                showOk(ok.replace('{sent}', d2.sent).replace('{skipped}', d2.skipped).replace('{failed}', d2.failed));
                openCampaign(state.currentCampaign);
            });
        });
    });

    // ---------- follow-ups ----------
    function loadFollowups(bucket) {
        apiGet('followups', { bucket: bucket }).then(function (d) {
            var host = document.getElementById('moFuList');
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            if (!d.followups.length) { host.innerHTML = '<div class="pa-card">' + esc(STR.noFollowups) + '</div>'; return; }
            var html = '<table class="mo-table"><thead><tr>' +
                '<th>' + esc(<?php echo json_encode(ma('col_org')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_contact')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_status')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_followup')); ?>) + '</th>' +
                '<th>' + esc(<?php echo json_encode(ma('col_last_contact')); ?>) + '</th>' +
                '<th></th></tr></thead><tbody>';
            d.followups.forEach(function (p) {
                html += '<tr><td><strong>' + esc(p.org || '—') + '</strong></td>' +
                    '<td>' + esc(p.contact_name || '—') + '<br><span style="color:#6b7280;font-size:0.75rem;">' + esc(p.email || '') + '</span></td>' +
                    '<td><span class="mo-pill">' + esc(p.status || '—') + '</span>' + (p.response ? '<br><span style="color:#6b7280;font-size:0.72rem;">' + esc(p.response) + '</span>' : '') + '</td>' +
                    '<td>' + esc(fmtDate(p.followup_ts)) + ' <span class="mo-pill ' + p.bucket + '">' + esc(p.bucket.replace(/_/g, ' ')) + '</span></td>' +
                    '<td>' + esc(p.last_contact || '—') + '</td>' +
                    '<td>' + (p.eligible ? '<button class="mo-btn secondary small mo-fu-add" data-email="' + esc(p.email) + '">' + esc(<?php echo json_encode(ma('add_to_campaign')); ?>) + '</button>' : '') + '</td></tr>';
            });
            host.innerHTML = html + '</tbody></table>';
            host.querySelectorAll('.mo-fu-add').forEach(function (b) {
                b.addEventListener('click', function () { fuAddEmail(b.dataset.email); });
            });
        }).catch(function () { showErr(STR.loadFailed); });
    }
    document.getElementById('moFuLoad').addEventListener('click', function () { loadFollowups(document.getElementById('moFuBucket').value); });
    document.getElementById('moFuBucket').addEventListener('change', function () { loadFollowups(this.value); });
    var fuSelected = [];
    function fuAddEmail(email) {
        fuSelected.push(email);
        var name = prompt(<?php echo json_encode(ma('campaign_name_prompt')); ?>, 'Follow-up ' + new Date().toISOString().slice(0, 10));
        if (!name) { fuSelected = []; return; }
        apiPost('create_campaign', { name: name, kind: 'followup', emails: fuSelected }).then(function (d) {
            fuSelected = [];
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            showOk(<?php echo json_encode(ma('campaign_created')); ?>);
            document.querySelector('.mo-tab[data-tab="campaigns"]').click();
            openCampaign(d.campaign_id);
        });
    }
    document.getElementById('moFuCampaign').addEventListener('click', function () {
        var emails = prompt(<?php echo json_encode(ma('recipient_emails_prompt')); ?>);
        if (!emails) return;
        fuSelected = emails.split(/[\s,;]+/).filter(Boolean);
        if (fuSelected.length) fuAddEmail(fuSelected[0]);
    });

    // ---------- templates ----------
    function loadTemplates() {
        apiGet('templates').then(function (d) {
            var host = document.getElementById('moTplList');
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            state.templates = d.templates || [];
            if (!state.templates.length) { host.innerHTML = '<div class="pa-card">' + esc(STR.noTemplates) + '</div>'; return; }
            var html = '<table class="mo-table"><thead><tr><th>' + esc(<?php echo json_encode(ma('col_name')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_kind')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('tpl_subject')); ?>) + '</th><th></th></tr></thead><tbody>';
            state.templates.forEach(function (t) {
                html += '<tr class="clickable" data-id="' + t.id + '"><td><strong>' + esc(t.name) + '</strong>' + (t.is_default == 1 ? ' <span class="mo-pill eligible">' + esc(<?php echo json_encode(ma('default')); ?>) + '</span>' : '') + '</td>' +
                    '<td>' + esc(t.kind) + '</td><td>' + esc(t.subject_tpl) + '</td><td><button class="mo-btn secondary small" data-edit="' + t.id + '">' + esc(<?php echo json_encode(ma('edit')); ?>) + '</button></td></tr>';
            });
            host.innerHTML = html + '</tbody></table>';
            host.querySelectorAll('[data-edit]').forEach(function (b) {
                b.addEventListener('click', function (e) { e.stopPropagation(); editTemplate(+b.dataset.edit); });
            });
        }).catch(function () { showErr(STR.loadFailed); });
    }
    function editTemplate(id) {
        var t = state.templates.filter(function (x) { return +x.id === id; })[0];
        if (!t) return;
        document.getElementById('moTplEditor').classList.remove('mo-hidden');
        document.getElementById('moTplEditorTitle').textContent = t.name;
        document.getElementById('moTplName').value = t.name;
        document.getElementById('moTplKind').value = t.kind;
        document.getElementById('moTplSubject').value = t.subject_tpl;
        document.getElementById('moTplBody').value = t.body_tpl;
        document.getElementById('moTplDefault').checked = t.is_default == 1;
        document.getElementById('moTplDelete').classList.remove('mo-hidden');
        document.getElementById('moTplDelete').dataset.id = id;
    }
    document.getElementById('moTplNew').addEventListener('click', function () {
        document.getElementById('moTplEditor').classList.remove('mo-hidden');
        document.getElementById('moTplEditorTitle').textContent = <?php echo json_encode(ma('new_template')); ?>;
        document.getElementById('moTplName').value = '';
        document.getElementById('moTplKind').value = 'initial';
        document.getElementById('moTplSubject').value = '';
        document.getElementById('moTplBody').value = '';
        document.getElementById('moTplDefault').checked = false;
        document.getElementById('moTplDelete').classList.add('mo-hidden');
        document.getElementById('moTplDelete').dataset.id = '';
    });
    document.getElementById('moTplCancel').addEventListener('click', function () { document.getElementById('moTplEditor').classList.add('mo-hidden'); });
    document.getElementById('moTplSave').addEventListener('click', function () {
        apiPost('template_save', {
            id: +document.getElementById('moTplDelete').dataset.id || 0,
            name: document.getElementById('moTplName').value,
            kind: document.getElementById('moTplKind').value,
            subject_tpl: document.getElementById('moTplSubject').value,
            body_tpl: document.getElementById('moTplBody').value,
            is_default: document.getElementById('moTplDefault').checked ? 1 : 0
        }).then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            showOk(<?php echo json_encode(ma('saved')); ?>);
            document.getElementById('moTplEditor').classList.add('mo-hidden');
            loadTemplates();
        });
    });
    document.getElementById('moTplDelete').addEventListener('click', function () {
        var id = +this.dataset.id; if (!id) return;
        if (!window.confirm(<?php echo json_encode(ma('confirm_delete_template')); ?>)) return;
        apiPost('template_delete', { id: id }).then(function (d) {
            if (d.success) { document.getElementById('moTplEditor').classList.add('mo-hidden'); loadTemplates(); }
        });
    });

    // ---------- history ----------
    function loadHistory() {
        apiGet('history').then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            var hs = document.getElementById('moHistSends');
            if (!d.sends.length) hs.innerHTML = '<p>' + esc(STR.noSends) + '</p>';
            else {
                var html = '<table class="mo-table"><thead><tr><th>' + esc(<?php echo json_encode(ma('col_campaign')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_email')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_status')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_sent_at')); ?>) + '</th></tr></thead><tbody>';
                d.sends.forEach(function (s) {
                    html += '<tr><td>' + esc(s.campaign_ref) + '</td><td>' + esc(s.email_normalized) + '</td><td><span class="mo-pill ' + s.status + '">' + esc(s.status) + '</span></td><td>' + esc(fmtDT(s.sent_at || s.created_at)) + '</td></tr>';
                });
                hs.innerHTML = html + '</tbody></table>';
            }
            var ha = document.getElementById('moHistAudit');
            if (!d.audit.length) ha.innerHTML = '<p>—</p>';
            else {
                var ah = '<table class="mo-table"><thead><tr><th>' + esc(<?php echo json_encode(ma('col_time')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_actor')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_action')); ?>) + '</th><th>' + esc(<?php echo json_encode(ma('col_detail')); ?>) + '</th></tr></thead><tbody>';
                d.audit.forEach(function (a) {
                    ah += '<tr><td>' + esc(fmtDT(a.created_at)) + '</td><td>' + esc(a.actor_email) + '</td><td>' + esc(a.action) + '</td><td style="font-size:0.75rem;color:#6b7280;">' + esc((a.detail || '').slice(0, 120)) + '</td></tr>';
                });
                ha.innerHTML = ah + '</tbody></table>';
            }
        }).catch(function () { showErr(STR.loadFailed); });
    }

    loadTab('overview');
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
