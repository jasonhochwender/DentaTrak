<?php
/**
 * Export UI consolidation tests (static).
 *
 * Verifies the Data & Privacy export surface: legacy section removed,
 * three-section layout, segmented format control, archive/format
 * independence, status badges, expired-download suppression, custom
 * modal/toast usage (no native dialogs), and locale key coverage.
 */

$passed = 0; $failed = 0;
function ok(string $n, bool $c, string $d = ''): void {
    global $passed, $failed;
    if ($c) { $passed++; echo "PASS: {$n}\n"; }
    else { $failed++; echo "FAIL: {$n}" . ($d ? " ({$d})" : '') . "\n"; }
}

$main = file_get_contents(__DIR__ . '/../main.php');
$js = file_get_contents(__DIR__ . '/../js/app.js');

// Extract the export block from app.js (between the DATA EXPORT banner and
// the 2FA observer that follows it).
$start = strpos($js, 'DATA EXPORT (v2 pipeline');
$end = strpos($js, 'Load 2FA status when settings modal opens', $start);
$block = ($start !== false && $end !== false) ? substr($js, $start, $end - $start) : '';
ok('export JS block located', $block !== '');

// ---------- Markup ----------
ok('legacy export UI scoped to non-allowlisted practices',
    strpos($main, 'id="exportDataBtn"') !== false
    && strpos($main, 'dataExportV2PracticeAllowed') !== false);
ok('legacy export status div present for fallback', strpos($main, 'id="exportStatus"') !== false);
ok('legacy section title key present for fallback', strpos($main, "settings.data_privacy.export.title") !== false);
ok('case data section present', strpos($main, 'export_v2.title') !== false);
ok('archive section present', strpos($main, 'export_v2.archive_title') !== false);
ok('history section present', strpos($main, 'exportV2List') !== false);
ok('segmented format control present', strpos($main, 'exportV2FormatCsv') !== false
    && strpos($main, 'exportV2FormatJson') !== false
    && strpos($main, 'role="group"') !== false);
ok('native select removed', strpos($main, 'id="exportV2Format"') === false);
ok('archive button present', strpos($main, 'id="exportV2ArchiveBtn"') !== false);

// ---------- JS behavior ----------
ok('no native confirm in export block', strpos($block, 'confirm(') === false);
ok('no native alert in export block', strpos($block, 'alert(') === false);
ok('archive uses styled confirm modal', strpos($block, 'showConfirmModal(') !== false);
ok('toasts used for notifications', substr_count($block, 'showToast(') >= 3);
ok('case export sends selected format', strpos($block, "exportV2Request('case_data', exportV2SelectedFormat") !== false);
ok('archive ignores format selection', strpos($block, "exportV2Request('practice_archive', 'zip'") !== false);
ok('download gated on completed status', strpos($block, "e.status === 'completed'") !== false);
ok('expired status has muted badge, no download', strpos($block, "expired: 'muted'") !== false
    && substr_count($block, "e.status === 'completed'") === 1);
ok('status badge classes for all states', strpos($block, 'exportStatusBadgeClass') !== false
    && strpos($block, 'queued') !== false && strpos($block, 'processing') !== false
    && strpos($block, 'completed') !== false && strpos($block, 'failed') !== false
    && strpos($block, 'expired') !== false);
ok('dates rendered via locale formatter', strpos($block, 'toLocaleString') !== false
    && strpos($block, "replace(' ', 'T')") !== false);
ok('empty state rendered', strpos($block, 'history_empty') !== false);
ok('history load failure message', strpos($block, 'history_load_error') !== false);
ok('download errors toasted', strpos($block, 'download_error') !== false);
ok('aria-pressed managed on format toggle', substr_count($block, 'aria-pressed') >= 2
    && strpos($block, 'exportV2SyncFormat') !== false);
ok('status list refresh after request', substr_count($block, 'loadExportV2Status') >= 4);

// ---------- Email deep link ----------
ok('deep link reads export_id param', strpos($block, "params.get('export_id')") !== false);
ok('deep link consumes pendingExport sessionStorage', strpos($block, "sessionStorage.getItem('pendingExport')") !== false);
ok('deep link strips URL param', strpos($block, "params.delete('export_id')") !== false);
ok('deep link opens settings modal', strpos($block, 'openSettingsBillingModal()') !== false);
ok('deep link selects data-privacy nav', strpos($block, "data-nav-target=\"data-privacy\"") !== false
    || strpos($block, "data-nav-target='data-privacy'") !== false
    || strpos($block, 'data-nav-target="data-privacy"') !== false);
ok('history rows carry export id', strpos($block, 'data-export-id') !== false);
ok('highlight applied to matched row', strpos($block, 'export-history-highlight') !== false);
ok('missing export shows generic notice', strpos($block, 'export_not_found') !== false);
ok('deep link self-invokes for deferred app.js',
    substr_count($block, 'processPendingExport') >= 2
    && strpos($block, 'window.processPendingExport();') !== false);
ok('deep link falls back to scoped single-export lookup',
    strpos($block, 'action=status&export_id=') !== false
    && strpos($block, 'exportHistoryRowHtml(ex)') !== false
    && strpos($block, 'bindExportV2Download(dl)') !== false);
$v2Api = file_get_contents(__DIR__ . '/../api/data-export-v2.php');
ok('status endpoint resolves single export by id',
    strpos($v2Api, "\$_GET['export_id']") !== false
    && strpos($v2Api, 'WHERE id = :id AND user_id = :u AND practice_id = :p') !== false
    && strpos($v2Api, "case 'status'") !== false);
$workerSrc = file_get_contents(__DIR__ . '/../api/export-worker.php');
ok('ready email deep-links to export', strpos($workerSrc, "main.php?export_id=") !== false);
ok('failed email deep-links to export', substr_count($workerSrc, "main.php?export_id=") >= 2);
$mainPhp = file_get_contents(__DIR__ . '/../main.php');
ok('main.php carries export_id through login redirect', strpos($mainPhp, "\$_GET['export_id']") !== false
    && strpos($mainPhp, 'export_id=') !== false);
ok('main.php exposes deep link id to JS', strpos($mainPhp, '__exportDeepLinkId') !== false
    && strpos($mainPhp, 'processPendingExport') !== false);
$loginPhp = file_get_contents(__DIR__ . '/../login.php');
ok('login.php stashes pendingExport', strpos($loginPhp, 'pendingExport') !== false);

// ---------- Locale coverage ----------
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
$need = ['title','description','format_group_aria','format_csv','format_json','button',
         'queued','error','download','download_error','expires',
         'status_queued','status_processing','status_completed','status_failed',
         'status_expired','status_pending','status_cancelled','status_unknown',
         'archive_title','archive_description','archive_button','archive_note',
         'archive_confirm_title','archive_confirm','history_title','history_empty',
         'history_load_error','history_created','type_archive','type_legacy'];
foreach ($locales as $loc) {
    $j = json_decode(file_get_contents(__DIR__ . "/../locales/$loc.json"), true);
    $ev = $j['settings']['data_privacy']['export_v2'] ?? [];
    $missing = array_diff($need, array_keys($ev));
    ok("$loc export_v2 keys complete", $missing === [], implode(',', $missing));
    ok("$loc legacy export keys retained", isset($j['settings']['data_privacy']['export']['button']));
}

// en-US specific copy checks
$en = json_decode(file_get_contents(__DIR__ . '/../locales/en-US.json'), true)['settings']['data_privacy']['export_v2'];
ok('title has no (new) suffix', $en['title'] === 'Case Data Export');
ok('button is Export Case Data', $en['button'] === 'Export Case Data');
ok('archive button is Create Archive (ZIP)', $en['archive_button'] === 'Create Archive (ZIP)');

// ---------- Non-allowlisted legacy fallback ----------
ok('legacy export section retained for non-allowlisted practices',
    strpos($main, 'exportDataBtn') !== false
    && strpos($main, "settings.data_privacy.export.title") !== false
    && strpos($main, 'dataExportV2PracticeAllowed') !== false);
ok('legacy export JS listener restored',
    strpos($block, "getElementById('exportDataBtn')") !== false
    && strpos($block, 'api/data-export.php?action=request') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
