<?php
/**
 * Feature Flags Configuration
 * 
 * Centralized feature visibility control for Dentatrak.
 * Set flags to true to show features, false to hide them.
 * All flags default to false for a minimal UI experience.
 * 
 * To re-enable features, simply change the flag value to true.
 * No code changes required - just configuration.
 */

$featureFlags = [
    // Case Cards / Case List
    'SHOW_AT_RISK' => false,            // At Risk badge on case cards and list views
    'SHOW_REVISION_COUNT' => true,     // Revision count inline next to patient name
    'SHOW_IN_STATUS' => false,          // "In Status" days counter on case cards
    
    // Case Detail / Edit Case
    'SHOW_AT_RISK_BANNER' => false,     // At Risk banner at top of Edit Case view
    'SHOW_ACTIVITY_TIMELINE' => false,   // Activity Timeline section on Details tab
    'SHOW_REVISION_HISTORY' => false,   // Revision History tab in Edit Case modal
    'SHOW_COMMENTS' => false,           // Comments/comment threads in case detail
    
    // Global / App-wide
    'SHOW_NOTIFICATIONS' => false,      // Notification bell, badges, counters, panels
    'SHOW_BILLING' => false,            // Billing menu, plan name/link. Off = all users on Control plan
    'SHOW_AI_CHAT' => false,            // Floating "Ask" AI chat button and panel
];

/**
 * Get a feature flag value
 * @param string $flag The flag name
 * @return bool Whether the feature is enabled
 */
function isFeatureEnabled(string $flag): bool {
    global $featureFlags;
    return isset($featureFlags[$flag]) && $featureFlags[$flag] === true;
}

/**
 * Get all feature flags as an associative array
 * @return array All feature flags
 */
function getAllFeatureFlags(): array {
    global $featureFlags;
    return $featureFlags;
}

/**
 * Get feature flags as JSON for frontend consumption
 * Includes case_required_fields from appConfig
 * @return string JSON-encoded feature flags
 */
function getFeatureFlagsJson(): string {
    global $featureFlags, $appConfig;
    $output = $featureFlags;
    // Include case required fields config for frontend validation
    if (isset($appConfig['case_required_fields'])) {
        $output['case_required_fields'] = $appConfig['case_required_fields'];
    }
    return json_encode($output);
}
