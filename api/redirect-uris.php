<?php
/**
 * Shared Redirect URIs Configuration
 * 
 * Contains the redirect URIs for OAuth to ensure consistency
 * 
 * NOTE: This file expects $appConfig to already be available (include appConfig.php first)
 */

// Get the current host
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Determine environment based on host
$isLocalhost = ($host === 'localhost' || $host === '127.0.0.1');

// Get the appropriate redirect URI
// For localhost/development, ALWAYS use http
// For production, use baseUrl from config
if ($isLocalhost) {
    $redirectUri = 'http://localhost/DentaTrak/api/google-auth-callback.php';
} else {
    global $appConfig;
    $redirectUri = rtrim($appConfig['baseUrl'], '/') . '/api/google-auth-callback.php';
}
