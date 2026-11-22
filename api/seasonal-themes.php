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
                // Roast turkey platters - the seasonal stand-in for the classic circles.
                // Identical primary illustration; plate4 is the one subtle variant.
                ['asset' => 'plate1.png', 'x' => 6,  'y' => 12, 's' => 240, 'dur' => 30, 'del' => -4,  'rot' => 3,  'op' => 0.72],
                ['asset' => 'plate1.png', 'x' => 93, 'y' => 20, 's' => 210, 'dur' => 34, 'del' => -12, 'rot' => -3, 'op' => 0.68],
                ['asset' => 'plate4.png', 'x' => 15, 'y' => 62, 's' => 190, 'dur' => 28, 'del' => -19, 'rot' => 4,  'op' => 0.66],
                ['asset' => 'plate1.png', 'x' => 85, 'y' => 78, 's' => 175, 'dur' => 32, 'del' => -8,  'rot' => -4, 'op' => 0.64],
                ['asset' => 'plate4.png', 'x' => 55, 'y' => 92, 's' => 150, 'dur' => 26, 'del' => -22, 'rot' => 3,  'op' => 0.6],
                // Small falling leaves as the background accent
                ['asset' => 'leaf1.png',  'x' => 30, 'y' => 30, 's' => 55, 'dur' => 22, 'del' => -5,  'rot' => 14, 'op' => 0.6, 'anim' => 'fall'],
                ['asset' => 'leaf3.png',  'x' => 68, 'y' => 25, 's' => 48, 'dur' => 26, 'del' => -14, 'rot' => -12,'op' => 0.55,'anim' => 'fall'],
                ['asset' => 'leaf5.png',  'x' => 45, 'y' => 55, 's' => 52, 'dur' => 24, 'del' => -9,  'rot' => 18, 'op' => 0.58,'anim' => 'fall'],
                ['asset' => 'leaf7.png',  'x' => 80, 'y' => 45, 's' => 44, 'dur' => 20, 'del' => -18, 'rot' => -16,'op' => 0.5, 'anim' => 'fall'],
                ['asset' => 'leaf2.png',  'x' => 20, 'y' => 85, 's' => 46, 'dur' => 28, 'del' => -3,  'rot' => 12, 'op' => 0.55,'anim' => 'fall'],
                ['asset' => 'leaf6.png',  'x' => 60, 'y' => 8,  's' => 42, 'dur' => 25, 'del' => -11, 'rot' => -14,'op' => 0.5, 'anim' => 'fall'],
            ],
        ],
        'christmas' => [
            'name' => 'Christmas',
            'decorations' => [
                // Evergreen wreaths - identical primary; wreath2 is the one subtle variant.
                // Each wreath pairs with a lights-only overlay (same slot params) whose bulbs
                // cycle color and twinkle via fx=lights; the green wreath itself stays static.
                ['asset' => 'wreath1.png',        'x' => 7,  'y' => 14, 's' => 250, 'dur' => 32, 'del' => -5,  'rot' => 2,  'op' => 0.78],
                ['asset' => 'wreath1-lights.png', 'x' => 7,  'y' => 14, 's' => 250, 'dur' => 32, 'del' => -5,  'rot' => 2,  'op' => 0.85, 'fx' => 'lights'],
                ['asset' => 'wreath1.png',        'x' => 92, 'y' => 22, 's' => 220, 'dur' => 36, 'del' => -14, 'rot' => -2, 'op' => 0.74],
                ['asset' => 'wreath1-lights.png', 'x' => 92, 'y' => 22, 's' => 220, 'dur' => 36, 'del' => -14, 'rot' => -2, 'op' => 0.82, 'fx' => 'lights'],
                ['asset' => 'wreath2.png',        'x' => 14, 'y' => 66, 's' => 200, 'dur' => 30, 'del' => -21, 'rot' => 3,  'op' => 0.72],
                ['asset' => 'wreath2-lights.png','x' => 14, 'y' => 66, 's' => 200, 'dur' => 30, 'del' => -21, 'rot' => 3,  'op' => 0.8,  'fx' => 'lights'],
                ['asset' => 'wreath1.png',        'x' => 86, 'y' => 80, 's' => 180, 'dur' => 34, 'del' => -9,  'rot' => -3, 'op' => 0.7],
                ['asset' => 'wreath1-lights.png', 'x' => 86, 'y' => 80, 's' => 180, 'dur' => 34, 'del' => -9,  'rot' => -3, 'op' => 0.78, 'fx' => 'lights'],
                ['asset' => 'wreath1.png',        'x' => 55, 'y' => 90, 's' => 160, 'dur' => 28, 'del' => -25, 'rot' => 2,  'op' => 0.66],
                ['asset' => 'wreath1-lights.png', 'x' => 55, 'y' => 90, 's' => 160, 'dur' => 28, 'del' => -25, 'rot' => 2,  'op' => 0.74, 'fx' => 'lights'],
                // Falling snowflakes as the background accent
                ['asset' => 'snow1.png',   'x' => 30, 'y' => 32, 's' => 62, 'dur' => 24, 'del' => -6,  'rot' => 20, 'op' => 0.65,'anim' => 'fall'],
                ['asset' => 'snow2.png',   'x' => 64, 'y' => 20, 's' => 55, 'dur' => 28, 'del' => -15, 'rot' => -18,'op' => 0.6, 'anim' => 'fall'],
                ['asset' => 'snow1.png',   'x' => 44, 'y' => 58, 's' => 58, 'dur' => 26, 'del' => -10, 'rot' => 16, 'op' => 0.62,'anim' => 'fall'],
                ['asset' => 'snow2.png',   'x' => 80, 'y' => 50, 's' => 50, 'dur' => 22, 'del' => -19, 'rot' => -20,'op' => 0.58,'anim' => 'fall'],
                ['asset' => 'snow1.png',   'x' => 20, 'y' => 82, 's' => 54, 'dur' => 30, 'del' => -4,  'rot' => 14, 'op' => 0.6, 'anim' => 'fall'],
                ['asset' => 'snow2.png',   'x' => 52, 'y' => 38, 's' => 48, 'dur' => 27, 'del' => -22, 'rot' => 18, 'op' => 0.56,'anim' => 'fall'],
                ['asset' => 'snow1.png',   'x' => 90, 'y' => 62, 's' => 46, 'dur' => 25, 'del' => -13, 'rot' => -14,'op' => 0.55,'anim' => 'fall'],
            ],
        ],
        'new_year' => [
            'name' => 'New Year',
            'decorations' => [
                // "2027" medallions - identical primary; medal2 is the one subtle variant
                ['asset' => 'medal1.png', 'x' => 7,  'y' => 14, 's' => 240, 'dur' => 30, 'del' => -4,  'rot' => 2,  'op' => 0.72],
                ['asset' => 'medal1.png', 'x' => 92, 'y' => 22, 's' => 210, 'dur' => 34, 'del' => -13, 'rot' => -3, 'op' => 0.68],
                ['asset' => 'medal2.png', 'x' => 15, 'y' => 64, 's' => 190, 'dur' => 28, 'del' => -20, 'rot' => 4,  'op' => 0.66],
                ['asset' => 'medal1.png', 'x' => 86, 'y' => 80, 's' => 175, 'dur' => 32, 'del' => -7,  'rot' => -4, 'op' => 0.64],
                ['asset' => 'medal1.png', 'x' => 55, 'y' => 91, 's' => 155, 'dur' => 26, 'del' => -23, 'rot' => 3,  'op' => 0.6],
                // Fireworks + falling confetti as the background accent
                ['asset' => 'firework1.png','x' => 35, 'y' => 30, 's' => 90,  'dur' => 24, 'del' => -6,  'rot' => 10, 'op' => 0.6],
                ['asset' => 'firework2.png','x' => 68, 'y' => 22, 's' => 75,  'dur' => 28, 'del' => -16, 'rot' => -8, 'op' => 0.55],
                ['asset' => 'confetti1.png', 'x' => 48, 'y' => 55, 's' => 55, 'dur' => 26, 'del' => -10, 'rot' => 16, 'op' => 0.55,'anim' => 'fall'],
                ['asset' => 'confetti1.png', 'x' => 82, 'y' => 48, 's' => 50,  'dur' => 30, 'del' => -18, 'rot' => -14,'op' => 0.5, 'anim' => 'fall'],
                ['asset' => 'confetti1.png', 'x' => 22, 'y' => 84, 's' => 48,  'dur' => 24, 'del' => -3,  'rot' => 12, 'op' => 0.5, 'anim' => 'fall'],
            ],
        ],
        'valentines' => [
            'name' => "Valentine's Day",
            'decorations' => [
                // Round flower posies - identical primary; posy2 is the one subtle variant
                ['asset' => 'posy1.png', 'x' => 7,  'y' => 14, 's' => 240, 'dur' => 30, 'del' => -5,  'rot' => -2, 'op' => 0.7],
                ['asset' => 'posy1.png', 'x' => 92, 'y' => 22, 's' => 210, 'dur' => 34, 'del' => -13, 'rot' => 3,  'op' => 0.66],
                ['asset' => 'posy2.png', 'x' => 15, 'y' => 64, 's' => 195, 'dur' => 28, 'del' => -20, 'rot' => -3, 'op' => 0.64],
                ['asset' => 'posy1.png', 'x' => 86, 'y' => 80, 's' => 175, 'dur' => 32, 'del' => -8,  'rot' => 4,  'op' => 0.62],
                ['asset' => 'posy1.png', 'x' => 55, 'y' => 91, 's' => 155, 'dur' => 26, 'del' => -24, 'rot' => -2, 'op' => 0.58],
                // Small drifting hearts as the background accent
                ['asset' => 'heart1.png', 'x' => 32, 'y' => 32, 's' => 55, 'dur' => 24, 'del' => -6,  'rot' => 12, 'op' => 0.6, 'anim' => 'fall'],
                ['asset' => 'heart2.png', 'x' => 67, 'y' => 24, 's' => 48, 'dur' => 28, 'del' => -15, 'rot' => -10,'op' => 0.55,'anim' => 'fall'],
                ['asset' => 'heart1.png', 'x' => 46, 'y' => 58, 's' => 52, 'dur' => 26, 'del' => -10, 'rot' => 14, 'op' => 0.58,'anim' => 'fall'],
                ['asset' => 'heart2.png', 'x' => 81, 'y' => 50, 's' => 44, 'dur' => 22, 'del' => -19, 'rot' => -12,'op' => 0.5, 'anim' => 'fall'],
                ['asset' => 'heart1.png', 'x' => 22, 'y' => 85, 's' => 46, 'dur' => 30, 'del' => -4,  'rot' => 10, 'op' => 0.55,'anim' => 'fall'],
            ],
        ],
        'easter' => [
            'name' => 'Easter',
            'decorations' => [
                // Decorated eggs - identical primary; egg2 is the one subtle variant
                ['asset' => 'egg1.png', 'x' => 7,  'y' => 14, 's' => 230, 'dur' => 30, 'del' => -4,  'rot' => -3, 'op' => 0.7],
                ['asset' => 'egg1.png', 'x' => 92, 'y' => 22, 's' => 200, 'dur' => 34, 'del' => -12, 'rot' => 3,  'op' => 0.66],
                ['asset' => 'egg2.png', 'x' => 15, 'y' => 64, 's' => 190, 'dur' => 28, 'del' => -19, 'rot' => -4, 'op' => 0.64],
                ['asset' => 'egg1.png', 'x' => 86, 'y' => 80, 's' => 170, 'dur' => 32, 'del' => -7,  'rot' => 4,  'op' => 0.62],
                ['asset' => 'egg1.png', 'x' => 55, 'y' => 91, 's' => 150, 'dur' => 26, 'del' => -22, 'rot' => -2, 'op' => 0.58],
                // Spring accents - gentle drifting flowers and a butterfly
                ['asset' => 'flower1.png',   'x' => 32, 'y' => 32, 's' => 50, 'dur' => 24, 'del' => -6,  'rot' => 12, 'op' => 0.6, 'anim' => 'fall'],
                ['asset' => 'butterfly1.png','x' => 66, 'y' => 24, 's' => 60, 'dur' => 20, 'del' => -14, 'rot' => -10,'op' => 0.6],
                ['asset' => 'flower1.png',   'x' => 46, 'y' => 56, 's' => 46, 'dur' => 28, 'del' => -10, 'rot' => 15, 'op' => 0.55,'anim' => 'fall'],
                ['asset' => 'butterfly1.png','x' => 82, 'y' => 48, 's' => 55, 'dur' => 23, 'del' => -18, 'rot' => -8, 'op' => 0.55],
                ['asset' => 'flower1.png',   'x' => 22, 'y' => 84, 's' => 44, 'dur' => 26, 'del' => -3,  'rot' => 10, 'op' => 0.5, 'anim' => 'fall'],
            ],
        ],
        'independence' => [
            'name' => 'Independence Day',
            'decorations' => [
                // Patriotic rosettes - identical primary; rosette2 is the one subtle variant
                ['asset' => 'rosette1.png', 'x' => 7,  'y' => 14, 's' => 245, 'dur' => 30, 'del' => -4,  'rot' => 2,  'op' => 0.74],
                ['asset' => 'rosette1.png', 'x' => 92, 'y' => 22, 's' => 215, 'dur' => 34, 'del' => -13, 'rot' => -3, 'op' => 0.7],
                ['asset' => 'rosette2.png', 'x' => 15, 'y' => 64, 's' => 195, 'dur' => 28, 'del' => -20, 'rot' => 4,  'op' => 0.68],
                ['asset' => 'rosette1.png', 'x' => 86, 'y' => 80, 's' => 175, 'dur' => 32, 'del' => -8,  'rot' => -4, 'op' => 0.66],
                ['asset' => 'rosette1.png', 'x' => 55, 'y' => 91, 's' => 155, 'dur' => 26, 'del' => -24, 'rot' => 3,  'op' => 0.62],
                // Fireworks + tiny stars as the background accent
                ['asset' => 'firework1.png','x' => 35, 'y' => 30, 's' => 95,  'dur' => 24, 'del' => -6,  'rot' => 10, 'op' => 0.62],
                ['asset' => 'firework2.png','x' => 68, 'y' => 22, 's' => 80,  'dur' => 28, 'del' => -16, 'rot' => -8, 'op' => 0.58],
                ['asset' => 'star1.png',    'x' => 48, 'y' => 55, 's' => 55,  'dur' => 22, 'del' => -10, 'rot' => 15, 'op' => 0.6],
                ['asset' => 'star1.png',    'x' => 82, 'y' => 48, 's' => 50,  'dur' => 26, 'del' => -18, 'rot' => -12,'op' => 0.55],
                ['asset' => 'firework1.png','x' => 22, 'y' => 84, 's' => 85,  'dur' => 30, 'del' => -3,  'rot' => 8,  'op' => 0.58],
            ],
        ],
        'halloween' => [
            'name' => 'Halloween',
            'decorations' => [
                // Jack-o'-lantern pumpkins - identical primary; pumpkin2 is the one subtle variant
                ['asset' => 'pumpkin1.png', 'x' => 7,  'y' => 14, 's' => 240, 'dur' => 30, 'del' => -4,  'rot' => -2, 'op' => 0.72],
                ['asset' => 'pumpkin1.png', 'x' => 92, 'y' => 22, 's' => 210, 'dur' => 34, 'del' => -13, 'rot' => 3,  'op' => 0.68],
                ['asset' => 'pumpkin2.png', 'x' => 15, 'y' => 64, 's' => 195, 'dur' => 28, 'del' => -20, 'rot' => -3, 'op' => 0.66],
                ['asset' => 'pumpkin1.png', 'x' => 86, 'y' => 80, 's' => 175, 'dur' => 32, 'del' => -8,  'rot' => 4,  'op' => 0.64],
                ['asset' => 'pumpkin1.png', 'x' => 55, 'y' => 91, 's' => 155, 'dur' => 26, 'del' => -24, 'rot' => -2, 'op' => 0.6],
                // Minimal spooky accents - a couple of small bats
                ['asset' => 'bat1.png', 'x' => 33, 'y' => 32, 's' => 70, 'dur' => 20, 'del' => -6,  'rot' => 12, 'op' => 0.6],
                ['asset' => 'bat1.png', 'x' => 67, 'y' => 25, 's' => 60, 'dur' => 24, 'del' => -15, 'rot' => -10,'op' => 0.55],
                ['asset' => 'bat1.png', 'x' => 82, 'y' => 52, 's' => 55, 'dur' => 22, 'del' => -19, 'rot' => 14, 'op' => 0.5],
                ['asset' => 'bat1.png', 'x' => 22, 'y' => 85, 's' => 58, 'dur' => 26, 'del' => -4,  'rot' => -12,'op' => 0.55],
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
            'anim'    => in_array($d['anim'] ?? '', ['float', 'fall'], true) ? $d['anim'] : 'float',
            'fx'      => ($d['fx'] ?? '') === 'lights' ? 'lights' : '',
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
