<?php
/**
 * Emergency session cleanup tool
 * Use this page to reset your session if you get stuck in a redirect loop
 */

// Start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the current session info for display
$oldSession = $_SESSION;
$sessionId = session_id();

// Clear practice-related flags but keep authentication
$practiceFlags = [
    'needs_practice_setup',
    'needs_practice_selection',
    'has_multiple_practices',
    'current_practice_id',
    'practice_setup_visits',
    'from_practice_setup'
];

foreach ($practiceFlags as $flag) {
    unset($_SESSION[$flag]);
}

// Force practice setup on next visit
$_SESSION['needs_practice_setup'] = true;

// Output success message
?><!DOCTYPE html>
<html>
<head>
    <title>Session Reset</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #2c3e50; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow: auto; }
        .success { color: #28a745; font-weight: bold; }
        .button { display: inline-block; background: #007bff; color: white; padding: 10px 15px; 
                 text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .button:hover { background: #0069d9; }
    </style>
</head>
<body>
    <h1>Session Reset</h1>
    
    <p class="success">Your session has been successfully reset!</p>
    
    <p>The following practice-related session flags have been cleared:</p>
    <ul>
        <?php foreach ($practiceFlags as $flag): ?>
        <li><?php echo htmlspecialchars($flag); ?></li>
        <?php endforeach; ?>
    </ul>
    
    <p>Your session ID is: <?php echo htmlspecialchars($sessionId); ?></p>
    
    <p>You will be redirected to the practice setup page on your next login.</p>
    
    <div>
        <a href="index.php" class="button">Go to Login</a>
        <a href="practice-setup.php" class="button">Go to Practice Setup</a>
    </div>
    
    <h2>Previous Session Data</h2>
    <pre><?php print_r($oldSession); ?></pre>
</body>
</html>
