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
$crmReads = isFeatureEnabled('MARKETING_CRM_READS');
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title><?php echo h(ma('title')); ?> - <?php echo h($appConfig['appName']); ?></title>
    <?php require __DIR__ . '/partials/brand-icons.php'; ?>
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
        .mo-pill.uncertain, .mo-pill.sheet-failed, .mo-pill.thread-new { background: #fef3c7; color: #92400e; }
        .mo-pill.thread-resolved, .mo-pill.sheet-synced { background: #d1fae5; color: #065f46; }
        .mo-sending { padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 14px; border: 1px solid #d1d5db; background: #f9fafb; color: #374151; }
        .mo-sending.allowlist { border-color: #fde68a; background: #fffbeb; color: #92400e; }
        .mo-sending.unrestricted { border-color: #fecaca; background: #fef2f2; color: #991b1b; font-weight: 600; }
        .mo-msg-note { font-size: 0.75rem; color: #6b7280; margin: 4px 0; }
        .mo-subj[readonly] { background: #f3f4f6; color: #4b5563; }
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
        .mo-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .mo-msg-list { display: flex; flex-direction: column; gap: 10px; }
        .mo-msg { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; }
        .mo-msg-head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: baseline; }
        .mo-msg-head strong { font-size: 0.9rem; }
        .mo-msg-sub { font-size: 0.78rem; color: #6b7280; margin: 2px 0 8px; word-break: break-all; }
        .mo-msg textarea, .mo-msg input[type="text"] {
            width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d1d5db;
            border-radius: 6px; font-size: 0.85rem; font-family: inherit; }
        .mo-msg textarea { min-height: 220px; font-family: ui-monospace, monospace; font-size: 0.8rem; }
        .mo-rte { border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden; }
        .mo-rte-bar { display: flex; gap: 4px; padding: 6px 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
        .mo-rte-bar button { min-width: 30px; height: 28px; padding: 0 8px; border: 1px solid #d1d5db; border-radius: 5px;
                             background: #fff; font-size: 0.8rem; cursor: pointer; color: #374151; }
        .mo-rte-bar button:hover { background: #eef2ff; border-color: #1e40af; }
        .mo-body-edit { min-height: 200px; max-height: 480px; overflow-y: auto; padding: 12px 14px;
                        font-size: 0.9rem; line-height: 1.55; outline: none; }
        .mo-body-edit:focus { box-shadow: inset 0 0 0 2px #1e40af33; }
        .mo-body-edit ul { margin: 6px 0 6px 22px; }
        .mo-rte-hint { font-size: 0.72rem; color: #9ca3af; padding: 6px 10px; background: #f9fafb; border-top: 1px solid #e5e7eb; }
        .mo-preview { border: 1px dashed #d1d5db; border-radius: 8px; padding: 14px; background: #fafafa;
                      font-size: 0.85rem; max-height: 320px; overflow: auto; }
        .mo-detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .mo-detail-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; }
        .mo-detail-item .k { font-size: 0.72rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
        .mo-detail-item .v { font-size: 0.88rem; font-weight: 600; margin-top: 2px; word-break: break-word; }
        .mo-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 14px; }
        .mo-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 14px; }
        .mo-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 14px; }
        .mo-hidden { display: none !important; }
        .mo-nowrap { white-space: nowrap; }
        .mo-crm-table th.mo-crm-check, .mo-crm-table td.mo-crm-check { width: 30px; }
        .mo-pager { display: flex; gap: 8px; align-items: center; margin-top: 12px; font-size: 0.82rem; color: #6b7280; }
        .mo-step-note { font-size: 0.78rem; color: #6b7280; margin-top: 6px; }
        /* Phase 3 CRM */
        .mo-sub { color: #6b7280; font-size: 0.75rem; }
        .mo-sortable { cursor: pointer; user-select: none; }
        .mo-sortable:hover { color: #1e40af; }
        .mo-crm-check { width: 28px; text-align: center; }
        .mo-crm-table td.mo-crm-check { vertical-align: middle; }
        .mo-bulkbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 10px 0;
                      padding: 10px 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 0.85rem; }
        .mo-bulkbar select, .mo-bulkbar input { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.82rem; }
        .mo-modal-back { position: fixed; inset: 0; background: rgba(17,24,39,0.45); z-index: 1200;
                         display: flex; align-items: flex-start; justify-content: center; padding: 4vh 16px; overflow-y: auto; }
        .mo-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 720px;
                    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
                    display: flex; flex-direction: column; max-height: calc(100vh - 8vh - 16px); }
        .mo-modal-head { display: flex; justify-content: space-between; align-items: center; flex: none;
                         padding: 16px 20px; border-bottom: 1px solid #e5e7eb; }
        .mo-modal-head h3 { margin: 0; font-size: 1rem; }
        .mo-modal-x { background: none; border: none; font-size: 1.5rem; line-height: 1; cursor: pointer; color: #6b7280; }
        .mo-modal-body { padding: 16px 20px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
        .mo-modal-foot { padding: 12px 20px; border-top: 1px solid #e5e7eb; flex: none;
                         display: flex; gap: 10px; justify-content: flex-end; }
        .mo-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px 14px; }
        .mo-field { display: flex; flex-direction: column; gap: 4px; font-size: 0.8rem; color: #374151; }
        .mo-field input, .mo-field select, .mo-field textarea { padding: 8px 10px; border: 1px solid #d1d5db;
            border-radius: 6px; font-size: 0.85rem; font-family: inherit; }
        .mo-field textarea { resize: vertical; }
        .mo-drawer { position: fixed; top: 0; right: 0; height: 100vh; width: min(560px, 96vw); background: #fff;
                     z-index: 1100; box-shadow: -8px 0 30px rgba(0,0,0,0.18); overflow-y: auto; }
        .mo-drawer-head { display: flex; justify-content: space-between; align-items: center;
                          padding: 16px 20px; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; background: #fff; }
        .mo-drawer-head h2 { margin: 0; font-size: 1.05rem; }
        .mo-drawer-body { padding: 16px 20px 40px; }
        .mo-events { list-style: none; margin: 8px 0 0; padding: 0; }
        .mo-events li { border-left: 2px solid #e5e7eb; padding: 6px 0 6px 12px; margin-left: 4px; }
        .mo-activity-add { display: flex; flex-direction: column; gap: 8px; margin: 10px 0; }
        .mo-activity-add select, .mo-activity-add textarea { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; font-family: inherit; }
        /* Native select arrows sit flush against the border; give every
           Marketing Outreach select a consistent right gutter and a custom
           chevron so spacing is identical across browsers. Placed last so
           it wins over the padding shorthands above. */
        .mo-pane select, .mo-bulkbar select, .mo-field select, .mo-modal select,
        .mo-drawer select, .mo-toolbar select, .mo-activity-add select, .mo-msg select {
            -webkit-appearance: none; appearance: none;
            padding-right: 2rem;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%236b7280' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 0.6rem center; background-size: 10px 6px;
        }
        @media (max-width: 720px) {
            .mo-table { display: block; overflow-x: auto; }
            .mo-msg textarea { min-height: 160px; }
        }
        /* ---------- performance dashboard ---------- */
        .mo-dash-filters select, .mo-dash-filters input[type="date"] {
            padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; }
        .mo-dash-note { font-size: 0.75rem; color: #6b7280; margin: -8px 0 14px; }
        .mo-dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .mo-dash-wide { grid-column: 1 / -1; }
        .mo-kpi { cursor: pointer; transition: box-shadow .15s ease; }
        .mo-kpi:hover { box-shadow: 0 0 0 2px #1e40af33; }
        .mo-kpi:hover .pa-metric-value { color: #1e40af; }
        .mo-kpi .pa-metric-note { font-size: 0.72rem; color: #6b7280; margin-top: 2px; }
        .mo-kpi.na { cursor: default; }
        .mo-kpi.na:hover { box-shadow: none; }
        .mo-hbar { display: grid; grid-template-columns: minmax(80px, 170px) 1fr 48px; gap: 8px;
                   align-items: center; padding: 5px 6px; margin: 0 -6px; border-radius: 6px;
                   font-size: 0.82rem; cursor: pointer; }
        .mo-hbar:hover { background: #f8fafc; }
        .mo-hbar-label { color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mo-hbar:hover .mo-hbar-label { color: #1e40af; }
        .mo-hbar-track { background: #f3f4f6; border-radius: 5px; height: 14px; overflow: hidden; }
        .mo-hbar-fill { height: 100%; background: #1e40af; border-radius: 5px; min-width: 2px; }
        .mo-hbar-fill.alt { background: #0ea5e9; } .mo-hbar-fill.good { background: #10b981; }
        .mo-hbar-fill.warn { background: #f59e0b; } .mo-hbar-fill.bad { background: #ef4444; }
        .mo-hbar-fill.muted { background: #9ca3af; }
        .mo-hbar-n { text-align: right; font-variant-numeric: tabular-nums; color: #374151; font-weight: 600; }
        .mo-chart { width: 100%; height: 190px; display: block; }
        .mo-chart .axis { font-size: 10px; fill: #6b7280; }
        .mo-chart .grid { stroke: #f3f4f6; }
        .mo-chart .bar-sent { fill: #1e40af; }
        .mo-chart .bar-failed { fill: #ef4444; }
        .mo-chart .bar-uncertain { fill: #f59e0b; }
        .mo-chart .reply-dot { fill: #059669; }
        .mo-chart .dot { fill: #0ea5e9; }
        .mo-legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: 0.75rem; color: #6b7280; margin-top: 6px; }
        .mo-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 5px; }
        .mo-attn { display: flex; flex-direction: column; }
        .mo-attn-row { display: flex; justify-content: space-between; align-items: center; gap: 10px;
                       padding: 8px 10px; margin: 0 -10px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
        .mo-attn-row:hover { background: #f8fafc; }
        .mo-attn-row .k { color: #374151; }
        .mo-attn-n { font-weight: 700; font-variant-numeric: tabular-nums; color: #1f2937; }
        .mo-attn-row.zero { opacity: 0.45; cursor: default; }
        .mo-attn-row.zero:hover { background: none; }
        .mo-seg-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 20px 24px; }
        .mo-seg-title { font-size: 0.78rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px; }
        .mo-empty-note { color: #9ca3af; font-size: 0.8rem; padding: 8px 0; }
    </style>
</head>
<?php paAdminChrome('marketing', ma('title'), ma('subtitle')); ?>

<div id="moSendingBanner" class="mo-sending mo-hidden" role="status"></div>
<div id="moGlobalError" class="mo-error mo-hidden"></div>
<div id="moGlobalOk" class="mo-ok mo-hidden"></div>

<div class="mo-tabs" role="tablist">
    <button class="mo-tab is-active" data-tab="overview" role="tab"><?php echo h(ma('tab_overview')); ?></button>
    <button class="mo-tab" data-tab="prospects" role="tab"><?php echo h(ma('tab_prospects')); ?></button>
    <button class="mo-tab" data-tab="campaigns" role="tab"><?php echo h(ma('tab_campaigns')); ?></button>
    <button class="mo-tab" data-tab="followups" role="tab"><?php echo h(ma('tab_followups')); ?></button>
    <button class="mo-tab" data-tab="replies" role="tab"><?php echo h(ma('tab_replies')); ?></button>
    <button class="mo-tab" data-tab="templates" role="tab"><?php echo h(ma('tab_templates')); ?></button>
    <button class="mo-tab" data-tab="history" role="tab"><?php echo h(ma('tab_history')); ?></button>
</div>

<!-- ============ OVERVIEW / PERFORMANCE DASHBOARD ============ -->
<section id="moTab-overview" class="mo-pane">
    <div class="mo-toolbar" id="moDashFilters">
        <select id="moDashDays" aria-label="<?php echo h(ma('dash_period')); ?>">
            <option value="7"><?php echo h(ma('dash_last7')); ?></option>
            <option value="30" selected><?php echo h(ma('dash_last30')); ?></option>
            <option value="90"><?php echo h(ma('dash_last90')); ?></option>
            <option value="custom"><?php echo h(ma('dash_custom')); ?></option>
        </select>
        <span id="moDashCustom" class="mo-hidden">
            <input type="date" id="moDashFrom" aria-label="<?php echo h(ma('dash_from')); ?>">
            <input type="date" id="moDashTo" aria-label="<?php echo h(ma('dash_to')); ?>">
        </span>
        <select id="moDashCampaign"><option value=""><?php echo h(ma('dash_all_campaigns')); ?></option></select>
        <select id="moDashKind">
            <option value=""><?php echo h(ma('dash_all_types')); ?></option>
            <option value="initial"><?php echo h(ma('kind_initial_lbl')); ?></option>
            <option value="followup"><?php echo h(ma('kind_followup_lbl')); ?></option>
        </select>
        <select id="moDashOrgType"><option value=""><?php echo h(ma('dash_all_orgtypes')); ?></option></select>
        <select id="moDashLocation"><option value=""><?php echo h(ma('dash_all_locations')); ?></option></select>
        <button type="button" class="mo-btn secondary small" id="moDashReset"><?php echo h(ma('dash_reset')); ?></button>
    </div>
    <p class="mo-dash-note" id="moDashNote"></p>

    <div class="pa-grid" id="moDashKpis"></div>

    <div class="mo-dash-grid">
        <div class="pa-card mo-dash-wide">
            <h2><?php echo h(ma('dash_chart_title')); ?></h2>
            <div id="moDashChart"></div>
            <div class="mo-legend" id="moDashLegend"></div>
            <p class="mo-step-note" id="moDashChartNote"></p>
        </div>
        <div class="pa-card">
            <h2><?php echo h(ma('dash_pipe_title')); ?></h2>
            <div id="moDashPipe"></div>
            <p class="mo-step-note" id="moDashPipeNote"></p>
        </div>
    </div>

    <div class="mo-dash-grid">
        <div class="pa-card mo-dash-wide">
            <h2><?php echo h(ma('dash_camp_title')); ?></h2>
            <div id="moDashCamps"></div>
        </div>
        <div class="pa-card">
            <h2><?php echo h(ma('dash_attn_title')); ?></h2>
            <div id="moDashAttn"></div>
        </div>
    </div>

    <div class="pa-card">
        <h2><?php echo h(ma('dash_seg_title')); ?></h2>
        <div class="mo-seg-cols" id="moDashSegs"></div>
        <p class="mo-step-note" id="moDashSegNote"></p>
    </div>
    <p class="mo-step-note" id="moDashDefs"></p>
</section>

<!-- ============ PROSPECTS ============ -->
<section id="moTab-prospects" class="mo-pane mo-hidden">
    <?php if ($crmReads): ?>
    <div class="mo-toolbar">
        <input type="search" id="moCrmQ" placeholder="<?php echo h(ma('search_placeholder')); ?>">
        <select id="moCrmStatus">
            <option value=""><?php echo h(ma('filter_all_status')); ?></option>
            <option>Not Contacted</option><option>Sent</option><option>Do Not Contact</option>
        </select>
        <select id="moCrmPriority">
            <option value=""><?php echo h(ma('filter_all_priority')); ?></option>
            <option>1 - Highest Priority</option><option>2 - Strong Priority</option>
            <option>3 - Worth Contacting</option><option>B - Enrich Next</option>
        </select>
        <input type="text" id="moCrmOrgType" placeholder="<?php echo h(ma('org_type_ph')); ?>" style="width:150px;">
        <select id="moCrmEligible">
            <option value=""><?php echo h(ma('filter_all_eligible')); ?></option>
            <option value="initial">initial</option>
            <option value="followup">follow-up</option>
        </select>
        <select id="moCrmFlag">
            <option value=""><?php echo h(ma('filter_all_flags')); ?></option>
            <option value="missing_contact"><?php echo h(ma('flag_missing_contact')); ?></option>
            <option value="do_not_contact"><?php echo h(ma('flag_do_not_contact')); ?></option>
            <option value="duplicate_org"><?php echo h(ma('flag_duplicate_org')); ?></option>
        </select>
        <select id="moCrmBucket">
            <option value=""><?php echo h(ma('bucket_all')); ?></option>
            <option value="due_today"><?php echo h(ma('bucket_due_today')); ?></option>
            <option value="overdue"><?php echo h(ma('bucket_overdue')); ?></option>
            <option value="upcoming"><?php echo h(ma('bucket_upcoming')); ?></option>
            <option value="awaiting_response"><?php echo h(ma('bucket_awaiting_response')); ?></option>
            <option value="not_contacted"><?php echo h(ma('bucket_not_contacted')); ?></option>
            <option value="completed"><?php echo h(ma('bucket_completed')); ?></option>
            <option value="excluded"><?php echo h(ma('bucket_excluded')); ?></option>
        </select>
        <label style="font-size:0.82rem;"><input type="checkbox" id="moCrmReviewOnly"> <?php echo h(ma('needs_review_filter')); ?></label>
        <button class="mo-btn secondary" id="moCrmSearchBtn"><?php echo h(ma('search')); ?></button>
        <button class="mo-btn" id="moCrmAdd"><?php echo h(ma('add_prospect')); ?></button>
        <button class="mo-btn secondary" id="moCrmNewOrg"><?php echo h(ma('add_org')); ?></button>
        <button class="mo-btn secondary" id="moCrmImportBtn"><?php echo h(ma('import_csv')); ?></button>
        <button class="mo-btn secondary" id="moCrmExportBtn"><?php echo h(ma('export_csv')); ?></button>
    </div>
    <div class="mo-bulkbar mo-hidden" id="moBulkBar">
        <strong id="moBulkCount"></strong>
        <select id="moBulkPriority"><option value=""><?php echo h(ma('bulk_set_priority')); ?></option>
            <option>1 - Highest Priority</option><option>2 - Strong Priority</option>
            <option>3 - Worth Contacting</option><option>B - Enrich Next</option></select>
        <input type="email" id="moBulkOwner" placeholder="<?php echo h(ma('bulk_owner_ph')); ?>" style="width:180px;">
        <input type="text" id="moBulkTags" placeholder="<?php echo h(ma('bulk_tags_ph')); ?>" style="width:160px;">
        <button class="mo-btn secondary small" id="moBulkCampaign"><?php echo h(ma('add_to_campaign')); ?></button>
        <button class="mo-btn secondary small" id="moBulkExport"><?php echo h(ma('export_selected')); ?></button>
        <button class="mo-btn secondary small" id="moBulkClear"><?php echo h(ma('clear_selection')); ?></button>
    </div>
    <div id="moCrmProspectList"></div>
    <div class="mo-pager" id="moCrmPager"></div>
    <?php else: ?>
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
            <option value="not_contacted"><?php echo h(ma('bucket_not_contacted')); ?></option>
            <option value="completed"><?php echo h(ma('bucket_completed')); ?></option>
            <option value="excluded"><?php echo h(ma('bucket_excluded')); ?></option>
        </select>
        <button class="mo-btn secondary" id="moProspectSearch"><?php echo h(ma('search')); ?></button>
    </div>
    <div id="moProspectList"></div>
    <div class="mo-pager" id="moProspectPager"></div>
    <?php endif; ?>
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
            <div class="mo-toolbar" id="moCampLegacyBar"<?php echo $crmReads ? ' style="display:none"' : ''; ?>>
                <button class="mo-btn secondary" id="moCampBack"><?php echo h(ma('back_to_list')); ?></button>
                <button class="mo-btn" id="moCampGenerate"><?php echo h(ma('generate_emails')); ?></button>
                <button class="mo-btn secondary" id="moCampApprove"><?php echo h(ma('approve_and_send')); ?></button>
                <button class="mo-btn danger mo-hidden" id="moCampRetry"><?php echo h(ma('retry_failed')); ?></button>
                <button class="mo-btn secondary mo-hidden" id="moCampThreads"><?php echo h(ma('recheck_threads')); ?></button>
                <button class="mo-btn secondary mo-hidden" id="moCampReconcile"><?php echo h(ma('reconcile_uncertain')); ?></button>
                <button class="mo-btn secondary mo-hidden" id="moCampSheetSync"><?php echo h(ma('retry_sheet_sync')); ?></button>
                <span class="mo-step-note" id="moCampStep"></span>
            </div>
            <?php if ($crmReads): ?>
            <div class="mo-toolbar" id="moCampCrmBar">
                <button class="mo-btn secondary" id="moCampBackCrm"><?php echo h(ma('back_to_list')); ?></button>
                <button class="mo-btn" id="moCampGenerateCrm"><?php echo h(ma('generate_emails')); ?></button>
                <button class="mo-btn secondary" id="moCampAddRecipCrm"><?php echo h(ma('add_recipients')); ?></button>
                <button class="mo-btn secondary" id="moCampEditCrm"><?php echo h(ma('edit_campaign')); ?></button>
                <select id="moCampTplCrm" title="<?php echo h(ma('template')); ?>"></select>
                <button class="mo-btn secondary" id="moCampApproveCrm"><?php echo h(ma('approve')); ?></button>
                <button class="mo-btn mo-hidden" id="moCampSendCrm"><?php echo h(ma('send_campaign')); ?></button>
                <button class="mo-btn danger mo-hidden" id="moCampRetryCrm"><?php echo h(ma('retry_failed')); ?></button>
                <button class="mo-btn secondary mo-hidden" id="moCampThreadsCrm"><?php echo h(ma('recheck_threads')); ?></button>
                <button class="mo-btn secondary mo-hidden" id="moCampReconcileCrm"><?php echo h(ma('reconcile_uncertain')); ?></button>
                <button class="mo-btn secondary" id="moCampSigCrm"><?php echo h(ma('sender_signature')); ?></button>
                <span class="mo-step-note" id="moCampStepCrm"></span>
            </div>
            <?php endif; ?>
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
            <option value="not_contacted"><?php echo h(ma('bucket_not_contacted')); ?></option>
            <option value="completed"><?php echo h(ma('bucket_completed')); ?></option>
            <option value="excluded"><?php echo h(ma('bucket_excluded')); ?></option>
        </select>
        <button class="mo-btn secondary" id="moFuLoad"><?php echo h(ma('load')); ?></button>
        <button class="mo-btn" id="moFuCampaign"<?php echo $crmReads ? ' style="display:none"' : ''; ?>><?php echo h(ma('new_followup_campaign')); ?></button>
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
<!-- ============ REPLIES ============ -->
<section id="moTab-replies" class="mo-pane mo-hidden">
    <div class="pa-card">
        <div class="mo-toolbar" style="margin-bottom:10px;">
            <select id="moReplyStatus">
                <option value="new"><?php echo h(ma('reply_filter_new')); ?></option>
                <option value=""><?php echo h(ma('reply_filter_all')); ?></option>
                <option value="reviewed"><?php echo h(ma('reply_filter_reviewed')); ?></option>
                <option value="dismissed"><?php echo h(ma('reply_filter_dismissed')); ?></option>
            </select>
            <select id="moReplyClass">
                <option value=""><?php echo h(ma('reply_filter_allclass')); ?></option>
                <option value="human"><?php echo h(ma('reply_cls_human')); ?></option>
                <option value="auto_response"><?php echo h(ma('reply_cls_auto')); ?></option>
                <option value="delivery_failure"><?php echo h(ma('reply_cls_bounce')); ?></option>
                <option value="unsubscribe_request"><?php echo h(ma('reply_cls_unsub')); ?></option>
                <option value="uncertain"><?php echo h(ma('reply_cls_uncertain')); ?></option>
            </select>
            <button type="button" class="mo-btn secondary small" id="moReplySyncNow"><?php echo h(ma('reply_sync_now')); ?></button>
        </div>
        <p class="mo-step-note" id="moReplySyncNote"></p>
        <div id="moReplyList"></div>
    </div>
</section>

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

<?php if ($crmReads): ?>
<div id="moDrawer" class="mo-drawer mo-hidden" role="dialog" aria-label="Prospect detail"></div>
<div id="moModal" class="mo-hidden"></div>
<script src="<?= appAssetUrl('js/marketing-crm.js') ?>"></script>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var CSRF = <?php echo json_encode($csrfToken); ?>;
    var API = 'api/marketing-admin.php';
    var CRM_READS = <?php echo json_encode($crmReads); ?>;
    var STR = {
        loadFailed: <?php echo json_encode(ma('load_failed')); ?>,
        sheetDown: <?php echo json_encode(ma('sheet_unavailable')); ?>,
        sheetErrs: {
            auth: <?php echo json_encode(ma('sheet_err_auth')); ?>,
            forbidden: <?php echo json_encode(ma('sheet_err_forbidden')); ?>,
            not_found: <?php echo json_encode(ma('sheet_err_not_found')); ?>,
            headers: <?php echo json_encode(ma('sheet_err_headers')); ?>,
            api: <?php echo json_encode(ma('sheet_err_api')); ?>,
            db: <?php echo json_encode(ma('sheet_err_db')); ?>,
            unknown: <?php echo json_encode(ma('sheet_err_unknown')); ?>
        },
        noProspects: <?php echo json_encode(ma('no_prospects')); ?>,
        noCampaigns: <?php echo json_encode(ma('no_campaigns')); ?>,
        noFollowups: <?php echo json_encode(ma('no_followups')); ?>,
        noTemplates: <?php echo json_encode(ma('no_templates')); ?>,
        noSends: <?php echo json_encode(ma('no_sends')); ?>,
        confirmSend: <?php echo json_encode(ma('confirm_send')); ?>,
        working: <?php echo json_encode(ma('working')); ?>,
        sendingDisabled: <?php echo json_encode(ma('sending_disabled_banner')); ?>,
        sendingAllowlist: <?php echo json_encode(ma('sending_allowlist_banner')); ?>,
        sendingUnrestricted: <?php echo json_encode(ma('sending_unrestricted_banner')); ?>,
        approveOnly: <?php echo json_encode(ma('approve_only')); ?>,
        approveAndSend: <?php echo json_encode(ma('approve_and_send')); ?>,
        approvedNotSent: <?php echo json_encode(ma('approved_not_sent')); ?>,
        confirmApproveOnly: <?php echo json_encode(ma('confirm_approve_only')); ?>,
        confirmSendTest: <?php echo json_encode(ma('confirm_send_test')); ?>,
        sendResult: <?php echo json_encode(ma('send_result')); ?>,
        sendResultExtra: <?php echo json_encode(ma('send_result_extra')); ?>,
        thread: {
            resolved: <?php echo json_encode(ma('thread_resolved')); ?>,
            unresolved: <?php echo json_encode(ma('thread_new')); ?>,
            ambiguous: <?php echo json_encode(ma('thread_ambiguous')); ?>,
            unverifiable: <?php echo json_encode(ma('thread_unverifiable')); ?>
        },
        threadSubjectLocked: <?php echo json_encode(ma('thread_subject_locked')); ?>,
        uncertainNote: <?php echo json_encode(ma('uncertain_note')); ?>,
        markNotSent: <?php echo json_encode(ma('mark_not_sent')); ?>,
        markSent: <?php echo json_encode(ma('mark_sent')); ?>,
        confirmMarkNotSent: <?php echo json_encode(ma('confirm_mark_not_sent')); ?>,
        confirmMarkSent: <?php echo json_encode(ma('confirm_mark_sent')); ?>,
        sheetPending: <?php echo json_encode(ma('sheet_sync_pending')); ?>,
        sheetFailed: <?php echo json_encode(ma('sheet_sync_failed')); ?>,
        sheetSyncResult: <?php echo json_encode(ma('sheet_sync_result')); ?>,
        reconcileResult: <?php echo json_encode(ma('reconcile_result')); ?>,
        notEligibleFollowup: <?php echo json_encode(ma('not_eligible_followup')); ?>,
        cancelled: <?php echo json_encode(ma('cancelled')); ?>,
        bucketLabels: {
            not_contacted: <?php echo json_encode(ma('bucket_not_contacted')); ?>,
            awaiting_response: <?php echo json_encode(ma('bucket_awaiting_response')); ?>,
            due_today: <?php echo json_encode(ma('bucket_due_today')); ?>,
            overdue: <?php echo json_encode(ma('bucket_overdue')); ?>,
            upcoming: <?php echo json_encode(ma('bucket_upcoming')); ?>,
            completed: <?php echo json_encode(ma('bucket_completed')); ?>,
            excluded: <?php echo json_encode(ma('bucket_excluded')); ?>
        },
        dash: {
            period: <?php echo json_encode(ma('dash_period')); ?>,
            rangeNote: <?php echo json_encode(ma('dash_range_note')); ?>,
            vsPrior: <?php echo json_encode(ma('dash_vs_prior')); ?>,
            kProspects: <?php echo json_encode(ma('dash_k_prospects')); ?>,
            kContactable: <?php echo json_encode(ma('dash_k_contactable')); ?>,
            kSent: <?php echo json_encode(ma('dash_k_sent')); ?>,
            kReplies: <?php echo json_encode(ma('dash_k_replies')); ?>,
            kRate: <?php echo json_encode(ma('dash_k_rate')); ?>,
            kAwaiting: <?php echo json_encode(ma('dash_k_awaiting')); ?>,
            kDue: <?php echo json_encode(ma('dash_k_due')); ?>,
            kRecorded: <?php echo json_encode(ma('dash_k_recorded')); ?>,
            tProspects: <?php echo json_encode(ma('dash_t_prospects')); ?>,
            tContactable: <?php echo json_encode(ma('dash_t_contactable')); ?>,
            tSent: <?php echo json_encode(ma('dash_t_sent')); ?>,
            tReplies: <?php echo json_encode(ma('dash_t_replies')); ?>,
            tRate: <?php echo json_encode(ma('dash_t_rate')); ?>,
            tAwaiting: <?php echo json_encode(ma('dash_t_awaiting')); ?>,
            tDue: <?php echo json_encode(ma('dash_t_due')); ?>,
            tRecorded: <?php echo json_encode(ma('dash_t_recorded')); ?>,
            chartEmpty: <?php echo json_encode(ma('dash_chart_empty')); ?>,
            chartNote: <?php echo json_encode(ma('dash_chart_note')); ?>,
            legSent: <?php echo json_encode(ma('dash_leg_sent')); ?>,
            legFailed: <?php echo json_encode(ma('dash_leg_failed')); ?>,
            legUncertain: <?php echo json_encode(ma('dash_leg_uncertain')); ?>,
            legAllTime: <?php echo json_encode(ma('dash_leg_alltime')); ?>,
            pipeNote: <?php echo json_encode(ma('dash_pipe_note')); ?>,
            campEmpty: <?php echo json_encode(ma('dash_camp_empty')); ?>,
            campRepliesNa: <?php echo json_encode(ma('dash_camp_replies_na')); ?>,
            attnNone: <?php echo json_encode(ma('dash_attn_none')); ?>,
            aOverdue: <?php echo json_encode(ma('dash_a_overdue')); ?>,
            aDue: <?php echo json_encode(ma('dash_a_due')); ?>,
            aAwaiting: <?php echo json_encode(ma('dash_a_awaiting')); ?>,
            aUpcoming: <?php echo json_encode(ma('dash_a_upcoming')); ?>,
            aReview: <?php echo json_encode(ma('dash_a_review')); ?>,
            aMissing: <?php echo json_encode(ma('dash_a_missing')); ?>,
            aDnc: <?php echo json_encode(ma('dash_a_dnc')); ?>,
            aDup: <?php echo json_encode(ma('dash_a_dup')); ?>,
            aRecorded: <?php echo json_encode(ma('dash_a_recorded')); ?>,
            aDrafts: <?php echo json_encode(ma('dash_a_drafts')); ?>,
            aReady: <?php echo json_encode(ma('dash_a_ready')); ?>,
            aApproved: <?php echo json_encode(ma('dash_a_approved')); ?>,
            aProblems: <?php echo json_encode(ma('dash_a_problems')); ?>,
            segNote: <?php echo json_encode(ma('dash_seg_note')); ?>,
            defs: <?php echo json_encode(ma('dash_defs')); ?>,
            chartTitle: <?php echo json_encode(ma('dash_chart_title')); ?>,
            allCampaigns: <?php echo json_encode(ma('dash_all_campaigns')); ?>,
            allOrgtypes: <?php echo json_encode(ma('dash_all_orgtypes')); ?>,
            allLocations: <?php echo json_encode(ma('dash_all_locations')); ?>,
            campName: <?php echo json_encode(ma('dash_camp_name')); ?>,
            campKind: <?php echo json_encode(ma('dash_camp_kind')); ?>,
            campStatus: <?php echo json_encode(ma('dash_camp_status')); ?>,
            campRecips: <?php echo json_encode(ma('dash_camp_recips')); ?>,
            campSent: <?php echo json_encode(ma('dash_camp_sent')); ?>,
            campIssues: <?php echo json_encode(ma('dash_camp_issues')); ?>,
            campLast: <?php echo json_encode(ma('dash_camp_last')); ?>,
            campOpen: <?php echo json_encode(ma('dash_camp_open')); ?>,
            segOrgType: <?php echo json_encode(ma('dash_seg_orgtype')); ?>,
            segStatus: <?php echo json_encode(ma('dash_seg_status')); ?>,
            segPriority: <?php echo json_encode(ma('dash_seg_priority')); ?>,
            segLocation: <?php echo json_encode(ma('dash_seg_location')); ?>,
            segSource: <?php echo json_encode(ma('dash_seg_source')); ?>,
            legReplies: <?php echo json_encode(ma('dash_leg_replies')); ?>,
            aReplies: <?php echo json_encode(ma('dash_a_replies')); ?>,
            aBounces: <?php echo json_encode(ma('dash_a_bounces')); ?>,
            aUnsub: <?php echo json_encode(ma('dash_a_unsub')); ?>,
            aAuto: <?php echo json_encode(ma('dash_a_auto')); ?>,
            syncOk: <?php echo json_encode(ma('reply_sync_ok')); ?>,
            syncErr: <?php echo json_encode(ma('reply_sync_err')); ?>,
            syncAuth: <?php echo json_encode(ma('reply_sync_auth')); ?>,
            rateNA: <?php echo json_encode(ma('dash_rate_na')); ?>,
            campReplies: <?php echo json_encode(ma('dash_camp_replies')); ?>,
            campVerified: <?php echo json_encode(ma('dash_camp_verified')); ?>,
            campCohort: <?php echo json_encode(ma('dash_camp_cohort')); ?>,
            aBperm: <?php echo json_encode(ma('dash_a_bperm')); ?>,
            aBtemp: <?php echo json_encode(ma('dash_a_btemp')); ?>,
            aSuppressed: <?php echo json_encode(ma('dash_a_suppressed')); ?>
        },
        reply: {
            empty: <?php echo json_encode(ma('reply_empty')); ?>,
            openGmail: <?php echo json_encode(ma('reply_open_gmail')); ?>,
            markReviewed: <?php echo json_encode(ma('reply_mark_reviewed')); ?>,
            dismiss: <?php echo json_encode(ma('reply_dismiss')); ?>,
            ambiguous: <?php echo json_encode(ma('reply_ambiguous')); ?>,
            unmatched: <?php echo json_encode(ma('reply_unmatched')); ?>,
            viaThread: <?php echo json_encode(ma('reply_via_thread')); ?>,
            viaRfc: <?php echo json_encode(ma('reply_via_rfc')); ?>,
            viaEmail: <?php echo json_encode(ma('reply_via_email')); ?>,
            clsHuman: <?php echo json_encode(ma('reply_cls_human')); ?>,
            clsAuto: <?php echo json_encode(ma('reply_cls_auto')); ?>,
            clsBounce: <?php echo json_encode(ma('reply_cls_bounce')); ?>,
            clsUnsub: <?php echo json_encode(ma('reply_cls_unsub')); ?>,
            clsUncertain: <?php echo json_encode(ma('reply_cls_uncertain')); ?>,
            clsBperm: <?php echo json_encode(ma('reply_cls_bperm')); ?>,
            clsBtemp: <?php echo json_encode(ma('reply_cls_btemp')); ?>,
            clsBamb: <?php echo json_encode(ma('reply_cls_bamb')); ?>,
            suppressedBadge: <?php echo json_encode(ma('reply_suppressed_badge')); ?>,
            heldBadge: <?php echo json_encode(ma('reply_held_badge')); ?>,
            unsubReview: <?php echo json_encode(ma('reply_unsub_review')); ?>,
            reviewed: <?php echo json_encode(ma('reply_reviewed_ok')); ?>,
            syncDone: <?php echo json_encode(ma('reply_sync_done')); ?>,
            syncRunning: <?php echo json_encode(ma('reply_sync_running')); ?>,
            colDate: <?php echo json_encode(ma('reply_col_date')); ?>,
            colFrom: <?php echo json_encode(ma('reply_col_from')); ?>,
            colSubject: <?php echo json_encode(ma('reply_col_subject')); ?>,
            colClass: <?php echo json_encode(ma('reply_col_class')); ?>,
            colCampaign: <?php echo json_encode(ma('reply_col_campaign')); ?>,
            colStatus: <?php echo json_encode(ma('reply_col_status')); ?>,
            colActions: <?php echo json_encode(ma('reply_col_actions')); ?>
        }
    };
    var state = { prospectPage: 1, currentCampaign: null, templates: [] };
    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function sheetErrorText(code) { return (code && STR.sheetErrs[code]) || STR.sheetDown; }
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
    // Sending is controlled separately from this interface. The banner
    // always states what an approved campaign can actually deliver.
    function renderSending(sending) {
        if (!sending) return;
        state.sending = sending;
        var el = document.getElementById('moSendingBanner');
        var text = sending.mode === 'unrestricted' ? STR.sendingUnrestricted
            : sending.mode === 'allowlist' ? STR.sendingAllowlist.replace('{n}', sending.allowlist_count)
            : STR.sendingDisabled;
        el.textContent = text;
        el.className = 'mo-sending ' + sending.mode;
    }
    function sendSummary(d) {
        var msg = STR.sendResult.replace('{sent}', d.sent).replace('{skipped}', d.skipped).replace('{failed}', d.failed);
        if (d.uncertain || d.blocked) msg += ' ' + STR.sendResultExtra.replace('{uncertain}', d.uncertain || 0).replace('{blocked}', d.blocked || 0);
        return msg;
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
        else if (name === 'prospects') { if (CRM_READS && window.MOCrm) MOCrm.load(); else loadProspects(); }
        else if (name === 'campaigns') { if (CRM_READS && window.MOCrm) MOCrm.loadCampaigns(); else loadCampaigns(); }
        else if (name === 'followups') loadFollowups('');
        else if (name === 'replies') loadReplies();
        else if (name === 'templates') loadTemplates();
        else if (name === 'history') loadHistory();
    }

    // ---------- overview / performance dashboard ----------
    var dashFilters = { days: '30', from: '', to: '', campaign: '', kind: '', org_type: '', location: '' };
    var dashWired = false;

    function dashParams() {
        var p = { days: dashFilters.days, campaign_id: dashFilters.campaign, kind: dashFilters.kind,
            org_type: dashFilters.org_type, location: dashFilters.location };
        if (dashFilters.days === 'custom') { p.from = dashFilters.from; p.to = dashFilters.to; }
        return p;
    }
    function dashWire() {
        if (dashWired) return; dashWired = true;
        var on = function () { loadOverview(); };
        $('moDashDays').addEventListener('change', function () {
            dashFilters.days = this.value;
            $('moDashCustom').classList.toggle('mo-hidden', this.value !== 'custom');
            if (this.value === 'custom') { setCustomDefaults(); return; }
            on();
        });
        $('moDashFrom').addEventListener('change', function () { dashFilters.from = this.value; on(); });
        $('moDashTo').addEventListener('change', function () { dashFilters.to = this.value; on(); });
        $('moDashCampaign').addEventListener('change', function () { dashFilters.campaign = this.value; on(); });
        $('moDashKind').addEventListener('change', function () { dashFilters.kind = this.value; on(); });
        $('moDashOrgType').addEventListener('change', function () { dashFilters.org_type = this.value; on(); });
        $('moDashLocation').addEventListener('change', function () { dashFilters.location = this.value; on(); });
        $('moDashReset').addEventListener('click', function () {
            dashFilters = { days: '30', from: '', to: '', campaign: '', kind: '', org_type: '', location: '' };
            $('moDashDays').value = '30'; $('moDashCustom').classList.add('mo-hidden');
            ['moDashCampaign','moDashKind','moDashOrgType','moDashLocation'].forEach(function (id) { $(id).value = ''; });
            on();
        });
    }
    function setCustomDefaults() {
        var to = new Date(), from = new Date(Date.now() - 29 * 864e5);
        var iso = function (d) { return d.toISOString().slice(0, 10); };
        if (!$('moDashFrom').value) $('moDashFrom').value = iso(from);
        if (!$('moDashTo').value) $('moDashTo').value = iso(to);
        dashFilters.from = $('moDashFrom').value; dashFilters.to = $('moDashTo').value;
        if (dashFilters.from && dashFilters.to) loadOverview();
    }
    function dashGoTab(tab) {
        var t = document.querySelector('.mo-tab[data-tab="' + tab + '"]');
        if (t) t.click();
    }
    function dashProspects(f) {
        if (window.MOCrm && MOCrm.openProspects) MOCrm.openProspects(f || {});
        dashGoTab('prospects');
    }
    function dashCampaigns() { dashGoTab('campaigns'); }
    function dashHistory() { dashGoTab('history'); }

    function dashKpi(label, value, note, tip, onClick) {
        return '<div class="pa-metric mo-kpi' + (onClick ? '' : ' na') + '"' +
            (tip ? ' title="' + esc(tip) + '"' : '') + (onClick ? ' data-act="' + esc(onClick) + '"' : '') + '>' +
            '<div class="pa-metric-value">' + esc(value) + '</div>' +
            '<div class="pa-metric-label">' + esc(label) + '</div>' +
            (note ? '<div class="pa-metric-note">' + esc(note) + '</div>' : '') + '</div>';
    }

    // Stacked daily/weekly bars drawn as inline SVG (no chart dependency).
    // replies: {date: n} human replies per day, drawn as a marker line.
    function dashChartSvg(series, from, to, replies) {
        var days = Math.round((new Date(to) - new Date(from)) / 864e5) + 1;
        var weekly = days > 31;
        var buckets = {}; var order = [];
        var t = new Date(from + 'T00:00:00Z').getTime();
        var end = new Date(to + 'T00:00:00Z').getTime();
        for (var d = t; d <= end; d += 864e5) {
            var dt = new Date(d);
            var key = weekly
                ? new Date(dt.getTime() - ((dt.getUTCDay() + 6) % 7) * 864e5).toISOString().slice(0, 10)
                : dt.toISOString().slice(0, 10);
            if (!buckets[key]) { buckets[key] = { sent: 0, failed: 0, uncertain: 0 }; order.push(key); }
        }
        Object.keys(series).forEach(function (day) {
            var wk = weekly ? (function (ds) {
                var x = new Date(ds + 'T00:00:00Z');
                return new Date(x.getTime() - ((x.getUTCDay() + 6) % 7) * 864e5).toISOString().slice(0, 10);
            })(day) : day;
            var b = buckets[wk]; if (!b) return;
            ['sent', 'failed', 'uncertain'].forEach(function (s) { b[s] += (series[day][s] || 0); });
        });
        var replyPts = [];
        if (replies) {
            Object.keys(replies).forEach(function (day) {
                var wk = weekly ? (function (ds) {
                    var x = new Date(ds + 'T00:00:00Z');
                    return new Date(x.getTime() - ((x.getUTCDay() + 6) % 7) * 864e5).toISOString().slice(0, 10);
                })(day) : day;
                var idx = order.indexOf(wk);
                if (idx >= 0) replyPts.push({ i: idx, n: replies[day] });
            });
        }
        var host = $('moDashChart');
        var W = Math.max(320, host.clientWidth || 720), H = 190;
        var m = { l: 36, r: 8, t: 10, b: 24 };
        var iw = W - m.l - m.r, ih = H - m.t - m.b;
        var max = 1;
        order.forEach(function (k) { var s = buckets[k]; max = Math.max(max, s.sent + s.failed + s.uncertain); });
        var niceMax = Math.max(1, Math.ceil(max / 4) * 4);
        var bw = iw / order.length;
        var barW = Math.max(2, Math.min(weekly ? 22 : 14, bw * 0.62));
        var svg = '<svg class="mo-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(STR.dash.chartTitle) + '">';
        for (var g = 0; g <= 4; g++) {
            var y = m.t + ih - (ih * g / 4), val = Math.round(niceMax * g / 4);
            svg += '<line class="grid" x1="' + m.l + '" y1="' + y + '" x2="' + (W - m.r) + '" y2="' + y + '"/>' +
                '<text class="axis" x="' + (m.l - 6) + '" y="' + (y + 3) + '" text-anchor="end">' + val + '</text>';
        }
        order.forEach(function (k, i) {
            var s = buckets[k], x = m.l + i * bw + (bw - barW) / 2, yBase = m.t + ih;
            var total = s.sent + s.failed + s.uncertain;
            if (total > 0) {
                var hh = total / niceMax * ih;
                var y = yBase - hh, acc = 0;
                [['sent', 'bar-sent'], ['failed', 'bar-failed'], ['uncertain', 'bar-uncertain']].forEach(function (seg) {
                    var h = s[seg[0]] / niceMax * ih;
                    if (h <= 0) return;
                    svg += '<rect class="' + seg[1] + '" x="' + x.toFixed(1) + '" y="' + (yBase - acc - h).toFixed(1) +
                        '" width="' + barW.toFixed(1) + '" height="' + h.toFixed(1) + '" rx="1">' +
                        '<title>' + esc(k + ': ' + seg[0] + ' ' + s[seg[0]]) + '</title></rect>';
                    acc += h;
                });
            }
            if (i % Math.ceil(order.length / 8) === 0 || i === order.length - 1) {
                svg += '<text class="axis" x="' + (m.l + i * bw + bw / 2).toFixed(1) + '" y="' + (H - 8) +
                    '" text-anchor="middle">' + esc(k.slice(5)) + '</text>';
            }
        });
        // Human replies: diamond markers at bucket centers near the top.
        replyPts.forEach(function (p) {
            var x = m.l + p.i * bw + bw / 2, y = m.t + 6;
            svg += '<rect class="reply-dot" x="' + (x - 3).toFixed(1) + '" y="' + y.toFixed(1) +
                '" width="6" height="6" transform="rotate(45 ' + x.toFixed(1) + ' ' + (y + 3).toFixed(1) + ')">' +
                '<title>' + esc(STR.dash.legReplies + ': ' + p.n) + '</title></rect>';
        });
        return svg + '</svg>';
    }

    function dashHbars(rows) {
        // rows: [{label, n, tip, act}]
        var max = 1; rows.forEach(function (r) { max = Math.max(max, r.n); });
        return rows.map(function (r) {
            var pct = r.n > 0 ? Math.max(2, Math.round(r.n / max * 100)) : 0;
            return '<div class="mo-hbar"' + (r.act ? ' data-act="' + esc(r.act) + '"' : '') +
                (r.tip ? ' title="' + esc(r.tip) + '"' : '') + '>' +
                '<span class="mo-hbar-label">' + esc(r.label) + '</span>' +
                '<span class="mo-hbar-track"><span class="mo-hbar-fill ' + (r.cls || '') +
                '" style="width:' + pct + '%"></span></span>' +
                '<span class="mo-hbar-n">' + r.n + '</span></div>';
        }).join('');
    }

    // Sheet-mode fallback: the dashboard requires the CRM; show the
    // legacy aggregate view instead.
    function legacyOverview() {
        apiGet('overview').then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            var o = d.overview, html = '';
            renderSending(o.sending);
            var metrics = [
                ['Prospects', o.prospects_total], ['Eligible', o.prospects_eligible],
                ['Eligible: initial', o.eligible_initial], ['Eligible: follow-up', o.eligible_followup],
                ['Due today', o.followup_buckets.due_today], ['Overdue', o.followup_buckets.overdue],
                ['Upcoming', o.followup_buckets.upcoming], ['Awaiting response', o.followup_buckets.awaiting_response],
                ['Campaigns', Object.keys(o.campaigns || {}).reduce(function (a, k) { return a + o.campaigns[k]; }, 0)],
                ['Sends (30d)', o.sends_30d], ['Suppressions', o.suppressions]
            ];
            metrics.forEach(function (m) {
                html += '<div class="pa-metric"><div class="pa-metric-value">' + esc(m[1]) + '</div><div class="pa-metric-label">' + esc(m[0]) + '</div></div>';
            });
            $('moDashKpis').innerHTML = html;
            $('moDashNote').textContent = o.sheet_error ? sheetErrorText(o.sheet_error) : '';
            var buckets = '';
            ['due_today','overdue','upcoming','awaiting_response','not_contacted','completed','excluded'].forEach(function (b) {
                buckets += '<span class="mo-pill ' + b + '">' + esc(b.replace(/_/g, ' ')) + ': ' + (o.followup_buckets[b] || 0) + '</span> ';
            });
            $('moDashPipe').innerHTML = buckets;
            $('moDashPipeNote').textContent = '';
            var fl = '';
            ['missing_contact','do_not_contact','duplicate_org'].forEach(function (f) {
                fl += '<span class="mo-flag ' + f + '">' + esc(f.replace(/_/g, ' ')) + ': ' + (o.flags[f] || 0) + '</span> ';
            });
            $('moDashAttn').innerHTML = fl || '—';
            $('moDashChart').innerHTML = ''; $('moDashLegend').innerHTML = ''; $('moDashChartNote').innerHTML = '';
            $('moDashCamps').innerHTML = ''; $('moDashSegs').innerHTML = '';
            $('moDashSegNote').innerHTML = ''; $('moDashDefs').innerHTML = '';
            $('moDashFilters').classList.add('mo-hidden');
        }).catch(function () { showErr(STR.loadFailed); });
    }

    function loadOverview() {
        if (!CRM_READS) { legacyOverview(); return; }
        dashWire();
        var host = $('moDashKpis');
        host.innerHTML = '<div class="pa-metric"><div class="pa-metric-value">…</div></div>';
        apiGet('dashboard', dashParams()).then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            var db = d.dashboard, p = db.prospect, s = db.sends;
            renderSending(db.sending || null);

            // Range note + filter option sync (only when options change).
            var r = db.range;
            $('moDashNote').textContent = STR.dash.rangeNote
                .replace('{from}', r.from).replace('{to}', r.to);
            var fill = function (id, opts, blank) {
                var el = $(id), cur = el.value, html = '<option value="">' + esc(blank) + '</option>';
                opts.forEach(function (o) { html += '<option value="' + esc(o.v) + '">' + esc(o.l) + '</option>'; });
                el.innerHTML = html; el.value = opts.some(function (o) { return o.v === cur; }) ? cur : '';
            };
            fill('moDashCampaign', (db.campaign_options || []).map(function (c) {
                return { v: c.id, l: c.name + ' (' + c.kind + ')' };
            }), STR.dash.allCampaigns);
            $('moDashCampaign').value = dashFilters.campaign;
            fill('moDashOrgType', (db.filter_options.org_types || []).map(function (t) { return { v: t, l: t }; }), STR.dash.allOrgtypes);
            $('moDashOrgType').value = dashFilters.org_type;
            fill('moDashLocation', (db.filter_options.locations || []).map(function (t) { return { v: t, l: t }; }), STR.dash.allLocations);
            $('moDashLocation').value = dashFilters.location;
            $('moDashKind').value = dashFilters.kind;
            $('moDashDays').value = dashFilters.days;
            $('moDashCustom').classList.toggle('mo-hidden', dashFilters.days !== 'custom');
            if (dashFilters.days === 'custom') { $('moDashFrom').value = dashFilters.from; $('moDashTo').value = dashFilters.to; }
            // Drop stale filter values that no longer exist in the options.
            [['moDashCampaign','campaign'],['moDashOrgType','org_type'],['moDashLocation','location'],['moDashKind','kind']]
                .forEach(function (pr) {
                    var el = $(pr[0]);
                    if (el && el.value !== dashFilters[pr[1]]) dashFilters[pr[1]] = el.value;
                });

            // ---- KPI cards ----
            var b = p.followup_buckets, delta = s.period - s.prior;
            var deltaTxt = (delta === 0 ? '±0' : (delta > 0 ? '+' : '') + delta) + ' ' + STR.dash.vsPrior;
            var rep = db.replies || { counts: {}, period_human: 0, prior_human: 0, new_review: 0,
                series: {}, sync: {} };
            var rdelta = rep.period_human - (rep.prior_human || 0);
            var rdeltaTxt = (rdelta === 0 ? '±0' : (rdelta > 0 ? '+' : '') + rdelta) + ' ' + STR.dash.vsPrior;
            // Response rate = human replies in period / verified sends in period.
            // Shown only when the send denominator is non-zero; otherwise NA.
            var rateTxt = s.period > 0
                ? ((rep.period_human / s.period * 100).toFixed(1) + '%')
                : STR.dash.rateNA;
            var kpis = [
                dashKpi(STR.dash.kProspects, p.prospects_total, STR.dash.tProspects, null, 'prospects'),
                dashKpi(STR.dash.kContactable, p.prospects_eligible, STR.dash.tContactable, null,
                    'prospects:{"eligible":"contactable"}'),
                dashKpi(STR.dash.kSent, s.period, deltaTxt, STR.dash.tSent,
                    'sent'),
                dashKpi(STR.dash.kReplies, rep.period_human, rdeltaTxt, STR.dash.tReplies,
                    'replies'),
                dashKpi(STR.dash.kRate, rateTxt, null, STR.dash.tRate, null),
                dashKpi(STR.dash.kAwaiting, b.awaiting_response, null, STR.dash.tAwaiting,
                    'bucket:awaiting_response'),
                dashKpi(STR.dash.kDue, b.due_today + b.overdue, null, STR.dash.tDue,
                    'bucket:overdue'),
                dashKpi(STR.dash.kRecorded, p.attention.recorded_responses, null, STR.dash.tRecorded,
                    'prospects')
            ];
            host.innerHTML = kpis.join('');
            host.querySelectorAll('.mo-kpi[data-act]').forEach(function (el) {
                el.addEventListener('click', function () {
                    var a = el.dataset.act, i = a.indexOf(':');
                    var kind = i < 0 ? a : a.slice(0, i), arg = i < 0 ? '' : a.slice(i + 1);
                    if (kind === 'prospects') {
                        dashProspects(arg ? JSON.parse(arg) : {});
                    } else if (kind === 'bucket') dashProspects({ bucket: arg });
                    else if (kind === 'sent') dashHistory();
                    else if (kind === 'replies') dashGoTab('replies');
                });
            });

            // ---- activity chart ----
            var anySends = Object.keys(s.series).length > 0 || Object.keys(rep.series || {}).length > 0;
            $('moDashChart').innerHTML = anySends ? dashChartSvg(s.series, r.from, r.to, rep.series)
                : '<div class="mo-empty-note">' + esc(STR.dash.chartEmpty) + '</div>';
            $('moDashLegend').innerHTML =
                '<span><i style="background:#1e40af"></i>' + esc(STR.dash.legSent) + ' (' + s.ledger_total + ' ' + esc(STR.dash.legAllTime || 'all time') + ')</span>' +
                '<span><i style="background:#ef4444"></i>' + esc(STR.dash.legFailed) + '</span>' +
                '<span><i style="background:#f59e0b"></i>' + esc(STR.dash.legUncertain) + ' (' + s.ledger_problems + ')</span>' +
                '<span><i class="reply-dot" style="display:inline-block;width:9px;height:9px;border-radius:2px;transform:rotate(45deg);background:#059669"></i>' + esc(STR.dash.legReplies) + '</span>';
            // Reply-sync health line.
            var syncNote = '';
            var sy = rep.sync || {};
            if (sy.last_error === 'gmail_auth_required') syncNote = ' ⚠ ' + STR.dash.syncAuth;
            else if (sy.last_error) syncNote = ' ⚠ ' + STR.dash.syncErr + ': ' + esc(sy.last_error);
            else if (sy.last_success) syncNote = ' · ' + STR.dash.syncOk + ' ' + esc(sy.last_success.slice(0, 16));
            $('moDashChartNote').textContent = STR.dash.chartNote + syncNote;

            // ---- pipeline (mutually exclusive buckets) ----
            var bk = STR.bucketLabels || {};
            var pipeRows = [
                { k: 'not_contacted', label: bk.not_contacted, cls: 'muted', act: 'bucket:not_contacted' },
                { k: 'awaiting_response', label: bk.awaiting_response, cls: 'warn', act: 'bucket:awaiting_response' },
                { k: 'due_today', label: bk.due_today, cls: 'bad', act: 'bucket:due_today' },
                { k: 'overdue', label: bk.overdue, cls: 'bad', act: 'bucket:overdue' },
                { k: 'upcoming', label: bk.upcoming, cls: 'alt', act: 'bucket:upcoming' },
                { k: 'completed', label: bk.completed, cls: 'good', act: 'bucket:completed' },
                { k: 'excluded', label: bk.excluded, cls: 'muted', act: 'bucket:excluded' }
            ].map(function (x) { return { label: x.label, n: b[x.k] || 0, cls: x.cls, act: 'bucket:' + x.k }; });
            $('moDashPipe').innerHTML = dashHbars(pipeRows);
            $('moDashPipe').querySelectorAll('.mo-hbar[data-act]').forEach(function (el) {
                el.addEventListener('click', function () {
                    dashProspects({ bucket: el.dataset.act.split(':')[1] });
                });
            });
            $('moDashPipeNote').textContent = STR.dash.pipeNote;

            // ---- campaign performance ----
            var camps = db.campaigns || [];
            if (!camps.length) {
                $('moDashCamps').innerHTML = '<div class="mo-empty-note">' + esc(STR.dash.campEmpty) + '</div>';
            } else {
                var ch = '<table class="mo-table"><thead><tr><th>' + esc(STR.dash.campName) + '</th><th>' +
                    esc(STR.dash.campKind) + '</th><th>' + esc(STR.dash.campStatus) + '</th><th>' +
                    esc(STR.dash.campRecips) + '</th><th>' + esc(STR.dash.campSent) + '</th><th>' +
                    esc(STR.dash.campVerified) + '</th><th>' + esc(STR.dash.campCohort) + '</th><th>' +
                    esc(STR.dash.campReplies) + '</th><th>' +
                    esc(STR.dash.campIssues) + '</th><th>' + esc(STR.dash.campLast) + '</th><th></th></tr></thead><tbody>';
                camps.forEach(function (c) {
                    var last = c.last_reply_at || c.last_activity_at || c.last_sent_at || '';
                    var repCell = c.recipients === 0 && c.sent === 0 ? '—'
                        : (c.replies_human || 0) + (c.replies_new > 0 ? ' <span class="mo-pill warn">' + c.replies_new + '</span>' : '');
                    ch += '<tr><td><strong>' + esc(c.name) + '</strong></td>' +
                        '<td>' + esc(c.kind) + '</td>' +
                        '<td><span class="mo-pill ' + esc(c.status) + '">' + esc(c.status) + '</span></td>' +
                        '<td>' + (c.recipients || 0) + '</td>' +
                        '<td>' + (c.sent || 0) + '</td>' +
                        '<td>' + (c.verified_sends || 0) + '</td>' +
                        '<td>' + (c.cohort_rate === null || c.cohort_rate === undefined ? '—'
                            : c.cohort_rate + '% (' + c.cohort_replies + '/' + c.verified_sends + ')') + '</td>' +
                        '<td>' + repCell + '</td>' +
                        '<td>' + ((c.problems || 0) > 0 ? '<span class="mo-pill failed">' + c.problems + '</span>' : '0') + '</td>' +
                        '<td class="mo-sub">' + esc(last ? last.slice(0, 10) : '—') + '</td>' +
                        '<td><button type="button" class="mo-btn secondary small" data-camp="' + esc(c.id) + '">' +
                        esc(STR.dash.campOpen) + '</button></td></tr>';
                });
                $('moDashCamps').innerHTML = ch + '</tbody></table>';
                $('moDashCamps').querySelectorAll('[data-camp]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        dashCampaigns();
                        if (window.MOCrm && MOCrm.openCampaign) setTimeout(function () { MOCrm.openCampaign(btn.dataset.camp); }, 60);
                    });
                });
            }

            // ---- needs attention ----
            var cs = db.campaign_status || {}, att = p.attention;
            var attnRows = [
                { label: STR.dash.aOverdue, n: b.overdue || 0, act: 'bucket:overdue' },
                { label: STR.dash.aDue, n: b.due_today || 0, act: 'bucket:due_today' },
                { label: STR.dash.aAwaiting, n: b.awaiting_response || 0, act: 'bucket:awaiting_response' },
                { label: STR.dash.aUpcoming, n: b.upcoming || 0, act: 'bucket:upcoming' },
                { label: STR.dash.aReview, n: att.needs_review, act: 'review' },
                { label: STR.dash.aMissing, n: att.missing_contact, act: 'flag:missing_contact' },
                { label: STR.dash.aDnc, n: att.do_not_contact, act: 'flag:do_not_contact' },
                { label: STR.dash.aDup, n: att.duplicate_org, act: 'flag:duplicate_org' },
                { label: STR.dash.aRecorded, n: att.recorded_responses, act: 'prospects' },
                { label: STR.dash.aDrafts, n: (cs.draft || 0), act: 'campaigns' },
                { label: STR.dash.aReady, n: (cs.ready || 0), act: 'campaigns' },
                { label: STR.dash.aApproved, n: (cs.approved || 0), act: 'campaigns' },
                { label: STR.dash.aProblems, n: s.ledger_problems, act: 'history' },
                { label: STR.dash.aReplies, n: rep.new_review || 0, act: 'replies' },
                { label: STR.dash.aBperm, n: (rep.bounce_kinds || {}).permanent || 0, act: 'replies:bounce' },
                { label: STR.dash.aBtemp, n: (rep.bounce_kinds || {}).temporary || 0, act: 'replies:bounce' },
                { label: STR.dash.aBounces, n: (rep.bounce_kinds || {}).ambiguous || 0, act: 'replies:bounce' },
                { label: STR.dash.aUnsub, n: (rep.counts || {}).unsubscribe_request || 0, act: 'replies:unsub' },
                { label: STR.dash.aSuppressed, n: rep.suppressed_contacts || 0, act: 'prospects' },
                { label: STR.dash.aAuto, n: (rep.counts || {}).auto_response || 0, act: 'replies:auto' }
            ];
            var anyAttn = attnRows.some(function (x) { return x.n > 0; });
            $('moDashAttn').innerHTML = anyAttn ? attnRows.map(function (x) {
                return '<div class="mo-attn-row' + (x.n > 0 ? '' : ' zero') + '"' +
                    (x.n > 0 ? ' data-act="' + x.act + '"' : '') + '>' +
                    '<span class="k">' + esc(x.label) + '</span><span class="mo-attn-n">' + x.n + '</span></div>';
            }).join('') : '<div class="mo-empty-note">' + esc(STR.dash.attnNone) + '</div>';
            $('moDashAttn').querySelectorAll('.mo-attn-row[data-act]').forEach(function (el) {
                el.addEventListener('click', function () {
                    var a = el.dataset.act;
                    if (a.indexOf('bucket:') === 0) dashProspects({ bucket: a.slice(7) });
                    else if (a === 'review') dashProspects({ review: 1 });
                    else if (a.indexOf('flag:') === 0) dashProspects({ flag: a.slice(5) });
                    else if (a === 'prospects') dashProspects({});
                    else if (a === 'campaigns') dashCampaigns();
                    else if (a === 'history') dashHistory();
                    else if (a.indexOf('replies') === 0) {
                        dashGoTab('replies');
                        var rc = a.split(':')[1];
                        if (rc) setTimeout(function () {
                            var cs = $('moReplyClass'); if (cs) { cs.value = rc; loadReplies(); }
                        }, 60);
                    }
                });
            });

            // ---- segmentation (inventory; respects org_type/state filters) ----
            function segRows(map, clickKind) {
                var max = 1, keys = Object.keys(map || {});
                keys.forEach(function (k) { max = Math.max(max, map[k]); });
                if (!keys.length) return '<div class="mo-empty-note">—</div>';
                return keys.map(function (k) {
                    var pct = Math.max(2, Math.round(map[k] / max * 100));
                    var act = '';
                    if (clickKind === 'org_type') act = ' data-seg="' + esc(k) + '"';
                    else if (clickKind === 'status') act = ' data-seg-status="' + esc(k) + '"';
                    else if (clickKind === 'priority') act = ' data-seg-priority="' + esc(k) + '"';
                    return '<div class="mo-hbar"' + act + ' title="' + esc(k) + '">' +
                        '<span class="mo-hbar-label">' + esc(k || '—') + '</span>' +
                        '<span class="mo-hbar-track"><span class="mo-hbar-fill alt" style="width:' + pct + '%"></span></span>' +
                        '<span class="mo-hbar-n">' + map[k] + '</span></div>';
                }).join('');
            }
            var sg = p.segments || {};
            $('moDashSegs').innerHTML =
                '<div><div class="mo-seg-title">' + esc(STR.dash.segOrgType) + '</div>' + segRows(sg.org_type, 'org_type') + '</div>' +
                '<div><div class="mo-seg-title">' + esc(STR.dash.segStatus) + '</div>' + segRows(sg.status, 'status') + '</div>' +
                '<div><div class="mo-seg-title">' + esc(STR.dash.segPriority) + '</div>' + segRows(sg.priority, 'priority') + '</div>' +
                '<div><div class="mo-seg-title">' + esc(STR.dash.segLocation) + '</div>' + segRows(sg.location) + '</div>' +
                '<div><div class="mo-seg-title">' + esc(STR.dash.segSource) + '</div>' + segRows(sg.source) + '</div>';
            $('moDashSegs').querySelectorAll('[data-seg]').forEach(function (el) {
                el.addEventListener('click', function () { dashProspects({ org_type: el.dataset.seg }); });
            });
            $('moDashSegs').querySelectorAll('[data-seg-status]').forEach(function (el) {
                el.addEventListener('click', function () { dashProspects({ status: el.dataset.segStatus }); });
            });
            $('moDashSegs').querySelectorAll('[data-seg-priority]').forEach(function (el) {
                el.addEventListener('click', function () { dashProspects({ priority: el.dataset.segPriority }); });
            });
            $('moDashSegNote').textContent = STR.dash.segNote;
            $('moDashDefs').textContent = STR.dash.defs;
        }).catch(function () { showErr(STR.loadFailed); });
    }

    // ---------- replies (Gmail reply tracking) ----------
    var replyWired = false;
    function replyClassLabel(r) {
        var c = r.classification || r;
        if (c === 'delivery_failure' && r && r.bounce_kind === 'permanent') return STR.reply.clsBperm;
        if (c === 'delivery_failure' && r && r.bounce_kind === 'temporary') return STR.reply.clsBtemp;
        if (c === 'delivery_failure' && r && r.bounce_kind) return STR.reply.clsBamb;
        return { human: STR.reply.clsHuman, auto_response: STR.reply.clsAuto,
            delivery_failure: STR.reply.clsBounce, unsubscribe_request: STR.reply.clsUnsub,
            uncertain: STR.reply.clsUncertain }[c] || c;
    }
    function replySyncNote(sync) {
        var el = $('moReplySyncNote'); if (!el) return;
        if (!sync || !sync.initialized) { el.textContent = STR.reply.syncRunning; return; }
        var t = sync.last_success ? STR.dash.syncOk + ' ' + sync.last_success.slice(0, 16) : '';
        if (sync.last_error === 'gmail_auth_required') el.textContent = STR.dash.syncAuth;
        else if (sync.last_error) el.textContent = STR.dash.syncErr + ': ' + sync.last_error + (t ? ' · ' + t : '');
        else el.textContent = t || '—';
    }
    function loadReplies() {
        if (!CRM_READS) { $('moReplyList').innerHTML = '<div class="mo-empty-note">—</div>'; return; }
        if (!replyWired) {
            replyWired = true;
            $('moReplyStatus').addEventListener('change', loadReplies);
            $('moReplyClass').addEventListener('change', loadReplies);
            $('moReplySyncNow').addEventListener('click', function () {
                var btn = this; btn.disabled = true; btn.textContent = STR.reply.syncRunning;
                apiPost('reply_sync_now', {}).then(function (d) {
                    btn.disabled = false; btn.textContent = <?php echo json_encode(ma('reply_sync_now')); ?>;
                    if (d.success) { showOk(STR.reply.syncDone); loadReplies(); }
                    else showErr(d.result && d.result.error ? d.result.error : STR.loadFailed);
                }).catch(function () { btn.disabled = false; showErr(STR.loadFailed); });
            });
        }
        apiGet('replies', { status: $('moReplyStatus').value, class: $('moReplyClass').value, limit: 100 })
        .then(function (d) {
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            replySyncNote(d.sync);
            var rows = d.replies || [];
            if (!rows.length) {
                $('moReplyList').innerHTML = '<div class="mo-empty-note">' + esc(STR.reply.empty) + '</div>';
                return;
            }
            var html = '<table class="mo-table"><thead><tr><th>' + esc(STR.reply.colDate) + '</th><th>' +
                esc(STR.reply.colFrom) + '</th><th>' + esc(STR.reply.colSubject) + '</th><th>' +
                esc(STR.reply.colClass) + '</th><th>' + esc(STR.reply.colCampaign) + '</th><th>' +
                esc(STR.reply.colStatus) + '</th><th></th></tr></thead><tbody>';
            rows.forEach(function (r) {
                var isBounce = r.classification === 'delivery_failure';
                var clsPill = 'mo-pill ' + (r.classification === 'human' ? 'good'
                    : isBounce ? (r.bounce_kind === 'permanent' ? 'bad' : r.bounce_kind === 'temporary' ? 'warn' : 'alt')
                    : r.classification === 'unsubscribe_request' ? 'bad' : 'alt');
                var stPill = r.review_status === 'new' ? '<span class="mo-pill warn">new</span>'
                    : '<span class="mo-pill">' + esc(r.review_status) + '</span>';
                var badges = '';
                if (r.ambiguous == 1) badges += '<br><span class="mo-flag do_not_contact">' + esc(STR.reply.ambiguous) + '</span>';
                if (r.matched_via === 'none') badges += '<br><span class="mo-flag missing_contact">' + esc(STR.reply.unmatched) + '</span>';
                if (r.suppressed_email) badges += '<br><span class="mo-flag do_not_contact">' + esc(STR.reply.suppressedBadge) + ': ' + esc(r.suppression_source || '') + '</span>';
                if (r.action_taken === 'held') badges += '<br><span class="mo-flag missing_contact">' + esc(STR.reply.heldBadge) + '</span>';
                if (r.unsubscribe_confidence === 'review' && r.classification === 'unsubscribe_request')
                    badges += '<br><span class="mo-flag missing_contact">' + esc(STR.reply.unsubReview) + '</span>';
                if (r.failed_recipient) badges += '<br><span class="mo-sub">→ ' + esc(r.failed_recipient) + '</span>';
                html += '<tr><td class="mo-sub">' + esc(fmtDT(r.gmail_internal_date)) + '</td>' +
                    '<td><strong>' + esc(r.contact_name || r.from_name || r.from_email || '—') + '</strong>' +
                    (r.org_name ? '<br><span class="mo-sub">' + esc(r.org_name) + '</span>' : '') +
                    (!r.contact_name && r.from_name ? '<br><span class="mo-sub">' + esc(r.from_email || '') + '</span>' : '') +
                    badges + '</td>' +
                    '<td>' + esc(r.subject || '—') +
                    (r.snippet ? '<br><span class="mo-sub">' + esc(r.snippet) + '</span>' : '') + '</td>' +
                    '<td><span class="' + clsPill + '">' + esc(replyClassLabel(r)) + '</span></td>' +
                    '<td class="mo-sub">' + esc(r.campaign_name || '—') + '</td>' +
                    '<td>' + stPill + (r.reviewed_by ? '<br><span class="mo-sub">' + esc(r.reviewed_by) + '</span>' : '') + '</td>' +
                    '<td style="white-space:nowrap;">' +
                    (r.contact_id && window.MOCrm && MOCrm.openDrawer ? '<button type="button" class="mo-btn secondary small" data-prospect="' + r.contact_id + '">' + esc(<?php echo json_encode(ma('reply_view_prospect')); ?>) + '</button> ' : '') +
                    '<a class="mo-btn secondary small" href="' + esc(r.gmail_url) + '" target="_blank" rel="noopener">Gmail</a> ' +
                    (r.review_status === 'new' ? '<button type="button" class="mo-btn small" data-review="' + r.id + '">' + esc(STR.reply.markReviewed) + '</button> ' +
                    '<button type="button" class="mo-btn secondary small" data-dismiss="' + r.id + '">' + esc(STR.reply.dismiss) + '</button>' : '') +
                    '</td></tr>';
            });
            $('moReplyList').innerHTML = html + '</tbody></table>';
            $('moReplyList').querySelectorAll('[data-review]').forEach(function (b) {
                b.addEventListener('click', function () {
                    apiPost('reply_mark_reviewed', { reply_id: +b.dataset.review }).then(function () {
                        showOk(STR.reply.reviewed); loadReplies();
                    });
                });
            });
            $('moReplyList').querySelectorAll('[data-dismiss]').forEach(function (b) {
                b.addEventListener('click', function () {
                    apiPost('reply_mark_reviewed', { reply_id: +b.dataset.dismiss, dismissed: 1 }).then(function () {
                        showOk(STR.reply.reviewed); loadReplies();
                    });
                });
            });
            $('moReplyList').querySelectorAll('[data-prospect]').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (window.MOCrm && MOCrm.openDrawer) MOCrm.openDrawer(+b.dataset.prospect);
                });
            });
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
            if (d.sheet_error) {
                var msg = sheetErrorText(d.sheet_error);
                showErr(msg);
                host.innerHTML = '<div class="pa-card">' + esc(msg) + '</div>';
                return;
            }
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
    // Sheet-mode prospect controls are absent when the CRM module is on.
    if (!CRM_READS) {
        document.getElementById('moProspectSearch').addEventListener('click', function () { state.prospectPage = 1; loadProspects(); });
        document.getElementById('moProspectQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { state.prospectPage = 1; loadProspects(); } });
    }

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
        if (CRM_READS && window.MOCrm) { MOCrm.newCampaignWizard(); return; }
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
            renderSending(d.sending);
            var canSend = !!(state.sending && state.sending.enabled);
            var recips = d.recipients || [];
            var hasUncertain = recips.some(function (r) { return r.status === 'uncertain'; });
            var hasSheetWork = recips.some(function (r) { return r.status === 'sent' && (r.sheet_sync_status === 'pending' || r.sheet_sync_status === 'failed'); });
            document.getElementById('moCampApprove').textContent = canSend ? STR.approveAndSend : STR.approveOnly;
            document.getElementById('moCampReconcile').classList.toggle('mo-hidden', !hasUncertain);
            document.getElementById('moCampSheetSync').classList.toggle('mo-hidden', !hasSheetWork);
            document.getElementById('moCampThreads').classList.toggle('mo-hidden',
                !(c.kind === 'followup' && (c.status === 'draft' || c.status === 'ready')));
            document.getElementById('moCampRetry').disabled = !canSend;
            state.currentKind = c.kind;
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
            // Follow-up threading: say plainly whether this message joins
            // the original conversation or starts a new one, and why.
            var notes = '';
            var locked = r.thread_status === 'resolved';
            if (state.currentKind === 'followup' && r.thread_status) {
                var tText = STR.thread[r.thread_status] || r.thread_status;
                flags += '<span class="mo-pill ' + (locked ? 'thread-resolved' : 'thread-new') + '">' + esc(tText) + '</span> ';
                if (locked) notes += '<div class="mo-msg-note">' + esc(STR.threadSubjectLocked) + '</div>';
                else if (r.thread_reason) notes += '<div class="mo-msg-note">' + esc(r.thread_reason.replace(/_/g, ' ')) + '</div>';
            }
            if (r.status === 'uncertain') {
                notes += '<div class="mo-msg-note">' + esc(STR.uncertainNote) + '</div>' +
                    '<div style="margin:6px 0;display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<button class="mo-btn secondary small" data-resolve="not_sent">' + esc(STR.markNotSent) + '</button>' +
                    '<button class="mo-btn secondary small" data-resolve="sent">' + esc(STR.markSent) + '</button></div>';
            }
            if (r.status === 'sent' && (r.sheet_sync_status === 'pending' || r.sheet_sync_status === 'failed')) {
                flags += '<span class="mo-pill sheet-failed">' + esc(r.sheet_sync_status === 'failed' ? STR.sheetFailed : STR.sheetPending) + '</span> ';
                if (r.sheet_sync_error) notes += '<div class="mo-msg-note">' + esc(r.sheet_sync_error) + '</div>';
            }
            div.innerHTML =
                '<div class="mo-msg-head"><strong>' + esc(r.prospect_contact || r.prospect_email) + '</strong>' +
                '<span>' + flags + '<span class="mo-pill ' + r.status + '">' + esc(r.status) + '</span>' +
                (r.is_edited == 1 ? ' <span class="mo-pill">' + esc(<?php echo json_encode(ma('edited')); ?>) + '</span>' : '') + '</span></div>' +
                '<div class="mo-msg-sub">' + esc(r.prospect_org || '') + ' · ' + esc(r.prospect_email) +
                (r.error ? ' · <span style="color:#b91c1c;">' + esc(r.error) + '</span>' : '') + '</div>' + notes +
                (canEdit
                    ? '<input type="text" class="mo-subj"' + (locked ? ' readonly' : '') + ' value="' + esc(r.subject || '') + '" placeholder="' + esc(<?php echo json_encode(ma('subject_ph')); ?>) + '">' +
                      '<textarea class="mo-body">' + esc(r.body_html || '') + '</textarea>' +
                      '<div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">' +
                      '<button class="mo-btn small" data-act="save">' + esc(<?php echo json_encode(ma('save')); ?>) + '</button>' +
                      '<button class="mo-btn secondary small" data-act="preview">' + esc(<?php echo json_encode(ma('preview')); ?>) + '</button></div>' +
                      '<div class="mo-preview mo-hidden" style="margin-top:8px;"></div>'
                    : '<div class="mo-preview">' + (r.body_html || '<em>' + esc(<?php echo json_encode(ma('no_content')) ?>) + '</em>') + '</div>');
            host.appendChild(div);
            div.querySelectorAll('button[data-resolve]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var outcome = b.dataset.resolve;
                    if (!window.confirm(outcome === 'sent' ? STR.confirmMarkSent : STR.confirmMarkNotSent)) return;
                    apiPost('resolve_uncertain', { campaign_id: state.currentCampaign, recipient_id: r.id, outcome: outcome })
                        .then(function (d2) { if (!d2.success) showErr(d2.error || STR.loadFailed); openCampaign(state.currentCampaign); });
                });
            });
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
            showOk(<?php echo json_encode(ma('generated_ok')); ?>.replace('{n}', d.generated));
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
            showOk(sendSummary(d2));
            openCampaign(state.currentCampaign);
        });
    });
    document.getElementById('moCampApprove').addEventListener('click', function () {
        var btn = this;
        // Sending is a separate control: with it off, this only approves.
        var canSend = !!(state.sending && state.sending.enabled);
        var live = canSend && state.sending.mode === 'unrestricted';
        if (!window.confirm(!canSend ? STR.confirmApproveOnly : (live ? STR.confirmSend : STR.confirmSendTest))) return;
        btn.disabled = true; btn.textContent = STR.working;
        apiPost('approve', { campaign_id: state.currentCampaign }).then(function (d) {
            if (!d.success) { btn.disabled = false; showErr(d.error || STR.loadFailed); openCampaign(state.currentCampaign); return; }
            if (!canSend) {
                btn.disabled = false;
                showOk(STR.approvedNotSent);
                openCampaign(state.currentCampaign);
                return;
            }
            // approved -> now send
            apiPost('send', { campaign_id: state.currentCampaign }).then(function (d2) {
                btn.disabled = false;
                if (!d2.success) { showErr(d2.error || STR.loadFailed); openCampaign(state.currentCampaign); return; }
                showOk(sendSummary(d2));
                openCampaign(state.currentCampaign);
            });
        });
    });
    // Recovery actions below never send email.
    document.getElementById('moCampThreads').addEventListener('click', function () {
        var btn = this; btn.disabled = true;
        apiPost('resolve_threads', { campaign_id: state.currentCampaign }).then(function (d) {
            btn.disabled = false;
            if (!d.success) showErr(d.error || STR.loadFailed);
            openCampaign(state.currentCampaign);
        });
    });
    document.getElementById('moCampReconcile').addEventListener('click', function () {
        var btn = this; btn.disabled = true;
        apiPost('reconcile', { campaign_id: state.currentCampaign }).then(function (d) {
            btn.disabled = false;
            if (!d.success) showErr(d.error || STR.loadFailed);
            else showOk(STR.reconcileResult.replace('{sent}', d.sent).replace('{not_sent}', d.not_sent).replace('{unknown}', d.unknown));
            openCampaign(state.currentCampaign);
        });
    });
    document.getElementById('moCampSheetSync').addEventListener('click', function () {
        var btn = this; btn.disabled = true;
        apiPost('retry_sheet_sync', { campaign_id: state.currentCampaign }).then(function (d) {
            btn.disabled = false;
            if (!d.success) showErr(d.error || STR.loadFailed);
            else showOk(STR.sheetSyncResult.replace('{synced}', d.synced).replace('{failed}', d.failed));
            openCampaign(state.currentCampaign);
        });
    });

    // ---------- follow-ups ----------
    function loadFollowups(bucket) {
        apiGet('followups', { bucket: bucket }).then(function (d) {
            var host = document.getElementById('moFuList');
            if (!d.success) { showErr(d.error || STR.loadFailed); return; }
            if (d.sheet_error) {
                var msg = sheetErrorText(d.sheet_error);
                showErr(msg);
                host.innerHTML = '<div class="pa-card">' + esc(msg) + '</div>';
                return;
            }
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
                    '<td>' + (p.eligible
                        ? '<button class="mo-btn secondary small mo-fu-add" data-email="' + esc(p.email) + '" data-cid="' + esc(p.id || '') + '">' + esc(<?php echo json_encode(ma('add_to_campaign')); ?>) + '</button>'
                        : '<span class="mo-msg-note">' + esc(STR.notEligibleFollowup) + '</span><br>' +
                          (p.followup_flags || []).map(function (f) { return '<span class="mo-flag duplicate_org">' + esc(f.replace(/_/g, ' ')) + '</span>'; }).join('')) + '</td></tr>';
            });
            host.innerHTML = html + '</tbody></table>';
            host.querySelectorAll('.mo-fu-add').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (CRM_READS && window.MOCrm && b.dataset.cid) {
                        MOCrm.addContactsToCampaign([parseInt(b.dataset.cid, 10)], 'followup');
                    } else {
                        fuAddEmail(b.dataset.email);
                    }
                });
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

    // Phase 3 CRM module (only present when MARKETING_CRM_READS is on)
    if (CRM_READS && window.MOCrm) {
        MOCrm.init({
            api: API, csrf: CSRF,
            showErr: showErr, showOk: showOk,
            str: {
                loadFailed: STR.loadFailed, loading: <?php echo json_encode(ma('loading')); ?>,
                noProspects: STR.noProspects, saved: <?php echo json_encode(ma('saved')); ?>,
                savedWithWarnings: <?php echo json_encode(ma('saved_with_warnings')); ?>,
                conflict: <?php echo json_encode(ma('conflict_reload')); ?>,
                bulkDone: <?php echo json_encode(ma('bulk_done')); ?>,
                selected: <?php echo json_encode(ma('selected_count')); ?>,
                colOrg: <?php echo json_encode(ma('col_org')); ?>,
                colContact: <?php echo json_encode(ma('col_contact')); ?>,
                colEmail: <?php echo json_encode(ma('col_email')); ?>,
                colStatus: <?php echo json_encode(ma('col_status')); ?>,
                colPriority: <?php echo json_encode(ma('col_priority')); ?>,
                colFollowup: <?php echo json_encode(ma('col_followup')); ?>,
                colEligibility: <?php echo json_encode(ma('col_eligibility')); ?>,
                colFlags: <?php echo json_encode(ma('col_flags')); ?>,
                colType: <?php echo json_encode(ma('col_type')); ?>,
                colTitle: <?php echo json_encode(ma('col_title')); ?>,
                colPhone: <?php echo json_encode(ma('col_phone')); ?>,
                colLocation: <?php echo json_encode(ma('col_location')); ?>,
                colWebsite: <?php echo json_encode(ma('col_website')); ?>,
                colDomain: <?php echo json_encode(ma('col_domain')); ?>,
                colResponse: <?php echo json_encode(ma('col_response')); ?>,
                colFit: <?php echo json_encode(ma('col_fit')); ?>,
                colOwner: <?php echo json_encode(ma('col_owner')); ?>,
                colSource: <?php echo json_encode(ma('col_source')); ?>,
                colLastContact: <?php echo json_encode(ma('col_last_contact')); ?>,
                colCampaign: <?php echo json_encode(ma('col_campaign')); ?>,
                colMethod: <?php echo json_encode(ma('col_method')); ?>,
                colNotes: <?php echo json_encode(ma('col_notes')); ?>,
                colKind: <?php echo json_encode(ma('col_kind')); ?>,
                colLine: <?php echo json_encode(ma('col_line')); ?>,
                firstName: <?php echo json_encode(ma('first_name')); ?>,
                lastName: <?php echo json_encode(ma('last_name')); ?>,
                parentOrg: <?php echo json_encode(ma('parent_org')); ?>,
                demoStatus: <?php echo json_encode(ma('demo_status')); ?>,
                trialStatus: <?php echo json_encode(ma('trial_status')); ?>,
                outcome: <?php echo json_encode(ma('outcome')); ?>,
                objection: <?php echo json_encode(ma('objection_reason')); ?>,
                eligible: <?php echo json_encode(ma('eligible_badge')); ?>,
                excluded: <?php echo json_encode(ma('excluded_badge')); ?>,
                needsReview: <?php echo json_encode(ma('needs_review_badge')); ?>,
                markReviewed: <?php echo json_encode(ma('mark_reviewed')); ?>,
                reviewResolved: <?php echo json_encode(ma('review_resolved')); ?>,
                organization: <?php echo json_encode(ma('organization')); ?>,
                editOrg: <?php echo json_encode(ma('edit_org')); ?>,
                edit: <?php echo json_encode(ma('edit')); ?>,
                prospect: <?php echo json_encode(ma('prospect')); ?>,
                campaignMembership: <?php echo json_encode(ma('campaign_membership')); ?>,
                sendHistory: <?php echo json_encode(ma('send_history')); ?>,
                activity: <?php echo json_encode(ma('activity')); ?>,
                activityPlaceholder: <?php echo json_encode(ma('activity_placeholder')); ?>,
                activityRequired: <?php echo json_encode(ma('activity_required')); ?>,
                activityAdded: <?php echo json_encode(ma('activity_added')); ?>,
                addActivity: <?php echo json_encode(ma('add_activity')); ?>,
                manualNotProof: <?php echo json_encode(ma('manual_not_proof')); ?>,
                act_note: <?php echo json_encode(ma('act_note')); ?>,
                act_call: <?php echo json_encode(ma('act_call')); ?>,
                act_meeting: <?php echo json_encode(ma('act_meeting')); ?>,
                act_manual_email: <?php echo json_encode(ma('act_manual_email')); ?>,
                act_status_update: <?php echo json_encode(ma('act_status_update')); ?>,
                editProspect: <?php echo json_encode(ma('edit_prospect')); ?>,
                addProspect: <?php echo json_encode(ma('add_prospect')); ?>,
                addOrg: <?php echo json_encode(ma('add_org')); ?>,
                editOrgTitle: <?php echo json_encode(ma('edit_org_title')); ?>,
                orgName: <?php echo json_encode(ma('org_name')); ?>,
                orgSearchPh: <?php echo json_encode(ma('org_search_ph')); ?>,
                orgTypePh: <?php echo json_encode(ma('org_type_ph')); ?>,
                domainPh: <?php echo json_encode(ma('domain_ph')); ?>,
                createNewOrg: <?php echo json_encode(ma('create_new_org')); ?>,
                noOrg: <?php echo json_encode(ma('no_org')); ?>,
                tagsPh: <?php echo json_encode(ma('tags_ph')); ?>,
                save: <?php echo json_encode(ma('save')); ?>,
                cancel: <?php echo json_encode(ma('cancel')); ?>,
                maxRecipients: '10',
                noCampaigns: <?php echo json_encode(ma('no_campaigns')); ?>,
                colTags: <?php echo json_encode(ma('col_tags')); ?>,
                newDraftCampaign: <?php echo json_encode(ma('new_draft_campaign')); ?>,
                campaignName: <?php echo json_encode(ma('campaign_name')); ?>,
                campaignNamePh: <?php echo json_encode(ma('campaign_name_ph')); ?>,
                kindInitial: <?php echo json_encode(ma('kind_initial_lbl')); ?>,
                kindFollowup: <?php echo json_encode(ma('kind_followup_lbl')); ?>,
                addToCampaignNote: <?php echo json_encode(ma('add_to_campaign_note')); ?>,
                confirmAdd: <?php echo json_encode(ma('confirm_add')); ?>,
                addToCampaign: <?php echo json_encode(ma('add_to_campaign')); ?>,
                campaignAddDone: <?php echo json_encode(ma('campaign_add_done')); ?>,
                previewNote: <?php echo json_encode(ma('campaign_preview_note')); ?>,
                campaignNameRequired: <?php echo json_encode(ma('campaign_name_required')); ?>,
                importNote: <?php echo json_encode(ma('import_note')); ?>,
                preview: <?php echo json_encode(ma('preview')); ?>,
                confirmImport: <?php echo json_encode(ma('confirm_import')); ?>,
                importNoFile: <?php echo json_encode(ma('import_no_file')); ?>,
                importCounts: <?php echo json_encode(ma('import_counts')); ?>,
                importDone: <?php echo json_encode(ma('import_done')); ?>,
                importCsv: <?php echo json_encode(ma('import_csv')); ?>,
                disposition: <?php echo json_encode(ma('disposition')); ?>,
                bulk_set_priority: <?php echo json_encode(ma('bulk_set_priority')); ?>,
                bulk_owner_ph: <?php echo json_encode(ma('bulk_owner_ph')); ?>,
                bulk_tags_ph: <?php echo json_encode(ma('bulk_tags_ph')); ?>,
                add_to_campaign: <?php echo json_encode(ma('add_to_campaign')); ?>,
                export_selected: <?php echo json_encode(ma('export_selected')); ?>,
                clear_selection: <?php echo json_encode(ma('clear_selection')); ?>,
                // Phase 4 campaign module
                colName: <?php echo json_encode(ma('col_name')); ?>,
                colKind: <?php echo json_encode(ma('col_kind')); ?>,
                colStatus: <?php echo json_encode(ma('col_status')); ?>,
                colRecipients: <?php echo json_encode(ma('col_recipients')); ?>,
                colSent: <?php echo json_encode(ma('col_sent')); ?>,
                colCreated: <?php echo json_encode(ma('col_created')); ?>,
                approvedBy: <?php echo json_encode(ma('approved_by')); ?>,
                approveWarn: <?php echo json_encode(ma('approve_warn')); ?>,
                approveAndSend: <?php echo json_encode(ma('approve_and_send')); ?>,
                approveOnly: <?php echo json_encode(ma('approve_only')); ?>,
                stepEdit: <?php echo json_encode(ma('step_edit')); ?>,
                tplDefault: <?php echo json_encode(ma('tpl_default_label')); ?>,
                searchProspects: <?php echo json_encode(ma('search_prospects_ph')); ?>,
                allEligibility: <?php echo json_encode(ma('all_eligibility')); ?>,
                selectRecipients: <?php echo json_encode(ma('select_recipients')); ?>,
                addSelected: <?php echo json_encode(ma('add_selected')); ?>,
                selectedCount: <?php echo json_encode(ma('selected_count_label')); ?>,
                overLimit: <?php echo json_encode(ma('over_recipient_limit')); ?>,
                campaignName: <?php echo json_encode(ma('campaign_name')); ?>,
                campaignNamePh: <?php echo json_encode(ma('campaign_name_ph')); ?>,
                description: <?php echo json_encode(ma('description')); ?>,
                stepDetails: <?php echo json_encode(ma('step_details')); ?>,
                stepRecipients: <?php echo json_encode(ma('step_recipients')); ?>,
                chooseRecipients: <?php echo json_encode(ma('choose_recipients')); ?>,
                noRecipientsYet: <?php echo json_encode(ma('no_recipients_yet')); ?>,
                createDraft: <?php echo json_encode(ma('create_draft')); ?>,
                newCampaign: <?php echo json_encode(ma('new_campaign_wizard')); ?>,
                campaignCreated: <?php echo json_encode(ma('campaign_created')); ?>,
                campaignNameRequired: <?php echo json_encode(ma('campaign_name_required')); ?>,
                addRecipients: <?php echo json_encode(ma('add_recipients')); ?>,
                addedN: <?php echo json_encode(ma('added_count')); ?>,
                editCampaign: <?php echo json_encode(ma('edit_campaign_title')); ?>,
                confirmRemoveRecipient: <?php echo json_encode(ma('confirm_remove_recipient')); ?>,
                removed: <?php echo json_encode(ma('recipient_removed')); ?>,
                regen: <?php echo json_encode(ma('regen_message')); ?>,
                remove: <?php echo json_encode(ma('remove_recipient')); ?>,
                subjectPh: <?php echo json_encode(ma('subject_ph')); ?>,
                preview: <?php echo json_encode(ma('preview')); ?>,
                previewFinal: <?php echo json_encode(ma('preview_final')); ?>,
                pvFrom: <?php echo json_encode(ma('pv_from')); ?>,
                pvTo: <?php echo json_encode(ma('pv_to')); ?>,
                pvSubject: <?php echo json_encode(ma('pv_subject')); ?>,
                pvThreading: <?php echo json_encode(ma('pv_threading')); ?>,
                pvManagedNote: <?php echo json_encode(ma('pv_managed_note')); ?>,
                pvUnsubPlaceholder: <?php echo json_encode(ma('pv_unsub_placeholder')); ?>,
                pvHtmlTab: <?php echo json_encode(ma('pv_html_tab')); ?>,
                pvTextTab: <?php echo json_encode(ma('pv_text_tab')); ?>,
                senderSignature: <?php echo json_encode(ma('sender_signature')); ?>,
                pasteEmails: <?php echo json_encode(ma('paste_emails')); ?>,
                pasteEmailsPh: <?php echo json_encode(ma('paste_emails_ph')); ?>,
                findRecipients: <?php echo json_encode(ma('find_recipients')); ?>,
                pasteEmailsRequired: <?php echo json_encode(ma('paste_emails_required')); ?>,
                notInCrm: <?php echo json_encode(ma('not_in_crm')); ?>,
                ambiguousMatch: <?php echo json_encode(ma('ambiguous_match')); ?>,
                alreadyInCampaign: <?php echo json_encode(ma('already_in_campaign')); ?>,
                bulkSummary: <?php echo json_encode(ma('bulk_summary')); ?>,
                sigHint: <?php echo json_encode(ma('sig_hint')); ?>,
                sigName: <?php echo json_encode(ma('sig_name')); ?>,
                sigTitleLbl: <?php echo json_encode(ma('sig_title_lbl')); ?>,
                sigDetailLbl: <?php echo json_encode(ma('sig_detail_lbl')); ?>,
                noContent: <?php echo json_encode(ma('no_content')); ?>,
                edited: <?php echo json_encode(ma('edited')); ?>,
                uncertainNote: <?php echo json_encode(ma('uncertain_note')); ?>,
                markNotSent: <?php echo json_encode(ma('mark_not_sent')); ?>,
                markSent: <?php echo json_encode(ma('mark_sent')); ?>,
                confirmMarkNotSent: <?php echo json_encode(ma('confirm_mark_not_sent')); ?>,
                confirmMarkSent: <?php echo json_encode(ma('confirm_mark_sent')); ?>,
                confirm: <?php echo json_encode(ma('confirm_dialog_title')); ?>,
                yes: <?php echo json_encode(ma('yes_btn')); ?>,
                confirmApproveOnly: <?php echo json_encode(ma('confirm_approve_only')); ?>,
                approvedNotSent: <?php echo json_encode(ma('approved_not_sent')); ?>,
                confirmSendCampaign: <?php echo json_encode(ma('confirm_send_campaign')); ?>,
                sendSummary: <?php echo json_encode(ma('send_summary')); ?>,
                reconcileResult: <?php echo json_encode(ma('reconcile_result')); ?>,
                generatedOk: <?php echo json_encode(ma('generated_ok')); ?>,
                kindInitial: <?php echo json_encode(ma('kind_initial_lbl')); ?>,
                kindFollowup: <?php echo json_encode(ma('kind_followup_lbl')); ?>,
                template: <?php echo json_encode(ma('template')); ?>,
                sendCampaign: <?php echo json_encode(ma('send_campaign')); ?>,
                maxRecipients: <?php echo json_encode(ma('max_recipients_label')); ?>,
                cancel: <?php echo json_encode(ma('cancel')); ?>,
                save: <?php echo json_encode(ma('save')); ?>,
                colLine: <?php echo json_encode(ma('col_line')); ?>,
                importNote: <?php echo json_encode(ma('import_note')); ?>,
                importNoFile: <?php echo json_encode(ma('import_no_file')); ?>,
                importCounts: <?php echo json_encode(ma('import_counts')); ?>,
                confirmImport: <?php echo json_encode(ma('confirm_import')); ?>,
                importDone: <?php echo json_encode(ma('import_done')); ?>,
                importCsv: <?php echo json_encode(ma('import_csv')); ?>,
                disposition: <?php echo json_encode(ma('disposition')); ?>,
                campaignAddDone: <?php echo json_encode(ma('campaign_add_done')); ?>,
                previewNote: <?php echo json_encode(ma('campaign_preview_note')); ?>,
                newDraftCampaign: <?php echo json_encode(ma('new_draft_campaign')); ?>,
                addToCampaignNote: <?php echo json_encode(ma('add_to_campaign_note')); ?>,
                confirmAdd: <?php echo json_encode(ma('confirm_add')); ?>,
                addToCampaign: <?php echo json_encode(ma('add_to_campaign')); ?>,
                campaignNameRequired2: <?php echo json_encode(ma('campaign_name_required')); ?>,
                unmappedColumns: <?php echo json_encode(ma('unmapped_columns')); ?>,
                fmtBold: <?php echo json_encode(ma('fmt_bold')); ?>,
                fmtItalic: <?php echo json_encode(ma('fmt_italic')); ?>,
                fmtUnderline: <?php echo json_encode(ma('fmt_underline')); ?>,
                fmtList: <?php echo json_encode(ma('fmt_list')); ?>,
                fmtClear: <?php echo json_encode(ma('fmt_clear')); ?>,
                editorHint: <?php echo json_encode(ma('editor_hint')); ?>,
                threadReasonNone: <?php echo json_encode(ma('thread_reason_none')); ?>,
                threadReasonAmbiguous: <?php echo json_encode(ma('thread_reason_ambiguous')); ?>,
                threadReasonUnavailable: <?php echo json_encode(ma('thread_reason_unavailable')); ?>,
                threadReasonUnverified: <?php echo json_encode(ma('thread_reason_unverified')); ?>,
                threadReasonInvalid: <?php echo json_encode(ma('thread_reason_invalid')); ?>,
                threadReasonAction: <?php echo json_encode(ma('thread_reason_action')); ?>,
                threadSendUnverified: <?php echo json_encode(ma('thread_send_unverified')); ?>,
                newThreadsWarn: <?php echo json_encode(ma('new_threads_warn')); ?>,
                newThreadsAck: <?php echo json_encode(ma('new_threads_ack')); ?>,
                checkingThreads: <?php echo json_encode(ma('checking_threads')); ?>,
                threadsMatched: <?php echo json_encode(ma('threads_matched')); ?>,
                threadsNotFound: <?php echo json_encode(ma('threads_not_found')); ?>,
                threadsAmbiguous: <?php echo json_encode(ma('threads_ambiguous')); ?>,
                threadsUnverifiable: <?php echo json_encode(ma('threads_unverifiable')); ?>,
                threadsSheetError: <?php echo json_encode(ma('threads_sheet_error')); ?>,
                threadsNone: <?php echo json_encode(ma('threads_none')); ?>
            }
        });
    }

    loadTab('overview');
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
