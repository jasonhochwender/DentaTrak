<?php
/**
 * Debug endpoint to check dev tools configuration
 * Returns info about feature flags and super users (without exposing sensitive data)
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/dev-tools-access.php';

header('Content-Type: application/json');

// Get current user email from session
session_start();
$userEmail = $_SESSION['user_email'] ?? null;

// Check configuration
$response = [
    'environment' => $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'unknown',
    'feature_flag_SHOW_DEV_TOOLS' => isFeatureEnabled('SHOW_DEV_TOOLS'),
    'super_users_count' => count($appConfig['super_users'] ?? []),
    'super_users_configured' => !empty($appConfig['super_users']),
    'current_user_email' => $userEmail ? substr($userEmail, 0, 3) . '***' : null,
    'is_super_user' => $userEmail ? isSuperUser($appConfig, $userEmail) : false,
    'can_access_dev_tools' => $userEmail ? canAccessDevTools($appConfig, $userEmail) : false,
    'raw_super_users_env' => getEnvVar('SUPER_USERS') ? 'SET (length: ' . strlen(getEnvVar('SUPER_USERS')) . ')' : 'NOT SET',
];

// For debugging, show first few chars of each super user email (masked)
if (!empty($appConfig['super_users'])) {
    $maskedUsers = array_map(function($email) {
        return substr($email, 0, 3) . '***@' . substr(strrchr($email, '@'), 1);
    }, $appConfig['super_users']);
    $response['super_users_masked'] = $maskedUsers;
}

echo json_encode($response, JSON_PRETTY_PRINT);
