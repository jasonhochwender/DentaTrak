<?php
/**
 * Platform Administration - Overview
 *
 * Administration landing page: concise platform-wide metrics with links
 * into each administration section. Read-only; all numbers come from
 * api/platform-admin-overview.php using the same definitions as the
 * Practice Usage Report and Practice Administration.
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

<div id="ovError" class="pa-alert" hidden></div>
<div id="ovAlerts"></div>

<div class="pa-grid" id="ovPracticeMetrics">
    <div class="pa-metric"><div class="pa-metric-value" id="ovMPractices">–</div><div class="pa-metric-label"><?php echo h(u('m_practices')); ?></div><div class="pa-metric-note"><?php echo h(u('m_practices_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMActivePractices">–</div><div class="pa-metric-label"><?php echo h(u('m_active_practices')); ?></div><div class="pa-metric-note"><?php echo h(u('m_active_practices_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMInactive">–</div><div class="pa-metric-label"><?php echo h(u('m_inactive')); ?></div><div class="pa-metric-note"><?php echo h(u('m_inactive_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMDemo">–</div><div class="pa-metric-label"><?php echo h(u('m_demo')); ?></div><div class="pa-metric-note"><?php echo h(u('m_demo_note')); ?></div></div>
</div>

<div class="pa-grid" id="ovSubMetrics">
    <div class="pa-metric"><div class="pa-metric-value" id="ovMSubs">–</div><div class="pa-metric-label"><?php echo h(u('m_subscriptions')); ?></div><div class="pa-metric-note" id="ovMSubsNote"></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMTrials">–</div><div class="pa-metric-label"><?php echo h(u('m_trials')); ?></div><div class="pa-metric-note"><?php echo h(u('m_trials_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMCasesActive">–</div><div class="pa-metric-label"><?php echo h(u('m_cases_active')); ?></div><div class="pa-metric-note"><?php echo h(u('m_cases_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMCasesArchived">–</div><div class="pa-metric-label"><?php echo h(u('m_cases_archived')); ?></div><div class="pa-metric-note"><?php echo h(u('m_cases_note')); ?></div></div>
    <div class="pa-metric"><div class="pa-metric-value" id="ovMStorage">–</div><div class="pa-metric-label"><?php echo h(u('m_storage')); ?></div><div class="pa-metric-note" id="ovMStorageNote"></div></div>
</div>

<div class="pa-card">
    <h2><?php echo h(u('sections_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(u('sections_sub')); ?></p>
    <div class="pa-section-links">
        <a class="pa-section-link" href="admin-practices.php">
            <div class="pa-section-link-title"><?php echo h(t('admin_nav.practices')); ?></div>
            <div class="pa-section-link-desc"><?php echo h(u('sec_practices_desc')); ?></div>
        </a>
        <a class="pa-section-link" href="practice-usage-report.php">
            <div class="pa-section-link-title"><?php echo h(t('admin_nav.usage')); ?></div>
            <div class="pa-section-link-desc"><?php echo h(u('sec_usage_desc')); ?></div>
        </a>
        <a class="pa-section-link" href="dev-ask-usage.php">
            <div class="pa-section-link-title"><?php echo h(t('admin_nav.ai_analytics')); ?></div>
            <div class="pa-section-link-desc"><?php echo h(u('sec_ai_desc')); ?></div>
        </a>
        <a class="pa-section-link" href="test-diagnostics.php">
            <div class="pa-section-link-title"><?php echo h(t('admin_nav.test_diag')); ?></div>
            <div class="pa-section-link-desc"><?php echo h(u('sec_test_desc')); ?></div>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/api/auth-timeout-script.php'; ?>
<script>
(function () {
    var STR = {
        loadFailed: <?php echo json_encode(u('load_failed')); ?>,
        subsNote: <?php echo json_encode(u('subs_note')); ?>,
        storageCoverage: <?php echo json_encode(u('storage_coverage')); ?>,
        storageComplete: <?php echo json_encode(u('storage_complete')); ?>,
        alertStorage: <?php echo json_encode(u('alert_storage')); ?>,
        alertTrials: <?php echo json_encode(u('alert_trials')); ?>
    };

    function formatBytes(bytes) {
        if (bytes === null || bytes === undefined) return '—';
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var v = bytes, i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i];
    }

    fetch('api/platform-admin-overview.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) {
                var err = document.getElementById('ovError');
                err.textContent = STR.loadFailed;
                err.hidden = false;
                return;
            }
            var m = data.metrics || {};
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
            document.getElementById('ovMCasesActive').textContent = m.cases_active != null ? m.cases_active : '–';
            document.getElementById('ovMCasesArchived').textContent = m.cases_archived != null ? m.cases_archived : '–';

            var s = m.storage || {};
            document.getElementById('ovMStorage').textContent = formatBytes(s.total_bytes || 0);
            var measured = (s.measured || 0) + (s.partial || 0);
            var denom = s.practices || 0;
            document.getElementById('ovMStorageNote').textContent = denom > 0
                ? (STR.storageCoverage.replace('{measured}', measured).replace('{total}', denom))
                : STR.storageComplete;

            var alerts = document.getElementById('ovAlerts');
            (m.alerts || []).forEach(function (a) {
                var div = document.createElement('div');
                div.className = 'pa-alert';
                div.textContent = a.kind === 'storage_error'
                    ? STR.alertStorage.replace('{count}', a.count)
                    : STR.alertTrials.replace('{count}', a.count);
                alerts.appendChild(div);
            });
        })
        .catch(function () {
            var err = document.getElementById('ovError');
            err.textContent = STR.loadFailed;
            err.hidden = false;
        });
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
