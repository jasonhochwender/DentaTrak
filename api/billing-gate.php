<?php
/**
 * Billing Feature Gate
 *
 * Include this file at the top of every billing API endpoint.
 * When BILLING_ENABLED is false (the production default) the endpoint
 * returns HTTP 503 with a safe JSON body and exits immediately.
 *
 * No Stripe SDK code, no environment variable reads, and no database
 * access happen before this guard fires.
 *
 * Usage:
 *   require_once __DIR__ . '/billing-gate.php';   // before ANY other require
 *   requireBillingEnabled();
 */

if (!function_exists('requireBillingEnabled')) {
    function requireBillingEnabled(): void {
        // Read the flag directly from the environment so this guard works even
        // before appConfig.php / feature-flags.php are loaded.
        $raw = getenv('BILLING_ENABLED');
        if ($raw === false) {
            $raw = $_ENV['BILLING_ENABLED'] ?? '';
        }
        $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN);

        if (!$enabled) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            http_response_code(503);
            echo json_encode([
                'error'      => 'Billing is not available.',
                'error_code' => 'BILLING_DISABLED',
            ]);
            exit;
        }
    }
}
