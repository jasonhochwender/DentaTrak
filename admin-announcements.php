<?php
/**
 * Platform Administration > Announcements
 *
 * Manage scheduled, audience-targeted announcements shown to DentaTrak
 * users below the app header. Draft / scheduled / active / expired /
 * disabled lifecycle, immediate enable/disable, duplicate, delete, and a
 * live preview that mirrors the user-facing banner.
 *
 * Access: super users, or the local development environment - same gate as
 * the other Platform Administration pages. Mutations go through
 * api/announcement-admin.php (CSRF + super-user + audit logged).
 */

require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

if (empty($_SESSION['db_user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/api/dev-tools-access.php';
require_once __DIR__ . '/api/asset-url.php';
require_once __DIR__ . '/admin-layout.php';

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

$csrfToken = generateCsrfToken();

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function a($k, $f = '') { $v = t('admin_ann.' . $k); return $v !== '' ? $v : $f; }
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title><?php echo h(t('admin_nav.announcements')); ?> - <?php echo h($appConfig['appName']); ?></title>
    <?php require __DIR__ . '/partials/brand-icons.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= appAssetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= appAssetUrl('css/admin.css') ?>">
    <link rel="stylesheet" href="<?= appAssetUrl('css/announcements.css') ?>">
    <style>
        .ann-wrap { max-width: 1100px; }
        .ann-toolbar { display: flex; justify-content: flex-end; margin-bottom: 16px; }
        .ann-btn {
            padding: 9px 18px; border: 0; border-radius: 6px; font-size: 0.88rem;
            font-weight: 600; cursor: pointer; background: #1e40af; color: #fff;
        }
        .ann-btn:hover { background: #1d4ed8; }
        .ann-btn-secondary { background: #fff; color: #1e40af; border: 1px solid #bfdbfe; }
        .ann-btn-secondary:hover { background: #eff6ff; }
        .ann-btn-danger { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
        .ann-btn-danger:hover { background: #fef2f2; }
        .ann-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .ann-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .ann-field { display: flex; flex-direction: column; gap: 4px; }
        .ann-field.full { grid-column: 1 / -1; }
        .ann-field label { font-size: 0.78rem; font-weight: 600; color: #4b5563; }
        .ann-field input:not([type="checkbox"]), .ann-field select, .ann-field textarea {
            padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 0.88rem; font-family: inherit; width: 100%; box-sizing: border-box;
        }
        .ann-field textarea { min-height: 90px; resize: vertical; }
        .ann-field select {
            padding-right: 34px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23475569' d='M2 4l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        .ann-field .ann-hint { font-size: 0.72rem; color: #9ca3af; font-weight: 400; }
        .ann-checks { display: flex; gap: 28px; flex-wrap: wrap; }
        .ann-check-item { display: flex; flex-direction: column; gap: 3px; }
        .ann-check {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 0.85rem; font-weight: 600; color: #111827;
            cursor: pointer; white-space: nowrap; width: fit-content;
        }
        .ann-check input[type="checkbox"] {
            width: 16px; height: 16px; margin: 0; flex: 0 0 auto; accent-color: #1e40af;
        }
        .ann-check-help { font-size: 0.75rem; color: #6b7280; font-weight: 400; }
        .ann-form-actions { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .ann-preview { margin-top: 16px; }
        .ann-preview .dt-announcements { padding: 0; max-width: none; }
        .ann-preview-label { font-size: 0.78rem; font-weight: 600; color: #4b5563; margin-bottom: 6px; }
        .ann-table-wrap { overflow-x: auto; }
        .ann-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; background: #fff; }
        .ann-table th { text-align: left; padding: 10px 12px; background: #f9fafb; font-weight: 600; color: #374151; white-space: nowrap; }
        .ann-table td { padding: 9px 12px; border-top: 1px solid #f3f4f6; vertical-align: top; }
        .ann-table .ann-title { font-weight: 600; color: #111827; }
        .ann-table .ann-msg { color: #6b7280; font-size: 0.78rem; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ann-pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .ann-pill-draft { background: #f3f4f6; color: #4b5563; }
        .ann-pill-scheduled { background: #dbeafe; color: #1e40af; }
        .ann-pill-active { background: #d1fae5; color: #065f46; }
        .ann-pill-expired { background: #f3f4f6; color: #9ca3af; }
        .ann-pill-disabled { background: #fee2e2; color: #991b1b; }
        .ann-type { display: inline-flex; align-items: center; gap: 5px; font-size: 0.75rem; font-weight: 600; }
        .ann-type .dt-announcement-icon { width: 14px; height: 14px; }
        .ann-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .ann-link-btn { background: none; border: none; padding: 2px 6px; font-size: 0.78rem; color: #1e40af; cursor: pointer; }
        .ann-link-btn:hover { text-decoration: underline; }
        .ann-link-btn.danger { color: #b91c1c; }
        .ann-empty { padding: 24px; text-align: center; color: #6b7280; background: #fff; border-radius: 10px; }
        .ann-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-bottom: 14px; }
        .ann-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; margin-bottom: 14px; }
        .ann-tz-note { font-size: 0.78rem; color: #6b7280; }
        @media (max-width: 720px) {
            .ann-form-grid { grid-template-columns: 1fr; }
            .ann-table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<?php paAdminChrome('announcements', t('admin_nav.announcements'), t('admin_nav.announcements_subtitle')); ?>
<div class="ann-wrap">

    <div id="annMsg"></div>

    <div class="pa-card" id="annEditorCard" hidden>
        <h2 id="annEditorTitle"><?php echo h(a('new_title', 'New announcement')); ?></h2>
        <p class="pa-card-sub"><?php echo h(a('editor_sub', 'Fill in the details, check the preview, then save as draft or publish.')); ?></p>

        <div class="ann-form-grid">
            <div class="ann-field">
                <label for="fTitle"><?php echo h(a('f_title', 'Title (optional)')); ?></label>
                <input type="text" id="fTitle" maxlength="200" placeholder="<?php echo h(a('f_title_ph', 'e.g. Scheduled Maintenance')); ?>">
            </div>
            <div class="ann-field">
                <label for="fType"><?php echo h(a('f_type', 'Type')); ?></label>
                <select id="fType">
                    <option value="info"><?php echo h(a('type_info', 'Information')); ?></option>
                    <option value="warning"><?php echo h(a('type_warning', 'Warning')); ?></option>
                    <option value="critical"><?php echo h(a('type_critical', 'Critical')); ?></option>
                    <option value="success"><?php echo h(a('type_success', 'Success')); ?></option>
                </select>
            </div>
            <div class="ann-field full">
                <label for="fMessage"><?php echo h(a('f_message', 'Message')); ?></label>
                <textarea id="fMessage" maxlength="4000" placeholder="<?php echo h(a('f_message_ph', 'Announcement text shown to users.')); ?>"></textarea>
            </div>
            <div class="ann-field">
                <label for="fAudience"><?php echo h(a('f_audience', 'Audience')); ?></label>
                <select id="fAudience">
                    <option value="all"><?php echo h(a('aud_all', 'All Practices')); ?></option>
                    <option value="fabg"><?php echo h(a('aud_fabg', 'FABG Practices Only')); ?></option>
                </select>
            </div>
            <div class="ann-field">
                <label for="fVisibleTo"><?php echo h(a('f_visible_to', 'Visible To')); ?></label>
                <select id="fVisibleTo">
                    <option value="all"><?php echo h(a('vis_all', 'All Users')); ?></option>
                    <option value="admin"><?php echo h(a('vis_admin', 'Practice Administrators Only')); ?></option>
                </select>
            </div>
            <div class="ann-field">
                <label for="fTz"><?php echo h(a('f_timezone', 'Time zone for dates below')); ?></label>
                <select id="fTz">
                    <option value="America/New_York">Eastern Time (America/New_York)</option>
                    <option value="America/Chicago">Central Time (America/Chicago)</option>
                    <option value="America/Denver">Mountain Time (America/Denver)</option>
                    <option value="America/Phoenix">Arizona (America/Phoenix)</option>
                    <option value="America/Los_Angeles">Pacific Time (America/Los_Angeles)</option>
                    <option value="America/Anchorage">Alaska (America/Anchorage)</option>
                    <option value="Pacific/Honolulu">Hawaii (Pacific/Honolulu)</option>
                    <option value="UTC">UTC</option>
                </select>
                <span class="ann-hint"><?php echo h(a('f_tz_hint', 'Times are stored in UTC; the selected zone is used for entry and display.')); ?></span>
            </div>
            <div class="ann-field">
                <label for="fStart"><?php echo h(a('f_start', 'Display start')); ?></label>
                <input type="datetime-local" id="fStart">
            </div>
            <div class="ann-field">
                <label for="fEnd"><?php echo h(a('f_end', 'Display end')); ?></label>
                <input type="datetime-local" id="fEnd">
            </div>
            <div class="ann-field full">
                <div class="ann-checks">
                    <div class="ann-check-item">
                        <label class="ann-check" for="fEnabled">
                            <input type="checkbox" id="fEnabled" checked>
                            <span class="ann-check-text"><?php echo h(a('f_enabled', 'Enabled')); ?></span>
                        </label>
                        <span class="ann-check-help"><?php echo h(a('f_enabled_help', 'Allow this announcement to appear during its scheduled period.')); ?></span>
                    </div>
                    <div class="ann-check-item">
                        <label class="ann-check" for="fPublished">
                            <input type="checkbox" id="fPublished">
                            <span class="ann-check-text"><?php echo h(a('f_published', 'Published')); ?></span>
                        </label>
                        <span class="ann-check-help"><?php echo h(a('f_published_help', 'Make this announcement available to its selected audience. Leave unchecked to save as a draft.')); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="ann-preview">
            <div class="ann-preview-label"><?php echo h(a('preview_label', 'Preview')); ?></div>
            <div class="dt-announcements" id="annPreviewBox"></div>
        </div>

        <div class="ann-form-actions">
            <button type="button" class="ann-btn" id="btnSave"><?php echo h(a('btn_save', 'Save')); ?></button>
            <button type="button" class="ann-btn ann-btn-secondary" id="btnCancel"><?php echo h(a('btn_cancel', 'Cancel')); ?></button>
        </div>
    </div>

    <div class="pa-card">
        <div class="ann-toolbar">
            <button type="button" class="ann-btn" id="btnNew"><?php echo h(a('btn_new', '+ New Announcement')); ?></button>
        </div>
        <div class="ann-table-wrap">
            <table class="ann-table" id="annTable">
                <thead>
                    <tr>
                        <th><?php echo h(a('col_announcement', 'Announcement')); ?></th>
                        <th><?php echo h(a('col_type', 'Type')); ?></th>
                        <th><?php echo h(a('col_audience', 'Audience')); ?></th>
                        <th><?php echo h(a('col_window', 'Display window')); ?></th>
                        <th><?php echo h(a('col_status', 'Status')); ?></th>
                        <th><?php echo h(a('col_enabled', 'Enabled')); ?></th>
                        <th><?php echo h(a('col_actions', 'Actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="annTbody"></tbody>
            </table>
            <div class="ann-empty" id="annEmpty" hidden><?php echo h(a('empty', 'No announcements yet.')); ?></div>
        </div>
    </div>
</div>

<script>
var ANN_I18N = <?= json_encode([
    'newTitle' => a('new_title', 'New announcement'),
    'editTitle' => a('edit_title', 'Edit announcement'),
    'previewPlaceholder' => a('preview_placeholder', '(preview appears here)'),
    'dismiss' => t('announcements.dismiss'),
    'audiencePrefix' => a('col_audience', 'Audience') . ': ',
    'visibleToPrefix' => a('visible_to_prefix', 'Visible to: '),
    'typeInfo' => a('type_info', 'Information'),
    'typeWarning' => a('type_warning', 'Warning'),
    'typeCritical' => a('type_critical', 'Critical'),
    'typeSuccess' => a('type_success', 'Success'),
    'audAll' => a('aud_all', 'All Practices'),
    'audFabg' => a('aud_fabg', 'FABG Practices Only'),
    'visAll' => a('vis_all', 'All Users'),
    'visAdmin' => a('vis_admin', 'Practice Administrators Only'),
    'stDraft' => a('status_draft', 'Draft'),
    'stScheduled' => a('status_scheduled', 'Scheduled'),
    'stActive' => a('status_active', 'Active'),
    'stExpired' => a('status_expired', 'Expired'),
    'stDisabled' => a('status_disabled', 'Disabled'),
    'btnEdit' => a('btn_edit', 'Edit'),
    'btnDuplicate' => a('btn_duplicate', 'Duplicate'),
    'btnDelete' => a('btn_delete', 'Delete'),
    'btnEnable' => a('btn_enable', 'Enable'),
    'btnDisable' => a('btn_disable', 'Disable'),
    'btnPublish' => a('btn_publish', 'Publish'),
    'btnUnpublish' => a('btn_unpublish', 'Unpublish'),
    'msgSaved' => a('msg_saved', 'Saved.'),
    'msgDuplicated' => a('msg_duplicated', 'Duplicated as draft.'),
    'msgDeleted' => a('msg_deleted', 'Deleted.'),
    'msgUpdated' => a('msg_updated', 'Updated.'),
    'loadFailed' => a('msg_failed', 'Request failed'),
    'confirmDelete' => a('confirm_delete', 'Delete this announcement? This cannot be undone.'),
    'overlapMsg' => a('overlap_msg', "This announcement overlaps with '{title}', which is scheduled from {window}. Please choose a different display period."),
    'untitled' => a('untitled', '(untitled)'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
(function () {
    var API = 'api/announcement-admin.php';
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var ICONS = {
        info: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>',
        warning: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
        critical: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
        success: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
    };
    var TYPE_LABELS = { info: ANN_I18N.typeInfo, warning: ANN_I18N.typeWarning, critical: ANN_I18N.typeCritical, success: ANN_I18N.typeSuccess };
    var AUD_LABELS = { all: ANN_I18N.audAll, fabg: ANN_I18N.audFabg };
    var VIS_LABELS = { all: ANN_I18N.visAll, admin: ANN_I18N.visAdmin };
    var STATUS_LABELS = { draft: ANN_I18N.stDraft, scheduled: ANN_I18N.stScheduled, active: ANN_I18N.stActive, expired: ANN_I18N.stExpired, disabled: ANN_I18N.stDisabled };
    var editingId = null;
    var annRows = [];

    // ---- Schedule overlap (client-side pre-check; server is authoritative) ----
    var TZ_LABELS = {
        'America/New_York': 'Eastern Time', 'America/Chicago': 'Central Time',
        'America/Denver': 'Mountain Time', 'America/Phoenix': 'Arizona Time',
        'America/Los_Angeles': 'Pacific Time', 'America/Anchorage': 'Alaska Time',
        'Pacific/Honolulu': 'Hawaii Time', 'UTC': 'UTC'
    };

    function utcParse(s) {
        var d = new Date(String(s || '').replace(' ', 'T') + 'Z');
        return isNaN(d) ? null : d;
    }

    function tzOffsetMs(utcDate, tz) {
        var parts = new Intl.DateTimeFormat('en-US', {
            timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        }).formatToParts(utcDate);
        var m = {};
        parts.forEach(function (p) { m[p.type] = p.value; });
        var hour = m.hour === '24' ? 0 : parseInt(m.hour, 10);
        var asUTC = Date.UTC(+m.year, +m.month - 1, +m.day, hour, +m.minute, +m.second);
        return asUTC - Math.floor(utcDate.getTime() / 1000) * 1000;
    }

    function localToUtc(localStr, tz) {
        if (!localStr) return null;
        var guess = new Date(localStr.replace('T', ' ') + ':00Z');
        if (isNaN(guess)) return null;
        try {
            var off = tzOffsetMs(guess, tz);
            var utc = new Date(guess.getTime() - off);
            var off2 = tzOffsetMs(utc, tz);
            if (off2 !== off) utc = new Date(guess.getTime() - off2);
            return utc;
        } catch (e) { return null; }
    }

    function fmtWindowForConflict(a) {
        function part(utcStr, opts) {
            var d = utcParse(utcStr);
            if (!d) return '';
            try {
                var p = new Intl.DateTimeFormat('en-US', Object.assign({ timeZone: a.display_timezone || 'UTC' }, opts)).formatToParts(d);
                var m = {};
                p.forEach(function (x) { m[x.type] = x.value; });
                return m;
            } catch (e) { return {}; }
        }
        var s = part(a.display_start_utc, { month: 'long', day: 'numeric' });
        var e = part(a.display_end_utc, { month: 'long', day: 'numeric' });
        var st = part(a.display_start_utc, { hour: 'numeric', minute: '2-digit', hour12: true });
        var et = part(a.display_end_utc, { hour: 'numeric', minute: '2-digit', hour12: true });
        var t = function (m) { return (m.hour || '') + ':' + (m.minute || '') + ' ' + (m.dayPeriod || ''); };
        var tzLabel = TZ_LABELS[a.display_timezone] || a.display_timezone || 'UTC';
        return (s.month || '') + ' ' + (s.day || '') + ' at ' + t(st) + ' through '
            + (e.month || '') + ' ' + (e.day || '') + ' at ' + t(et) + ' ' + tzLabel;
    }

    function conflictMessage(conflict) {
        var title = (conflict.title || '').trim() || ANN_I18N.untitled;
        return ANN_I18N.overlapMsg
            .replace('{title}', title)
            .replace('{window}', fmtWindowForConflict(conflict));
    }

    /**
     * Return the first published+enabled, unexpired row whose UTC window
     * overlaps [newStartUtc, newEndUtc), excluding excludeId. Mirrors the
     * server-side rule (adjacent windows do not overlap).
     */
    function findClientConflict(newStartUtc, newEndUtc, excludeId) {
        var now = Date.now();
        for (var i = 0; i < annRows.length; i++) {
            var r = annRows[i];
            if (excludeId && r.id === excludeId) continue;
            if (!Number(r.published) || !Number(r.enabled)) continue;
            var rs = utcParse(r.display_start_utc), re = utcParse(r.display_end_utc);
            if (!rs || !re || re.getTime() <= now) continue;
            if (newStartUtc < re && newEndUtc > rs) return r;
        }
        return null;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function msg(text, ok) {
        var el = document.getElementById('annMsg');
        el.innerHTML = text ? '<div class="' + (ok ? 'ann-ok' : 'ann-error') + '">' + esc(text) + '</div>' : '';
    }

    function apiGet(action, params) {
        var qs = '?action=' + action + (params ? '&' + params : '');
        return fetch(API + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function apiPost(action, payload) {
        payload.csrf_token = csrf;
        return fetch(API + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    // ---- Preview ----
    function renderPreview() {
        var box = document.getElementById('annPreviewBox');
        var type = document.getElementById('fType').value;
        var title = document.getElementById('fTitle').value.trim();
        var message = document.getElementById('fMessage').value.trim();
        var audience = document.getElementById('fAudience').value;
        var visibleTo = document.getElementById('fVisibleTo').value;
        var start = document.getElementById('fStart').value;
        var end = document.getElementById('fEnd').value;
        var tz = document.getElementById('fTz').value;
        if (!message) {
            box.innerHTML = '<div class="dt-announcement dt-announcement-info" style="opacity:.6"><span aria-hidden="true">' + ICONS.info + '</span><div class="dt-announcement-body"><p class="dt-announcement-message">' + esc(ANN_I18N.previewPlaceholder) + '</p></div></div>';
            return;
        }
        var html = '<div class="dt-announcement dt-announcement-' + type + '" role="status">';
        html += '<span aria-hidden="true">' + ICONS[type] + '</span>';
        html += '<div class="dt-announcement-body">';
        if (title) html += '<p class="dt-announcement-title">' + esc(title) + '</p>';
        html += '<p class="dt-announcement-message">' + esc(message) + '</p>';
        html += '</div>';
        html += '<button type="button" class="dt-announcement-close" aria-label="' + esc(ANN_I18N.dismiss) + '" title="' + esc(ANN_I18N.dismiss) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>';
        html += '</div>';
        html += '<div class="ann-tz-note" style="margin-top:6px">' + esc(ANN_I18N.audiencePrefix) + esc(AUD_LABELS[audience] || audience)
            + ' &middot; ' + esc(ANN_I18N.visibleToPrefix) + esc(VIS_LABELS[visibleTo] || visibleTo)
            + (start && end ? ' &middot; ' + esc(start.replace('T', ' ')) + ' &rarr; ' + esc(end.replace('T', ' ')) + ' ' + esc(tz) : '') + '</div>';
        box.innerHTML = html;
    }

    ['fTitle', 'fMessage', 'fType', 'fAudience', 'fVisibleTo', 'fStart', 'fEnd', 'fTz'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', renderPreview);
        document.getElementById(id).addEventListener('change', renderPreview);
    });

    // ---- Table ----
    function fmtWindow(a) {
        function fmt(utc) {
            if (!utc) return '';
            var d = new Date(String(utc).replace(' ', 'T') + 'Z');
            if (isNaN(d)) return utc;
            try {
                return new Intl.DateTimeFormat(undefined, {
                    timeZone: a.display_timezone || 'UTC',
                    month: 'short', day: 'numeric', year: 'numeric',
                    hour: 'numeric', minute: '2-digit'
                }).format(d);
            } catch (e) { return d.toISOString(); }
        }
        return fmt(a.display_start_utc) + ' &rarr; ' + fmt(a.display_end_utc);
    }

    function load() {
        return apiGet('list').then(function (res) {
            if (!res.success) { msg(res.message || ANN_I18N.loadFailed, false); return; }
            var rows = res.announcements || [];
            annRows = rows;
            var tb = document.getElementById('annTbody');
            tb.innerHTML = '';
            document.getElementById('annEmpty').hidden = rows.length > 0;
            rows.forEach(function (a) {
                var tr = document.createElement('tr');
                var title = a.title ? esc(a.title) : '<em>' + esc(ANN_I18N.untitled) + '</em>';
                tr.innerHTML =
                    '<td><div class="ann-title">' + title + '</div><div class="ann-msg">' + esc(a.message) + '</div></td>' +
                    '<td><span class="ann-type" style="color:inherit">' + ICONS[a.type] + esc(TYPE_LABELS[a.type] || a.type) + '</span></td>' +
                    '<td>' + esc(AUD_LABELS[a.audience] || a.audience)
                        + '<div class="ann-msg">' + esc(VIS_LABELS[a.visible_to] || a.visible_to || 'all') + '</div></td>' +
                    '<td>' + fmtWindow(a) + '<div class="ann-msg">' + esc(a.display_timezone || 'UTC') + '</div></td>' +
                    '<td><span class="ann-pill ann-pill-' + esc(a.status) + '">' + esc(STATUS_LABELS[a.status] || a.status) + '</span></td>' +
                    '<td><button type="button" class="ann-link-btn" data-act="set_enabled" data-id="' + a.id + '" data-val="' + (a.enabled ? 0 : 1) + '">' + (a.enabled ? ANN_I18N.btnDisable : ANN_I18N.btnEnable) + '</button></td>' +
                    '<td><div class="ann-actions">' +
                        '<button type="button" class="ann-link-btn" data-act="edit" data-id="' + a.id + '">' + ANN_I18N.btnEdit + '</button>' +
                        '<button type="button" class="ann-link-btn" data-act="duplicate" data-id="' + a.id + '">' + ANN_I18N.btnDuplicate + '</button>' +
                        '<button type="button" class="ann-link-btn" data-act="set_published" data-id="' + a.id + '" data-val="' + (a.published ? 0 : 1) + '">' + (a.published ? ANN_I18N.btnUnpublish : ANN_I18N.btnPublish) + '</button>' +
                        '<button type="button" class="ann-link-btn danger" data-act="delete" data-id="' + a.id + '">' + ANN_I18N.btnDelete + '</button>' +
                    '</div></td>';
                tb.appendChild(tr);
            });
        }).catch(function () { msg(ANN_I18N.loadFailed, false); });
    }

    // ---- Editor ----
    function openEditor() {
        document.getElementById('annEditorCard').hidden = false;
        document.getElementById('annEditorCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function closeEditor() {
        document.getElementById('annEditorCard').hidden = true;
        editingId = null;
        document.getElementById('annEditorTitle').textContent = ANN_I18N.newTitle;
        ['fTitle','fMessage','fStart','fEnd'].forEach(function (id) { document.getElementById(id).value = ''; });
        document.getElementById('fType').value = 'info';
        document.getElementById('fAudience').value = 'all';
        document.getElementById('fVisibleTo').value = 'all';
        document.getElementById('fTz').value = 'America/New_York';
        document.getElementById('fEnabled').checked = true;
        document.getElementById('fPublished').checked = false;
        renderPreview();
    }

    function utcToLocalInput(utc, tz) {
        if (!utc) return '';
        var d = new Date(String(utc).replace(' ', 'T') + 'Z');
        if (isNaN(d)) return '';
        try {
            var parts = new Intl.DateTimeFormat('en-CA', {
                timeZone: tz || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', hour12: false
            }).formatToParts(d);
            var m = {};
            parts.forEach(function (p) { m[p.type] = p.value; });
            var hh = m.hour === '24' ? '00' : m.hour;
            return m.year + '-' + m.month + '-' + m.day + 'T' + hh + ':' + m.minute;
        } catch (e) { return ''; }
    }

    function edit(id) {
        apiGet('get', 'id=' + id).then(function (res) {
            if (!res.success || !res.announcement) { msg(res.message || ANN_I18N.loadFailed, false); return; }
            var a = res.announcement;
            editingId = a.id;
            document.getElementById('annEditorTitle').textContent = ANN_I18N.editTitle;
            document.getElementById('fTitle').value = a.title || '';
            document.getElementById('fMessage').value = a.message || '';
            document.getElementById('fType').value = a.type;
            document.getElementById('fAudience').value = a.audience;
            document.getElementById('fVisibleTo').value = a.visible_to || 'all';
            document.getElementById('fTz').value = a.display_timezone || 'UTC';
            document.getElementById('fStart').value = utcToLocalInput(a.display_start_utc, a.display_timezone);
            document.getElementById('fEnd').value = utcToLocalInput(a.display_end_utc, a.display_timezone);
            document.getElementById('fEnabled').checked = !!Number(a.enabled);
            document.getElementById('fPublished').checked = !!Number(a.published);
            openEditor();
            renderPreview();
        });
    }

    function save() {
        var payload = {
            title: document.getElementById('fTitle').value.trim(),
            message: document.getElementById('fMessage').value.trim(),
            type: document.getElementById('fType').value,
            audience: document.getElementById('fAudience').value,
            visible_to: document.getElementById('fVisibleTo').value,
            display_start: document.getElementById('fStart').value,
            display_end: document.getElementById('fEnd').value,
            display_timezone: document.getElementById('fTz').value,
            enabled: document.getElementById('fEnabled').checked ? 1 : 0,
            published: document.getElementById('fPublished').checked ? 1 : 0
        };
        var action = editingId ? 'update' : 'create';
        if (editingId) payload.id = editingId;
        // Client-side schedule pre-check (published+enabled only); the
        // server re-validates authoritatively under a write lock.
        if (payload.published && payload.enabled && payload.display_start && payload.display_end) {
            var tz = payload.display_timezone || 'UTC';
            var ns = localToUtc(payload.display_start, tz);
            var ne = localToUtc(payload.display_end, tz);
            if (ns && ne) {
                var cf = findClientConflict(ns, ne, editingId);
                if (cf) { msg(conflictMessage(cf), false); return; }
            }
        }
        apiPost(action, payload).then(function (res) {
            if (res.success) {
                msg(ANN_I18N.msgSaved, true);
                closeEditor();
                load();
            } else {
                msg(res.message || ANN_I18N.loadFailed, false);
            }
        }).catch(function () { msg(ANN_I18N.loadFailed, false); });
    }

    // ---- Wiring ----
    document.getElementById('btnNew').addEventListener('click', function () { closeEditor(); openEditor(); });
    document.getElementById('btnCancel').addEventListener('click', closeEditor);
    document.getElementById('btnSave').addEventListener('click', save);

    document.getElementById('annTbody').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) return;
        var act = btn.dataset.act;
        var id = parseInt(btn.dataset.id, 10);
        if (act === 'edit') { edit(id); return; }
        if (act === 'duplicate') {
            apiPost('duplicate', { id: id }).then(function (res) {
                msg(res.success ? ANN_I18N.msgDuplicated : (res.message || ANN_I18N.loadFailed), res.success);
                load();
            });
            return;
        }
        if (act === 'delete') {
            if (!confirm(ANN_I18N.confirmDelete)) return;
            apiPost('delete', { id: id }).then(function (res) {
                msg(res.success ? ANN_I18N.msgDeleted : (res.message || ANN_I18N.loadFailed), res.success);
                load();
            });
            return;
        }
        if (act === 'set_enabled' || act === 'set_published') {
            var val = parseInt(btn.dataset.val, 10);
            // Pre-check enabling/publishing against the schedule rule.
            if (val === 1) {
                var row = null;
                for (var i = 0; i < annRows.length; i++) { if (annRows[i].id === id) { row = annRows[i]; break; } }
                if (row) {
                    var effEnabled = act === 'set_enabled' ? 1 : Number(row.enabled);
                    var effPublished = act === 'set_published' ? 1 : Number(row.published);
                    var rs = utcParse(row.display_start_utc), re = utcParse(row.display_end_utc);
                    if (effEnabled && effPublished && rs && re && re.getTime() > Date.now()) {
                        var cf2 = findClientConflict(rs, re, id);
                        if (cf2) { msg(conflictMessage(cf2), false); load(); return; }
                    }
                }
            }
            var payload = { id: id, value: val };
            apiPost(act, payload).then(function (res) {
                msg(res.success ? ANN_I18N.msgUpdated : (res.message || ANN_I18N.loadFailed), res.success);
                load();
            });
        }
    });

    load();
    renderPreview();
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
