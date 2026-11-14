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
                    box-shadow: 0 20px 50px rgba(0,0,0,0.25); }
        .mo-modal-head { display: flex; justify-content: space-between; align-items: center;
                         padding: 16px 20px; border-bottom: 1px solid #e5e7eb; }
        .mo-modal-head h3 { margin: 0; font-size: 1rem; }
        .mo-modal-x { background: none; border: none; font-size: 1.5rem; line-height: 1; cursor: pointer; color: #6b7280; }
        .mo-modal-body { padding: 16px 20px; max-height: 66vh; overflow-y: auto; }
        .mo-modal-foot { padding: 12px 20px; border-top: 1px solid #e5e7eb; display: flex; gap: 10px; justify-content: flex-end; }
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
        cancelled: <?php echo json_encode(ma('cancelled')); ?>
    };
    var state = { prospectPage: 1, currentCampaign: null, templates: [] };

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
        else if (name === 'templates') loadTemplates();
        else if (name === 'history') loadHistory();
    }

    // ---------- overview ----------
    function loadOverview() {
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
            document.getElementById('moOvMetrics').innerHTML = html;
            var buckets = '';
            ['due_today','overdue','upcoming','awaiting_response','not_contacted','completed','excluded'].forEach(function (b) {
                buckets += '<span class="mo-pill ' + b + '">' + esc(b.replace(/_/g, ' ')) + ': ' + (o.followup_buckets[b] || 0) + '</span> ';
            });
            document.getElementById('moOvBuckets').innerHTML = buckets;
            var fl = '';
            ['missing_contact','do_not_contact','duplicate_org'].forEach(function (f) {
                fl += '<span class="mo-flag ' + f + '">' + esc(f.replace(/_/g, ' ')) + ': ' + (o.flags[f] || 0) + '</span> ';
            });
            document.getElementById('moOvFlags').innerHTML = fl || '—';
            document.getElementById('moOvSheetNote').textContent = o.sheet_error ? sheetErrorText(o.sheet_error) : '';
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
                newThreadsWarn: <?php echo json_encode(ma('new_threads_warn')); ?>,
                newThreadsAck: <?php echo json_encode(ma('new_threads_ack')); ?>
            }
        });
    }

    loadTab('overview');
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
