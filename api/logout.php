<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/unified-identity.php';

// ============================================
// LOGOUT HANDLING
// Security: Ends the authenticated session. The remembered-email cookie
// (login-form prefill only) intentionally survives logout.
// ============================================

// Get user ID before destroying session (needed for token revocation)
$userId = $_SESSION['db_user_id'] ?? null;

// Clear any legacy remember-me auth cookie (no longer accepted anywhere)
clearRememberMeCookie();

// Clear the session data
unset($_SESSION['resolved_locale']);
session_unset();
session_destroy();

// Redirect to the login page
header('Location: ../login.php');
exit;
