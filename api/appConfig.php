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
    'feedback_email'    => 'feedback@dentatrak.com',
    'support_email'     => getEnvVar('SUPPORT_EMAIL', 'support@dentatrak.com'),
    'test_record_emails' => filter_var(getEnvVar('DENTATRAK_TEST_RECORD_EMAILS', getEnvVar('DENTATRAK_TEST_MODE', 'false')), FILTER_VALIDATE_BOOLEAN) === true,

    // Resend email configuration
    'resend_api_key' => getEnvVar('RESEND_API_KEY'),
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
        'api_key'    => getEnvVar('GEMINI_API_KEY'),
        'model'      => 'gemini-3.6-flash',
        'max_tokens' => 4096,
    ],

    'ai_prompt' => 'Analyze the following dental lab workflow data and provide exactly 3 actionable recommendations to improve efficiency, quality, or scheduling. Return ONLY a JSON array with this exact format (no markdown, no extra text):
[
  {"title": "Brief title", "description": "Detailed recommendation", "priority": "high|medium|low", "category": "efficiency|quality|scheduling|workload|communication"},
  {"title": "Brief title", "description": "Detailed recommendation", "priority": "high|medium|low", "category": "efficiency|quality|scheduling|workload|communication"},
  {"title": "Brief title", "description": "Detailed recommendation", "priority": "high|medium|low", "category": "efficiency|quality|scheduling|workload|communication"}
]

Here is the workflow data to analyze:
',

    // Google Cloud Storage configuration (for case file uploads)
    'gcs' => [
        'bucket_name' => getEnvVar('GCS_BUCKET_NAME', ''),  // REQUIRED — no silent default; validated at use-time in getGcsBucket()
        'key_file' => getEnvVar('GCS_KEY_FILE'), // Local dev only; Cloud Run uses ADC
        'signed_url_expiry' => 15 * 60, // 15 minutes for upload URLs
        'download_url_expiry' => 5 * 60, // 5 minutes for download URLs

        // --- Per-case aggregate limits ---
        'max_total_size' => 1024 * 1024 * 1024, // 1 GB total per case submission
        'max_file_count' => 15,                  // Max files per case submission

        // --- Type-specific per-file limits (bytes) ---
        // Looked up by lowercase extension; fallback is 'default'.
        'max_file_size_by_type' => [
            'stl'     => 250 * 1024 * 1024,  // 250 MB — dental 3D scans
            'obj'     => 250 * 1024 * 1024,
            'ply'     => 250 * 1024 * 1024,
            'dcm'     => 250 * 1024 * 1024,
            'jpg'     => 25 * 1024 * 1024,   // 25 MB — photos
            'jpeg'    => 25 * 1024 * 1024,
            'png'     => 25 * 1024 * 1024,
            'gif'     => 25 * 1024 * 1024,
            'webp'    => 25 * 1024 * 1024,
            'tiff'    => 25 * 1024 * 1024,
            'tif'     => 25 * 1024 * 1024,
            'bmp'     => 25 * 1024 * 1024,
            'svg'     => 10 * 1024 * 1024,
            'pdf'     => 50 * 1024 * 1024,   // 50 MB — documents
            'zip'     => 250 * 1024 * 1024,
            'default' => 100 * 1024 * 1024,  // Fallback
        ],

        // Legacy flat limit kept for any code that still references it
        'max_file_size' => 250 * 1024 * 1024,

        'allowed_mime_types' => [
            // 3D scan files (STL, OBJ, PLY)
            'model/stl',
            'application/sla',
            'application/vnd.ms-pki.stl',
            'application/x-navistyle',
            'model/obj',
            'application/x-tgif',
            'application/octet-stream', // Generic binary (many scanners use this for STL)
            // Images
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/tiff',
            'image/bmp',
            'image/svg+xml',
            // Documents
            'application/pdf',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'allowed_extensions' => [
            'stl', 'obj', 'ply', 'dcm',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg',
            'pdf', 'zip',
        ],
    ],

    // ── Stripe configuration ────────────────────────────────────────────────
    // Built by a closure so validation failures abort config loading immediately
    // rather than surfacing as cryptic Stripe API errors at request time.
    //
    // STRIPE_ENVIRONMENT must be 'test' or 'live'.
    //   test → keys must start with sk_test_ / pk_test_
    //   live → keys must start with sk_live_ / pk_live_
    //
    // There is deliberately NO cross-environment fallback: a missing test key
    // will never silently fall back to the live key, and vice versa.
    //
    // Only publishable_key is ever exposed to browser JS.  All other values
    // remain server-side.
    'stripe' => (static function (): array {
        // When billing is disabled we should never touch Stripe.  Keep the closure
        // self-contained but skip the noisy error log in that case; the safe empty
        // config is returned either way.
        $billingEnabledRaw = getenv('BILLING_ENABLED');
        if ($billingEnabledRaw === false) {
            $billingEnabledRaw = $_ENV['BILLING_ENABLED'] ?? '';
        }
        $billingEnabled = filter_var($billingEnabledRaw, FILTER_VALIDATE_BOOLEAN);

        $env = getEnvVar('STRIPE_ENVIRONMENT', '');
        if (!in_array($env, ['test', 'live'], true)) {
            if ($billingEnabled) {
                error_log('[appConfig] STRIPE_ENVIRONMENT must be "test" or "live"; got: ' .
                          (empty($env) ? '(empty)' : substr($env, 0, 20)));
            }
            // Return a config that will cause the first Stripe call to fail loudly
            // rather than silently falling back to an unexpected environment.
            return [
                'environment'              => $env,
                'publishable_key'          => null,
                'secret_key'              => null,
                'webhook_secret'          => null,
                'portal_configuration_id' => null,
                'prices'                  => [],
                'display_prices'          => [
                    'operate' => ['month' => 24900, 'year' => 249000],
                    'control' => ['month' => 49900, 'year' => 499000],
                    'scale'   => ['month' => 99900, 'year' => 999000],
                ],
                'config_error' => 'STRIPE_ENVIRONMENT must be "test" or "live"',
            ];
        }

        $secretKey      = getEnvVar('STRIPE_SECRET_KEY',      '');
        $publishableKey = getEnvVar('STRIPE_PUBLISHABLE_KEY', '');

        $expectedSecretPrefix      = 'sk_' . $env . '_';
        $expectedPublishablePrefix = 'pk_' . $env . '_';

        $configError = null;

        if (!empty($secretKey) && !str_starts_with($secretKey, $expectedSecretPrefix)) {
            $configError = 'STRIPE_SECRET_KEY prefix does not match STRIPE_ENVIRONMENT=' . $env;
            if ($billingEnabled) {
                error_log('[appConfig] ' . $configError);
            }
        }

        if (!empty($publishableKey) && !str_starts_with($publishableKey, $expectedPublishablePrefix)) {
            $configError = $configError ?? ('STRIPE_PUBLISHABLE_KEY prefix does not match ' .
                           'STRIPE_ENVIRONMENT=' . $env);
            if ($billingEnabled) {
                error_log('[appConfig] ' . ($configError));
            }
        }

        return [
            'environment'              => $env,
            'publishable_key'          => $publishableKey ?: null,
            'secret_key'               => $secretKey       ?: null,
            'webhook_secret'           => getEnvVar('STRIPE_WEBHOOK_SECRET')      ?: null,
            'webhook_secret_test'      => getEnvVar('STRIPE_WEBHOOK_SECRET_TEST') ?: null,
            'portal_configuration_id'  => getEnvVar('STRIPE_PORTAL_CONFIGURATION_ID') ?: null,

            // Server-side Price ID map — never sent to or accepted from the browser.
            // Read exclusively from the environment, so the running environment's
            // own Price IDs are the only ones that can ever be selected. A plan
            // with no configured Price ID for this environment resolves to null
            // and callers fail closed (see api/stripe-price-map.php).
            'prices' => [
                'operate' => [
                    'month' => getEnvVar('STRIPE_OPERATE_MONTHLY_PRICE_ID') ?: null,
                    'year'  => getEnvVar('STRIPE_OPERATE_ANNUAL_PRICE_ID')  ?: null,
                ],
                'control' => [
                    'month' => getEnvVar('STRIPE_CONTROL_MONTHLY_PRICE_ID') ?: null,
                    'year'  => getEnvVar('STRIPE_CONTROL_ANNUAL_PRICE_ID')  ?: null,
                ],
                // Scale: test Price IDs are set in .env for local/test. The LIVE
                // Price IDs do not exist yet — set STRIPE_SCALE_MONTHLY_PRICE_ID
                // and STRIPE_SCALE_ANNUAL_PRICE_ID as production environment
                // variables (Cloud Run) once they are created in the live Stripe
                // account. The per-extra-practice add-on Price IDs are also
                // configured per environment in the same way.
                // Until then these stay null in production and a Scale checkout
                // there fails with a clear, controlled error instead of falling
                // back to test IDs.
                'scale' => [
                    'month'            => getEnvVar('STRIPE_SCALE_MONTHLY_PRICE_ID') ?: null,
                    'year'             => getEnvVar('STRIPE_SCALE_ANNUAL_PRICE_ID')  ?: null,
                    'additional_month' => getEnvVar('STRIPE_SCALE_ADDITIONAL_MONTHLY_PRICE_ID') ?: null,
                    'additional_year'  => getEnvVar('STRIPE_SCALE_ADDITIONAL_ANNUAL_PRICE_ID')  ?: null,
                ],
            ],

            // Display prices in cents — for UI only; authoritative amount is on the Stripe Price object.
            'display_prices' => [
                'operate' => ['month' => 24900, 'year' => 249000],  // $249/mo, $2,490/yr
                'control' => ['month' => 49900, 'year' => 499000],  // $499/mo, $4,990/yr
                'scale'   => ['month' => 99900, 'year' => 999000],  // $999/mo, $9,990/yr
            ],

            // Set when environment or key prefix validation fails — checked by endpoints.
            'config_error' => $configError,
        ];
    })(),

    // Base URL for Stripe success/cancel redirects and portal return URLs.
    // Set APP_BASE_URL in .env (e.g. http://localhost/DentaTrak for local dev).
    'app_base_url'  => rtrim(getEnvVar('APP_BASE_URL', 'https://dentatrak.com'), '/'),
    'user_guide_url' => rtrim(getEnvVar('APP_BASE_URL', 'https://dentatrak.com'), '/') . '/resources/user-guide',

    // Internationalization
    'i18n' => [
        'locale' => 'en-US',
        'fallback_locale' => 'en-US',
        'supported_locales' => [
            'en-US' => [
                'name' => 'English (United States)',
                'nativeName' => 'English (United States)',
                'enabled' => true,
            ],
        ],
    ],

    // Public article URLs — clean paths on production, direct .php on local/UAT
    // (Local Apache serves the app from a subfolder so clean URL rewrites don't apply)
    'public_urls' => [
        'article_dental_case_tracking_software' => 'dental-case-tracking-software',
        'article_how_to_track'                  => 'how-to-track-dental-cases',
        'article_visual_workflow'               => 'visual-dental-case-workflow',
        'article_comparison'                    => 'dental-case-tracking-vs-spreadsheets',
        'article_vs_pms'                        => 'dental-case-tracking-software-vs-pms',
        'article_lab_tracking'                  => 'dental-lab-case-tracking',
        'article_crown_bridge'                  => 'crown-and-bridge-case-tracking',
        'article_implant'                       => 'implant-case-tracking',
        'article_dental_remake_cost'            => 'dental-remake-cost',
        'page_about'                            => 'about',
        'page_resources'                        => 'resources',
        'page_hipaa_security'                   => 'hipaa-security',
    ],

    'billing' => [
        'trial_days' => 90, // Evaluate plan trial period in days
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
                'max_cases' => 0, // Unlimited
                'max_users' => 5,
                'can_add_users' => true,
                'has_analytics' => true
            ],
            'control' => [
                'name' => 'Control',
                'max_cases' => 0, // Unlimited
                'can_add_users' => true,
                'has_analytics' => true
            ],
            // NOTE: this legacy per-user metering tier (users.billing_tier -
            // case/user limits) is a separate concern from the owner-level
            // subscription plan in the `subscriptions` table. Scale is defined
            // here purely as a safety net: consumers of this map fall back to
            // the 'evaluate' TRIAL tier for unknown keys, so without an entry a
            // paying Scale customer whose billing_tier ever read 'scale' would
            // be silently metered as a trial user. Mirrors Control.
            'scale' => [
                'name' => 'Scale',
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
    // Public DentaTrak origin must never expose Cloud Run/container ports
    // (e.g. :8080). Use the canonical public origin.
    'baseUrl'       => 'https://dentatrak.com',
    'app_base_url'  => 'https://dentatrak.com'
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
    'baseUrl'     => 'http://localhost/DentaTrak',
    'public_urls' => [
        'article_dental_case_tracking_software' => 'dental-case-tracking-software.php',
        'article_how_to_track'                  => 'how-to-track-dental-cases.php',
        'article_visual_workflow'               => 'visual-dental-case-workflow.php',
        'article_comparison'                    => 'dental-case-tracking-vs-spreadsheets.php',
        'article_vs_pms'                        => 'dental-case-tracking-software-vs-pms.php',
        'article_lab_tracking'                  => 'dental-lab-case-tracking.php',
        'article_crown_bridge'                  => 'crown-and-bridge-case-tracking.php',
        'article_implant'                       => 'implant-case-tracking.php',
        'article_dental_remake_cost'            => 'dental-remake-cost.php',
        'page_about'                            => 'about.php',
        'page_resources'                        => 'resources.php',
        'page_hipaa_security'                   => 'hipaa-security.php',
    ],
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
    'baseUrl'     => 'http://localhost/DentaTrak',
    'public_urls' => [
        'article_dental_case_tracking_software' => 'dental-case-tracking-software.php',
        'article_how_to_track'                  => 'how-to-track-dental-cases.php',
        'article_visual_workflow'               => 'visual-dental-case-workflow.php',
        'article_comparison'                    => 'dental-case-tracking-vs-spreadsheets.php',
        'article_vs_pms'                        => 'dental-case-tracking-software-vs-pms.php',
        'article_lab_tracking'                  => 'dental-lab-case-tracking.php',
        'article_crown_bridge'                  => 'crown-and-bridge-case-tracking.php',
        'article_implant'                       => 'implant-case-tracking.php',
        'article_dental_remake_cost'            => 'dental-remake-cost.php',
        'page_about'                            => 'about.php',
        'page_resources'                        => 'resources.php',
        'page_hipaa_security'                   => 'hipaa-security.php',
    ],
]);

// Determine which environment to use
// Check for environment override file (allows switching between UAT and local dev)
$envOverrideFile = __DIR__ . '/../.env_mode';
$envMode = null;
if (file_exists($envOverrideFile)) {
    $envMode = trim(file_get_contents($envOverrideFile));
}

// Database connection
try {
    if (getenv('K_SERVICE') || getenv('CLOUD_RUN_JOB')) {
        // ===== Cloud Run (Production) - Service (K_SERVICE) or Job (CLOUD_RUN_JOB) =====
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

    // Suppress session warnings (can occur during concurrent test runs)
    @session_start();
}

// Make the i18n helper functions and active locale available everywhere
require_once __DIR__ . '/i18n.php';

// Ensure locale columns exist before resolving the active locale
require_once __DIR__ . '/locale-migrations.php';
ensureLocaleColumns();

// Resolve and persist the active locale from preferences / session
$resolvedLocale = resolveLocale(
    null,
    $_SESSION['db_user_id'] ?? null,
    $_SESSION['current_practice_id'] ?? null
);
setResolvedLocale($resolvedLocale);
$appConfig['i18n']['locale'] = $resolvedLocale;
$activeLocale = $resolvedLocale;
