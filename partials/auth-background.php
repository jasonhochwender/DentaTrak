<?php
/**
 * Auth-page background: seasonal decorations when a theme is active,
 * otherwise the classic floating circles. Drop-in replacement for the
 * .login-bg-shapes block on login/signup/password/2FA pages.
 *
 * Decorations are purely decorative: aria-hidden, pointer-events none,
 * non-interactive. Honors prefers-reduced-motion via css/seasonal.css.
 * Any failure falls back to the classic background - auth is never
 * affected by theme rendering.
 *
 * Preview: append ?seasonal_preview=<theme_id> to any auth page to see a
 * theme regardless of schedule (decorations only; grants nothing).
 */

require_once __DIR__ . '/../api/seasonal-themes.php';

function renderAuthBackground(PDO $pdo): void {
    $previewId = isset($_GET['seasonal_preview']) && is_string($_GET['seasonal_preview'])
        ? substr($_GET['seasonal_preview'], 0, 40) : null;
    $previewId = $previewId !== null && $previewId !== '' ? $previewId : null;

    $theme = null;
    try {
        $theme = seasonalResolveTheme($pdo, $previewId);
    } catch (Throwable $e) {
        error_log('[seasonal] resolve failed: ' . $e->getMessage());
    }

    if ($theme === null) {
        echo '<div class="login-bg-shapes">'
            . '<div class="shape shape-1"></div>'
            . '<div class="shape shape-2"></div>'
            . '<div class="shape shape-3"></div>'
            . '<div class="shape shape-4"></div>'
            . '<div class="shape shape-5"></div>'
            . '</div>';
        return;
    }

    $cfg = seasonalLoadConfig($pdo);
    // Only render slots that are enabled and whose artwork resolves
    // (built-in file present or admin-uploaded override); anything else
    // is skipped and an empty result falls back to the classic circles.
    $decos = array_values(array_filter((array)($theme['theme']['decorations'] ?? []), function ($d) use ($cfg, $theme) {
        if (!is_array($d)) return false;
        if (isset($d['enabled']) && empty($d['enabled'])) return false;
        if (empty($d['asset'])) return false;
        return seasonalAssetExists($cfg, $theme['theme_id'], (string)$d['asset']);
    }));
    if (!$decos) {
        // Theme resolved but has no artwork - fall back to classic.
        echo '<div class="login-bg-shapes">'
            . '<div class="shape shape-1"></div>'
            . '<div class="shape shape-2"></div>'
            . '<div class="shape shape-3"></div>'
            . '<div class="shape shape-4"></div>'
            . '<div class="shape shape-5"></div>'
            . '</div>';
        return;
    }

    echo '<div class="login-bg-seasonal" data-seasonal-theme="' . htmlspecialchars($theme['theme_id'], ENT_QUOTES) . '" aria-hidden="true">';
    foreach ($decos as $i => $d) {
        $src = seasonalAssetUrl($cfg, $theme['theme_id'], (string)$d['asset']);
        $style = sprintf(
            'left:%s%%;top:%s%%;width:%dpx;--dur:%ds;--del:%ds;--rot:%sdeg;--op:%s',
            (float)$d['x'], (float)$d['y'], (int)$d['s'],
            (int)$d['dur'], (int)$d['del'], (float)$d['rot'], (float)$d['op']
        );
        $anim = (($d['anim'] ?? 'float') === 'fall') ? ' seasonal-deco--fall' : '';
        $fx = (($d['fx'] ?? '') === 'lights') ? ' seasonal-deco--fx-lights' : '';
        echo '<img class="seasonal-deco' . $anim . $fx . '" src="' . htmlspecialchars($src, ENT_QUOTES)
            . '" alt="" loading="lazy" draggable="false" style="' . $style . '">';
    }
    echo '</div>';
}
