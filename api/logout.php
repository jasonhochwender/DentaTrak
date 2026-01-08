<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/unified-identity.php';

// ============================================
// LOGOUT HANDLING
// Security: Clears session and revokes remember me tokens
// ============================================

// Get user ID before destroying session (needed for token revocation)
$userId = $_SESSION['db_user_id'] ?? null;

// Clear the remember me cookie and revoke token
clearRememberMeCookie();

// Clear the session data
session_unset();
session_destroy();

// Redirect to the login page
header('Location: ../login.php');
exit;
