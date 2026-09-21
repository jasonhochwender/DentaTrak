<?php
/**
 * Integrations Management API
 *
 * Practice-admin endpoints for managing PMS (practice-management system)
 * integration connections. Phase B scope: connection lifecycle only -
 * no PMS network calls, no synchronization, no case creation.
 *
 * Actions:
 *   GET  ?action=list                          -> connections for the active practice
 *   POST {action:'configure', provider, credentials{}, remove_credentials[]}
 *   POST {action:'update_settings', connection_id, sync_enabled}
 *   POST {action:'disconnect',   connection_id}
 *   POST {action:'reenable',     connection_id}
 *
 * SECURITY MODEL (every action):
 *   authenticated session -> valid practice context -> practice admin ->
 *   not a lab collaborator -> CSRF (POST) -> SHOW_PMS_INTEGRATIONS flag ->
 *   connection verified to belong to the active practice.
 *   The practice is ALWAYS derived from the server-side session context;
 *   a practice_id in the request body is never trusted.
 *
 * CREDENTIAL RULES:
 *   - Secrets accepted only via POST body keys whitelisted per provider.
 *   - Stored exclusively through IntegrationCredentials (encrypted).
 *   - Never returned, logged, placed in config_json, or echoed in errors.
 *   - A blank credential field means "leave unchanged" - never a delete.
 *     Removal requires the explicit remove_credentials list.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/billing-bypass.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/integrations/IntegrationManager.php';
require_once __DIR__ . '/integrations/IntegrationCredentials.php';
require_once __DIR__ . '/integrations/IntegrationEvents.php';
require_once __DIR__ . '/integrations/OpenDentalPortalClient.php';
require_once __DIR__ . '/integrations/register-adapters.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e) {
    error_log('[integrations] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('api.integrations.error_generic')]);
    exit;
});

function integrationsFail(int $status, string $message, ?string $errorCode = null): void {
    http_response_code($status);
    $out = ['success' => false, 'message' => $message];
    // error_code is a safe category enum (never secrets/URLs/raw responses)
    // - same contract as test_connection, and it lets the UI give targeted
    // next-step guidance and local devs identify the failure class.
    if ($errorCode !== null) {
        $out['error_code'] = $errorCode;
    }
    echo json_encode($out);
    exit;
}

// -------------------------------------------------------------------------
// Auth stack: session -> practice context -> admin -> not lab collaborator
// -------------------------------------------------------------------------
if (!isset($_SESSION['db_user_id'])) {
    integrationsFail(401, t('auth.errors.not_authenticated'));
}
$userId = (int)$_SESSION['db_user_id'];

$currentPracticeId = requireValidPracticeContext();
requirePracticeAdmin($currentPracticeId);
requireNotLabCollaborator($currentPracticeId, t('settings.external_collaborator_denied'));
requireCurrentTermsAcceptedForApi($userId);

// Feature gate: hidden UI is not the only control - the API refuses all
// integration operations while the foundation flag is off.
if (!isFeatureEnabled('SHOW_PMS_INTEGRATIONS')) {
    integrationsFail(403, t('api.integrations.disabled'));
}

// Plan entitlement: PMS integration management is a Control-tier-and-above
// capability. hasControlAccess() is the same authoritative check Lab
// Insights/Smart Recommendations use - it encodes BILLING_ENABLED, billing
// bypass accounts, active trials, and cumulative tiers. Every action below
// (list, configure, generate_key, test_connection, subscribe, unsubscribe,
// import_existing, disconnect, reenable, update_settings) is gated here, so
// an Operate practice cannot bypass the restriction by calling endpoints
// directly. The practice owner may still view status via the public page -
// nothing secret is returned either way.
if (!hasControlAccess($pdo, $currentPracticeId, (string)($_SESSION['user_email'] ?? ''))) {
    integrationsFail(403, t('api.integrations.plan_required'), 'plan_required');
}

// Providers the management API will accept. The adapter registry in
// IntegrationManager is what eventually runs a provider; this whitelist is
// the administrative surface - a provider can exist here before its adapter
// ships, but no other provider strings can ever create connections.
$INTEGRATION_PROVIDERS = [
    'open_dental' => [
        // Only the practice-specific Customer Key is a stored credential.
        // The Developer Key and Developer Portal Key are DentaTrak-central
        // server secrets (OpenDentalConfig/env) and are rejected if a client
        // ever submits them.
        'credential_keys' => ['customer_key'],
    ],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// GET: list connections for the active practice
// -------------------------------------------------------------------------
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    if ($action !== 'list') {
        integrationsFail(400, t('api.integrations.unknown_action'));
    }

    // Projections contain no credential material, no config_json, and are
    // scoped to THIS practice only.
    $connections = IntegrationManager::listConnectionsForPractice($pdo, $currentPracticeId);
    echo json_encode(['success' => true, 'connections' => $connections]);
    exit;
}

if ($method !== 'POST') {
    integrationsFail(405, t('api.integrations.method_not_allowed'));
}

requireCsrfToken();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    integrationsFail(400, t('api.settings.invalid_data'));
}

$action = $body['action'] ?? '';

/**
 * Audit helper: user_activity_log is the established mechanism for
 * authenticated user actions (admin_audit_log is for platform-level
 * admins). Descriptions carry only provider/practice/connection metadata -
 * never credential values, hashes, or config contents.
 */
function logIntegrationAdminAction(int $userId, string $type, int $practiceId, string $provider, int $connectionId): void {
    if (function_exists('logUserActivity')) {
        logUserActivity(
            $userId,
            $type,
            "provider={$provider} practice_id={$practiceId} connection_id={$connectionId}"
        );
    }
}

// -------------------------------------------------------------------------
// POST configure: create or update a provider connection + credentials
// -------------------------------------------------------------------------
if ($action === 'configure') {
    $provider = strtolower(trim((string)($body['provider'] ?? '')));
    if (!isset($INTEGRATION_PROVIDERS[$provider])) {
        integrationsFail(400, t('api.integrations.invalid_provider'));
    }
    $allowedCredKeys = $INTEGRATION_PROVIDERS[$provider]['credential_keys'];

    // Validate every credential/removal key BEFORE any mutation - a rejected
    // request must not create or touch the connection row.
    $credentialsSubmitted = $body['credentials'] ?? [];
    if (!is_array($credentialsSubmitted)) {
        integrationsFail(400, t('api.settings.invalid_data'));
    }
    foreach ($credentialsSubmitted as $credKey => $credValue) {
        if (!in_array($credKey, $allowedCredKeys, true)) {
            integrationsFail(400, t('api.integrations.invalid_credential_key'));
        }
    }
    $remove = $body['remove_credentials'] ?? [];
    if (is_array($remove)) {
        foreach ($remove as $credKey) {
            if (!in_array($credKey, $allowedCredKeys, true)) {
                integrationsFail(400, t('api.integrations.invalid_credential_key'));
            }
        }
    }

    $existsStmt = $pdo->prepare("SELECT 1 FROM integration_connections WHERE practice_id = :pid AND provider = :p LIMIT 1");
    $existsStmt->execute([':pid' => $currentPracticeId, ':p' => $provider]);
    $isNewConnection = !$existsStmt->fetchColumn();

    // Upsert on the (practice_id, provider) unique key. Reconfiguring always
    // returns the connection to 'pending': changed credentials/settings are
    // unverified until a real connection test exists (Phase C), and a
    // disabled connection being reconfigured signals intent to reconnect.
    $stmt = $pdo->prepare("
        INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, created_by_user_id)
        VALUES (:practice_id, :provider, 'pending', 1, :user_id)
        ON DUPLICATE KEY UPDATE status = 'pending', sync_enabled = 1
    ");
    $stmt->execute([
        ':practice_id' => $currentPracticeId,
        ':provider'    => $provider,
        ':user_id'     => $userId,
    ]);

    $existing = $pdo->prepare("SELECT id, created_at FROM integration_connections WHERE practice_id = :pid AND provider = :p LIMIT 1");
    $existing->execute([':pid' => $currentPracticeId, ':p' => $provider]);
    $connection = $existing->fetch(PDO::FETCH_ASSOC);
    $connectionId = (int)$connection['id'];

    // Credentials: only whitelisted keys, only non-empty values stored.
    // Blank fields leave existing credentials untouched.
    $credentialsChanged = false;
    foreach ($credentialsSubmitted as $credKey => $credValue) {
        if (!is_string($credValue) || $credValue === '') {
            continue; // blank = keep existing
        }
        IntegrationCredentials::store($pdo, $connectionId, $credKey, $credValue);
        $credentialsChanged = true;
    }

    // Explicit removal only when the client asks for it by name.
    if (is_array($remove)) {
        foreach ($remove as $credKey) {
            IntegrationCredentials::delete($pdo, $connectionId, $credKey);
            $credentialsChanged = true;
        }
    }

    logIntegrationAdminAction($userId, 'integration_configured', $currentPracticeId, $provider, $connectionId);
    if ($credentialsChanged) {
        logIntegrationAdminAction($userId, 'integration_credentials_updated', $currentPracticeId, $provider, $connectionId);
    }

    echo json_encode([
        'success'    => true,
        'message'    => t('api.integrations.saved_unverified'),
        'created'    => $isNewConnection,
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST generate_key: create a practice Customer Key through the Open Dental
// Developer Portal API (POST /apikeys) so the admin never handles developer
// credentials. The connection row is created if needed. The generated key
// is stored encrypted via IntegrationCredentials and returned ONCE in this
// response - the admin needs it to paste into Open Dental (Setup > Advanced
// Setup > API). It is never returned by any other endpoint.
//
// Repeat behavior is intentionally guarded: without {regenerate:true} an
// existing stored key yields 409 - this prevents silently accumulating
// unused keys on the DentaTrak developer account. Regeneration creates the
// replacement FIRST, then best-effort disables the old key remotely
// (PUT KeyStatus=DisabledByDeveloper), so a failure never strands the
// practice without a working path.
// -------------------------------------------------------------------------
if ($action === 'generate_key') {
    $provider = 'open_dental'; // only provider with a developer-portal flow

    // Fail closed when the central server secrets are absent. The message
    // is safe for an admin audience - no variable names, no values.
    if (!OpenDentalConfig::hasDeveloperKey() || !OpenDentalConfig::hasDeveloperPortalKey()) {
        integrationsFail(503, t('api.integrations.server_not_configured'), 'server_config_missing');
    }

    // Get-or-create the connection row (same upsert semantics as configure).
    $stmt = $pdo->prepare("
        INSERT INTO integration_connections (practice_id, provider, status, sync_enabled, created_by_user_id)
        VALUES (:practice_id, :provider, 'pending', 1, :user_id)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt->execute([
        ':practice_id' => $currentPracticeId,
        ':provider'    => $provider,
        ':user_id'     => $userId,
    ]);
    $find = $pdo->prepare("SELECT * FROM integration_connections WHERE practice_id = :pid AND provider = :p LIMIT 1");
    $find->execute([':pid' => $currentPracticeId, ':p' => $provider]);
    $connection = $find->fetch(PDO::FETCH_ASSOC);
    $connectionId = (int)$connection['id'];

    $existingKey = IntegrationCredentials::get($pdo, $connectionId, 'customer_key');
    $regenerate = !empty($body['regenerate']);
    if ($existingKey !== null && !$regenerate) {
        integrationsFail(409, t('api.integrations.key_exists'));
    }

    try {
        $portal = new OpenDentalPortalClient();
        $created = $portal->createCustomerKey();
    } catch (OpenDentalApiException $e) {
        error_log('[integrations] customer key generation failed: ' . $e->category . ' HTTP ' . $e->httpCode);
        $code = $e->category === 'server_config_missing' ? 503 : 502;
        integrationsFail($code, t('api.integrations.generate_failed'), $e->category);
    } catch (Throwable $e) {
        error_log('[integrations] customer key generation failed: unexpected');
        integrationsFail(502, t('api.integrations.generate_failed'), 'unexpected');
    }
    $newKey = (string)$created['CustomerKey'];

    // Best-effort DevRefId so the key is identifiable in OUR developer
    // portal (visible only to DentaTrak, per OD docs). Practice name is
    // the developer-facing label, matching the documented convention.
    try {
        $pname = $pdo->prepare("SELECT practice_name FROM practices WHERE id = :id");
        $pname->execute([':id' => $currentPracticeId]);
        $ref = trim((string)$pname->fetchColumn());
        if ($ref !== '') {
            $portal->updateCustomerKey($newKey, null, mb_substr($ref, 0, 100));
        }
    } catch (Throwable $e) {
        // Non-blocking bookkeeping only.
    }

    // Regeneration: retire the superseded key remotely so unused keys do
    // not accumulate on the developer account. Best-effort - a failure
    // here never blocks issuing the replacement.
    if ($existingKey !== null) {
        try {
            $portal->disableCustomerKey($existingKey);
        } catch (Throwable $e) {
            error_log('[integrations] previous customer key remote disable failed for connection ' . $connectionId);
        }
    }

    IntegrationCredentials::store($pdo, $connectionId, 'customer_key', $newKey);
    // Purge any legacy per-practice developer_key row - it is obsolete now
    // that the Developer Key is a central server secret.
    IntegrationCredentials::delete($pdo, $connectionId, 'developer_key');

    // New key material is unverified until a connection test passes.
    $pdo->prepare("UPDATE integration_connections SET status = 'pending' WHERE id = :id")
        ->execute([':id' => $connectionId]);

    logIntegrationAdminAction($userId, 'integration_key_generated', $currentPracticeId, $provider, $connectionId);

    echo json_encode([
        'success'      => true,
        'message'      => t('api.integrations.key_generated'),
        // Shown once, admin-only, never stored in any response elsewhere.
        'customer_key' => $newKey,
        'connection'   => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST update_settings: whitelisted non-secret settings only (sync_enabled)
// -------------------------------------------------------------------------
if ($action === 'update_settings') {
    $connectionId = (int)($body['connection_id'] ?? 0);
    // Practice-isolated lookup: foreign/missing connections both 404.
    $connection = IntegrationManager::getConnectionForPractice($pdo, $currentPracticeId, $connectionId);
    if (!$connection) {
        integrationsFail(404, t('api.integrations.not_found'));
    }
    if ($connection['status'] === 'disabled') {
        integrationsFail(409, t('api.integrations.disabled_connection'));
    }

    if (!array_key_exists('sync_enabled', $body)) {
        integrationsFail(400, t('api.integrations.no_supported_settings'));
    }
    $syncEnabled = filter_var($body['sync_enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE integration_connections SET sync_enabled = :v WHERE id = :id");
    $stmt->execute([':v' => $syncEnabled, ':id' => $connectionId]);

    logIntegrationAdminAction($userId, 'integration_settings_updated', $currentPracticeId, $connection['provider'], $connectionId);

    echo json_encode([
        'success'    => true,
        'message'    => t('api.integrations.settings_saved'),
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST disconnect: soft-disable - preserves mappings, sync history, and
// credentials so a disconnect is reversible and destroys no audit trail.
// (FK cascade would delete credentials/mappings/runs/events on row DELETE,
// which is exactly why this endpoint never deletes the connection row.)
// -------------------------------------------------------------------------
if ($action === 'disconnect') {
    $connectionId = (int)($body['connection_id'] ?? 0);
    $connection = IntegrationManager::getConnectionForPractice($pdo, $currentPracticeId, $connectionId);
    if (!$connection) {
        integrationsFail(404, t('api.integrations.not_found'));
    }

    $stmt = $pdo->prepare("
        UPDATE integration_connections
        SET status = 'disabled', sync_enabled = 0
        WHERE id = :id
    ");
    $stmt->execute([':id' => $connectionId]);

    // Best-effort: retire the remote event subscriptions so Open Dental
    // stops firing into a disabled connection. Never blocks disconnect.
    $sub = IntegrationEvents::getSubscriptionConfig($connection);
    $subNums = array_filter([
        (int)($sub['subscription_num'] ?? 0),
        (int)($sub['deleted_subscription_num'] ?? 0),
    ]);
    if ($sub !== null && !empty($sub['enabled']) && $subNums) {
        try {
            $adapter = IntegrationManager::getAdapter($connection);
            if (method_exists($adapter, 'disableSubscription')) {
                $credentials = IntegrationManager::getConnectionCredentials($pdo, $connectionId);
                foreach ($subNums as $subNum) {
                    $adapter->disableSubscription($credentials, $subNum);
                }
            }
        } catch (Throwable $e) {
            error_log('[integrations] remote subscription disable failed for connection ' . $connectionId);
        }
        IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
            'enabled'     => false,
            'disabled_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // Best-effort: disable the practice's Customer Key remotely through the
    // Developer Portal API so a disconnected integration leaves no live key
    // behind. Never blocks disconnect, never destroys local history - the
    // encrypted key and all mappings stay so reconnect can re-enable it.
    if ($connection['provider'] === 'open_dental'
        && OpenDentalConfig::hasDeveloperKey()
        && OpenDentalConfig::hasDeveloperPortalKey()) {
        $storedKey = IntegrationCredentials::get($pdo, $connectionId, 'customer_key');
        if ($storedKey !== null) {
            try {
                (new OpenDentalPortalClient())->disableCustomerKey($storedKey);
            } catch (Throwable $e) {
                error_log('[integrations] remote customer key disable failed for connection ' . $connectionId);
            }
        }
    }

    logIntegrationAdminAction($userId, 'integration_disabled', $currentPracticeId, $connection['provider'], $connectionId);

    echo json_encode([
        'success'    => true,
        'message'    => t('api.integrations.disconnected'),
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST reenable: disabled -> pending (never straight to active - the
// connection still has not passed a live verification test).
// -------------------------------------------------------------------------
if ($action === 'reenable') {
    $connectionId = (int)($body['connection_id'] ?? 0);
    $connection = IntegrationManager::getConnectionForPractice($pdo, $currentPracticeId, $connectionId);
    if (!$connection) {
        integrationsFail(404, t('api.integrations.not_found'));
    }
    if ($connection['status'] !== 'disabled') {
        integrationsFail(409, t('api.integrations.not_disabled'));
    }

    $stmt = $pdo->prepare("
        UPDATE integration_connections
        SET status = 'pending', sync_enabled = 1
        WHERE id = :id
    ");
    $stmt->execute([':id' => $connectionId]);

    // Best-effort mirror of disconnect: re-enable the stored Customer Key
    // remotely. The admin still has to pass Test Connection before the
    // connection goes active, so a failed re-enable surfaces there.
    if ($connection['provider'] === 'open_dental'
        && OpenDentalConfig::hasDeveloperKey()
        && OpenDentalConfig::hasDeveloperPortalKey()) {
        $storedKey = IntegrationCredentials::get($pdo, $connectionId, 'customer_key');
        if ($storedKey !== null) {
            try {
                (new OpenDentalPortalClient())->enableCustomerKey($storedKey);
            } catch (Throwable $e) {
                error_log('[integrations] remote customer key re-enable failed for connection ' . $connectionId);
            }
        }
    }

    logIntegrationAdminAction($userId, 'integration_reenabled', $currentPracticeId, $connection['provider'], $connectionId);

    echo json_encode([
        'success'    => true,
        'message'    => t('api.integrations.reenabled'),
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST test_connection: run the provider adapter's real verification.
// Success -> status 'active', clears last_error, stores the non-secret
// external_account_id hint. Failure -> status 'error' with a sanitized
// last_error. Credentials are NEVER removed or modified by a test outcome.
// -------------------------------------------------------------------------
if ($action === 'test_connection') {
    $connectionId = (int)($body['connection_id'] ?? 0);
    $connection = IntegrationManager::getConnectionForPractice($pdo, $currentPracticeId, $connectionId);
    if (!$connection) {
        integrationsFail(404, t('api.integrations.not_found'));
    }
    if ($connection['status'] === 'disabled') {
        integrationsFail(409, t('api.integrations.disabled_connection'));
    }

    $adapter = IntegrationManager::getAdapter($connection); // fail-closed: unregistered provider -> exception handler -> safe 500
    $credentials = IntegrationManager::getConnectionCredentials($pdo, $connectionId);
    $config = json_decode((string)($connection['config_json'] ?? ''), true);
    if (!is_array($config)) {
        $config = [];
    }

    try {
        $result = $adapter->testConnection($credentials, $config);
    } catch (OpenDentalApiException $e) {
        // Adapter exceptions (e.g. missing credentials) become a failed
        // result, not a 500 - the admin needs the connection error state.
        $result = ['success' => false, 'message' => $e->getMessage(), 'error_code' => $e->category, 'external_account_id' => null];
    } catch (Throwable $e) {
        $result = ['success' => false, 'message' => $e->getMessage(), 'error_code' => 'unexpected', 'external_account_id' => null];
    }

    if (!empty($result['success'])) {
        $stmt = $pdo->prepare("
            UPDATE integration_connections
            SET status = 'active',
                last_error = NULL,
                external_account_id = COALESCE(:ext_id, external_account_id)
            WHERE id = :id
        ");
        $stmt->execute([
            ':ext_id' => $result['external_account_id'] ?? null,
            ':id'     => $connectionId,
        ]);
        logIntegrationAdminAction($userId, 'integration_verified', $currentPracticeId, $connection['provider'], $connectionId);

        echo json_encode([
            'success'    => true,
            'message'    => t('api.integrations.test_succeeded'),
            'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
        ]);
        exit;
    }

    // Failure path: safe adapter message only (category + endpoint + HTTP
    // code by construction). Truncated defensively before storage.
    $safeError = mb_substr((string)($result['message'] ?? t('api.integrations.test_failed')), 0, 500);
    $stmt = $pdo->prepare("
        UPDATE integration_connections
        SET status = 'error', last_error = :err
        WHERE id = :id
    ");
    $stmt->execute([':err' => $safeError, ':id' => $connectionId]);
    // Local/dev diagnostics: the constructed message carries only the safe
    // category, endpoint path and HTTP code - never secrets or raw bodies.
    error_log('[integrations] test_connection failed: ' . $safeError);
    logIntegrationAdminAction($userId, 'integration_test_failed', $currentPracticeId, $connection['provider'], $connectionId);

    echo json_encode([
        'success'    => false,
        'message'    => t('api.integrations.test_failed'),
        // Safe machine-readable category (credentials_missing | auth |
        // server_config_missing | network | timeout | ...) so the UI can
        // show targeted next-step guidance instead of a raw error.
        'error_code' => $result['error_code'] ?? 'unexpected_response',
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST subscribe: register a WatchTable: LabCase event subscription with
// Open Dental (Phase D POC). Requires a verified ('active') connection -
// subscribing an unverified connection would register a callback against
// credentials that may not work.
//
// The callback URL embeds a per-connection random token; we persist only
// its sha256. Open Dental's own auth (customer key in the event
// Authorization header) is the second factor verified on every delivery.
// -------------------------------------------------------------------------
if ($action === 'subscribe') {
    $connectionId = (int)($body['connection_id'] ?? 0);
    $connection = IntegrationManager::getConnectionForPractice($pdo, $currentPracticeId, $connectionId);
    if (!$connection) {
        integrationsFail(404, t('api.integrations.not_found'));
    }
    if ($connection['status'] !== 'active') {
        integrationsFail(409, t('api.integrations.subscription_needs_active'));
    }

    $existing = IntegrationEvents::getSubscriptionConfig($connection);
    $labCaseActive = $existing !== null && !empty($existing['enabled']);
    // The LabCaseDeleted watch may still be missing on connections
    // subscribed before it existed (needs OD v26.1.6+) - subscribe repairs
    // it rather than treating "already active" as fully configured.
    $deletedConfigured = $labCaseActive
        && (!empty($existing['deleted_subscription_num'])
            || ($existing['deleted_watch'] ?? null) === 'unsupported');
    if ($labCaseActive && $deletedConfigured) {
        echo json_encode([
            'success'    => true,
            'message'    => t('api.integrations.subscription_exists'),
            'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
        ]);
        exit;
    }

    $adapter = IntegrationManager::getAdapter($connection);
    if (!method_exists($adapter, 'createLabCaseSubscription')) {
        integrationsFail(400, t('api.integrations.provider_no_events'));
    }

    $pollingSeconds = (int)($body['polling_seconds'] ?? 0);
    if ($pollingSeconds <= 0) {
        $pollingSeconds = OpenDentalAdapter::DEFAULT_POLLING_SECONDS;
    }
    $pollingSeconds = min(3600, max(15, $pollingSeconds));

    // Workstation is an ops-level Open Dental concept. For a localhost
    // endpoint the firing OD.exe/API service runs on this same machine, so
    // its hostname is always a valid value on every OD version. For remote
    // endpoints use the >= 26.2.1 'All Workstations' sentinel; on older
    // versions a real machine name must be passed (production should pin
    // the machine running OpenDentalAPIService.exe anyway).
    $workstation = trim((string)($body['workstation'] ?? ''));
    if ($workstation === '') {
        $cbHost = parse_url($appConfig['app_base_url'] ?? '', PHP_URL_HOST);
        $local = in_array(strtolower((string)$cbHost), ['localhost', '127.0.0.1', '::1'], true);
        $workstation = ($local && gethostname()) ? (string)gethostname() : OpenDentalAdapter::ALL_WORKSTATIONS;
    }

    $credentials = IntegrationManager::getConnectionCredentials($pdo, $connectionId);

    if (!$labCaseActive) {
        $token = IntegrationEvents::generateCallbackToken();
        $endpointUrl = IntegrationEvents::buildCallbackUrl(
            (string)($appConfig['app_base_url'] ?? ''), $connectionId, $token
        );

        try {
            $created = $adapter->createLabCaseSubscription(
                $credentials,
                [
                    'endpoint_url'    => $endpointUrl,
                    'workstation'     => $workstation,
                    'polling_seconds' => $pollingSeconds,
                    'note'            => 'DentaTrak LabCase change notifications',
                ]
            );
        } catch (Throwable $e) {
            error_log('[integrations] subscription create failed: ' . $e->getMessage());
            integrationsFail(502, t('api.integrations.subscription_failed'),
                $e instanceof OpenDentalApiException ? $e->category : 'unexpected');
        }

        IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
            'enabled'           => true,
            'subscription_num'  => (int)$created['SubscriptionNum'],
            'watch_table'       => 'LabCase',
            'polling_seconds'   => $pollingSeconds,
            'workstation'       => $workstation,
            'callback_key_hash' => IntegrationEvents::hashCallbackToken($token),
            'subscribed_at'     => gmdate('Y-m-d H:i:s'),
            'disabled_at'       => null,
        ]);
    }

    // Deletion watch (best-effort, never fails the request): a second
    // subscription on the same callback URL; the Event-Type header tells
    // deliveries apart. OD < 26.1.6 rejects LabCaseDeleted - record
    // 'unsupported' so this is never retried pointlessly on every call.
    if (!$deletedConfigured) {
        $delEndpoint = $endpointUrl ?? null;
        if ($delEndpoint === null) {
            // Repair path: the LabCase subscription already exists but its
            // callback URL is not persisted - rebuild it from scratch with a
            // fresh token and point BOTH subscriptions at it, so the stored
            // hash and the registered URLs stay consistent.
            $token = IntegrationEvents::generateCallbackToken();
            $delEndpoint = IntegrationEvents::buildCallbackUrl(
                (string)($appConfig['app_base_url'] ?? ''), $connectionId, $token
            );
            try {
                $adapter->updateSubscription($credentials, (int)$existing['subscription_num'], [
                    'EndPointUrl' => $delEndpoint,
                ]);
                IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
                    'callback_key_hash' => IntegrationEvents::hashCallbackToken($token),
                ]);
            } catch (Throwable $e) {
                error_log('[integrations] callback URL repair failed for connection ' . $connectionId);
                $delEndpoint = null;
            }
        }
        if ($delEndpoint !== null) {
            try {
                $delCreated = $adapter->createLabCaseSubscription($credentials, [
                    'endpoint_url'    => $delEndpoint,
                    'workstation'     => $workstation,
                    'polling_seconds' => $pollingSeconds,
                    'watch_table'     => OpenDentalAdapter::WATCH_TABLE_LABCASE_DELETED,
                    'note'            => 'DentaTrak LabCase deletion notifications',
                ]);
                IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
                    'deleted_subscription_num' => (int)$delCreated['SubscriptionNum'],
                    'deleted_watch'            => 'active',
                ]);
            } catch (OpenDentalApiException $e) {
                IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
                    'deleted_watch' => $e->category === 'bad_request' ? 'unsupported' : 'error',
                ]);
            } catch (Throwable $e) {
                IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
                    'deleted_watch' => 'error',
                ]);
            }
        }
    }

    logIntegrationAdminAction($userId, 'integration_subscribed', $currentPracticeId, $connection['provider'], $connectionId);

    echo json_encode([
        'success'    => true,
        'message'    => t('api.integrations.subscribed'),
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST unsubscribe: retire the remote subscription via PUT DateTimeStop
// (Subscriptions DELETE is NOT in the free ApiReadAll tier - it falls under
// paid "All Others" - so expiry is the free-tier disable mechanism) and
// mark the local subscription block disabled. Stray in-flight events are
// acknowledged-and-dropped by the webhook endpoint.
// -------------------------------------------------------------------------
if ($action === 'unsubscribe') {
    $connectionId = (int)($body['connection_id'] ?? 0);
    $connection = IntegrationManager::getConnectionForPractice($pdo, $currentPracticeId, $connectionId);
    if (!$connection) {
        integrationsFail(404, t('api.integrations.not_found'));
    }

    $sub = IntegrationEvents::getSubscriptionConfig($connection);
    if ($sub === null || empty($sub['enabled'])) {
        integrationsFail(409, t('api.integrations.no_subscription'));
    }

    $remoteOk = true;
    $subNums = array_filter([
        (int)($sub['subscription_num'] ?? 0),
        (int)($sub['deleted_subscription_num'] ?? 0),
    ]);
    if ($subNums) {
        try {
            $adapter = IntegrationManager::getAdapter($connection);
            $credentials = IntegrationManager::getConnectionCredentials($pdo, $connectionId);
            foreach ($subNums as $subNum) {
                $adapter->disableSubscription($credentials, $subNum);
            }
        } catch (Throwable $e) {
            // Still mark locally disabled: the webhook drops strays when
            // enabled=false, and a retry can be attempted later.
            error_log('[integrations] remote unsubscribe failed: ' . $e->getMessage());
            $remoteOk = false;
        }
    }

    IntegrationEvents::updateSubscriptionConfig($pdo, $connectionId, [
        'enabled'     => false,
        'disabled_at' => gmdate('Y-m-d H:i:s'),
    ]);

    logIntegrationAdminAction($userId, 'integration_unsubscribed', $currentPracticeId, $connection['provider'], $connectionId);

    echo json_encode([
        'success'    => true,
        'message'    => $remoteOk ? t('api.integrations.unsubscribed') : t('api.integrations.unsubscribed_remote_failed'),
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

// -------------------------------------------------------------------------
// POST import_existing: admin-initiated historical LabCase import.
//
// The request only STARTS the import - it never holds open while records
// are fetched. Open Dental's LabCases list has no server-side date filter,
// so a 'labcase_scan' queue event enumerates pages through the existing
// worker, and each in-scope row becomes a normal 'labcase' event that
// flows through the exact-once import path. Cases already imported via
// live events (or a previous import) are never duplicated.
//
// The admin picks a bounded window; there is deliberately no "import
// everything ever" option.
// -------------------------------------------------------------------------
if ($action === 'import_existing') {
    $connectionId = (int)($body['connection_id'] ?? 0);
    $connection = IntegrationManager::getConnectionForPractice($pdo, $currentPracticeId, $connectionId);
    if (!$connection) {
        integrationsFail(404, t('api.integrations.not_found'));
    }
    if ($connection['status'] !== 'active') {
        // Import requires working credentials - same gate as subscribing.
        integrationsFail(409, t('api.integrations.import_needs_active'));
    }

    $days = (int)($body['days'] ?? 0);
    if (!in_array($days, IntegrationEvents::BACKFILL_SCOPES, true)) {
        integrationsFail(400, t('api.integrations.import_invalid_range'));
    }

    $adapter = IntegrationManager::getAdapter($connection);
    if (!method_exists($adapter, 'listLabCases')) {
        integrationsFail(400, t('api.integrations.provider_no_events'));
    }

    try {
        IntegrationEvents::startBackfill($pdo, $connection, $days);
    } catch (RuntimeException $e) {
        integrationsFail(409, t('api.integrations.import_in_progress'));
    } catch (Throwable $e) {
        error_log('[integrations] import start failed: ' . $e->getMessage());
        integrationsFail(502, t('api.integrations.import_failed'));
    }

    logIntegrationAdminAction($userId, 'integration_backfill_started', $currentPracticeId, $connection['provider'], $connectionId);

    echo json_encode([
        'success'    => true,
        'message'    => t('api.integrations.import_started'),
        'connection' => IntegrationManager::toPublicConnection($pdo, IntegrationManager::findConnection($pdo, $connectionId)),
    ]);
    exit;
}

integrationsFail(400, t('api.integrations.unknown_action'));
