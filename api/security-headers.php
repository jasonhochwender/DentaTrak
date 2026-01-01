<?php
/**
 * Security Headers
 * Sets HTTP security headers to protect against common attacks
 */

function setSecurityHeaders() {
    // Prevent clickjacking - page cannot be embedded in iframes
    header('X-Frame-Options: DENY');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Enable XSS filter in browsers
    header('X-XSS-Protection: 1; mode=block');
    
    // Referrer policy - only send origin for cross-origin requests
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Permissions policy - restrict browser features
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    
    // HSTS - enforce HTTPS
    // DISABLED: Can cause ERR_TOO_MANY_ACCEPT_CH_RESTARTS on Google App Engine
    // when combined with load balancer HTTPS termination and .htaccess redirects
    // The .htaccess already handles HTTPS redirect, HSTS is redundant and problematic
    // if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    //     header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    // }
    
    // Cross-Origin-Opener-Policy - isolate browsing context
    // DISABLED: These headers can cause ERR_TOO_MANY_ACCEPT_CH_RESTARTS in Chrome
    // when combined with certain CDN/hosting configurations
    // header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    
    // Cross-Origin-Embedder-Policy - for additional isolation (use credentialless for compatibility)
    // DISABLED: Can cause redirect loops with Google OAuth
    // header('Cross-Origin-Embedder-Policy: credentialless');
    
    // Content Security Policy - restrict resource loading
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://*.google.com https://*.googleapis.com https://*.gstatic.com https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: https: blob:",
        "connect-src 'self' https://*.google.com https://*.googleapis.com https://api.openai.com https://cdn.jsdelivr.net",
        "frame-src https://*.google.com",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self' https://accounts.google.com",
        // REMOVED: upgrade-insecure-requests can cause redirect loops on App Engine
        // "upgrade-insecure-requests"
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

/**
 * Set security headers for API responses (JSON)
 */
function setApiSecurityHeaders() {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
