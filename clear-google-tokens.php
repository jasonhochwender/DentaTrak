<?php
/**
 * Clear Google tokens to force re-authentication
 * This script will clear the session tokens and redirect to re-authenticate
 */
session_start();

// Clear all Google-related session tokens
// Login tokens
unset($_SESSION['google_login_token']);
// Drive tokens (separate from login)
unset($_SESSION['google_drive_token']);
unset($_SESSION['google_drive_refresh_token']);
unset($_SESSION['google_drive_connected']);
// User session data
unset($_SESSION['user']);
unset($_SESSION['db_user_id']);
unset($_SESSION['user_role']);
unset($_SESSION['oauth_state']);
unset($_SESSION['oauth_mode']);

// Clear any session cookies
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the entire session to be thorough
session_destroy();

// Start a fresh session
session_start();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Authentication Reset</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .container { background: white; border: 1px solid #ddd; padding: 30px; border-radius: 8px; max-width: 600px; margin: 0 auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #28a745; font-size: 18px; margin: 20px 0; }
        .info { color: #17a2b8; margin: 15px 0; }
        .warning { color: #ffc107; background: #fff9e6; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .btn { display: inline-block; padding: 12px 24px; background: #4285f4; color: white; text-decoration: none; border-radius: 5px; margin: 10px; }
        .btn:hover { background: #3367d6; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔧 Authentication Reset</h2>
        <p class="success">✓ Authentication tokens have been cleared</p>
        
        <div class="warning">
            <strong>⚠️ Important:</strong> You must sign in again to continue.
        </div>
        
        <p class="info">Please sign in again to restore access to the application.</p>
        
        <div style="margin: 30px 0;">
            <a href="api/oauth-start.php" class="btn">🔐 Sign In with Google</a>
            <a href="index.php" class="btn" style="background: #6c757d;">🏠 Back to Login</a>
        </div>
    </div>
    
    <script>
        // Auto-redirect after 5 seconds if user doesn't click
        setTimeout(function() {
            window.location.href = 'api/oauth-start.php';
        }, 5000);
    </script>
</body>
</html>
