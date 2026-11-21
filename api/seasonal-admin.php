<?php
/**
 * Seasonal Themes admin API - Platform Administration backend.
 *
 * Session + CSRF + super-user (or local development) gated, same rule as
 * the other administration endpoints. All state lives in
 * platform_settings.sk='seasonal_themes' so changes apply to the login
 * page immediately without a deployment.
 *
 * GET  ?action=state                 -> config + catalog + computed windows
 * POST ?action=save   (JSON)         -> {enabled, mode, manual_theme, holidays[]}
 * POST ?action=upload (multipart)    -> replace a theme's built-in artwork
 * POST ?action=reset_asset (JSON)    -> remove an uploaded override
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/gcs-storage.php';
require_once __DIR__ . '/seasonal-themes.php';
setSecurityHeaders();
header('Content-Type: application/json');

function st_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
function st_ok(array $extra = []): void {
    echo json_encode(['success' => true] + $extra);
    exit;
}

if (empty($_SESSION['db_user_id'])) st_fail('unauthenticated', 401);
$userEmail = (string)($_SESSION['user_email'] ?? '');
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
if (!isSuperUser($appConfig, $userEmail) && !$isDev) st_fail('forbidden', 403);

global $pdo;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

// ---- GET: full state for the admin page ----
if ($method === 'GET' && $action === 'state') {
    $cfg = seasonalLoadConfig($pdo);
    $catalog = seasonalThemeCatalog();
    $today = (new DateTime('now', new DateTimeZone(SEASONAL_TZ)))->format('Y-m-d');

    $holidays = [];
    foreach ((array)$cfg['holidays'] as $h) {
        $w = seasonalHolidayWindow($h, (int)substr($today, 0, 4));
        $holidays[] = [
            'id' => $h['id'] ?? '',
            'name' => $h['name'] ?? '',
            'theme' => $h['theme'] ?? '',
            'theme_name' => $catalog[$h['theme']]['name'] ?? ($h['theme'] ?? ''),
            'enabled' => !empty($h['enabled']),
            'start' => $h['start'] ?? null,
            'end' => $h['end'] ?? null,
            'start_label' => seasonalRuleLabel((array)($h['start'] ?? [])),
            'end_label' => seasonalRuleLabel((array)($h['end'] ?? [])),
            'window' => $w ? $w[0] . ' → ' . $w[1] : null,
            'active_today' => seasonalHolidayActiveOn($h, $today),
        ];
    }

    $active = seasonalResolveTheme($pdo);
    $themesDetail = [];
    foreach ($catalog as $tid => $t) {
        $decos = [];
        foreach ((array)$t['decorations'] as $d) {
            $decos[] = ['asset' => $d['asset'], 'url' => seasonalAssetUrl($cfg, $tid, $d['asset'])];
        }
        $themesDetail[] = ['id' => $tid, 'name' => $t['name'], 'decorations' => $decos];
    }
    st_ok([
        'config' => [
            'enabled' => !empty($cfg['enabled']),
            'mode' => $cfg['mode'] ?? 'auto',
            'manual_theme' => $cfg['manual_theme'] ?? '',
            'asset_overrides' => array_keys((array)$cfg['asset_overrides']),
        ],
        'themes' => array_map(function ($id, $t) { return ['id' => $id, 'name' => $t['name']]; }, array_keys($catalog), $catalog),
        'themes_detail' => $themesDetail,
        'holidays' => $holidays,
        'active' => $active ? ['theme_id' => $active['theme_id'], 'name' => $active['theme']['name'], 'source' => $active['source']] : null,
        'preview_base' => 'login.php?seasonal_preview=',
    ]);
}

if ($method !== 'POST') st_fail('method not allowed', 405);

// Multipart upload path (file field present)
if (!empty($_FILES['file'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) st_fail('csrf', 403);

    $theme = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['theme'] ?? ''));
    $slot = preg_replace('/[^a-z0-9_.-]/', '', (string)($_POST['slot'] ?? ''));
    $catalog = seasonalThemeCatalog();
    if (!isset($catalog[$theme])) st_fail('unknown theme');
    // slot must be one of the theme's declared decoration assets
    $validSlots = array_column($catalog[$theme]['decorations'], 'asset');
    if (!in_array($slot, $validSlots, true)) st_fail('slot is not a decoration of this theme');

    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) st_fail('upload failed');
    if ($f['size'] > 512 * 1024) st_fail('file too large (max 512KB)');
    $ext = strtolower(pathinfo($slot, PATHINFO_EXTENSION));
    $tmp = $f['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed[$ext]) || !in_array($mime, $allowed, true)) st_fail('unsupported file type');
    if ($ext === 'svg') {
        $svg = file_get_contents($tmp);
        if ($svg === false || stripos($svg, '<script') !== false || stripos($svg, 'javascript:') !== false || stripos($svg, 'onload=') !== false) {
            st_fail('SVG contains active content');
        }
        if (strpos($svg, '<svg') === false) st_fail('not an SVG file');
    }

    $key = $theme . '/' . $slot;
    $cfg = seasonalLoadConfig($pdo);
    $gcsBucketName = trim((string)($appConfig['gcs']['bucket_name'] ?? ''));
    try {
        if ($gcsBucketName !== '') {
            $bucket = getGcsBucket();
            $contentType = $allowed[$ext];
            $bucket->upload(fopen($tmp, 'r'), ['name' => 'seasonal/' . $key, 'metadata' => ['contentType' => $contentType]]);
        } else {
            $dir = __DIR__ . '/../uploads/seasonal/' . $theme;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            move_uploaded_file($tmp, $dir . '/' . $slot);
        }
        $cfg['asset_overrides'][$key] = true;
        seasonalSaveConfig($pdo, $cfg);
    } catch (Throwable $e) {
        error_log('[seasonal-admin] asset upload failed: ' . $e->getMessage());
        st_fail('storage error', 500);
    }
    logUserActivity((int)$_SESSION['db_user_id'], 'seasonal_asset_upload', "Uploaded seasonal asset $key");
    st_ok(['key' => $key]);
}

// JSON actions
$in = json_decode((string)file_get_contents('php://input'), true) ?? $_POST;
$csrf = (string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) st_fail('csrf', 403);

$cfg = seasonalLoadConfig($pdo);
$catalog = seasonalThemeCatalog();

switch ($action) {
    case 'save':
        $enabled = !empty($in['enabled']);
        $mode = (string)($in['mode'] ?? 'auto');
        if (!in_array($mode, ['auto', 'manual'], true)) st_fail('invalid mode');
        $manual = preg_replace('/[^a-z0-9_]/', '', (string)($in['manual_theme'] ?? ''));
        if ($mode === 'manual' && !isset($catalog[$manual])) st_fail('invalid manual theme');

        $holidays = [];
        foreach ((array)($in['holidays'] ?? []) as $h) {
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($h['id'] ?? '')));
            $name = trim(mb_substr((string)($h['name'] ?? ''), 0, 80));
            $theme = preg_replace('/[^a-z0-9_]/', '', (string)($h['theme'] ?? ''));
            if ($id === '' || $name === '' || !isset($catalog[$theme])) st_fail('invalid holiday entry');
            $start = seasonalValidateRule($h['start'] ?? null);
            $end = seasonalValidateRule($h['end'] ?? null);
            if ($start === null || $end === null) st_fail('invalid holiday dates for ' . $name);
            $holidays[] = [
                'id' => $id, 'name' => $name, 'theme' => $theme,
                'enabled' => !empty($h['enabled']),
                'start' => $start, 'end' => $end,
            ];
            if (count($holidays) > 20) st_fail('too many holidays');
        }

        $cfg['enabled'] = $enabled;
        $cfg['mode'] = $mode;
        $cfg['manual_theme'] = $manual !== '' ? $manual : 'thanksgiving';
        $cfg['holidays'] = $holidays;
        try {
            seasonalSaveConfig($pdo, $cfg);
        } catch (Throwable $e) {
            error_log('[seasonal-admin] save failed: ' . $e->getMessage());
            st_fail('save failed', 500);
        }
        logUserActivity((int)$_SESSION['db_user_id'], 'seasonal_config_save',
            'Seasonal themes: enabled=' . (int)$enabled . ' mode=' . $mode);
        st_ok(['config' => ['enabled' => $enabled, 'mode' => $mode, 'manual_theme' => $cfg['manual_theme']]]);

    case 'reset_asset':
        $key = (string)($in['key'] ?? '');
        if (!seasonalValidAssetKey($key)) st_fail('invalid asset key');
        unset($cfg['asset_overrides'][$key]);
        // Remove stored override so the built-in artwork returns.
        $gcsBucketName = trim((string)($appConfig['gcs']['bucket_name'] ?? ''));
        try {
            if ($gcsBucketName !== '') {
                $bucket = getGcsBucket();
                $bucket->object('seasonal/' . $key)->delete(['ifExists' => true]);
            } else {
                $p = __DIR__ . '/../uploads/seasonal/' . $key;
                if (is_file($p)) unlink($p);
            }
            seasonalSaveConfig($pdo, $cfg);
        } catch (Throwable $e) {
            error_log('[seasonal-admin] asset reset failed: ' . $e->getMessage());
            st_fail('reset failed', 500);
        }
        logUserActivity((int)$_SESSION['db_user_id'], 'seasonal_asset_reset', "Reset seasonal asset $key");
        st_ok();

    default:
        st_fail('unknown action', 404);
}

/** Validate a start/end date rule; returns normalized rule or null. */
function seasonalValidateRule($rule): ?array {
    if (!is_array($rule)) return null;
    $kind = (string)($rule['kind'] ?? '');
    if ($kind === 'fixed') {
        $m = (int)($rule['m'] ?? 0); $d = (int)($rule['d'] ?? 0);
        if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return null;
        if (!checkdate($m, $d, 2024)) return null;
        return ['kind' => 'fixed', 'm' => $m, 'd' => $d];
    }
    if ($kind === 'easter') {
        $off = (int)($rule['offset'] ?? 0);
        if ($off < -60 || $off > 60) return null;
        return ['kind' => 'easter', 'offset' => $off];
    }
    return null;
}
