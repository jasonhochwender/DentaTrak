<?php
/**
 * Google Drive Authorization Callback
 * 
 * This handles the callback from Google's OAuth flow for DRIVE authorization only.
 * This is separate from the login flow (google-auth-callback.php).
 * Drive tokens are stored separately from login tokens.
 */

require_once __DIR__ . '/session.php'; // use centralized session handling
require_once __DIR__ . '/google-drive.php';

// Ensure user is logged in before allowing Drive connection
if (!isset($_SESSION['db_user_id'])) {
    header('Location: ../login.php?error=not_logged_in');
    exit;
}

// Check if there's an auth code in the URL
if (isset($_GET['code'])) {
    try {
        $client = getGoogleClient();
        
        // Exchange auth code for access token
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        $client->setAccessToken($token);
        
        // Store the DRIVE tokens in session (separate from login tokens)
        $_SESSION['google_drive_token'] = $token;
        if (isset($token['refresh_token'])) {
            $_SESSION['google_drive_refresh_token'] = $token['refresh_token'];
        }
        
        // Mark Drive as connected
        $_SESSION['google_drive_connected'] = true;
        
        // Redirect back to the main page with success message
        header('Location: ../main.php?drive_connected=1');
        exit;
    } catch (Exception $e) {
        // Log the error
        error_log('Google Drive Auth Error: ' . $e->getMessage());
        
        // Display error and link to retry
        echo "<h1>Google Drive Connection Error</h1>";
        echo "<p>There was an error connecting to Google Drive: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><a href='google-auth.php'>Try Again</a> or <a href='../main.php'>Return to Dashboard</a></p>";
    }
} else if (isset($_GET['error'])) {
    // User denied permission or other error
    error_log('Google Drive Auth Error: ' . $_GET['error']);
    
    echo "<h1>Google Drive Connection Canceled</h1>";
    echo "<p>Google Drive connection was canceled or denied: " . htmlspecialchars($_GET['error']) . "</p>";
    echo "<p><a href='../main.php'>Return to Dashboard</a></p>";
} else {
    // No code or error in the URL
    header('Location: ../main.php');
    exit;
}
