<?php
/**
 * CSRF Protection
 * Generates and validates CSRF tokens for state-changing requests
 */

/**
 * Generate a CSRF token and store in session
 * @return string The generated token
 */
function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Generate new token if not exists or expired (1 hour)
    if (!isset($_SESSION['csrf_token']) || 
        !isset($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time']) > 3600) {
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token from request
 * @param string|null $token Token from request (if null, checks headers and POST)
 * @return bool True if valid
 */
function validateCsrfToken($token = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Get token from parameter, header, or POST data
    if ($token === null) {
        // Check X-CSRF-Token header first
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        // Then check POST data
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? null;
        }
        
        // Then check JSON body
        if ($token === null) {
            $input = file_get_contents('php://input');
            if ($input) {
                $data = json_decode($input, true);
                $token = $data['csrf_token'] ?? null;
            }
        }
    }
    
    // Validate token exists and matches
    if (!$token || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    // Use timing-safe comparison
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token or exit with error
 * Call this at the start of state-changing API endpoints
 */
function requireCsrfToken() {
    if (!validateCsrfToken()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or missing CSRF token',
            'message' => 'Your session may have expired. Please refresh the page and try again.'
        ]);
        exit;
    }
}

/**
 * Get CSRF token for embedding in forms/JavaScript
 * @return string The current CSRF token
 */
function getCsrfToken() {
    return generateCsrfToken();
}
