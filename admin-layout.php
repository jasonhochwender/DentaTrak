<?php
/**
 * Platform Administration - shared page chrome.
 *
 * Provides the consistent administrative layout used by every platform
 * administration page: persistent left navigation (collapsible to a top
 * strip on small screens), a top bar with the section title, production
 * environment indicator, language selector, and a return link to the
 * main application.
 *
 * Authorization is NOT performed here - each page keeps its own server-
 * side gate (super user, or the local development environment) before
 * calling paAdminChrome(). Never render this chrome for unauthorized
 * users.
 */

require_once __DIR__ . '/api/dev-tools-access.php';
require_once __DIR__ . '/api/asset-url.php';

if (!function_exists('paAdminSections')) {
    /** Canonical section map: key => [url, locale key]. */
    function paAdminSections(): array {
        $sections = [
            'overview' => ['platform-admin.php', 'admin_nav.overview'],
            'practices' => ['admin-practices.php', 'admin_nav.practices'],
            'usage' => ['practice-usage-report.php', 'admin_nav.usage'],
            'ai' => ['dev-ask-usage.php', 'admin_nav.ai_analytics'],
            'test' => ['test-diagnostics.php', 'admin_nav.test_diag'],
        ];
        // Marketing Outreach stays hidden until the feature flag is enabled.
        if (function_exists('isFeatureEnabled') && isFeatureEnabled('MARKETING_OUTREACH')) {
            $sections['marketing'] = ['marketing-outreach.php', 'admin_nav.marketing'];
        }
        return $sections;
    }
}

if (!function_exists('paAdminEscape')) {
    function paAdminEscape($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('paAdminChrome')) {
    /**
     * Emit <body>, the admin shell (sidebar + topbar), and open the
     * content container. Pair with paAdminChromeEnd() before </body>.
     *
     * @param string $section  Key from paAdminSections() for the active item.
     * @param string $title    Page heading shown in the top bar.
     * @param string $subtitle Optional supporting line under the heading.
     */
    function paAdminChrome(string $section, string $title, string $subtitle = ''): void {
        global $appConfig, $csrfToken;

        $envName = function_exists('getEnvironmentDisplayName')
            ? getEnvironmentDisplayName($appConfig) : 'Production';
        $isProd = function_exists('isProductionOrUAT') && isProductionOrUAT($appConfig);
        $appName = $appConfig['appName'] ?? 'DentaTrak';

        echo '<body class="pa-body">';
        echo '<div class="pa-shell">';

        // ---- Sidebar navigation ----
        echo '<aside class="pa-sidebar">';
        echo '<div class="pa-brand"><span class="pa-brand-app">' . paAdminEscape($appName) . '</span>'
            . '<span class="pa-brand-sub">' . paAdminEscape(t('admin_nav.brand')) . '</span></div>';
        echo '<nav class="pa-nav" aria-label="' . paAdminEscape(t('admin_nav.nav_label')) . '">';
        foreach (paAdminSections() as $key => [$url, $labelKey]) {
            $active = $key === $section ? ' is-active' : '';
            $aria = $key === $section ? ' aria-current="page"' : '';
            echo '<a class="pa-nav-link' . $active . '" href="' . paAdminEscape($url) . '"' . $aria . '>'
                . paAdminEscape(t($labelKey)) . '</a>';
        }
        echo '</nav>';
        echo '<div class="pa-sidebar-foot">';
        echo '<span class="pa-env pa-env-' . ($isProd ? 'prod' : 'dev') . '">'
            . paAdminEscape($envName) . '</span>';
        echo '<a class="pa-back-app" href="main.php">&larr; ' . paAdminEscape(t('admin_nav.back_to_app')) . '</a>';
        echo '</div>';
        echo '</aside>';

        // ---- Content column ----
        echo '<div class="pa-col">';
        echo '<header class="pa-topbar">';
        echo '<div class="pa-topbar-title">';
        echo '<h1>' . paAdminEscape($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="pa-topbar-sub">' . paAdminEscape($subtitle) . '</p>';
        }
        echo '</div>';
        echo '<div class="pa-topbar-actions">';
        echo '<span class="pa-env pa-env-' . ($isProd ? 'prod' : 'dev') . ' pa-env-top">'
            . paAdminEscape($envName) . '</span>';
        if (function_exists('renderLanguageSelector') && function_exists('getResolvedLocale')) {
            echo renderLanguageSelector('api/save-user-language.php', getResolvedLocale(), false, $csrfToken ?? '');
        }
        echo '<a class="pa-back-app pa-back-top" href="main.php">&larr; ' . paAdminEscape(t('admin_nav.back_to_app')) . '</a>';
        echo '</div>';
        echo '</header>';
        echo '<main class="pa-content">';
    }
}

if (!function_exists('paAdminChromeEnd')) {
    /** Close the shell opened by paAdminChrome(). Call before </body>. */
    function paAdminChromeEnd(): void {
        echo '</main>';
        echo '</div>'; // .pa-col
        echo '</div>'; // .pa-shell
        echo '</body>';
    }
}
