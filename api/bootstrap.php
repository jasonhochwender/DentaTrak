<?php
/**
 * Bootstrap file for the application
 * Sets up environment variables before any other code is loaded
 * Cloud Run safe: proper error logging, no filesystem writes outside /tmp
 */

// Determine if running on Cloud Run (K_SERVICE is always set in Cloud Run)
define('IS_CLOUD_RUN', (bool) getenv('K_SERVICE'));

// Configure error logging FIRST - before any code that might fail
// Cloud Run filesystem is read-only except /tmp, so we must log to stderr
if (IS_CLOUD_RUN) {
    ini_set('error_log', 'php://stderr');
    ini_set('log_errors', '1');
    ini_set('display_errors', '0');
} else {
    // Local/UAT: use logs directory if writable, otherwise stderr
    $localLogPath = dirname(__DIR__) . '/logs/error.log';
    $logDir = dirname($localLogPath);
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $localLogPath);
    } else {
        ini_set('error_log', 'php://stderr');
    }
    ini_set('log_errors', '1');
    ini_set('display_errors', '1');
}

// Set error reporting level
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Load Composer autoloader
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Load environment variables from .env file (local/UAT only)
// Cloud Run should use environment variables set via Cloud Run config or Secret Manager
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath) && class_exists('Dotenv\Dotenv')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
    } catch (Exception $e) {
        error_log('WARNING: Failed to load .env file: ' . $e->getMessage());
        // Continue execution - env vars may be set elsewhere
    }
}

// Fallback: Set encryption key environment variable if still not set
// In production (Cloud Run), this should come from Secret Manager
if (!getenv('ENCRYPTION_KEY') && !isset($_ENV['ENCRYPTION_KEY'])) {
    if (IS_CLOUD_RUN) {
        error_log('FATAL: ENCRYPTION_KEY environment variable not set in Cloud Run');
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Server configuration error']);
        exit(1);
    } else {
        // Local development fallback only
        putenv('ENCRYPTION_KEY=176cb7191fe5dc2ab8651dd35a3df8322f3668767c6595998ba9642dcd2824b3');
    }
}
