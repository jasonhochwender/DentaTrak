<?php
/**
 * Feature Flags
 * Controls feature visibility based on environment variables
 */

// Default feature states
$FEATURE_DEFAULTS = [
    'BILLING_ENABLED' => false,     // Master billing feature flag — false = billing completely disabled.
                                    // Set BILLING_ENABLED=true in .env (local) or Cloud Run env vars (prod)
                                    // only after Stripe is fully configured.
    'SHOW_BILLING' => false,
    'SHOW_NOTIFICATIONS' => false,
    'SHOW_AT_RISK' => false,
    'SHOW_COMMENTS' => false,
    'SHOW_REVISION_HISTORY' => false,
    'SHOW_ACTIVITY_TIMELINE' => false,
    'SHOW_AI_CHAT' => false,
    'SHOW_IN_STATUS' => false,
    'SHOW_DEV_TOOLS' => false,
    'SHOW_REVISION_COUNT' => true,
    'SHOW_GOOGLE_DRIVE_BACKUP' => false,
    'SHOW_TOUR' => false,
];

/**
 * Check if a feature is enabled
 *
 * @param string $featureName The feature flag name
 * @return bool True if feature is enabled
 */
function isFeatureEnabled($featureName) {
    global $FEATURE_DEFAULTS, $appConfig;

    // Check environment variable first (process env, then Dotenv-populated $_ENV)
    $envValue = getenv($featureName);
    if ($envValue === false) {
        $envValue = $_ENV[$featureName] ?? null;
    }
    if ($envValue !== false && $envValue !== null && $envValue !== '') {
        return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
    }

    // SHOW_DEV_TOOLS has environment-specific defaults:
    // - Local development (MAMP/localhost): visible by default
    // - Cloud Run / production: hidden by default
    if ($featureName === 'SHOW_DEV_TOOLS') {
        // Prefer the environment-derived value already computed by appConfig
        if (isset($appConfig) && is_array($appConfig) && array_key_exists('show_dev_tools', $appConfig)) {
            return (bool) $appConfig['show_dev_tools'];
        }

        // Cloud Run = production = hidden
        if (getenv('K_SERVICE')) {
            return false;
        }

        // Local development heuristic
        $host = strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $hostWithoutPort = explode(':', $host)[0];
        $localHosts = ['localhost', '127.0.0.1', '[::1]'];
        if (in_array($hostWithoutPort, $localHosts, true) || str_ends_with($hostWithoutPort, '.localhost')) {
            return true;
        }

        return false;
    }

    // Return default value
    return $FEATURE_DEFAULTS[$featureName] ?? false;
}

/**
 * Get all feature flags as JSON for JavaScript
 *
 * @return string JSON-encoded feature flags
 */
function getFeatureFlagsJson() {
    global $FEATURE_DEFAULTS;

    $flags = [];
    foreach ($FEATURE_DEFAULTS as $name => $default) {
        $flags[$name] = isFeatureEnabled($name);
    }

    return json_encode($flags);
}
