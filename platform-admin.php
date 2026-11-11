<?php
/**
 * Platform Administration - Overview
 *
 * Administration landing page: platform-wide metrics scoped to the
 * caller's visible (non-hidden) practices by default, plus a Needs
 * Attention panel for actionable items. Read-only; all numbers come
 * from api/platform-admin-overview.php using the same definitions as
 * the Practice Usage Report and Practice Administration.
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
require_once __DIR__ . '/admin-layout.php';

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function u($k) { return t('admin_overview.' . $k); }
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title><?php echo h(t('admin_nav.overview')); ?> - <?php echo h($appConfig['appName']); ?></title>
    <?php require __DIR__ . '/partials/brand-icons.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= appAssetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= appAssetUrl('css/admin.css') ?>">
</head>
<?php paAdminChrome('overview', t('admin_nav.overview'), u('subtitle')); ?>

<?php if ($isDev && !isSuperUser($appConfig, $userEmail)): ?>
<div class="pa-alert"><?php echo h(u('dev_notice')); ?></div>
<?php endif; ?>

<div class="pa-scope-bar">
    <span class="pa-scope-text" id="ovScopeText"><?php echo h(u('scope_loading')); ?></span>
    <label class="pa-scope-toggle">
        <input type="checkbox" id="ovIncludeHidden">
        <span><?php echo h(u('include_hidden')); ?></span>
    </label>
</div>

<div id="ovError" class="pa-alert" hidden></div>

<div class="pa-group-label"><?php echo h(u('group_practices')); ?></div>
<div class="pa-grid" id="ovPracticeMetrics">
    <div class="pa-metric"><div class="pa-metric-value" id="ovMPractices">–</div><div class="pa-metric-label"><?php echo h(u('m_practices')); ?></div><div class="pa-metric-note"><?php echo h(u('m_practices_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMActivePractices">–</div><div class="pa-metric-label"><?php echo h(u('m_active_practices')); ?></div><div class="pa-metric-note"><?php echo h(u('m_active_practices_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMInactive">–</div><div class="pa-metric-label"><?php echo h(u('m_inactive')); ?></div><div class="pa-metric-note"><?php echo h(u('m_inactive_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMDemo">–</div><div class="pa-metric-label"><?php echo h(u('m_demo')); ?></div><div class="pa-metric-note"><?php echo h(u('m_demo_note')); ?></div></div>
</div>

<div class="pa-group-label"><?php echo h(u('group_subscriptions')); ?></div>
<div class="pa-grid" id="ovSubMetrics">
    <div class="pa-metric"><div class="pa-metric-value" id="ovMSubs">–</div><div class="pa-metric-label"><?php echo h(u('m_subscriptions')); ?></div><div class="pa-metric-note" id="ovMSubsNote"></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMTrials">–</div><div class="pa-metric-label"><?php echo h(u('m_trials')); ?></div><div class="pa-metric-note"><?php echo h(u('m_trials_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMTrialsEnding">–</div><div class="pa-metric-label"><?php echo h(u('m_trials_ending')); ?></div><div class="pa-metric-note"><?php echo h(u('m_trials_ending_note')); ?></div></div>
</div>

<div class="pa-group-label"><?php echo h(u('group_usage')); ?></div>
<div class="pa-grid" id="ovUsageMetrics">
    <div class="pa-metric"><div class="pa-metric-value" id="ovMCasesActive">–</div><div class="pa-metric-label"><?php echo h(u('m_cases_active')); ?></div><div class="pa-metric-note"><?php echo h(u('m_cases_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMCasesArchived">–</div><div class="pa-metric-label"><?php echo h(u('m_cases_archived')); ?></div><div class="pa-metric-note"><?php echo h(u('m_cases_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMStorage">–</div><div class="pa-metric-label"><?php echo h(u('m_storage')); ?></div><div class="pa-metric-note" id="ovMStorageNote"></div></div>
</div>

<div class="pa-card">
    <h2><?php echo h(u('attention_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(u('attention_sub')); ?></p>
    <div id="ovAttention"><div class="pa-empty"><?php echo h(u('attention_loading')); ?></div></div>
</div>

<?php require_once __DIR__ . '/api/auth-timeout-script.php'; ?>
<script>
(function () {
    var STR = {
        loadFailed: <?php echo json_encode(u('load_failed')); ?>,
        subsNote: <?php echo json_encode(u('subs_note')); ?>,
        storageCoverage: <?php echo json_encode(u('storage_coverage')); ?>,
        storageComplete: <?php echo json_encode(u('storage_complete')); ?>,
        scopeVisible: <?php echo json_encode(u('scope_visible')); ?>,
        scopeAll: <?php echo json_encode(u('scope_all')); ?>,
        attentionEmpty: <?php echo json_encode(u('attention_empty')); ?>,
        attentionTrials: <?php echo json_encode(u('attention_trials')); ?>,
        attentionStorage: <?php echo json_encode(u('attention_storage')); ?>
    };

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var v = bytes, i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i];
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function loadOverview() {
        var includeHidden = document.getElementById('ovIncludeHidden').checked ? '1' : '';
        var url = 'api/platform-admin-overview.php' + (includeHidden ? '?include_hidden=1' : '');
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    var err = document.getElementById('ovError');
                    err.textContent = STR.loadFailed;
                    err.hidden = false;
                    return;
                }
                document.getElementById('ovError').hidden = true;
                var m = data.metrics || {};
                var scope = data.scope || {};

                // Scope indicator
                var scopeText = document.getElementById('ovScopeText');
                if (scope.include_hidden) {
                    scopeText.textContent = STR.scopeAll.replace('{count}', scope.visible != null ? scope.visible : '–');
                } else {
                    scopeText.textContent = STR.scopeVisible.replace('{count}', scope.visible != null ? scope.visible : '–');
                }

                document.getElementById('ovMPractices').textContent = m.practices_total != null ? m.practices_total : '–';
                document.getElementById('ovMActivePractices').textContent = m.practices_active != null ? m.practices_active : '–';
                document.getElementById('ovMInactive').textContent = m.practices_inactive != null ? m.practices_inactive : '–';
                document.getElementById('ovMDemo').textContent = m.practices_demo != null ? m.practices_demo : '–';

                var subs = m.subscriptions || {};
                var subTotal = Object.keys(subs).reduce(function (a, k) { return a + subs[k]; }, 0);
                document.getElementById('ovMSubs').textContent = subTotal;
                document.getElementById('ovMSubsNote').textContent = STR.subsNote
                    .replace('{active}', subs.active || 0)
                    .replace('{trialing}', subs.trialing || 0)
                    .replace('{past_due}', subs.past_due || 0);
                document.getElementById('ovMTrials').textContent = m.subscriptions_trials_active != null ? m.subscriptions_trials_active : '–';
                document.getElementById('ovMTrialsEnding').textContent = m.subscriptions_trials_ending_14d != null ? m.subscriptions_trials_ending_14d : '–';
                document.getElementById('ovMCasesActive').textContent = m.cases_active != null ? m.cases_active : '–';
                document.getElementById('ovMCasesArchived').textContent = m.cases_archived != null ? m.cases_archived : '–';

                var s = m.storage || {};
                var denom = s.practices || 0;
                var measured = (s.measured || 0) + (s.partial || 0);
                document.getElementById('ovMStorage').textContent = (measured > 0 || denom > 0) ? formatBytes(s.total_bytes || 0) : '—';
                document.getElementById('ovMStorageNote').textContent = denom > 0
                    ? (STR.storageCoverage.replace('{measured}', measured).replace('{total}', denom))
                    : STR.storageComplete;

                // Needs Attention
                var att = document.getElementById('ovAttention');
                var items = m.attention || [];
                if (!items.length) {
                    att.innerHTML = '<div class="pa-empty">' + esc(STR.attentionEmpty) + '</div>';
                } else {
                    att.innerHTML = items.map(function (a) {
                        var text = a.kind === 'storage_error'
                            ? STR.attentionStorage.replace('{count}', a.count)
                            : STR.attentionTrials.replace('{count}', a.count);
                        return '<a class="pa-attention-item" href="' + esc(a.link || '#') + '">' +
                            '<span>' + esc(text) + '</span><span class="pa-attention-link">&rarr;</span></a>';
                    }).join('');
                }
            });
    }

    document.getElementById('ovIncludeHidden').addEventListener('change', function () {
        loadOverview().catch(function () {
            var err = document.getElementById('ovError');
            err.textContent = STR.loadFailed;
            err.hidden = false;
        });
    });

    loadOverview().catch(function () {
        var err = document.getElementById('ovError');
        err.textContent = STR.loadFailed;
        err.hidden = false;
    });
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
