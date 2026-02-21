<?php

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env file
function loadEnv($envFile) {
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if (strpos($value, '"') === 0) {
                $value = substr($value, 1, -1);
            }
            
            // Set in both $_ENV and putenv() for compatibility
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Load environment variables
loadEnv(__DIR__ . '/../.env');

/**
 * Resend email sender
 * Uses Resend REST API (https://resend.com/docs/api-reference/emails/send-email)
 * Outbound-only; no inbound email handling.
 */
function sendAppEmail(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null, ?string $replyToEmail = null): array {
    global $appConfig;
    
    // Fail closed: require valid API key before attempting to send
    $resendApiKey = getenv('RESEND_API_KEY');
    if (!$resendApiKey || $resendApiKey === 'YOUR_RESEND_API_KEY_HERE' || strpos($resendApiKey, 're_') !== 0) {
        error_log('[email] Resend API key not configured or invalid');
        return [
            'success' => false,
            'provider' => 'resend',
            'error' => 'Email service not configured'
        ];
    }
    
    // Get from email and name from environment, with fallbacks
    $fromEmail = getenv('EMAIL_FROM') ?: ($appConfig['email_from'] ?? 'noreply@dentatrak.com');
    $fromName = getenv('EMAIL_FROM_NAME') ?: ($appConfig['appName'] ?? 'DentaTrak');
    
    // Auto-generate plain text from HTML if not provided
    if (!$textBody) {
        $plainText = strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $htmlBody));
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
        $plainText = preg_replace('/\n\s*\n/', "\n\n", $plainText);
        $textBody = trim($plainText);
    }
    
    // Build Resend API payload
    $payload = [
        'from' => "$fromName <$fromEmail>",
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => $textBody
    ];
    
    if ($replyToEmail) {
        $payload['reply_to'] = $replyToEmail;
    }
    
    // Send via Resend REST API using cURL
    return sendViaResendApi($resendApiKey, $payload, $toEmail);
}

/**
 * Internal helper: sends email via Resend REST API
 * Resend API endpoint: POST https://api.resend.com/emails
 * Returns standardized response array with success status.
 */
function sendViaResendApi(string $apiKey, array $payload, string $toEmail): array {
    $ch = curl_init('https://api.resend.com/emails');
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30
    ]);
    
    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Handle cURL errors (network issues, timeouts)
    if ($curlError) {
        error_log('[email] Resend cURL error for ' . $toEmail . ': ' . $curlError);
        return [
            'success' => false,
            'provider' => 'resend',
            'error' => 'Email delivery failed'
        ];
    }
    
    // Resend returns 200 on success
    if ($httpCode === 200) {
        return [
            'success' => true,
            'provider' => 'resend',
            'status_code' => $httpCode
        ];
    }
    
    // Log API errors server-side but return generic message to caller
    error_log('[email] Resend API error for ' . $toEmail . ': HTTP ' . $httpCode . ' ' . $responseBody);
    return [
        'success' => false,
        'provider' => 'resend',
        'error' => 'Email delivery failed',
        'status_code' => $httpCode
    ];
}
