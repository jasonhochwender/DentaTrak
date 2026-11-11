<?php
/**
 * Platform Administration - Test & Diagnostics
 *
 * Restricted section for test-case generation and destructive test
 * utilities migrated from the old floating Dev Tools panel. Every tool
 * here operates ONLY on the practice selected in the current session -
 * the APIs enforce that server-side; nothing here can touch another
 * practice.
 *
 * Access: same rule as the other administration pages for viewing
 * (super user, or the local development environment). The underlying
 * APIs keep their own stricter gates (SHOW_DEV_TOOLS + super user, plus
 * practice-admin verification in production).
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
require_once __DIR__ . '/api/case-types.php';
require_once __DIR__ . '/admin-layout.php';

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

$currentPracticeId = (int)($_SESSION['current_practice_id'] ?? 0);
$practiceName = $_SESSION['practice_name'] ?? $_SESSION['current_practice_name'] ?? '';
$envName = getEnvironmentDisplayName($appConfig);
$isProd = isProductionOrUAT($appConfig);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function td($k) { return t('admin_testdiag.' . $k); }
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title><?php echo h(t('admin_nav.test_diag')); ?> - <?php echo h($appConfig['appName']); ?></title>
    <?php require __DIR__ . '/partials/brand-icons.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= appAssetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= appAssetUrl('css/admin.css') ?>">
    <style>
        .td-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .td-field { display: flex; flex-direction: column; gap: 4px; }
        .td-field label { font-size: 0.78rem; font-weight: 600; color: #4b5563; }
        .td-field select, .td-field input {
            padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 0.88rem; min-width: 140px;
        }
        .td-btn {
            padding: 9px 18px; border: 0; border-radius: 6px; font-size: 0.88rem;
            font-weight: 600; cursor: pointer; background: #1e40af; color: #fff;
        }
        .td-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .td-btn-danger { background: #b91c1c; }
        .td-btn-danger:hover { background: #991b1b; }
        .td-status { margin-top: 10px; font-size: 0.85rem; min-height: 1.2em; }
        .td-status.ok { color: #047857; }
        .td-status.err { color: #b91c1c; }
        .td-scope {
            margin-top: 12px; padding: 10px 12px; background: #f9fafb;
            border: 1px solid #e5e7eb; border-radius: 6px; font-size: 0.8rem; color: #4b5563;
        }
        .td-danger-zone { border-left: 3px solid #b91c1c; }
    </style>
</head>
<?php paAdminChrome('test', t('admin_nav.test_diag'), td('subtitle')); ?>

<?php if ($isDev && !isSuperUser($appConfig, $userEmail)): ?>
<div class="pa-alert"><?php echo h(td('dev_notice')); ?></div>
<?php endif; ?>

<div class="pa-card">
    <h2><?php echo h(td('gen_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(td('gen_sub')); ?></p>
    <div class="td-form">
        <div class="td-field">
            <label for="tdCaseType"><?php echo h(td('case_type')); ?></label>
            <select id="tdCaseType">
                <option value="Mixed Case Type"><?php echo h(t('case_types.mixed')); ?></option>
                <?php echo renderCaseTypeOptions(array_diff(getCanonicalCaseTypes(), [CASE_TYPE_NEEDS_CLASSIFICATION])); ?>
            </select>
        </div>
        <div class="td-field">
            <label for="tdCaseCount"><?php echo h(td('case_count')); ?></label>
            <input type="number" id="tdCaseCount" value="10" min="1" max="500">
        </div>
        <button type="button" class="td-btn" id="tdGenerateBtn"><?php echo h(td('generate')); ?></button>
    </div>
    <div class="td-status" id="tdGenStatus" role="status" aria-live="polite"></div>
    <div class="td-scope"><?php echo h(td('gen_scope')); ?></div>
</div>

<div class="pa-card td-danger-zone">
    <h2><?php echo h(td('danger_title')); ?></h2>
    <p class="pa-card-sub"><?php echo h(td('danger_sub')); ?></p>
    <div class="td-form">
        <button type="button" class="td-btn td-btn-danger" id="tdDeleteAllBtn"><?php echo h(td('delete_all')); ?></button>
    </div>
    <div class="td-status" id="tdDeleteStatus" role="status" aria-live="polite"></div>
    <div class="td-scope">
        <strong><?php echo h(td('scope_label')); ?></strong>
        <?php echo h(td('delete_scope')); ?>
        <br><strong><?php echo h(td('practice_label')); ?></strong>
        <?php echo h($practiceName !== '' ? $practiceName : ('#' . $currentPracticeId)); ?>
        (<?php echo h($envName); ?>)
    </div>
</div>

<script>
(function () {
    var csrf = <?php echo json_encode($csrfToken); ?>;
    var STR = {
        enterCount: <?php echo json_encode(t('common.enter_case_count')); ?>,
        working: 'Working...',
        genFailed: <?php echo json_encode(t('demo_data.generate_failed')); ?>,
        genError: <?php echo json_encode(t('demo_data.generate_error', ['message' => '{message}'])); ?>,
        genSuccess: <?php echo json_encode(t('demo_data.fake_cases_generated')); ?>,
        cancelled: <?php echo json_encode(t('common.action_cancelled')); ?>,
        deleting: <?php echo json_encode(t('common.deleting')); ?>,
        delScope: <?php echo json_encode(td('delete_confirm_msg')); ?>,
        delSuccess: <?php echo json_encode(td('delete_success')); ?>,
        delError: <?php echo json_encode(td('delete_error')); ?>,
        delUnknown: <?php echo json_encode(td('delete_unknown')); ?>
    };
    var isProd = <?php echo $isProd ? 'true' : 'false'; ?>;
    var envName = <?php echo json_encode($envName); ?>;

    function setStatus(el, msg, cls) {
        el.textContent = msg || '';
        el.className = 'td-status' + (cls ? ' ' + cls : '');
    }

    // ---- Generate test cases (current practice only) ----
    var genBtn = document.getElementById('tdGenerateBtn');
    var countInput = document.getElementById('tdCaseCount');
    genBtn.addEventListener('click', function () {
        var count = parseInt(countInput.value, 10);
        if (isNaN(count) || count < 1) { setStatus(document.getElementById('tdGenStatus'), STR.enterCount, 'err'); return; }
        if (count > 500) { count = 500; countInput.value = '500'; }

        genBtn.disabled = true;
        var original = genBtn.textContent;
        genBtn.textContent = STR.working;
        setStatus(document.getElementById('tdGenStatus'), '');

        fetch('api/generate-fake-cases.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
            body: JSON.stringify({ count: count })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            genBtn.disabled = false;
            genBtn.textContent = original;
            if (!data || !data.success) {
                setStatus(document.getElementById('tdGenStatus'), (data && data.message) || STR.genFailed, 'err');
                return;
            }
            setStatus(document.getElementById('tdGenStatus'), data.message || STR.genSuccess, 'ok');
            setTimeout(function () { window.location.reload(); }, 800);
        })
        .catch(function (err) {
            genBtn.disabled = false;
            genBtn.textContent = original;
            setStatus(document.getElementById('tdGenStatus'), STR.genError.replace('{message}', err.message), 'err');
        });
    });

    // ---- Delete all cases (current practice only, explicit confirm) ----
    var delBtn = document.getElementById('tdDeleteAllBtn');
    delBtn.addEventListener('click', function () {
        if (isProd) {
            var msg = STR.delScope.replace('{env}', envName);
            var input = window.prompt(msg);
            if (input !== 'CONFIRM') {
                setStatus(document.getElementById('tdDeleteStatus'), STR.cancelled);
                return;
            }
        } else if (!window.confirm(STR.delScope.replace('{env}', envName))) {
            setStatus(document.getElementById('tdDeleteStatus'), STR.cancelled);
            return;
        }

        delBtn.disabled = true;
        var original = delBtn.textContent;
        delBtn.textContent = STR.deleting;
        setStatus(document.getElementById('tdDeleteStatus'), '');

        fetch('api/delete-all-cases.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) {
                setStatus(document.getElementById('tdDeleteStatus'), STR.delSuccess, 'ok');
                setTimeout(function () { window.location.reload(); }, 1000);
            } else {
                setStatus(document.getElementById('tdDeleteStatus'), (data && data.message) || STR.delError, 'err');
                delBtn.disabled = false;
                delBtn.textContent = original;
            }
        })
        .catch(function () {
            setStatus(document.getElementById('tdDeleteStatus'), STR.delUnknown, 'err');
            delBtn.disabled = false;
            delBtn.textContent = original;
        });
    });
})();
</script>
<?php paAdminChromeEnd(); ?>
</html>
