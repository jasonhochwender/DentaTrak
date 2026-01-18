<?php
// Application Configuration

// Load bootstrap to set environment variables and configure error logging
// Bootstrap handles: IS_CLOUD_RUN detection, error_log path, .env loading, autoloader
require_once __DIR__ . '/bootstrap.php';

use PHPMailer\PHPMailer\PHPMailer;

// Helper to get env var with optional fallback
function getEnvVar(string $key, ?string $fallback = null): ?string {
    $value = getenv($key) ?: ($_ENV[$key] ?? null);
    return ($value !== false && $value !== null && $value !== '') ? $value : $fallback;
}

// Common configuration values
$commonConfig = [
    'appName'         => 'DentaTrak',
    'port'            => 465,
    'smtpAuth'        => true,
    'disable_caching' => true,
    'feedback_email'  => 'feedback@dentatrak.com',

    // SendGrid email configuration
    'sendgrid_api_key' => getEnvVar('SENDGRID_API_KEY'),
    'email_from'       => 'noreply@dentatrak.com',
    'email_from_name'  => 'DentaTrak',

    'google_client_id'     => getEnvVar('GOOGLE_CLIENT_ID'),
    'google_client_secret' => getEnvVar('GOOGLE_CLIENT_SECRET'),
    'google_api_key'       => getEnvVar('GOOGLE_API_KEY'),

    // AI Provider: 'openai' or 'gemini' - set in .env file
    'ai_provider' => getEnvVar('AI_PROVIDER', 'gemini'),

    'openai' => [
        'api_key'     => getEnvVar('OPENAI_API_KEY'),
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => 1000,
        'temperature' => 0.7,
    ],

    'gemini' => [
        'api_key'     => getEnvVar('GEMINI_API_KEY'),
        'model'       => 'gemini-2.0-flash',
        'max_tokens'  => 1000,
        'temperature' => 0.7,
    ],

    'ai_prompt' => 'Analyze the following dental lab workflow data and provide exactly 3 actionable recommendations to improve efficiency, quality, or scheduling. Return ONLY a JSON array with this exact format (no markdown, no extra text):
[
  {"title": "Brief title", "description": "Detailed recommendation", "priority": "high|medium|low", "category": "efficiency|quality|scheduling|workload|communication"},
  {"title": "Brief title", "description": "Detailed recommendation", "priority": "high|medium|low", "category": "efficiency|quality|scheduling|workload|communication"},
  {"title": "Brief title", "description": "Detailed recommendation", "priority": "high|medium|low", "category": "efficiency|quality|scheduling|workload|communication"}
]

Here is the workflow data to analyze:
',

    // Stripe configuration
    'stripe' => [
        'publishable_key' => getEnvVar('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => getEnvVar('STRIPE_SECRET_KEY'),
        'pricing_table_id' => getEnvVar('STRIPE_PRICING_TABLE_ID'),
        'pricing_table_publishable_key' => getEnvVar('STRIPE_PRICING_TABLE_PUBLISHABLE_KEY'),
    ],

    'billing' => [
        'trial_days' => 30, // Evaluate plan trial period in days
        'tiers' => [
            'evaluate' => [
                'name' => 'Evaluate',
                'max_cases' => 0, // Unlimited during trial
                'can_add_users' => true, // Full access during trial
                'has_analytics' => true, // Full access during trial
                'is_trial' => true // This tier is time-limited
            ],
            'operate' => [
                'name' => 'Operate',
                'max_cases' => 100,
                'max_users' => 5,
                'can_add_users' => true,
                'has_analytics' => true
            ],
            'control' => [
                'name' => 'Control',
                'max_cases' => 0, // Unlimited
                'can_add_users' => true,
                'has_analytics' => true
            ]
        ]
    ],

    // Dev Tools Configuration
    // Super users who can access dev tools when SHOW_DEV_TOOLS feature flag is enabled
    // Set via SUPER_USERS environment variable (comma-separated email addresses)
    'super_users' => array_filter(array_map('trim', explode(',', getEnvVar('SUPER_USERS', '')))),

    // Case Form Field Requirements
    // Set to true to make a field required, false to make it optional
    // Note: Clinical fields are only validated when their case type is selected
    'case_required_fields' => [
        // Patient Information
        'patientFirstName' => true,
        'patientLastName' => true,
        'patientDOB' => true,
        'patientGender' => true,
        
        // Case Information
        'dentistName' => true,
        'caseType' => true,
        'dueDate' => true,
        'status' => true,
        
        // Optional global fields - set to true to make required
        'toothShade' => false,
        'material' => false,
        'assignedTo' => false,
        'notes' => false,
        'attachments' => false,
        
        // Clinical Details - Crown
        'toothNumber' => true,              // Crown: Tooth #
        
        // Clinical Details - Bridge
        'abutmentTeeth' => true,            // Bridge: Abutment Teeth
        'ponticTeeth' => true,              // Bridge: Pontic Teeth
        
        // Clinical Details - Implant Crown
        'implantToothNumber' => true,       // Implant Crown: Tooth #
        'abutmentType' => false,            // Implant Crown: Abutment Type
        'implantSystem' => false,           // Implant Crown: Implant System
        'platformSize' => false,            // Implant Crown: Platform Size
        'scanBodyUsed' => false,            // Implant Crown: Scan Body Used
        
        // Clinical Details - Implant Surgical Guide
        'implantSites' => false,            // Implant Surgical Guide: Implant Sites
        
        // Clinical Details - Denture
        'dentureJaw' => false,              // Denture: Jaw (Upper/Lower/Both)
        'dentureType' => false,             // Denture: Type
        'gingivalShade' => false,           // Denture: Gingival Shade
        
        // Clinical Details - Partial
        'partialJaw' => false,              // Partial: Jaw
        'teethToReplace' => true,           // Partial: Teeth to Replace
        'partialMaterial' => false,         // Partial: Material
        'partialGingivalShade' => false,    // Partial: Gingival Shade
    ]
];

// Production configuration (Cloud Run with Cloud SQL)
// All credentials come from Cloud Run env vars / Secret Manager
$appConfigProduction = array_merge($commonConfig, [
    'environment'   => 'production',
    'db_user'       => getEnvVar('DB_USER'),
    'db_password'   => getEnvVar('DB_PASSWORD'),
    'db_name'       => getEnvVar('DB_NAME', 'dental_case_tracker'),
    'baseUrl'       => getEnvVar('BASE_URL', 'https://dentatrak.com')
]);

// UAT configuration (local machine with bridge to production DB)
// Uses same credentials as prod - should be set in .env file
$appConfigUAT = array_merge($commonConfig, [
    'environment' => 'uat',
    'db_host'     => '127.0.0.1',
    'db_port'     => 3307, // Bridge connection to prod DB
    'db_user'     => getEnvVar('DB_USER'),
    'db_password' => getEnvVar('DB_PASSWORD'),
    'db_name'     => getEnvVar('DB_NAME', 'dental_case_tracker'),
    'baseUrl'     => 'http://localhost/'
]);

// Local Development configuration (MAMP with local DB)
// Credentials should be set in .env file - no hardcoded fallbacks for security
$appConfigLocalDev = array_merge($commonConfig, [
    'environment' => 'development',
    'db_host'     => '127.0.0.1',
    'db_port'     => 3308, // MAMP MySQL port (check MAMP preferences if different)
    'db_user'     => getEnvVar('DB_USER_LOCAL'),
    'db_password' => getEnvVar('DB_PASSWORD_LOCAL'),
    'db_name'     => getEnvVar('DB_NAME', 'dental_case_tracker'),
    'baseUrl'     => 'http://localhost/'
]);

// RA7Y4CKWHAWBVMKLSF4XMM7Y

// Determine which environment to use
// Check for environment override file (allows switching between UAT and local dev)
$envOverrideFile = __DIR__ . '/../.env_mode';
$envMode = null;
if (file_exists($envOverrideFile)) {
    $envMode = trim(file_get_contents($envOverrideFile));
}

// Database connection
try {
    if (getenv('K_SERVICE')) {
        // ===== Cloud Run (Production) =====
        $connectionName = getenv('CLOUD_SQL_CONNECTION_NAME');
        if (!$connectionName) {
            throw new Exception('CLOUD_SQL_CONNECTION_NAME not set');
        }

        $socket = "/cloudsql/{$connectionName}";
        $dsn = "mysql:unix_socket={$socket};dbname={$appConfigProduction['db_name']};charset=utf8mb4";

        $pdo = new PDO(
            $dsn,
            $appConfigProduction['db_user'],
            $appConfigProduction['db_password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            ]
        );

        $appConfig = $appConfigProduction;
        $appConfig['current_environment'] = 'production';
        $appConfig['show_dev_tools'] = false;

    } else {
        // ===== Local Environment =====
        // Determine if this is UAT (bridge to prod DB) or Local Dev (MAMP local DB)
        
        // If .env_mode file explicitly specifies the environment, use that
        if ($envMode === 'development' || $envMode === 'local') {
            $selectedConfig = $appConfigLocalDev;
            $currentEnv = 'development';
            $showDevTools = true;
        } elseif ($envMode === 'uat') {
            $selectedConfig = $appConfigUAT;
            $currentEnv = 'uat';
            $showDevTools = false;
        } else {
            // No .env_mode file - try UAT first, fall back to local dev
            // This allows the app to work whether bridge is running or not
            $selectedConfig = $appConfigUAT;
            $currentEnv = 'uat';
            $showDevTools = false;
            
            // Test if UAT port (3307) is available
            $uatConnection = @fsockopen($appConfigUAT['db_host'], $appConfigUAT['db_port'], $errno, $errstr, 1);
            if (!$uatConnection) {
                // UAT bridge not available, try local dev (MAMP on port 3308)
                $localConnection = @fsockopen($appConfigLocalDev['db_host'], $appConfigLocalDev['db_port'], $errno, $errstr, 1);
                if ($localConnection) {
                    fclose($localConnection);
                    $selectedConfig = $appConfigLocalDev;
                    $currentEnv = 'development';
                    $showDevTools = true;
                } else {
                    // Neither UAT nor local dev available - provide helpful error
                    error_log("Neither UAT (port 3307) nor local MAMP (port {$appConfigLocalDev['db_port']}) is available");
                }
            } else {
                fclose($uatConnection);
            }
        }
        
        if (
            empty($selectedConfig['db_host']) ||
            empty($selectedConfig['db_port']) ||
            empty($selectedConfig['db_name'])
        ) {
            throw new Exception('Database configuration is incomplete');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $selectedConfig['db_host'],
            $selectedConfig['db_port'],
            $selectedConfig['db_name']
        );

        $pdo = new PDO(
            $dsn,
            $selectedConfig['db_user'],
            $selectedConfig['db_password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            ]
        );

        $appConfig = $selectedConfig;
        $appConfig['current_environment'] = $currentEnv;
        $appConfig['show_dev_tools'] = $showDevTools;
    }

} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed. Please try again later.', 'retry' => true]);
    exit(1);
} catch (Exception $e) {
    error_log('Configuration error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Application configuration error.']);
    exit(1);
}


// Start session (Cloud Run safe)
if (session_status() === PHP_SESSION_NONE) {
    if (IS_CLOUD_RUN) {
        // Cloud Run: use /tmp for session storage (only writable path)
        session_save_path('/tmp');
    } else {
        // Local development: use a sessions folder in the project
        $localSessionPath = __DIR__ . '/../sessions';
        if (!is_dir($localSessionPath)) {
            @mkdir($localSessionPath, 0700, true);
        }
        if (is_dir($localSessionPath) && is_writable($localSessionPath)) {
            session_save_path($localSessionPath);
        }
    }
    
    // Set secure session cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => IS_CLOUD_RUN, // HTTPS only in production
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start(); 
}
