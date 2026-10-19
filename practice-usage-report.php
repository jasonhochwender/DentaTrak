<?php
/**
 * Dev Tools > Practice Usage Report
 *
 * Internal-only snapshot of case counts and cached file-storage
 * measurements across all practices.
 *
 * - Case counts are computed live from cases_cache (the canonical case
 *   record; deleted cases are not present).
 * - Storage totals come exclusively from the cached
 *   practice_storage_usage rows produced by the shared
 *   practice-storage-usage.php calculation - this page never scans
 *   storage or enumerates buckets.
 *
 * Access: same model as admin-practices.php - super users, or the local
 * development environment. Customer practice admins are redirected.
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

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v); }
function ur($k) { return h(t('usage_report.' . $k)); }
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title><?php echo ur('title'); ?> - <?php echo h($appConfig['appName']); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= appAssetUrl('css/app.css') ?>">
    <style>
        body { font-family: 'Poppins','Inter','Noto Sans KR',sans-serif; background: #f3f4f6; margin: 0; padding: 20px; }
        .admin-container { max-width: 1400px; margin: 0 auto; }
        .admin-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
        .admin-header h1 { font-size: 1.5rem; margin: 0; }
        .admin-header .subtitle { font-size: 0.85rem; color: #6b7280; margin-top: 4px; }
        .back-link { color: #6366f1; text-decoration: none; font-size: 0.9rem; white-space: nowrap; }
        .filter-bar { background: #fff; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-bar input, .filter-bar select { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; }
        .filter-bar input { flex: 1 1 220px; min-width: 0; }
        .included-note { font-size: 0.8rem; color: #6b7280; }
        .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .metric-card { background: #fff; border-radius: 8px; padding: 14px; }
        .metric-card .m-val { font-size: 1.4rem; font-weight: 600; }
        .metric-card .m-label { font-size: 0.78rem; color: #6b7280; margin-top: 2px; }
        .metric-card .m-note { font-size: 0.72rem; color: #b45309; margin-top: 4px; }
        .panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .panel { background: #fff; border-radius: 8px; padding: 16px; }
        .panel h3 { margin: 0 0 4px; font-size: 0.95rem; }
        .panel .chart-note { font-size: 0.75rem; color: #6b7280; margin: 0 0 8px; }
        .chart-wrap { position: relative; }
        .usage-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; background: #fff; border-radius: 8px; overflow: hidden; }
        .usage-table th { text-align: left; padding: 10px 12px; background: #f9fafb; font-weight: 600; color: #374151; white-space: nowrap; }
        .usage-table th button { background: none; border: none; padding: 0; font: inherit; color: inherit; cursor: pointer; }
        .usage-table th button .sort-ind { color: #6366f1; font-size: 0.7rem; margin-left: 4px; }
        .usage-table td { padding: 8px 12px; border-top: 1px solid #f3f4f6; }
        .usage-table td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .usage-table th.num { text-align: right; }
        .practice-link { color: #6366f1; text-decoration: none; }
        .practice-link:hover { text-decoration: underline; }
        .demo-badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 0.68rem; background: #fef3c7; color: #92400e; margin-left: 6px; vertical-align: middle; }
        .status-pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 0.72rem; white-space: nowrap; }
        .status-pill.measured { background: #d1fae5; color: #065f46; }
        .status-pill.partial { background: #fef3c7; color: #92400e; }
        .status-pill.uncalculated { background: #f3f4f6; color: #6b7280; }
        .status-pill.refreshing { background: #dbeafe; color: #1e40af; }
        .status-pill.error { background: #fee2e2; color: #991b1b; }
        .empty-note { background: #fff; border-radius: 8px; padding: 24px; text-align: center; color: #6b7280; margin-bottom: 16px; }
        .generated-note { font-size: 0.75rem; color: #9ca3af; margin-top: 16px; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        @media (max-width: 720px) {
            body { padding: 10px; }
            .usage-table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="admin-header">
        <div>
            <h1>📊 <?php echo ur('title'); ?></h1>
            <div class="subtitle"><?php echo ur('subtitle'); ?></div>
        </div>
        <a class="back-link" href="main.php">&larr; <?php echo ur('back'); ?></a>
    </div>

    <div class="filter-bar">
        <label for="urSearch" class="sr-only"><?php echo ur('search_placeholder'); ?></label>
        <input type="search" id="urSearch" placeholder="<?php echo ur('search_placeholder'); ?>">
        <label for="urDemoFilter" class="sr-only"><?php echo ur('filter_label'); ?></label>
        <select id="urDemoFilter">
            <option value="all"><?php echo ur('filter_all'); ?></option>
            <option value="exclude" selected><?php echo ur('filter_exclude'); ?></option>
            <option value="only"><?php echo ur('filter_only'); ?></option>
        </select>
        <span class="included-note" id="urIncludedNote"></span>
    </div>

    <div id="urError" class="empty-note" hidden><?php echo ur('load_failed'); ?></div>

    <div class="metric-grid" id="urSummary">
        <div class="metric-card"><div class="m-val" id="urMPractices">–</div><div class="m-label"><?php echo ur('m_practices'); ?></div></div>
        <div class="metric-card"><div class="m-val" id="urMActive">–</div><div class="m-label"><?php echo ur('m_active'); ?></div></div>
        <div class="metric-card"><div class="m-val" id="urMArchived">–</div><div class="m-label"><?php echo ur('m_archived'); ?></div></div>
        <div class="metric-card"><div class="m-val" id="urMTotal">–</div><div class="m-label"><?php echo ur('m_total'); ?></div></div>
        <div class="metric-card"><div class="m-val" id="urMStorage">–</div><div class="m-label"><?php echo ur('m_storage'); ?></div><div class="m-note" id="urMStorageNote"></div></div>
    </div>

    <div class="panels">
        <div class="panel">
            <h3 id="urCasesChartTitle"><?php echo ur('chart_cases'); ?></h3>
            <p class="chart-note"><?php echo ur('chart_note'); ?></p>
            <div class="chart-wrap" style="height: 340px;">
                <canvas id="urCasesChart" role="img" aria-labelledby="urCasesChartTitle urCasesChartDesc"></canvas>
            </div>
            <p id="urCasesChartDesc" class="sr-only"></p>
        </div>
        <div class="panel">
            <h3 id="urStorageChartTitle"><?php echo ur('chart_storage'); ?></h3>
            <p class="chart-note"><?php echo ur('chart_storage_note'); ?></p>
            <div class="chart-wrap" style="height: 340px;">
                <canvas id="urStorageChart" role="img" aria-labelledby="urStorageChartTitle urStorageChartDesc"></canvas>
            </div>
            <p id="urStorageChartDesc" class="sr-only"></p>
        </div>
    </div>

    <table class="usage-table" id="urTable">
        <thead>
            <tr id="urHeadRow"></tr>
        </thead>
        <tbody id="urTableBody"></tbody>
    </table>
    <div id="urEmpty" class="empty-note" hidden style="margin-top:16px;"><?php echo ur('empty'); ?></div>
    <div class="generated-note" id="urGenerated"></div>
</div>

<?php require_once __DIR__ . '/api/auth-timeout-script.php'; ?>
<script>
    window.csrfToken = <?php echo json_encode($csrfToken); ?>;
    const TOP_N = 10;

    const COLUMNS = [
        { key: 'name',            label: <?php echo json_encode(t('usage_report.c_practice')); ?>, numeric: false },
        { key: 'active_cases',    label: <?php echo json_encode(t('usage_report.c_active')); ?>, numeric: true },
        { key: 'archived_cases',  label: <?php echo json_encode(t('usage_report.c_archived')); ?>, numeric: true },
        { key: 'total_cases',     label: <?php echo json_encode(t('usage_report.c_total')); ?>, numeric: true },
        { key: 'storage',         label: <?php echo json_encode(t('usage_report.c_storage')); ?>, numeric: true },
        { key: 'status',          label: <?php echo json_encode(t('usage_report.c_storage_status')); ?>, numeric: false },
        { key: 'calculated',      label: <?php echo json_encode(t('usage_report.c_calculated')); ?>, numeric: true },
    ];
    const STATUS_LABELS = {
        measured: <?php echo json_encode(t('usage_report.status_measured')); ?>,
        partial: <?php echo json_encode(t('usage_report.status_partial')); ?>,
        uncalculated: <?php echo json_encode(t('usage_report.status_uncalculated')); ?>,
        refreshing: <?php echo json_encode(t('usage_report.status_refreshing')); ?>,
        error: <?php echo json_encode(t('usage_report.status_error')); ?>,
    };

    const state = {
        practices: [],
        search: '',
        demoFilter: 'exclude',
        sortKey: 'total_cases',
        sortDir: -1,
        charts: {},
        chartFailed: false,
    };

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let v = bytes, i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i];
    }

    function formatDateTime(s) {
        if (!s) return '—';
        // generated_at is UTC ('…Z'); DB datetimes are local server time.
        const str = String(s);
        const d = str.endsWith('Z') ? new Date(str) : new Date(str.replace(' ', 'T'));
        return isNaN(d.getTime()) ? s : d.toLocaleString();
    }

    function includedPractices() {
        return state.practices.filter(p => {
            if (state.demoFilter === 'exclude' && p.is_demo) return false;
            if (state.demoFilter === 'only' && !p.is_demo) return false;
            if (state.search && p.name.toLowerCase().indexOf(state.search) === -1) return false;
            return true;
        });
    }

    function sortValue(p, key) {
        switch (key) {
            case 'name': return p.name.toLowerCase();
            case 'active_cases': return p.active_cases;
            case 'archived_cases': return p.archived_cases;
            case 'total_cases': return p.total_cases;
            case 'storage': return p.storage.total_bytes === null ? null : p.storage.total_bytes;
            case 'status': return STATUS_LABELS[p.storage.status] || p.storage.status;
            case 'calculated': return p.storage.calculated_at;
        }
        return null;
    }

    function sortedRows(rows) {
        const k = state.sortKey, d = state.sortDir;
        return rows.slice().sort((a, b) => {
            let av = sortValue(a, k), bv = sortValue(b, k);
            if (av === null || av === undefined) return 1;
            if (bv === null || bv === undefined) return -1;
            if (typeof av === 'string') return av.localeCompare(bv) * d;
            return (av - bv) * d;
        });
    }

    function renderSummary(included) {
        let active = 0, archived = 0, total = 0, storageBytes = 0;
        let measured = 0, unknown = 0, partialish = 0;
        included.forEach(p => {
            active += p.active_cases;
            archived += p.archived_cases;
            total += p.total_cases;
            if (p.storage.total_bytes !== null) { storageBytes += p.storage.total_bytes; measured++; }
            else { unknown++; }
            if (p.storage.status === 'partial' || p.storage.status === 'error' || p.storage.status === 'refreshing') partialish++;
        });
        document.getElementById('urMPractices').textContent = included.length;
        document.getElementById('urMActive').textContent = active;
        document.getElementById('urMArchived').textContent = archived;
        document.getElementById('urMTotal').textContent = total;
        document.getElementById('urMStorage').textContent = unknown > 0
            ? formatBytes(storageBytes) + ' *'
            : formatBytes(storageBytes);
        const note = document.getElementById('urMStorageNote');
        if (included.length === 0) {
            note.textContent = '';
        } else if (unknown > 0 || partialish > 0) {
            note.textContent = <?php echo json_encode(t('usage_report.storage_coverage')); ?>
                .replace('{measured}', measured).replace('{total}', included.length);
        } else {
            note.textContent = <?php echo json_encode(t('usage_report.storage_complete')); ?>;
        }
    }

    function chartTop(rows, valueFn) {
        return rows
            .filter(p => valueFn(p) !== null)
            .sort((a, b) => valueFn(b) - valueFn(a))
            .slice(0, TOP_N);
    }

    function truncateLabel(s, n) {
        return s.length > n ? s.slice(0, n - 1) + '…' : s;
    }

    function showChartError() {
        document.querySelectorAll('.chart-wrap').forEach(w => {
            w.innerHTML = '<div class="empty-note" style="margin:0;">' + escapeHtml(<?php echo json_encode(t('usage_report.chart_error')); ?>) + '</div>';
        });
    }

    function renderChart(canvasId, descId, titleId, titleText, rows, valueFn, fmt, axisTpl, color) {
        const canvas = document.getElementById(canvasId);
        const top = chartTop(rows, valueFn);
        const titleEl = document.getElementById(titleId);
        if (rows.length > TOP_N) {
            titleEl.textContent = <?php echo json_encode(t('usage_report.chart_top_title')); ?>
                .replace('{title}', titleText).replace('{n}', TOP_N);
        } else {
            titleEl.textContent = titleText;
        }
        const desc = document.getElementById(descId);
        desc.textContent = <?php echo json_encode(t('usage_report.chart_desc')); ?>
            .replace('{list}', top.map(p => p.name + ': ' + fmt(valueFn(p))).join('; '));
        canvas.setAttribute('aria-label', desc.textContent);

        if (state.charts[canvasId]) { state.charts[canvasId].destroy(); }
        state.charts[canvasId] = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: top.map(p => truncateLabel(p.name, 28)),
                datasets: [{
                    label: titleText,
                    data: top.map(p => valueFn(p)),
                    backgroundColor: color + '99',
                    borderColor: color,
                    borderWidth: 1,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: items => top[items[0].dataIndex] ? top[items[0].dataIndex].name : '',
                            label: item => axisTpl.replace('{v}', fmt(top[item.dataIndex] ? valueFn(top[item.dataIndex]) : item.raw)),
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { callback: v => fmt(v) } },
                },
            },
        });
    }

    function renderCharts(included) {
        if (typeof Chart === 'undefined') {
            // chart.js loads deferred; wait for full page load once, then
            // either draw or show the fallback (the table holds all data).
            if (!window.__urChartDeferred) {
                window.__urChartDeferred = true;
                window.addEventListener('load', () => {
                    if (typeof Chart !== 'undefined') { renderAll(); } else { showChartError(); }
                }, { once: true });
            }
            return;
        }
        renderChart('urCasesChart', 'urCasesChartDesc', 'urCasesChartTitle', <?php echo json_encode(t('usage_report.chart_cases')); ?>,
            included, p => p.total_cases, v => v, <?php echo json_encode(t('usage_report.cases_axis')); ?>, '#6366f1');
        renderChart('urStorageChart', 'urStorageChartDesc', 'urStorageChartTitle', <?php echo json_encode(t('usage_report.chart_storage')); ?>,
            included.filter(p => p.storage.status === 'measured' || p.storage.status === 'partial'),
            p => p.storage.total_bytes, formatBytes, <?php echo json_encode(t('usage_report.storage_axis')); ?>, '#0ea5e9');
    }

    function renderTable(included) {
        const head = document.getElementById('urHeadRow');
        head.innerHTML = COLUMNS.map(c =>
            '<th' + (c.numeric ? ' class="num"' : '') + '>' +
            '<button type="button" data-key="' + c.key + '" aria-label="' +
                escapeHtml(<?php echo json_encode(t('usage_report.sort_aria')); ?>.replace('{column}', c.label)) + '">' +
            escapeHtml(c.label) +
            (state.sortKey === c.key ? '<span class="sort-ind">' + (state.sortDir === 1 ? '▲' : '▼') + '</span>' : '') +
            '</button></th>'
        ).join('');
        head.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                const k = btn.dataset.key;
                if (state.sortKey === k) { state.sortDir = -state.sortDir; }
                else { state.sortKey = k; state.sortDir = k === 'name' || k === 'status' ? 1 : -1; }
                renderAll();
            });
        });

        const rows = sortedRows(included);
        const body = document.getElementById('urTableBody');
        body.innerHTML = rows.map(p => {
            const s = p.storage;
            const stLabel = STATUS_LABELS[s.status] || s.status;
            const stTitle = s.status === 'error' && s.last_error ? ' title="' + escapeHtml(s.last_error) + '"' : '';
            return '<tr>' +
                '<td><a class="practice-link" href="admin-practices.php?practice_id=' + p.id + '">' + escapeHtml(p.name) + '</a>' +
                    (p.is_demo ? '<span class="demo-badge">' + escapeHtml(<?php echo json_encode(t('usage_report.demo_badge')); ?>) + '</span>' : '') +
                    (p.is_active ? '' : ' <span style="color:#9ca3af;font-size:0.72rem;">(' + escapeHtml(<?php echo json_encode(t('usage_report.inactive_tag')); ?>) + ')</span>') +
                '</td>' +
                '<td class="num">' + p.active_cases + '</td>' +
                '<td class="num">' + p.archived_cases + '</td>' +
                '<td class="num">' + p.total_cases + '</td>' +
                '<td class="num"><a class="practice-link" href="admin-practices.php?practice_id=' + p.id + '&tab=usage" title="' +
                    escapeHtml(<?php echo json_encode(t('usage_report.storage_link_title')); ?>) + '">' + formatBytes(s.total_bytes) + '</a></td>' +
                '<td><span class="status-pill ' + s.status + '"' + stTitle + '>' + escapeHtml(stLabel) + '</span></td>' +
                '<td class="num">' + (s.calculated_at ? escapeHtml(formatDateTime(s.calculated_at)) : '—') + '</td>' +
            '</tr>';
        }).join('');
        document.getElementById('urEmpty').hidden = rows.length > 0;
    }

    function renderAll() {
        const included = includedPractices();
        document.getElementById('urIncludedNote').textContent = <?php echo json_encode(t('usage_report.included_note')); ?>
            .replace('{included}', included.length).replace('{total}', state.practices.length);
        renderSummary(included);
        renderCharts(included);
        renderTable(included);
    }

    document.getElementById('urSearch').addEventListener('input', e => {
        state.search = e.target.value.trim().toLowerCase();
        renderAll();
    });
    document.getElementById('urDemoFilter').addEventListener('change', e => {
        state.demoFilter = e.target.value;
        renderAll();
    });

    fetch('api/admin-practices.php?action=usage_report', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                document.getElementById('urError').hidden = false;
                return;
            }
            state.practices = data.practices || [];
            document.getElementById('urGenerated').textContent = <?php echo json_encode(t('usage_report.generated')); ?>
                .replace('{time}', formatDateTime(data.generated_at));
            renderAll();
        })
        .catch(() => { document.getElementById('urError').hidden = false; });
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
</body>
</html>
