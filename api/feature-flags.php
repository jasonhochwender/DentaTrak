<?php
/**
 * Feature Flags
 * Controls feature visibility based on environment variables
 */

// Default feature states
$FEATURE_DEFAULTS = [
    'BILLING_ENABLED' => true,     // Master billing feature flag — false = billing completely disabled.
                                    // Set BILLING_ENABLED=true in .env (local) or Cloud Run env vars (prod)
                                    // only after Stripe is fully configured.
    'SHOW_BILLING' => true,
    'SHOW_NOTIFICATIONS' => true,  // Off globally until Phase 3 rollout approval
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
    'SHOW_LAB_INSIGHTS' => true,   // Lab Insights foundation (assignment-history
                                    // tracking, Lab designation on users/labels).
                                    // Must remain OFF until analytics/UI are built
                                    // and explicitly approved for rollout.
    'SHOW_CASE_DOWNLOAD_ALL' => true, // Bulk case attachment ZIP download.
                                       // Default false until controlled rollout.
];

/**
 * Check if a feature is enabled
 *
 * @param string $featureName The feature flag name
 * @return bool True if feature is enabled
 */
function isFeatureEnabled($featureName) {
    global $FEATURE_DEFAULTS, $appConfig;

    // Check environment variable (process env, Dotenv-populated $_ENV, $_SERVER)
    $envValue = getEnvVar($featureName);
    if ($envValue !== null && $envValue !== '') {
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
        if (getEnvVar('K_SERVICE')) {
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

    // In local/test environments, allow a session-level override for
    // test fixtures.  This is never available in Cloud Run because the
    // override is keyed to a trusted server-side environment indicator,
    // not a user-controlled HTTP_HOST.
    if ($featureName === 'SHOW_NOTIFICATIONS' && session_status() === PHP_SESSION_ACTIVE) {
        $currentEnv = $appConfig['current_environment'] ?? 'production';
        $isTestMode = in_array($currentEnv, ['development', 'test', 'local'], true)
            || getEnvVar('DENTATRAK_TEST_MODE', 'false') === 'true';
        if ($isTestMode && isset($_SESSION[$featureName])) {
            return (bool)$_SESSION[$featureName];
        }
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
