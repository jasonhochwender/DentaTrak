<?php
/**
 * Seasonal Themes - login-page decorations.
 *
 * A lightweight, DB-backed theme system for the public auth pages
 * (login/signup/password recovery). Platform admins manage it from
 * Platform Administration > Seasonal Themes; config lives in
 * platform_settings.sk='seasonal_themes' (see migration
 * 2026_12_03_seasonal_themes.php) so changes apply without deployment.
 *
 * All date math uses America/New_York. Themes resolve automatically by
 * holiday windows; admins can also force a theme manually or disable
 * decorations entirely. When nothing is active the classic floating
 * circles render instead (see partials/auth-background.php).
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/asset-url.php';

const SEASONAL_TZ = 'America/New_York';
const SEASONAL_SETTINGS_KEY = 'seasonal_themes';

/** Built-in decoration catalog. Each theme lists absolutely-positioned
 *  artwork slots; coordinates are percentages of the viewport and sizes
 *  are px at a 1440px-wide reference (CSS clamps them down on mobile). */
function seasonalThemeCatalog(): array {
    return [
        'thanksgiving' => [
            'name' => 'Thanksgiving',
            'decorations' => [
                // Hero anchors: four distinct turkeys + harvest pieces at the edges
                ['asset' => 'turkey1.png',   'x' => 4,  'y' => 9,  's' => 200, 'dur' => 26, 'del' => -4,  'rot' => 5,  'op' => 0.8],
                ['asset' => 'turkey2.png',   'x' => 93, 'y' => 26, 's' => 180, 'dur' => 30, 'del' => -11, 'rot' => -4, 'op' => 0.78],
                ['asset' => 'turkey3.png',   'x' => 88, 'y' => 68, 's' => 165, 'dur' => 24, 'del' => -18, 'rot' => 6,  'op' => 0.75],
                ['asset' => 'turkey4.png',   'x' => 8,  'y' => 84, 's' => 150, 'dur' => 28, 'del' => -7,  'rot' => -6, 'op' => 0.75],
                ['asset' => 'pumpkin1.png',  'x' => 16, 'y' => 56, 's' => 195, 'dur' => 32, 'del' => -9,  'rot' => 4,  'op' => 0.85],
                ['asset' => 'pie1.png',      'x' => 63, 'y' => 5,  's' => 170, 'dur' => 28, 'del' => -15, 'rot' => -4, 'op' => 0.8],
                ['asset' => 'pumpkin3.png',  'x' => 57, 'y' => 87, 's' => 125, 'dur' => 30, 'del' => -13, 'rot' => 5,  'op' => 0.8],
                ['asset' => 'pumpkin4.png',  'x' => 73, 'y' => 90, 's' => 105, 'dur' => 27, 'del' => -6,  'rot' => -5, 'op' => 0.75],
                ['asset' => 'pumpkin2.png',  'x' => 38, 'y' => 92, 's' => 80,  'dur' => 29, 'del' => -16, 'rot' => 8,  'op' => 0.7],
                // Larger leaf accents near the top and sides
                ['asset' => 'leaf4.png',     'x' => 35, 'y' => 14, 's' => 72,  'dur' => 22, 'del' => -5,  'rot' => -20,'op' => 0.65],
                ['asset' => 'leaf8.png',     'x' => 51, 'y' => 9,  's' => 60,  'dur' => 19, 'del' => -8,  'rot' => 18, 'op' => 0.65],
                // Small leaf fillers scattered through the negative space
                ['asset' => 'leaf1.png',     'x' => 26, 'y' => 24, 's' => 52,  'dur' => 18, 'del' => -2,  'rot' => 20, 'op' => 0.7],
                ['asset' => 'leaf7.png',     'x' => 13, 'y' => 34, 's' => 45,  'dur' => 23, 'del' => -12, 'rot' => 18, 'op' => 0.6],
                ['asset' => 'leaf2.png',     'x' => 31, 'y' => 44, 's' => 47,  'dur' => 21, 'del' => -9,  'rot' => -18,'op' => 0.65],
                ['asset' => 'leaf3.png',     'x' => 72, 'y' => 40, 's' => 55,  'dur' => 19, 'del' => -13, 'rot' => 15, 'op' => 0.6],
                ['asset' => 'leaf6.png',     'x' => 93, 'y' => 50, 's' => 45,  'dur' => 20, 'del' => -5,  'rot' => -15,'op' => 0.6],
                ['asset' => 'leaf5.png',     'x' => 29, 'y' => 73, 's' => 48,  'dur' => 17, 'del' => -16, 'rot' => 25, 'op' => 0.6],
                ['asset' => 'leaf3.png',     'x' => 62, 'y' => 82, 's' => 50,  'dur' => 24, 'del' => -3,  'rot' => 12, 'op' => 0.6],
                ['asset' => 'leaf1.png',     'x' => 84, 'y' => 85, 's' => 45,  'dur' => 21, 'del' => -10, 'rot' => -22,'op' => 0.6],
            ],
        ],
        'christmas' => [
            'name' => 'Christmas',
            'decorations' => [
                ['asset' => 'tree1.png',      'x' => 35, 'y' => 90, 's' => 170, 'dur' => 30, 'del' => -5,  'rot' => 3,  'op' => 0.7],
                ['asset' => 'branch1.png',    'x' => 93, 'y' => 50, 's' => 140, 'dur' => 28, 'del' => -17, 'rot' => -3, 'op' => 0.65],
                ['asset' => 'ornament1.png',  'x' => 10, 'y' => 75, 's' => 85,  'dur' => 22, 'del' => -6,  'rot' => 8,  'op' => 0.8],
                ['asset' => 'ornament2.png',  'x' => 55, 'y' => 6,  's' => 75,  'dur' => 26, 'del' => -11, 'rot' => -6, 'op' => 0.75],
                ['asset' => 'snowflake1.png', 'x' => 5,  'y' => 15, 's' => 90,  'dur' => 20, 'del' => -3,  'rot' => 30, 'op' => 0.8],
                ['asset' => 'snowflake2.png', 'x' => 90, 'y' => 22, 's' => 110, 'dur' => 24, 'del' => -8,  'rot' => -25,'op' => 0.75],
                ['asset' => 'snowflake3.png', 'x' => 78, 'y' => 75, 's' => 80,  'dur' => 19, 'del' => -14, 'rot' => 20, 'op' => 0.7],
                ['asset' => 'present1.png',   'x' => 88, 'y' => 68, 's' => 95,  'dur' => 23, 'del' => -9,  'rot' => -5, 'op' => 0.8],
                ['asset' => 'candy1.png',     'x' => 25, 'y' => 10, 's' => 55,  'dur' => 18, 'del' => -12, 'rot' => 18, 'op' => 0.7],
                ['asset' => 'present2.png',   'x' => 48, 'y' => 88, 's' => 85,  'dur' => 25, 'del' => -7,  'rot' => 6,  'op' => 0.75],
            ],
        ],
        'new_year' => [
            'name' => 'New Year',
            'decorations' => [
                ['asset' => 'star1.png',      'x' => 8,  'y' => 18, 's' => 95,  'dur' => 18, 'del' => -2,  'rot' => 15, 'op' => 0.85],
                ['asset' => 'star2.png',      'x' => 85, 'y' => 15, 's' => 80,  'dur' => 21, 'del' => -7,  'rot' => -12,'op' => 0.8],
                ['asset' => 'star3.png',      'x' => 70, 'y' => 70, 's' => 110, 'dur' => 23, 'del' => -12, 'rot' => 10, 'op' => 0.75],
                ['asset' => 'confetti1.png',  'x' => 25, 'y' => 8,  's' => 55,  'dur' => 16, 'del' => -5,  'rot' => 30, 'op' => 0.7],
                ['asset' => 'confetti2.png',  'x' => 50, 'y' => 85, 's' => 60,  'dur' => 17, 'del' => -9,  'rot' => -25,'op' => 0.7],
                ['asset' => 'confetti3.png',  'x' => 92, 'y' => 50, 's' => 50,  'dur' => 15, 'del' => -14, 'rot' => 20, 'op' => 0.65],
                ['asset' => 'firework1.png',  'x' => 15, 'y' => 60, 's' => 100, 'dur' => 25, 'del' => -4,  'rot' => 8,  'op' => 0.7],
                ['asset' => 'balloon1.png',   'x' => 60, 'y' => 30, 's' => 90,  'dur' => 27, 'del' => -10, 'rot' => -8, 'op' => 0.65],
                ['asset' => 'partyhat1.png',  'x' => 40, 'y' => 92, 's' => 85,  'dur' => 24, 'del' => -16, 'rot' => 12, 'op' => 0.75],
            ],
        ],
        'valentines' => [
            'name' => "Valentine's Day",
            'decorations' => [
                ['asset' => 'heart1.png',    'x' => 7,  'y' => 20, 's' => 85,  'dur' => 18, 'del' => -3,  'rot' => 10, 'op' => 0.8],
                ['asset' => 'heart2.png',    'x' => 88, 'y' => 25, 's' => 70,  'dur' => 22, 'del' => -8,  'rot' => -8, 'op' => 0.75],
                ['asset' => 'heart3.png',    'x' => 75, 'y' => 72, 's' => 95,  'dur' => 20, 'del' => -13, 'rot' => 12, 'op' => 0.8],
                ['asset' => 'bouquet1.png',  'x' => 20, 'y' => 75, 's' => 95,  'dur' => 24, 'del' => -6,  'rot' => -10,'op' => 0.75],
                ['asset' => 'chocolate1.png','x' => 50, 'y' => 10, 's' => 70,  'dur' => 19, 'del' => -15, 'rot' => 15, 'op' => 0.7],
                ['asset' => 'flower1.png',   'x' => 93, 'y' => 60, 's' => 75,  'dur' => 21, 'del' => -11, 'rot' => -14,'op' => 0.7],
                ['asset' => 'flower2.png',   'x' => 35, 'y' => 88, 's' => 70,  'dur' => 23, 'del' => -9,  'rot' => 8,  'op' => 0.7],
            ],
        ],
        'easter' => [
            'name' => 'Easter',
            'decorations' => [
                ['asset' => 'egg1.png',    'x' => 8,  'y' => 22, 's' => 85,  'dur' => 19, 'del' => -4,  'rot' => 12, 'op' => 0.8],
                ['asset' => 'egg2.png',    'x' => 86, 'y' => 18, 's' => 75,  'dur' => 22, 'del' => -9,  'rot' => -10,'op' => 0.75],
                ['asset' => 'egg3.png',    'x' => 72, 'y' => 70, 's' => 95,  'dur' => 20, 'del' => -14, 'rot' => 8,  'op' => 0.8],
                ['asset' => 'flower1.png', 'x' => 18, 'y' => 78, 's' => 80,  'dur' => 21, 'del' => -6,  'rot' => 15, 'op' => 0.75],
                ['asset' => 'flower2.png', 'x' => 55, 'y' => 8,  's' => 70,  'dur' => 23, 'del' => -12, 'rot' => -12,'op' => 0.7],
                ['asset' => 'butterfly1.png','x' => 35,'y' => 55,'s' => 70, 'dur' => 17, 'del' => -7,  'rot' => 18, 'op' => 0.7],
            ],
        ],
        'independence' => [
            'name' => 'Independence Day',
            'decorations' => [
                ['asset' => 'star1.png',       'x' => 8,  'y' => 20, 's' => 90,  'dur' => 19, 'del' => -3,  'rot' => 12, 'op' => 0.85],
                ['asset' => 'star2.png',       'x' => 86, 'y' => 22, 's' => 100, 'dur' => 22, 'del' => -8,  'rot' => -10,'op' => 0.8],
                ['asset' => 'star3.png',       'x' => 70, 'y' => 68, 's' => 80,  'dur' => 20, 'del' => -13, 'rot' => 15, 'op' => 0.8],
                ['asset' => 'flag1.png',       'x' => 22, 'y' => 75, 's' => 95,  'dur' => 24, 'del' => -6,  'rot' => -14,'op' => 0.85],
                ['asset' => 'firecracker1.png','x' => 50, 'y' => 10, 's' => 85,  'dur' => 18, 'del' => -11, 'rot' => 8,  'op' => 0.75],
                ['asset' => 'firework1.png',   'x' => 40, 'y' => 55, 's' => 90,  'dur' => 21, 'del' => -5,  'rot' => -10,'op' => 0.7],
                ['asset' => 'firework2.png',   'x' => 93, 'y' => 60, 's' => 75,  'dur' => 23, 'del' => -15, 'rot' => 6,  'op' => 0.7],
            ],
        ],
        'halloween' => [
            'name' => 'Halloween',
            'decorations' => [
                ['asset' => 'ghost1.png',    'x' => 7,  'y' => 18, 's' => 100, 'dur' => 20, 'del' => -4,  'rot' => 6,  'op' => 0.75],
                ['asset' => 'ghost2.png',    'x' => 88, 'y' => 22, 's' => 85,  'dur' => 24, 'del' => -10, 'rot' => -5, 'op' => 0.7],
                ['asset' => 'bat1.png',      'x' => 60, 'y' => 8,  's' => 95,  'dur' => 17, 'del' => -6,  'rot' => 12, 'op' => 0.8],
                ['asset' => 'bat2.png',      'x' => 30, 'y' => 80, 's' => 80,  'dur' => 19, 'del' => -13, 'rot' => -10,'op' => 0.75],
                ['asset' => 'pumpkin1.png',  'x' => 15, 'y' => 72, 's' => 110, 'dur' => 23, 'del' => -8,  'rot' => 4,  'op' => 0.85],
                ['asset' => 'pumpkin2.png',  'x' => 75, 'y' => 65, 's' => 95,  'dur' => 26, 'del' => -15, 'rot' => -4, 'op' => 0.8],
                ['asset' => 'moon1.png',     'x' => 82, 'y' => 10, 's' => 90,  'dur' => 28, 'del' => -2,  'rot' => 3,  'op' => 0.6],
                ['asset' => 'witchhat1.png','x' => 45, 'y' => 90, 's' => 85,  'dur' => 22, 'del' => -11, 'rot' => -8, 'op' => 0.75],
                ['asset' => 'candy1.png',    'x' => 93, 'y' => 45, 's' => 70,  'dur' => 20, 'del' => -7,  'rot' => 14, 'op' => 0.7],
            ],
        ],
    ];
}

/** Default admin-managed config. mode: 'auto' (schedule), 'manual'
 *  (force manual_theme), or 'off' (always classic background). */
function seasonalThemesDefaultConfig(): array {
    return [
        // Disabled by default: a platform admin enables seasonal
        // decorations from Platform Administration > Seasonal Themes.
        'enabled' => false,
        'mode' => 'auto',
        'manual_theme' => 'thanksgiving',
        'holidays' => [
            ['id' => 'halloween',     'name' => 'Halloween',         'theme' => 'halloween',     'enabled' => true,  'start' => ['kind' => 'fixed', 'm' => 10, 'd' => 20], 'end' => ['kind' => 'fixed', 'm' => 10, 'd' => 31]],
            ['id' => 'thanksgiving',  'name' => 'Thanksgiving',      'theme' => 'thanksgiving',  'enabled' => true,  'start' => ['kind' => 'fixed', 'm' => 11, 'd' => 15], 'end' => ['kind' => 'fixed', 'm' => 11, 'd' => 30]],
            ['id' => 'christmas',     'name' => 'Christmas',         'theme' => 'christmas',     'enabled' => true,  'start' => ['kind' => 'fixed', 'm' => 12, 'd' => 1],  'end' => ['kind' => 'fixed', 'm' => 12, 'd' => 26]],
            ['id' => 'new_year',      'name' => 'New Year',          'theme' => 'new_year',      'enabled' => true,  'start' => ['kind' => 'fixed', 'm' => 12, 'd' => 27], 'end' => ['kind' => 'fixed', 'm' => 1,  'd' => 2]],
            ['id' => 'valentines',    'name' => "Valentine's Day",   'theme' => 'valentines',    'enabled' => true,  'start' => ['kind' => 'fixed', 'm' => 2,  'd' => 7],  'end' => ['kind' => 'fixed', 'm' => 2,  'd' => 14]],
            ['id' => 'easter',        'name' => 'Easter',            'theme' => 'easter',        'enabled' => true,  'start' => ['kind' => 'easter', 'offset' => -2], 'end' => ['kind' => 'easter', 'offset' => 1]],
            ['id' => 'independence',  'name' => 'Independence Day',  'theme' => 'independence',  'enabled' => true,  'start' => ['kind' => 'fixed', 'm' => 7,  'd' => 1],  'end' => ['kind' => 'fixed', 'm' => 7,  'd' => 5]],
        ],
        'asset_overrides' => [],
        // Admin-managed decoration layouts: theme_id => [deco, ...] fully
        // replaces the built-in catalog layout for that theme. Absent key
        // means "use the built-in catalog". Values are sanitized on save.
        'theme_decorations' => [],
        // Admin-created themes: theme_id => {name, decorations:[...]}
        'custom_themes' => [],
    ];
}

/** Sanitize an admin-supplied decoration list. Returns a clean array of
 *  decoration slots, or null when the input is not a list. Each slot:
 *  asset (safe image filename), x/y (0-100 %), s (width px), dur/del
 *  (animation seconds), rot (deg), op (opacity), enabled. */
function seasonalSanitizeDecorations($list): ?array {
    if (!is_array($list)) return null;
    $out = [];
    foreach (array_values($list) as $d) {
        if (!is_array($d)) continue;
        $asset = (string)($d['asset'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*\.(svg|png|webp)$/', $asset) || strlen($asset) > 64) continue;
        $out[] = [
            'asset'   => $asset,
            'x'       => max(0, min(100, (float)($d['x'] ?? 50))),
            'y'       => max(0, min(100, (float)($d['y'] ?? 50))),
            's'       => max(16, min(800, (int)($d['s'] ?? 120))),
            'dur'     => max(6, min(120, (int)($d['dur'] ?? 24))),
            'del'     => max(-120, min(0, (int)($d['del'] ?? 0))),
            'rot'     => max(-60, min(60, (float)($d['rot'] ?? 0))),
            'op'      => max(0.05, min(1, (float)($d['op'] ?? 0.75))),
            'enabled' => array_key_exists('enabled', $d) ? !empty($d['enabled']) : true,
        ];
        if (count($out) >= 24) break;
    }
    return $out;
}

/** All known themes: built-in catalog merged with admin custom themes.
 *  Returns theme_id => ['name'=>..., 'custom'=>bool, 'decorations'=>[...]] */
function seasonalAllThemes(array $cfg): array {
    $themes = [];
    foreach (seasonalThemeCatalog() as $tid => $t) {
        $decos = $t['decorations'];
        if (isset($cfg['theme_decorations'][$tid]) && is_array($cfg['theme_decorations'][$tid])) {
            $decos = $cfg['theme_decorations'][$tid];
        }
        $themes[$tid] = ['name' => $t['name'], 'custom' => false, 'decorations' => $decos];
    }
    foreach ((array)($cfg['custom_themes'] ?? []) as $tid => $ct) {
        if (!is_array($ct)) continue;
        $tid = (string)$tid;
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $tid) || isset($themes[$tid])) continue;
        $themes[$tid] = [
            'name' => trim((string)($ct['name'] ?? $tid)) ?: $tid,
            'custom' => true,
            'decorations' => is_array($ct['decorations'] ?? null) ? $ct['decorations'] : [],
        ];
    }
    return $themes;
}

/** Resolve one theme (built-in or custom) with admin decoration
 *  overrides applied. Returns null for unknown ids. */
function seasonalGetTheme(array $cfg, string $tid): ?array {
    return seasonalAllThemes($cfg)[$tid] ?? null;
}

/** Does a decoration asset resolve to something servable? Built-in files
 *  must exist under images/seasonal/<theme>/; uploaded overrides are
 *  assumed present (they were validated at upload time). */
function seasonalAssetExists(array $cfg, string $themeId, string $assetFile): bool {
    $key = $themeId . '/' . $assetFile;
    if (!empty($cfg['asset_overrides'][$key])) return true;
    $path = dirname(__DIR__) . '/images/seasonal/' . $themeId . '/' . $assetFile;
    return is_file($path);
}

/** Easter Sunday (Anonymous Gregorian computus), Y-m-d for a given year. */
function seasonalEasterDate(int $year): string {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** Resolve a date rule ({kind:'fixed',m,d} | {kind:'easter',offset}) to a
 *  Y-m-d string for a given year. Returns null on malformed rules. */
function seasonalRuleDate(array $rule, int $year): ?string {
    $kind = (string)($rule['kind'] ?? '');
    if ($kind === 'fixed') {
        $m = (int)($rule['m'] ?? 0); $d = (int)($rule['d'] ?? 0);
        if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return null;
        return sprintf('%04d-%02d-%02d', $year, $m, $d);
    }
    if ($kind === 'easter') {
        $ts = strtotime(seasonalEasterDate($year) . ' 12:00:00 UTC');
        if ($ts === false) return null;
        return gmdate('Y-m-d', $ts + ((int)($rule['offset'] ?? 0)) * 86400);
    }
    return null;
}

/** Human-readable description of a date rule for the admin UI. */
function seasonalRuleLabel(array $rule): string {
    if (($rule['kind'] ?? '') === 'easter') {
        $off = (int)($rule['offset'] ?? 0);
        if ($off === 0) return 'Easter Sunday';
        return sprintf('Easter %+d days', $off);
    }
    return sprintf('%02d/%02d', (int)($rule['m'] ?? 0), (int)($rule['d'] ?? 0));
}

/** Load + validate config; falls back to defaults on missing/corrupt data. */
function seasonalLoadConfig(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = seasonalThemesDefaultConfig();
    try {
        $s = $pdo->prepare("SELECT sval FROM platform_settings WHERE sk=?");
        $s->execute([SEASONAL_SETTINGS_KEY]);
        $raw = $s->fetchColumn();
        if ($raw) {
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $cfg = array_merge($cfg, array_intersect_key($decoded, $cfg));
                if (isset($decoded['holidays']) && is_array($decoded['holidays'])) $cfg['holidays'] = $decoded['holidays'];
                if (isset($decoded['asset_overrides']) && is_array($decoded['asset_overrides'])) $cfg['asset_overrides'] = $decoded['asset_overrides'];
                if (isset($decoded['theme_decorations']) && is_array($decoded['theme_decorations'])) $cfg['theme_decorations'] = $decoded['theme_decorations'];
                if (isset($decoded['custom_themes']) && is_array($decoded['custom_themes'])) $cfg['custom_themes'] = $decoded['custom_themes'];
            }
        }
    } catch (Throwable $e) {
        error_log('[seasonal-themes] config load failed: ' . $e->getMessage());
    }
    $cache = $cfg;
    return $cfg;
}

function seasonalSaveConfig(PDO $pdo, array $cfg): void {
    $pdo->prepare("INSERT INTO platform_settings (sk, sval) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE sval=VALUES(sval)")
        ->execute([SEASONAL_SETTINGS_KEY, json_encode($cfg)]);
}

/** Compute the concrete start/end Y-m-d for a holiday in a given "season
 *  year". Windows may wrap the new year (e.g. Dec 27 - Jan 2): the end is
 *  searched in year and year+1 and the window is valid if today falls
 *  between start (year Y) and end (year Y or Y+1). */
function seasonalHolidayWindow(array $holiday, int $year): ?array {
    $start = seasonalRuleDate((array)($holiday['start'] ?? []), $year);
    if ($start === null) return null;
    // End may fall in the next calendar year (New Year window).
    $end = seasonalRuleDate((array)($holiday['end'] ?? []), $year);
    if ($end === null || $end < $start) {
        $endNext = seasonalRuleDate((array)($holiday['end'] ?? []), $year + 1);
        if ($endNext === null) return null;
        $end = $endNext;
    }
    return [$start, $end];
}

/** Is a holiday active on a given Y-m-d (America/New_York date)? */
function seasonalHolidayActiveOn(array $holiday, string $ymd): bool {
    $y = (int)substr($ymd, 0, 4);
    foreach ([$y - 1, $y, $y + 1] as $yr) {
        $w = seasonalHolidayWindow($holiday, $yr);
        if ($w && $ymd >= $w[0] && $ymd <= $w[1]) return true;
    }
    return false;
}

/** Resolve which theme (if any) should render right now.
 *  Returns ['theme_id'=>..., 'theme'=>catalog entry, 'holiday'=>?array,
 *           'mode'=>..., 'source'=>'schedule'|'manual'|'preview'] or null. */
function seasonalResolveTheme(PDO $pdo, ?string $previewId = null): ?array {
    $cfg = seasonalLoadConfig($pdo);

    if ($previewId !== null && $previewId !== '') {
        $t = seasonalGetTheme($cfg, $previewId);
        if ($t !== null) {
            return ['theme_id' => $previewId, 'theme' => $t,
                    'holiday' => null, 'mode' => 'preview', 'source' => 'preview'];
        }
        return null;
    }

    if (empty($cfg['enabled'])) return null;

    if (($cfg['mode'] ?? 'auto') === 'manual') {
        $tid = (string)($cfg['manual_theme'] ?? '');
        $t = $tid !== '' ? seasonalGetTheme($cfg, $tid) : null;
        if ($t !== null) {
            return ['theme_id' => $tid, 'theme' => $t,
                    'holiday' => null, 'mode' => 'manual', 'source' => 'manual'];
        }
        return null;
    }

    try {
        $today = (new DateTime('now', new DateTimeZone(SEASONAL_TZ)))->format('Y-m-d');
    } catch (Throwable $e) {
        $today = gmdate('Y-m-d');
    }
    foreach ((array)($cfg['holidays'] ?? []) as $h) {
        if (empty($h['enabled'])) continue;
        $tid = (string)($h['theme'] ?? '');
        if ($tid === '') continue;
        $t = seasonalGetTheme($cfg, $tid);
        if ($t === null) continue;
        if (seasonalHolidayActiveOn($h, $today)) {
            return ['theme_id' => $tid, 'theme' => $t,
                    'holiday' => $h, 'mode' => 'auto', 'source' => 'schedule'];
        }
    }
    return null;
}

/** Public URL for a theme asset. Admin-uploaded overrides are served via
 *  api/seasonal-asset.php; built-ins come from the repo under
 *  images/seasonal/<theme>/. */
function seasonalAssetUrl(array $cfg, string $themeId, string $assetFile): string {
    $key = $themeId . '/' . $assetFile;
    $overrides = (array)($cfg['asset_overrides'] ?? []);
    if (!empty($overrides[$key])) {
        return 'api/seasonal-asset.php?k=' . rawurlencode($key);
    }
    return appAssetUrl('images/seasonal/' . $themeId . '/' . $assetFile);
}

/** Validate an admin-supplied asset key (theme/file) strictly. */
function seasonalValidAssetKey(string $key): bool {
    return (bool)preg_match('/^[a-z0-9_]+\/[a-z0-9_-]+\.(svg|png|webp)$/', $key);
}
