<?php
/**
 * Email Resolution API
 * 
 * Step 1 of progressive authentication flow.
 * Checks if an email exists and what auth methods are available.
 * 
 * Returns:
 * - exists: boolean - whether email is registered
 * - auth_methods: array - available auth methods ('google', 'email', 'both')
 * - can_set_password: boolean - whether user can add password auth
 * - requires_google: boolean - whether Google is the only auth method
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/unified-identity.php';

header('Content-Type: application/json');

// Get JSON input
$jsonInput = file_get_contents('php://input');
$input = json_decode($jsonInput, true);

$email = trim($input['email'] ?? '');

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address'
    ]);
    exit;
}

// Look up user by email
$user = findUserByEmail($email);

if (!$user) {
    // Email does not exist - new user flow
    echo json_encode([
        'success' => true,
        'exists' => false,
        'auth_methods' => [],
        'can_set_password' => true,
        'requires_google' => false,
        'flow' => 'register' // Show registration fields
    ]);
    exit;
}

// Email exists - determine auth methods
$authMethod = $user['auth_method'] ?? 'email';

$hasPassword = ($authMethod === 'email' || $authMethod === 'both');
$hasGoogle = ($authMethod === 'google' || $authMethod === 'both');

// Check if account is active
if (!$user['is_active']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'This account has been deactivated. Please contact support.'
    ]);
    exit;
}

if ($hasPassword) {
    // User has password auth - show password field
    echo json_encode([
        'success' => true,
        'exists' => true,
        'auth_methods' => $authMethod === 'both' ? ['google', 'email'] : ['email'],
        'has_password' => true,
        'has_google' => $hasGoogle,
        'can_set_password' => false, // Already has password
        'requires_google' => false,
        'flow' => 'login', // Show password login
        'first_name' => $user['first_name'] ?? '' // For personalized greeting
    ]);
} else {
    // User has Google auth ONLY - cannot login with password
    echo json_encode([
        'success' => true,
        'exists' => true,
        'auth_methods' => ['google'],
        'has_password' => false,
        'has_google' => true,
        'can_set_password' => true, // Can add password to existing account
        'requires_google' => true, // Must use Google or set up password first
        'flow' => 'google_only', // Show Google login + option to set password
        'first_name' => $user['first_name'] ?? ''
    ]);
}
