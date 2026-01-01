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

use SendGrid as SendGrid;
use SendGrid\Mail\Mail as SendGridMail;

/**
 * SendGrid email sender
 * Professional email delivery service
 */
function sendAppEmail(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null, ?string $replyToEmail = null): array {
    global $appConfig;
    
    // Get SendGrid API key from environment
    $sendGridApiKey = getenv('SENDGRID_API_KEY');
    if (!$sendGridApiKey || $sendGridApiKey === 'YOUR_SENDGRID_API_KEY_HERE') {
        error_log('[email] SendGrid API key not configured');
        return [
            'success' => false,
            'provider' => 'sendgrid',
            'error' => 'SendGrid API key not configured'
        ];
    }
    
    // Get from email and name
    $fromEmail = getenv('EMAIL_FROM') ?: ($appConfig['email_from'] ?? 'noreply@dentatrak.com');
    $fromName = getenv('EMAIL_FROM_NAME') ?: ($appConfig['appName'] ?? 'DentaTrak');
    
    try {
        // Create SendGrid email
        $email = new SendGridMail();
        $email->setFrom($fromEmail, $fromName);
        $email->setSubject($subject);
        $email->addTo($toEmail);
        $email->addContent("text/html", $htmlBody);
        
        // Add plain text version if provided or auto-generate
        if ($textBody) {
            $email->addContent("text/plain", $textBody);
        } else {
            // Auto-generate plain text from HTML
            $plainText = strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $htmlBody));
            $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
            $plainText = preg_replace('/\n\s*\n/', "\n\n", $plainText);
            $plainText = trim($plainText);
            $email->addContent("text/plain", $plainText);
        }
        
        // Set reply-to if provided
        if ($replyToEmail) {
            $email->setReplyTo($replyToEmail);
        }
        
        // Send email via SendGrid
        $sendgrid = new SendGrid($sendGridApiKey);
        $response = $sendgrid->send($email);
        
        // Check response
        if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
            // Success - no error logging needed for successful emails
            return [
                'success' => true,
                'provider' => 'sendgrid',
                'status_code' => $response->statusCode()
            ];
        } else {
            error_log('[email] SendGrid API error for ' . $toEmail . ': ' . $response->statusCode() . ' ' . $response->body());
            return [
                'success' => false,
                'provider' => 'sendgrid',
                'error' => 'SendGrid API error: ' . $response->statusCode(),
                'status_code' => $response->statusCode()
            ];
        }
        
    } catch (Exception $e) {
        error_log('[email] SendGrid exception for ' . $toEmail . ': ' . $e->getMessage());
        return [
            'success' => false,
            'provider' => 'sendgrid',
            'error' => 'SendGrid exception: ' . $e->getMessage()
        ];
    }
}
