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

// ---- one-time Gmail-send admin setup (state-gated; Drive flow untouched) ----
if (isset($_GET['state']) && str_starts_with($_GET['state'], 'gmail_setup_')) {
    $nonce = substr($_GET['state'], strlen('gmail_setup_'));
    $nf = sys_get_temp_dir() . '/gmail_setup_nonce';
    $expect = @file_get_contents($nf);
    if (!$expect || !hash_equals(trim($expect), $nonce) || !isset($_GET['code'])) {
        http_response_code(403); exit('invalid setup state');
    }
    @unlink($nf); // single-use
    $tok = json_decode(file_get_contents('https://oauth2.googleapis.com/token', false,
        stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'code' => $_GET['code'],
            'client_id' => $appConfig['google_client_id'],
            'client_secret' => $appConfig['google_client_secret'],
            'redirect_uri' => rtrim($appConfig['baseUrl'], '/') . '/api/google-drive-callback.php',
            'grant_type' => 'authorization_code'])]])) ?: '{}', true);
    $rt = $tok['refresh_token'] ?? null;
    if (!$rt) { http_response_code(502); exit('no refresh token - re-run the setup link'); }
    $meta = json_decode(file_get_contents(
        'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token',
        false, stream_context_create(['http' => ['header' => 'Metadata-Flavor: Google']])), true);
    $smUrl = 'https://secretmanager.googleapis.com/v1/projects/dtk-prod-core/secrets/dtk-prod-gmail-refresh-token:addVersion';
    $r = file_get_contents($smUrl, false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Authorization: Bearer ' . ($meta['access_token'] ?? '') . "\r\nContent-Type: application/json",
        'content' => json_encode(['payload' => ['data' => base64_encode($rt)]]),
        'ignore_errors' => true]]));
    $ok = strpos((string)$r, '"name"') !== false;
    echo $ok ? '<h1>Gmail send authorized</h1><p>Refresh token stored in Secret Manager. This tab can be closed.</p>'
             : '<h1>Secret write failed</h1><p>' . htmlspecialchars(substr((string)$r, 0, 200)) . '</p>';
    exit;
}
// ---- end gmail setup branch ----

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
