<?php
/**
 * Feature Flags
 * Controls feature visibility based on environment variables
 */

// Default feature states
$FEATURE_DEFAULTS = [
    'SHOW_BILLING' => false,
    'SHOW_NOTIFICATIONS' => false,
    'SHOW_AT_RISK' => false,
    'SHOW_COMMENTS' => false,
    'SHOW_REVISION_HISTORY' => false,
    'SHOW_ACTIVITY_TIMELINE' => false,
    'SHOW_AI_CHAT' => false,
    'SHOW_IN_STATUS' => false,
    'SHOW_DEV_TOOLS' => true,
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
    global $FEATURE_DEFAULTS;
    
    // Check environment variable first
    $envValue = getenv($featureName);
    if ($envValue !== false) {
        return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
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
