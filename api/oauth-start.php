<?php
/**
 * OAuth 2.0 Start Flow
 * 
 * This script initiates the OAuth 2.0 flow with Google by redirecting the user
 * to Google's authorization page.
 */

// Start the session to store the state parameter
session_start();

// Include configuration and redirect URI settings
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/redirect-uris.php';

// Generate a random state parameter for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

// Track whether this auth flow was started in a popup window
$mode = (isset($_GET['mode']) && $_GET['mode'] === 'popup') ? 'popup' : 'redirect';
$_SESSION['oauth_mode'] = $mode;

// Define the required scopes for LOGIN ONLY
// Drive scopes are requested separately when user connects Google Drive
$scopes = [
    'openid',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
];

// Build the authorization URL
$authUrl = 'https://accounts.google.com/o/oauth2/auth';

// Use the redirect URI from our central configuration
// This ensures it matches what's in Google Cloud Console
// $redirectUri is already defined in redirect-uris.php

$authParams = [
    'response_type' => 'code',
    'client_id'     => $appConfig['google_client_id'],
    'redirect_uri'  => $redirectUri,
    'scope'         => implode(' ', $scopes),
    'state'         => $state,
    'access_type'   => 'online',  // No refresh token needed for login-only
    'prompt'        => 'select_account'  // Allow account selection without forcing consent
];

// Store the redirect URI in the session for verification
$_SESSION['oauth_redirect_uri'] = $authParams['redirect_uri'];

// Redirect to Google's authorization page
header('Location: ' . $authUrl . '?' . http_build_query($authParams));
exit;
