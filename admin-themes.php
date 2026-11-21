<?php
/**
 * Seasonal Themes - Platform Administration
 *
 * Manage login-page seasonal decorations: global toggle, automatic
 * scheduling vs manual theme, holiday windows (fixed or Easter-relative
 * dates, America/New_York), per-theme previews, and artwork overrides.
 * Changes persist in platform_settings and apply immediately - no
 * deployment needed.
 *
 * Access: super user, or the local development environment (same rule
 * as the other administration pages). Unauthorized users are redirected.
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
require_once __DIR__ . '/api/seasonal-themes.php';
require_once __DIR__ . '/admin-layout.php';

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function u($k, $params = []) { return t('admin_themes.' . $k, $params); }
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title><?php echo h(u('title')); ?> - <?php echo h($appConfig['appName']); ?></title>
    <?php require __DIR__ . '/partials/brand-icons.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= appAssetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= appAssetUrl('css/admin.css') ?>">
    <style>
        .st-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:10px; }
        .st-field { display:flex; flex-direction:column; gap:4px; font-size:.8rem; color:#4b5563; }
        .st-field input, .st-field select { padding:6px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; }
        .st-table { width:100%; border-collapse:collapse; font-size:.82rem; }
        .st-table th { text-align:left; padding:6px 8px; border-bottom:1px solid #e5e7eb; color:#6b7280; font-weight:600; }
        .st-table td { padding:8px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .st-table input, .st-table select { padding:4px 6px; border:1px solid #d1d5db; border-radius:5px; font-size:.8rem; }
        .st-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.72rem; font-weight:600; }
        .st-badge.on { background:#dcfce7; color:#166534; }
        .st-badge.off { background:#f3f4f6; color:#6b7280; }
        .st-badge.live { background:#dbeafe; color:#1d4ed8; }
        .st-btn { padding:7px 14px; border-radius:7px; border:1px solid #d1d5db; background:#fff; font-size:.82rem; cursor:pointer; }
        .st-btn.primary { background:#1e40af; border-color:#1e40af; color:#fff; }
        .st-btn.danger { color:#b91c1c; border-color:#fecaca; }
        .st-btn:disabled { opacity:.5; cursor:default; }
        .st-msg { font-size:.82rem; margin-top:8px; min-height:1em; }
        .st-msg.err { color:#b91c1c; } .st-msg.ok { color:#166534; }
        .st-thumb { width:44px; height:44px; object-fit:contain; background:#f9fafb; border-radius:6px; border:1px solid #e5e7eb; }
        .st-deco-row { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:.78rem; color:#4b5563; }
        .st-deco-row .st-deco-name { flex:1; font-family:ui-monospace,monospace; }
        details.st-theme { border:1px solid #e5e7eb; border-radius:8px; margin-bottom:10px; }
        details.st-theme summary { padding:10px 14px; cursor:pointer; font-weight:600; font-size:.88rem; list-style:none; }
        details.st-theme summary::before { content:'▸ '; color:#9ca3af; }
        details.st-theme[open] summary::before { content:'▾ '; }
        details.st-theme .st-theme-body { padding:0 14px 14px; }
        .st-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .st-check { display:flex; align-items:center; gap:6px; font-size:.85rem; }
    </style>
</head>
<?php paAdminChrome('themes', u('title'), u('subtitle')); ?>

<div class="pa-card">
    <h2><?php echo h(u('status_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(u('status_sub')); ?></p>
    <div id="stStatus" class="st-row">
        <span class="st-badge off"><?php echo h(u('loading')); ?></span>
    </div>
</div>

<div class="pa-card">
    <h2><?php echo h(u('settings_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(u('settings_sub')); ?></p>
    <div class="st-grid" style="margin-bottom:14px;">
        <label class="st-check"><input type="checkbox" id="stEnabled"> <?php echo h(u('enabled_label')); ?></label>
        <div class="st-field" style="max-width:280px;">
            <span><?php echo h(u('mode_label')); ?></span>
            <select id="stMode">
                <option value="auto"><?php echo h(u('mode_auto')); ?></option>
                <option value="manual"><?php echo h(u('mode_manual')); ?></option>
            </select>
        </div>
        <div class="st-field" id="stManualWrap" style="max-width:280px;">
            <span><?php echo h(u('manual_theme')); ?></span>
            <select id="stManualTheme"></select>
        </div>
    </div>
    <button class="st-btn primary" id="stSaveBtn"><?php echo h(u('save')); ?></button>
    <div class="st-msg" id="stSaveMsg"></div>
</div>

<div class="pa-card">
    <h2><?php echo h(u('holidays_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(u('holidays_sub')); ?></p>
    <div style="overflow-x:auto;">
    <table class="st-table">
        <thead>
            <tr>
                <th><?php echo h(u('col_name')); ?></th>
                <th><?php echo h(u('col_theme')); ?></th>
                <th><?php echo h(u('col_start')); ?></th>
                <th><?php echo h(u('col_end')); ?></th>
                <th><?php echo h(u('col_window')); ?></th>
                <th><?php echo h(u('col_on')); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="stHolidayRows"></tbody>
    </table>
    </div>
    <div class="st-row" style="margin-top:12px;">
        <button class="st-btn" id="stAddHoliday"><?php echo h(u('add_holiday')); ?></button>
        <button class="st-btn primary" id="stSaveHolidays"><?php echo h(u('save_holidays')); ?></button>
        <div class="st-msg" id="stHolidayMsg"></div>
    </div>
</div>

<div class="pa-card">
    <h2><?php echo h(u('themes_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(u('themes_sub')); ?></p>
    <div id="stThemes"></div>
</div>

<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var STR = <?php echo json_encode([
        'load_failed' => u('load_failed'),
        'saved' => u('saved'),
        'save_failed' => u('save_failed'),
        'active_now' => u('active_now'),
        'standard_bg' => u('standard_bg'),
        'none_active' => u('none_active'),
        'preview' => u('preview'),
        'replace_art' => u('replace_art'),
        'reset_art' => u('reset_art'),
        'art_replaced' => u('art_replaced'),
        'art_reset' => u('art_reset'),
        'delete' => u('delete'),
        'kind_fixed' => u('kind_fixed'),
        'kind_easter' => u('kind_easter'),
        'easter_offset' => u('easter_offset'),
        'confirm_delete' => u('confirm_delete'),
    ], JSON_UNESCAPED_UNICODE); ?>;

    var state = null;
    var holidays = [];

    function apiGet() {
        return fetch('api/seasonal-admin.php?action=state', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }
    function apiPost(action, body, isForm) {
        var opts = { method: 'POST', credentials: 'same-origin' };
        if (isForm) {
            opts.body = body; // FormData includes csrf_token field
        } else {
            opts.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf };
            opts.body = JSON.stringify(Object.assign({ action: action, csrf_token: csrf }, body));
        }
        if (!isForm) opts.headers['X-CSRF-Token'] = csrf;
        return fetch('api/seasonal-admin.php?action=' + action, opts).then(function (r) { return r.json(); });
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function msg(el, text, cls) { el.textContent = text || ''; el.className = 'st-msg ' + (cls || ''); }

    function ruleEditor(prefix, rule) {
        rule = rule || { kind: 'fixed', m: 1, d: 1 };
        var kind = rule.kind === 'easter' ? 'easter' : 'fixed';
        var html = '<select class="' + prefix + '-kind" data-prefix="' + prefix + '">'
            + '<option value="fixed"' + (kind === 'fixed' ? ' selected' : '') + '>' + esc(STR.kind_fixed) + '</option>'
            + '<option value="easter"' + (kind === 'easter' ? ' selected' : '') + '>' + esc(STR.kind_easter) + '</option></select> '
            + '<span class="' + prefix + '-fixed"' + (kind === 'easter' ? ' style="display:none"' : '') + '>'
            + '<select class="' + prefix + '-m">';
        for (var m = 1; m <= 12; m++) html += '<option value="' + m + '"' + (m === (rule.m || 1) ? ' selected' : '') + '>' + m + '</option>';
        html += '</select>/ <select class="' + prefix + '-d">';
        for (var d = 1; d <= 31; d++) html += '<option value="' + d + '"' + (d === (rule.d || 1) ? ' selected' : '') + '>' + d + '</option>';
        html += '</select></span>'
            + '<span class="' + prefix + '-easter"' + (kind === 'fixed' ? ' style="display:none"' : '') + '>'
            + '<input type="number" class="' + prefix + '-off" value="' + (rule.offset || 0) + '" min="-60" max="60" style="width:70px"> ' + esc(STR.easter_offset) + '</span>';
        return html;
    }

    function readRule(prefix) {
        var row = document.querySelector('[data-rule="' + prefix + '"]');
        if (!row) return null;
        if (row.querySelector('.' + prefix + '-kind').value === 'easter') {
            return { kind: 'easter', offset: parseInt(row.querySelector('.' + prefix + '-off').value, 10) || 0 };
        }
        return { kind: 'fixed', m: parseInt(row.querySelector('.' + prefix + '-m').value, 10), d: parseInt(row.querySelector('.' + prefix + '-d').value, 10) };
    }

    function renderStatus() {
        var el = document.getElementById('stStatus');
        var cfg = state.config;
        var active = state.active;
        var html = '';
        html += '<span class="st-badge ' + (cfg.enabled ? 'on' : 'off') + '">' + (cfg.enabled ? 'ON' : 'OFF') + '</span>';
        html += '<span class="st-badge off">' + esc(cfg.mode === 'manual' ? 'manual' : 'auto') + '</span>';
        if (active) {
            html += '<span class="st-badge live">' + esc(STR.active_now) + ': ' + esc(active.name) + ' (' + esc(active.source) + ')</span>';
        } else {
            html += '<span class="st-badge off">' + esc(STR.none_active) + ' — ' + esc(STR.standard_bg) + '</span>';
        }
        el.innerHTML = html;
        document.getElementById('stEnabled').checked = !!cfg.enabled;
        document.getElementById('stMode').value = cfg.mode || 'auto';
        var mt = document.getElementById('stManualTheme');
        mt.innerHTML = state.themes.map(function (t) {
            return '<option value="' + esc(t.id) + '"' + (t.id === cfg.manual_theme ? ' selected' : '') + '>' + esc(t.name) + '</option>';
        }).join('');
        document.getElementById('stManualWrap').style.display = (cfg.mode === 'manual') ? '' : 'none';
    }

    function renderHolidays() {
        var tb = document.getElementById('stHolidayRows');
        tb.innerHTML = holidays.map(function (h, i) {
            var themeOpts = state.themes.map(function (t) {
                return '<option value="' + esc(t.id) + '"' + (t.id === h.theme ? ' selected' : '') + '>' + esc(t.name) + '</option>';
            }).join('');
            return '<tr data-h="' + i + '">'
                + '<td><input type="text" class="h-name" value="' + esc(h.name) + '" style="width:150px">'
                + '<input type="hidden" class="h-id" value="' + esc(h.id) + '"></td>'
                + '<td><select class="h-theme">' + themeOpts + '</select></td>'
                + '<td data-rule="h' + i + 's">' + ruleEditor('h' + i + 's', h.start) + '</td>'
                + '<td data-rule="h' + i + 'e">' + ruleEditor('h' + i + 'e', h.end) + '</td>'
                + '<td style="white-space:nowrap">' + esc(h.window || '') + (h.active_today ? ' <span class="st-badge live">' + esc(STR.active_now) + '</span>' : '') + '</td>'
                + '<td><input type="checkbox" class="h-enabled"' + (h.enabled ? ' checked' : '') + '></td>'
                + '<td><button class="st-btn danger h-del" data-i="' + i + '">' + esc(STR.delete) + '</button></td>'
                + '</tr>';
        }).join('');
        tb.querySelectorAll('.h-del').forEach(function (b) {
            b.addEventListener('click', function () {
                var i = parseInt(b.getAttribute('data-i'), 10);
                if (confirm(STR.confirm_delete.replace('{name}', holidays[i].name || ''))) {
                    holidays.splice(i, 1);
                    renderHolidays();
                }
            });
        });
        tb.querySelectorAll('.h-kind, .h-kind'.replace('h-kind', '')).forEach(function () {});
        // wire kind selects
        tb.querySelectorAll('select[class$="-kind"]').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var p = sel.getAttribute('data-prefix');
                var isEaster = sel.value === 'easter';
                var fr = document.querySelector('.' + p + '-fixed');
                var ea = document.querySelector('.' + p + '-easter');
                if (fr) fr.style.display = isEaster ? 'none' : '';
                if (ea) ea.style.display = isEaster ? '' : 'none';
            });
        });
    }

    function collectHolidays() {
        return Array.prototype.map.call(document.querySelectorAll('#stHolidayRows tr'), function (tr, i) {
            return {
                id: tr.querySelector('.h-id').value,
                name: tr.querySelector('.h-name').value,
                theme: tr.querySelector('.h-theme').value,
                enabled: tr.querySelector('.h-enabled').checked,
                start: readRule('h' + i + 's'),
                end: readRule('h' + i + 'e'),
            };
        });
    }

    function renderThemes() {
        var wrap = document.getElementById('stThemes');
        wrap.innerHTML = (state.themes_detail || []).map(function (t) {
            var body = (t.decorations || []).map(function (d) {
                var overridden = (state.config.asset_overrides || []).indexOf(t.id + '/' + d.asset) !== -1;
                return '<div class="st-deco-row">'
                    + '<img class="st-thumb" src="' + esc(d.url) + '" alt="">'
                    + '<span class="st-deco-name">' + esc(d.asset) + '</span>'
                    + '<input type="file" accept=".svg,.png,.webp,image/svg+xml,image/png,image/webp" data-theme="' + esc(t.id) + '" data-slot="' + esc(d.asset) + '" class="st-file" style="font-size:.75rem;max-width:220px">'
                    + (overridden ? '<button class="st-btn st-reset" data-key="' + esc(t.id + '/' + d.asset) + '">' + esc(STR.reset_art) + '</button>' : '')
                    + '<span class="st-msg" data-for="' + esc(t.id + '/' + d.asset) + '"></span>'
                    + '</div>';
            }).join('');
            return '<details class="st-theme"' + (t.id === 'thanksgiving' ? ' open' : '') + '>'
                + '<summary>' + esc(t.name) + ' <a href="' + esc(state.preview_base) + esc(t.id) + '" target="_blank" rel="noopener" style="font-size:.75rem;font-weight:400">' + esc(STR.preview) + '</a></summary>'
                + '<div class="st-theme-body">' + body + '</div>'
                + '</details>';
        }).join('');

        wrap.querySelectorAll('.st-file').forEach(function (inp) {
            inp.addEventListener('change', function () {
                if (!inp.files || !inp.files[0]) return;
                var key = inp.getAttribute('data-theme') + '/' + inp.getAttribute('data-slot');
                var fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('theme', inp.getAttribute('data-theme'));
                fd.append('slot', inp.getAttribute('data-slot'));
                fd.append('file', inp.files[0]);
                var m = wrap.querySelector('[data-for="' + key + '"]');
                if (m) msg(m, '…');
                fetch('api/seasonal-admin.php?action=upload', { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success) {
                            if (m) msg(m, STR.art_replaced, 'ok');
                            state.config.asset_overrides.push(key);
                            inp.previousElementSibling; // noop
                            var row = inp.closest('.st-deco-row');
                            if (row && !row.querySelector('.st-reset')) {
                                var b = document.createElement('button');
                                b.className = 'st-btn st-reset'; b.setAttribute('data-key', key); b.textContent = STR.reset_art;
                                b.addEventListener('click', resetAsset);
                                row.insertBefore(b, m);
                            }
                        } else if (m) { msg(m, (d && d.message) || 'error', 'err'); }
                    })
                    .catch(function () { if (m) msg(m, 'error', 'err'); });
            });
        });
        wrap.querySelectorAll('.st-reset').forEach(function (b) { b.addEventListener('click', resetAsset); });
    }

    function resetAsset(ev) {
        var key = ev.currentTarget.getAttribute('data-key');
        apiPost('reset_asset', { key: key }).then(function (d) {
            if (d && d.success) { load(); }
        });
    }

    function load() {
        apiGet().then(function (d) {
            if (!d || !d.success) { document.getElementById('stStatus').innerHTML = '<span class="st-badge off">' + esc(STR.load_failed) + '</span>'; return; }
            state = d;
            holidays = JSON.parse(JSON.stringify(d.holidays));
            // holidays rows need rule objects for editors
            holidays.forEach(function (h) { h._start = h.start; h._end = h.end; });
            // state payload includes themes_detail from server? build here
            state.themes_detail = (d.themes_detail || d.themes || []).map(function (t) {
                if (typeof t === 'string') return t;
                return t;
            });
            renderStatus();
            renderHolidays();
            renderThemes();
        });
    }

    document.getElementById('stMode').addEventListener('change', function () {
        document.getElementById('stManualWrap').style.display = this.value === 'manual' ? '' : 'none';
    });

    document.getElementById('stSaveBtn').addEventListener('click', function () {
        var btn = this; btn.disabled = true;
        apiPost('save', {
            enabled: document.getElementById('stEnabled').checked,
            mode: document.getElementById('stMode').value,
            manual_theme: document.getElementById('stManualTheme').value,
            holidays: holidays.map(function (h) {
                return { id: h.id, name: h.name, theme: h.theme, enabled: h.enabled, start: h.start, end: h.end };
            })
        }).then(function (d) {
            btn.disabled = false;
            msg(document.getElementById('stSaveMsg'), d && d.success ? STR.saved : ((d && d.message) || STR.save_failed), d && d.success ? 'ok' : 'err');
            if (d && d.success) load();
        }).catch(function () { btn.disabled = false; msg(document.getElementById('stSaveMsg'), STR.save_failed, 'err'); });
    });

    document.getElementById('stSaveHolidays').addEventListener('click', function () {
        var btn = this; btn.disabled = true;
        var rows = collectHolidays();
        // merge collected rows back into holidays array order
        holidays = rows.map(function (r, i) {
            return { id: r.id || ('h' + Date.now() + i), name: r.name, theme: r.theme, enabled: r.enabled, start: r.start, end: r.end };
        });
        // save whole config via save action using current settings values
        apiPost('save', {
            enabled: document.getElementById('stEnabled').checked,
            mode: document.getElementById('stMode').value,
            manual_theme: document.getElementById('stManualTheme').value,
            holidays: holidays
        }).then(function (d) {
            btn.disabled = false;
            msg(document.getElementById('stHolidayMsg'), d && d.success ? STR.saved : ((d && d.message) || STR.save_failed), d && d.success ? 'ok' : 'err');
            if (d && d.success) load();
        }).catch(function () { btn.disabled = false; msg(document.getElementById('stHolidayMsg'), STR.save_failed, 'err'); });
    });

    document.getElementById('stAddHoliday').addEventListener('click', function () {
        holidays.push({
            id: 'custom_' + Date.now(), name: 'New holiday', theme: 'thanksgiving',
            enabled: true, start: { kind: 'fixed', m: 12, d: 1 }, end: { kind: 'fixed', m: 12, d: 31 }
        });
        renderHolidays();
    });

    load();
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
