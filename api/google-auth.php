<?php
/**
 * Google Drive Authorization Start
 * 
 * This endpoint initiates the OAuth flow for Google Drive access ONLY.
 * This is separate from the login flow (oauth-start.php).
 * User must be logged in before connecting Google Drive.
 */

// Use centralized session handling so this shares the same session as index.php/main.php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/google-drive.php';

// Ensure user is logged in before allowing Drive connection
if (!isset($_SESSION['db_user_id'])) {
    header('Location: ../login.php?error=not_logged_in');
    exit;
}

// Prepare Google client for Drive OAuth
$client = getGoogleClient();

// Generate authorization URL for Drive scopes only
$authUrl = $client->createAuthUrl();

// Redirect to Google's OAuth page for Drive authorization
header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
exit;
